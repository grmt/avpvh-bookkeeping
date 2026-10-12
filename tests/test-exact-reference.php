<?php
/** Synthetic regression checks; importer writes only to in-memory fake storage. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
class AVPVH_Roles {
    public static function current_user_has_role($role): bool { return true; }
}
class AVPVH_DB {
    public static function get_member(int $id): ?object { return $GLOBALS['members'][$id] ?? null; }
    public static function get_activity_types(): array {
        return array_map(fn($name) => (object) ['name' => $name], ['Kamp', 'Contributie', 'Drank', 'Boek', 'T-shirt']);
    }
    public static function get_activities(): array {
        return [(object) ['id' => 1, 'name' => 'Contributie', 'year' => 2026], (object) ['id' => 2, 'name' => 'Voorbeeldkamp', 'year' => 2026]];
    }
    public static function get_activity(int $id): ?object { return (object) ['id' => $id, 'year' => 2026, 'type_name' => 'Kamp', 'name' => 'Voorbeeldkamp']; }
}
class AVBK_DB {
    public static array $fees = [];
    public static array $transactions = [];
    public static array $allocations = [];
    public static array $drafts = [];
    public static array $queue = [];
    public static int $guesses = 0;
    public static function get_fee_item(int $id): ?object { return isset(self::$fees[$id]) ? clone self::$fees[$id] : null; }
    public static function fee_item_book_year(object $item): int { return (int) $item->year; }
    public static function get_fee_item_remaining(object $item): float {
        $paid = array_sum(array_map(fn($a) => $a->fee_item_id === $item->id ? $a->amount : 0.0, self::$allocations));
        return $item->status === 'waived' ? 0.0 : round($item->amount_due - $item->paid - $paid, 2);
    }
    public static function create_import_batch($filename, $user): int { return 1; }
    public static function dedupe_hash(...$args): string { return hash('sha256', json_encode($args)); }
    public static function transaction_exists(string $hash): bool {
        return (bool) array_filter(self::$transactions, fn($tx) => ($tx->dedupe_hash ?? '') === $hash);
    }
    public static function find_semantic_duplicate(array $tx): bool { return false; }
    public static function insert_transaction(array $tx): int {
        $id = count(self::$transactions) + 1;
        self::$transactions[$id] = (object) (['id' => $id] + $tx + ['suggested_member_ids' => '', 'suggested_type' => '']);
        return $id;
    }
    public static function allocate(int $transaction, int $fee, int $member, float $amount): void {
        self::$allocations[] = (object) ['transaction_id' => $transaction, 'fee_item_id' => $fee, 'member_id' => $member, 'amount' => $amount];
    }
    public static function update_transaction_status(int $id, string $status): void { self::$transactions[$id]->status = $status; }
    public static function update_transaction_suggestion(int $id, string $status, string $members, string $types): void {
        foreach (['status' => $status, 'suggested_member_ids' => $members, 'suggested_type' => $types] as $key => $value) self::$transactions[$id]->$key = $value;
    }
    public static function clear_transaction_draft(int $id): void { unset(self::$drafts[$id]); }
    public static function get_transaction_draft(int $id): ?array { return self::$drafts[$id] ?? null; }
    public static function get_review_queue(...$args): array {
        return self::$queue ?: array_values(array_filter(self::$transactions, fn($tx) => in_array($tx->status, ['suggested', 'unmatched'], true)));
    }
    public static function get_transaction(int $id): ?object { return self::$transactions[$id] ?? null; }
    public static function get_transactions(): array { return array_values(self::$transactions); }
    public static function get_payable_members(): array { self::$guesses++; return array_values($GLOBALS['members']); }
    public static function update_import_batch_counts(...$args): void {}
    public static function remember_iban(...$args): void {}
    public static function get_current_activity_for_type_name(string $name): ?object { return (object) ['id' => 1, 'name' => $name]; }
    public static function activity_fee_type_map(): array { return ['Contributie' => 'contribution', 'Kamp' => 'camp']; }
    public static function get_allocations_for_transaction(int $id): array {
        return array_values(array_filter(self::$allocations, fn($a) => $a->transaction_id === $id));
    }
    public static function member_edit_url(int $id): string { return 'https://example.test/member/' . $id; }
    public static function get_member_fee_detail_for_activity(int $member, int $activity): array { return ['found' => false, 'share' => 0.0]; }
    public static function get_member_status_detail(int $id): array { return ['found' => false, 'share' => 0.0]; }
    public static function get_open_fee_items_for_member(int $member_id): array {
        return array_values(array_filter(self::$fees, fn($f) => $f->member_id === $member_id && $f->status === 'open'));
    }
    public static function get_open_tshirt_fee_item(int $member_id): ?object {
        foreach (self::$fees as $f) {
            if ($f->member_id === $member_id && $f->status === 'open' && in_array($f->category ?? '', ['tshirt', 'T-shirt'], true)) return $f;
        }
        return null;
    }
    public static function get_open_book_fee_item(int $member_id): ?object {
        foreach (self::$fees as $f) {
            if ($f->member_id === $member_id && $f->status === 'open' && in_array($f->category ?? '', ['book', 'Boek'], true)) return $f;
        }
        return null;
    }
}
function get_option(string $name, $default = false) { return $name === 'avbk_closed_through_year' ? 2025 : $default; }
function current_time(string $format): string { return $format === 'Y' ? '2026' : '2026-10-09'; }
function wp_date(string $format, int $timestamp): string { return date($format, $timestamp); }
function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value): string { return esc_html($value); }
function esc_url($value): string { return esc_html($value); }
function current_user_can(string $cap): bool { return true; }
function get_current_user_id(): int { return 1; }
function get_user_meta(...$args): string { return ''; }
function wp_die(string $message): void { throw new RuntimeException($message); }
function wp_create_nonce(string $key): string { return 'synthetic'; }
function wp_json_encode($value): string { return json_encode($value); }
function admin_url(string $path): string { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $value = null, $url = null): string {
    if (is_array($args)) $url = $value;
    else $args = [$args => $value];
    return ($url ?? admin_url('admin.php')) . '?' . http_build_query($args);
}
function remove_query_arg(string $key): string { return admin_url('admin.php'); }
function wp_list_pluck(array $rows, string $key): array { return array_map(fn($row) => $row->$key, $rows); }
function avpvh_format_name(object $member, string $format = ''): string { return $member->first_name . ' ' . $member->last_name; }
function selected($current, $value): void { if ($current === $value) echo 'selected'; }
function wp_nonce_field(...$args): void {}
function submit_button(...$args): void {}
require AVBK_PLUGIN_DIR . 'includes/class-bank-import-layout.php';
require AVBK_PLUGIN_DIR . 'includes/class-csv-reader.php';
require AVBK_PLUGIN_DIR . 'includes/class-matcher.php';
require AVBK_PLUGIN_DIR . 'includes/class-import.php';
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $message);
    if (!in_array('--render', $_SERVER['argv'] ?? [], true)) echo "PASS $message\n";
}
function reset_case(): void {
    $GLOBALS['members'] = [
        1001 => (object) ['id' => 1001, 'first_name' => 'Anna', 'last_name' => 'Voorbeeld', 'initials' => 'A.'],
        1002 => (object) ['id' => 1002, 'first_name' => 'Bram', 'last_name' => 'Voorbeeld', 'initials' => 'B.'],
    ];
    AVBK_DB::$transactions = AVBK_DB::$allocations = AVBK_DB::$drafts = AVBK_DB::$queue = [];
    AVBK_DB::$guesses = 0;
    AVBK_DB::$fees = [
        1001 => (object) ['id' => 1001, 'member_id' => 1001, 'amount_due' => 30.0, 'paid' => 0.0, 'status' => 'open', 'year' => 2026, 'description' => 'Contributie', 'activity_id' => 1],
        1002 => (object) ['id' => 1002, 'member_id' => 1002, 'amount_due' => 90.0, 'paid' => 0.0, 'status' => 'open', 'year' => 2026, 'description' => 'Kamp', 'activity_id' => 2],
    ];
    $_GET = [];
}
function transaction(float $amount, string $memo, string $date = '2026-10-07'): int {
    return AVBK_DB::insert_transaction(['amount' => $amount, 'description' => $memo, 'transaction_date' => $date, 'status' => 'suggested', 'direction' => 'in', 'counterparty_iban' => '', 'counterparty_name' => 'Piet Jansen', 'import_batch_id' => null, 'source_row' => null]);
}

reset_case();
$result = AVBK_Import::process_file(__DIR__ . '/fixtures/exact-newest-first.csv', 'synthetic.csv', 1);
check($result['row_count'] === 2 && $result['matched_count'] === 1, 'descending CSV matched once and retains later reused QR for review');
check(AVBK_DB::$transactions[1]->transaction_date === '2026-10-07' && AVBK_DB::$transactions[1]->source_row === 3, 'oldest transfer processed first while original source row retained');
check(count(AVBK_DB::$allocations) === 2 && array_sum(array_column(AVBK_DB::$allocations, 'amount')) === 120.0, 'older combined transfer allocated to its exact fees');
check(AVBK_DB::$transactions[2]->status === 'suggested' && AVBK_DB::$transactions[2]->suggested_member_ids === '1001' && AVBK_DB::$guesses === 0, 'later settled reference cannot fall back to name or IBAN guessing');
$again = AVBK_Import::process_file(__DIR__ . '/fixtures/exact-newest-first.csv', 'synthetic.csv', 1);
check($again['row_count'] === 0 && count(AVBK_DB::$allocations) === 2, 'reimport remains deduplicated and preserves allocations');

reset_case();
$review = AVBK_Import::get_exact_reference_review('PVH-1001-F1001.1002.1001');
check(count($review['rows']) === 2 && $review['remaining'] === 120.0 && $review['rows'][1]['activity'] === 'f1002', 'reference review uses exact distinct fees, their owners and actual outstanding amounts');
AVBK_DB::$fees[1001]->paid = 30.0;
$review = AVBK_Import::get_exact_reference_review('PVH-1001-F1001.1002');
check(count($review['rows']) === 2 && $review['rows'][0]['amount'] === 0.0 && $review['remaining'] === 90.0 && count($review['warnings']) === 1, 'already settled fee remains visible at zero with warning');
AVBK_DB::$fees[1002]->year = 2025;
$review = AVBK_Import::get_exact_reference_review('PVH-1001-F1001.1002.9999');
check($review['remaining'] === 0.0 && count($review['rows']) === 2 && count($review['warnings']) === 3, 'missing and closed reference items warn without guessing replacement fees');
AVBK_DB::$fees[1002]->year = 2026;
AVBK_DB::$fees[1002]->status = 'waived';
check(AVBK_Import::get_exact_reference_review('PVH-1001-F1002')['remaining'] === 0.0, 'waived reference never becomes payable again');
check(AVBK_Matcher::match_reference_code('PVH-1001: Contributie') === 1001 && AVBK_Matcher::match_fee_item_reference('PVH-1001: Contributie') === [], 'legacy member-only reference format remains supported');
check(AVBK_Matcher::classify_types('Name: Piet Boekman Description: contributie en kamp IBAN: XXTEST') === ['Kamp', 'Contributie'], 'surname in bank metadata does not create a book suggestion');
check(AVBK_Matcher::classify_types('Naam: Piet Boekman Omschrijving: Boek IBAN: XXTEST') === ['Boek'], 'real book purchase in Dutch memo still recognized');
check(AVBK_Matcher::classify_types('Name: Piet Boekman Description: IBAN: XXTEST') === [], 'empty bank memo cannot classify account holder metadata');
check(AVBK_Matcher::classify_types('Name: Piet Boekman IBAN: XXTEST') === [], 'missing bank memo cannot turn payer name into book purchase');
check(AVBK_Matcher::classify_types('Boek') === ['Boek'], 'plain unlabelled purchase memo still recognized');
check(in_array('T-shirt', AVBK_Matcher::classify_types('Naam: Piet Jansen Omschrijving: Archeo merch IBAN: XXTEST'), true), 'merch in memo classifies as T-shirt');
check(in_array('T-shirt', AVBK_Matcher::classify_types('Naam: Piet Jansen Omschrijving: Lustrum hoodie XXL IBAN: XXTEST'), true), 'hoodie in memo classifies as T-shirt');
check(in_array('T-shirt', AVBK_Matcher::classify_types('Naam: Piet Jansen Omschrijving: tshirt maat L IBAN: XXTEST'), true), 'tshirt without dash classifies as T-shirt');
check(in_array('T-shirt', AVBK_Matcher::classify_types('Naam: Piet Jansen Omschrijving: Lustrumkleding IBAN: XXTEST'), true), 'lustrumkleding classifies as T-shirt');
check(in_array('Boek', AVBK_Matcher::classify_types('Naam: Piet Jansen Omschrijving: Boek Doorgraven IBAN: XXTEST'), true), 'doorgraven classifies as Boek');

reset_case();
$id = transaction(50.0, 'PVH-1001-F1001.1002');
$changed = AVBK_Import::recompute_suggestions();
check($changed === 1 && AVBK_DB::$transactions[$id]->suggested_member_ids === '1001,1002' && !AVBK_DB::$allocations, 'partial reference updates only suggestions, not allocations');
check(AVBK_Import::recompute_suggestions() === 0, 'same reference recomputation is idempotent');
AVBK_DB::$drafts[$id] = [['member_id' => 1002, 'activity' => 'f1002', 'amount' => 5.0]];
$snapshot = serialize([AVBK_DB::$transactions, AVBK_DB::$drafts, AVBK_DB::$allocations]);
AVBK_Import::recompute_suggestions();
check(serialize([AVBK_DB::$transactions, AVBK_DB::$drafts, AVBK_DB::$allocations]) === $snapshot, 'saved draft never auto-confirmed or overwritten');

reset_case();
$later = transaction(30.0, 'PVH-1001-F1001', '2026-10-08');
$older = transaction(120.0, 'PVH-1001-F1001.1002', '2026-10-07');
AVBK_DB::$queue = [AVBK_DB::$transactions[$later], AVBK_DB::$transactions[$older]];
AVBK_Import::recompute_suggestions();
check(AVBK_DB::$transactions[$older]->status === 'matched' && AVBK_DB::$transactions[$later]->status === 'suggested', 'recompute processes review rows chronologically despite input order');

reset_case();
$id = transaction(50.0, 'PVH-1001-F1001.1002');
$rows = AVBK_Import::get_exact_reference_review('PVH-1001-F1001.1002')['rows'];
$result = AVBK_Import::confirm_transaction($id, $rows);
check($result['ok'] && $result['underpaid'] === 70.0 && $result['remaining_open'] === 70.0, 'explicit partial confirmation leaves unpaid remainder on selected exact fees');
check(array_column(AVBK_DB::$allocations, 'amount') === [30.0, 20.0] && array_column(AVBK_DB::$allocations, 'fee_item_id') === [1001, 1002], 'partial allocation is capped at receipt and targets exact fee IDs');
$snapshot = serialize(AVBK_DB::$allocations);
check(!AVBK_Import::confirm_transaction($id, $rows)['ok'] && serialize(AVBK_DB::$allocations) === $snapshot, 'replayed confirmation cannot alter already processed transfer');

reset_case();
$id = transaction(150.0, 'PVH-1001-F1001.1002');
$result = AVBK_Import::confirm_transaction($id, AVBK_Import::get_exact_reference_review('PVH-1001-F1001.1002')['rows']);
check($result['ok'] && $result['unassigned'] === 30.0 && $result['remaining_open'] === 0.0, 'excess receipt remains explicitly unassigned, without inventing a new fee');
foreach (['wrong-member', 'missing', 'closed', 'waived', 'paid', 'duplicate'] as $case) {
    reset_case();
    $id = transaction(30.0, 'PVH-1001-F1001');
    $rows = [['member_id' => 1001, 'activity' => 'f1001', 'amount' => 30.0]];
    if ($case === 'wrong-member') $rows[0]['member_id'] = 1002;
    if ($case === 'missing') $rows[0]['activity'] = 'f9999';
    if ($case === 'closed') AVBK_DB::$fees[1001]->year = 2025;
    if ($case === 'waived') AVBK_DB::$fees[1001]->status = 'waived';
    if ($case === 'paid') AVBK_DB::$fees[1001]->paid = 30.0;
    if ($case === 'duplicate') { $rows[0]['amount'] = 15.0; $rows[] = $rows[0]; }
    check(!AVBK_Import::confirm_transaction($id, $rows)['ok'] && !AVBK_DB::$allocations, 'rejects ' . $case . ' reference before allocation');
}

reset_case();
$id = transaction(150.0, 'PVH-1001-F1001.1002');
AVBK_DB::$fees[1001]->paid = 30.0;
AVBK_DB::$transactions[$id]->suggested_member_ids = '999'; // Deliberately stale fuzzy guess.
$unknown_id = transaction(10.01, 'kamp contributie drank boek');
AVBK_DB::$transactions[$unknown_id]->suggested_member_ids = '1001';
$draft_id = transaction(120.0, 'PVH-1001-F1001.1002');
AVBK_DB::$drafts[$draft_id] = [['member_id' => 1002, 'activity' => 'f1002', 'amount' => 5.0]];
$missing_id = transaction(1.0, 'PVH-1001-F9999');
$tiny_id = transaction(0.02, 'kamp contributie drank boek');
AVBK_DB::$transactions[$tiny_id]->suggested_member_ids = '1001';
ob_start();
require AVBK_PLUGIN_DIR . 'admin/review-queue.php';
$html = ob_get_clean();
check(str_contains($html, 'Exact betalingskenmerk herkend.') && str_contains($html, 'Post #1001 is al volledig betaald.'), 'review template shows exact reference and settled-item warning');
check(str_contains($html, 'value="f1001" selected') && str_contains($html, 'value="f1002" selected') && str_contains($html, 'Meer ontvangen'), 'review uses exact fee choices rather than stale person/activity guesses');
check(!str_contains($html, 'value="999"'), 'stale fuzzy member is not rendered as the payment beneficiary');
preg_match('/id="tx-' . $unknown_id . '".*?<\/form>/s', $html, $unknown_match);
preg_match_all('/class="avbk-amount-input"[^>]*value="([^"]+)"/', $unknown_match[0], $amount_match);
check($amount_match[1] === ['2,50', '2,50', '2,50', '2,51'], 'unknown split gives remainder cents to final row and sums exactly to receipt');
preg_match('/id="tx-' . $tiny_id . '".*?<\/form>/s', $html, $tiny_match);
preg_match_all('/class="avbk-amount-input"[^>]*value="([^"]+)"/', $tiny_match[0], $tiny_amounts);
check($tiny_amounts[1] === ['0,01', '0,01', '0,00', '0,00'], 'tiny receipt never creates negative rounding remainder');
preg_match('/id="tx-' . $draft_id . '".*?<form[^>]*class="avbk-review-form".*?<\/form>/s', $html, $draft_match);
check(str_contains($draft_match[0], 'value="5,00"') && str_contains($draft_match[0], 'Je opgeslagen concept is behouden.'), 'review leaves explicit saved partial amount unchanged');
preg_match('/id="tx-' . $missing_id . '".*?<\/form>/s', $html, $missing_match);
preg_match('/class="avbk-review-split".*?<tbody>(.*?)<\/tbody>/s', $missing_match[0], $missing_rows);
check(str_contains($missing_match[0], 'Post #9999 of het bijbehorende lid bestaat niet meer.') && trim($missing_rows[1]) === '', 'missing exact reference does not invent a suggested replacement row');
ob_start(); avbk_activity_select('activity[]', [], [], 'f1002'); $select = ob_get_clean();
check(str_contains($select, 'Post #1002') && avbk_row_detail(['member_id' => 1002, 'activity' => 'f1002'])['share'] === 90.0, 'saved exact fee choices retain label and true outstanding amount');
AVBK_DB::$fees[1002]->description = '<script>synthetic</script>';
ob_start(); avbk_activity_select('activity[]', [], [], 'f1002'); $escaped = ob_get_clean();
check(!str_contains($escaped, '<script>') && str_contains($escaped, '&lt;script&gt;'), 'exact fee labels remain HTML escaped');

if (in_array('--render', $_SERVER['argv'] ?? [], true)) {
    echo '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8"><title>Fictieve referentietest</title></head><body>' . $html . '</body></html>';
} else {
    echo "All exact reference checks passed; no real database or mail used.\n";
}
