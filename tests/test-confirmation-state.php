<?php
/** Confirmation preserves pending records when a database operation fails. */
require __DIR__ . '/test-public-registration.php';
class Confirmation_Test_DB {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public Public_Security_WPDB $inner;
    public string $fail = '';
    private ?Public_Security_WPDB $snapshot = null;
    public function __construct() { $this->inner = new Public_Security_WPDB(); }
    public function __call($name, $args) {
        if ($name === 'query') {
            if ($args[0] === $this->fail || ($this->fail === 'lock' && str_contains($args[0], 'FOR UPDATE'))) return false;
            if ($args[0] === 'START TRANSACTION') $this->snapshot = unserialize(serialize($this->inner));
            if ($args[0] === 'ROLLBACK' && $this->snapshot) $this->inner = $this->snapshot;
        }
        if ($name === 'insert' && $this->fail === 'fee' && str_contains($args[0], 'avb_fee_items')) return false;
        if ($name === 'update' && $this->fail === 'update') return false;
        $result = $this->inner->$name(...$args);
        $this->insert_id = $this->inner->insert_id;
        return $result;
    }
}
foreach (['START TRANSACTION', 'lock', 'COMMIT', 'update', 'fee'] as $failure) {
    reset_test_env();
    $wpdb = new Confirmation_Test_DB();
    $result = AVBK_DB::create_order(['email' => 'anna@example.test', 'order_type' => 'book', 'first_name' => 'Anna', 'last_name' => 'Dijk', 'total_amount' => 35]);
    $wpdb->fail = $failure;
    $rejected = false;
    try { AVBK_DB::confirm_order($result['id']); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected && $wpdb->inner->orders[$result['id']]->status === 'pending_confirmation' && !$wpdb->inner->fee_items, 'Incomplete confirmation leaves the request pending: ' . $failure);
}
reset_test_env();
$wpdb = new Confirmation_Test_DB();
$result = AVBK_DB::create_order(['email' => 'anna@example.test', 'order_type' => 'tshirt', 'first_name' => 'Anna', 'last_name' => 'Dijk', 'total_amount' => 21]);
AVBK_DB::confirm_order($result['id']);
check(count($wpdb->inner->fee_items) === 1, 'Imported garment order without item lines can still be confirmed');
$wpdb->fail = 'fee';
$rejected = false;
try { AVBK_DB::create_tshirt_fee_item(1, 1, 21); } catch (RuntimeException $e) { $rejected = true; }
check($rejected, 'Failed garment fee write is reported');
$rejected = false;
try { AVBK_DB::upsert_event_fee_item(1, 'Test event', 20); } catch (RuntimeException $e) { $rejected = true; }
check($rejected, 'Failed event fee write is reported');
$wpdb->fail = '';
AVBK_DB::upsert_event_fee_item(1, 'Test event', 20);
AVBK_DB::confirm_order(999999);
$result = AVBK_DB::create_order(['status' => 'cancelled', 'email' => 'anna@example.test']);
AVBK_DB::confirm_order($result['id']);
check($wpdb->inner->orders[$result['id']]->status === 'cancelled', 'Cancelled order cannot be confirmed');

foreach (['already', 'linked', 'update_failed'] as $case) {
    $wpdb = new Confirmation_Test_DB();
    $result = AVBK_DB::create_congress_registration(['member_id' => 1, 'fee_item_id' => null, 'first_name' => 'Anna', 'suffix' => '', 'last_name' => 'Dijk', 'email' => 'anna@example.test', 'phone' => '', 'match_type' => 'email', 'review_note' => '', 'status' => $case === 'already' ? 'confirmed' : 'pending_confirmation']);
    if ($case === 'update_failed') $wpdb->fail = 'update';
    $rejected = false;
    try { AVBK_DB::confirm_congress_registration($result['id']); } catch (RuntimeException $e) { $rejected = true; }
    check($case === 'update_failed' ? $rejected : $wpdb->inner->registrations[$result['id']]->status === 'confirmed', 'Congress confirmation handles existing linkage/state: ' . $case);
}
