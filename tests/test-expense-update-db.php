<?php
/** AVBK_DB::update_expense(): changing an explanation takes back its Tweede controle. Fictional data only. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');

class AVBK_Audit {
    public static array $log = [];
    public static function log(string $action, string $type, int $id, array $details = [], ?int $member_id = null): void {
        self::$log[] = [$action, $id, $details];
    }
}

class Fake_Expense_WPDB {
    public string $prefix = 'wp_';
    public array $expenses = [5 => ['id' => 5, 'transaction_id' => 40, 'kind' => 'other', 'category' => 'Accommodatie', 'note' => 'x']];
    public array $transactions = [40 => ['id' => 40, 'second_approved_by' => 2, 'second_approved_at' => '2026-03-01 10:00:00']];
    public bool $fail = false;

    public function prepare(string $query, ...$args): string {
        return vsprintf(str_replace(['%d', '%s'], '%s', $query), $args);
    }
    public function get_row(string $query) {
        return preg_match('/avb_expenses WHERE id = (\d+)/', $query, $m) && isset($this->expenses[(int) $m[1]]) ? (object) $this->expenses[(int) $m[1]] : null;
    }
    public function update(string $table, array $data, array $where) {
        if ($this->fail) {
            return false;
        }
        $rows = str_ends_with($table, 'avb_expenses') ? 'expenses' : 'transactions';
        $this->$rows[(int) $where['id']] = $data + $this->$rows[(int) $where['id']];
        return 1;
    }
}

$GLOBALS['wpdb'] = new Fake_Expense_WPDB();
require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

check(AVBK_DB::update_expense(5, ['category' => 'Catering', 'transaction_id' => 99]), 'The change is saved');
check($wpdb->expenses[5]['category'] === 'Catering' && $wpdb->expenses[5]['transaction_id'] === 40, 'Only the explanation fields change');
check($wpdb->transactions[40]['second_approved_by'] === null && $wpdb->transactions[40]['second_approved_at'] === null, 'Tweede controle is taken back');
check(AVBK_Audit::$log === [['expense.updated', 40, ['expense_id' => 5, 'fields' => ['category']]]], 'The change is in the logboek');
check(!AVBK_DB::update_expense(6, ['category' => 'Catering']), 'An unknown expense');
check(!AVBK_DB::update_expense(5, ['transaction_id' => 99]), 'Nothing to change');
$wpdb->fail = true;
check(!AVBK_DB::update_expense(5, ['category' => 'EHBO']) && count(AVBK_Audit::$log) === 1, 'A failed update is not logged as done');

exit($failures ? 1 : 0);
