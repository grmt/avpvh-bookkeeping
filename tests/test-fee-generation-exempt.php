<?php
/**
 * A member who becomes fee-exempt after their contribution item was made
 * gets that item waived, unless money already came in. Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
function current_time($format) { return $format === 'Y' ? '2026' : '2026-06-01'; }
interface AVBK_Component { public function register(): void; }
final class AVPVH_DB {
    public static function get_member(int $id): ?object { return (object) ['id' => $id, 'status' => 'active']; }
    public static function member_is_fee_exempt(int $id): bool { return $id !== 4; }
    public static function get_activity_by_name_year(string $name, int $year): ?object { return (object) ['id' => 3, 'year' => $year]; }
}
final class AVBK_DB {
    public static array $items = [];
    public static array $waived = [];
    public static function get_activity_rates(int $activity_id): array { return [(object) ['id' => 1]]; }
    public static function get_contribution_items_with_payments(array $ids, ?int $year = null): array {
        return array_values(array_filter(self::$items, static fn($i) => in_array($i->member_id, $ids, true) && $i->year === $year));
    }
    public static function waive_fee_item(int $id): void { self::$waived[] = $id; }
}
require dirname(__DIR__) . '/includes/class-fee-generation.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

AVBK_DB::$items = [
    (object) ['id' => 11, 'member_id' => 1, 'year' => 2026, 'status' => 'open', 'paid' => 0],
    (object) ['id' => 12, 'member_id' => 2, 'year' => 2026, 'status' => 'open', 'paid' => 50],
    (object) ['id' => 13, 'member_id' => 3, 'year' => 2026, 'status' => 'waived', 'paid' => 0],
    (object) ['id' => 14, 'member_id' => 1, 'year' => 2025, 'status' => 'open', 'paid' => 0],
];
foreach ([1, 2, 3] as $member_id) {
    check(AVBK_Fee_Generation::generate_contribution_fee_for_member($member_id, 2026) === false, "Exempt member $member_id gets no new item");
}
check(AVBK_DB::$waived === [11], 'Only the unpaid open item of that year is waived');

exit($failures ? 1 : 0);
