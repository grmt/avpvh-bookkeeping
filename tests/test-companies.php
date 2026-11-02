<?php
/** Synthetic company isolation, bank import, and workbook merge regressions. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
define('WP_CLI', true);
$GLOBALS['test_user'] = 11;
$GLOBALS['test_admin'] = false;
function get_current_user_id(): int { return $GLOBALS['test_user']; }
function current_user_can(string $capability): bool { return $GLOBALS['test_admin'] && get_current_user_id() > 0; }
function get_userdata(int $id) { return $id > 0 ? (object) ['ID' => $id] : false; }
function current_time(string $type) { return $type === 'mysql' ? '2026-08-15 09:00:00' : strtotime('2026-08-15 09:00:00'); }
function wp_json_encode($data): string { return json_encode($data, JSON_UNESCAPED_UNICODE); }
function sanitize_file_name(string $name): string { return basename($name); }
function remove_accents(string $text): string { return $text; }
function get_option(string $name, $default = false) { return $GLOBALS['test_options'][$name] ?? $default; }
function update_option(string $name, $value, $autoload = null): bool { $changed = get_option($name) !== $value; $GLOBALS['test_options'][$name] = $value; return $changed; }
function add_option($name, $value, $unused = '', $autoload = null): bool { if (get_option($name) !== false) return false; return update_option($name, $value, $autoload); }
function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text): string { return esc_html($text); }
function esc_url($text): string { return esc_attr($text); }
function admin_url($path): string { return 'https://example.test/wp-admin/' . $path; }
function wp_nonce_field($action): void { echo '<input type="hidden" name="_wpnonce" value="synthetic">'; }
function wp_nonce_url($url, $action): string { return $url . '&_wpnonce=synthetic'; }
function add_query_arg($args, $url): string { return $url . '?' . http_build_query($args); }
function selected($value, $expected): void { if ((string) $value === (string) $expected) echo 'selected'; }
function is_email($value): bool { return (bool) filter_var($value, FILTER_VALIDATE_EMAIL); }
function home_url($path = ''): string { return 'https://example.test' . $path; }
function add_action($hook, $callback): void {}
function remove_action($hook, $callback): void {}
function wp_mail($recipient, $subject, $html, $headers): bool { $GLOBALS['test_mails'][] = compact('recipient', 'subject', 'html'); return true; }
function maybe_serialize($value): string { return serialize($value); }
function wp_cache_delete($key, $group): bool { return true; }
function wp_tempnam($name) { return tempnam(sys_get_temp_dir(), 'avbk-review-test-'); }
function wp_delete_file($path): void { unlink($path); }

class Company_Test_DB {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public bool $fail_compare = false;
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
        if (str_contains($sql, 'avb_company_documents')) {
            $documents = array_filter($this->documents, function ($doc) use ($sql, $args) {
                if ($doc->company_id !== $args[0]) return false;
                if (str_contains($sql, "document_kind = 'invoice'")) {
                    if ($doc->document_kind !== 'invoice') return false;
                    if (str_contains($sql, 'paid_at IS NULL')) return ($doc->due_date ?? null) !== null && ($doc->paid_at ?? null) === null;
                    return true;
                }
                return in_array($doc->document_kind, ['payslip', 'payroll_tax'], true) && $doc->document_period >= $args[1] && $doc->document_period < $args[2];
            });
            preg_match('/SELECT (.+?) FROM/', $sql, $match);
            $fields = array_flip(array_map('trim', explode(',', $match[1])));
            return array_values(array_map(fn($doc) => (object) array_intersect_key((array) $doc, $fields), $documents));
        }
        if (str_contains($sql, 'avb_company_card_transactions')) return array_values(array_filter($this->card_transactions, fn($t) => $t->company_id === $args[0] && $t->transaction_date >= $args[1] && $t->transaction_date < $args[2]));
        if (str_contains($sql, 'INNER JOIN')) return array_values(array_filter($this->companies, fn($c) => isset($this->roles[$c->id][$args[0]])));
        if (str_contains($sql, "direction = 'out' AND amount")) return array_values(array_filter($this->transactions, fn($t) => $t->company_id === $args[0] && $t->direction === 'out' && number_format((float) $t->amount, 2, '.', '') === $args[1]));
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
        if (str_contains($table, 'avb_company_documents')) {
            foreach ($this->documents as $doc) {
                if ($doc->id === $where['id'] && $doc->company_id === $where['company_id']) {
                    foreach ($row as $key => $value) $doc->$key = $value;
                    return 1;
                }
            }
            return false;
        }
        foreach ($this->companies as $id => $company) if ($id !== $where['id'] && ($company->card_account ?? null) === $row['card_account']) return false;
        $this->companies[$where['id']]->card_account = $row['card_account']; return 1;
    }
    public function delete(string $table, array $row): int { unset($this->roles[$row['company_id']][$row['user_id']]); return 1; }
    public function query($sql): int {
        if (is_array($sql)) {
            [, $args] = $sql;
            if ($this->fail_compare) { $this->fail_compare = false; return 0; }
            if (maybe_serialize(get_option($args[1])) !== $args[2]) return 0;
            $GLOBALS['test_options'][$args[1]] = unserialize($args[0]);
            return 1;
        }
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
require AVBK_PLUGIN_DIR . 'includes/class-company-features.php';
require AVBK_PLUGIN_DIR . 'includes/class-company-documents.php';
require AVBK_PLUGIN_DIR . 'includes/class-company-payroll.php';
require AVBK_PLUGIN_DIR . 'includes/class-company-payroll-automation.php';
require AVBK_PLUGIN_DIR . 'includes/class-qr.php';
require AVBK_PLUGIN_DIR . 'includes/class-vat-patterns.php';
require AVBK_PLUGIN_DIR . 'includes/class-company-workbook.php';
require AVBK_PLUGIN_DIR . 'includes/class-company-report.php';
require AVBK_PLUGIN_DIR . 'includes/class-company-review.php';
require AVBK_PLUGIN_DIR . 'includes/class-company-cards.php';
require AVBK_PLUGIN_DIR . 'includes/class-company-card-groups.php';
require AVBK_PLUGIN_DIR . 'includes/class-company-admin.php';

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
    company_check(AVBK_Company_Documents::add(1, 0, 'https://example.test/invoices/TEST-1.pdf', '', '', true) === $document_id, 'batch uploads can safely reuse existing private documents');
    $due_id = AVBK_Company_Documents::add(1, 0, 'https://example.test/invoices/TEST-2.pdf', '', '', false, [], ['invoice_date' => '2026-07-01', 'due_date' => '2026-07-31', 'amount_total' => '1234,56']);
    $stored = AVBK_Company_Documents::document(1, $due_id);
    company_check($stored->invoice_date === '2026-07-01' && $stored->due_date === '2026-07-31' && (float) $stored->amount_total === 1234.56, 'invoice dates and amount are stored from the upload form');
    company_reject(fn() => AVBK_Company_Documents::add(1, 0, 'https://example.test/invoices/TEST-3.pdf', '', '', false, [], ['invoice_date' => '2026-13-40']), 'an invalid invoice date is rejected');
    company_reject(fn() => AVBK_Company_Documents::add(1, 0, 'https://example.test/invoices/TEST-4.pdf', '', '', false, [], ['invoice_date' => '2026-07-31', 'due_date' => '2026-07-01']), 'a due date before the invoice date is rejected');
    company_reject(fn() => AVBK_Company_Documents::add(1, 0, 'https://example.test/invoices/TEST-5.pdf', '', '', false, [], ['amount_total' => '12,345']), 'an invoice amount with more than two decimals is rejected');
    $unpaid = AVBK_Company_Documents::unpaid(1);
    company_check(count($unpaid) === 1 && $unpaid[0]->id === $due_id, 'unpaid() lists only invoices with a due date that are not yet paid');
    company_reject(fn() => AVBK_Company_Documents::mark_paid(1, $due_id, '2026-13-40'), 'an invalid payment date is rejected');
    company_reject(fn() => AVBK_Company_Documents::mark_paid(2, $due_id), 'marking an invoice paid cannot cross company boundaries');
    AVBK_Company_Documents::mark_paid(1, $due_id, '2026-08-01');
    company_check(str_starts_with((string) AVBK_Company_Documents::document(1, $due_id)->paid_at, '2026-08-01'), 'a backdated payment date is stored as given');
    company_check(AVBK_Company_Documents::unpaid(1) === [], 'a paid invoice no longer appears as unpaid');
    AVBK_Company_Documents::mark_unpaid(1, $due_id);
    company_check(AVBK_Company_Documents::document(1, $due_id)->paid_at === null, 'reopening an invoice clears its paid timestamp');
    $GLOBALS['test_user'] = 12;
    company_reject(fn() => AVBK_Company_Documents::mark_paid(1, $due_id), 'read-only accountant cannot change invoice payment status');
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
    company_check(hash('sha256', base64_decode($report['workbook_data'], true)) === $report['workbook_hash'], 'private downloadable working copy matches the reviewed workbook');
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
    $report['workbook_data'] = null;
    $report_stale = false;
    $company_id = 1;
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
    $pdf_row = $card_row; $pdf_row['Boekingsdatum'] = '';
    $pdf_statement = ['headers' => array_keys($pdf_row), 'rows' => [$pdf_row], 'card_account' => '12345678901', 'customer_reference' => '417164300', 'statement_total' => '121.00'];
    $pdf_plan = AVBK_Company_Cards::prepare($wpdb->companies[1], $pdf_statement, 2026, 3, true);
    company_check($pdf_plan['rows'][0]['booking_date'] === null && $pdf_plan['rows'][0]['dedupe_hash'] === $card_plan['rows'][0]['dedupe_hash'], 'PDF booking dates remain unknown and overlapping CSV purchases have the same identity');
    company_check(AVBK_Company_Cards::import_statement(1, $pdf_statement, 'statement.pdf', 2026, 3)['duplicates'] === 1, 'interpreted statement and CSV do not duplicate the same purchase');
    $statement_pdf = base64_encode("%PDF-1.4\n% Synthetic credit card statement\n%%EOF\n");
    $statement_id = AVBK_Company_Documents::import_mail(1, 'Synthetic card statement', 'card-statement.pdf', $statement_pdf);
    $verified_statement = $pdf_statement + ['document_id' => $statement_id, 'source_hash' => hash('sha256', base64_decode($statement_pdf)), 'statement_date' => '2026-10-09'];
    company_check(AVBK_Company_Cards::import_statement(1, $verified_statement, 'card-statement.pdf', 2026, 3)['duplicates'] === 1, 'statement metadata can be registered without duplicating earlier CSV purchases');
    $metadata = get_option('avbk_company_card_statements_1')[$statement_id];
    company_check($metadata['total_cents'] === 12100 && $metadata['transaction_hashes'] === [$card_plan['rows'][0]['dedupe_hash']], 'verified full statement total and purchase identities are retained separately from quarter totals');
    $wrong_source = $verified_statement; $wrong_source['source_hash'] = str_repeat('f', 64);
    company_reject(fn() => AVBK_Company_Cards::import_statement(1, $wrong_source, 'card-statement.pdf', 2026, 3), 'statement metadata rejects a mismatched source document hash');
    $wrong_source['source_hash'] = $verified_statement['source_hash']; $wrong_source['document_id'] = 999;
    company_reject(fn() => AVBK_Company_Cards::import_statement(1, $wrong_source, 'card-statement.pdf', 2026, 3), 'statement metadata cannot refer to an unrelated private document');
    $bad_statement = $pdf_statement; $bad_statement['customer_reference'] = '999999999';
    company_reject(fn() => AVBK_Company_Cards::import_statement(1, $bad_statement, 'statement.pdf', 2026, 3), 'PDF customer reference must match the company bank account');
    $bad_statement = $pdf_statement; $bad_statement['statement_total'] = '242.00';
    company_reject(fn() => AVBK_Company_Cards::import_statement(1, $bad_statement, 'statement.pdf', 2026, 3), 'PDF purchase rows must reconcile to the statement total');
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
    $single_upload = ['name' => 'statement.pdf', 'tmp_name' => '/tmp/upload-test', 'size' => 1024, 'error' => UPLOAD_ERR_OK];
    company_check(AVBK_Company_Admin::statement_uploads($single_upload) === [$single_upload], 'batch uploader remains compatible with a single statement');
    $batch_upload = ['name' => ['statement.pdf', 'cards.csv'], 'tmp_name' => ['/tmp/upload-a', '/tmp/upload-b'], 'size' => [1024, 2048], 'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK]];
    $batch = AVBK_Company_Admin::statement_uploads($batch_upload);
    company_check(count($batch) === 2 && $batch[0]['name'] === 'statement.pdf' && $batch[1]['name'] === 'cards.csv', 'mixed multi-file upload metadata is normalized without dropping files');
    $broken_batch = $batch_upload; unset($broken_batch['tmp_name'][1]);
    company_reject(fn() => AVBK_Company_Admin::statement_uploads($broken_batch), 'incomplete multipart file arrays are rejected');
    $too_many = [];
    foreach ($single_upload as $key => $value) $too_many[$key] = array_fill(0, 21, $value);
    company_reject(fn() => AVBK_Company_Admin::statement_uploads($too_many), 'server enforces the 20-file batch limit');
    $oversized = $batch_upload; $oversized['size'] = [11 * 1024 * 1024, 10 * 1024 * 1024];
    company_reject(fn() => AVBK_Company_Admin::statement_uploads($oversized), 'server enforces the aggregate batch size limit');
    $mail_pdf = base64_encode("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    $mail_id = AVBK_Company_Documents::import_mail(1, 'Mailbox: invoice candidate TEST-2; review required', 'TEST-2.pdf', $mail_pdf);
    company_check(base64_decode(AVBK_Company_Documents::document(1, $mail_id)->document_data) === base64_decode($mail_pdf), 'mail attachments are stored as private company documents');
    company_check(AVBK_Company_Documents::import_mail(1, 'Mailbox: repeated candidate', 'TEST-2.pdf', $mail_pdf) === $mail_id, 'repeated mailbox collection does not duplicate attachments');
    company_reject(fn() => AVBK_Company_Documents::import_mail(2, 'Mailbox candidate', 'TEST-2.pdf', $mail_pdf), 'mail collector cannot import into an inaccessible company');
    company_reject(fn() => AVBK_Company_Documents::import_mail(1, 'Mailbox candidate', 'fake.pdf', base64_encode('not a PDF')), 'mail collection validates attachment bytes rather than trusting the filename');
    $GLOBALS['test_user'] = 12;
    company_reject(fn() => AVBK_Company_Documents::import_mail(1, 'Mailbox candidate', 'TEST-2.pdf', $mail_pdf), 'read-only accountant cannot collect invoices');
    $GLOBALS['test_user'] = 11;

    $cloud_card = clone $cards[0]; $cloud_card->id = 101; $cloud_card->description = 'GOOGLE *CLOUD TESTREF CC GOOGLE.COM IRL'; $cloud_card->dedupe_hash = hash('sha256', 'synthetic-cloud-purchase');
    $unknown_card_path = $dir . '/unknown-card-vat.xlsx';
    AVBK_Company_Workbook::update($source, $unknown_card_path, $wpdb->companies[1], [$settlement_tx], 2026, 3, [$cloud_card]);
    $blank_card_report = AVBK_Company_Report::build($unknown_card_path, $wpdb->companies[1], [$settlement_tx], 2026, 3, 'unknown.xlsx', [$cloud_card]);
    company_check($blank_card_report['totals']['unknown_rows'] === 1, 'card without matching historical evidence stays unknown');
    $evidence = [];
    foreach (['Google Cloud' => '21', 'Google One' => '21', 'Google Workspace' => '0', 'Microsoft' => '21', 'OpenAI ChatGPT Subscription' => '0', 'Claude.ai Subscription' => '0'] as $merchant => $code) {
        $evidence[] = ['merchant' => $merchant, 'code' => $code, 'date' => '2026-06-09', 'source' => 'Alpha Q2 supplied history'];
    }
    company_reject(fn() => AVBK_Vat_Patterns::record_card_history(1, $evidence), 'bookkeeper cannot change the company historical VAT evidence');
    $GLOBALS['test_user'] = 13;
    AVBK_Vat_Patterns::record_card_history(1, $evidence);
    AVBK_Vat_Patterns::record_card_history(1, $evidence);
    company_check(count(get_option('avbk_company_card_vat_history_1')) === 6, 'repeated company historical evidence does not duplicate records');
    company_reject(fn() => AVBK_Vat_Patterns::record_card_history(2, $evidence), 'CEO cannot write VAT evidence for another company');
    $card_history = AVBK_Vat_Patterns::history($source, $wpdb->companies[1], 2026, 3);
    foreach (['GOOGLE*CLOUD TESTREF CC GOOGLE.COM IRL' => '21', 'GOOGLE *GOOGLE ONE TEST IRL' => '21', 'Google Workspace_example Dublin IRL' => '0', 'MICROSOFT#G000001 MSBILL.INFO IRL' => '21', 'OPENAI *CHATGPT SUBSCR SAN FRANCISCO USA' => '0', 'ANTHROPIC* CLAUDE SUB DUBLIN IRL' => '0'] as $merchant => $code) {
        $merchant_tx = (object) ['counterparty_name' => $merchant, 'direction' => 'out'];
        $match = AVBK_Vat_Patterns::suggest_card($card_history, $merchant_tx);
        company_check($match['code'] === $code && $match['source'] === 'Alpha Q2 supplied history', 'card supplier history matches service and cites its source: ' . $merchant);
    }
    company_check(AVBK_Vat_Patterns::suggest_card($card_history, (object) ['counterparty_name' => 'Microsoft Azure', 'direction' => 'out'])['code'] === null, 'Microsoft billing history does not classify unrelated Microsoft services');
    company_check(AVBK_Vat_Patterns::suggest_card($card_history, (object) ['counterparty_name' => 'GOOGLE*CLOUD TESTREF', 'direction' => 'in'])['code'] === null, 'purchase evidence does not silently classify refunds');
    $future = [['merchant' => 'Microsoft', 'code' => '0', 'date' => '2026-07-15', 'source' => 'Later quarter evidence']];
    AVBK_Vat_Patterns::record_card_history(1, $future);
    $cutoff_history = AVBK_Vat_Patterns::history($source, $wpdb->companies[1], 2026, 3);
    company_check(AVBK_Vat_Patterns::suggest_card($cutoff_history, (object) ['counterparty_name' => 'Microsoft-G000002', 'direction' => 'out'])['code'] === '21', 'evidence after the selected quarter cutoff is excluded');
    $conflicting = [['merchant' => 'Microsoft', 'code' => '0', 'date' => '2026-06-08', 'source' => 'Conflicting earlier evidence']];
    AVBK_Vat_Patterns::record_card_history(1, $conflicting);
    company_check(AVBK_Vat_Patterns::suggest_card(AVBK_Vat_Patterns::history($source, $wpdb->companies[1], 2026, 3), (object) ['counterparty_name' => 'Microsoft-G000003', 'direction' => 'out'])['status'] === 'conflict', 'conflicting historical rates remain unresolved');
    $filled_card_path = $dir . '/filled-card-vat.xlsx';
    $refresh = AVBK_Company_Workbook::update($unknown_card_path, $filled_card_path, $wpdb->companies[1], [$settlement_tx], 2026, 3, [$cloud_card]);
    $filled_report = AVBK_Company_Report::build($filled_card_path, $wpdb->companies[1], [$settlement_tx], 2026, 3, 'filled.xlsx', [$cloud_card]);
    company_check($refresh['cards_added'] === 0 && $refresh['suggested'] === 1 && $filled_report['totals']['known_vat_cents'] === 2100 && $filled_report['totals']['known_net_cents'] === 10000 && $filled_report['totals']['unknown_rows'] === 0, 'new historical evidence fills existing unknown card rows without duplicating purchases');
    company_check($filled_report['totals']['confirmed_vat_cents'] === 0, 'historical card matches retain invoice review markers');
    $filled_again = $dir . '/filled-card-again.xlsx';
    company_check(AVBK_Company_Workbook::update($filled_card_path, $filled_again, $wpdb->companies[1], [$settlement_tx], 2026, 3, [$cloud_card])['suggested'] === 0, 'existing VAT annotations are preserved on a later update');
    AVBK_Vat_Patterns::record_card_classifications(1, 2026, 3, [['merchant' => 'Microsoft', 'code' => '0', 'source' => 'Explicit quarter instruction']]);
    $instructed = AVBK_Vat_Patterns::suggest_card(AVBK_Vat_Patterns::history($source, $wpdb->companies[1], 2026, 3), (object) ['counterparty_name' => 'Microsoft-G000004', 'direction' => 'out']);
    company_check($instructed['code'] === '0' && $instructed['status'] === 'instructed', 'explicit current-quarter classification takes precedence over historical evidence');
    company_check(AVBK_Vat_Patterns::suggest_card(AVBK_Vat_Patterns::history($source, $wpdb->companies[1], 2026, 4), (object) ['counterparty_name' => 'Microsoft-G000004', 'direction' => 'out'])['status'] !== 'instructed', 'current-quarter instructions do not leak into another quarter');
    AVBK_Vat_Patterns::record_card_classifications(1, 2026, 3, [['merchant' => 'Microsoft', 'code' => '21', 'source' => 'Updated quarter instruction']]);
    company_check(AVBK_Vat_Patterns::suggest_card(AVBK_Vat_Patterns::history($source, $wpdb->companies[1], 2026, 3), (object) ['counterparty_name' => 'Microsoft-G000004', 'direction' => 'out'])['code'] === '21', 'a later explicit quarter instruction replaces the earlier one');
    $zero_card = clone $cloud_card; $zero_card->description = 'Unclassified service'; $zero_card->dedupe_hash = hash('sha256', 'synthetic-zero-purchase');
    $zero_source = $dir . '/zero-source.xlsx'; $zero_output = $dir . '/zero-output.xlsx';
    AVBK_Company_Workbook::update($source, $zero_source, $wpdb->companies[1], [$settlement_tx], 2026, 3, [$zero_card]);
    AVBK_Vat_Patterns::record_card_classifications(1, 2026, 3, [['merchant' => 'Unclassified service', 'code' => '0', 'source' => 'User supplied zero treatment']]);
    AVBK_Company_Workbook::update($zero_source, $zero_output, $wpdb->companies[1], [$settlement_tx], 2026, 3, [$zero_card]);
    $zero_report = AVBK_Company_Report::build($zero_output, $wpdb->companies[1], [$settlement_tx], 2026, 3, 'zero.xlsx', [$zero_card]);
    $instructed_rows = array_filter($zero_report['rows'], fn($row) => $row['source'] === 'Creditcard' && $row['status'] === 'instructed');
    company_check(count($instructed_rows) === 1 && $zero_report['totals']['known_vat_cents'] === 0 && $zero_report['totals']['known_net_cents'] === 12100 && $zero_report['totals']['unknown_rows'] === 0, 'an explicit zero classification produces known zero VAT and the full net purchase amount');
    // Direct portal reviews persist the workbook, totals and an audit entry as one comparison-checked write.
    $GLOBALS['test_user'] = 11;
    $wpdb->transactions = [$supplier]; $wpdb->card_transactions = [];
    AVBK_Company_Report::save(AVBK_Company_Report::build($suggested_path, $wpdb->companies[1], [$supplier], 2026, 3, 'review.xlsx'));
    $before_review = AVBK_Company_Report::get(1, 2026, 3);
    $review_key = $before_review['rows'][0]['review_key'];
    company_check(str_starts_with($review_key, 'bank:'), 'imported bank row has a stable review identity');
    $review = fn($hash, $key, $code = '21', $fixed = '', $state = 'checked', $note = '') => AVBK_Company_Review::save(1, 2026, 3, $hash, $key, $code, $fixed, $state, $note);
    company_reject(fn() => $review($before_review['workbook_hash'], 'bank:' . str_repeat('0', 64)), 'forged transaction identities cannot edit workbook cells');
    company_reject(fn() => $review($before_review['workbook_hash'], $review_key, '', '', 'checked'), 'unknown VAT cannot be confirmed');
    $GLOBALS['test_user'] = 12;
    company_reject(fn() => $review($before_review['workbook_hash'], $review_key), 'accountant can read but cannot approve VAT');
    $GLOBALS['test_user'] = 14;
    company_reject(fn() => $review($before_review['workbook_hash'], $review_key), 'another company CEO cannot approve this company VAT');
    $GLOBALS['test_user'] = 11;
    $checked = $review($before_review['workbook_hash'], $review_key, '21', '', 'checked', '=Invoice TEST-1');
    company_check($checked['totals']['confirmed_vat_cents'] === 2100 && $checked['totals']['confirmed_net_cents'] === 10000 && $checked['totals']['pending_rows'] === 0, 'confirming bank VAT updates checked totals');
    company_check(count($checked['review_log']) === 1 && $checked['review_log'][0]['after']['by'] === 11 && $checked['review_log'][0]['before']['status'] === 'suggested', 'audit records the previous values and the reviewing user');
    company_reject(fn() => $review($before_review['workbook_hash'], $review_key, '0'), 'stale browser review cannot overwrite a newer workbook');
    $reopened = $review($checked['workbook_hash'], $review_key, '0', '', 'pending', 'Verify invoice');
    company_check($reopened['totals']['known_vat_cents'] === 0 && $reopened['totals']['known_net_cents'] === 12100 && $reopened['totals']['confirmed_net_cents'] === 0 && $reopened['totals']['pending_rows'] === 1, 'zero VAT is known and reopening removes it from checked totals');
    company_reject(fn() => $review($reopened['workbook_hash'], $review_key, 'x', '121.01'), 'fixed VAT cannot exceed the gross amount');
    company_reject(fn() => $review($reopened['workbook_hash'], $review_key, 'x', '-1'), 'fixed VAT sign is derived from the transaction');
    $fixed = $review($reopened['workbook_hash'], $review_key, 'x', '12,34');
    company_check($fixed['totals']['confirmed_vat_cents'] === 1234 && $fixed['totals']['confirmed_net_cents'] === 10866, 'fixed invoice VAT uses decimal cents and computes net');
    $wpdb->fail_compare = true;
    company_reject(fn() => $review($fixed['workbook_hash'], $review_key, '9'), 'concurrent report write rejects a stale review without partial persistence');
    company_check(AVBK_Company_Report::get(1, 2026, 3) === $fixed, 'failed comparison preserves the workbook and audit history together');
    $review_export = $dir . '/review-export.xlsx';
    AVBK_Company_Workbook::update($source, $review_export, $wpdb->companies[1], [$supplier], 2026, 3);
    $export_report = AVBK_Company_Report::build($review_export, $wpdb->companies[1], [$supplier], 2026, 3, 'export.xlsx');
    company_check($export_report['totals']['confirmed_vat_cents'] === 1234, 'saved portal decisions survive a fresh export from an old template');
    $original = new AVBK_Xlsx_Archive($source); $updated = new AVBK_Xlsx_Archive($review_export);
    company_check($original->getFromName('xl/worksheets/sheet2.xml') === $updated->getFromName('xl/worksheets/sheet2.xml'), 'review leaves the other company worksheet untouched');
    $original->close(); $updated->close();
    $wpdb->transactions[] = $extra_tx;
    company_reject(fn() => $review($fixed['workbook_hash'], $review_key), 'new bank imports block reviews until the workbook is refreshed');

    // Card settlement remains excluded; purchase/refund signs and the bank cash column stay intact.
    unset($GLOBALS['test_options']['avbk_company_report_1_2026_3']);
    $wpdb->transactions = [$settlement_tx]; $wpdb->card_transactions = $cards;
    AVBK_Company_Report::save($card_report);
    $card_edit = array_values(array_filter($card_report['rows'], fn($row) => $row['source'] === 'Creditcard' && $row['direction'] === 'out'))[0];
    company_check($card_report['rows'][0]['review_key'] === '' || $card_report['rows'][0]['source'] === 'Creditcard', 'card settlement has no purchase VAT review control');
    $card_checked = $review($card_report['workbook_hash'], $card_edit['review_key']);
    company_check($card_checked['totals']['confirmed_vat_cents'] === 2100 && $card_checked['totals']['gross_cents'] === 12100 && $card_checked['totals']['purchase_gross_cents'] === 22990, 'card review counts the purchase once and preserves bank cash');
    $refund = array_values(array_filter($card_checked['rows'], fn($row) => $row['source'] === 'Creditcard' && $row['direction'] === 'in'))[0];
    $refund_checked = $review($card_checked['workbook_hash'], $refund['review_key'], '21');
    $refund_row = array_values(array_filter($refund_checked['rows'], fn($row) => $row['review_key'] === $refund['review_key']))[0];
    company_check($refund_row['vat_cents'] < 0 && $refund_row['net_cents'] < 0 && $refund_row['status'] === 'checked', 'refund review produces signed VAT and net values');
    $bank_key = 'bank:' . AVBK_Company_Report::bank_key($settlement_tx->transaction_date, $settlement_tx->amount, $settlement_tx->direction, $settlement_tx->counterparty_iban, $settlement_tx->counterparty_name, $settlement_tx->description);
    company_reject(fn() => $review($refund_checked['workbook_hash'], $bank_key), 'forged settlement review cannot double-count card expenses');
    company_check(count($refund_checked['review_log']) === 2, 'repeated reviews append audit history');
    company_check($refund_checked['totals']['pending_rows'] === 1, 'settlement does not leave an unresolvable VAT review warning');
    company_reject(fn() => AVBK_Company_Report::save($refund_checked, null, true), 'first export cannot overwrite a concurrently created result');
    $admin = (new ReflectionClass(AVBK_Company_Admin::class))->newInstanceWithoutConstructor();
    $render_report = new ReflectionMethod(AVBK_Company_Admin::class, 'report_html');
    $response_html = $render_report->invoke($admin, $card_report, 'https://example.test/portal/');
    company_check(substr_count($response_html, 'class="avbk-company-quick-review"') === 2 && str_contains($response_html, 'name="_wpnonce"'), 'inline response renders quick confirmations with nonce-protected forms');
    company_check(str_contains($response_html, 'name="return_to" value="https://example.test/portal/"') && str_contains($response_html, 'value="' . $card_report['workbook_hash'] . '"'), 'inline response retains the frontend return address and current workbook version');
    $GLOBALS['test_user'] = 12;
    company_check(!str_contains($render_report->invoke($admin, $card_report, 'https://example.test/portal/'), 'class="avbk-company-quick-review"'), 'inline rendering does not grant edit controls to accountants');

    // Payroll evidence shares the private company document store, without changing VAT or bank records.
    $GLOBALS['test_user'] = 11;
    company_check(!AVBK_Company_Payroll::enabled(1), 'payroll is disabled by default for each company');
    company_reject(fn() => AVBK_Company_Payroll::configure(1, true), 'bookkeeper cannot change payroll feature access');
    $GLOBALS['test_user'] = 13;
    AVBK_Company_Payroll::configure(1, true);
    company_check(AVBK_Company_Payroll::enabled(1), 'company CEO can enable payroll for that company');
    company_reject(fn() => AVBK_Company_Payroll::configure(2, true), 'company CEO cannot enable payroll for another company');
    foreach (['2026-00', '2026-13', '2026-1', '2026-10-01', '', '1999-12'] as $bad_month) company_reject(fn() => AVBK_Company_Payroll::period($bad_month), 'payroll rejects an invalid document month: ' . $bad_month);
    $GLOBALS['test_user'] = 11;
    $payroll_snapshot = AVBK_Company_Report::get(1, 2026, 3);
    $payroll_pdf = base64_encode("%PDF-1.4\n% Synthetic payroll document A\n%%EOF\n");
    $tax_pdf = base64_encode("%PDF-1.4\n% Synthetic payroll document B\n%%EOF\n");
    $payslip = ['kind' => 'payslip', 'period' => '2026-10'];
    $payroll_tax = ['kind' => 'payroll_tax', 'period' => '2026-10'];
    $payslip_id = AVBK_Company_Documents::import_mail(1, 'October payslip', 'payslip.pdf', $payroll_pdf, $payslip);
    $payroll_tax_id = AVBK_Company_Documents::import_mail(1, 'October wage tax statement', 'payroll-tax.pdf', $tax_pdf, $payroll_tax);
    company_check(AVBK_Company_Documents::import_mail(1, 'Repeated payroll import', 'payslip.pdf', $payroll_pdf, $payslip) === $payslip_id, 'repeated payroll imports do not duplicate evidence');
    company_reject(fn() => AVBK_Company_Documents::import_mail(1, 'Wrong month', 'payslip.pdf', $payroll_pdf, ['kind' => 'payslip', 'period' => '2026-09']), 'duplicate bytes cannot silently move to a different payroll month');
    company_reject(fn() => AVBK_Company_Documents::import_mail(1, 'Wrong type', 'payslip.pdf', $payroll_pdf, $payroll_tax), 'duplicate bytes cannot silently change payroll document type');
    company_reject(fn() => AVBK_Company_Documents::import_mail(1, 'Unknown type', 'other.pdf', $payroll_pdf, ['kind' => 'other', 'period' => '2026-10']), 'unsupported payroll document types are rejected');
    company_reject(fn() => AVBK_Company_Documents::import_mail(1, 'Invalid bytes', 'other.pdf', base64_encode('text'), $payslip), 'payroll documents require validated PDF or image bytes');
    $payroll_doc = AVBK_Company_Documents::document(1, $payslip_id);
    company_check($payroll_doc->document_kind === 'payslip' && $payroll_doc->document_period === '2026-10-01' && base64_decode($payroll_doc->document_data) === base64_decode($payroll_pdf), 'payroll evidence stores its type, month and original bytes privately');
    $payroll_list = AVBK_Company_Payroll::documents(1, 2026);
    company_check(count($payroll_list) === 2 && !isset($payroll_list[0]->document_data), 'payroll listing contains metadata and keeps attachment bytes out of the page');
    company_check(AVBK_Company_Payroll::documents(1, 2025) === [], 'payroll evidence is filtered to the selected year');
    company_check(!in_array($payslip_id, array_map(fn($doc) => $doc->id, AVBK_Company_Documents::listing(1)), true), 'payslips do not appear among VAT invoices');
    company_reject(fn() => AVBK_Company_Documents::mark_paid(1, $payslip_id), 'a payroll document has no invoice payment status to mark paid');
    company_check(AVBK_Company_Report::get(1, 2026, 3) === $payroll_snapshot, 'adding payroll evidence leaves the VAT workbook and its totals unchanged');
    $form_method = new ReflectionMethod(AVBK_Company_Admin::class, 'form_renderer');
    $form_start = $form_method->invoke($admin, 1, 2026, 3, 'https://example.test/portal/');
    $company_id = 1; $company = $wpdb->companies[1]; $year = 2026;
    ob_start(); require AVBK_PLUGIN_DIR . 'admin/company-payroll.php'; $payroll_html = ob_get_clean();
    company_check(str_contains($payroll_html, 'name="payroll_documents[]"') && str_contains($payroll_html, 'multiple required') && str_contains($payroll_html, '2026-10'), 'payroll UI supports multiple files and displays document month');
    $GLOBALS['test_user'] = 12;
    company_reject(fn() => AVBK_Company_Documents::import_mail(1, 'Read-only upload', 'payslip.pdf', $payroll_pdf, $payslip), 'accountants cannot upload payroll documents');
    ob_start(); require AVBK_PLUGIN_DIR . 'admin/company-payroll.php'; $readonly_payroll = ob_get_clean();
    company_check(str_contains($readonly_payroll, 'payslip.pdf') && !str_contains($readonly_payroll, '<form'), 'accountants can see private payroll download links without upload controls');
    $GLOBALS['test_user'] = 14;
    company_check(!AVBK_Company_Payroll::enabled(2), 'enabling payroll does not enable it for another company');
    company_reject(fn() => AVBK_Company_Documents::import_mail(2, 'Disabled payroll', 'payslip.pdf', $payroll_pdf, $payslip), 'disabled company rejects payroll uploads');
    company_reject(fn() => AVBK_Company_Payroll::documents(1, 2026), 'another company cannot list payroll documents');
    company_reject(fn() => AVBK_Company_Documents::document(2, $payslip_id), 'changing the company ID cannot download another company payslip');
    $GLOBALS['test_user'] = 11;
    $qr_entries = [['kind' => 'payroll_tax', 'document_id' => $payroll_tax_id, 'iban' => 'NL91ABNA0417164300', 'name' => 'Voorbeeld ontvanger', 'reference' => '0123456789012345', 'amount_cents' => 4300, 'due_date' => '2026-11-30', 'state' => 'pending', 'note' => 'Synthetic tax statement'],
        ['kind' => 'payslip', 'document_id' => $payslip_id, 'iban' => 'NL91ABNA0417164300', 'name' => 'Anna', 'reference' => 'Salaris oktober 2026', 'amount_cents' => 85635, 'state' => 'review', 'note' => 'Prior salary transfers need reconciliation']];
    AVBK_Company_Payroll::save_payments(1, '2026-10', $qr_entries);
    $prepared = AVBK_Company_Payroll::payments(1, 2026)['2026-10'];
    $payload = AVBK_Company_Payroll::payload($prepared[0]);
    company_check(str_contains($payload, "\nEUR43.00\n") && str_contains($payload, "\n0123456789012345\n"), 'tax QR preserves the exact amount and leading zero in the payment reference');
    company_check(AVBK_Company_Payroll::payload($prepared[1]) === null, 'salary held for reconciliation cannot produce a duplicate-payment QR');
    foreach (['iban' => 'NL00ABNA0417164300', 'reference' => '012345678901234', 'amount_cents' => 0, 'name' => "Receiver\nInjected", 'document_id' => $payslip_id] as $field => $bad) {
        $invalid = $qr_entries; $invalid[0][$field] = $bad;
        company_reject(fn() => AVBK_Company_Payroll::save_payments(1, '2026-10', $invalid), 'QR preparation rejects invalid payment ' . $field);
    }
    company_reject(fn() => AVBK_Company_Payroll::save_payments(1, '2026-09', $qr_entries), 'QR source document must belong to the selected payroll month');
    $GLOBALS['test_user'] = 12;
    company_reject(fn() => AVBK_Company_Payroll::save_payments(1, '2026-10', $qr_entries), 'accountant cannot alter payment instructions');
    company_check(count(AVBK_Company_Payroll::payments(1, 2026)['2026-10']) === 2, 'accountant can review prepared payment details');
    $GLOBALS['test_user'] = 14;
    company_reject(fn() => AVBK_Company_Payroll::payments(1, 2026), 'another company cannot read payroll payment instructions');
    $GLOBALS['test_user'] = 13;
    $feature_snapshot = AVBK_Company_Report::get(1, 2026, 3);
    AVBK_Company_Features::save(1, ['creditcard' => false, 'payroll' => true, 'loans' => true]);
    company_check(!AVBK_Company_Features::enabled(1, 'creditcard') && AVBK_Company_Payroll::enabled(1) && AVBK_Company_Features::enabled(1, 'loans'), 'creditcard, payroll and loan applicability are independent company settings');
    company_reject(fn() => AVBK_Company_Cards::link(1, '12345678901'), 'disabled creditcard setting blocks linking');
    company_reject(fn() => AVBK_Company_Cards::import(1, $card_csv, 'cards.csv', 2026, 3), 'disabled creditcard setting blocks transaction imports');
    company_check(count(AVBK_Company_Cards::transactions(1, 2026, 3)) === 4 && AVBK_Company_Report::get(1, 2026, 3) === $feature_snapshot, 'disabling a workflow preserves existing card records and VAT calculations');
    company_reject(fn() => AVBK_Company_Features::save(2, ['creditcard' => false, 'payroll' => false, 'loans' => true]), 'feature settings cannot be changed across company boundaries');
    $GLOBALS['test_user'] = 11;
    company_reject(fn() => AVBK_Company_Features::save(1, ['creditcard' => true, 'payroll' => true, 'loans' => false]), 'bookkeepers cannot change company features');
    company_reject(fn() => AVBK_Company_Payroll_Automation::configure(1, 'owner@example.test'), 'bookkeeper cannot choose a reminder recipient');
    $GLOBALS['test_user'] = 13;
    company_reject(fn() => AVBK_Company_Payroll_Automation::configure(1, 'invalid'), 'monthly reminder requires a valid recipient');
    AVBK_Company_Payroll_Automation::configure(1, 'owner@example.test', 1, 0);
    $tax_payment = clone $supplier; $tax_payment->amount = '43.00'; $tax_payment->description = 'Payment 0123 4567 8901 2345'; $tax_payment->counterparty_iban = 'NL91ABNA0417164300'; $tax_payment->transaction_date = '2026-11-02';
    $wpdb->transactions[] = $tax_payment;
    company_check(AVBK_Company_Payroll_Automation::paid(1, $prepared[0]) !== null, 'reminder matches the exact amount, recipient account and complete tax reference');
    $wrong_ref = $prepared[0]; $wrong_ref['reference'] = '0123456789012346';
    company_check(AVBK_Company_Payroll_Automation::paid(1, $wrong_ref) === null, 'same amount with another tax reference remains unpaid');
    $digest = AVBK_Company_Payroll_Automation::reminder(1, '2026-11-27');
    $digest_html = AVBK_Company_Payroll_Automation::html($digest);
    company_check(count($digest['holds']) === 1 && !str_contains($digest_html, 'src="cid:'), 'paid tax and salary on hold do not appear as payment QRs in the reminder');
    $first_send = AVBK_Company_Payroll_Automation::send(1);
    company_check($first_send === 'sent' && count($GLOBALS['test_mails']) === 1 && $GLOBALS['test_mails'][0]['recipient'] === 'owner@example.test', 'scheduled reminder uses the configured recipient');
    company_check(AVBK_Company_Payroll_Automation::send(1) === 'already_sent' && count($GLOBALS['test_mails']) === 1, 'repeated monthly runs do not send duplicate reminders');

} finally {
    foreach (glob($dir . '/*') as $file) unlink($file);
    rmdir($dir);
}
