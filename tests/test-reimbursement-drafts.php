<?php
/** Server-side automatic reimbursement draft handlers and draft submission. Fictional data only. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_URL', 'https://example.test/wp-content/plugins/avpvh-bookkeeping/');
define('AVBK_RECEIPTS_DIR', sys_get_temp_dir() . '/avbk-draft-test-' . getmypid());

$GLOBALS['logged_in'] = true;
$GLOBALS['current_user'] = 5;
$GLOBALS['member_id'] = 7;
$GLOBALS['redirect'] = null;
$GLOBALS['mails'] = [];
$GLOBALS['drafts'] = [];
$GLOBALS['receipts'] = [];
$GLOBALS['next_id'] = 100;
$GLOBALS['duplicate'] = false;
$GLOBALS['ocr_text'] = "Bakkerij Voorbeeld\n03.08 2026\nTotaal 31,70";

function is_user_logged_in() { return $GLOBALS['logged_in']; }
function check_ajax_referer($action, $field) { return true; }
function check_admin_referer($action) { return true; }
function avbk_send_json_error($data = null) { throw new RuntimeException('error: ' . (is_string($data) ? $data : '')); }
function wp_send_json_success($data) { throw new RuntimeException('success: ' . json_encode($data)); }
function wp_die($msg) { throw new RuntimeException('die: ' . $msg); }
function wp_safe_redirect($url) { $GLOBALS['redirect'] = $url; throw new RuntimeException('redirect: ' . $url); }
function wp_get_referer() { return 'https://example.test/leden/beheer/declareren/'; }
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($arg1, $arg2 = null, $arg3 = null) {
    if (is_array($arg1)) {
        return $arg2 . '?' . http_build_query($arg1);
    }
    return $arg3 . '?' . http_build_query([$arg1 => $arg2]);
}
function wp_create_nonce($action) { return 'nonce-' . $action; }
function wp_nonce_url($url, $action) { return htmlspecialchars($url . '&_wpnonce=' . $action, ENT_QUOTES, 'UTF-8'); }
function wp_date($format, $timestamp = null) { return date($format, $timestamp ?? time()); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function esc_url($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function wp_unslash($value) { return $value; }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key)); }
function sanitize_file_name($name) { return (string) $name; }
function sanitize_text_field($v) { return trim((string) $v); }
function sanitize_textarea_field($v) { return trim((string) $v); }
function wp_get_image_mime($file) { $info = @getimagesize($file); return $info['mime'] ?? false; }
function get_current_user_id() { return $GLOBALS['current_user']; }
function get_option($name, $default = false) { return $default; }
function avpvh_get_member_by_wp_user($uid) { return $uid === 5 ? (object) ['id' => $GLOBALS['member_id'], 'first_name' => 'Piet', 'last_name' => 'Jansen'] : null; }
function avpvh_format_name($m) { return $m->first_name . ' ' . $m->last_name; }
function wp_mail($to, $subject, $body, $headers = []) { $GLOBALS['mails'][] = [$to, $subject, $body]; return true; }
function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
function wp_generate_password($len = 12) { return 'file' . (++$GLOBALS['next_id']); }

final class AVBK_OCR {
    public static function engines(): array { return ['docling' => 'Docling', 'google' => 'Google']; }
    public static function extract_text(string $path, int $timeout = 30, ?string $engine = null): ?string { return $GLOBALS['ocr_text']; }
    public static function guess_total(string $text): ?float { return preg_match('/(\d+),(\d{2})/', $text, $m) ? (float) ($m[1] . '.' . $m[2]) : null; }
    public static function guess_date(string $text): ?string { return str_contains($text, '03.08 2026') ? '2026-08-03' : null; }
    public static function guess_store(string $text): ?string { return strtok($text, "\n") ?: null; }
    public static function candidates(string $text): array { return ['amounts' => [31.7], 'dates' => ['2026-08-03'], 'stores' => ['Bakkerij Voorbeeld']]; }
}

final class AVBK_DB {
    public static array $remembered = [];
    public static function get_reimbursement(int $id): ?object { return $GLOBALS['drafts'][$id] ?? null; }
    public static function get_reimbursements_for_member(int $member_id): array {
        return array_values(array_filter($GLOBALS['drafts'], fn($d) => (int) $d->member_id === $member_id && $d->status !== 'draft'));
    }
    public static function get_draft_reimbursement(int $member_id): ?object {
        foreach ($GLOBALS['drafts'] as $d) {
            if ((int) $d->member_id === $member_id && $d->status === 'draft') return $d;
        }
        return null;
    }
    public static function get_or_create_draft_reimbursement(int $member_id, string $default_iban = ''): int {
        $existing = self::get_draft_reimbursement($member_id);
        if ($existing) return (int) $existing->id;
        $id = ++$GLOBALS['next_id'];
        $GLOBALS['drafts'][$id] = (object) [
            'id' => $id, 'member_id' => $member_id, 'activity_id' => null, 'description' => '',
            'member_note' => '', 'amount' => 0.0, 'ocr_amount' => null, 'iban' => $default_iban, 'status' => 'draft',
            'created_at' => '2026-11-27 19:00:00',
        ];
        return $id;
    }
    public static function add_reimbursement_receipt(int $reimbursement_id, array $data): int {
        $id = ++$GLOBALS['next_id'];
        $GLOBALS['receipts'][$id] = (object) (['id' => $id, 'reimbursement_id' => $reimbursement_id] + $data);
        return $id;
    }
    public static function get_reimbursement_receipts(int $reimbursement_id): array {
        return array_values(array_filter($GLOBALS['receipts'], fn($r) => (int) $r->reimbursement_id === $reimbursement_id));
    }
    public static function get_member_receipt(int $reimbursement_id, int $receipt_id, int $member_id, string $status): ?object {
        $d = $GLOBALS['drafts'][$reimbursement_id] ?? null;
        $r = $GLOBALS['receipts'][$receipt_id] ?? null;
        return ($d && $r && (int) $d->member_id === $member_id && $d->status === $status && (int) $r->reimbursement_id === $reimbursement_id) ? $r : null;
    }
    public static function update_member_receipt(int $reimbursement_id, int $receipt_id, int $member_id, array $data, string $status = 'pending'): bool {
        $r = self::get_member_receipt($reimbursement_id, $receipt_id, $member_id, $status);
        if (!$r) return false;
        foreach ($data as $k => $v) $r->$k = $v;
        return true;
    }
    public static function get_draft_receipt(int $draft_id, int $receipt_id, int $member_id): ?object {
        return self::get_member_receipt($draft_id, $receipt_id, $member_id, 'draft');
    }
    public static function update_draft_reimbursement(int $id, int $member_id, array $data): bool {
        $d = $GLOBALS['drafts'][$id] ?? null;
        if (!$d || (int) $d->member_id !== $member_id || $d->status !== 'draft') return false;
        foreach ($data as $k => $v) $d->$k = $v;
        return true;
    }
    public static function update_draft_receipt(int $draft_id, int $receipt_id, int $member_id, array $data): bool {
        return self::update_member_receipt($draft_id, $receipt_id, $member_id, $data, 'draft');
    }
    public static function attach_member_receipt_photo(int $reimbursement_id, int $receipt_id, int $member_id, array $data): bool {
        $r = self::get_member_receipt($reimbursement_id, $receipt_id, $member_id, 'draft')
            ?? self::get_member_receipt($reimbursement_id, $receipt_id, $member_id, 'pending');
        if (!$r || trim((string) ($r->receipt_path ?? '')) !== '') return false;
        $r->receipt_path = (string) $data['receipt_path'];
        $r->receipt_hash = (string) ($data['receipt_hash'] ?? '');
        if (empty($r->date) && !empty($data['ocr_date'])) $r->date = $data['ocr_date'];
        if (trim((string) ($r->store ?? '')) === '' && !empty($data['ocr_store'])) $r->store = (string) $data['ocr_store'];
        if ($r->amount === null && $data['ocr_amount'] !== null) $r->amount = (float) $data['ocr_amount'];
        return true;
    }
    public static function delete_draft_receipt(int $draft_id, int $receipt_id, int $member_id): ?object {
        $r = self::get_draft_receipt($draft_id, $receipt_id, $member_id);
        if (!$r) return null;
        unset($GLOBALS['receipts'][$receipt_id]);
        return $r;
    }
    public static function delete_draft_reimbursement(int $draft_id, int $member_id): array {
        $d = $GLOBALS['drafts'][$draft_id] ?? null;
        if (!$d || (int) $d->member_id !== $member_id || $d->status !== 'draft') return [];
        $paths = [];
        foreach ($GLOBALS['receipts'] as $id => $r) {
            if ((int) $r->reimbursement_id === $draft_id) {
                if ($r->receipt_path !== '') $paths[] = $r->receipt_path;
                unset($GLOBALS['receipts'][$id]);
            }
        }
        unset($GLOBALS['drafts'][$draft_id]);
        return $paths;
    }
    public static function submit_draft_reimbursement(int $draft_id, int $member_id, array $data): bool {
        $d = $GLOBALS['drafts'][$draft_id] ?? null;
        if (!$d || (int) $d->member_id !== $member_id || $d->status !== 'draft') return false;
        foreach ($data as $k => $v) $d->$k = $v;
        $d->status = 'pending';
        return true;
    }
    public static function refresh_reimbursement_description_summary(int $id): void {
        $d = $GLOBALS['drafts'][$id] ?? null;
        if ($d) {
            $d->description = implode('; ', array_filter(array_map(fn($r) => (string) $r->description, self::get_reimbursement_receipts($id))));
        }
    }
    public static function remember_iban(int $member_id, string $iban, string $name): void { self::$remembered[] = [$member_id, $iban]; }
    public static function log_mail(array $data): void {}
    public static function find_duplicate_receipt(int $member_id, string $hash, ?string $date, ?string $store, int $exclude_receipt_id = 0, bool $ignore_drafts = false): ?object {
        return $GLOBALS['duplicate'] ? (object) ['id' => 999] : null;
    }
}

require dirname(__DIR__) . '/includes/interface-component.php';
require dirname(__DIR__) . '/includes/class-reimbursements.php';

final class Test_Draft_Reimbursements extends AVBK_Reimbursements {
    protected function uploaded_path(string $field): string { return $GLOBALS['uploaded'][$field] ?? ''; }
    protected function store_receipt_file(string $tmp_path, string $validated_ext): string {
        wp_mkdir_p(self::receipts_dir());
        $name = wp_generate_password() . '.' . $validated_ext;
        copy($tmp_path, self::receipts_dir() . '/' . $name);
        return $name;
    }
    protected function delete_receipt_file(string $filename): void {
        $path = self::receipts_dir() . '/' . basename($filename);
        if ($filename !== '' && file_exists($path)) unlink($path);
    }
}

$failures = 0;
function check(bool $ok, string $label): void { global $failures; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) $failures++; }
function run_handler(callable $fn): array {
    try { $fn(); return ['ok', null]; } catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'success: ')) return ['success', json_decode(substr($e->getMessage(), 9), true)];
        if (str_starts_with($e->getMessage(), 'error: ')) return ['error', substr($e->getMessage(), 7)];
        if (str_starts_with($e->getMessage(), 'redirect: ')) return ['redirect', substr($e->getMessage(), 10)];
        return ['other', $e->getMessage()];
    }
}

$png = tempnam(sys_get_temp_dir(), 'avbk-draft');
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$handler = new Test_Draft_Reimbursements();

try {
    // 1. Ensure draft & add uploaded receipt.
    $_POST = ['iban' => 'NL00 TEST 0000 0000 01'];
    [$kind, $res] = run_handler([$handler, 'ajax_draft_ensure']);
    $draft_id = $res['draft_id'];
    check($kind === 'success' && $draft_id > 0 && $GLOBALS['drafts'][$draft_id]->status === 'draft', 'ensure creates a draft for the member');

    $GLOBALS['uploaded'] = ['receipt' => $png];
    $_FILES = ['receipt' => ['name' => 'bon.png']];
    $_POST = ['draft_id' => $draft_id, 'engine' => 'google'];
    [$kind, $added] = run_handler([$handler, 'ajax_draft_add_receipt']);
    $receipt_id = $added['receipt_id'];
    $stored_file = AVBK_RECEIPTS_DIR . '/' . $GLOBALS['receipts'][$receipt_id]->receipt_path;
    check($kind === 'success' && $added['amount'] === 31.7 && $added['date'] === '2026-08-03' && $added['is_image'] === true && file_exists($stored_file), 'adding a receipt runs OCR, stores the file, and returns draft metadata');

    // Duplicate upload is not stored.
    $GLOBALS['duplicate'] = true;
    $count_before = count($GLOBALS['receipts']);
    [$kind, $dup] = run_handler([$handler, 'ajax_draft_add_receipt']);
    check($kind === 'success' && $dup === ['duplicate' => true] && count($GLOBALS['receipts']) === $count_before, 'a duplicate receipt is flagged without storing a file');
    $GLOBALS['duplicate'] = false;

    // 2. Retry OCR on stored receipt & save edited fields + header.
    $GLOBALS['ocr_text'] = "Supermarkt\n03.08 2026\nTotaal 42,50";
    $_POST = ['draft_id' => $draft_id, 'receipt_id' => $receipt_id, 'engine' => 'google'];
    [$kind, $retried] = run_handler([$handler, 'ajax_draft_retry_receipt']);
    check($kind === 'success' && $retried['amount'] === 42.5 && $retried['store'] === 'Supermarkt', 'retry re-reads the stored receipt file');

    $_POST = ['draft_id' => $draft_id, 'receipt_id' => $receipt_id, 'date' => '2026-08-04', 'store' => 'Markt', 'description' => 'fruit', 'amount' => '12,34'];
    [$kind] = run_handler([$handler, 'ajax_draft_save_receipt']);
    check($kind === 'success' && $GLOBALS['receipts'][$receipt_id]->store === 'Markt' && $GLOBALS['receipts'][$receipt_id]->amount === 12.34, 'saving a receipt updates its fields on the draft');

    $_POST = ['draft_id' => $draft_id, 'activity_id' => '9', 'amount' => '12,34', 'iban' => 'NL00TEST0000000001', 'member_note' => 'voorgeschoten'];
    [$kind] = run_handler([$handler, 'ajax_draft_save']);
    check($kind === 'success' && $GLOBALS['drafts'][$draft_id]->activity_id === 9 && $GLOBALS['drafts'][$draft_id]->member_note === 'voorgeschoten', 'saving header updates activity, amount, IBAN, and note');

    // 3. Add manual row (left empty -> pruned on submit) + another manual row (removed via AJAX).
    $_POST = ['draft_id' => $draft_id];
    [$kind, $m1] = run_handler([$handler, 'ajax_draft_add_manual']);
    [$kind2, $m2] = run_handler([$handler, 'ajax_draft_add_manual']);
    check($kind === 'success' && $kind2 === 'success' && $m1['receipt_id'] !== $m2['receipt_id'], 'manual rows are added to the draft');

    $_POST = ['draft_id' => $draft_id, 'receipt_id' => $m2['receipt_id']];
    [$kind] = run_handler([$handler, 'ajax_draft_remove_receipt']);
    check($kind === 'success' && !isset($GLOBALS['receipts'][$m2['receipt_id']]), 'removing a receipt deletes it from the draft');

    // 4. Client serialization (photoUrl must use raw '&', never HTML-escaped '&amp;').
    $client = AVBK_Reimbursements::receipt_to_client($draft_id, $GLOBALS['receipts'][$receipt_id]);
    check($client['id'] === $receipt_id && $client['hasReceipt'] === true && $client['isImage'] === true && $client['store'] === 'Markt' && str_contains($client['photoUrl'], '&id=' . $draft_id . '&receipt=' . $receipt_id) && !str_contains($client['photoUrl'], '&amp;'), 'receipt_to_client formats a draft receipt with an unescaped URL for the browser');

    // 5. Submitting the draft syncs posted values, strips empty manual rows, promotes draft -> pending, and emails treasurer.
    $_POST = [
        'draft_id'    => $draft_id,
        'activity_id' => '9',
        'iban'        => 'NL00TEST0000000001',
        'member_note' => 'klaar',
        'receipt_id'  => [$receipt_id, $m1['receipt_id']],
        'date'        => ['2026-08-05', ''],
        'store'       => ['Markt Centrum', ''],
        'description' => ['groente', ''],
        'row_amount'  => ['12,34', ''],
        'amount'      => '12,34',
    ];
    [$kind, $url] = run_handler([$handler, 'handle_submit']);
    check($kind === 'redirect' && str_contains($url, 'reimbursement_submitted=1'), 'submitting a draft redirects with submitted notice');
    check($GLOBALS['drafts'][$draft_id]->status === 'pending' && $GLOBALS['receipts'][$receipt_id]->store === 'Markt Centrum' && $GLOBALS['receipts'][$receipt_id]->amount === 12.34 && !isset($GLOBALS['receipts'][$m1['receipt_id']]), 'submitting promotes draft to pending, syncs final edits, and drops empty manual rows');
    check(count($GLOBALS['mails']) === 1, 'penningmeester is emailed only on submit, never during draft saves');

    // 5b. Editing a receipt on a still-pending declaration via AJAX works for the owner and rejects non-owners / paid ones.
    $_POST = ['reimbursement_id' => $draft_id, 'receipt_id' => $receipt_id, 'date' => '2026-08-06', 'store' => 'Bakkerij', 'description' => 'brood', 'amount' => '15,50'];
    [$kind] = run_handler([$handler, 'ajax_member_update_receipt']);
    check($kind === 'success' && $GLOBALS['receipts'][$receipt_id]->store === 'Bakkerij' && $GLOBALS['receipts'][$receipt_id]->amount === 15.5, 'member can update a receipt on their own pending declaration');
    $GLOBALS['member_id'] = 99;
    [$kind] = run_handler([$handler, 'ajax_member_update_receipt']);
    check($kind === 'error', 'another member cannot update someone else\'s pending receipt');
    $GLOBALS['member_id'] = 7;

    // 5c. Attaching a photo to a photo-less row on a pending declaration keeps existing text and fills empty fields.
    $manual_pending_id = AVBK_DB::add_reimbursement_receipt($draft_id, [
        'receipt_path' => '', 'receipt_hash' => '', 'description' => 'eigen omschrijving',
        'date' => null, 'store' => 'Eigen Winkel', 'amount' => null, 'ocr_amount' => null, 'ocr_date' => null, 'ocr_store' => '',
    ]);
    $GLOBALS['ocr_text'] = "Gescande Winkel\n03.08 2026\nTotaal 22,50";
    $GLOBALS['uploaded'] = ['receipt' => $png];
    $_FILES = ['receipt' => ['name' => 'bon.png']];
    $_POST = ['reimbursement_id' => $draft_id, 'receipt_id' => $manual_pending_id];
    [$kind, $attached] = run_handler([$handler, 'ajax_member_attach_receipt_photo']);
    check($kind === 'success' && $attached['is_image'] === true && $GLOBALS['receipts'][$manual_pending_id]->store === 'Eigen Winkel' && $GLOBALS['receipts'][$manual_pending_id]->date === '2026-08-03' && $GLOBALS['receipts'][$manual_pending_id]->amount === 22.5, 'attaching a photo stores it, fills empty fields, and keeps existing values');
    // Cannot overwrite an already-attached photo.
    [$kind] = run_handler([$handler, 'ajax_member_attach_receipt_photo']);
    check($kind === 'error', 'attaching refuses a row that already has a photo');

    // 6. Member page summary shows own draft + pending declarations, and hides private draft from another viewer.
    $_POST = ['iban' => 'NL00TEST0000000001'];
    [, $d2] = run_handler([$handler, 'ajax_draft_ensure']);
    $_POST = ['draft_id' => $d2['draft_id']];
    [, $a2] = run_handler([$handler, 'ajax_draft_add_receipt']);
    $file2 = AVBK_RECEIPTS_DIR . '/' . $GLOBALS['receipts'][$a2['receipt_id']]->receipt_path;
    check(file_exists($file2), 'second draft stored its receipt file');

    ob_start();
    AVBK_Reimbursements::render_member_summary((object) ['id' => 7], (object) ['id' => 7]);
    $own_html = (string) ob_get_clean();
    check(str_contains($own_html, 'Verder met concept') && str_contains($own_html, 'In behandeling') && str_contains($own_html, 'Concept'), 'member summary shows own draft and pending declarations');

    ob_start();
    AVBK_Reimbursements::render_member_summary((object) ['id' => 99], (object) ['id' => 7]);
    $admin_html = (string) ob_get_clean();
    check(!str_contains($admin_html, 'Concept') && str_contains($admin_html, 'In behandeling'), 'member summary hides private draft from another viewer while showing pending');

    $_POST = ['draft_id' => $d2['draft_id']];
    [$kind] = run_handler([$handler, 'ajax_draft_discard']);
    check($kind === 'success' && !isset($GLOBALS['drafts'][$d2['draft_id']]) && !file_exists($file2), 'discarding a draft deletes its rows and receipt files');
} finally {
    if (file_exists($png)) unlink($png);
    if (is_dir(AVBK_RECEIPTS_DIR)) {
        foreach (glob(AVBK_RECEIPTS_DIR . '/*') ?: [] as $f) unlink($f);
        rmdir(AVBK_RECEIPTS_DIR);
    }
}
exit($failures ? 1 : 0);
