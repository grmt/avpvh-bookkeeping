<?php
/** Reading a selected part of a receipt, and the OCR engine a member chooses. Fictional data only. */
define('ABSPATH', __DIR__ . '/');
function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key)); }
function sanitize_file_name($name) { return (string) $name; }
function wp_get_image_mime($file) { $info = @getimagesize($file); return $info['mime'] ?? false; }
/** Stands in for the real OCR: records what it was asked, answers with $GLOBALS['ocr_text']. */
final class AVBK_OCR {
    public static array $calls = [];
    public static function engines(): array { return ['docling' => 'Docling (eigen server)', 'google' => 'Google']; }
    public static function extract_text(string $path, int $timeout = 30, ?string $engine = null): ?string {
        self::$calls[] = [$timeout, $engine];
        return $GLOBALS['ocr_text'];
    }
}
require dirname(__DIR__) . '/includes/interface-component.php';
require dirname(__DIR__) . '/includes/class-reimbursements.php';

$failures = 0;
function check(bool $ok, string $label): void { global $failures; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) $failures++; }

$png = tempnam(sys_get_temp_dir(), 'avbk-region');
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$text_file = tempnam(sys_get_temp_dir(), 'avbk-region');
file_put_contents($text_file, 'not an image');

try {
    check(AVBK_Reimbursements::requested_engine('google') === 'google' && AVBK_Reimbursements::requested_engine(' Docling ') === 'docling', 'a configured engine is used');
    check(AVBK_Reimbursements::requested_engine('tesseract') === null && AVBK_Reimbursements::requested_engine(['google']) === null && AVBK_Reimbursements::requested_engine('') === null, 'an unknown or malformed engine falls back to the default');

    $GLOBALS['ocr_text'] = "  TOTAAL\n 11,80 ";
    check(AVBK_Reimbursements::read_region($png, 'google') === ['text' => 'TOTAAL 11,80', 'error' => ''], 'a selected part: its text on one line');
    check(end(AVBK_OCR::$calls) === [20, 'google'], 'read with the chosen engine and a short timeout');

    $GLOBALS['ocr_text'] = null;
    check(str_contains(AVBK_Reimbursements::read_region($png, null)['error'], 'geen tekst gevonden'), 'nothing read: says so, so the member can select more or type it');

    $calls = count(AVBK_OCR::$calls);
    check(AVBK_Reimbursements::read_region($text_file, null) === ['text' => '', 'error' => 'Ongeldig fragment'] && count(AVBK_OCR::$calls) === $calls, 'not a PNG: refused before any OCR');
    check(AVBK_Reimbursements::validate_receipt_content($png, 'bon.png') === 'png' && AVBK_Reimbursements::validate_receipt_content($png, 'bon.svg') === null, 'content check: real bytes and an allowed extension');
    $pdf = tempnam(sys_get_temp_dir(), 'avbk-region');
    file_put_contents($pdf, "%PDF-1.4 fictional");
    check(AVBK_Reimbursements::validate_receipt_content($pdf, 'bon.pdf') === 'pdf' && AVBK_Reimbursements::validate_receipt_content($text_file, 'bon.pdf') === null, 'content check: a PDF by its signature');
    check(AVBK_Reimbursements::validate_receipt_content($pdf, 'bon.png') === null && AVBK_Reimbursements::validate_receipt_content($png, 'bon.jpg') === null, 'content check: the extension has to match the real type');
    unlink($pdf);
} finally {
    unlink($png);
    unlink($text_file);
}
exit($failures ? 1 : 0);
