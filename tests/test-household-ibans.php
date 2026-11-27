<?php
/** The account list on the declaration form: parents' accounts first, so a refund goes to whoever paid. Fictional data only. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
final class AVPVH_DB {
    /** [parent, child] pairs. */
    public static array $parents = [];
    public static function is_parent_of(int $parent_id, int $child_id): bool { return in_array([$parent_id, $child_id], self::$parents, true); }
}
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
exit($failures ? 1 : 0);
