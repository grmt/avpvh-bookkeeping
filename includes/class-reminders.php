<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/functions-mail.php';
require_once __DIR__ . '/class-reminder-rules.php';

/**
 * Herinneringen: the penningmeester reminds members with open items to pay,
 * many at once. The page lists everyone with something open (outside closed
 * years), how often they were reminded and when they were last asked; the
 * penningmeester picks who gets a mail and adjusts the opening text.
 *
 * A round is stored first (one pending reminder per member, protected
 * against a double click by its request key) and then sent in small batches
 * from the browser, which shows the progress. Each reminder is claimed
 * before it is sent, so nobody gets the same reminder twice. The mail is the
 * member page's payment request (AVBK_Admin::send_balance_request()), logged
 * as kind 'reminder' in Verzonden mails, which is also where the count comes
 * from. Who is listed and ticked is AVBK_Reminder_Rules.
 */
class AVBK_Reminders implements AVBK_Component {

    public const BATCH_SIZE = 5;

    private const NO_ACCESS = 'Geen toegang.';

    public function register(): void {
        add_action('admin_post_avbk_reminders_start', [$this, 'handle_start']);
        add_action('admin_post_avbk_reminders_preview', [$this, 'handle_preview']);
        add_action('admin_post_avbk_reminders_test', [$this, 'handle_test']);
        add_action('admin_post_avbk_reminders_batch', [$this, 'handle_batch']);
        add_action('wp_ajax_avbk_reminders_batch', [$this, 'ajax_batch']);
    }

    private function can_manage(): bool {
        return current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('penningmeester');
    }

    private static function page_url(array $args = []): string {
        return add_query_arg(['page' => 'avbk-reminders'] + $args, admin_url('admin.php'));
    }

    /**
     * The posted selection and text, shared by start, preview and test.
     *
     * @return array{member_ids: int[], intro: string}
     */
    private static function posted_selection(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- every caller checked its nonce first.
        $member_ids = array_values(array_unique(array_filter(array_map('intval', (array) wp_unslash($_POST['member_ids'] ?? [])))));
        $intro = trim(sanitize_textarea_field(wp_unslash($_POST['intro'] ?? '')));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return ['member_ids' => $member_ids, 'intro' => $intro !== '' ? $intro : AVBK_Reminder_Rules::DEFAULT_INTRO];
    }

    /** Stores the round and goes to its progress page, where the browser sends it. */
    public function handle_start(): void {
        check_admin_referer('avbk_reminders_send');
        if (!$this->can_manage()) {
            wp_die(esc_html(self::NO_ACCESS), 403);
        }
        ['member_ids' => $member_ids, 'intro' => $intro] = self::posted_selection();
        $request_key = sanitize_text_field(wp_unslash($_POST['request_key'] ?? ''));
        if (!empty($_POST['save_intro'])) {
            update_option('avbk_reminder_intro', $intro, false);
        }
        // Re-derive what each member owes now instead of trusting the page.
        $open = AVBK_DB::get_open_items_by_member();
        $reminders = [];
        foreach ($member_ids as $member_id) {
            $items = $open[$member_id] ?? [];
            if ($items && AVBK_Reminder_Rules::has_mail_address(AVPVH_DB::get_member($member_id))) {
                $reminders[] = [
                    'member_id'    => $member_id,
                    'fee_item_ids' => array_map(static fn($item) => (int) $item->id, $items),
                    'amount'       => array_sum(array_map(static fn($item) => (float) $item->remaining, $items)),
                ];
            }
        }
        $round_id = 0;
        if ($reminders && preg_match('/^[0-9a-f-]{36}$/i', $request_key)) {
            $round_id = AVBK_DB::create_reminder_round($request_key, $intro, $reminders);
        }
        if (!$round_id) {
            avbk_log_failure('reminders start', $reminders ? 'not_saved' : 'no_recipients', ['selected' => count($member_ids)]);
            wp_safe_redirect(self::page_url(['start_failed' => $reminders ? 'not_saved' : 'no_recipients']));
            exit;
        }
        wp_safe_redirect(self::page_url(['round' => $round_id]));
        exit;
    }

    /** The mail for the first selected member, as it would be sent; in a new tab. */
    public function handle_preview(): void {
        check_admin_referer('avbk_reminders_send');
        if (!$this->can_manage()) {
            wp_die(esc_html(self::NO_ACCESS), 403);
        }
        ['member_ids' => $member_ids, 'intro' => $intro] = self::posted_selection();
        [$member, $items] = self::first_mailable($member_ids);
        if (!$member) {
            wp_die('Kies minstens één lid met openstaande posten.', 'Voorbeeld', ['response' => 400]);
        }
        header('Content-Type: text/html; charset=UTF-8');
        // The mail's own markup, shown in its own sandboxed document.
        header('Content-Security-Policy: sandbox; default-src \'none\'; style-src \'unsafe-inline\'; img-src data:');
        echo AVBK_Admin::balance_request_preview($member, $items, AVBK_Admin::balance_request_url((int) $member->id, $items), $intro); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the mail body, built from escaped parts.
        exit;
    }

