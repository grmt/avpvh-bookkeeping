<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/functions-mail.php';

/**
 * Berichten, the penningmeester's side: answer a conversation (on the site
 * and by mail, with the whole text), keep internal notes, close or reopen
 * it, or start one with a member. The member's side is AVBK_Messages.
 * (The tables and names still say dispute: Berichten grew out of Bezwaren.)
 */
class AVBK_Disputes {

    /** A request key: a version 4 UUID, one per form. */
    private const REQUEST_KEY = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public static function recipient(object $dispute): ?object {
        // Historical messages did not record the submitting household member.
        // Do not silently substitute someone else when a known submitter is gone.
        $member_id = $dispute->submitted_by_member_id ?? $dispute->member_id;
        return AVPVH_DB::get_member((int) $member_id);
    }

    /** Returns a notice code; errors preserve the draft in the admin handler. */
    public static function act(int $id, string $action, string $message, string $request_key): string|WP_Error {
        if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
            return new WP_Error('forbidden', 'Geen toegang.');
        }
        if (!preg_match(self::REQUEST_KEY, $request_key)
            || !in_array($action, ['reply', 'note', 'resolve', 'reopen'], true)) {
            return new WP_Error('invalid_action', 'Ongeldige actie. Herlaad de pagina en probeer opnieuw.');
        }
        $dispute = AVBK_DB::get_dispute($id);
        if (!$dispute) {
            return new WP_Error('not_found', 'Dit gesprek bestaat niet meer.');
        }
        if (AVBK_DB::has_dispute_request($request_key)) {
            return 'duplicate';
        }

        $message = trim($message);
        if (in_array($action, ['reply', 'note'], true) && $message === '') {
            return new WP_Error('empty_message', 'Vul eerst een antwoord of interne notitie in.');
        }
        $actor_id = get_current_user_id();
        if ($action === 'resolve' || $action === 'reopen') {
            $status = $action === 'resolve' ? 'resolved' : 'open';
            if ($dispute->status === $status) {
                return 'unchanged';
            }
            return AVBK_DB::change_dispute_status($id, $status, $actor_id, $request_key, $message)
                ? ($action === 'resolve' ? 'resolved' : 'reopened')
                : new WP_Error('save_failed', 'De actie is niet opgeslagen. Probeer opnieuw.');
        }
        if ($action === 'note') {
            return AVBK_DB::add_dispute_event($id, 'note', $message, $actor_id, $request_key)
                ? 'note_saved'
                : new WP_Error('save_failed', 'De notitie is niet opgeslagen. Probeer opnieuw.');
        }

