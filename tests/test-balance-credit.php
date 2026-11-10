<?php
/** Tests for settling credit against a payment request selection (AVBK_Admin, Ledenoverzicht). Fictional data only. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_list_pluck($list, $field) { return array_map(fn($o) => $o->$field, array_values($list)); }
function get_option($key, $default = false) { return $key === 'avbk_payment_email_login_help' ? 0 : $default; }
function avpvh_format_name($m) { return $m->first_name; }
class AVPVH_DB {
    public static function get_extended_household(int $id): array { return $id === 1 ? [(object) ['id' => 2, 'first_name' => 'Bram']] : []; }
    public static function get_relationships(int $id): array { return []; }
    public static function get_member(int $id): object { return (object) ['id' => $id, 'first_name' => $id === 2 ? 'Bram' : 'Anna']; }
}
class AVBK_QR {
    public const UNSUPPORTED_BANKS_NOTE = 'Let op.';
    public static function fee_reference_code(int $member_id, array $ids): string { return "PVH-$member_id-F" . implode('-', $ids); }
}
class AVBK_DB {
    public static array $items = [];
    public static float $credit = 0.0;
    public static array $applied = [];
    public static function get_fee_item(int $id): ?object { return isset(self::$items[$id]) ? clone self::$items[$id] : null; }
    public static function get_fee_item_remaining(object $item): float { return $item->due; }
    public static function apply_credit_fifo(int $member_id, int $fee_item_id, float $amount): float {
        $take = min(self::$credit, $amount);
        self::$credit -= $take;
        self::$applied[] = [$member_id, $fee_item_id, $take];
        return $take;
    }
    public static function split_fee_description(string $d): array { return ['base' => $d, 'label' => '']; }
    public static function fee_item_quantity_label(object $item): string { return ''; }
}
require_once AVBK_PLUGIN_DIR . 'includes/interface-component.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-admin.php';

$failures = 0;
function check(bool $ok, string $label): void { global $failures; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) $failures++; }
function call(string $method, ...$args) { return (new ReflectionMethod('AVBK_Admin', $method))->invoke(null, ...$args); }
$fee = fn(int $id, int $member, float $due, string $status = 'open') => (object) ['id' => $id, 'member_id' => $member, 'due' => $due, 'status' => $status, 'description' => "Post $id"];
AVBK_DB::$items = [10 => $fee(10, 1, 35.0), 11 => $fee(11, 2, 45.5), 12 => $fee(12, 3, 20.0), 13 => $fee(13, 1, 0.0), 14 => $fee(14, 1, 9.0, 'waived')];

$allowed = call('balance_allowed_member_ids', 1);
check($allowed === [1, 2], 'the member and their household may be in a selection');
$items = call('balance_selection_items', [10, 11, 12, 13, 14, 99], $allowed);
check(wp_list_pluck($items, 'id') === [10, 11], 'only open items of the member and housemates are selected');

// Part of the selection paid by credit, including a housemate's item.
AVBK_DB::$credit = 60.0;
check(call('apply_credit_to_items', 1, $items) === 60.0, 'all available credit is applied');
check(AVBK_DB::$applied === [[1, 10, 35.0], [1, 11, 25.0]], "the member's credit also covers a housemate's item");
check(wp_list_pluck($items, 'open_after') === [0.0, 20.5], 'what is still open per item');
$html = call('balance_request_mail_html', (object) ['id' => 1, 'first_name' => 'Anna', 'email' => 'a@example.test'], $items, 60.0, 'https://example.test/');
check(str_contains($html, 'Verrekend met je tegoed') && str_contains($html, '−&nbsp;€&nbsp;60,00'), 'the mail shows the settled credit');
check(str_contains($html, 'Nog te betalen') && substr_count($html, '€&nbsp;20,50') === 2, 'the mail asks for what is still open, in the table and as Bedrag');
check(str_contains($html, 'PVH-1-F11<'), 'the reference code only holds items that are still open');
check(str_contains($html, 'Post 11 voor Bram'), "a housemate's item names them");

// More credit than the selection: the item is settled in full and the rest of the credit stays.
AVBK_DB::$credit = 70.0; AVBK_DB::$applied = [];
$items = call('balance_selection_items', [10], $allowed);
check(call('apply_credit_to_items', 1, $items) === 35.0, 'only what the item needs is applied');
check(AVBK_DB::$credit === 35.0, 'the rest of the credit stays available');
check(array_filter($items, fn($item) => $item->open_after > 0.005) === [], 'nothing is left to pay, so no payment request is sent');

// No credit: a plain request with a total.
$items = call('balance_selection_items', [10, 11], $allowed);
$html = call('balance_request_mail_html', (object) ['id' => 1, 'first_name' => 'Anna'], $items, 0.0, 'https://example.test/');
check(!str_contains($html, 'tegoed') && str_contains($html, 'Totaal') && str_contains($html, 'PVH-1-F10-11<'), 'without credit: total and all items in the reference');

exit($failures ? 1 : 0);
