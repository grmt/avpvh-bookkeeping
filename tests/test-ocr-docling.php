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
function get_transient($name) { return false; }

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

unlink($file);
exit($failures ? 1 : 0);
