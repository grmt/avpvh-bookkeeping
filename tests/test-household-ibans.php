<?php
/** The account list on the declaration form: parents' accounts first, so a refund goes to whoever paid. Fictional data only. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
final class AVPVH_DB {
    /** [parent, child] pairs. */
    public static array $parents = [];
    public static function is_parent_of(int $parent_id, int $child_id): bool { return in_array([$parent_id, $child_id], self::$parents, true); }
    public static array $manageable = [];
    public static array $same_address = [];
    public static array $relationships = [];
    public static function get_manageable_members(int $id): array { return self::$manageable[$id] ?? []; }
    public static function has_same_address(int $a, int $b): bool { return in_array($b, self::$same_address[$a] ?? [], true); }
    public static function get_relationships(int $id): array { return self::$relationships[$id] ?? []; }
}
function current_time($format) { return '2026-11-27'; }
/** Answers the account query with the rows of the asked members, as MySQL would group them. */
final class Test_Wpdb {
    public string $prefix = 'wp_';
    public array $ids = [];
    /** member_id => [iban, created_at] */
    public array $accounts = [];
    public function prepare(string $sql, array $args): array { return [$sql, $args]; }
    public function get_results(array $query): array {
        [$sql, $this->ids] = $query;
        $rows = [];
        foreach ($this->accounts as [$member, $iban, $created]) {
            if (!in_array($member, $this->ids, true)) continue;
            $rows[$iban] ??= (object) ['iban' => $iban, 'account_name' => '', 'created_at' => $created, 'holder_ids' => []];
            $rows[$iban]->holder_ids[] = $member;
            $rows[$iban]->created_at = max($rows[$iban]->created_at, $created);
        }
        foreach ($rows as $row) $row->holder_ids = implode(',', $row->holder_ids);
        usort($rows, static fn($a, $b) => strcmp($b->created_at, $a->created_at));
        return $rows;
    }
}
$wpdb = new Test_Wpdb();
require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';

$failures = 0;
function check(bool $ok, string $label): void { global $failures; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) $failures++; }
function account(string $iban, string $holders): object { return (object) ['iban' => $iban, 'account_name' => '', 'holder_ids' => $holders]; }
function order(array $rows, int $member_id, array $household): array {
    return array_column(AVBK_DB::order_household_ibans($rows, $member_id, $household), 'iban');
}

// Household: parents 1 and 2, children 3 and 4. Rows come in newest first.
AVPVH_DB::$parents = [[1, 3], [2, 3], [1, 4], [2, 4]];
$household = [1, 2, 3, 4];
$rows = [
    account('CHILD-NEW', '3,9'),   // a child's, newest; 9 is outside the household
    account('PARTNER', '2'),
    account('OWN', '1'),
    account('SHARED', '1,2,3'),
    account('CHILD-OLD', '4'),
];
check(order($rows, 1, $household) === ['OWN', 'SHARED', 'PARTNER', 'CHILD-NEW', 'CHILD-OLD'], 'a parent: own accounts first, then the partner\'s, then the children\'s');
check(order($rows, 2, $household) === ['PARTNER', 'SHARED', 'OWN', 'CHILD-NEW', 'CHILD-OLD'], 'the other parent: their own accounts first');
check(order($rows, 3, $household) === ['PARTNER', 'OWN', 'SHARED', 'CHILD-NEW', 'CHILD-OLD'], 'a child: the parents\' accounts before their own');
check(order([account('OUTSIDE', '9')], 1, $household) === ['OUTSIDE'], 'an account held only outside the household stays in the list');

// Without parent links nobody is a child: own first, the rest newest first.
AVPVH_DB::$parents = [];
check(order($rows, 1, $household) === ['OWN', 'SHARED', 'CHILD-NEW', 'PARTNER', 'CHILD-OLD'], 'no parent links: own first, then newest first');
check(order([], 1, [1]) === [], 'no accounts: an empty list');

// The whole list for a member: housemates and a current partner, parents first.
AVPVH_DB::$parents = [[1, 3], [2, 3]];
AVPVH_DB::$manageable[1] = [(object) ['id' => 1], (object) ['id' => 3], (object) ['id' => 5]];
AVPVH_DB::$same_address[1] = [3];
AVPVH_DB::$relationships = [
    1 => [(object) ['category' => 'partner', 'other_id' => 2, 'valid_from' => null, 'valid_until' => null],
          (object) ['category' => 'partner', 'other_id' => 6, 'valid_from' => null, 'valid_until' => '2020-01-01'],
          (object) ['category' => 'partner', 'other_id' => 7, 'valid_from' => '2030-01-01', 'valid_until' => null]],
    3 => [(object) ['category' => 'ouder_kind', 'other_id' => 1, 'valid_from' => null, 'valid_until' => null]],
];
$wpdb->accounts = [
    [3, 'CHILD', '2026-08-29'], [1, 'OWN', '2026-08-25'], [2, 'PARTNER', '2026-08-20'],
    [5, 'ELSEWHERE', '2026-09-01'], [6, 'FORMER-PARTNER', '2026-09-01'], [7, 'FUTURE-PARTNER', '2026-09-01'],
];
check(array_column(AVBK_DB::get_known_ibans_for_member(1), 'iban') === ['OWN', 'PARTNER', 'CHILD'], 'own, then the partner\'s, then the child\'s; nobody outside the house or past or future partners');
check($wpdb->ids === [1, 3, 2], 'asked for the member, the housemate and the current partner');
$wpdb->accounts = [];
check(AVBK_DB::get_known_ibans_for_member(1) === [], 'no accounts at all: an empty list');
exit($failures ? 1 : 0);
