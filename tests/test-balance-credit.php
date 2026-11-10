<?php
/** Tests for settling credit against a payment request selection (AVBK_Admin, Ledenoverzicht). Fictional data only. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_list_pluck($list, $field) { return array_map(fn($o) => $o->$field, array_values($list)); }
function get_option($key, $default = false) { return $key === 'avbk_payment_email_login_help' ? 0 : $default; }
function avpvh_format_name($m) { return $m->first_name; }
function wp_date($format, $ts) { return gmdate('j-n-Y', $ts); }
function home_url($path = '') { return 'https://example.test' . $path; }
function is_email($e) { return str_contains((string) $e, '@'); }
function wp_mail($to, $subject, $body, $headers = []) { $GLOBALS['mails'][] = [$to, $subject, $body, $headers]; return true; }
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
    /** Fictional bank transfers with their unused rest: id => [date, amount, credit]. */
    public static array $sources = [];
    public static array $allocations = [];
    public static array $applied = [];
    public static function get_fee_item(int $id): ?object { return isset(self::$items[$id]) ? clone self::$items[$id] : null; }
    public static function get_fee_item_remaining(object $item): float { return $item->due; }
    public static function get_credit_transactions_for_member(int $member_id): array {
        $rows = [];
        foreach (self::$sources as $id => [$date, $amount, $credit]) {
            if ($credit > 0.005) $rows[] = (object) ['id' => $id, 'transaction_date' => $date, 'amount' => $amount, 'credit' => $credit];
        }
        return $rows;
    }
    public static function get_allocations_for_transaction(int $id): array { return self::$allocations[$id] ?? []; }
    public static function apply_credit_fifo(int $member_id, int $fee_item_id, float $amount): float {
        $applied = 0.0;
        foreach (self::$sources as $id => [$date, $total, $credit]) {
            $take = min($credit, round($amount - $applied, 2));
            if ($take <= 0.005) continue;
            self::$sources[$id][2] = round($credit - $take, 2);
            self::$allocations[$id][] = (object) ['fee_item_id' => $fee_item_id, 'amount' => $take];
            $applied = round($applied + $take, 2);
        }
        self::$applied[] = [$member_id, $fee_item_id, $applied];
        return $applied;
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

$member = (object) ['id' => 1, 'first_name' => 'Anna', 'email' => 'a@example.test'];
AVBK_DB::$items[20] = $fee(20, 1, 35.0);

// Part of the selection paid by credit, including a housemate's item: two transfers with an unused rest.
AVBK_DB::$sources = [7 => ['2026-03-02', 50.0, 15.0], 8 => ['2026-10-07', 105.0, 45.0]];
AVBK_DB::$allocations = [7 => [(object) ['fee_item_id' => 20, 'amount' => 35.0]]];
$items = call('balance_selection_items', [10, 11], $allowed);
$settlement = call('settle_credit', 1, $items);
check($settlement['applied'] === 60.0 && $settlement['left'] === 0.0, 'all available credit is applied');
check(AVBK_DB::$applied === [[1, 10, 35.0], [1, 11, 25.0]], "the member's credit also covers a housemate's item");
check(wp_list_pluck($items, 'open_after') === [0.0, 20.5], 'what is still open per item');
check(wp_list_pluck($settlement['sources'], 'id') === [7, 8], 'both transfers are named as the source');
$html = call('balance_request_mail_html', $member, $items, $settlement, 'https://example.test/');
check(str_contains($html, 'Verrekend met je tegoed') && str_contains($html, '−&nbsp;€&nbsp;60,00'), 'the mail shows the settled credit');
check(str_contains($html, 'Nog te betalen') && substr_count($html, '€&nbsp;20,50') === 2, 'the mail asks for what is still open, in the table and as Bedrag');
check(str_contains($html, 'PVH-1-F11<'), 'the reference code only holds items that are still open');
check(str_contains($html, 'Post 11 voor Bram'), "a housemate's item names them");
check(str_contains($html, 'De overboeking van €&nbsp;50,00 op 2-3-2026 betaald') && str_contains($html, 'Post 20') && str_contains($html, '€&nbsp;15,00'), 'the first transfer: what it paid and what was left');
check(str_contains($html, 'De overboeking van €&nbsp;105,00 op 7-10-2026 is nog niet voor een bijdrage gebruikt'), 'the second transfer was unused');
check(str_contains($html, 'Daarvan is nu €&nbsp;60,00 verrekend. Je tegoed is daarmee op.'), 'how much was settled and that nothing is left');

// More credit than the selection: the item is settled in full, the rest of the credit stays, and only the credit mail goes out.
AVBK_DB::$sources = [9 => ['2026-10-07', 105.0, 70.0]];
AVBK_DB::$allocations = [9 => [(object) ['fee_item_id' => 20, 'amount' => 35.0]]];
$GLOBALS['mails'] = [];
$items = call('balance_selection_items', [10], $allowed);
$settlement = call('settle_credit', 1, $items);
check($settlement['applied'] === 35.0 && $settlement['left'] === 35.0, 'only what the item needs is applied; the rest stays');
check(array_filter($items, fn($item) => $item->open_after > 0.005) === [], 'nothing is left to pay, so no payment request is sent');
call('mail_credit_applied', $member, $items, $settlement);
[$to, $subject, $body, $headers] = $GLOBALS['mails'][0] ?? ['', '', '', []];
check($to === 'a@example.test' && $subject === '[AV-PvH] Tegoed verrekend' && in_array('Content-Type: text/html; charset=UTF-8', $headers, true), 'the credit mail goes to the member as HTML');
check(str_contains($body, 'We hebben je tegoed verrekend') && str_contains($body, 'Nog te betalen') && str_contains($body, '€&nbsp;0,00'), 'the credit mail shows the settlement');
check(str_contains($body, 'De overboeking van €&nbsp;105,00 op 7-10-2026 betaalde') && str_contains($body, 'Je houdt €&nbsp;35,00 tegoed over.'), 'the credit mail explains where the credit came from and what is left');

// No credit: a plain request with a total.
$items = call('balance_selection_items', [10, 11], $allowed);
$html = call('balance_request_mail_html', (object) ['id' => 1, 'first_name' => 'Anna'], $items, ['applied' => 0.0, 'sources' => [], 'left' => 0.0], 'https://example.test/');
check(!str_contains($html, 'tegoed') && str_contains($html, 'Totaal') && str_contains($html, 'PVH-1-F10-11<'), 'without credit: total and all items in the reference');

exit($failures ? 1 : 0);