    /** Sends the first selected member's reminder to the penningmeester's own address. */
    public function handle_test(): void {
        check_admin_referer('avbk_reminders_send');
        if (!$this->can_manage()) {
            wp_die(esc_html(self::NO_ACCESS), 403);
        }
        ['member_ids' => $member_ids, 'intro' => $intro] = self::posted_selection();
        [$member, $items] = self::first_mailable($member_ids);
        $own_address = (string) wp_get_current_user()->user_email;
        $notice = ['test_sent' => '1'];
        if (!$member || !is_email($own_address)) {
            $notice = ['test_failed' => '1'];
        } else {
            $test_member = clone $member;
            $test_member->email = $own_address;
            $result = AVBK_Admin::send_balance_request($test_member, $items, ['applied' => 0.0, 'sources' => [], 'left' => 0.0], AVBK_Admin::balance_request_url((int) $member->id, $items), '[TEST] ' . AVBK_Reminder_Rules::subject($items), 'reminder_test', $intro);
            $notice = $result['sent'] ? $notice : ['test_failed' => '1'];
        }
        if (isset($notice['test_failed'])) {
            avbk_log_failure('reminders test', 'not_sent', ['selected' => count($member_ids)]);
        }
        wp_safe_redirect(self::page_url($notice));
        exit;
    }

    /**
     * The first selected member that can be mailed, with their open items.
     *
     * @param int[] $member_ids
     * @return array{0: ?object, 1: object[]}
     */
    private static function first_mailable(array $member_ids): array {
        $open = AVBK_DB::get_open_items_by_member();
        foreach ($member_ids as $member_id) {
            $member = AVPVH_DB::get_member($member_id);
            $items = AVBK_Admin::open_items_for_reminder($member_id, array_map(static fn($item) => (int) $item->id, $open[$member_id] ?? []));
            if ($items && AVBK_Reminder_Rules::has_mail_address($member)) {
                return [$member, $items];
            }
        }
        return [null, []];
    }

    /**
     * Sends the next batch of a round.
     *
     * @return array{pending: int, sending: int, sent: int, failed: int, skipped: int}
     */
    public static function send_batch(int $round_id): array {
        $round = AVBK_DB::get_reminder_round($round_id);
        if (!$round) {
            return ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        }
        foreach (AVBK_DB::claim_pending_reminders($round_id, self::BATCH_SIZE) as $reminder) {
            [$status, $error] = self::send_one($reminder, (string) $round->intro);
            AVBK_DB::finish_reminder((int) $reminder->id, $status, $error);
            if ($status === 'failed') {
                avbk_log_failure('reminder', $error !== '' ? 'mail_failed' : 'not_sent', ['round' => $round_id, 'member' => (int) $reminder->member_id]);
            }
        }
        return AVBK_DB::reminder_counts($round_id);
    }

    /**
     * One reminder, with what is still open now: an item paid since the
     * round started is left out, and a member with nothing left is skipped.
     *
     * @return array{0: string, 1: string} Status and error.
     */
    private static function send_one(object $reminder, string $intro): array {
        $member = AVPVH_DB::get_member((int) $reminder->member_id);
        if (!AVBK_Reminder_Rules::has_mail_address($member)) {
            return ['failed', 'geen geldig e-mailadres'];
        }
        $items = AVBK_Admin::open_items_for_reminder((int) $reminder->member_id, array_map('intval', explode(',', (string) $reminder->fee_item_ids)));
        if (!$items) {
            return ['skipped', 'niets meer open'];
        }
        $result = AVBK_Admin::send_balance_request($member, $items, ['applied' => 0.0, 'sources' => [], 'left' => 0.0], AVBK_Admin::balance_request_url((int) $member->id, $items), AVBK_Reminder_Rules::subject($items), 'reminder', $intro);
        if ($result['sent']) {
            return ['sent', ''];
        }
        return ['failed', $result['error'] !== '' ? $result['error'] : 'niet verstuurd'];
    }

    /** The browser's batch call while the progress page is open. */
    public function ajax_batch(): void {
        check_ajax_referer('avbk_reminders_batch');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => self::NO_ACCESS], 403);
        }
        wp_send_json_success(self::send_batch((int) ($_POST['round'] ?? 0)));
    }

    /** Without JavaScript: one batch per click. */
    public function handle_batch(): void {
        check_admin_referer('avbk_reminders_batch');
        if (!$this->can_manage()) {
            wp_die(esc_html(self::NO_ACCESS), 403);
        }
        $round_id = (int) ($_POST['round'] ?? 0);
        self::send_batch($round_id);
        wp_safe_redirect(self::page_url(['round' => $round_id]));
        exit;
    }
}
