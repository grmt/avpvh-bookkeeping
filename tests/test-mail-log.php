<?php
/**
 * avbk_mail(): every plugin mail is logged with its kind, member and
 * outcome; only a captured wp_mail_failed error counts as failed.
 * Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');

$GLOBALS['hooks'] = [];
function add_action(string $hook, callable $callback): void { $GLOBALS['hooks'][$hook][] = $callback; }
function remove_action(string $hook, callable $callback): void {
    $GLOBALS['hooks'][$hook] = array_values(array_filter($GLOBALS['hooks'][$hook] ?? [], fn($c) => $c !== $callback));
}
class Test_Mail_Error { public function __construct(private string $message) {} public function get_error_message(): string { return $this->message; } }

/** How the fake wp_mail() behaves: 'ok', 'error' (fires wp_mail_failed), or 'false' (returns false without error). */
$GLOBALS['mail_mode'] = 'ok';
function wp_mail($to, $subject, $body, $headers = []): bool {
    if ($GLOBALS['mail_mode'] === 'error') {
        foreach ($GLOBALS['hooks']['wp_mail_failed'] ?? [] as $callback) {
            $callback(new Test_Mail_Error('SMTP connect failed'));
        }
        return false;
    }
    return $GLOBALS['mail_mode'] === 'ok';
}
class AVBK_DB {
    public static array $logged = [];
    public static function log_mail(array $mail): void { self::$logged[] = $mail; }
}

require dirname(__DIR__) . '/includes/functions-mail.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

$sent = avbk_mail('payment_request', 7, 'anna@example.test', 'Openstaande betaling', '<!doctype html><html><body>Dag Anna</body></html>');
$log = AVBK_DB::$logged[0];
check($sent, 'Returns what wp_mail() returned');
check($log['kind'] === 'payment_request' && $log['member_id'] === 7 && $log['recipient'] === 'anna@example.test', 'Kind, member and recipient logged');
check($log['status'] === 'sent' && $log['error'] === '', 'Logged as sent');
check($log['is_html'], 'HTML body recognised');

$GLOBALS['mail_mode'] = 'error';
$sent = avbk_mail('treasurer_notice', null, ['a@example.test', 'b@example.test'], 'Melding', 'Platte tekst');
$log = AVBK_DB::$logged[1];
check(!$sent && $log['status'] === 'failed' && $log['error'] === 'SMTP connect failed', 'Captured error logged as failed');
check($log['recipient'] === 'a@example.test, b@example.test' && $log['member_id'] === null, 'Several recipients joined, no member');
check(!$log['is_html'], 'Plain text recognised');
check(($GLOBALS['hooks']['wp_mail_failed'] ?? []) === [], 'Error listener removed again');

$GLOBALS['mail_mode'] = 'false';
avbk_mail('order', 3, 'cas@example.test', 'Bestelling', 'Tekst', ['Content-Type: text/html; charset=UTF-8']);
$log = AVBK_DB::$logged[2];
check($log['status'] === 'sent', 'false without an error counts as sent (Gmail-API connector)');
check($log['is_html'], 'Content-Type header makes it HTML');

exit($failures ? 1 : 0);
