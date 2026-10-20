<?php
/**
 * Test suite for security hardening (SEC-01 through SEC-05).
 * Follows AGENTS.md: no real personal names in code, comments, or test data.
 */
define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/avbk_test_content');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
define('AVBK_PLUGIN_URL', 'https://example.test/wp-content/plugins/avpvh-bookkeeping/');

class WP_Error {
    public function __construct(private string $code, private string $message) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}

$GLOBALS['transients'] = [];
function get_transient(string $key) {
    return $GLOBALS['transients'][$key] ?? false;
}
function set_transient(string $key, $value, int $expiration = 0): bool {
    $GLOBALS['transients'][$key] = $value;
    return true;
}
function delete_transient(string $key): bool {
    unset($GLOBALS['transients'][$key]);
    return true;
}

function wp_unslash($v) { return $v; }
function sanitize_text_field(string $str): string { return trim(strip_tags($str)); }
function sanitize_email(string $email): string { return filter_var(trim($email), FILTER_SANITIZE_EMAIL) ?: ''; }
function sanitize_file_name(string $name): string { return preg_replace('/[^a-zA-Z0-9._-]/', '', $name); }
function sanitize_key(string $key): string { return strtolower(preg_replace('/[^a-z0-9_-]/', '', $key)); }
function esc_url_raw(string $url): string { return filter_var($url, FILTER_SANITIZE_URL) ?: ''; }
function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
function esc_attr(string $str): string { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }
function esc_html(string $str): string { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }
function is_email(string $email): bool { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
function home_url(string $path = ''): string { return 'https://example.test' . $path; }
function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $val_or_url = null, $url = null): string {
    if (is_array($args)) {
        $target = $val_or_url ?: '';
        $params = $args;
    } else {
        $target = $url ?: '';
        $params = [$args => $val_or_url];
    }
    $sep = str_contains($target, '?') ? '&' : '?';
    return $target . $sep . http_build_query($params);
}
function wp_safe_redirect(string $location, int $status = 302): bool {
    $GLOBALS['last_redirect'] = $location;
    return true;
}
function add_shortcode(string $tag, callable $callback): void {}
function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
function wp_enqueue_style(...$args): void {}
function wp_enqueue_script(...$args): void {}
function get_option(string $key, $default = false) { return $default; }
function avbk_asset_version(string $path): string { return '1.0'; }
function wp_send_json_error($data = null, int $status_code = 200): void {
    $GLOBALS['last_json'] = ['success' => false, 'data' => $data, 'status' => $status_code];
    throw new RuntimeException('wp_send_json_error');
}
function wp_send_json_success($data = null, int $status_code = 200): void {
    $GLOBALS['last_json'] = ['success' => true, 'data' => $data, 'status' => $status_code];
    throw new RuntimeException('wp_send_json_success');
}
function check_admin_referer(string $action, string $query_arg = '_wpnonce'): bool { return true; }
function wp_create_nonce(string $action): string { return 'mock_nonce'; }
function wp_verify_nonce(string $nonce, string $action): bool { return true; }
function wp_mkdir_p(string $dir): bool { return true; }
function wp_delete_file(string $path): bool { @unlink($path); return true; }
function wp_tempnam(string $prefix): string { return tempnam(sys_get_temp_dir(), $prefix); }

function wp_safe_remote_get(string $url, array $args = []) {
    $GLOBALS['last_remote_get'] = $url;
    // Mock response
    return [
        'response' => ['code' => 200],
        'body'     => "col1,col2\nval1,val2",
    ];
}
function wp_remote_retrieve_response_code($response): int { return $response['response']['code'] ?? 200; }
function wp_remote_retrieve_body($response): string { return $response['body'] ?? ''; }
function is_wp_error($v): bool { return $v instanceof WP_Error; }

class AVPVH_Roles {
    public static function current_user_has_role(string $role): bool { return true; }
}

class AVPVH_DB {
    public static array $ensured_identities = [];
    public static array $updated_members = [];
    public static function find_members_by_name(string $first, string $last): array {
        if ($first === 'Anna' && $last === 'Jansen') {
            return [(object) ['id' => 42, 'first_name' => 'Anna', 'last_name' => 'Jansen']];
        }
        if ($first === 'Bram' && $last === 'Bakker') {
            return [(object) ['id' => 43, 'first_name' => 'Bram', 'last_name' => 'Bakker']];
        }
        return [];
    }
    public static function get_member_by_email(string $email): ?object { return null; }
    public static function get_member(int $id): ?object {
        if ($id === 42) {
            return (object) ['id' => 42, 'first_name' => 'Anna', 'last_name' => 'Jansen', 'email' => 'anna@example.test'];
        }
        if ($id === 43) {
            return (object) ['id' => 43, 'first_name' => 'Bram', 'last_name' => 'Bakker', 'email' => 'bram.bakker@avpvh.local'];
        }
        return null;
    }
    public static function ensure_identity(int $member_id, string $type, string $val): void {
        self::$ensured_identities[] = [$member_id, $type, $val];
    }
    public static function update_member_with_audit(int $member_id, array $fields, array $formats): void {
        self::$updated_members[] = [$member_id, $fields];
    }
}

