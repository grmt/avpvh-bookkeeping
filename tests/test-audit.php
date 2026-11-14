<?php
/** Tests for the logboek (AVBK_Audit) and the changes AVBK_DB records in it. Fictional data only. */
define('ABSPATH', __DIR__ . '/');
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');

$GLOBALS['current_user'] = 0;
$GLOBALS['failures_logged'] = [];
function get_current_user_id() { return $GLOBALS['current_user']; }
function current_user_can($cap) { return $GLOBALS['current_user'] === 1; }
function current_time($type, $gmt = false) { return '2026-11-13 20:00:00'; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function wp_generate_uuid4() { return '11111111-2222-4333-8444-555555555555'; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function wp_unslash($value) { return $value; }
function wp_doing_cron() { return false; }
function sanitize_email($email) { return trim((string) $email); }
function is_email($email) { return str_contains((string) $email, '@'); }
function avbk_log_failure(string $context, string $reason, array $ids = []): void { $GLOBALS['failures_logged'][] = [$context, $reason]; }

class Fake_WPDB {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public array $rows = ['wp_avb_audit_log' => [], 'wp_avb_fee_items' => [], 'wp_avb_orders' => [], 'wp_avb_transaction_allocations' => []];
    public bool $fail_audit = false;

    public function prepare(string $query, ...$args): string {
        $args = array_map(fn($a) => is_string($a) ? "'" . addslashes($a) . "'" : $a, $args);
        return vsprintf(str_replace(['%d', '%s', '%f'], '%s', $query), $args);
    }
    public function insert(string $table, array $data) {
        if ($this->fail_audit && $table === 'wp_avb_audit_log') return false;
        $this->insert_id = count($this->rows[$table] ?? []) + 1;
        $this->rows[$table][$this->insert_id] = ['id' => $this->insert_id] + $data;
        return 1;
    }
    public function update(string $table, array $data, array $where) {
        $id = (int) $where['id'];
        if (!isset($this->rows[$table][$id])) return 0;
        $this->rows[$table][$id] = $data + $this->rows[$table][$id];
        return 1;
    }
    public function delete(string $table, array $where) { unset($this->rows[$table][(int) ($where['id'] ?? 0)]); return 1; }
    public function get_row(string $query, $output = OBJECT) {
        foreach (['avb_fee_items', 'avb_orders'] as $table) {
            if (str_contains($query, $table) && preg_match('/WHERE id = (\d+)/', $query, $m)) {
                $row = $this->rows['wp_' . $table][(int) $m[1]] ?? null;
                return $row ? (object) $row : null;
            }
        }
        return null;
    }
    public function get_var(string $query) {
        if (str_contains($query, 'SELECT details FROM wp_avb_audit_log') && preg_match('/WHERE id = (\d+)/', $query, $m)) {
            return $this->rows['wp_avb_audit_log'][(int) $m[1]]['details'] ?? null;
        }
        return null;
    }
    public function get_results(string $query, $output = OBJECT): array { return []; }
}
$wpdb = new Fake_WPDB();

require_once AVBK_PLUGIN_DIR . 'includes/interface-component.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-audit.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';

$failures = 0;
function check(bool $ok, string $label): void { global $failures; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) $failures++; }
function audit_rows(): array { return array_values($GLOBALS['wpdb']->rows['wp_avb_audit_log']); }
function last_audit(): array { $rows = audit_rows(); $row = end($rows) ?: []; $row['details'] = json_decode((string) ($row['details'] ?? ''), true); return $row; }

// The request layer: which actions, which ids, which outcome.
check(AVBK_Audit::is_recorded_action('avbk_process_order_email') && AVBK_Audit::is_recorded_action('avbk_waive_fee_item'), 'bookkeeping actions are recorded');
check(!AVBK_Audit::is_recorded_action('avbk_export_book_orders') && !AVBK_Audit::is_recorded_action('avbk_view_receipt') && !AVBK_Audit::is_recorded_action('other_plugin_action'), 'exports, previews and other plugins are not');
$ids = AVBK_Audit::request_ids(['order_id' => '41', 'email' => 'a@example.test', 'fee_item_ids' => ['3', 'x', '5'], 'member_id' => '', 'note' => 'vrije tekst', '_wpnonce' => 'abc']);
check($ids === ['order_id' => 41, 'fee_item_ids' => [3, 5]], 'only identifying ids are taken from a request, no e-mail, text or nonce');
$outcome = AVBK_Audit::redirect_outcome('https://example.test/wp-admin/admin.php?page=avbk-orders&tab=book&order_email_processed=41&mail_sent=0&_wpnonce=abc&tshirt_token=secret');
check($outcome === ['order_email_processed' => '41', 'mail_sent' => '0'], 'the redirect outcome is kept without page, nonce or token');

$GLOBALS['current_user'] = 1;
$GLOBALS['pagenow'] = 'admin-post.php';
$_REQUEST = ['action' => 'avbk_process_order_email', 'order_id' => '41', 'member_id' => '7', 'email' => 'new@example.test'];
AVBK_Audit::record_request();
$row = last_audit();
check($row['action'] === 'request:avbk_process_order_email' && $row['user_id'] === 1 && $row['actor'] === 'beheer' && $row['member_id'] === 7, 'an admin action gets a row with who and which member');
check($row['details'] === ['ids' => ['order_id' => 41, 'member_id' => 7]] && $row['created_at'] === '2026-11-13 20:00:00', 'the row holds the ids and the time');
AVBK_Audit::record_outcome('https://example.test/wp-admin/admin.php?page=avbk-orders&order_email_processed=41&mail_sent=0');
check(last_audit()['details']['outcome'] === ['order_email_processed' => '41', 'mail_sent' => '0'], 'the outcome of the redirect is added to the same row');
$count = count(audit_rows());
AVBK_Audit::record_outcome('https://example.test/somewhere?x=1');
check(count(audit_rows()) === $count && !isset(last_audit()['details']['outcome']['x']), 'a later redirect in the same request does not overwrite it');
$GLOBALS['pagenow'] = 'admin.php';
AVBK_Audit::record_request();
check(count(audit_rows()) === $count, 'ordinary admin pages are not recorded');

// The data layer: old and new values.
$wpdb->rows['wp_avb_fee_items'][5] = ['id' => 5, 'member_id' => 7, 'status' => 'open', 'amount_due' => '45.00', 'description' => 'Contributie 2026'];
AVBK_DB::update_fee_item_amount(5, 30.0);
$row = last_audit();
check($row['action'] === 'fee_item.amount' && $row['object_type'] === 'fee_item' && $row['object_id'] === 5 && $row['member_id'] === 7, 'an amount change is logged on the fee item and its member');
check($row['details']['amount_due'] == [45, 30], 'with old and new amount');
check($row['request_id'] === '11111111-2222-4333-8444-555555555555', 'rows of one request share its request id');
$count = count(audit_rows());
AVBK_DB::update_fee_item_amount(5, 30.0);
check(count(audit_rows()) === $count, 'an unchanged amount adds nothing');
AVBK_DB::waive_fee_item(5);
check(last_audit()['action'] === 'fee_item.waived' && last_audit()['details']['status'] === ['open', 'waived'], 'waiving is logged with the old status');

AVBK_DB::allocate(9, 5, 7, 12.5);
$row = last_audit();
check($row['action'] === 'allocation.added' && $row['object_id'] === 9 && $row['details']['fee_item_id'] === 5 && $row['details']['amount'] == 12.5, 'linking a payment is logged on the transaction');

$wpdb->rows['wp_avb_orders'][41] = ['id' => 41, 'member_id' => 7, 'email' => 'old@example.test', 'notes' => '', 'distribution_status' => 'pending', 'status' => 'confirmed', 'fee_item_id' => 5, 'order_type' => 'book', 'total_amount' => '35.00'];
AVBK_DB::update_order_distribution(41, 'collected');
check(last_audit()['action'] === 'order.distribution' && last_audit()['details']['distribution_status'] === ['pending', 'collected'], 'a distribution change is logged with old and new status');
AVBK_DB::update_order_notes(41, 'Ophalen op de ALV');
check(last_audit()['action'] === 'order.notes' && last_audit()['details']['notes'] === ['', 'Ophalen op de ALV'], 'a note change is logged');
AVBK_DB::update_order_email(41, 'new@example.test');
check(last_audit()['action'] === 'order.email_changed' && last_audit()['details']['email'] === ['old@example.test', 'new@example.test'], 'an order e-mail change is logged with old and new address');

// Actors and failures.
$GLOBALS['current_user'] = 0;
AVBK_Audit::log('order.created', 'order', 50);
check(last_audit()['actor'] === 'website' && last_audit()['user_id'] === 0, 'a visitor order is logged as via de website');
$GLOBALS['current_user'] = 2;
AVBK_Audit::log('order.created', 'order', 51);
check(last_audit()['actor'] === 'lid', 'a logged-in member who is no manager is logged as lid');
$wpdb->fail_audit = true;
check(AVBK_Audit::log('fee_item.amount', 'fee_item', 5) === 0 && $GLOBALS['failures_logged'] === [['audit log', 'insert_failed']], 'a failed write goes to the server log and never throws');
$wpdb->fail_audit = false;

check(AVBK_Audit::label('order.deleted') === 'Bestelling verwijderd' && AVBK_Audit::label('request:avbk_save_settings') === 'Actie avbk_save_settings', 'labels are Dutch; unknown actions show their name');

exit($failures ? 1 : 0);
