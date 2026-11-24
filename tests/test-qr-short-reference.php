<?php
/**
 * The QR remittance carries the same short reference (PVH-<member>-B<id>)
 * as the mail and the page show for typing by hand, and falls back to the
 * long F-form when the selection can't be stored. Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
function get_option($name, $default = false) { return ['avbk_club_iban' => 'NL00TEST0123456789', 'avbk_club_name' => 'Testclub'][$name] ?? $default; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
final class AVBK_DB {
    public static bool $can_store = true;
    /** "ids" => reference id */
    public static array $refs = [];
    public static function payment_reference_id(int $member_id, array $ids): int {
        sort($ids);
        $key = $member_id . ':' . implode('.', $ids);
        if (!self::$can_store) { return 0; }
        return self::$refs[$key] ??= count(self::$refs) + 1;
    }
    public static function split_fee_description(string $description): array { return ['base' => $description]; }
    public static function fee_item_quantity_label(object $item): string { return ''; }
    public static function fee_item_book_year(object $item): ?int { return $item->year ?? null; }
}
require dirname(__DIR__) . '/includes/class-qr.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

$shirt = (object) ['id' => 601, 'status' => 'open', 'remaining' => 32.0, 'description' => 'Lustrumkleding', 'year' => 2026];
$fee = (object) ['id' => 51, 'status' => 'open', 'remaining' => 50.0, 'description' => 'Contributie 2026', 'year' => 2026];
$paid = (object) ['id' => 16, 'status' => 'open', 'remaining' => 0.0, 'description' => 'Kamp', 'year' => 2026];

$typed = AVBK_QR::short_reference_code(40, [601]);
check($typed === 'PVH-40-B1', 'The short reference for one item');
check(AVBK_QR::remittance_for_fee_item(40, $shirt) === 'PVH-40-B1: Lustrumkleding 2026', 'The fee-item QR uses that same reference');

$balance = AVBK_QR::remittance_for_balance([$fee, $paid, $shirt], 40);
check($balance === 'PVH-40-B2: Contributie 2026, Lustrumkleding', 'The balance QR uses the short reference of its open items');
check(AVBK_QR::short_reference_code(40, [601, 51]) === 'PVH-40-B2', 'Whatever order the items come in');

$combined = AVBK_QR::remittance_for_combined([['item' => $fee, 'name' => 'Anna'], ['item' => $shirt, 'name' => 'Bram']], 40);
check(str_starts_with($combined, 'PVH-40-B2: Anna: Contributie 2026'), 'The combined QR uses the short reference too');

check(AVBK_QR::manual_reference(40, $shirt) === 'PVH-40-B1: Lustrumkleding', 'The reference to type by hand is the same one');
$html = AVBK_QR::manual_transfer_html(40, (object) ['id' => 601, 'description' => 'Shirt <M>']);
check(str_contains($html, 'NL00TEST0123456789') && str_contains($html, 'Testclub'), 'The manual transfer names the IBAN and the club');
check(str_contains($html, '<code>PVH-40-B1: Shirt &lt;M&gt;</code>'), 'and the escaped reference');

AVBK_DB::$can_store = false;
check(AVBK_QR::remittance_for_fee_item(40, $shirt) === 'PVH-40-F601: Lustrumkleding 2026', 'Without storage the long form is used');
check(AVBK_QR::remittance_for_balance([$paid], 40) === 'PVH-40', 'Nothing open: just the member reference');

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