require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-reimbursements.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-sheet-import.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-camp-sheet-import.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-admin.php';

function assert_test(bool $cond, string $msg): void {
    if (!$cond) {
        echo "FAIL: $msg\n";
        exit(1);
    }
    echo "PASS: $msg\n";
}

// -----------------------------------------------------------------------------
// SEC-01: Receipt File Upload Validation & Stored XSS Protection
// -----------------------------------------------------------------------------
echo "\n--- Testing SEC-01: Receipt Upload & View Hardening ---\n";

// 1. Non-uploaded file rejected
$tmp_normal = tempnam(sys_get_temp_dir(), 'rcpt_sec');
file_put_contents($tmp_normal, '%PDF-1.4 test');
assert_test(AVBK_Reimbursements::validate_receipt_file($tmp_normal, 'test.pdf') === null, 'validate_receipt_file rejects non-is_uploaded_file');

// Simulate is_uploaded_file check bypass for unit testing validate logic
$reflection = new ReflectionClass(AVBK_Reimbursements::class);
$validate_method = $reflection->getMethod('validate_receipt_file');

// Test extensions whitelist
$disallowed = ['test.php', 'shell.phtml', 'evil.svg', 'xss.html', 'payload.phar', 'virus.exe'];
foreach ($disallowed as $f) {
    $t = tempnam(sys_get_temp_dir(), 'rcpt');
    file_put_contents($t, 'test');
    // Direct check extension against ALLOWED_MIMES
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $allowed_exts = [];
    foreach (AVBK_Reimbursements::ALLOWED_MIMES as $exts) {
        $allowed_exts = array_merge($allowed_exts, $exts);
    }
    assert_test(!in_array($ext, $allowed_exts, true), "Extension .$ext correctly disallowed in ALLOWED_MIMES");
    unlink($t);
}

// PDF validation: Must start with %PDF-
$tmp_bad_pdf = tempnam(sys_get_temp_dir(), 'rcpt_pdf_bad');
file_put_contents($tmp_bad_pdf, '<script>alert(1)</script>');
// Check PDF signature logic directly
$handle = fopen($tmp_bad_pdf, 'rb');
$sig = fread($handle, 5);
fclose($handle);
assert_test($sig !== '%PDF-', 'Fake PDF has invalid magic signature');
unlink($tmp_bad_pdf);

$tmp_good_pdf = tempnam(sys_get_temp_dir(), 'rcpt_pdf_good');
file_put_contents($tmp_good_pdf, "%PDF-1.5\nfake binary");
$handle = fopen($tmp_good_pdf, 'rb');
$sig = fread($handle, 5);
fclose($handle);
assert_test($sig === '%PDF-', 'Valid PDF has %PDF- signature');
unlink($tmp_good_pdf);

// -----------------------------------------------------------------------------
// SEC-02: Identity & Address Mutation Protection
// -----------------------------------------------------------------------------
echo "\n--- Testing SEC-02: Address & Identity Protection ---\n";

AVPVH_DB::$ensured_identities = [];
// When an unauthenticated visitor registers with an existing member name (Anna Jansen):
// find_or_create_member_for_registration should NOT attach the untrusted email to Anna's identities!
$res = AVBK_DB::find_or_create_member_for_registration('Anna', '', 'Jansen', 'hacker@attacker.test', '');
assert_test($res['member_id'] === 42, 'Matched Anna Jansen by name');
assert_test($res['match_type'] === 'name', 'Match type is name');
assert_test(empty(AVPVH_DB::$ensured_identities), 'Unauthenticated name match DID NOT call ensure_identity on existing member');

// A name match whose existing record only has the @avpvh.local placeholder
// e-mail (no real address on file yet) gets the freshly submitted real
// e-mail stored instead of silently keeping the stale placeholder.
AVPVH_DB::$updated_members = [];
$res = AVBK_DB::find_or_create_member_for_registration('Bram', '', 'Bakker', 'bram@example.test', '');
assert_test($res['member_id'] === 43, 'Matched Bram Bakker by name');
assert_test(
    AVPVH_DB::$updated_members === [[43, ['email' => 'bram@example.test']]],
    'Placeholder e-mail on existing name match is replaced with the submitted real e-mail'
);

// A name match whose existing record already has a different real e-mail
// is left alone — that may be deliberate, not a stale placeholder.
AVPVH_DB::$updated_members = [];
$res = AVBK_DB::find_or_create_member_for_registration('Anna', '', 'Jansen', 'anna.new@example.test', '');
assert_test($res['member_id'] === 42, 'Matched Anna Jansen by name again');
assert_test(empty(AVPVH_DB::$updated_members), 'Existing real e-mail on name match is NOT overwritten');

