<?php
defined('ABSPATH') || exit;

class AVBK_Disputes {

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
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $request_key)
            || !in_array($action, ['reply', 'note', 'resolve', 'reopen'], true)) {
            return new WP_Error('invalid_action', 'Ongeldige actie. Herlaad de pagina en probeer opnieuw.');
        }
        $dispute = AVBK_DB::get_dispute($id);
        if (!$dispute) return new WP_Error('not_found', 'Dit bezwaar bestaat niet meer.');
        if (AVBK_DB::has_dispute_request($request_key)) return 'duplicate';

        $message = trim($message);
        if (in_array($action, ['reply', 'note'], true) && $message === '') {
            return new WP_Error('empty_message', 'Vul eerst een antwoord of interne notitie in.');
        }
        $actor_id = get_current_user_id();
        if ($action === 'resolve' || $action === 'reopen') {
            $status = $action === 'resolve' ? 'resolved' : 'open';
            if ($dispute->status === $status) return 'unchanged';
            return AVBK_DB::change_dispute_status($id, $status, $actor_id, $request_key, $message)
                ? ($action === 'resolve' ? 'resolved' : 'reopened')
                : new WP_Error('save_failed', 'De actie is niet opgeslagen. Probeer opnieuw.');
        }
        if ($action === 'note') {
            return AVBK_DB::add_dispute_event($id, 'note', $message, $actor_id, $request_key)
                ? 'note_saved'
                : new WP_Error('save_failed', 'De notitie is niet opgeslagen. Probeer opnieuw.');
        }

        $recipient = self::recipient($dispute);
        $email = $recipient ? (string) ($recipient->email ?? '') : '';
        if (!is_email($email)) return new WP_Error('missing_email', 'Er is geen geldig e-mailadres voor de ontvanger. Het antwoord is niet verstuurd.');

        // Reserve and retain the answer BEFORE sending. The unique request key
        // prevents a double click or replay from sending the same form twice.
        $event_id = AVBK_DB::add_dispute_event($id, 'reply', $message, $actor_id, $request_key, $email, 'pending');
        if (!$event_id) {
            return AVBK_DB::has_dispute_request($request_key) ? 'duplicate'
                : new WP_Error('save_failed', 'Het antwoord is niet opgeslagen en niet verstuurd. Probeer opnieuw.');
        }

        $from = sanitize_email(get_option('avbk_penningmeester_email', 'penningmeester@avphilipsvanhorne.nl'));
        if (!is_email($from)) $from = 'penningmeester@avphilipsvanhorne.nl';
        $signature = sanitize_text_field(get_option('avbk_penningmeester_name', 'de penningmeester'));
        $body = $message . "\n\nGroet,\n" . $signature . "\n\n---\nJe oorspronkelijke bericht:\n" . $dispute->message;
        $force_from = static fn($current) => $from;
        $force_name = static fn($current) => 'AV-PvH Penningmeester';
        add_filter('wp_mail_from', $force_from, PHP_INT_MAX);
        add_filter('wp_mail_from_name', $force_name, PHP_INT_MAX);
        try {
            $sent = wp_mail($email, '[AV-PvH] Antwoord op je bericht (#' . $id . ')', $body, [
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
        return $sent ? 'reply_sent' : new WP_Error('reply_failed', 'Verzenden is mislukt. Het antwoord staat in de historie en als concept klaar om opnieuw te proberen.');
    }
}
