<?php
/**
 * Generating contributions makes every active member a participant of the
 * year's Contributie activity, also one who owes nothing, and leaves an
 * existing participation alone. Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
function current_time($format) { return $format === 'Y' ? '2026' : '2026-06-01'; }
function wp_list_pluck($list, $field) { return array_map(static fn($o) => $o->$field, $list); }
$GLOBALS['members'] = [
    1 => (object) ['id' => 1, 'status' => 'active', 'birth_date' => '1980-01-01', 'birth_year' => null],
    2 => (object) ['id' => 2, 'status' => 'active', 'birth_date' => '2023-04-08', 'birth_year' => null],
    3 => (object) ['id' => 3, 'status' => 'active', 'birth_date' => '1950-01-01', 'birth_year' => null],
    4 => (object) ['id' => 4, 'status' => 'active', 'birth_date' => '1990-01-01', 'birth_year' => null],
    5 => (object) ['id' => 5, 'status' => 'former', 'birth_date' => '1990-01-01', 'birth_year' => null],
];
interface AVBK_Component { public function register(): void; }
final class AVPVH_DB {
    /** member_id => fields saved; member 4 already takes part. */
    public static array $participation = [4 => ['notes' => 'kept']];
    public static array $saved = [];
    public static function get_members(array $args): array {
        return array_values(array_filter($GLOBALS['members'], static fn($m) => $m->status === $args['status']));
    }
    public static function get_member(int $id): ?object { return $GLOBALS['members'][$id] ?? null; }
    public static function get_flags_for_member(int $id): array { return []; }
    public static function member_is_fee_exempt(int $id): bool { return $id === 3; }
    public static function get_activity_by_name_year(string $name, int $year): ?object { return (object) ['id' => 3, 'year' => $year]; }
    public static function get_participation(int $member_id, int $activity_id): ?object {
        return $activity_id === 3 && isset(self::$participation[$member_id]) ? (object) self::$participation[$member_id] : null;
    }
    public static function save_participation(int $member_id, int $activity_id, array $fields): int {
        self::$saved[] = [$member_id, $activity_id, $fields];
        self::$participation[$member_id] = $fields;
        return $member_id;
    }
}
final class AVBK_DB {
    public static array $upserted = [];
    public static function get_activity_rates(int $activity_id): array { return [(object) ['id' => 1]]; }
    public static function is_member_student_for_year(object $member, int $year): bool { return false; }
    public static function get_rate_for_age(int $activity_id, int $age): ?object {
        return $age >= 18 ? (object) ['rate' => 50, 'label' => 'Volwassenen'] : null;
    }
    public static function get_contribution_items_with_payments(array $ids, ?int $year = null): array { return []; }
    public static function waive_fee_item(int $id): void {}
    public static function upsert_contribution_fee_item(int $member_id, int $year, float $amount, string $description, bool $estimated, string $reason, int $activity_id): void {
        self::$upserted[] = $member_id;
    }
}
require dirname(__DIR__) . '/includes/class-fee-generation.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

AVBK_Fee_Generation::generate_contribution_fees(2026);
$saved_members = array_column(AVPVH_DB::$saved, 0);
check($saved_members === [1, 2, 3], 'Every active member without a participation gets one');
check(!in_array(4, $saved_members, true), 'An existing participation is not saved again');
check(AVPVH_DB::$participation[4] === ['notes' => 'kept'], 'Its notes stay');
check(!in_array(5, $saved_members, true), 'A former member gets no participation');
check(array_unique(array_column(AVPVH_DB::$saved, 1)) === [3], 'The participation is for the Contributie activity');
check(AVBK_DB::$upserted === [1, 4], 'Only members who owe something get an item');

AVPVH_DB::$saved = [];
AVBK_Fee_Generation::generate_contribution_fees(2026);
check(AVPVH_DB::$saved === [], 'A second run adds nothing');

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
