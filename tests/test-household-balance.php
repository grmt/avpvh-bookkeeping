<?php
/**
 * Standalone view checks with synthetic members/fees; no database writes or
 * mail. Covers the member-detail page's household "Rekening combineren met"
 * selector, which folds selected household members' own open items into the
 * same combined table (replacing the older separate household-balance
 * summary section).
 */
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
function wp_list_pluck(array $list, string $field): array {
    return array_map(fn($item) => is_object($item) ? $item->$field : $item[$field], $list);
}
function checked($checked, $current = true, bool $echo = true): string {
    $result = ((string) $checked === (string) $current) ? ' checked="checked"' : '';
    if ($echo) echo $result;
    return $result;
}

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
    $GLOBALS['households'] = [10 => [$GLOBALS['members'][10], $GLOBALS['members'][11], $GLOBALS['members'][12]]];
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
function table(string $html, string $storageKey): string {
    preg_match('/<table data-storage-key="' . preg_quote($storageKey, '/') . '".*?<\/table>/s', $html, $match);
    return $match[0] ?? '';
}
reset_case();
$html = render();
$preview = $html;
$table = table($html, 'avbk_member_detail_hidden_cols');
check(!str_contains($html, 'data-col="lid"'), 'no Lid column when no household members are combined into the table');
check(!str_contains($table, 'Gedeeltelijk betaald'), 'own table does not include other household members\' items by default');
check(str_contains($html, 'Rekening combineren met'), 'household combine checkboxes are offered when a household is registered');
check(str_contains($html, 'name="also[]" value="11"') && str_contains($html, 'name="also[]" value="12"'), 'a checkbox is offered per household member');
check(!str_contains($html, 'checked="checked"'), 'no household member pre-selected by default');
check(!str_contains($html, 'Niet gekoppeld') && !str_contains($html, 'Kosten buiten familie'), 'unrelated members and costs are not included');
check(!str_contains($html, 'id="avbk-payment-selection"'), 'no empty payment request form for a settled individual');

$combined = render(['also' => ['11']]);
$combined_table = table($combined, 'avbk_member_detail_hidden_cols');
check(str_contains($combined, 'data-col="lid"'), 'Lid column appears once a household member is combined in');
check((bool) preg_match('/name="also\[\]" value="11"\s+checked="checked"/', $combined), 'combined member\'s checkbox reflects the selection');
check(str_contains($combined_table, 'Gedeeltelijk betaald') && str_contains($combined_table, 'Lid B'), 'combined member\'s own open item and name appear in the same table');
check(!str_contains($combined_table, 'Afgesloten jaar'), 'a closed-year item of the combined member stays hidden by default, same as for the viewed member');
check(str_contains($combined_table, 'Kwijtgescholden bijdrage') && str_contains($combined_table, 'Te veel betaald'), 'the combined member\'s full current-year ledger is shown (not just open items), same as for the viewed member');
check(!str_contains($combined, 'name="also[]" value="99"'), 'unrelated members are never offered as combine options');

$ignored = render(['also' => ['99']]);
check(!str_contains($ignored, 'data-col="lid"') && !str_contains($ignored, 'Niet gekoppeld'), 'a member outside the household cannot be combined in via a crafted also[] value');

$with_closed = render(['also' => ['11'], 'show_all_years' => '1', 'per_year' => '1']);
check(str_contains(table($with_closed, 'avbk_member_detail_hidden_cols'), 'Afgesloten jaar'), 'show_all_years reveals closed-year items for a combined member too, same as for the viewed member');

reset_case();
$fees[] = fee(9, 10, 2026, 5, 0, 'Eigen open post');
$html = render();
check(str_contains($html, 'id="avbk-payment-selection"') && str_contains($html, 'name="fee_item_ids[]" value="9"'), 'existing selection and payment requests remain available for the viewed member\'s own items');

reset_case();
$fees[6]->activity_id = 7;
$per_year_combined = render(['also' => ['12'], 'per_year' => '1']);
check(str_contains(table($per_year_combined, 'avbk_member_detail_hidden_cols'), 'Overige kosten zonder jaar'), 'a combined member\'s fee without a year uses its activity book year, same as for the viewed member');

$members[11]->name = '<script>name</script>';
$fees[1]->description = '<script>fee</script>';
$html = render(['also' => ['11']]);
check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;fee&lt;/script&gt;') && str_contains($html, '&lt;script&gt;name&lt;/script&gt;'), 'member names and fee descriptions are HTML escaped, including in the new Lid column');

reset_case();
$fees = [];
$html = render();
check(str_contains($html, 'Nog geen bijdragen geregistreerd'), 'an empty account still renders without error');

reset_case();
$households = [];
$html = render();
check(!str_contains($html, 'Rekening combineren met'), 'no combine selector offered when no household is registered');

reset_case();
$treasurer = false;
try {
    render();
    throw new RuntimeException('FAILED: unauthorized page rendered');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'Access denied', 'unauthorized viewers cannot see the member balance page');
}
$admin = true;
check(str_contains(render(), 'data-storage-key="avbk_member_detail_hidden_cols"'), 'administrator retains access');

if (in_array('--render', $_SERVER['argv'] ?? [], true)) {
    echo '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8"><title>Fictief familieoverzicht</title></head><body>' . $preview . '</body></html>';
} else {
    echo "All household view checks passed; no real data or mail used.\n";
}
