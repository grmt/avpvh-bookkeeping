<?php
/** Tests for the docling route in AVBK_OCR, with WordPress functions stubbed. Fictional data only. */
define('ABSPATH', __DIR__ . '/');

$options = [];
$requests = [];
$reply = null;
function get_option($name, $default = false) { global $options; return $options[$name] ?? $default; }
function is_wp_error($thing) { return $thing instanceof WP_Error; }
class WP_Error {}
function wp_remote_post($url, $args) { global $requests, $reply; $requests[] = [$url, $args]; return $reply; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function get_transient($name) { return $GLOBALS['token'] ?? false; }
function wp_json_encode($data) { return json_encode($data); }

require_once dirname(__DIR__) . '/includes/class-ocr.php';

$failures = 0;
function check(bool $ok, string $label): void { global $failures; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) $failures++; }

check(AVBK_OCR::docling_url() === null && !AVBK_OCR::available(), 'nothing configured: OCR not available');
$options['avbk_docling_url'] = 'not a url';
check(AVBK_OCR::docling_url() === null, 'an invalid docling URL is ignored');
$options['avbk_docling_url'] = ' http://docling-serve:5001/ ';
check(AVBK_OCR::docling_url() === 'http://docling-serve:5001' && AVBK_OCR::available(), 'docling URL trimmed, trailing slash removed, OCR available');

$file = tempnam(sys_get_temp_dir(), 'avbk-ocr');
file_put_contents($file, "%PDF-1.4 fictional");
$reply = ['code' => 200, 'body' => json_encode(['document' => ['text_content' => "Voorbeeld B.V.\nTotaal 12,00"], 'status' => 'success'])];
$text = AVBK_OCR::extract_text($file, 77);
[$url, $args] = end($requests);
check($text === "Voorbeeld B.V.\nTotaal 12,00", 'text_content from the docling reply');
check($url === 'http://docling-serve:5001/v1/convert/file' && $args['timeout'] === 77, 'posted to /v1/convert/file with the given timeout');
check(str_contains($args['body'], 'name="files"; filename="' . basename($file) . '"') && str_contains($args['body'], '%PDF-1.4 fictional') && str_contains($args['body'], "name=\"to_formats\"\r\n\r\ntext\r\n"), 'multipart body holds the file and asks for text');
check(preg_match('/boundary=(\S+)/', $args['headers']['Content-Type'], $m) === 1 && str_ends_with($args['body'], "--{$m[1]}--\r\n"), 'body closes with the boundary from the header');

$reply = ['code' => 500, 'body' => 'error'];
check(AVBK_OCR::extract_text($file) === null, 'a docling error gives null, not an exception');
$reply = new WP_Error();
check(AVBK_OCR::extract_text($file) === null, 'a connection failure gives null');
check(AVBK_OCR::extract_text($file . '-missing') === null, 'a missing file gives null without a request');

// Choosing an engine for one receipt.
check(AVBK_OCR::engines() === ['docling' => 'Docling (eigen server)'], 'only docling configured: one engine');
$before = count($requests);
check(AVBK_OCR::extract_text($file, 30, 'google') === null && count($requests) === $before, 'an engine that is not configured is not tried, and docling is not used instead');
$options['avbk_gcp_service_account_json'] = json_encode(['private_key' => 'fictional', 'client_email' => 'ocr@example.test']);
check(array_keys(AVBK_OCR::engines()) === ['docling', 'google'], 'both configured: docling first, then Google');
$reply = ['code' => 200, 'body' => json_encode(['document' => ['text_content' => 'Docling text']])];
check(AVBK_OCR::extract_text($file, 30, 'docling') === 'Docling text', 'docling chosen: docling reads it');
$GLOBALS['token'] = 'fictional-token';
$reply = ['code' => 200, 'body' => json_encode(['document' => ['text' => 'Google text']])];
check(AVBK_OCR::extract_text($file, 30, 'google') === 'Google text' && str_contains((string) end($requests)[0], 'documentai.googleapis.com'), 'Google chosen: Google reads it, docling is skipped');
unset($GLOBALS['token']);
unset($options['avbk_gcp_service_account_json']);

// Suggestions to pick from.
$candidates = AVBK_OCR::candidates("Bakkerij Voorbeeld\nDorpsstraat 1\n01-08-2026 10:12\nBrood 3,50\nTaart 8,30\nTotaal 11,80\nBetaald 11,80\n02-08-2026");
check($candidates['amounts'] === [11.8, 8.3, 3.5], 'amounts largest first, each once');
check($candidates['dates'] === ['2026-08-01', '2026-08-02'], 'dates in order of appearance');
check($candidates['stores'] === ['Bakkerij Voorbeeld', 'Dorpsstraat 1', 'Brood 3,50', 'Taart 8,30', 'Totaal 11,80'], 'the first five text lines as shop candidates');
check(AVBK_OCR::candidates('') === ['amounts' => [], 'dates' => [], 'stores' => []], 'no text: no suggestions');

unlink($file);
exit($failures ? 1 : 0);
