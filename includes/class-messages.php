<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/functions-mail.php';

/**
 * Berichten, the member's side: conversations with the penningmeester on
 * the member page instead of by e-mail. A member starts one (any subject,
 * optionally about an item on their account) or answers in one; the
 * penningmeester gets a mail with the text and a link to the conversation.
 * The penningmeester answers, notes and closes in Berichten (admin), or
 * starts a conversation from the member's page in the admin; see
 * AVBK_Disputes. A conversation is an avb_disputes row with its events.
 */
class AVBK_Messages implements AVBK_Component {

    private const NO_ACCESS = 'Geen toegang.';

    /** Names of the member-side notices, from the query string. */
    public const NOTICES = [
        'message_sent'   => 'Je bericht is verstuurd naar de penningmeester. Je antwoord verschijnt hier.',
        'message_failed' => 'Je bericht is niet opgeslagen. Probeer het opnieuw.',
    ];

    public function register(): void {
        add_action('admin_post_avbk_message_new', [$this, 'handle_new']);
        add_action('admin_post_avbk_message_reply', [$this, 'handle_reply']);
        add_action('admin_post_avbk_message_start', [$this, 'handle_start']);
    }

    /**
     * The messages of a conversation in order, for the member page and the
     * admin. Internal notes only when $internal; status changes always.
     *
     * @param object[] $events AVBK_DB::get_dispute_events()
     * @return array<int, array{author: string, member_id: ?int, text: string, at: string, kind: string, event: ?object}>
     *     author 'member', 'treasurer' or 'system'; kind 'message', 'note' or 'status'.
     */
    public static function conversation(object $thread, array $events, bool $internal): array {
        $entries = [];
        if (trim((string) $thread->message) !== '') {
            $by_treasurer = ($thread->started_by ?? 'member') === 'treasurer';
            $entries[] = [
                'author'    => $by_treasurer ? 'treasurer' : 'member',
                'member_id' => $by_treasurer ? null : (int) ($thread->submitted_by_member_id ?: $thread->member_id),
                'text'      => (string) $thread->message,
                'at'        => (string) $thread->created_at,
                'kind'      => 'message',
                'event'     => null,
            ];
        }
        foreach ($events as $event) {
            $entry = self::entry_for_event($event, $internal);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /** @return array{author: string, member_id: ?int, text: string, at: string, kind: string, event: object}|null */
    private static function entry_for_event(object $event, bool $internal): ?array {
        $kinds = [
            'member_message' => ['member', 'message'],
            'reply'          => ['treasurer', 'message'],
            'note'           => ['treasurer', 'note'],
            'resolved'       => ['system', 'status'],
            'reopened'       => ['system', 'status'],
        ];
        $kind = $kinds[$event->event_type] ?? null;
        if ($kind === null || ($kind[1] === 'note' && !$internal)) {
            return null;
        }
        return [
            'author'    => $kind[0],
            'member_id' => !empty($event->author_member_id) ? (int) $event->author_member_id : null,
            'text'      => (string) $event->message,
            'at'        => (string) $event->created_at,
            'kind'      => $kind[1],
            'event'     => $event,
        ];
    }

    /** For the penningmeester: "Wacht op jou", "Wacht op het lid" or "Afgehandeld". */
    public static function admin_state(object $thread): string {
        if ($thread->status === 'resolved') {
            return 'Afgehandeld';
        }
        return ($thread->awaiting ?? '') === 'member' ? 'Wacht op het lid' : 'Wacht op jou';
    }

    /** Whether the logged-in member may see and write in the conversations about $member_id. */
    public static function can_view(object $own_member, int $member_id): bool {
        if ($member_id === (int) $own_member->id || current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('bestuur')) {
            return true;
        }
        $household = array_map('intval', wp_list_pluck(AVPVH_DB::get_extended_household((int) $own_member->id), 'id'));
        return in_array($member_id, $household, true);
    }

    /** The posted text fields of a member form. @return array{message: string, request_key: string, redirect: string} */
    private static function posted(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- every caller checked its nonce first.
        $redirect = esc_url_raw(wp_unslash($_POST['redirect_url'] ?? '')) ?: home_url('/');
        $posted = [
            'message'     => trim(sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''))),
            'request_key' => sanitize_text_field(wp_unslash($_POST['request_key'] ?? '')),
            'redirect'    => strtok($redirect, '#') . '#berichten',
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return $posted;
    }

    /** The logged-in member, or a 403. */
    private static function own_member(): object {
        $own_member = is_user_logged_in() ? avpvh_get_member_by_wp_user(get_current_user_id()) : null;
        if (!$own_member) {
            wp_die(esc_html(self::NO_ACCESS), 'Fout', ['response' => 403]);
        }
        return $own_member;
    }

    private static function back(string $redirect, string $notice): void {
        wp_safe_redirect(add_query_arg($notice, '1', strtok($redirect, '#')) . '#berichten');
        exit;
    }

    /** A member starts a conversation from the member page. */
    public function handle_new(): void {
        check_admin_referer('avbk_message_new');
        $own_member = self::own_member();
        ['message' => $message, 'request_key' => $request_key, 'redirect' => $redirect] = self::posted();
        $member_id = (int) ($_POST['member_id'] ?? 0);
        if (!self::can_view($own_member, $member_id)) {
            wp_die(esc_html(self::NO_ACCESS), 'Fout', ['response' => 403]);
        }
        $fee_item_id = self::own_fee_item($own_member, (int) ($_POST['fee_item_id'] ?? 0));
        $subject = trim(sanitize_text_field(wp_unslash($_POST['subject'] ?? '')));
        if ($message === '' || !wp_is_uuid($request_key)) {
            avbk_log_failure('message new', $message === '' ? 'empty_message' : 'invalid_request', ['member' => $member_id]);
            self::back($redirect, 'message_failed');
        }
        if (AVBK_DB::get_thread_by_request($request_key)) {
            self::back($redirect, 'message_sent');
        }
        $thread_id = AVBK_DB::create_message_thread([
            'member_id'              => $member_id,
            'submitted_by_member_id' => (int) $own_member->id,
            'subject'                => $subject,
            'fee_item_id'            => $fee_item_id,
            'started_by'             => 'member',
            'message'                => $message,
            'request_key'            => $request_key,
        ]);
        $thread = $thread_id ? AVBK_DB::get_dispute($thread_id) : null;
        if (!$thread) {
            avbk_log_failure('message new', 'not_saved', ['member' => $member_id]);
            self::back($redirect, 'message_failed');
        }
        self::notify_treasurer($thread, $own_member, $message);
        self::back($redirect, 'message_sent');
    }

    /** A fee item the member may refer to (their own or a housemate's), or null. */
    private static function own_fee_item(object $own_member, int $fee_item_id): ?int {
        $item = $fee_item_id ? AVBK_DB::get_fee_item($fee_item_id) : null;
        return $item && self::can_view($own_member, (int) $item->member_id) ? $fee_item_id : null;
    }

    /** A member answers in a conversation. */
    public function handle_reply(): void {
        check_admin_referer('avbk_message_reply');
        $own_member = self::own_member();
        ['message' => $message, 'request_key' => $request_key, 'redirect' => $redirect] = self::posted();
        $thread = AVBK_DB::get_dispute((int) ($_POST['thread_id'] ?? 0));
        if (!$thread || !self::can_view($own_member, (int) $thread->member_id)) {
            wp_die(esc_html(self::NO_ACCESS), 'Fout', ['response' => 403]);
        }
        if ($message === '' || !wp_is_uuid($request_key)) {
            avbk_log_failure('message reply', $message === '' ? 'empty_message' : 'invalid_request', ['thread' => (int) $thread->id]);
            self::back($redirect, 'message_failed');
        }
        if (AVBK_DB::has_dispute_request($request_key)) {
            self::back($redirect, 'message_sent');
        }
        if (!AVBK_DB::add_member_message((int) $thread->id, $message, (int) $own_member->id, $request_key)) {
            avbk_log_failure('message reply', 'not_saved', ['thread' => (int) $thread->id]);
            self::back($redirect, 'message_failed');
        }
        self::notify_treasurer($thread, $own_member, $message);
        self::back($redirect, 'message_sent');
    }

    /** The penningmeester starts a conversation from the member's page in the admin. */
    public function handle_start(): void {
        check_admin_referer('avbk_message_start');
        if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
            wp_die(esc_html(self::NO_ACCESS), 403);
        }
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $fee_item_id = (int) ($_POST['fee_item_id'] ?? 0);
        $item = $fee_item_id ? AVBK_DB::get_fee_item($fee_item_id) : null;
        $result = AVBK_Disputes::start(
            $member_id,
            sanitize_text_field(wp_unslash($_POST['subject'] ?? '')),
            $item && (int) $item->member_id === $member_id ? $fee_item_id : null,
            sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['request_key'] ?? ''))
        );
        $notice = ['message_started' => is_wp_error($result) ? '' : (string) $result];
        if (is_wp_error($result)) {
            avbk_log_failure('message start', $result->get_error_code(), ['member' => $member_id]);
            $notice = ['message_start_failed' => $result->get_error_code()];
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-members', 'member_id' => $member_id] + $notice, admin_url('admin.php')) . '#berichten');
        exit;
    }

    /** The penningmeester's mail: who wrote, the text, and a link to the conversation. */
    private static function notify_treasurer(object $thread, object $author, string $message): void {
        $to = get_option('avbk_penningmeester_email', 'info@avphilipsvanhorne.nl');
        $member = AVPVH_DB::get_member((int) $thread->member_id);
        $topic = trim((string) ($thread->subject ?? ''));
        $subject = sprintf('[AV-PvH] Bericht van %s%s', avpvh_format_name($author), $topic !== '' ? ': ' . $topic : '');
        $about = $member && (int) $member->id !== (int) $author->id ? ' (over ' . avpvh_format_name($member) . ')' : '';
        $body = sprintf(
            "%s%s schreef:\n\n%s\n\nLees en antwoord in Berichten: %s",
            avpvh_format_name($author),
            $about,
            $message,
            add_query_arg(['page' => 'avbk-disputes', 'dispute_id' => (int) $thread->id], admin_url('admin.php')) . '#avbk-dispute-' . (int) $thread->id
        );
        avbk_mail('treasurer_notice', (int) $thread->member_id, $to, $subject, $body);
    }
}
