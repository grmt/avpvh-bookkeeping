<?php
/** Tests for includes/functions-errors.php: every error a user sees also goes to the log. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['filters'] = [];
function add_filter(string $hook, callable $callback, ...$rest): void { $GLOBALS['filters'][$hook] = $callback; }
function get_current_user_id(): int { return 7; }
function wp_strip_all_tags(string $text): string { return strip_tags($text); }
function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }
function wp_send_json_error($data = null, ?int $status = null): void { $GLOBALS['json_errors'][] = [$data, $status]; }
class WP_Error {
    public function __construct(private string $message = '') {}
    public function get_error_message(): string { return $this->message; }
}
require dirname(__DIR__) . '/includes/functions-errors.php';

$log = tempnam(sys_get_temp_dir(), 'avbk-log-');
ini_set('error_log', $log);
$failures = 0;
function check(bool $ok, string $label): void { global $failures; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) $failures++; }
function log_lines(): array { global $log; $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log)))); file_put_contents($log, ''); return $lines; }
function request(array $request, array $get = []): void { $_REQUEST = $request; $_GET = $get; }

avbk_register_error_logging();
$died = [];
$die = $GLOBALS['filters']['wp_die_handler'](static function ($message, $title, $args) use (&$died) { $died[] = $message; });

request(['action' => 'avbk_request_payment']);
$die('Geen toegang.', '', ['response' => 403]);
$lines = log_lines();
check(count($died) === 1, 'the original wp_die handler still runs');
check(count($lines) === 1 && str_contains($lines[0], 'avbk: avbk_request_payment failed: reason=die user=7 status=403 message=Geen_toegang.'), 'wp_die() in an own request is logged');

request(['action' => 'other_plugin_action']);
$die('Something else.', '', []);
check(log_lines() === [], 'wp_die() in another plugin\'s request is not logged');

request([], ['page' => 'avbk-members']);
$die(new WP_Error('Lid niet gevonden.'), '', []);
check(str_contains(implode("\n", log_lines()), 'avbk: avbk-members failed: reason=die user=7 message=Lid_niet_gevonden.'), 'WP_Error on an own admin page is logged');

request(['action' => 'avbk_check_order']);
$die('', '', ['response' => null]);
check(log_lines() === [], 'the empty wp_die() that ends a JSON response is not logged');

$redirect = $GLOBALS['filters']['wp_redirect'];
request(['action' => 'avbk_book_order']);
check($redirect('https://example.org/boek/?book_error=unauthorized') === 'https://example.org/boek/?book_error=unauthorized', 'the redirect location is unchanged');
check(str_contains(implode("\n", log_lines()), 'avbk: avbk_book_order failed: reason=book_error user=7 value=unauthorized'), 'an error redirect is logged');
$redirect('https://example.org/x/?process_error=' . rawurlencode('Ongeldig adres: anna@example.org'));
$line = implode("\n", log_lines());
check(str_contains($line, 'value=Ongeldig_adres:_<address>') && !str_contains($line, '@'), 'addresses in a redirect message are masked');
$redirect('https://example.org/wp-admin/admin.php?page=avbk-disputes&dispute_result=resolved');
check(log_lines() === [], 'dispute_result=resolved is not an error');
$redirect('https://example.org/wp-admin/admin.php?page=avbk-disputes&dispute_result=save_failed');
check(count(log_lines()) === 1, 'dispute_result=save_failed is logged');
$redirect('https://example.org/?book_token=abc');
check(log_lines() === [], 'a redirect without an error flag is not logged');
request(['action' => 'other_plugin_action']);
$redirect('https://example.org/?import_error=1');
check(log_lines() === [], 'a redirect in another plugin\'s request is not logged');

request(['action' => 'avbk_photo_upload']);
avbk_send_json_error(['message' => 'Kies een foto binnen de aangegeven bestandsgrootte.'], 400);
check(($GLOBALS['json_errors'][0] ?? null) === [['message' => 'Kies een foto binnen de aangegeven bestandsgrootte.'], 400], 'avbk_send_json_error() still sends the JSON error');
check(str_contains(implode("\n", log_lines()), 'avbk: avbk_photo_upload failed: reason=json_error user=7 status=400 message=Kies_een_foto'), 'the JSON error is logged');

unlink($log);
exit($failures ? 1 : 0);