        return self::send_reply($dispute, $message, $request_key);
    }

    /**
     * The penningmeester starts a conversation with a member: the thread
     * has no opening text of its own, the first 'reply' event is the
     * message, mailed like any answer. Returns the thread id, or an error.
     */
    public static function start(int $member_id, string $subject, ?int $fee_item_id, string $message, string $request_key): int|WP_Error {
        $message = trim($message);
        $error = self::start_rejection($message, $request_key);
        $existing = $error ? null : AVBK_DB::get_thread_by_request($request_key);
        if ($error || $existing) {
            return $error ?? (int) $existing->id;
        }
        $thread_id = AVBK_DB::create_message_thread([
            'member_id'              => $member_id,
            'submitted_by_member_id' => null,
            'subject'                => trim($subject),
            'fee_item_id'            => $fee_item_id ?: null,
            'started_by'             => 'treasurer',
            'message'                => '',
            'request_key'            => $request_key,
        ]);
        $thread = $thread_id ? AVBK_DB::get_dispute($thread_id) : null;
        $sent = $thread ? self::send_reply($thread, $message, wp_generate_uuid4()) : new WP_Error('save_failed', 'Het bericht is niet opgeslagen en niet verstuurd. Probeer opnieuw.');
        // A failed mail still leaves the conversation on the member page; it shows as failed in Berichten.
        return is_wp_error($sent) && $sent->get_error_code() !== 'reply_failed' ? $sent : $thread_id;
    }

    /** Why the penningmeester can't start this conversation, or null. */
    private static function start_rejection(string $message, string $request_key): ?WP_Error {
        if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
            return new WP_Error('forbidden', 'Geen toegang.');
        }
        if ($message === '') {
            return new WP_Error('empty_message', 'Vul een bericht in.');
        }
        return preg_match(self::REQUEST_KEY, $request_key) ? null : new WP_Error('invalid_action', 'Ongeldige actie. Herlaad de pagina en probeer opnieuw.');
    }

    /**
     * Saves the penningmeester's message and mails it with the whole text
     * and a link to the conversation on the member's page; then it is the
     * member's turn.
     */
    private static function send_reply(object $dispute, string $message, string $request_key): string|WP_Error {
        $id = (int) $dispute->id;
        $recipient = self::recipient($dispute);
        $email = $recipient ? (string) ($recipient->email ?? '') : '';
        if (!is_email($email)) {
            return new WP_Error('missing_email', 'Er is geen geldig e-mailadres voor de ontvanger. Het antwoord is niet verstuurd.');
        }

        // Reserve and retain the answer BEFORE sending. The unique request key
        // prevents a double click or replay from sending the same form twice.
        $event_id = AVBK_DB::add_dispute_event($id, 'reply', $message, get_current_user_id(), $request_key, $email, 'pending');
        if (!$event_id) {
            return AVBK_DB::has_dispute_request($request_key) ? 'duplicate'
                : new WP_Error('save_failed', 'Het antwoord is niet opgeslagen en niet verstuurd. Probeer opnieuw.');
        }
        return self::deliver($dispute, $recipient, $email, $message, $event_id);
    }

    /** Mails the saved answer and records the outcome; then it is the member's turn. */
    private static function deliver(object $dispute, object $recipient, string $email, string $message, int $event_id): string|WP_Error {
        $id = (int) $dispute->id;

        $from = sanitize_email(get_option('avbk_penningmeester_email', 'penningmeester@avphilipsvanhorne.nl'));
        if (!is_email($from)) {
            $from = 'penningmeester@avphilipsvanhorne.nl';
        }
        [$subject, $body] = self::reply_mail($dispute, $recipient, $message);
        $force_from = static fn($current) => $from;
        $force_name = static fn($current) => 'AV-PvH Penningmeester';
        add_filter('wp_mail_from', $force_from, PHP_INT_MAX);
        add_filter('wp_mail_from_name', $force_name, PHP_INT_MAX);
        try {
            $sent = avbk_mail('dispute_reply', (int) $recipient->id, $email, $subject, $body, [
                'From: AV-PvH Penningmeester <' . $from . '>',
                'Reply-To: ' . $from,
                'Content-Type: text/plain; charset=UTF-8',
            ]);
        } catch (\Throwable $error) {
            $sent = false;
        } finally {
            remove_filter('wp_mail_from', $force_from, PHP_INT_MAX);
            remove_filter('wp_mail_from_name', $force_name, PHP_INT_MAX);
        }
        if (!AVBK_DB::set_dispute_delivery($event_id, $sent ? 'sent' : 'failed')) {
            return new WP_Error('tracking_failed', 'De verzendstatus kon niet worden opgeslagen. Controleer de verzending voordat je opnieuw verstuurt.');
        }
        AVBK_DB::mark_thread_awaiting_member($id);
        return $sent ? 'reply_sent' : new WP_Error('reply_failed', 'Verzenden is mislukt. Het antwoord staat in de historie en als concept klaar om opnieuw te proberen.');
    }

    /**
     * The mail to the member: the whole message, and a link to read the
     * conversation and answer on the member page.
     *
     * @return array{0: string, 1: string} Subject and plain-text body.
     */
    public static function reply_mail(object $dispute, object $recipient, string $message): array {
        $topic = trim((string) ($dispute->subject ?? ''));
        $subject = '[AV-PvH] ' . ($topic !== '' ? $topic : 'Bericht van de penningmeester') . ' (#' . (int) $dispute->id . ')';
        $first_name = trim((string) ($recipient->first_name ?? ''));
        $signature = sanitize_text_field(get_option('avbk_penningmeester_name', 'de penningmeester'));
        $body = ($first_name !== '' ? 'Dag ' . $first_name . ",\n\n" : '')
            . $message . "\n\nGroet,\n" . $signature
            . "\n\n---\nLees het hele gesprek en reageer op je ledenpagina:\n" . self::member_page_url((int) $dispute->member_id);
        return [$subject, $body];
    }

    /** The conversations on the member page. */
    public static function member_page_url(int $member_id): string {
        return add_query_arg(['member_id' => $member_id], home_url('/leden/beheer/member-profile/')) . '#berichten';
    }
}
