<?php
/**
 * An import explains the bank's invoice for the club's own account as
 * Bankkosten straight away; other outgoing payments stay to be explained.
 * Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
function get_option($name, $default = false) { return ['avbk_club_iban' => 'NL00TEST0123456789'][$name] ?? $default; }
function avbk_log_failure(string $where, string $reason, array $ids = []): void {}
final class AVBK_Bank_Import_Layout { public static function resolve(): array { return ['csv_delimiter' => ';']; } }
final class AVBK_Csv_Reader { public static function read(string $path, string $delimiter): array { return ['rows' => [['a'], ['b']]]; } }
final class AVBK_Matcher {
    public static function parse_row(array $row, array $layout): ?array {
        $out = ['transaction_date' => '2026-03-27', 'amount' => 24.81, 'direction' => 'out', 'counterparty_iban' => ''];
        return $row === ['a']
            ? $out + ['counterparty_name' => 'Kosten Zakelijk Betalingsverkeer', 'description' => 'Factuurnr. 1 Betreft IBAN: NL00TEST0123456789 Periode: 01-02-2026']
            : $out + ['counterparty_name' => 'Drukkerij', 'description' => 'Factuur 9'];
    }
}
final class AVBK_DB {
    public static array $transactions = [];
    public static array $expenses = [];
    public static function create_import_batch(string $filename, int $uploaded_by): int { return 1; }
    public static function dedupe_hash(...$parts): string { return md5(serialize($parts)); }
    public static function transaction_exists(string $hash): bool { return false; }
    public static function find_semantic_duplicate(array $tx): ?object { return null; }
    public static function insert_transaction(array $tx): int {
        $id = count(self::$transactions) + 1;
        self::$transactions[$id] = (object) ($tx + ['id' => $id, 'duplicate_of' => null]);
        return $id;
    }
    public static function update_import_batch_counts(int $batch, int $rows, int $matched): void {}
    public static function get_unexplained_outgoing(int $min_year = 0): array {
        return array_values(array_filter(self::$transactions, static fn($tx) => !isset(self::$expenses[$tx->id])));
    }
    public static function insert_expense(array $data): int {
        self::$expenses[$data['transaction_id']] = $data;
        return count(self::$expenses);
    }
}
require dirname(__DIR__) . '/includes/class-import.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

$result = AVBK_Import::process_file('/tmp/export.csv', 'export.csv', 1);
check($result['row_count'] === 2, 'Both outgoing payments are imported');
check(AVBK_DB::$transactions[1]->status === 'ignored' && AVBK_DB::$transactions[1]->ignore_reason === 'import_outgoing', 'Kept as outgoing');
check(array_keys(AVBK_DB::$expenses) === [1] && AVBK_DB::$expenses[1]['kind'] === 'bank_costs' && AVBK_DB::$expenses[1]['category'] === 'Bankkosten', 'The bank costs are explained right away');
check(!isset(AVBK_DB::$expenses[2]), 'The other payment stays to be explained');

exit($failures ? 1 : 0);
