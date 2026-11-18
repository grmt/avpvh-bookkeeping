<?php
/**
 * AVBK_Expenses: when an outgoing transaction can be explained, and which
 * reimbursements and returned payments are suggested first. Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
interface AVBK_Component { public function register(): void; }
require dirname(__DIR__) . '/includes/class-expenses.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}
function reject(string $kind, ?object $tx, array $input = [], bool $doc = false, ?object $reimbursement = null, ?object $related = null, bool $explained = false, bool $returned = false): ?string {
    return AVBK_Expenses::rejection($kind, $tx, $input + ['explained' => $explained, 'document' => $doc, 'reimbursement' => $reimbursement, 'related' => $related, 'returned' => $returned]);
}

$out = (object) ['id' => 1, 'direction' => 'out', 'amount' => 300.0, 'status' => 'ignored', 'duplicate_of' => null];
$in = (object) ['id' => 2, 'direction' => 'in', 'amount' => 300.0, 'status' => 'ignored', 'duplicate_of' => null];
$with = fn(object $o, array $changes) => (object) ($changes + (array) $o);

// The transaction itself
check(reject('bank_costs', null) === 'no_transaction', 'Missing transaction');
check(reject('bank_costs', $with($out, ['direction' => 'in'])) === 'not_outgoing', 'Incoming transaction is no expense');
check(reject('bank_costs', $with($out, ['status' => 'matched'])) === 'not_outgoing', 'Already linked transaction');
check(reject('bank_costs', $with($out, ['duplicate_of' => 9])) === 'not_outgoing', 'Duplicate import row');
check(reject('bank_costs', $out, [], false, null, null, true) === 'not_outgoing', 'Already explained');
check(reject('gift', $out) === 'unknown_kind', 'Unknown kind');
check(reject('bank_costs', $out) === null, 'Bank costs need nothing else');
check(reject('transfer', $out) === null, 'Internal transfer needs nothing else');

// Invoice
check(reject('invoice', $out, ['supplier' => ' '], true) === 'no_supplier', 'Invoice needs a supplier');
check(reject('invoice', $out, ['supplier' => 'Drukkerij X']) === 'no_document', 'Invoice needs a document');
check(reject('invoice', $out, ['supplier' => 'Drukkerij X'], true) === null, 'Invoice with supplier and document');

// Reimbursement
check(reject('reimbursement', $out) === 'no_reimbursement', 'Reimbursement needs a declaratie');
check(reject('reimbursement', $out, [], false, (object) ['amount' => 300.0, 'status' => 'rejected']) === 'no_reimbursement', 'Rejected declaratie');
check(reject('reimbursement', $out, [], false, (object) ['amount' => 299.0, 'status' => 'pending']) === 'amount_mismatch', 'Declaratie amount differs');
check(reject('reimbursement', $out, [], false, (object) ['amount' => 300.0, 'status' => 'paid']) === null, 'Paid declaratie without transfer yet');

// Returned payment
check(reject('returned_payment', $out) === 'no_related', 'Returned payment needs the incoming one');
check(reject('returned_payment', $out, [], false, null, $out) === 'no_related', 'An outgoing transaction is not a payment received');
check(reject('returned_payment', $out, [], false, null, $with($in, ['status' => 'matched'])) === 'related_allocated', 'Allocated payment goes through Terugbetalen');
check(reject('returned_payment', $out, [], false, null, $in, false, true) === 'related_returned', 'Already returned');
check(reject('returned_payment', $out, [], false, null, $with($in, ['amount' => 150.0])) === 'amount_mismatch', 'Amount differs');
check(reject('returned_payment', $out, [], false, null, $in) === null, 'Double payment sent back');
check(reject('returned_payment', $out, [], false, null, $with($in, ['status' => 'unmatched'])) === null, 'Unmatched payment sent back');

// Other
check(reject('other', $out, ['note' => '']) === 'no_note', 'Other needs a note');
check(reject('other', $out, ['note' => 'Cadeau spreker']) === null, 'Other with a note');

// Ranking
$ranked = AVBK_Expenses::rank_returned_candidates([
    (object) ['id' => 5, 'amount' => 50.0, 'transaction_date' => '2026-03-25'],
    (object) ['id' => 6, 'amount' => 300.0, 'transaction_date' => '2026-02-22'],
    (object) ['id' => 7, 'amount' => 300.0, 'transaction_date' => '2026-03-20'],
], 300.0);
check(array_map(fn($p) => $p->id, $ranked) === [7, 6, 5], 'Same amount first, most recent first');

$ranked = AVBK_Expenses::rank_reimbursements([
    (object) ['id' => 1, 'amount' => 20.0, 'iban' => 'NL00TEST0000000001', 'status' => 'pending'],
    (object) ['id' => 2, 'amount' => 20.0, 'iban' => 'NL00TEST0000000002', 'status' => 'paid'],
    (object) ['id' => 3, 'amount' => 20.0, 'iban' => 'NL00TEST0000000002', 'status' => 'pending'],
    (object) ['id' => 4, 'amount' => 35.0, 'iban' => 'NL 00 TEST 0000 0000 02', 'status' => 'pending'],
], 20.0, 'NL00TEST0000000002');
check(array_map(fn($r) => $r->id, $ranked) === [3, 2, 4, 1], 'Same account, then same amount, then pending, then oldest');

exit($failures ? 1 : 0);
