<?php
/**
 * Test: the review queue's pickers only offer fee items with something left
 * to pay — fully paid and zero-amount items stay status 'open' (= not
 * waived) in the database but must not show up as "open: € 0,00".
 * No real names in tests or test data (see AGENTS.md).
 */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');

function get_option(string $name, $default = false) {
    return $GLOBALS['options'][$name] ?? $default;
}

class Mock_Payable_WPDB {
    public string $prefix = 'wp_';
    public array $fee_items = [];
    public array $allocations = [];

    public function prepare(string $query, ...$args): string {
        return vsprintf(str_replace(['%d', '%s', '%f'], ['%s', '%s', '%s'], $query), $args);
    }

    public function get_results(string $query): array {
        preg_match('/member_id = (\d+)/', $query, $m);
        return array_values(array_filter(
            $this->fee_items,
            fn($i) => (int) $i->member_id === (int) $m[1] && $i->status === 'open'
        ));
    }

    public function get_var(string $query) {
        preg_match('/fee_item_id = (\d+)/', $query, $m);
        $sum = 0.0;
        foreach ($this->allocations as $a) {
            if ($a['fee_item_id'] === (int) $m[1]) {
                $sum += $a['amount'];
            }
        }
        return (string) $sum;
    }
}

require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$ok) {
        $failures++;
    }
}

function item(int $id, float $due, string $status = 'open'): object {
    return (object) ['id' => $id, 'member_id' => 7, 'type' => 'other', 'year' => 2026, 'activity_id' => null,
        'category' => 'tshirt', 'description' => "Post $id", 'amount_due' => $due, 'status' => $status, 'created_at' => '2026-01-01'];
}

$GLOBALS['wpdb'] = new Mock_Payable_WPDB();
$GLOBALS['wpdb']->fee_items = [
    item(1, 25.00),           // fully paid
    item(2, 0.00),            // free
    item(3, 30.00),           // partly paid, 10 left
    item(4, 15.00),           // unpaid
    item(5, 40.00, 'waived'), // waived
];
$GLOBALS['wpdb']->allocations = [
    ['fee_item_id' => 1, 'amount' => 25.00],
    ['fee_item_id' => 3, 'amount' => 20.00],
];

$open_ids = array_map(fn($i) => (int) $i->id, AVBK_DB::get_open_fee_items_for_member(7));
check($open_ids === [1, 2, 3, 4], 'get_open_fee_items_for_member keeps paid/free items (not waived = open)');

$payable_ids = array_map(fn($i) => (int) $i->id, AVBK_DB::get_payable_fee_items_for_member(7));
check($payable_ids === [3, 4], 'get_payable_fee_items_for_member drops paid and zero-amount items');

$GLOBALS['wpdb']->allocations[] = ['fee_item_id' => 4, 'amount' => 15.00];
$payable_ids = array_map(fn($i) => (int) $i->id, AVBK_DB::get_payable_fee_items_for_member(7));
check($payable_ids === [3], 'single remaining payable item, so the review queue can prefill it');

echo $failures ? "\n$failures failure(s)\n" : "\nAll tests passed\n";
exit($failures ? 1 : 0);
