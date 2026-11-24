<?php
/**
 * Herinneringen page: candidates() labels each member's open items by
 * activity or order, and the selection table renders the sortable,
 * filterable columns. Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
const DAY_IN_SECONDS = 86400;
function current_time($format) { return $format === 'Y-m-d' ? '2026-11-20' : '2026-11-20 10:00:00'; }
function is_email($email) { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
function get_option($name, $default = false) { return $default; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return (string) $url; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="x">'; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
function wp_date($format, $timestamp) { return gmdate($format, $timestamp); }
function sanitize_text_field($text) { return trim((string) $text); }
function wp_unslash($value) { return $value; }
function checked($checked) { echo $checked ? ' checked' : ''; }
function avpvh_format_name($member, $style) { return $member->first_name; }
final class AVPVH_DB {
    public static array $activity_calls = [];
    public static function get_member(int $id): ?object {
        return [1 => (object) ['id' => 1, 'first_name' => 'Anna', 'email' => 'anna@example.test'], 2 => (object) ['id' => 2, 'first_name' => 'Bram', 'email' => '']][$id] ?? null;
    }
    public static function get_activity(int $id): ?object {
        self::$activity_calls[] = $id;
        return [3 => (object) ['name' => 'Contributie', 'year' => 2026], 2 => (object) ['name' => 'Zonneveld', 'year' => 2026]][$id] ?? null;
    }
}
final class AVBK_DB {
    public static function get_request_history(): array {
        return [1 => ['reminders' => 2, 'last_reminder' => '2026-10-01 09:00:00', 'last_request' => '2026-10-01 09:00:00']];
    }
    public static function get_open_dispute_member_ids(): array { return []; }
    public static function get_open_items_by_member(): array {
        $item = static fn(int $id, float $remaining, array $more) => (object) ($more + ['id' => $id, 'remaining' => $remaining, 'created_at' => '2026-02-01 10:00:00', 'is_estimated' => 0]);
        return [
            1 => [$item(10, 50.0, ['type' => 'contribution', 'activity_id' => 3]), $item(11, 16.0, ['type' => 'other', 'category' => 'tshirt', 'description' => '1x Shirt (M)'])],
            2 => [$item(12, 120.0, ['type' => 'camp', 'activity_id' => 2]), $item(13, 5.0, ['type' => 'other', 'activity_id' => 99, 'description' => 'Losse post'])],
        ];
    }
    public static function split_fee_description(string $description): array {
        return preg_match('/^(.*)\s\(([^)]+)\)$/', $description, $m) ? ['base' => $m[1], 'label' => $m[2]] : ['base' => $description, 'label' => ''];
    }
}
require dirname(__DIR__) . '/includes/class-reminder-rules.php';
require dirname(__DIR__) . '/admin/reminders.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

$rows = AVBK_Reminder_Rules::candidates();
check(array_column($rows, 'member_id') === [2, 1], 'Most owed first');
check($rows[0]['labels'] === ['Kamp Zonneveld 2026', 'Losse post'], 'A camp, and an item whose activity is gone by its description');
check($rows[1]['labels'] === ['Contributie 2026', 'Kledingbestelling'], 'A contribution and a clothing order');
check(count(AVPVH_DB::$activity_calls) === 3, 'Each activity is looked up once');

$_GET = [];
ob_start();
avbk_render_reminder_selection();
$html = ob_get_clean();
check(str_contains($html, 'data-avpvh-sortable') && str_contains($html, 'avpvh-sortable-table'), 'The table is the shared sortable table');
check(str_contains($html, 'data-col="posten" data-filter="select" data-filter-sep="|"'), 'Posten has a checklist filter');
check(str_contains($html, 'data-filter-value="Contributie 2026|Kledingbestelling">Contributie 2026, Kledingbestelling<'), 'A row lists its posts, filterable one by one');
check(str_contains($html, 'data-sort-value="125.00"'), 'Amounts sort as numbers');
check(str_contains($html, 'data-filter-value="2×"') && str_contains($html, 'data-filter-value="nog niet"'), 'Herinnerd filters on the count');
check(str_contains($html, 'data-filter-value="geen geldig e-mailadres"'), 'Let op filters on the warning');
check(str_contains($html, 'data-filter-value="geen"'), 'and on no warning');
check(substr_count($html, 'class="avbk-reminder-member"') === 2, 'One row per member');
check(str_contains($html, 'Alleen de aangevinkte leden die je ziet krijgen een mail'), 'The page says only visible ticked members are mailed');

$_GET = ['min_amount' => '100'];
ob_start();
avbk_render_reminder_selection();
$filtered = ob_get_clean();
check(substr_count($filtered, 'class="avbk-reminder-member"') === 1, 'The server filters still apply');

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
