<?php
/**
 * Verantwoord: which changes to an explanation are allowed, saving one,
 * and the sortable table with its edit form. Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
final class Redirected extends Exception {}
function add_action(...$args): void {}
function check_admin_referer($action) { return true; }
function current_user_can($cap) { return true; }
function wp_die($message) { throw new RuntimeException((string) $message); }
function wp_safe_redirect($url) { $GLOBALS['redirect'] = $url; throw new Redirected(); }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args); }
function current_time($format) { return $format === 'Y' ? '2026' : '2026-11-20 10:00:00'; }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key)); }
function sanitize_text_field($text) { return trim((string) $text); }
function sanitize_textarea_field($text) { return trim((string) $text); }
function wp_unslash($value) { return $value; }
function avbk_log_failure(string $where, string $reason, array $ids = []): void { $GLOBALS['logged'][] = "$where: $reason"; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return (string) $url; }
function wp_kses($text, $allowed) { return (string) $text; }
function wp_date($format, $timestamp) { return gmdate($format, $timestamp); }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="' . $action . '">'; }
function selected($a, $b) { echo (string) $a === (string) $b ? ' selected' : ''; }
final class AVPVH_Roles { public static function current_user_has_role($role) { return true; } }
final class AVPVH_DB {
    public static function get_activities(): array { return [(object) ['id' => 3, 'name' => 'Kamp Zonneveld', 'year' => 2026]]; }
}
final class AVBK_DB {
    public static array $expenses = [];
    public static array $updates = [];
    public static bool $update_ok = true;
    public static function get_expense(int $id): ?object { return isset(self::$expenses[$id]) ? (object) self::$expenses[$id] : null; }
    public static function update_expense(int $id, array $data): bool {
        self::$updates[] = [$id, $data];
        return self::$update_ok;
    }
    public static function get_expenses_for_year(int $year): array { return array_map(static fn($e) => (object) $e, array_values(self::$expenses)); }
    public static function get_expense_categories(): array { return ['Accommodatie']; }
}
require dirname(__DIR__) . '/includes/class-expense-edits.php';
require dirname(__DIR__) . '/admin/expenses.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

$base = ['transaction_id' => 1, 'transaction_date' => '2026-01-19', 'amount' => 1273.84, 'counterparty_name' => 'Kampeerboerderij', 'kind' => 'other', 'category' => 'Accommodatie', 'supplier' => '', 'activity_id' => null, 'note' => 'tweede termijn', 'related_transaction_id' => null, 'reimbursement_id' => null, 'document_path' => '', 'second_approved_by' => 4];
AVBK_DB::$expenses = [
    1 => ['id' => 1] + $base,
    2 => ['id' => 2, 'transaction_id' => 2, 'transaction_date' => '2026-02-10', 'amount' => 110.0, 'counterparty_name' => 'A. Jansen', 'kind' => 'reimbursement', 'category' => 'Attenties', 'reimbursement_id' => 7, 'document_path' => 'expense-x.pdf', 'second_approved_by' => null, 'activity_id' => 3, 'note' => ''] + $base,
    3 => ['id' => 3, 'transaction_id' => 3, 'kind' => 'bank_costs', 'category' => '', 'amount' => 21.22, 'note' => ''] + $base,
];
$other = AVBK_DB::get_expense(1);
$linked = AVBK_DB::get_expense(2);

// What may change
check(AVBK_Expense_Edits::rejection($other, 'invoice', ['supplier' => 'Kampeerboerderij', 'document' => false]) === 'no_document', 'An invoice still needs a document');
check(AVBK_Expense_Edits::rejection($other, 'invoice', ['supplier' => '', 'document' => true]) === 'no_supplier', 'and a supplier');
check(AVBK_Expense_Edits::rejection($other, 'invoice', ['supplier' => 'Kampeerboerderij', 'document' => true]) === null, 'Overig can become an invoice');
check(AVBK_Expense_Edits::rejection($other, 'other', ['note' => ' ']) === 'no_note', 'Overig keeps needing a note');
check(AVBK_Expense_Edits::rejection($other, 'reimbursement', ['document' => false]) === 'no_reimbursement', 'A declaratie outside the site needs the receipt');
check(AVBK_Expense_Edits::rejection($other, 'returned_payment', []) === 'kind_fixed', 'It can not become a returned payment');
check(AVBK_Expense_Edits::rejection($other, 'gift', []) === 'unknown_kind', 'Unknown kind');
check(AVBK_Expense_Edits::rejection($linked, 'reimbursement', ['document' => false]) === null, 'A linked declaratie keeps its kind, no receipt needed');
check(AVBK_Expense_Edits::rejection($linked, 'other', ['note' => 'x']) === 'kind_fixed', 'but can not change kind');
check(array_keys(AVBK_Expense_Edits::editable_kinds($linked)) === ['reimbursement'] && !isset(AVBK_Expense_Edits::editable_kinds($other)['returned_payment']), 'The kinds offered');
check(AVBK_Expenses::summary($linked) === 'Declaratie — Attenties — declaratie #7', 'Summary: kind, category, details');
check(AVBK_Expenses::details($other) === ['tweede termijn'], 'Details hold the note');

// Saving
function save(array $post): string {
    $_POST = $post;
    try {
        (new AVBK_Expense_Edits())->handle_edit();
    } catch (Redirected $redirect) {
        return $GLOBALS['redirect'];
    }
    return '';
}
$url = save(['expense_id' => 1, 'kind' => 'bank_costs', 'category' => 'Bankkosten', 'supplier' => 'X', 'note' => 'aangepast', 'activity_id' => 3, 'year' => 2026]);
check(str_contains($url, 'tab=explained') && str_contains($url, 'year=2026') && str_contains($url, 'updated=1') && str_ends_with($url, '#expense-1'), 'Saved: back to the row on Verantwoord');
check(AVBK_DB::$updates[0] === [1, ['kind' => 'bank_costs', 'category' => 'Bankkosten', 'supplier' => '', 'activity_id' => null, 'note' => 'aangepast']], 'Fields that do not belong to the kind are cleared');
check(str_contains(save(['expense_id' => 1, 'kind' => 'other', 'note' => '']), 'explain_failed=no_note'), 'A refused change is reported');
check(str_contains(save(['expense_id' => 99, 'kind' => 'other']), 'explain_failed=not_found'), 'An unknown expense');
AVBK_DB::$update_ok = false;
check(str_contains(save(['expense_id' => 3, 'kind' => 'transfer']), 'explain_failed=not_saved'), 'A failed update');
check(in_array('expense edit: no_note', $GLOBALS['logged'], true) && in_array('expense edit: not_saved', $GLOBALS['logged'], true), 'Every refusal is logged');

// The table
$_GET = ['tab' => 'explained', 'year' => '2026'];
ob_start();
avbk_render_expenses_explained();
$html = ob_get_clean();
check(str_contains($html, 'avpvh-sortable-table') && str_contains($html, 'data-col="categorie" data-filter="select"') && str_contains($html, 'data-col="soort" data-filter="select"'), 'Sortable, with filters on kind and category');
check(str_contains($html, '<td data-total-col="bedrag">'), 'A total of the visible rows');
check(str_contains($html, 'data-sort-value="1273.84"') && str_contains($html, 'data-sort-value="2026-01-19"'), 'Amounts and dates sort');
check(str_contains($html, 'data-filter-value="Kamp Zonneveld (2026)">Kamp Zonneveld (2026)<') && str_contains($html, 'data-filter-value="geen"'), 'Activity column, and "geen" to filter on');
check(str_contains($html, 'data-filter-value="met document"') && str_contains($html, 'data-filter-value="zonder document"'), 'Document filter');
check(substr_count($html, '<dialog id="avbk-expense-edit-') === 3 && substr_count($html, 'data-dialog="avbk-expense-edit-') === 3, 'Each row has its edit form');
check(str_contains($html, 'value="tweede termijn"') === false && str_contains($html, '>tweede termijn</textarea>'), 'The form is filled in');
check(str_contains($html, 'data-value="3"'), 'with the chosen activity');
check(str_contains($html, 'Kies alleen een bestand om het te vervangen'), 'An existing document can be replaced');
check(str_contains($html, 'Een andere soort kies je door de verantwoording te verwijderen'), 'A linked expense says why its kind is fixed');
check(substr_count($html, '<template id="avbk-expense-activities">') === 1, 'The activities once for the page');

// Notices
foreach (['updated' => 'Verantwoording aangepast', 'explain_failed' => 'gekoppeld aan een declaratie'] as $key => $text) {
    $_GET = [$key => $key === 'updated' ? '1' : 'kind_fixed'];
    ob_start();
    avbk_render_expenses_notices();
    check(str_contains(ob_get_clean(), $text), "Message after $key");
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
