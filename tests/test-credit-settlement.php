<?php
/** Credit settlement validates both balances within a database transaction. */
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/includes/class-db.php';
function get_option($key, $default = false) { return $default; }
function wp_list_pluck($rows, $key) { return array_map(fn($row) => $row->$key, $rows); }
class AVPVH_DB {
    public static function get_extended_household(int $id): array { return [(object) ['id' => 2]]; }
}
class Credit_Test_DB {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public float $available = 100;
    public float $paid = 0;
    public ?object $item;
    public array $queries = [];
    public string $fail = '';
    public string $last_error = '';
    public bool $source_allowed = true;
    private array $snapshot = [];
    private array $savepoint = [];
    public function __construct() { $this->item = (object) ['id' => 10, 'member_id' => 1, 'status' => 'open', 'amount_due' => 100]; }
    public function prepare($sql, ...$args): string {
        foreach ($args as $arg) $sql = preg_replace('/%[dsf]/', is_numeric($arg) ? (string) $arg : "'" . $arg . "'", $sql, 1);
        return $sql;
    }
    public function query($sql): int|false {
        $this->queries[] = $sql;
        if ($this->fail !== '' && str_contains($sql, $this->fail)) return false;
        if ($sql === 'START TRANSACTION') $this->snapshot = [$this->available, $this->paid, $this->insert_id];
        if ($sql === 'SAVEPOINT avbk_apply_credit') $this->savepoint = [$this->available, $this->paid, $this->insert_id];
        if ($sql === 'ROLLBACK TO SAVEPOINT avbk_apply_credit') [$this->available, $this->paid, $this->insert_id] = $this->savepoint;
        if ($sql === 'ROLLBACK') [$this->available, $this->paid, $this->insert_id] = $this->snapshot;
        return 1;
    }
    public function get_results($sql): array {
        return $this->available > 0 ? [(object) ['id' => 1, 'credit' => $this->available, 'amount' => 100, 'allocated_total' => 100 - $this->available, 'refunded_total' => 0, 'counterparty_iban' => 'SYNTHETIC']] : [];
    }
    public function get_col($sql): array {
        if (str_contains($sql, 'avb_refunds')) return [];
        if (str_contains($sql, 'WHERE transaction_id')) return [100 - $this->available];
        if (str_contains($sql, 'WHERE fee_item_id')) return [$this->paid];
        return [1];
    }
    public function get_row($sql): ?object {
        if (str_contains($sql, 'avb_transactions')) return $this->source_allowed ? (object) ['amount' => 100] : null;
        return $this->item ? clone $this->item : null;
    }
    public function get_var($sql) { return $this->paid; }
    public function insert($table, $data): int|false {
        if ($this->fail === 'insert') return false;
        $this->available -= $data['amount'];
        $this->paid += $data['amount'];
        $this->insert_id++;
        return 1;
    }
}
function credit_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . "\n";
}
$wpdb = new Credit_Test_DB();
credit_check(AVBK_DB::apply_credit(1, 10, 1, 0) === 0 && !$wpdb->queries, 'Nonpositive credit does not start a write');
credit_check(AVBK_DB::apply_credit(1, 10, 1, 60) === 1 && $wpdb->available === 40.0 && $wpdb->paid === 60.0, 'Credit settles once and reduces both sides');
credit_check(AVBK_DB::apply_credit(1, 10, 1, 60) === 0, 'Repeated request cannot reuse the original balance');
credit_check(str_contains($wpdb->queries[1], 'avb_transactions WHERE id = 1 FOR UPDATE') && str_contains($wpdb->queries[2], 'avb_fee_items WHERE id = 10 FOR UPDATE'), 'Source and target are locked before settlement');
$wpdb = new Credit_Test_DB();
$wpdb->item->member_id = 2;
credit_check(AVBK_DB::apply_credit_fifo(1, 10, 40) === 40.0, 'FIFO can settle an item belonging to a housemate');
foreach (['outside', 'closed', 'missing', 'paid', 'no_credit'] as $case) {
    $wpdb = new Credit_Test_DB();
    if ($case === 'outside') $wpdb->item->member_id = 99;
    if ($case === 'closed') $wpdb->item->status = 'waived';
    if ($case === 'missing') $wpdb->item = null;
    if ($case === 'paid') $wpdb->paid = 100;
    if ($case === 'no_credit') $wpdb->available = 0;
    credit_check(AVBK_DB::apply_credit(1, 10, 1, 10) === 0 && $wpdb->insert_id === 0, 'Ineligible source/target leaves allocations unchanged: ' . $case);
}
foreach (['START TRANSACTION', 'FOR UPDATE', 'insert', 'COMMIT'] as $failure) {
    $wpdb = new Credit_Test_DB();
    $wpdb->fail = $failure;
    try { $result = AVBK_DB::apply_credit(1, 10, 1, 20); }
    catch (RuntimeException $e) { $result = 0; }
    credit_check($result === 0 && $wpdb->paid === 0.0 && $wpdb->available === 100.0, 'Database failure leaves no settlement: ' . $failure);
    if ($failure !== 'START TRANSACTION') credit_check(end($wpdb->queries) === 'ROLLBACK', 'Failed write transaction rolls back');
}

$wpdb = new Credit_Test_DB();
$wpdb->query('START TRANSACTION');
credit_check(AVBK_DB::apply_credit(1, 10, 1, 30, true) > 0, 'Preview can settle inside an existing transaction');
credit_check(!in_array('COMMIT', $wpdb->queries, true), 'Settlement does not commit the caller transaction');
$wpdb->query('ROLLBACK');
credit_check($wpdb->paid === 0.0 && $wpdb->available === 100.0, 'Rolling back the preview restores its credit');
$wpdb = new Credit_Test_DB();
$wpdb->source_allowed = false;
credit_check(AVBK_DB::apply_credit(1, 10, 1, 10) === 0, 'Unrecognized source cannot provide credit');
$wpdb = new Credit_Test_DB();
$wpdb->last_error = 'synthetic read failure';
try { AVBK_DB::apply_credit(1, 10, 1, 10); credit_check(false, 'Expected a read failure'); }
catch (RuntimeException $e) { credit_check($wpdb->paid === 0.0, 'Failed current-balance read leaves credit untouched'); }
$wpdb = new Credit_Test_DB();
$wpdb->query('START TRANSACTION');
$wpdb->fail = 'insert';
try { AVBK_DB::apply_credit(1, 10, 1, 10, true); } catch (RuntimeException $e) {}
credit_check($wpdb->paid === 0.0 && end($wpdb->queries) === 'ROLLBACK TO SAVEPOINT avbk_apply_credit', 'Nested failure rolls back to its own savepoint');
