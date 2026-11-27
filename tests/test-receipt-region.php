<?php
/** Reading receipts and selected parts of them, the OCR engine a member chooses, and the form's assets. Fictional data only. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_URL', 'https://example.test/wp-content/plugins/avpvh-bookkeeping/');
$GLOBALS['logged_in'] = false;
$GLOBALS['enqueued'] = [];
$GLOBALS['actions'] = [];
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function check_ajax_referer($action, $field) { $GLOBALS['nonce_checked'] = $action; return true; }
function avbk_send_json_error($data = null) { throw new RuntimeException('error: ' . (is_string($data) ? $data : '')); }
function wp_unslash($value) { return $value; }
function has_shortcode($content, $tag) { return str_contains((string) $content, '[' . $tag); }
function wp_enqueue_style($handle, $src) { $GLOBALS['enqueued']['style:' . $handle] = [$src, []]; }
function wp_enqueue_script($handle, $src, $deps = []) { $GLOBALS['enqueued'][$handle] = [$src, $deps]; }
function avbk_asset_version($file) { return '1'; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function wp_create_nonce($action) { return 'nonce-' . $action; }
function wp_json_encode($data) { return json_encode($data); }
function add_action($hook, $callback) { $GLOBALS['actions'][$hook] = $callback; }
function add_shortcode($tag, $callback) { $GLOBALS['shortcodes'][$tag] = $callback; }
function wp_send_json_success($data) { throw new RuntimeException('success: ' . json_encode($data)); }
function get_current_user_id() { return 5; }
function avpvh_get_member_by_wp_user($user_id) { return $user_id === 5 ? (object) ['id' => 7] : null; }
final class AVBK_DB {
    public static array $asked = [];
    public static function find_duplicate_receipt(int $member_id, string $hash, ?string $date, ?string $store): ?object {
        self::$asked[] = [$member_id, $date, $store];
        return $GLOBALS['duplicate'] ? (object) ['id' => 1] : null;
    }
}
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
    public static function guess_total(string $text): ?float { return str_contains($text, '11,80') ? 11.8 : null; }
    public static function guess_date(string $text): ?string { return str_contains($text, '01-08-2026') ? '2026-08-01' : null; }
    public static function guess_store(string $text): ?string { return strtok($text, "\n") ?: null; }
    public static function candidates(string $text): array { return ['amounts' => $text === '' ? [] : [11.8], 'dates' => [], 'stores' => []]; }
}
require dirname(__DIR__) . '/includes/interface-component.php';
require dirname(__DIR__) . '/includes/class-reimbursements.php';

/** The handlers as they run after a real upload: the test says which file was uploaded. */
final class Test_Reimbursements extends AVBK_Reimbursements {
    protected function uploaded_path(string $field): string { return $GLOBALS['uploaded'][$field] ?? ''; }
}

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

    // A whole receipt: the best guesses, the suggestions, and a duplicate check.
    $GLOBALS['ocr_text'] = "Bakkerij Voorbeeld\n01-08-2026\nTotaal 11,80";
    $GLOBALS['duplicate'] = false;
    $read = AVBK_Reimbursements::read_receipt($png, 'bon.png', 7, 'docling');
    check($read === ['amount' => 11.8, 'date' => '2026-08-01', 'store' => 'Bakkerij Voorbeeld', 'duplicate' => false, 'candidates' => ['amounts' => [11.8], 'dates' => [], 'stores' => []]], 'a receipt: guesses and suggestions');
    check(end(AVBK_OCR::$calls) === [30, 'docling'] && end(AVBK_DB::$asked) === [7, '2026-08-01', 'Bakkerij Voorbeeld'], 'read with the chosen engine; the duplicate check gets the member, date and shop');
    $GLOBALS['duplicate'] = true;
    check(AVBK_Reimbursements::read_receipt($png, 'bon.png', 7, null)['duplicate'] === true, 'a receipt declared before is flagged');
    $asked = count(AVBK_DB::$asked);
    check(AVBK_Reimbursements::read_receipt($png, 'bon.png', 0, null)['duplicate'] === false && count(AVBK_DB::$asked) === $asked, 'no member: no duplicate check');
    $GLOBALS['ocr_text'] = null;
    check(AVBK_Reimbursements::read_receipt($png, 'bon.png', 7, null) === ['amount' => null, 'date' => null, 'store' => null, 'duplicate' => true, 'candidates' => ['amounts' => [], 'dates' => [], 'stores' => []]], 'nothing read: empty guesses, no suggestions');
    check(AVBK_Reimbursements::read_receipt($text_file, 'bon.png', 7, null) === null, 'not a valid receipt: null');

    // The AJAX handlers refuse what is not a real upload by a logged-in member.
    $handler = (new ReflectionClass(AVBK_Reimbursements::class))->newInstanceWithoutConstructor();
    $refused = static function (callable $call): string {
        try { $call(); } catch (RuntimeException $e) { return $e->getMessage(); }
        return 'not refused';
    };
    $_FILES = ['receipt' => ['tmp_name' => $png, 'name' => 'bon.png'], 'region' => ['tmp_name' => $png]];
    check($refused([$handler, 'ajax_ocr_receipt']) === 'error: ' && $GLOBALS['nonce_checked'] === 'avbk_ocr_receipt', 'receipt: logged out, refused after the nonce check');
    check($refused([$handler, 'ajax_ocr_region']) === 'error: Ongeldig fragment', 'region: logged out, refused');
    $GLOBALS['logged_in'] = true;
    check($refused([$handler, 'ajax_ocr_receipt']) === 'error: ' && $refused([$handler, 'ajax_ocr_region']) === 'error: Ongeldig fragment', 'a file that was not uploaded is refused');
    $_FILES = [];
    check($refused([$handler, 'ajax_ocr_receipt']) === 'error: ' && $refused([$handler, 'ajax_ocr_region']) === 'error: Ongeldig fragment', 'no file is refused');

    // After a real upload: the handlers answer with what was read.
    $after_upload = (new ReflectionClass(Test_Reimbursements::class))->newInstanceWithoutConstructor();
    $GLOBALS['uploaded'] = ['receipt' => $png, 'region' => $png];
    $_FILES = ['receipt' => ['name' => 'bon.png']];
    $_POST = ['engine' => 'google'];
    $GLOBALS['ocr_text'] = "Bakkerij Voorbeeld\nTotaal 11,80";
    $GLOBALS['duplicate'] = false;
    $answer = $refused([$after_upload, 'ajax_ocr_receipt']);
    check(str_starts_with($answer, 'success: ') && json_decode(substr($answer, 9), true)['amount'] === 11.8 && end(AVBK_OCR::$calls) === [30, 'google'] && end(AVBK_DB::$asked)[0] === 7, 'receipt uploaded: the guesses, read with the chosen engine, checked for this member');
    check($refused([$after_upload, 'ajax_ocr_region']) === 'success: {"text":"Bakkerij Voorbeeld Totaal 11,80"}', 'part uploaded: its text');
    $GLOBALS['uploaded'] = ['receipt' => $text_file, 'region' => $text_file];
    check($refused([$after_upload, 'ajax_ocr_receipt']) === 'error: Ongeldig bestand' && $refused([$after_upload, 'ajax_ocr_region']) === 'error: Ongeldig fragment', 'an upload that is no receipt image is refused');
    $_POST = [];

    // The shortcode and both AJAX actions are registered.
    $after_upload->register();
    check(isset($GLOBALS['shortcodes']['avpvh_bk_reimbursement'], $GLOBALS['actions']['wp_ajax_avbk_ocr_receipt'], $GLOBALS['actions']['wp_ajax_avbk_ocr_region']), 'the form and both OCR actions are registered');

    // The form's scripts only on a page with the form, with the engines in the page config.
    $GLOBALS['post'] = (object) ['post_content' => 'Gewone pagina'];
    $handler->enqueue_assets();
    check($GLOBALS['enqueued'] === [], 'another page: nothing loaded');
    $GLOBALS['post'] = (object) ['post_content' => '[avpvh_bk_reimbursement]'];
    $handler->enqueue_assets();
    check(array_keys($GLOBALS['enqueued']) === ['style:avbk-reimbursement', 'avbk-receipt-viewer', 'avbk-reimbursement'] && $GLOBALS['enqueued']['avbk-reimbursement'][1] === ['avbk-receipt-viewer'], 'the form page loads its style, the viewer, and the form script after the viewer');
    ob_start();
    ($GLOBALS['actions']['wp_footer'])();
    $config = json_decode(preg_replace('#^<script[^>]*>|</script>$#', '', (string) ob_get_clean()), true);
    check($config === ['ajaxUrl' => 'https://example.test/wp-admin/admin-ajax.php', 'nonce' => 'nonce-avbk_ocr_receipt', 'engines' => AVBK_OCR::engines()], 'the page config holds the AJAX URL, the nonce and the engines');
    $GLOBALS['logged_in'] = false;
    $GLOBALS['enqueued'] = [];
    $handler->enqueue_assets();
    check($GLOBALS['enqueued'] === [], 'logged out: nothing loaded');
} finally {
    unlink($png);
    unlink($text_file);
}
exit($failures ? 1 : 0);
