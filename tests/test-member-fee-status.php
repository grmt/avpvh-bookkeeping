<?php
/**
 * The contribution status these books give avpvh-members (AVBK_Member_Fee_Status).
 * Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');

$GLOBALS['options'] = ['avbk_closed_through_year' => 2025];
$GLOBALS['shutdown'] = [];
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function current_time($format) { return $format === 'Y' ? '2026' : '2026-06-01'; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg(array $args, string $url) { return $url . '?' . http_build_query($args); }
function add_filter(...$args) {}
function add_action($hook, $cb = null) { if ($hook === 'shutdown') { $GLOBALS['shutdown'][] = $cb; } }
interface AVBK_Component { public function register(): void; }

final class AVBK_DB {
    public static array $items = [];
    public static function get_contribution_items_with_payments(array $ids, ?int $year = null): array {
        return array_values(array_filter(self::$items, static fn($i) => in_array($i->member_id, $ids, true) && ($year === null || $i->year === $year)));
    }
}
final class AVPVH_DB {
    public static function member_is_fee_exempt(int $id): bool { return $id === 7; }
}
final class AVBK_Fee_Generation {
    public static array $refreshed = [];
    public static function generate_contribution_fee_for_member(int $id): bool { self::$refreshed[] = $id; return true; }
}
require dirname(__DIR__) . '/includes/class-member-fee-status.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}
function item(int $member, int $year, float $due, float $paid = 0, ?string $date = null, string $status = 'open'): object {
    return (object) ['member_id' => $member, 'year' => $year, 'amount_due' => $due, 'paid' => $paid, 'paid_date' => $date, 'status' => $status];
}

AVBK_DB::$items = [
    item(1, 2026, 25, 25, '2026-02-10 00:00:00'),
    item(2, 2026, 25),
    item(3, 2026, 25, 10, '2026-03-01'),
    item(4, 2026, 25, 0, null, 'waived'),
    item(5, 2026, 25, 30, '2026-04-01'),
    item(1, 2025, 20),
    item(2, 2025, 20, 20, '2025-05-05'),
    item(6, 2026, 0),
];
$s = AVBK_Member_Fee_Status::statuses(null, [1, 2, 3, 4, 5, 6, 7, 8], 2026);
check($s[1]['status'] === 'paid' && $s[1]['paid_date'] === '2026-02-10', 'Fully paid, date without time');
check($s[2]['status'] === 'pending' && $s[2]['amount_paid'] === 0.0, 'Nothing paid is pending');
check($s[3]['status'] === 'partial' && $s[3]['amount_paid'] === 10.0, 'Some money is partial');
check($s[4]['status'] === 'waived', 'Waived item');
check($s[5]['status'] === 'paid', 'Overpaid counts as paid');
check($s[6]['status'] === 'paid', 'Nothing due counts as paid');
check($s[7]['status'] === 'waived' && $s[7]['amount_due'] === 0.0, 'Fee-exempt member without an item is waived');
check(!isset($s[8]), 'Member without item and not exempt is left out');

$history = AVBK_Member_Fee_Status::member_fees(null, 1);
check(array_column($history, 'year') === [2026], 'Unpaid item in a closed year is left out');
$history = AVBK_Member_Fee_Status::member_fees(null, 2);
check(array_column($history, 'year') === [2026, 2025] && $history[1]['status'] === 'paid', 'Paid item in a closed year stays');
$history = AVBK_Member_Fee_Status::member_fees(null, 7);
check(count($history) === 1 && $history[0]['year'] === 2026 && $history[0]['status'] === 'waived', 'Exempt member gets this year as waived');

check(
    AVBK_Member_Fee_Status::summarize([item(9, 2026, 25, 0, null, 'waived'), item(9, 2026, 10, 10, '2026-01-02')], 2026, 2025)['status'] === 'paid',
    'Waived and paid items together: paid'
);
check(AVBK_Member_Fee_Status::summarize([], 2026, 2025) === null, 'No items, no row');

$m = AVBK_Member_Fee_Status::manager(null, 12);
check($m['label'] === 'boekhouding' && str_contains($m['url'], 'page=avbk-members') && str_contains($m['url'], 'member_id=12'), 'Manager links to the member');
check(str_contains(AVBK_Member_Fee_Status::manager(null)['url'], 'page=avbk-rates'), 'Manager without member links to the rates');

AVBK_Member_Fee_Status::schedule_refresh(3);
AVBK_Member_Fee_Status::schedule_refresh(3);
AVBK_Member_Fee_Status::schedule_refresh(0);
foreach ($GLOBALS['shutdown'] as $cb) { $cb(); }
check(AVBK_Fee_Generation::$refreshed === [3], 'Refresh once per member, at shutdown');

exit($failures ? 1 : 0);
