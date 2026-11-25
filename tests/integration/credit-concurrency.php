<?php
/**
 * Real InnoDB credit-settlement regression for a disposable local database.
 * Run setup, then two worker processes concurrently, then verify.
 * Requires mysqli, MYSQL_HOST, and database avbk_security_test on that host.
 */
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__, 2) . '/includes/class-db.php';
function get_option($key, $default = false) { return $default; }
function wp_list_pluck($rows, $field) { return array_map(fn($row) => $row->$field, $rows); }
class AVPVH_DB {
    public static function get_extended_household(int $id): array { return []; }
}
class Credit_Security_DB {
    public string $prefix = 'test_';
    public int $insert_id = 0;
    public mysqli $connection;
    public function __construct() {
        $this->connection = new mysqli(getenv('MYSQL_HOST'), 'root', '', 'avbk_security_test', 3306);
    }
    public function prepare(string $sql, ...$args): string {
        $index = 0;
        return preg_replace_callback('/%[dsf]/', function ($m) use ($args, &$index) {
            $arg = $args[$index++];
            return $m[0] === '%d' ? (string) (int) $arg : "'" . $this->connection->real_escape_string((string) $arg) . "'";
        }, $sql);
    }
    public function query(string $sql): int|false {
        $result = $this->connection->query($sql);
        return $result === false ? false : ($result instanceof mysqli_result ? $result->num_rows : $this->connection->affected_rows);
    }
    public function get_results(string $sql): array {
        $result = $this->connection->query($sql)->fetch_all(MYSQLI_ASSOC);
        // Make the unsafe implementation reliably overlap its availability reads.
        if (str_contains($sql, 'FROM test_avb_transactions t')) usleep(400000);
        return array_map(fn($row) => (object) $row, $result);
    }
    public function get_row(string $sql): ?object { return $this->connection->query($sql)->fetch_object() ?: null; }
    public function get_var(string $sql) { return $this->connection->query($sql)->fetch_row()[0] ?? null; }
    public function get_col(string $sql): array { return array_column($this->connection->query($sql)->fetch_all(), 0); }
    public function insert(string $table, array $data): int|false {
        $values = implode(',', array_map(fn($v) => "'" . $this->connection->real_escape_string((string) $v) . "'", $data));
        $result = $this->query('INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . $values . ')');
        $this->insert_id = $this->connection->insert_id;
        return $result;
    }
}
$wpdb = new Credit_Security_DB();
$mode = $argv[1] ?? '';
$scenario = $argv[2] ?? 'source';
if ($mode === 'setup') {
    foreach (['transactions', 'fee_items', 'transaction_allocations', 'known_ibans', 'refunds'] as $table) $wpdb->query("DROP TABLE IF EXISTS test_avb_$table");
    $wpdb->query("CREATE TABLE test_avb_transactions (id INT PRIMARY KEY, transaction_date DATE, amount DECIMAL(8,2), description TEXT, counterparty_name TEXT, counterparty_iban VARCHAR(34), direction VARCHAR(10), status VARCHAR(20)) ENGINE=InnoDB");
    $wpdb->query("CREATE TABLE test_avb_fee_items (id INT PRIMARY KEY, member_id INT, status VARCHAR(20), amount_due DECIMAL(8,2)) ENGINE=InnoDB");
    $wpdb->query("CREATE TABLE test_avb_transaction_allocations (id INT AUTO_INCREMENT PRIMARY KEY, transaction_id INT, fee_item_id INT, member_id INT, amount DECIMAL(8,2)) ENGINE=InnoDB");
    $wpdb->query("CREATE TABLE test_avb_known_ibans (iban VARCHAR(34), member_id INT, confirmed INT) ENGINE=InnoDB");
    $wpdb->query("CREATE TABLE test_avb_refunds (credit_transaction_id INT, amount DECIMAL(8,2)) ENGINE=InnoDB");
    $wpdb->query("INSERT INTO test_avb_transactions VALUES (1, '2026-01-01', 100, '', '', 'SYNTHETIC-IBAN', 'in', 'matched'), (2, '2026-01-02', 100, '', '', 'SYNTHETIC-IBAN', 'in', 'matched')");
    $wpdb->query("INSERT INTO test_avb_known_ibans VALUES ('SYNTHETIC-IBAN', 1, 1), ('SYNTHETIC-IBAN', 2, 1)");
    $wpdb->query("INSERT INTO test_avb_fee_items VALUES (10, 1, 'open', 100), (11, 2, 'open', 100)");
} elseif ($mode === 'worker') {
    $second = ($argv[3] ?? '') === '2';
    $transaction = $scenario === 'target' && $second ? 2 : 1;
    $fee = $scenario !== 'target' && $second ? 11 : 10;
    $member = $scenario !== 'target' && $second ? 2 : 1;
    $nested = $scenario === 'snapshot';
    if ($nested) {
        $wpdb->query('START TRANSACTION');
        AVBK_DB::get_total_credit_for_member($member);
        usleep(500000);
    }
    $id = AVBK_DB::apply_credit($transaction, $fee, $member, 75.0, $nested);
    if ($nested) $wpdb->query('COMMIT');
    echo $id ? "allocated\n" : "refused\n";
} elseif ($mode === 'preview') {
    $wpdb->query('START TRANSACTION');
    AVBK_DB::get_total_credit_for_member(1);
    if (!AVBK_DB::apply_credit(1, 10, 1, 25.0, true)) throw new RuntimeException('Preview did not settle');
    $wpdb->query('ROLLBACK');
    if ((int) $wpdb->get_var('SELECT COUNT(*) FROM test_avb_transaction_allocations') !== 0) throw new RuntimeException('Preview persisted a settlement');
    echo "PASS: outer transaction can roll back preview settlement\n";
} elseif ($mode === 'verify') {
    $sum = (float) $wpdb->get_var('SELECT COALESCE(SUM(amount), 0) FROM test_avb_transaction_allocations');
    if ($sum !== 75.0) throw new RuntimeException('FAIL: overlapping credit requests allocated ' . $sum);
    echo "PASS: $scenario locks prevent concurrent over-allocation\n";
} else {
    throw new RuntimeException('Expected setup, worker or verify');
}
