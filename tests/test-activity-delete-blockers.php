<?php
/**
 * Test suite for AVBK_DB::activity_delete_blockers()/cleanup_deleted_activity()
 * — avpvh-bookkeeping's answer to avpvh-members' avpvh_activity_delete_blockers
 * filter (see avpvh-members PR #67). Follows AGENTS.md: no real personal
 * names in code, comments, or test data.
 */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
define('AVBK_PLUGIN_URL', 'https://example.test/wp-content/plugins/avpvh-bookkeeping/');

$GLOBALS['options'] = [];
function get_option(string $key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option(string $key, $value): bool { $GLOBALS['options'][$key] = $value; return true; }
function delete_option(string $key): bool { unset($GLOBALS['options'][$key]); return true; }

class Mock_Activity_Delete_WPDB {
    public string $prefix = 'wp_';
    /** @var array<string,int> table name (without prefix) => row count for the requested activity_id */
    public array $counts = [];

    public function prepare(string $query, ...$args): string {
        return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], $query), $args);
    }

    public function get_var(string $query) {
        foreach ($this->counts as $table => $count) {
            if (str_contains($query, "{$this->prefix}{$table} ")) {
                return (string) $count;
            }
        }
        return '0';
    }
}

require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';

function assert_test(bool $cond, string $msg): void {
    if (!$cond) {
        echo "FAIL: $msg\n";
        exit(1);
    }
    echo "PASS: $msg\n";
}

global $wpdb;

// Nothing references the activity: no blockers, matching the review-queue
// activity ("t-shirt (2026)") case this was built for.
$wpdb = new Mock_Activity_Delete_WPDB();
$reasons = AVBK_DB::activity_delete_blockers([], 7);
assert_test($reasons === [], 'no blockers when nothing in avpvh-bookkeeping references the activity');

// Every avb_* table that carries activity_id is checked, with correct
// singular/plural Dutch phrasing.
$wpdb = new Mock_Activity_Delete_WPDB();
$wpdb->counts = [
    'avb_activity_rates'           => 1,
    'avb_fee_items'                => 5,
    'avb_transactions'              => 3,
    'avb_sheet_participation_meta' => 1,
    'avb_payment_requests'          => 2,
    'avb_activity_payment_links'   => 1,
];
$reasons = AVBK_DB::activity_delete_blockers([], 7);
assert_test($reasons === [
    '1 tarief',
    '5 contributie-/bijdrageposten',
    '3 transacties',
    '1 aanmelding (sheet-import)',
    '2 betaalverzoeken',
    '1 generiek betaalverzoek (link/QR)',
], 'one correctly pluralized reason per non-empty table, in a stable order');

// A reason already added by another plugin (e.g. avpvh-members' own
// participant check) is preserved, not overwritten.
$wpdb = new Mock_Activity_Delete_WPDB();
$wpdb->counts = ['avb_fee_items' => 2];
$reasons = AVBK_DB::activity_delete_blockers(['3 deelnemers'], 7);
assert_test($reasons === ['3 deelnemers', '2 contributie-/bijdrageposten'], 'existing reasons from earlier filter callbacks are kept, new ones appended');

// Cleanup only ever touches the per-activity sheet-import option — nothing
// else to delete, since activity_delete_blockers() already guaranteed no
// rows reference the activity by the time this runs.
$GLOBALS['options']['avbk_sheet_import_7'] = ['match_activity_id' => 7];
$GLOBALS['options']['avbk_sheet_import_8'] = ['match_activity_id' => 8];
AVBK_DB::cleanup_deleted_activity(7);
assert_test(!isset($GLOBALS['options']['avbk_sheet_import_7']), 'the deleted activity\'s own sheet-import config is removed');
assert_test(isset($GLOBALS['options']['avbk_sheet_import_8']), 'a different activity\'s sheet-import config is untouched');

echo "All activity-delete-blocker checks passed.\n";
