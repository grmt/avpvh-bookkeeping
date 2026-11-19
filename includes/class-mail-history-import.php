<?php
defined('ABSPATH') || exit;

/**
 * Brings the plugin's mails from before Verzonden mails existed into it,
 * from FluentSMTP's own log (fsmpt_email_logs), once, at the 1.54 upgrade.
 *
 * Only mails the plugin itself sends are taken over, recognised by their
 * subject (KINDS); login, password and other WordPress mails stay out, as
 * they do for mails logged since (see avbk_mail()). The member is found by
 * the recipient's address. FluentSMTP keeps its log for a limited time
 * only, so this also keeps those mails after FluentSMTP has deleted them.
 */
class AVBK_Mail_History_Import {

    /** Value of avb_mail_log.source for a mail taken over from FluentSMTP. */
    public const SOURCE = 'fluentsmtp';

    /** Subject prefix => mail kind (AVBK_MAIL_KINDS), checked in this order. */
    public const KINDS = [
        'Openstaande betaling'                       => 'payment_request',
        '[AV-PvH] Tegoed'                            => 'credit',
        '[AV-PvH] Antwoord op je bericht'            => 'dispute_reply',
        '[AV-PvH] '                                  => 'treasurer_notice',
        'Bevestig je aanmelding — Congres'           => 'congress',
        'Activeer jouw persoonlijke fotomap'         => 'photo_share',
        'Bevestig je bestelling'                     => 'order',
        'Bevestiging bestelling'                     => 'order',
        'Bestelling geannuleerd'                     => 'order',
        'AV Philips van Horne — Wachtwoord aanmaken' => 'order_lookup',
    ];

    /** The kind of a mail the plugin sent, or null for any other mail. */
    public static function kind_for_subject(string $subject): ?string {
        $subject = trim(html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach (self::KINDS as $prefix => $kind) {
            if (str_starts_with($subject, $prefix)) {
                return $kind;
            }
        }
        return null;
    }

    /**
     * The addresses in FluentSMTP's serialized "to" column.
     *
     * @return string[]
     */
    public static function recipients(string $to): array {
        $value = @unserialize($to, ['allowed_classes' => false]); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed value means no recipients.
        if (!is_array($value)) {
            return $to !== '' && !str_contains($to, '{') ? [trim($to)] : [];
        }
        $emails = [];
        foreach ($value as $entry) {
            $email = is_array($entry) ? (string) ($entry['email'] ?? '') : (string) $entry;
            if ($email !== '') {
                $emails[] = trim($email);
            }
        }
        return $emails;
    }

    /** Whether FluentSMTP's serialized headers or the body make it an HTML mail. */
    public static function is_html(string $headers, string $body): bool {
        return stripos($headers, 'text/html') !== false
            || stripos(ltrim($body), '<!doctype html') === 0
            || stripos(ltrim($body), '<html') === 0;
    }

    /**
     * One FluentSMTP row as a mail log row, or null when it isn't one of
     * the plugin's mails.
     *
     * @param callable(string): ?int $member_for_email
     * @return array<string, mixed>|null
     */
    public static function row_for(object $log, callable $member_for_email): ?array {
        $kind = self::kind_for_subject((string) $log->subject);
        $recipients = self::recipients((string) $log->to);
        if ($kind === null || !$recipients) {
            return null;
        }
        $failed = (string) $log->status === 'failed';
        return [
            'sent_at'   => (string) $log->created_at,
            'kind'      => $kind,
            'member_id' => $member_for_email($recipients[0]),
            'recipient' => mb_substr(implode(', ', $recipients), 0, 255),
            'subject'   => mb_substr(html_entity_decode((string) $log->subject, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 255),
            'body'      => (string) $log->body,
            'is_html'   => self::is_html((string) $log->headers, (string) $log->body) ? 1 : 0,
            'status'    => $failed ? 'failed' : 'sent',
            'error'     => $failed ? 'mislukt volgens FluentSMTP' : '',
            'sent_by'   => null,
            'source'    => self::SOURCE,
        ];
    }

    /**
     * Takes over FluentSMTP's mails from before the first mail the plugin
     * logged itself; a mail already in the log is skipped, so running it
     * again adds nothing.
     *
     * @return int|null Mails taken over, or null when there is no FluentSMTP log.
     */
    public static function run(): ?int {
        global $wpdb;
        $fluent = $wpdb->prefix . 'fsmpt_email_logs';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($fluent))) !== $fluent) {
            return null;
        }
        $log = $wpdb->prefix . 'avb_mail_log';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
        $cutoff = (string) ($wpdb->get_var($wpdb->prepare("SELECT MIN(sent_at) FROM $log WHERE source != %s", self::SOURCE)) ?: current_time('mysql'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, `to`, subject, body, headers, status, created_at FROM $fluent WHERE created_at < %s ORDER BY created_at ASC, id ASC",
            $cutoff
        )) ?: [];
        $members = [];
        $member_for_email = static function (string $email) use (&$members): ?int {
            $key = strtolower($email);
            if (!array_key_exists($key, $members)) {
                $member = AVBK_DB::find_member_by_email($email);
                $members[$key] = $member ? (int) $member->id : null;
            }
            return $members[$key];
        };
        $imported = 0;
        foreach ($rows as $row) {
            $mail = self::row_for($row, $member_for_email);
            if ($mail === null || $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $log WHERE sent_at = %s AND recipient = %s AND subject = %s LIMIT 1",
                $mail['sent_at'],
                $mail['recipient'],
                $mail['subject']
            ))) {
                continue;
            }
            $imported += $wpdb->insert($log, $mail) ? 1 : 0;
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $imported;
    }
}