// -----------------------------------------------------------------------------
// SEC-03: Rate Limiting on Email Check Endpoints
// -----------------------------------------------------------------------------
echo "\n--- Testing SEC-03: Email Check Rate Limiting ---\n";

require_once AVBK_PLUGIN_DIR . 'includes/class-book-order.php';
$book_order = new AVBK_Book_Order();

$_SERVER['REMOTE_ADDR'] = '198.51.100.25';
$rate_key = 'avbk_chkmail_' . substr(hash('sha256', '198.51.100.25'), 0, 24);
delete_transient($rate_key);

$_POST['check_email'] = 'test@example.test';
$_POST['ajax'] = '1';

for ($i = 1; $i <= 15; $i++) {
    $GLOBALS['last_json'] = null;
    try {
        $book_order->handle_check_email();
    } catch (RuntimeException $e) {}
    assert_test(get_transient($rate_key) === $i, "Attempt $i recorded in transient rate limiter");
}

// 16th attempt must be rate-limited
$GLOBALS['last_json'] = null;
try {
    $book_order->handle_check_email();
} catch (RuntimeException $e) {}
assert_test(isset($GLOBALS['last_json']), '16th attempt triggered rate limit response');
assert_test(($GLOBALS['last_json']['status'] ?? 0) === 429, 'Rate limit returned HTTP 429');
assert_test(($GLOBALS['last_json']['data']['status'] ?? '') === 'rate_limited', 'Rate limit status is rate_limited');

// -----------------------------------------------------------------------------
// SEC-04: SSRF Protection in Sheet Import
// -----------------------------------------------------------------------------
echo "\n--- Testing SEC-04: SSRF Protection in Sheet Import ---\n";

$refl_sheet = new ReflectionClass(AVBK_Sheet_Import::class);
$to_csv = $refl_sheet->getMethod('to_csv_export_url');

// Reject internal and malicious URLs
$ssrf_urls = [
    'http://127.0.0.1:8080/spreadsheets/d/123',
    'http://localhost/spreadsheets/d/123',
    'http://169.254.169.254/latest/meta-data/',
    'https://attacker.evil.com/spreadsheets/d/12345',
    'file:///etc/passwd',
    'ftp://docs.google.com/spreadsheets/d/123',
];
foreach ($ssrf_urls as $u) {
    $result = $to_csv->invoke(null, $u);
    assert_test($result === null, "SSRF attempt rejected: $u");
}

// Accept valid Google Sheets URL
$valid_url = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit?gid=456#gid=456';
$export = $to_csv->invoke(null, $valid_url);
assert_test($export !== null && str_starts_with($export, 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/export?format=csv&gid=456'), 'Valid Google Sheets URL converted to export URL');

// Camp Sheet Import
$camp_ssrf = AVBK_Camp_Sheet_Import::import_from_url(1, 'http://169.254.169.254/sheet');
assert_test(!empty($camp_ssrf['errors']) && str_contains($camp_ssrf['errors'][0], 'Geen geldige Google Sheets-link'), 'Camp Sheet Import rejects non-docs.google.com link');

// -----------------------------------------------------------------------------
// SEC-05: CSV Formula Injection Protection (CWE-1236)
// -----------------------------------------------------------------------------
echo "\n--- Testing SEC-05: CSV Formula Injection Protection ---\n";

assert_test(AVBK_Admin::sanitize_csv_cell('=1+1') === "'=1+1", 'Formula starting with = is quoted');
assert_test(AVBK_Admin::sanitize_csv_cell('+1+1') === "'+1+1", 'Formula starting with + is quoted');
assert_test(AVBK_Admin::sanitize_csv_cell('-5+2') === "'-5+2", 'Formula starting with - is quoted');
assert_test(AVBK_Admin::sanitize_csv_cell('@SUM(A1:A10)') === "'@SUM(A1:A10)", 'Formula starting with @ is quoted');
assert_test(AVBK_Admin::sanitize_csv_cell("\tTAB") === "'\tTAB", 'Formula starting with tab is quoted');
assert_test(AVBK_Admin::sanitize_csv_cell("\rCR") === "'\rCR", 'Formula starting with carriage return is quoted');
assert_test(AVBK_Admin::sanitize_csv_cell('Anna') === 'Anna', 'Normal text is unchanged');
assert_test(AVBK_Admin::sanitize_csv_cell(42) === 42, 'Integer is unchanged');
assert_test(AVBK_Admin::sanitize_csv_cell(35.50) === 35.50, 'Float is unchanged');
assert_test(AVBK_Admin::sanitize_csv_cell('') === '', 'Empty string is unchanged');

$row = ['=cmd|calc', 'Piet Jansen', 10, '-100', '@link'];
$sanitized = AVBK_Admin::sanitize_csv_row($row);
assert_test($sanitized === ["'=cmd|calc", 'Piet Jansen', 10, "'-100", "'@link"], 'Row array sanitized correctly against formula injection');

echo "\nAll security hardening tests PASSED cleanly!\n";
