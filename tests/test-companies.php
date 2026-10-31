<?php
/** Synthetic company isolation, bank import, and workbook merge regressions. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
$GLOBALS['test_user'] = 11;
$GLOBALS['test_admin'] = false;
function get_current_user_id(): int { return $GLOBALS['test_user']; }
function current_user_can(string $capability): bool { return $GLOBALS['test_admin'] && get_current_user_id() > 0; }
function get_userdata(int $id) { return $id > 0 ? (object) ['ID' => $id] : false; }
function wp_json_encode($data): string { return json_encode($data, JSON_UNESCAPED_UNICODE); }
function sanitize_file_name(string $name): string { return basename($name); }
function remove_accents(string $text): string { return $text; }
function get_option(string $name, $default = false) { return $GLOBALS['test_options'][$name] ?? $default; }
function update_option(string $name, $value, $autoload = null): bool { $changed = get_option($name) !== $value; $GLOBALS['test_options'][$name] = $value; return $changed; }
function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }

class Company_Test_DB {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public array $roles = [1 => [11 => 'bookkeeper', 12 => 'accountant', 13 => 'ceo'], 2 => [14 => 'ceo']];
    public array $companies;
    public array $transactions = [];
    public array $card_transactions = [];
    public array $documents = [];
    public bool $fail_insert = false;
    private array $snapshot = [];
    public function __construct() {
        $this->companies = [1 => (object) ['id' => 1, 'name' => 'Voorbeeld BV', 'iban' => 'NL91ABNA0417164300', 'sheet_prefix' => 'Alpha'], 2 => (object) ['id' => 2, 'name' => 'Tweede BV', 'iban' => 'NL02ABNA0123456789', 'sheet_prefix' => 'Beta']];
    }
    public function prepare(string $sql, ...$args): array { return [$sql, $args]; }
    public function get_var(array $query) {
        [$sql, $args] = $query;
        if (str_contains($sql, 'SELECT role')) return $this->roles[$args[0]][$args[1]] ?? null;
        if (str_contains($sql, 'avb_company_card_transactions')) {
            foreach ($this->card_transactions as $tx) if ($tx->company_id === $args[0] && $tx->dedupe_hash === $args[1]) return $tx->id;
            return null;
        }
        if (str_contains($sql, 'avb_company_documents')) {
            foreach ($this->documents as $doc) if ($doc->company_id === $args[0] && $doc->content_hash === $args[1]) return $doc->id;
            return null;
        }
        if (str_contains($sql, 'AND id =')) {
            foreach ($this->transactions as $tx) if ($tx->company_id === $args[0] && $tx->id === $args[1]) return $tx->id;
            return null;
        }
        foreach ($this->transactions as $tx) if ($tx->company_id === $args[0] && $tx->dedupe_hash === $args[1]) return $tx->id;
        return null;
    }
    public function get_row(array $query) {
        if (str_contains($query[0], 'avb_company_documents')) {
            foreach ($this->documents as $doc) if ($doc->company_id === $query[1][0] && $doc->id === $query[1][1]) return $doc;
            return null;
        }
        return $this->companies[$query[1][0]] ?? null;
    }
    public function get_results($query): array {
        if (is_string($query)) return array_values($this->companies);
        [$sql, $args] = $query;
        if (str_contains($sql, 'avb_company_card_transactions')) return array_values(array_filter($this->card_transactions, fn($t) => $t->company_id === $args[0] && $t->transaction_date >= $args[1] && $t->transaction_date < $args[2]));
        if (str_contains($sql, 'INNER JOIN')) return array_values(array_filter($this->companies, fn($c) => isset($this->roles[$c->id][$args[0]])));
        return array_values(array_filter($this->transactions, fn($t) => $t->company_id === $args[0] && $t->transaction_date >= $args[1] && $t->transaction_date < $args[2]));
    }
    public function insert(string $table, array $row) {
        if ($this->fail_insert) { $this->fail_insert = false; return false; }
        if (str_contains($table, 'avb_company_card_transactions')) { $this->card_transactions[] = (object) (['id' => ++$this->insert_id] + $row); return 1; }
        if (str_contains($table, 'avb_company_documents')) { $this->documents[] = (object) (['id' => ++$this->insert_id] + $row); return 1; }
        $this->transactions[] = (object) (['id' => ++$this->insert_id] + $row);
        return 1;
    }
    public function replace(string $table, array $row): int { $this->roles[$row['company_id']][$row['user_id']] = $row['role']; return 1; }
    public function update(string $table, array $row, array $where) {
        foreach ($this->companies as $id => $company) if ($id !== $where['id'] && ($company->card_account ?? null) === $row['card_account']) return false;
        $this->companies[$where['id']]->card_account = $row['card_account']; return 1;
    }
    public function delete(string $table, array $row): int { unset($this->roles[$row['company_id']][$row['user_id']]); return 1; }
    public function query(string $sql): int {
        if ($sql === 'START TRANSACTION') $this->snapshot = [$this->transactions, $this->card_transactions];
        if ($sql === 'ROLLBACK') [$this->transactions, $this->card_transactions] = $this->snapshot;
        return 1;
    }
}
$wpdb = new Company_Test_DB();
require AVBK_PLUGIN_DIR . 'includes/class-bank-import-layout.php';
require AVBK_PLUGIN_DIR . 'includes/class-matcher.php';
require AVBK_PLUGIN_DIR . 'includes/class-csv-reader.php';
require AVBK_PLUGIN_DIR . 'includes/class-xlsx-reader.php';
require AVBK_PLUGIN_DIR . 'includes/class-companies.php';
require AVBK_PLUGIN_DIR . 'includes/class-company-documents.php';
require AVBK_PLUGIN_DIR . 'includes/class-vat-patterns.php';
require AVBK_PLUGIN_DIR . 'includes/class-company-workbook.php';
require AVBK_PLUGIN_DIR . 'includes/class-company-report.php';
require AVBK_PLUGIN_DIR . 'includes/class-company-cards.php';

function company_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
function company_reject(callable $operation, string $message): void {
    try { $operation(); } catch (RuntimeException $e) { company_check(true, $message); return; }
    throw new RuntimeException('FAIL: ' . $message);
}
company_check(!class_exists('AVPVH_DB') && !class_exists('AVPVH_Roles'), 'company services work without the members plugin');
company_check(AVBK_Companies::valid_iban('NL91ABNA0417164300') && !AVBK_Companies::valid_iban('NL00ABNA0417164300'), 'IBAN checksum validated');
company_check(AVBK_Companies::period(2026, 4) === ['2026-10-01', '2027-01-01'], 'year-end quarter boundary');
company_reject(fn() => AVBK_Companies::period(2026, 5), 'invalid quarters rejected');
company_check(AVBK_Companies::can(1, 'import') && !AVBK_Companies::can(1, 'access'), 'bookkeeper imports without managing users');
company_check(count(AVBK_Companies::accessible()) === 1, 'only assigned company appears in the switcher');
company_reject(fn() => AVBK_Companies::company(2), 'changing a company ID cannot read another company');
company_reject(fn() => AVBK_Companies::set_access(1, 14, 'ceo'), 'bookkeeper cannot elevate an account');
$GLOBALS['test_user'] = 12;
company_check(AVBK_Companies::can(1, 'view') && AVBK_Companies::can(1, 'export') && !AVBK_Companies::can(1, 'import'), 'accountant reads and downloads without importing');
$GLOBALS['test_user'] = 13;
AVBK_Companies::set_access(1, 15, 'bookkeeper');
company_check($wpdb->roles[1][15] === 'bookkeeper', 'CEO can assign access in their own company');
company_reject(fn() => AVBK_Companies::set_access(2, 15, 'ceo'), 'CEO cannot grant access in another company');
AVBK_Companies::set_access(1, 15, 'remove');
company_check(!isset($wpdb->roles[1][15]), 'CEO can revoke company access');
$GLOBALS['test_user'] = 0; $GLOBALS['test_admin'] = true;
company_check(!AVBK_Companies::can(1, 'view') && AVBK_Companies::accessible() === [], 'anonymous users never inherit site access');
$GLOBALS['test_user'] = 11; $GLOBALS['test_admin'] = false;

$dir = sys_get_temp_dir() . '/avbk-company-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
try {
    $bank = ['Date' => '20260930', 'Name / Description' => 'Leverancier', 'Account' => 'NL91ABNA0417164300', 'Counterparty' => '', 'Debit/credit' => 'Debit', 'Amount (EUR)' => '121.00', 'Notifications' => 'Invoice TEST-1', '__row_number' => 2];
    $parsed = ['rows' => [$bank]];
    $prepared = AVBK_Companies::prepare_import($wpdb->companies[1], $parsed, 2026, 3);
    company_check($prepared[0]['direction'] === 'out' && $prepared[0]['amount'] === '121.00', 'outgoing expenses are kept without assigning VAT');
    foreach (['Account' => 'NL02ABNA0123456789', 'Date' => '20260931', 'Amount (EUR)' => 'nonsense', 'Debit/credit' => ''] as $field => $bad) {
        $invalid = $bank; $invalid[$field] = $bad;
        company_reject(fn() => AVBK_Companies::prepare_import($wpdb->companies[1], ['rows' => [$bank, $invalid]], 2026, 3), 'whole import rejects invalid ' . $field);
    }
    $spanning = [];
    foreach (['20260630', '20260701', '20260930', '20261001', '20250930', '20270930'] as $date) {
        $row = $bank; $row['Date'] = $date; $spanning[] = $row;
    }
    $selected = AVBK_Companies::prepare_import($wpdb->companies[1], ['rows' => $spanning], 2026, 3);
    company_check(array_column($selected, 'transaction_date') === ['2026-07-01', '2026-09-30'], 'longer exports select the exact quarter and year boundaries');
    $previous = $bank; $previous['Date'] = '20260630';
    company_reject(fn() => AVBK_Companies::prepare_import($wpdb->companies[1], ['rows' => [$previous]], 2026, 3), 'export without selected-quarter transactions is rejected');
    foreach (['Account' => 'NL02ABNA0123456789', 'Amount (EUR)' => 'nonsense', 'Debit/credit' => '', 'Date' => '20260230'] as $field => $bad) {
        $invalid = $previous; $invalid[$field] = $bad;
        company_reject(fn() => AVBK_Companies::prepare_import($wpdb->companies[1], ['rows' => [$bank, $invalid]], 2026, 3), 'excluded rows still require valid ' . $field);
    }
    foreach (['1.234,56' => '1234.56', '1234.56' => '1234.56', '1234' => '1234.00'] as $amount => $expected) {
        $row = $bank; $row['Amount (EUR)'] = (string) $amount;
        company_check(AVBK_Companies::prepare_import($wpdb->companies[1], ['rows' => [$row]], 2026, 3)[0]['amount'] === $expected, 'amount parsing: ' . $amount);
    }
    $invalid = $bank; $invalid['Amount (EUR)'] = '1.234';
    company_reject(fn() => AVBK_Companies::prepare_import($wpdb->companies[1], ['rows' => [$invalid]], 2026, 3), 'ambiguous three-decimal amounts rejected');
    $csv = $dir . '/bank.csv';
    $handle = fopen($csv, 'w');
    $csv_bank = $bank; unset($csv_bank['__row_number']);
    fputcsv($handle, array_keys($csv_bank), ';'); fputcsv($handle, $csv_bank, ';');
    $csv_previous = $csv_bank; $csv_previous['Date'] = '20260630';
    fputcsv($handle, $csv_previous, ';'); fclose($handle);
    $result = AVBK_Companies::import(1, $csv, 'bank.csv', 2026, 3);
    company_check($result === ['added' => 1, 'duplicates' => 0, 'outside_period' => 1], 'semicolon CSV upload stores selected-quarter rows and reports excluded rows');
    company_check(AVBK_Companies::import(1, $csv, 'bank.csv', 2026, 3) === ['added' => 0, 'duplicates' => 1, 'outside_period' => 1], 'overlapping longer exports do not duplicate transactions');
    company_reject(fn() => AVBK_Companies::import(2, $csv, 'bank.csv', 2026, 3), 'import cannot target an unassigned company');
    $document_id = AVBK_Company_Documents::add(1, $wpdb->transactions[0]->id, 'https://example.test/invoices/TEST-1.pdf');
    company_check(AVBK_Company_Documents::document(1, $document_id)->document_data === null, 'invoice pointer stored without fetching external data');
    company_reject(fn() => AVBK_Company_Documents::document(2, $document_id), 'invoice access cannot cross company boundaries');
    company_reject(fn() => AVBK_Company_Documents::add(1, 999, 'missing payment'), 'invoice cannot link an unrelated payment');
    company_reject(fn() => AVBK_Company_Documents::add(1, 0, ''), 'invoice requires evidence or a reference');
    company_reject(fn() => AVBK_Company_Documents::add(1, 0, '', $csv, 'fake.pdf'), 'invoice upload rejects ordinary server files');
    company_reject(fn() => AVBK_Company_Documents::add(1, 0, 'https://example.test/invoices/TEST-1.pdf'), 'duplicate invoice references rejected');
    $GLOBALS['test_user'] = 12;
    company_check(AVBK_Company_Documents::document(1, $document_id)->id === $document_id, 'assigned accountant can inspect invoice evidence');
    company_reject(fn() => AVBK_Company_Documents::add(1, 0, 'https://example.test/new.pdf'), 'read-only accountant cannot upload invoices');
    $GLOBALS['test_user'] = 11;
    $wpdb->transactions = []; $wpdb->fail_insert = true;
    company_reject(fn() => AVBK_Companies::import(1, $csv, 'bank.csv', 2026, 3), 'database errors roll back imports');
    company_check($wpdb->transactions === [], 'failed import leaves no rows');

    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $text = fn($ref, $value) => '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
    $sheet_xml = function (int $year) use ($ns, $text): string {
        return '<worksheet xmlns="' . $ns . '"><dimension ref="A1:P5"/><sheetData><row r="1">' . $text('A1', 'Date') . $text('B1', 'Name / Description') . $text('I1', 'BTW %') . '</row>'
            . '<row r="2">' . $text('A2', $year . '0920') . $text('B2', 'Leverancier') . $text('C2', 'NL91ABNA0417164300') . $text('F2', 'Debit') . '<c r="G2"><v>121</v></c><c r="I2"><v>21</v></c><c r="J2"><f>G2-K2</f><v>21</v></c><c r="K2"><f t="shared" si="0" ref="K2:K4">G2/1.21</f><v>100</v></c>' . $text('N2', 'Invoice EXISTING') . '</row>'
            . '<row r="3">' . $text('B3', 'Card detail') . '<c r="F3"><f>SUM(G3:G3)</f><v>12.10</v></c><c r="G3"><v>12.10</v></c></row>'
            . '<row r="4">' . $text('A4', $year . '0901') . $text('B4', 'Tweede leverancier') . $text('C4', 'NL91ABNA0417164300') . $text('F4', 'Debit') . '<c r="G4"><v>24.20</v></c><c r="K4"><f t="shared" si="0"/><v>20</v></c>' . $text('N4', 'Invoice OTHER') . '</row>'
            . '<row r="5"><c r="H5"><f t="shared" si="1" ref="H5:I5">SUM(H2:H4)</f><v>145.20</v></c><c r="I5"><f t="shared" si="1"/><v>21</v></c><c r="J5"><f>SUM(J2:J4)</f><v>21</v></c></row></sheetData></worksheet>';
    };
    $make_workbook = function (string $path, int $year) use ($sheet_xml, $ns): void {
        $zip_path = $path . '.zip'; $archive = new PharData($zip_path, 0, null, Phar::ZIP);
        $archive['xl/workbook.xml'] = '<workbook xmlns="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Alpha Q3" sheetId="1" r:id="rId1"/><sheet name="Beta Q3" sheetId="2" r:id="rId2"/></sheets><calcPr/></workbook>';
        $archive['xl/_rels/workbook.xml.rels'] = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/><Relationship Id="rId2" Target="worksheets/sheet2.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/><Relationship Id="rId3" Target="calcChain.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/calcChain"/></Relationships>';
        $archive['[Content_Types].xml'] = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/calcChain.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.calcChain+xml"/></Types>';
        $archive['xl/worksheets/sheet1.xml'] = $sheet_xml($year);
        $archive['xl/worksheets/sheet2.xml'] = $sheet_xml(2024);
        $archive['xl/calcChain.xml'] = '<calcChain xmlns="' . $ns . '"/>';
        unset($archive); rename($zip_path, $path);
    };
    $tx = (object) ($prepared[0] + ['company_id' => 1, 'id' => 1]);
    $tx->counterparty_name = '=HYPERLINK("https://example.test","text")';
    $source = $dir . '/template.xlsx'; $output = $dir . '/updated.xlsx';
    $make_workbook($source, 2025); $source_hash = hash_file('sha256', $source);
    $result = AVBK_Company_Workbook::update($source, $output, $wpdb->companies[1], [$tx], 2026, 3);
    company_check($result === ['sheet' => 'Alpha Q3', 'added' => 1, 'preserved' => 0, 'suggested' => 0, 'cards_added' => 0], 'prior-year template updates the correct named quarter');
    company_check(hash_file('sha256', $source) === $source_hash, 'source workbook is never modified');
    $original = new AVBK_Xlsx_Archive($source); $updated = new AVBK_Xlsx_Archive($output);
    company_check($original->getFromName('xl/worksheets/sheet2.xml') === $updated->getFromName('xl/worksheets/sheet2.xml'), 'other company sheet remains byte-for-byte unchanged');
    $xml = $updated->getFromName('xl/worksheets/sheet1.xml');
    company_check(!str_contains($xml, '202509') && str_contains($xml, '20260930'), 'stale bank rows replaced with current-year data');
    company_check(str_contains($xml, 't="inlineStr"><is><t>=HYPERLINK') && !str_contains($xml, '<f>HYPERLINK'), 'bank text cannot become an Excel formula');
    company_check($updated->getFromName('xl/calcChain.xml') === false && str_contains($updated->getFromName('xl/workbook.xml'), 'fullCalcOnLoad="1"'), 'calculation chain removed and recalculation requested');
    $original->close(); $updated->close();
    $rows = AVBK_Xlsx_Reader::read_named_rows($output, 'Alpha Q3');
    company_check($rows[2][8] === '' && $rows[2][9] === '', 'new transactions have blank VAT fields for invoice review');

    $current = $dir . '/current.xlsx'; $merged = $dir . '/merged.xlsx'; $make_workbook($current, 2026);
    $result = AVBK_Company_Workbook::update($current, $merged, $wpdb->companies[1], [$tx], 2026, 3);
    company_check($result['preserved'] === 2 && $result['added'] === 1, 'current-year bank rows merged instead of replaced');
    $rows = AVBK_Xlsx_Reader::read_named_rows($merged, 'Alpha Q3');
    company_check($rows[3][8] === '21' && $rows[3][9] === '21' && $rows[4][1] === 'Card detail', 'VAT annotations and manual card detail rows preserved');
    $archive = new AVBK_Xlsx_Archive($merged); $xml = $archive->getFromName('xl/worksheets/sheet1.xml'); $archive->close();
    company_check(str_contains($xml, 'SUM(G4:G4)') && str_contains($xml, 'G5/1.21') && str_contains($xml, 'SUMIF(P2:P5'), 'manual, shared, and total formulas follow moved rows');
    $again = $dir . '/again.xlsx';
    company_check(AVBK_Company_Workbook::update($merged, $again, $wpdb->companies[1], [$tx], 2026, 3)['added'] === 0, 'repeated workbook update does not duplicate bank rows');
    company_reject(fn() => AVBK_Company_Workbook::update($source, $source, $wpdb->companies[1], [$tx], 2026, 3), 'source cannot be overwritten in place');
    company_reject(fn() => AVBK_Company_Workbook::update($source, $dir . '/wrong.xlsx', $wpdb->companies[2], [$tx], 2026, 3), 'export refuses transactions from another company');

    $history = AVBK_Vat_Patterns::history($source, $wpdb->companies[1], 2026, 3);
    $supplier = clone $tx; $supplier->counterparty_name = 'Leverancier';
    $suggestion = AVBK_Vat_Patterns::suggest($history, $supplier);
    company_check($suggestion['code'] === '21' && $suggestion['source'] === 'Alpha Q3!I2', 'VAT suggested with an earlier source in the same company');
    company_check(AVBK_Vat_Patterns::history($source, $wpdb->companies[2], 2026, 3) === [], 'patterns do not use another company bank account');
    $conflict = $history;
    foreach ($conflict as &$entries) $entries[] = ['code' => '0', 'vat' => 0, 'source' => 'Alpha Q2!I2', 'date' => '2025-01-01'];
    unset($entries);
    company_check(AVBK_Vat_Patterns::suggest($conflict, $supplier)['status'] === 'conflict', 'conflicting VAT history requires review');
    $settlement = clone $supplier; $settlement->counterparty_name = 'ING Commercial Cards';
    company_check(AVBK_Vat_Patterns::suggest($history, $settlement)['status'] === 'settlement', 'credit-card settlement cannot inherit a purchase VAT rate');
    $suggested_path = $dir . '/suggested.xlsx';
    company_check(AVBK_Company_Workbook::update($source, $suggested_path, $wpdb->companies[1], [$supplier], 2026, 3)['suggested'] === 1, 'new workbook rows receive supported VAT suggestions');
    $rows = AVBK_Xlsx_Reader::read_named_rows($suggested_path, 'Alpha Q3');
    company_check($rows[2][8] === '21' && $rows[2][9] === '21' && str_starts_with($rows[2][15], 'BTW-suggestie:'), 'suggested VAT is traceable and marked for review');
    $archive = new AVBK_Xlsx_Archive($suggested_path); $xml = $archive->getFromName('xl/worksheets/sheet1.xml'); $archive->close();
    company_check(str_contains($xml, '&lt;&gt;BTW-*'), 'pending suggestions excluded from checked VAT totals');

    $report = AVBK_Company_Report::build($suggested_path, $wpdb->companies[1], [$supplier], 2026, 3, 'quarter.xlsx');
    company_check(count($report['rows']) === 1 && $report['bank_rows'] === 1, 'result excludes the provisional worksheet footer');
    company_check($report['totals']['known_vat_cents'] === 2100 && $report['totals']['known_net_cents'] === 10000, 'result totals show VAT and ex-VAT amounts from row values');
    company_check($report['totals']['pending_rows'] === 1 && $report['totals']['confirmed_vat_cents'] === 0 && $report['totals']['confirmed_net_cents'] === 0, 'unreviewed suggestions are separate from unmarked totals');
    AVBK_Company_Report::save($report);
    company_check(AVBK_Company_Report::get(1, 2026, 3) === $report, 'last workbook result persists privately per company and period');
    AVBK_Company_Report::save($report);
    company_check(AVBK_Company_Report::get(1, 2026, 2) === null, 'another quarter does not inherit a workbook result');
    company_reject(fn() => AVBK_Company_Report::get(2, 2026, 3), 'result cannot be read through another company ID');
    $foreign = $report; $foreign['company_id'] = 2;
    company_reject(fn() => AVBK_Company_Report::save($foreign), 'publishing a result requires access to that company');
    $GLOBALS['test_user'] = 12;
    company_check(AVBK_Company_Report::get(1, 2026, 3) === $report, 'assigned accountant can read the shared workbook result');
    $GLOBALS['test_user'] = 0;
    company_reject(fn() => AVBK_Company_Report::get(1, 2026, 3), 'anonymous users cannot read result snapshots');
    $GLOBALS['test_user'] = 11;
    $missing = AVBK_Company_Report::build($output, $wpdb->companies[1], [$tx], 2026, 3, 'unknown.xlsx');
    company_check($missing['rows'][0]['vat_cents'] === null && $missing['rows'][0]['net_cents'] === null && $missing['totals']['unknown_rows'] === 1, 'blank VAT values remain unknown rather than becoming zero');
    $extra_tx = clone $tx; $extra_tx->id = 99;
    company_check($report['transactions_hash'] !== AVBK_Company_Report::fingerprint([$supplier, $extra_tx]), 'later bank imports make a saved result stale');
    company_check(AVBK_Company_Report::fingerprint([$supplier, $extra_tx]) === AVBK_Company_Report::fingerprint([$extra_tx, $supplier]), 'result freshness is independent of transaction order');
    company_reject(fn() => AVBK_Company_Report::build($source, $wpdb->companies[1], [], 2026, 3, 'prior-year.xlsx'), 'result rejects a previous-year sheet');
    $newer = clone $supplier; $newer->amount = '242.00';
    company_reject(fn() => AVBK_Company_Report::build($suggested_path, $wpdb->companies[1], [$newer], 2026, 3, 'older-result.xlsx'), 'result cannot appear current when a bank transaction is missing');

    $signed_path = $dir . '/signed-report.xlsx'; copy($suggested_path, $signed_path);
    $archive = new AVBK_Xlsx_Archive($signed_path);
    $archive->put('xl/worksheets/sheet1.xml', '<worksheet xmlns="' . $ns . '"><sheetData><row r="1">' . $text('A1', 'Date') . $text('I1', 'BTW %') . '</row>'
        . '<row r="2">' . $text('A2', '20260920') . $text('B2', 'Voorbeeld') . $text('C2', 'NL91ABNA0417164300') . $text('F2', 'Credit')
        . '<c r="G2"><v>121</v></c><c r="H2"><v>-121</v></c><c r="I2"><v>21</v></c><c r="J2"><v>-21</v></c><c r="K2"><v>-100</v></c></row>'
        . '<row r="3">' . $text('B3', 'Totaal') . '<c r="H3"><v>999999</v></c><c r="J3"><v>999999</v></c><c r="K3"><v>999999</v></c></row></sheetData></worksheet>');
    $archive->close();
    $income_report = AVBK_Company_Report::build($signed_path, $wpdb->companies[1], [], 2026, 3, 'income.xlsx');
    company_check($income_report['totals']['gross_cents'] === -12100 && $income_report['totals']['confirmed_vat_cents'] === -2100 && $income_report['totals']['confirmed_net_cents'] === -10000, 'incoming VAT and net values keep workbook signs and ignore cached footer totals');
    $report['rows'][0]['name'] = '<script>alert(1)</script>';
    $report_stale = false;
    ob_start(); require AVBK_PLUGIN_DIR . 'admin/company-report.php'; $html = ob_get_clean();
    company_check(str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'), 'result display escapes imported worksheet text');

    company_reject(fn() => AVBK_Company_Cards::link(1, '12345678901'), 'bookkeeper cannot change the card account binding');
    $GLOBALS['test_user'] = 13;
    AVBK_Company_Cards::link(1, "'12345678901");
    company_check($wpdb->companies[1]->card_account === '12345678901', 'CEO links the statement account, removing spreadsheet text prefixes');
    $GLOBALS['test_user'] = 14;
    company_reject(fn() => AVBK_Company_Cards::link(2, '12345678901'), 'one card account cannot be assigned to both companies');
    AVBK_Company_Cards::link(2, '23456789012');
    $GLOBALS['test_user'] = 11;
    $card_row = ['Accountnummer' => "'12345678901", 'Kaartnummer' => '1234 **** **** 5678', 'Naam op kaart' => 'Voorbeeld',
        'Transactiedatum' => '2026-09-30', 'Boekingsdatum' => '2026-10-02', 'Omschrijving' => 'Leverancier',
        'Valuta' => 'USD', 'Bedrag' => '-132,00', 'Koers' => '1,09', 'Bedrag in EUR' => '-121,00'];
    $refund = $card_row; $refund['Omschrijving'] = 'Retour leverancier'; $refund['Bedrag'] = '13,20'; $refund['Bedrag in EUR'] = '12,10';
    $payment = $card_row; $payment['Omschrijving'] = 'Betaling creditcard'; $payment['Bedrag'] = '132,00'; $payment['Bedrag in EUR'] = '121,00';
    $other_account = $card_row; $other_account['Accountnummer'] = '23456789012';
    $other_quarter = $card_row; $other_quarter['Transactiedatum'] = '2026-06-30';
    $card_parsed = ['headers' => array_keys($card_row), 'rows' => [$card_row, $card_row, $refund, $payment, $other_account, $other_quarter]];
    $card_plan = AVBK_Company_Cards::prepare($wpdb->companies[1], $card_parsed, 2026, 3);
    company_check(count($card_plan['rows']) === 4 && $card_plan['outside_account'] === 1 && $card_plan['outside_period'] === 1, 'mixed-account card export selects the linked account and transaction quarter');
    company_check($card_plan['rows'][0]['amount'] === '121.00' && $card_plan['rows'][0]['original_amount'] === '-132.00' && $card_plan['rows'][0]['currency'] === 'USD', 'card import uses the provider EUR amount while retaining original currency');
    company_check($card_plan['rows'][0]['entry_type'] === 'purchase' && $card_plan['rows'][2]['entry_type'] === 'refund' && $card_plan['rows'][3]['entry_type'] === 'settlement', 'card purchases, refunds and statement payments remain distinct');
    company_check($card_plan['rows'][0]['dedupe_hash'] !== $card_plan['rows'][1]['dedupe_hash'], 'two identical card purchases in one export are both retained');
    $safe_card_raw = json_decode($card_plan['rows'][0]['raw_data'], true);
    company_check(!isset($safe_card_raw['Naam op kaart']) && $safe_card_raw['Kaartnummer'] === '**** 5678', 'card imports retain only the last four card digits and omit cardholder names');
    $bad_card = $other_account; $bad_card['Bedrag in EUR'] = 'nonsense';
    company_reject(fn() => AVBK_Company_Cards::prepare($wpdb->companies[1], ['headers' => $card_parsed['headers'], 'rows' => [$card_row, $bad_card]], 2026, 3), 'excluded card rows must still contain valid financial data');
    $card_csv = $dir . '/cards.csv'; $handle = fopen($card_csv, 'w');
    fputcsv($handle, $card_parsed['headers']); foreach ($card_parsed['rows'] as $r) fputcsv($handle, $r); fclose($handle);
    company_check(AVBK_Company_Cards::import(1, $card_csv, 'cards.csv', 2026, 3) === ['added' => 4, 'duplicates' => 0, 'outside_account' => 1, 'outside_period' => 1], 'card CSV is saved separately from bank records');
    company_check(AVBK_Company_Cards::import(1, $card_csv, 'cards.csv', 2026, 3)['duplicates'] === 4, 'overlapping card statements do not duplicate purchases');
    $broken_card_csv = $dir . '/card-rollback.csv'; $handle = fopen($broken_card_csv, 'w');
    $new_purchase = $card_row; $new_purchase['Bedrag in EUR'] = '-242,00';
    fputcsv($handle, $card_parsed['headers']); fputcsv($handle, $new_purchase); fclose($handle);
    $wpdb->fail_insert = true;
    company_reject(fn() => AVBK_Company_Cards::import(1, $broken_card_csv, 'cards.csv', 2026, 3), 'card database failures abort the complete import');
    company_check(count($wpdb->card_transactions) === 4, 'failed card import preserves existing purchases');
    $GLOBALS['test_user'] = 12;
    company_reject(fn() => AVBK_Company_Cards::import(1, $card_csv, 'cards.csv', 2026, 3), 'accountant cannot import card purchases');
    $GLOBALS['test_user'] = 11;
    $cards = AVBK_Company_Cards::transactions(1, 2026, 3);
    company_check(count($cards) === 4 && count($wpdb->transactions) === 0, 'card transactions do not become bank payments');
    company_reject(fn() => AVBK_Company_Cards::transactions(2, 2026, 3), 'card ledger cannot cross company access boundaries');
    $settlement_tx = clone $tx; $settlement_tx->counterparty_name = 'ING Commercial Cards';
    $card_output = $dir . '/with-cards.xlsx';
    $card_update = AVBK_Company_Workbook::update($source, $card_output, $wpdb->companies[1], [$settlement_tx], 2026, 3, $cards);
    company_check($card_update['cards_added'] === 3, 'workbook adds purchases and refunds but excludes card payment rows');
    $card_report = AVBK_Company_Report::build($card_output, $wpdb->companies[1], [$settlement_tx], 2026, 3, 'with-cards.xlsx', $cards);
    company_check($card_report['card_rows'] === 3 && $card_report['bank_rows'] === 1 && $card_report['totals']['gross_cents'] === 12100 && $card_report['totals']['card_gross_cents'] === 22990 && $card_report['totals']['purchase_gross_cents'] === 22990, 'statement cash payment and card expenses are not counted twice');
    company_check($card_report['totals']['known_vat_cents'] === 4200 && $card_report['totals']['known_net_cents'] === 20000 && $card_report['totals']['confirmed_vat_cents'] === 0, 'card VAT suggestions contribute only to provisional totals');
    $card_again = $dir . '/with-cards-again.xlsx';
    company_check(AVBK_Company_Workbook::update($card_output, $card_again, $wpdb->companies[1], [$settlement_tx], 2026, 3, $cards)['cards_added'] === 0, 'repeated worksheet updates preserve card rows without duplicates');
    company_reject(fn() => AVBK_Company_Workbook::update($current, $dir . '/manual-card-conflict.xlsx', $wpdb->companies[1], [$tx], 2026, 3, $cards), 'legacy manual detail rows require review before adding card imports');
} finally {
    foreach (glob($dir . '/*') as $file) unlink($file);
    rmdir($dir);
}
