<?php
/**
 * AVBK_Refunds: when a refund can be linked, and which outgoing
 * transactions are suggested first. Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');

class AVBK_DB {
    public static array $transactions = [];
    public static array $fee_items = [];
    public static array $paid = [];
    public static array $refunds = [];
    public static array $credit_rows = [];

    public static function get_transaction(int $id): ?object { return self::$transactions[$id] ?? null; }
    public static function get_fee_item(int $id): ?object { return self::$fee_items[$id] ?? null; }
    public static function get_fee_item_paid(int $id): float { return self::$paid[$id] ?? 0.0; }
    public static function get_refunded_for_transaction(int $id): float {
        return array_sum(array_map(fn($r) => $r['amount'], array_filter(self::$refunds, fn($r) => $r['tx'] === $id)));
    }
    public static function get_refunded_for_fee_item(int $id): float {
        return array_sum(array_map(fn($r) => $r['amount'], array_filter(self::$refunds, fn($r) => $r['item'] === $id)));
    }
    public static function insert_refund(int $tx, ?int $item, int $member, float $amount, ?int $credit_tx = null): int {
        self::$refunds[] = ['tx' => $tx, 'item' => $item, 'member' => $member, 'amount' => $amount] + ($credit_tx ? ['credit_tx' => $credit_tx] : []);
        return count(self::$refunds);
    }
    public static function get_credit_transactions_for_member(int $member_id): array { return self::$credit_rows; }
}

require dirname(__DIR__) . '/includes/class-refunds.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

$out = fn(int $id, float $amount, string $status = 'ignored', string $iban = '', string $date = '2026-11-01') =>
    (object) ['id' => $id, 'direction' => 'out', 'amount' => $amount, 'status' => $status, 'duplicate_of' => null, 'counterparty_iban' => $iban, 'transaction_date' => $date];
$item = (object) ['id' => 7, 'member_id' => 3, 'amount_due' => 25.0];

// rejection()
check(AVBK_Refunds::rejection(null, $item, 25, 0, 25, 0) === 'no_transaction', 'Missing transaction');
check(AVBK_Refunds::rejection($out(1, 25), null, 25, 0, 25, 0) === 'no_fee_item', 'Missing fee item');
$in = $out(1, 25);
$in->direction = 'in';
check(AVBK_Refunds::rejection($in, $item, 25, 0, 25, 0) === 'not_outgoing', 'Incoming transaction is no refund');
$dup = $out(1, 25);
$dup->duplicate_of = 9;
check(AVBK_Refunds::rejection($dup, $item, 25, 0, 25, 0) === 'not_outgoing', 'Duplicate import row is no refund');
check(AVBK_Refunds::rejection($out(1, 25), $item, 0, 0, 25, 0) === 'invalid_amount', 'Zero amount');
check(AVBK_Refunds::rejection($out(1, 20), $item, 25, 0, 25, 0) === 'exceeds_transaction', 'More than the transfer');
check(AVBK_Refunds::rejection($out(1, 50, 'matched'), $item, 30, 25, 25, 0) === 'exceeds_transaction', 'More than what is left of a split transfer');
check(AVBK_Refunds::rejection($out(1, 30), $item, 30, 0, 25, 0) === 'exceeds_paid', 'More than was paid');
check(AVBK_Refunds::rejection($out(1, 25), $item, 10, 0, 25, 20) === 'exceeds_paid', 'More than is left after an earlier refund');
check(AVBK_Refunds::rejection($out(1, 25), $item, 25, 0, 25, 0) === null, 'Full refund');
check(AVBK_Refunds::rejection($out(1, 25), $item, 20, 0, 25, 0) === null, 'Partial refund, costs kept');
check(AVBK_Refunds::rejection($out(1, 50, 'matched'), $item, 25, 25, 25, 0) === null, 'Second part of a split transfer');

// rank_candidates()
$ranked = AVBK_Refunds::rank_candidates([
    $out(1, 25, 'ignored', 'NL00TEST0000000001', '2026-11-05'),
    $out(2, 25, 'ignored', 'NL00TEST0000000002', '2026-11-04'),
    $out(3, 40, 'ignored', 'NL 00 TEST 0000 0000 03', '2026-11-03'),
    $out(4, 25, 'ignored', 'NL00TEST0000000003', '2026-11-02'),
    (object) ['id' => 5, 'amount' => 25, 'refunded' => 25, 'counterparty_iban' => 'NL00TEST0000000003', 'transaction_date' => '2026-11-06'],
], ['NL00TEST0000000003'], ['NL00TEST0000000002'], 25.0);
check(array_map(fn($c) => $c->id, $ranked) === [4, 3, 2, 1], 'Paying account first (exact amount first), then member account, then the rest; fully linked left out');
check($ranked[0]->match === 2 && $ranked[2]->match === 1 && $ranked[3]->match === 0, 'Match levels set');
check($ranked[1]->open === 40.0, 'Open amount set');

// add()
AVBK_DB::$transactions = [1 => $out(1, 25)];
AVBK_DB::$fee_items = [7 => $item];
AVBK_DB::$paid = [7 => 25.0];
check(AVBK_Refunds::add(1, 7, 30) === 'exceeds_transaction', 'add() refuses too much');
check(AVBK_DB::$refunds === [], 'Nothing stored on refusal');
check(AVBK_Refunds::add(1, 7, 25.004) === 1, 'add() stores a valid refund');
check(AVBK_DB::$refunds[0] === ['tx' => 1, 'item' => 7, 'member' => 3, 'amount' => 25.0], 'Stored rounded, for the fee item owner');

// Credit refunds
$tx = $out(1, 25);
check(AVBK_Refunds::credit_rejection(null, 10, 0, 50) === 'no_transaction', 'Credit: missing transaction');
check(AVBK_Refunds::credit_rejection((object) ['direction' => 'in', 'duplicate_of' => null, 'status' => 'matched', 'amount' => 25], 10, 0, 50) === 'not_outgoing', 'Credit: incoming refused');
check(AVBK_Refunds::credit_rejection($tx, 0, 0, 50) === 'invalid_amount', 'Credit: zero refused');
check(AVBK_Refunds::credit_rejection($tx, 20, 10, 50) === 'exceeds_transaction', 'Credit: more than left on the transaction');
check(AVBK_Refunds::credit_rejection($tx, 25, 0, 20) === 'exceeds_credit', 'Credit: more than the credit');
check(AVBK_Refunds::credit_rejection($tx, 20, 0, 20) === null, 'Credit: exactly the credit is fine');

$rows = [(object) ['id' => 11, 'credit' => 15.0], (object) ['id' => 12, 'credit' => 30.0]];
check(AVBK_Refunds::spread_over_credit($rows, 10) === [11 => 10.0], 'Spread: within the oldest payment');
check(AVBK_Refunds::spread_over_credit($rows, 40) === [11 => 15.0, 12 => 25.0], 'Spread: oldest first, then the next');

AVBK_DB::$refunds = [];
AVBK_DB::$transactions = [5 => $out(5, 60)];
AVBK_DB::$credit_rows = $rows;
check(AVBK_Refunds::add_credit(5, 3, 50) === 'exceeds_credit', 'add_credit() refuses more than the credit');
check(AVBK_DB::$refunds === [], 'Nothing stored on refusal');
check(AVBK_Refunds::add_credit(5, 3, 40) === 2, 'add_credit() stores one row per payment');
check(AVBK_DB::$refunds === [
    ['tx' => 5, 'item' => null, 'member' => 3, 'amount' => 15.0, 'credit_tx' => 11],
    ['tx' => 5, 'item' => null, 'member' => 3, 'amount' => 25.0, 'credit_tx' => 12],
], 'Credit refund rows: no fee item, pointing at the payment');

exit($failures ? 1 : 0);
