<?php
/**
 * A member who becomes fee-exempt after their contribution item was made
 * gets that item waived, unless money already came in. And who owes no
 * contribution because no rate covers them. Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
function current_time($format) { return $format === 'Y' ? '2026' : '2026-06-01'; }
function wp_list_pluck($list, $field) { return array_map(static fn($o) => $o->$field, $list); }
$GLOBALS['members'] = [
    5 => (object) ['id' => 5, 'status' => 'active', 'birth_date' => '2023-04-08', 'birth_year' => null],
    6 => (object) ['id' => 6, 'status' => 'active', 'birth_date' => '1980-01-01', 'birth_year' => null],
    7 => (object) ['id' => 7, 'status' => 'former', 'birth_date' => '2023-04-08', 'birth_year' => null],
];
interface AVBK_Component { public function register(): void; }
final class AVPVH_DB {
    public static function get_member(int $id): ?object { return $GLOBALS['members'][$id] ?? (object) ['id' => $id, 'status' => 'active']; }
    public static function get_flags_for_member(int $id): array { return []; }
    public static function member_is_fee_exempt(int $id): bool { return $id !== 4; }
    public static function get_participation(int $member_id, int $activity_id): ?object { return null; }
    public static function save_participation(int $member_id, int $activity_id, array $fields): int { return 1; }
    public static function get_activity_by_name_year(string $name, int $year): ?object { return (object) ['id' => 3, 'year' => $year]; }
}
final class AVBK_DB {
    public static array $items = [];
    public static array $waived = [];
    public static bool $has_rates = true;
    public static function get_activity_rates(int $activity_id): array { return self::$has_rates ? [(object) ['id' => 1]] : []; }
    public static function is_member_student_for_year(object $member, int $year): bool { return false; }
    /** Kinderen 4-15 and Volwassenen 18+. */
    public static function get_rate_for_age(int $activity_id, int $age): ?object {
        if ($age >= 4 && $age <= 15) { return (object) ['rate' => 5, 'label' => 'Kinderen']; }
        return $age >= 18 ? (object) ['rate' => 50, 'label' => 'Volwassenen'] : null;
    }
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

check(AVBK_Fee_Generation::owes_no_contribution(5, 2026), 'A two-year-old below the youngest bracket owes nothing');
check(!AVBK_Fee_Generation::owes_no_contribution(6, 2026), 'An adult owes the contribution');
check(!AVBK_Fee_Generation::owes_no_contribution(7, 2026), 'A former member is not reported as owing nothing');
AVBK_DB::$has_rates = false;
check(!AVBK_Fee_Generation::owes_no_contribution(5, 2026), 'Without rates for the year nothing is known yet');

exit($failures ? 1 : 0);
