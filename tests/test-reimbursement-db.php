<?php
/** Tests the real AVBK_DB reimbursement draft, pending-edit, duplicate, and migration methods against an in-memory $wpdb. Fictional data only. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');

$GLOBALS['options'] = ['avbk_db_version' => '1.57'];
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value) { $GLOBALS['options'][$name] = $value; return true; }
function current_time($format) { return '2026-11-27 12:00:00'; }
function wp_list_pluck($list, $field) { return array_map(static fn($item) => is_object($item) ? ($item->$field ?? null) : ($item[$field] ?? null), $list); }

final class Test_Reimbursement_Wpdb {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public array $reimbursements = [];
    public array $receipts = [];
    public string $status_enum = "enum('pending','paid','rejected','withdrawn')";

    public function prepare(string $sql, ...$args): array {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        return [$sql, $args];
    }

    public function query($sql): int|bool {
        $text = is_array($sql) ? $sql[0] : (string) $sql;
        if (str_contains($text, "MODIFY COLUMN status ENUM('draft'")) {
            $this->status_enum = "enum('draft','pending','paid','rejected','withdrawn')";
            return 1;
        }
        return 1;
    }

    public function insert(string $table, array $data): int {
        $id = ++$this->insert_id;
        if (str_ends_with($table, 'avb_reimbursements')) {
            $this->reimbursements[$id] = (object) (['id' => $id, 'receipt_path' => '', 'receipt_hash' => '', 'ocr_date' => null, 'ocr_store' => '', 'created_at' => '2026-11-27 10:00:00'] + $data);
        } elseif (str_ends_with($table, 'avb_reimbursement_receipts')) {
            $this->receipts[$id] = (object) (['id' => $id] + $data);
        }
        return 1;
    }

    public function update(string $table, array $data, array $where): int {
        $store = str_ends_with($table, 'avb_reimbursements') ? 'reimbursements' : 'receipts';
        $count = 0;
        foreach ($this->$store as $row) {
            if ($this->matches($row, $where)) {
                foreach ($data as $k => $v) {
                    $row->$k = $v;
                }
                $count++;
            }
        }
        return $count;
    }

    public function delete(string $table, array $where): int {
        $store = str_ends_with($table, 'avb_reimbursements') ? 'reimbursements' : 'receipts';
        $count = 0;
        foreach ($this->$store as $id => $row) {
            if ($this->matches($row, $where)) {
                unset($this->{$store}[$id]);
                $count++;
            }
        }
        return $count;
    }

    public function get_row($query): ?object {
        if (!is_array($query)) {
            if (str_contains((string) $query, "SHOW COLUMNS") && str_contains((string) $query, "LIKE 'status'")) {
                return (object) ['Field' => 'status', 'Type' => $this->status_enum];
            }
            return null;
        }
        [$sql, $args] = $query;
        if (str_contains($sql, 'FROM wp_avb_reimbursements WHERE id = %d')) {
            return $this->reimbursements[(int) $args[0]] ?? null;
        }
        if (str_contains($sql, "WHERE member_id = %d AND status = 'draft'")) {
            $found = array_values(array_filter($this->reimbursements, static fn($r) => (int) $r->member_id === (int) $args[0] && $r->status === 'draft'));
            usort($found, static fn($a, $b) => $b->id <=> $a->id);
            return $found[0] ?? null;
        }
        if (str_contains($sql, 'WHERE r.id = %d AND rr.id = %d AND r.member_id = %d AND r.status = %s')) {
            $r = $this->reimbursements[(int) $args[0]] ?? null;
            $rr = $this->receipts[(int) $args[1]] ?? null;
            return ($r && $rr && (int) $rr->reimbursement_id === (int) $args[0] && (int) $r->member_id === (int) $args[2] && $r->status === (string) $args[3]) ? $rr : null;
        }
        if (str_contains($sql, 'rr.receipt_hash = %s')) {
            $ignore_drafts = str_contains($sql, "NOT IN ('withdrawn', 'draft')");
            foreach ($this->receipts as $rr) {
                $r = $this->reimbursements[(int) $rr->reimbursement_id] ?? null;
                if ($r && $this->statusAllowed($r->status, $ignore_drafts) && (int) $r->member_id === (int) $args[0] && $rr->receipt_hash === (string) $args[1] && (int) $rr->id !== (int) $args[2]) {
                    return $rr;
                }
            }
            return null;
        }
        if (str_contains($sql, 'FROM wp_avb_reimbursements WHERE member_id = %d AND receipt_hash = %s')) {
            $ignore_drafts = str_contains($sql, "NOT IN ('withdrawn', 'draft')");
            foreach ($this->reimbursements as $r) {
                if ($this->statusAllowed($r->status, $ignore_drafts) && (int) $r->member_id === (int) $args[0] && $r->receipt_hash === (string) $args[1]) {
                    return $r;
                }
            }
            return null;
        }
        if (str_contains($sql, 'rr.ocr_date = %s')) {
            $ignore_drafts = str_contains($sql, "NOT IN ('withdrawn', 'draft')");
            foreach ($this->receipts as $rr) {
                $r = $this->reimbursements[(int) $rr->reimbursement_id] ?? null;
                if ($r && $this->statusAllowed($r->status, $ignore_drafts) && (int) $r->member_id === (int) $args[0] && $rr->ocr_date === (string) $args[1] && strtolower(trim((string) $rr->ocr_store)) === strtolower(trim((string) $args[2])) && (int) $rr->id !== (int) $args[3]) {
                    return $rr;
                }
            }
            return null;
        }
        if (str_contains($sql, 'ocr_date = %s AND LOWER(TRIM(ocr_store))')) {
            $ignore_drafts = str_contains($sql, "NOT IN ('withdrawn', 'draft')");
            foreach ($this->reimbursements as $r) {
                if ($this->statusAllowed($r->status, $ignore_drafts) && (int) $r->member_id === (int) $args[0] && $r->ocr_date === (string) $args[1] && strtolower(trim((string) $r->ocr_store)) === strtolower(trim((string) $args[2]))) {
                    return $r;
                }
            }
            return null;
        }
        return null;
    }

    public function get_results($query): array {
        [$sql, $args] = $query;
        if (str_contains($sql, 'FROM wp_avb_reimbursement_receipts WHERE reimbursement_id = %d')) {
            return array_values(array_filter($this->receipts, static fn($rr) => (int) $rr->reimbursement_id === (int) $args[0]));
        }
        if (str_contains($sql, "WHERE member_id = %d AND status != 'draft'")) {
            return array_values(array_filter($this->reimbursements, static fn($r) => (int) $r->member_id === (int) $args[0] && $r->status !== 'draft'));
        }
        return [];
    }

    private function matches(object $row, array $where): bool {
        foreach ($where as $k => $v) {
            if (!isset($row->$k) || (string) $row->$k !== (string) $v) {
                return false;
            }
        }
        return true;
    }

    private function statusAllowed(string $status, bool $ignore_drafts): bool {
        return $status !== 'withdrawn' && (!$ignore_drafts || $status !== 'draft');
    }
}

$wpdb = new Test_Reimbursement_Wpdb();
require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';

$failures = 0;
function check(bool $ok, string $label): void { global $failures; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) $failures++; }

// 1. DB migration 1.58 adds 'draft' to status ENUM.
AVBK_DB::maybe_upgrade();
check($GLOBALS['options']['avbk_db_version'] === '1.58' && str_contains($wpdb->status_enum, "'draft'"), 'DB upgrade 1.58 adds draft to reimbursement status ENUM');

// 2. Draft creation, lookup, and header updates.
check(AVBK_DB::get_draft_reimbursement(7) === null, 'no draft initially');
$draft_id = AVBK_DB::get_or_create_draft_reimbursement(7, 'NL00 TEST 0000 0000 01');
check(AVBK_DB::get_or_create_draft_reimbursement(7) === $draft_id, 'get_or_create reuses existing draft');
check(AVBK_DB::get_reimbursements_for_member(7) === [], 'draft is excluded from member history list');
check(AVBK_DB::update_draft_reimbursement($draft_id, 7, []) === false, 'empty draft header update returns false');
check(AVBK_DB::update_draft_reimbursement($draft_id, 7, ['activity_id' => 9, 'member_note' => 'notitie', 'amount' => 42.5, 'iban' => 'NL00 TEST 0000 0000 02']), 'updating draft header persists fields');
check($wpdb->reimbursements[$draft_id]->iban === 'NL00TEST0000000002' && $wpdb->reimbursements[$draft_id]->activity_id === 9, 'IBAN normalized and activity saved');

// 3. Adding, updating, and duplicate-checking draft receipts.
$rid1 = AVBK_DB::add_reimbursement_receipt($draft_id, [
    'receipt_path' => 'abc.jpg', 'receipt_hash' => 'hash1', 'description' => '', 'date' => '2026-08-01',
    'store' => 'Bakker', 'amount' => 12.5, 'ocr_amount' => 12.5, 'ocr_date' => '2026-08-01', 'ocr_store' => 'Bakker',
]);
$rid2 = AVBK_DB::add_reimbursement_receipt($draft_id, [
    'receipt_path' => '', 'receipt_hash' => '', 'description' => '', 'date' => null,
    'store' => 'Eigen Winkel', 'amount' => null, 'ocr_amount' => null, 'ocr_date' => null, 'ocr_store' => '',
]);
check(AVBK_DB::get_draft_receipt($draft_id, $rid1, 7) !== null && AVBK_DB::get_draft_receipt($draft_id, $rid1, 99) === null, 'draft receipt enforces member ownership');
check(AVBK_DB::update_draft_receipt($draft_id, $rid1, 7, ['description' => 'brood', 'store' => 'Bakkerij', 'date' => '2026-08-02', 'amount' => 14.0, 'ocr_store' => 'Bakker', 'ocr_date' => '2026-08-01', 'ocr_amount' => 12.5]), 'update_draft_receipt updates fields and refreshes summary');
check($wpdb->reimbursements[$draft_id]->description === 'brood', 'description summary refreshed on parent');
check(AVBK_DB::update_draft_receipt($draft_id, $rid1, 99, ['store' => 'hack']) === false, 'non-owner cannot update draft receipt');

// Duplicate checks: exclude self, ignore drafts on submit, detect by hash and by ocr date+store, and legacy fallback.
check(AVBK_DB::find_duplicate_receipt(7, 'hash1', null, null) !== null, 'duplicate by hash found inside draft');
check(AVBK_DB::find_duplicate_receipt(7, 'hash1', null, null, $rid1) === null, 'duplicate check excludes self receipt_id');
check(AVBK_DB::find_duplicate_receipt(7, 'hash1', null, null, 0, true) === null, 'ignore_drafts skips draft receipts');
check(AVBK_DB::find_duplicate_receipt(7, '', '2026-08-01', 'bakker') !== null, 'duplicate by ocr_date + ocr_store found');
check(AVBK_DB::find_duplicate_receipt(7, '', '2026-08-01', 'bakker', $rid1) === null, 'ocr_date + ocr_store excludes self');

// 4. Attach photo to photo-less row (fills empty date/amount, keeps existing store, refuses when already attached).
check(AVBK_DB::attach_member_receipt_photo($draft_id, $rid2, 7, [
    'receipt_path' => 'attached.jpg', 'receipt_hash' => 'hash2', 'ocr_date' => '2026-08-03', 'ocr_store' => 'OCR Winkel', 'ocr_amount' => 9.95,
]), 'attach_member_receipt_photo attaches file to photo-less row');
check($wpdb->receipts[$rid2]->store === 'Eigen Winkel' && $wpdb->receipts[$rid2]->date === '2026-08-03' && $wpdb->receipts[$rid2]->amount === 9.95, 'attach keeps existing store and fills empty date and amount');
check(AVBK_DB::attach_member_receipt_photo($draft_id, $rid2, 7, ['receipt_path' => 'other.jpg', 'receipt_hash' => 'h3', 'ocr_date' => null, 'ocr_store' => '', 'ocr_amount' => null]) === false, 'attach refuses a row that already has a photo');

// 5. Delete one draft receipt and submit the draft -> pending; member can still update pending receipt.
$deleted = AVBK_DB::delete_draft_receipt($draft_id, $rid2, 7);
check($deleted !== null && $deleted->receipt_path === 'attached.jpg' && AVBK_DB::delete_draft_receipt($draft_id, $rid2, 7) === null, 'delete_draft_receipt returns deleted row once');
check(AVBK_DB::submit_draft_reimbursement($draft_id, 7, [
    'activity_id' => 9, 'member_note' => 'klaar', 'amount' => 14.0, 'ocr_amount' => 12.5, 'iban' => 'NL00TEST0000000002',
]), 'submit_draft_reimbursement promotes draft to pending');
check($wpdb->reimbursements[$draft_id]->status === 'pending' && count(AVBK_DB::get_reimbursements_for_member(7)) === 1, 'submitted declaration now appears in member history');
check(AVBK_DB::update_member_receipt($draft_id, $rid1, 7, ['store' => 'Aangepast'], 'pending'), 'member can update a receipt on their pending declaration');
check(AVBK_DB::find_duplicate_receipt(7, 'hash1', null, null, 0, true) !== null, 'submitted receipt now blocks duplicates even with ignore_drafts=true');

// 6. Legacy parent-row duplicate check + discarding a second draft.
$legacy_id = AVBK_DB::create_reimbursement([
    'member_id' => 8, 'activity_id' => null, 'description' => 'oud', 'member_note' => '', 'amount' => 5.0, 'ocr_amount' => 5.0, 'iban' => 'NL00TEST0000000008',
]);
$wpdb->reimbursements[$legacy_id]->receipt_hash = 'legacyhash';
$wpdb->reimbursements[$legacy_id]->ocr_date = '2026-07-01';
$wpdb->reimbursements[$legacy_id]->ocr_store = 'Oude Winkel';
check(AVBK_DB::find_duplicate_receipt(8, 'legacyhash', null, null) !== null, 'legacy hash duplicate detected');
check(AVBK_DB::find_duplicate_receipt(8, '', '2026-07-01', 'oude winkel') !== null, 'legacy ocr_date + ocr_store duplicate detected');

$draft2 = AVBK_DB::get_or_create_draft_reimbursement(7);
AVBK_DB::add_reimbursement_receipt($draft2, [
    'receipt_path' => 'd2.jpg', 'receipt_hash' => 'hd2', 'description' => '', 'date' => null, 'store' => '', 'amount' => null, 'ocr_amount' => null, 'ocr_date' => null, 'ocr_store' => '',
]);
check(AVBK_DB::delete_draft_reimbursement($draft2, 99) === [], 'non-owner cannot discard draft');
check(AVBK_DB::delete_draft_reimbursement($draft2, 7) === ['d2.jpg'] && !isset($wpdb->reimbursements[$draft2]), 'discarding draft deletes rows and returns stored paths');

exit($failures ? 1 : 0);
