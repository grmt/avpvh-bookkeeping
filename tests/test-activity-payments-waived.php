<?php
/**
 * Deelname en betalingen: what is still open and the payment status per
 * participant. A waived fee item (an honorary member's contribution) is owed
 * by no one, so the row shows Kwijtgescholden and offers no payment request.
 * Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');

function esc_html($s) { return htmlspecialchars((string) $s); }
function esc_attr($s) { return htmlspecialchars((string) $s); }
function esc_url($s) { return (string) $s; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_list_pluck($list, $field) { return array_map(static fn($o) => $o->$field, $list); }
function wp_nonce_field($a) {}
function wp_nonce_url($u, $a = '') { return $u; }
function submit_button($text, ...$a) { echo '<button>' . $text . '</button>'; }
function avpvh_format_name($m, $style = '') { return $m->last_name . ', ' . $m->first_name; }
final class AVBK_DB {
    public static array $items = [];
    public static function get_fee_item_for_member_activity(int $member_id, int $activity_id): ?object { return self::$items[$member_id] ?? null; }
    public static function get_fee_item_paid(int $id): float { return [2 => 20.0, 4 => 50.0, 5 => 60.0][$id] ?? 0.0; }
    public static function get_refunded_for_fee_item(int $id): float { return 0.0; }
    public static function get_sheet_participation_meta(int $activity_id, int $member_id): ?object { return null; }
    public static function get_own_known_ibans(int $member_id): array { return []; }
    public static function get_payments_for_fee_item(int $id): array { return []; }
    public static function get_last_payment_request(int $id): ?object { return null; }
}
require dirname(__DIR__) . '/admin/activity-payments.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}
function participant(int $id): object {
    return (object) ['member_id' => $id, 'first_name' => 'Anna', 'last_name' => 'Jansen', 'diet' => '', 'notes' => ''];
}
function row(int $id): array {
    $values = avbk_activity_participant_values(participant($id), 3, ['price_per_person' => 15]);
    ob_start();
    avbk_render_activity_participant_row(participant($id), $values, 3);
    return [$values, (string) ob_get_clean()];
}

AVBK_DB::$items = [
    1 => (object) ['id' => 1, 'amount_due' => 50.0, 'status' => 'waived'],
    2 => (object) ['id' => 2, 'amount_due' => 50.0, 'status' => 'open'],
    4 => (object) ['id' => 4, 'amount_due' => 50.0, 'status' => 'open'],
    5 => (object) ['id' => 5, 'amount_due' => 50.0, 'status' => 'open'],
    6 => (object) ['id' => 6, 'amount_due' => 50.0, 'status' => 'open'],
];

[$values, $html] = row(1);
check($values['waived'] && $values['due'] === 0.0 && $values['remaining'] === 0.0 && $values['open'] === 0.0, 'Waived item: nothing due, nothing remaining');
check($values['status'] === 'Kwijtgescholden', 'Waived item: status Kwijtgescholden');
check(str_contains($html, 'Kwijtgescholden') && !str_contains($html, 'Vraag om betaling'), 'Waived item: shows Kwijtgescholden, no payment request');

[$values, $html] = row(2);
check(!$values['waived'] && $values['due'] === 50.0 && $values['remaining'] === 30.0, 'Open item: due and remaining');
check(str_contains($html, '€ 50,00') && str_contains($html, 'Vraag om betaling'), 'Open item: amount and payment request');
check($values['status'] === 'Deels betaald' && str_contains($html, '€ 30,00') && str_contains($html, '<td>Deels betaald</td>'), 'Part paid: € 30,00 still to pay, Deels betaald');
check(row(4)[0]['status'] === 'Betaald' && row(4)[0]['open'] === 0.0, 'Fully paid: Betaald, nothing open');
check(row(5)[0]['status'] === 'Betaald' && row(5)[0]['open'] === 0.0, 'Overpaid: Betaald, nothing open rather than a negative amount');
check(row(6)[0]['status'] === 'Open' && row(6)[0]['open'] === 50.0, 'Nothing paid: Open, everything still to pay');

[$values, $html] = row(3);
check(!$values['waived'] && $values['due'] === 15.0 && $values['remaining'] === 0.0 && $values['status'] === 'Geen post', 'No item yet: the activity price, nothing to request, Geen post');

// The table: the new columns and their footer total.
ob_start();
avbk_render_activity_participants_table(3, [participant(2), participant(6), participant(1)], ['price_per_person' => 15]);
$table = (string) ob_get_clean();
check(str_contains($table, 'data-col="open"') && str_contains($table, 'data-col="status" data-filter="select"'), 'Table: Nog te betalen and a Status filter');
check(str_contains($table, 'data-total-col="open" data-sort-value="80.00"'), 'Table: total still to pay (30 + 50 + 0)');
check(str_contains($table, 'data-total-col="totaal" data-sort-value="100.00"'), 'Table: the waived item is left out of the total due');

exit($failures ? 1 : 0);
