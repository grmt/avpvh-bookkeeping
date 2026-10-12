<?php
/** Standalone view checks with synthetic members/fees; no database writes or mail. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');

class AVPVH_Roles {
    public static function current_user_has_role(string $role): bool { return $GLOBALS['treasurer']; }
}
class AVPVH_DB {
    public static function get_members(array $args): array { return array_values($GLOBALS['members']); }
    public static function get_member(int $id): ?object { return $GLOBALS['members'][$id] ?? null; }
    public static function get_extended_household(int $id): array { return $GLOBALS['households'][$id] ?? []; }
    public static function get_activity(int $id): ?object { return $id === 7 ? (object) ['year' => 2027] : null; }
}
class AVBK_Household_Test_DB {
    public string $prefix = 'test_';
    public function prepare(string $sql, ...$args): string { return vsprintf($sql, $args); }
    public function get_results(string $sql): array {
        if (str_contains($sql, 'FROM test_avb_fee_items f')) {
            preg_match('/f.member_id = (\d+)/', $sql, $match);
            return array_map(fn($item) => clone $item, array_values(array_filter(
                $GLOBALS['fees'], fn($item) => $item->member_id === (int) $match[1]
            )));
        }
        return []; // No historical student statuses, rates, or payment links in fixtures.
    }
}
function current_user_can(string $capability): bool { return $GLOBALS['admin']; }
function wp_die(string $message): void { throw new RuntimeException('Access denied'); }
function current_time(string $format): string { return $format === 'Y' ? '2026' : '2026-10-09'; }
function get_option(string $name, $default = false) { return $name === 'avbk_closed_through_year' ? $GLOBALS['closed_year'] : $default; }
function admin_url(string $path): string { return 'https://example.test/wp-admin/' . $path; }
function home_url(string $path): string { return 'https://example.test' . $path; }
function add_query_arg($args, $value = null, $url = null): string {
    if (!is_array($args)) {
        $args = [$args => $value];
    } else {
        $url = $value;
    }
    $url ??= admin_url('admin.php') . '?' . http_build_query($_GET);
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);
    $query = array_filter(array_replace($query, $args), fn($val) => $val !== false);
    return preg_replace('/\?.*$/', '', $url) . ($query ? '?' . http_build_query($query) : '');
}
function remove_query_arg(string $key): string { return add_query_arg([$key => false]); }
function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value): string { return esc_html($value); }
function esc_url($value): string { return esc_html($value); }
function avpvh_format_name(object $member): string { return $member->name; }
function wp_date(string $format, int $timestamp): string { return date($format, $timestamp); }
function wp_nonce_field(string $action): void { echo '<input type="hidden" name="_wpnonce" value="test">'; }

require AVBK_PLUGIN_DIR . 'includes/class-db.php';
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $message);
    if (!in_array('--render', $_SERVER['argv'] ?? [], true)) echo "PASS $message\n";
}
function fee(int $id, int $member_id, ?int $year, float $due, float $paid, string $description, string $status = 'open'): object {
    return (object) [
        'id' => $id, 'member_id' => $member_id, 'year' => $year,
        'amount_due' => $due, 'paid' => $paid, 'description' => $description,
        'status' => $status, 'created_at' => '2026-01-01', 'activity_id' => null,
    ];
}
function reset_case(): void {
    $GLOBALS['wpdb'] = new AVBK_Household_Test_DB();
    $GLOBALS['members'] = [
        10 => (object) ['id' => 10, 'name' => 'Lid A'],
        11 => (object) ['id' => 11, 'name' => 'Lid B'],
        12 => (object) ['id' => 12, 'name' => 'Lid C'],
        99 => (object) ['id' => 99, 'name' => 'Niet gekoppeld'],
    ];
    $GLOBALS['households'] = [10 => [$GLOBALS['members'][10], $GLOBALS['members'][11], $GLOBALS['members'][12], $GLOBALS['members'][11]]];
    $GLOBALS['fees'] = [
        fee(1, 10, 2026, 75, 75, 'Betaalde bijdrage'),
        fee(2, 11, 2026, 40, 15, 'Gedeeltelijk betaald'),
        fee(3, 11, 2025, 10, 0, 'Open vorig jaar'),
        fee(4, 11, 2024, 9, 0, 'Afgesloten jaar'),
        fee(5, 11, 2026, 90, 0, 'Kwijtgescholden bijdrage', 'waived'),
        fee(6, 11, 2026, 10, 110, 'Te veel betaald'),
        fee(7, 12, null, 12, 0, 'Overige kosten zonder jaar'),
        fee(8, 99, 2026, 123, 0, 'Kosten buiten familie'),
    ];
    $GLOBALS['closed_year'] = 2024;
    $GLOBALS['admin'] = false;
    $GLOBALS['treasurer'] = true;
}
function render(array $query = []): string {
    $_GET = ['page' => 'avbk-members', 'member_id' => 10] + $query;
    ob_start();
    try {
        require AVBK_PLUGIN_DIR . 'admin/members-balance.php';
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}
function table(string $html, string $id): string {
    preg_match('/<table id="' . preg_quote($id, '/') . '".*?<\/table>/s', $html, $match);
    return $match[0] ?? '';
}
function family_link(string $html): array {
    preg_match('/href="([^"]+)">Volledige rekening, huisgenoten en QR/', $html, $match);
    parse_str(parse_url(html_entity_decode($match[1] ?? ''), PHP_URL_QUERY) ?? '', $query);
    return $query;
}

reset_case();
$html = render();
$preview = $html;
$summary = table($html, 'avbk-household-balances');
$open = table($html, 'avbk-household-open-items');
check(substr_count($summary, '<tbody>') === 1 && substr_count($summary, 'Bekijk rekening') === 3, 'each household member shown once, including members with zero balance');
check(str_contains($summary, '47,00') && str_contains($summary, '35,00') && str_contains($summary, '12,00'), 'individual and combined totals count partial payments without masking debts with overpayments');
check(str_contains($open, 'Open vorig jaar') && str_contains($open, 'Overige kosten zonder jaar'), 'household shows all non-closed years even when individual table is current-year-only');
check(!str_contains($open, 'Afgesloten jaar') && !str_contains($open, 'Kwijtgescholden') && !str_contains($open, 'Te veel betaald') && !str_contains($open, 'Betaalde bijdrage'), 'closed, waived, paid and overpaid fees omitted from open items');
check(!str_contains($html, 'Niet gekoppeld') && !str_contains($html, 'Kosten buiten familie'), 'unrelated members and costs are not included');
$query = family_link($html);
check(($query['member_id'] ?? '') === '10' && ($query['also'] ?? []) === ['11', '12'], 'combined QR link is always available and includes the unique household, even if chosen member owes nothing');
check(!str_contains($html, 'id="avbk-payment-selection"'), 'no empty payment request form for settled individual');
check(str_contains(html_entity_decode($summary), 'member_id=11&per_year=1'), 'member detail links reveal non-closed years rather than hiding older open fees');

$with_years = render(['per_year' => '1']);
check(table($with_years, 'avbk-household-balances') === $summary, 'individual per-year toggle does not change household totals');
$with_closed = render(['show_all_years' => '1', 'per_year' => '1']);
check(str_contains(table($with_closed, 'avbk-household-balances'), '56,00') && str_contains(table($with_closed, 'avbk-household-open-items'), 'Afgesloten jaar'), 'explicit closed-year toggle reveals historical fees with matching totals');
check(str_contains($with_closed, 'actuele rekening en QR hieronder bevatten deze niet'), 'historical fees are not represented as payable through current QR');

reset_case();
$fees[] = fee(9, 10, 2026, 5, 0, 'Eigen open post');
$html = render();
check(str_contains(table($html, 'avbk-household-balances'), '52,00') && str_contains($html, 'id="avbk-payment-selection"') && str_contains($html, 'name="fee_item_ids[]" value="9"'), 'existing individual selection and payment requests remain available');

reset_case();
$fees[] = fee(9, 10, 2025, 5, 0, 'Eigen vorige post');
$html = render();
check(str_contains(table($html, 'avbk-household-open-items'), 'Eigen vorige post') && !str_contains($html, 'id="avbk-payment-selection"'), 'own older open fee is visible in household section without changing individual year filter');

reset_case();
$fees[6]->activity_id = 7;
check(str_contains(table(render(), 'avbk-household-open-items'), '<td>2027</td>'), 'fees without year use their activity book year');
$members[11]->name = '<script>name</script>';
$fees[1]->description = '<script>fee</script>';
$html = render();
check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;fee&lt;/script&gt;'), 'member names and fee descriptions are HTML escaped');

reset_case();
$fees = [];
$html = render();
check(str_contains($html, 'Geen openstaande posten') && str_contains($html, 'Volledige rekening, huisgenoten en QR'), 'fully paid household still shown with combined account link');
reset_case();
$households = [];
$html = render();
check(str_contains($html, 'geen andere familieleden of huisgenoten gekoppeld') && substr_count(table($html, 'avbk-household-balances'), 'Bekijk rekening') === 1, 'no inferred family when no household is registered');

reset_case();
$treasurer = false;
try {
    render();
    throw new RuntimeException('FAILED: unauthorized page rendered');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'Access denied', 'unauthorized viewers cannot see household balances');
}
$admin = true;
check(str_contains(render(), 'avbk-household-balances'), 'administrator retains access');

if (in_array('--render', $_SERVER['argv'] ?? [], true)) {
    echo '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8"><title>Fictief familieoverzicht</title></head><body>' . $preview . '</body></html>';
} else {
    echo "All household view checks passed; no real data or mail used.\n";
}
