<?php
/**
 * The CSV the plugin writes (order exports, T-shirt purchase matrix) and the
 * Google Sheets CSV it reads (sheet import). Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');

$GLOBALS['options'] = [];
$GLOBALS['sheet_body'] = '';
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value) { $GLOBALS['options'][$name] = $value; return true; }
function remove_accents($s) { return $s; }
function wp_timezone() { return new DateTimeZone('Europe/Amsterdam'); }
function wp_safe_remote_get($url, $args = []) { return ['url' => $url]; }
function is_wp_error($v) { return false; }
function wp_remote_retrieve_response_code($r) { return 200; }
function wp_remote_retrieve_body($r) { return $GLOBALS['sheet_body']; }
interface AVBK_Component { public function register(): void; }
final class AVPVH_DB {
    public static function get_activity(int $id): ?object { return (object) ['name' => 'Excursie']; }
}
require AVBK_PLUGIN_DIR . 'includes/class-admin.php';
require AVBK_PLUGIN_DIR . 'includes/class-sheet-import.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}
/** The rows as write_csv() writes them. */
function written(array $rows): string {
    $handle = fopen('php://memory', 'w+');
    AVBK_Admin::write_csv($handle, $rows);
    rewind($handle);
    return (string) stream_get_contents($handle);
}
function order(array $fields = []): object {
    return (object) ($fields + [
        'id' => 7, 'created_at' => '2026-03-01 10:00:00', 'status' => 'confirmed', 'member_id' => 12,
        'first_name' => 'Anna', 'suffix' => 'de', 'last_name' => 'Jansen', 'email' => 'anna@example.test',
        'phone' => '0612345678', 'street' => 'Dorpsstraat', 'house_number' => '1', 'postal_code' => '1234 AB',
        'city' => 'Ergens', 'country' => 'NL', 'quantity' => 2, 'unit_price' => 25, 'total_amount' => 50,
        'fee_paid' => 50, 'fee_status' => 'open', 'distribution_status' => 'collected',
        'attend_presentation' => 1, 'keep_updated' => 0, 'notes' => '',
    ]);
}

// --- writing -------------------------------------------------------------------
$csv = written([['Naam', 'Opmerking'], ['Anna', 'zegt "hoi"; en C:\\map\\']]);
check(str_starts_with($csv, "\xEF\xBB\xBFNaam;Opmerking\n"), 'BOM, then semicolon-separated header');
check(str_contains($csv, 'Anna;"zegt ""hoi""; en C:\\map\\"' . "\n"), 'Quotes doubled, semicolon and backslash kept in a quoted cell');
check(written([['a\\"b']]) === "\xEF\xBB\xBF" . '"a\\"b"' . "\n", 'Backslash before a quote still escapes as before PHP 8.4');

// --- book orders ------------------------------------------------------------------
$rows = AVBK_Admin::book_orders_csv([
    order(),
    order(['id' => 8, 'status' => 'pending', 'member_id' => 0, 'fee_paid' => 10, 'distribution_status' => 'distributed', 'keep_updated' => 1, 'notes' => '=SOM(A1)']),
    order(['id' => 9, 'fee_status' => 'waived', 'fee_paid' => 0, 'distribution_status' => 'pending', 'unit_price' => 1012.5]),
]);
check(count($rows) === 4 && $rows[0][0] === 'Bestelnummer' && $rows[0][14] === 'Aantal exemplaren' && count($rows[0]) === 23, 'Book export: header with 23 columns');
check(count($rows[1]) === 23 && $rows[1][2] === 'Bevestigd' && $rows[1][15] === '25,00' && $rows[1][18] === 'Betaald' && $rows[1][19] === 'Opgehaald' && $rows[1][20] === 'Ja' && $rows[1][21] === 'Nee', 'Book export: a paid, collected order');
check($rows[2][2] === 'In afwachting van bevestiging' && $rows[2][3] === '' && $rows[2][18] === 'Open' && $rows[2][19] === 'Uitgereikt' && $rows[2][21] === 'Ja', 'Book export: a pending, part-paid order');
check($rows[2][22] === "'=SOM(A1)", 'Book export: a formula in a note is defused');
check($rows[3][18] === 'Kwijtgescholden' && $rows[3][19] === 'In afwachting' && $rows[3][15] === '1012,50', 'Book export: waived, not handed out, no thousands separator');

