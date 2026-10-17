<?php
/**
 * Test suite for review queue count and submenu badge.
 * Follows AGENTS.md: no real personal names in code, comments, or test data.
 */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
define('AVBK_PLUGIN_URL', 'https://example.test/wp-content/plugins/avpvh-bookkeeping/');

class Mock_Review_WPDB {
    public string $prefix = 'wp_';
    public array $transactions = [];

    public function prepare(string $query, ...$args): string {
        return vsprintf(str_replace(['%d', '%s', '%f'], ['%s', '%s', '%s'], $query), $args);
    }

    public function get_var(string $query) {
        if (str_contains($query, 'COUNT(*)')) {
            $filtered = $this->transactions;
            if (str_contains($query, "direction = 'in'")) {
                $filtered = array_filter($filtered, fn($t) => ($t['direction'] ?? '') === 'in');
            }
            if (str_contains($query, "status IN ('suggested', 'unmatched')")) {
                $filtered = array_filter($filtered, fn($t) => in_array($t['status'] ?? '', ['suggested', 'unmatched'], true));
            }
            if (str_contains($query, "status = 'matched' AND second_approved_by IS NULL")) {
                $filtered = array_filter($filtered, fn($t) => ($t['status'] ?? '') === 'matched' && empty($t['second_approved_by']));
            }
            if (preg_match('/YEAR\(transaction_date\) >= (\d+)/', $query, $m)) {
                $min_year = (int) $m[1];
                $filtered = array_filter($filtered, function ($t) use ($min_year) {
                    $year = (int) substr($t['transaction_date'] ?? '2000-01-01', 0, 4);
                    return $year >= $min_year;
                });
            }
            return (string) count($filtered);
        }
        return null;
    }
}

$GLOBALS['wpdb'] = new Mock_Review_WPDB();
global $wpdb;

$GLOBALS['options'] = [];
function get_option(string $name, $default = false) {
    return $GLOBALS['options'][$name] ?? $default;
}

$GLOBALS['submenus'] = [];
function add_action(...$args): void {}
function add_menu_page(...$args): void {}
function add_submenu_page($parent, $page_title, $menu_title, $capability, $menu_slug, $callback = null): void {
    $GLOBALS['submenus'][$menu_slug] = [
        'page_title' => $page_title,
        'menu_title' => $menu_title,
        'capability' => $capability,
    ];
}

class AVPVH_Roles {
    public static function current_user_has_role(string $role): bool { return true; }
}

function current_user_can(string $cap): bool { return true; }
function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
function avbk_asset_version(string $path): string { return '1.0'; }

require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-admin.php';

function check(bool $cond, string $msg): void {
    if (!$cond) {
        echo "FAIL: $msg\n";
        exit(1);
    }
    echo "PASS: $msg\n";
}

echo "\n--- Testing AVBK_DB::count_review_queue ---\n";

// Empty DB returns 0
$wpdb->transactions = [];
check(AVBK_DB::count_review_queue() === 0, 'Empty DB count_review_queue returns 0');

// Seed transactions
$wpdb->transactions = [
    // 1: Suggested in 2026 (active)
    ['id' => 1, 'direction' => 'in', 'status' => 'suggested', 'transaction_date' => '2026-03-01'],
    // 2: Unmatched in 2026 (active)
    ['id' => 2, 'direction' => 'in', 'status' => 'unmatched', 'transaction_date' => '2026-04-15'],
    // 3: Matched in 2026 (not in review queue)
    ['id' => 3, 'direction' => 'in', 'status' => 'matched', 'transaction_date' => '2026-05-10', 'second_approved_by' => null],
    // 4: Suggested in 2024 (closed year)
    ['id' => 4, 'direction' => 'in', 'status' => 'suggested', 'transaction_date' => '2024-02-01'],
    // 5: Outgoing transaction (ignored for review queue)
    ['id' => 5, 'direction' => 'out', 'status' => 'unmatched', 'transaction_date' => '2026-06-01'],
];

check(AVBK_DB::count_review_queue() === 3, 'All-time review queue has 3 incoming suggested/unmatched transactions');
check(AVBK_DB::count_review_queue(2025) === 2, 'Review queue with min_year 2025 has 2 transactions');
check(AVBK_DB::count_review_queue(2027) === 0, 'Review queue with min_year 2027 has 0 transactions');

echo "\n--- Testing AVBK_DB::count_pending_second_approval with min_year ---\n";
check(AVBK_DB::count_pending_second_approval() === 1, 'Second approval count returns 1');
check(AVBK_DB::count_pending_second_approval(2026) === 1, 'Second approval count with min_year 2026 returns 1');
check(AVBK_DB::count_pending_second_approval(2027) === 0, 'Second approval count with min_year 2027 returns 0');

echo "\n--- Testing AVBK_Admin::register_menus badge rendering ---\n";

$admin = new AVBK_Admin();

// Case 1: Closed through 2024, so min_year is 2025 (2 items in review queue)
$GLOBALS['options']['avbk_closed_through_year'] = 2024;
$GLOBALS['submenus'] = [];
$admin->register_menus();

check(isset($GLOBALS['submenus']['avbk-review']), 'avbk-review submenu registered');
$menu_title = $GLOBALS['submenus']['avbk-review']['menu_title'];
check(str_contains($menu_title, 'Te controleren'), 'Menu title contains Te controleren');
check(str_contains($menu_title, 'awaiting-mod count-2'), 'Badge contains count-2 class');
check(str_contains($menu_title, '<span class="pending-count">2</span>'), 'Badge contains pending-count 2');

// Case 2: No review items left (0 items)
$wpdb->transactions = [
    ['id' => 3, 'direction' => 'in', 'status' => 'matched', 'transaction_date' => '2026-05-10', 'second_approved_by' => 1],
];
$GLOBALS['submenus'] = [];
$admin->register_menus();
$menu_title_empty = $GLOBALS['submenus']['avbk-review']['menu_title'];
check($menu_title_empty === 'Te controleren', 'Menu title is plain Te controleren when count is 0');
check(!str_contains($menu_title_empty, 'awaiting-mod'), 'No badge rendered when count is 0');

echo "\nAll review queue badge tests PASSED cleanly!\n";
