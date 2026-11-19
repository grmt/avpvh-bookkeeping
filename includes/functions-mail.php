<?php
defined('ABSPATH') || exit;

/**
 * Kinds of mail the plugin sends, for the Verzonden mails page. The key is
 * stored with each logged mail.
 */
const AVBK_MAIL_KINDS = [
    'payment_request'    => 'Betaalverzoek',
    'reminder'           => 'Herinnering',
    'reminder_test'      => 'Testherinnering',
    'credit'             => 'Tegoed',
    'donation'           => 'Schenking',
    'dispute_reply'      => 'Bericht aan lid',
    'congress'           => 'Congresinschrijving',
    'order'              => 'Bestelling',
    'order_lookup'       => 'Bestelling opzoeken',
    'photo_share'        => "Foto's delen",
    'treasurer_notice'   => 'Melding aan penningmeester',
];

/**
 * wp_mail() for the plugin's own mails, logged in Verzonden mails (the
 * kind, member, recipient, subject, body and outcome).
 *
 * Only a captured wp_mail_failed error counts as failed. FluentSMTP's
 * Gmail-API connector sometimes returns false for a mail that was
 * delivered, without an error (see AVBK_DB::get_order(), incident
 * 2026-10-19), so the return value alone is not evidence of failure.
 *
 * Mails of other plugins and WordPress itself (login codes, password
 * resets) go through plain wp_mail() and are never logged.
 *
 * @param string|string[] $to
 * @param string|string[] $headers
 */
function avbk_mail(string $kind, ?int $member_id, string|array $to, string $subject, string $body, string|array $headers = []): bool {
    $error = '';
    $capture = static function ($wp_error) use (&$error): void {
        $error = is_object($wp_error) && method_exists($wp_error, 'get_error_message') ? (string) $wp_error->get_error_message() : 'onbekende fout';
    };
    $hooks = function_exists('add_action') && function_exists('remove_action');
    if ($hooks) {
        add_action('wp_mail_failed', $capture);
    }
    try {
        $sent = (bool) wp_mail($to, $subject, $body, $headers);
    } finally {
        if ($hooks) {
            remove_action('wp_mail_failed', $capture);
        }
    }
    avbk_log_sent_mail($kind, $member_id, $to, $subject, $body, $headers, $error);
    return $sent;
}

/**
 * Writes one row to the mail log. A log failure never stops the mail; it
 * only goes to the server log.
 *
 * @param string|string[] $to
 * @param string|string[] $headers
 */
function avbk_log_sent_mail(string $kind, ?int $member_id, string|array $to, string $subject, string $body, string|array $headers, string $error): void {
    if (!method_exists('AVBK_DB', 'log_mail')) {
        return;
    }
    try {
        AVBK_DB::log_mail([
            'kind'      => $kind,
            'member_id' => $member_id ?: null,
            'recipient' => implode(', ', (array) $to),
            'subject'   => $subject,
            'body'      => $body,
            'is_html'   => avbk_mail_is_html($headers, $body),
            'status'    => $error === '' ? 'sent' : 'failed',
            'error'     => $error,
        ]);
    } catch (\Throwable $e) {
        if (function_exists('avbk_log_failure')) {
            avbk_log_failure('mail log', 'insert_failed', ['kind' => $kind]);
        }
    }
}

/** Whether a mail is HTML: a Content-Type header says so, or the body starts like a document. */
function avbk_mail_is_html(string|array $headers, string $body): bool {
    foreach ((array) $headers as $header) {
        if (stripos((string) $header, 'content-type:') === 0 && stripos((string) $header, 'text/html') !== false) {
            return true;
        }
    }
    return stripos(ltrim($body), '<!doctype html') === 0 || stripos(ltrim($body), '<html') === 0;
}
