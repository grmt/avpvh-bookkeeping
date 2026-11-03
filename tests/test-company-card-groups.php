<?php
/** Statement grouping must preserve money, identities, details and company boundaries. */
define('ABSPATH', __DIR__);
function wp_json_encode($value) { return json_encode($value); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
class AVBK_Companies {
    public static function company($id) { if ($id !== 1) throw new RuntimeException('No access'); }
    public static function normalize_iban($value) { return strtoupper(str_replace(' ', '', $value)); }
}
require dirname(__DIR__) . '/includes/class-company-report.php';
require dirname(__DIR__) . '/includes/class-vat-patterns.php';
require dirname(__DIR__) . '/includes/class-company-card-groups.php';
function check_group($condition, $message) { if (!$condition) throw new RuntimeException($message); echo "PASS: $message\n"; }
$company = (object) ['id' => 1, 'card_account' => '12345678901'];
$statement = ['date' => '2026-07-09', 'total_cents' => 12100, 'source_hash' => str_repeat('a', 64), 'card_account' => $company->card_account, 'filename' => 'statement.pdf', 'document_id' => 7];
$GLOBALS['options']['avbk_company_card_statements_1'] = [7 => $statement];
$bank = (object) ['company_id' => 1, 'transaction_date' => '2026-07-21', 'amount' => '121.00', 'direction' => 'out', 'counterparty_name' => 'ING Commercial Cards', 'counterparty_iban' => '', 'description' => 'Creditcard afrekening'];
$card = (object) ['company_id' => 1, 'dedupe_hash' => str_repeat('b', 64), 'raw_data' => json_encode(['Afschrift document_id' => '7', 'Afschrift sha256' => $statement['source_hash'], 'Afschriftdatum' => $statement['date']])];
$card_row = ['number' => 3, 'source' => 'Creditcard', 'review_key' => 'card:' . $card->dedupe_hash, 'date' => '2026-07-01', 'status' => 'checked', 'gross_cents' => 2100];
$settlement_row = ['number' => 2, 'source' => 'Bank', 'review_key' => '', 'date' => $bank->transaction_date, 'name' => $bank->counterparty_name, 'direction' => 'out', 'status' => 'settlement', 'gross_cents' => 12100];
$ordinary = ['number' => 4, 'source' => 'Bank', 'status' => 'checked', 'gross_cents' => -30000];
$detail = ['number' => 5, 'source' => 'Detail', 'status' => 'detail', 'gross_cents' => null];
$report = ['rows' => [$card_row, $settlement_row, $ordinary, $detail]];
$build = fn($banks, $cards, $input = null) => AVBK_Company_Card_Groups::build($input ?? $report, $company, $banks, $cards);
$groups = $build([$bank], [$card]);
check_group(count($groups) === 2 && $groups[0]['id'] === 'bank' && count($groups[0]['rows']) === 2, 'ordinary bank transaction keeps its manual detail row');
check_group($groups[1]['id'] === 'statement-7' && $groups[1]['paid'] && array_column($groups[1]['rows'], 'number') === [2, 3], 'unique settlement appears above the associated card purchases');
check_group($groups[1]['card_cents'] === 2100 && $groups[1]['statement']['total_cents'] === 12100, 'full statement total is used even when most purchases fall outside the quarter');
$before = $report['rows']; $after = array_merge(...array_column($groups, 'rows'));
usort($before, fn($a, $b) => $a['number'] <=> $b['number']); usort($after, fn($a, $b) => $a['number'] <=> $b['number']);
check_group($before === $after, 'grouping preserves all row values and review identities exactly once');
$groups = $build([], [$card]);
check_group(!$groups[1]['paid'] && count($groups) === 3, 'missing bank data leaves the unmatched settlement visible');
$groups = $build([$bank, clone $bank], [$card]);
check_group(count($groups) === 3 && !$groups[1]['paid'] && $groups[1]['ambiguous'], 'duplicate payment candidates are not guessed');
$GLOBALS['options']['avbk_company_card_statements_1'][8] = ['document_id' => 8] + $statement;
$groups = $build([$bank], [$card]);
check_group(!$groups[1]['paid'] && $groups[1]['ambiguous'], 'one bank payment cannot match two equal statement totals');
unset($GLOBALS['options']['avbk_company_card_statements_1'][8]);
$bad_card = clone $card; $bad_card->raw_data = str_replace(str_repeat('a', 64), str_repeat('c', 64), $bad_card->raw_data);
$groups = $build([$bank], [$bad_card]);
check_group(in_array('cards-unlinked', array_column($groups, 'id'), true), 'an unverified document hash cannot assign a purchase to a statement');
$GLOBALS['options']['avbk_company_card_statements_1'][7]['transaction_hashes'] = [$card->dedupe_hash];
$groups = $build([$bank], [$bad_card]);
check_group(in_array('statement-7', array_column($groups, 'id'), true), 'validated PDF purchase identities also group preexisting CSV imports');
unset($GLOBALS['options']['avbk_company_card_statements_1'][7]['transaction_hashes']);
$other = clone $bank; $other->company_id = 2;
$groups = $build([$other], [$card]);
check_group(!$groups[1]['paid'], 'another company bank payment cannot be linked');
$outside = clone $bank; $outside->transaction_date = '2026-09-30';
$outside_row = $settlement_row; $outside_row['date'] = $outside->transaction_date;
$groups = $build([$outside], [$card], ['rows' => [$card_row, $outside_row]]);
check_group(!$groups[0]['paid'], 'a distant equal payment is not linked');
$groups = $build([$bank], [$card], ['rows' => [$card_row, $detail, $settlement_row]]);
check_group(array_column($groups[0]['rows'], 'number') === [2, 3, 5], 'purchase details remain attached when the settlement moves to the top');
try { AVBK_Company_Card_Groups::build($report, (object) ['id' => 2], [], []); throw new LogicException('Missing access check'); }
catch (RuntimeException $expected) { echo "PASS: grouping checks company access\n"; }
