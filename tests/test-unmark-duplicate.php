<?php
/** AVBK_DB::unmark_transaction_duplicate(): undoing a "dubbele betaling" mark. Fictional data only. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');

class AVBK_Audit {
    public static array $log = [];
    public static function log(string $action, string $type, int $id, array $details = [], ?int $member_id = null): void {
        self::$log[] = [$action, $id, $details];
    }
}

class Fake_Duplicate_WPDB {
    public string $prefix = 'wp_';
    public array $transactions = [];

    public function prepare(string $query, ...$args): string {
        return vsprintf(str_replace(['%d', '%s'], '%s', $query), $args);
    }
    public function get_row(string $query) {
        return preg_match('/id = (\d+)/', $query, $m) && isset($this->transactions[(int) $m[1]])
            ? (object) $this->transactions[(int) $m[1]] : null;
    }
    public function update(string $table, array $data, array $where) {
        $row = $this->transactions[(int) $where['id']] ?? null;
        if (!$row || $row['status'] !== $where['status']) {
            return 0;
        }
        $this->transactions[(int) $where['id']] = $data + $row;
        return 1;
    }
}

$GLOBALS['wpdb'] = new Fake_Duplicate_WPDB();
require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

$tx = fn(int $id, string $direction, string $status, ?int $duplicate_of, string $suggested = '') =>
    ['id' => $id, 'direction' => $direction, 'status' => $status, 'duplicate_of' => $duplicate_of, 'suggested_member_ids' => $suggested];
$wpdb->transactions = [
    1 => $tx(1, 'in', 'duplicate', 9, '3'),
    2 => $tx(2, 'in', 'duplicate', 9),
    3 => $tx(3, 'in', 'matched', null),
    4 => $tx(4, 'out', 'duplicate', 8),
];

check(AVBK_DB::unmark_transaction_duplicate(1) === null, 'Incoming duplicate can be undone');
check($wpdb->transactions[1]['status'] === 'suggested' && $wpdb->transactions[1]['duplicate_of'] === null, 'With member suggestions it returns as suggested, unlinked');
check(AVBK_Audit::$log === [['transaction.unduplicated', 1, ['status' => ['duplicate', 'suggested'], 'duplicate_of' => 9]]], 'The undo is in the logboek');
check(AVBK_DB::unmark_transaction_duplicate(2) === null && $wpdb->transactions[2]['status'] === 'unmatched', 'Without suggestions it returns as unmatched');
check(AVBK_DB::unmark_transaction_duplicate(1) === 'not_duplicate', 'Undoing twice is refused');
check(AVBK_DB::unmark_transaction_duplicate(3) === 'not_duplicate', 'A matched payment is no duplicate');
check(AVBK_DB::unmark_transaction_duplicate(4) === 'not_duplicate', 'An outgoing import copy stays a duplicate');
check(AVBK_DB::unmark_transaction_duplicate(99) === 'no_transaction', 'Unknown transaction');
check(AVBK_DB::unmark_transaction_duplicate(0) === 'no_transaction', 'No transaction id');
check(count(AVBK_Audit::$log) === 2, 'Refusals write nothing to the logboek');

exit($failures ? 1 : 0);
