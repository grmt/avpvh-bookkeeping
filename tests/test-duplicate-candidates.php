<?php
/**
 * "Dubbele betaling van": payments of the member's family first, the same
 * amount first among those. Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/includes/class-duplicate-candidates.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

// Anna (1) and Bram (2) are partners; Cas (3) is their child; Piet (9) is someone else.
$households = [1 => [2, 3], 2 => [1, 3], 3 => [1, 2], 9 => []];
$household_of = fn(int $id) => $households[$id] ?? [];
$family = AVBK_Duplicate_Candidates::relatives([2, 2], $household_of);
sort($family);
check($family === [1, 2, 3], 'Family of Bram: Bram, Anna and Cas');
check(AVBK_Duplicate_Candidates::relatives([], $household_of) === [], 'No members, no family');

$tx = fn(int $id, float $amount, string $date) => (object) ['id' => $id, 'amount' => $amount, 'transaction_date' => $date];
$candidates = [$tx(10, 50, '2026-03-01'), $tx(11, 50, '2026-03-05'), $tx(12, 25, '2026-03-09'), $tx(13, 50, '2026-03-02'), $tx(14, 50, '2026-03-03')];
$involved = [10 => [1], 11 => [9], 12 => [3], 13 => [2], 14 => [2]];
check(
    AVBK_Duplicate_Candidates::preferred($candidates, $involved, [1, 2, 3], 14, 50.0) === [13, 10, 12],
    'Family payments only, itself left out; same amount first, newest first'
);
check(AVBK_Duplicate_Candidates::preferred($candidates, $involved, [], 0, 50.0) === [], 'Without a family nothing is preferred');
check(AVBK_Duplicate_Candidates::preferred($candidates, [], [1], 0, 50.0) === [], 'A payment that involves nobody is not preferred');

exit($failures ? 1 : 0);