// --- T-shirt orders -----------------------------------------------------------------
$rows = AVBK_Admin::tshirt_orders_csv([order(['quantity' => 3, 'items' => [
    (object) ['quantity' => 2, 'title' => 'Logo', 'variant' => 'M'],
    (object) ['quantity' => 1, 'title' => 'Jubileum', 'variant' => 'XL'],
]])]);
check(count($rows[0]) === 21 && $rows[0][14] === 'Bestelde shirts (overzicht)', 'T-shirt export: header with 21 columns');
check($rows[1][14] === '2x Logo (M), 1x Jubileum (XL)' && $rows[1][15] === 3 && $rows[1][16] === '50,00' && $rows[1][18] === 'Betaald', 'T-shirt export: items summarised per order');

// --- T-shirt matrix -----------------------------------------------------------------
$rows = AVBK_Admin::tshirt_matrix_csv([
    'variants' => ['M', 'L'], 'designs' => ['Logo', '+Jubileum'],
    'cells' => ['Logo' => ['M' => 2], '+Jubileum' => ['M' => 1, 'L' => 3]],
    'totals_by_design' => ['Logo' => 2, '+Jubileum' => 4], 'totals_by_variant' => ['M' => 3, 'L' => 3],
    'grand_total' => 6, 'total_revenue' => 120,
], 20.0);
check($rows[0] === ['Design / Model', 'M', 'L', 'Totaal aantal shirts', 'Omzet (EUR)'], 'Matrix: sizes as columns');
check($rows[1] === ['Logo', 2, 0, 2, '40,00'], 'Matrix: a missing size counts 0, revenue per design');
check($rows[2][0] === "'+Jubileum", 'Matrix: a design name that looks like a formula is defused');
check($rows[3] === ['TOTAAL PER MAAT', 3, 3, 6, '120,00'], 'Matrix: totals per size');

// --- reading the Google Sheet ---------------------------------------------------------
$url = 'https://docs.google.com/spreadsheets/d/abc123/edit#gid=5';
$GLOBALS['sheet_body'] = "Tijdstempel,Naam,\"Dieet, wensen\"\n"
    . "3/1/2026 10:00:00,,\"geen noten, wel \"\"vega\"\"\"\n"
    . "\n"
    . "3/2/2026 11:00:00,,C:\\pad\\\n"
    . "Totaal,,\n"
    . "na het totaal,,\n";
$preview = AVBK_Sheet_Import::fetch_preview($url, 1);
check($preview['error'] === null && $preview['headers'] === ['A' => 'Tijdstempel', 'B' => 'Naam', 'C' => 'Dieet, wensen'], 'Preview: a quoted header with a comma is one column');
check($preview['rows'][0][2] === 'geen noten, wel "vega"' && $preview['rows'][1][2] === 'C:\\pad\\', 'Preview: quoted cells and backslashes read as before');
check(array_column($preview['raw_rows'], 'row_number') === [1, 2, 4, 5, 6], 'Raw rows: the first three, plus the rows around the total');

$missing = AVBK_Sheet_Import::fetch_preview($url, 9);
check($missing['error'] !== null && count($missing['raw_rows']) === 5, 'Preview: a header row past the end says so, with the raw rows');

AVBK_Sheet_Import::save_config(3, ['sheet_url' => $url, 'slots' => [['name' => 'B', 'email' => '']]]);
$result = AVBK_Sheet_Import::import(3);
check($result['errors'] === [] && $result['preview']['rows'][1][2] === 'C:\\pad\\' && count($result['preview']['rows']) === 2, 'Import: reads the sheet and stops at the total row');
check(AVBK_Sheet_Import::get_config(3)['header_cache']['C'] === 'Dieet, wensen' && AVBK_Sheet_Import::get_config(3)['timestamp_column'] === 'A', 'Import: caches the headers and finds the timestamp column');

exit($failures ? 1 : 0);
