<?php
/** Request-level fixtures: real redirects return, so handlers must terminate. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
require AVBK_PLUGIN_DIR . 'includes/interface-component.php';
function check_admin_referer(...$args): void {}
function home_url($path = ''): string { return 'https://example.test' . $path; }
function wp_unslash($value) { return $value; }
function wp_parse_url($url) { return parse_url($url); }
function esc_url_raw($url): string { return $url; }
function sanitize_text_field($value): string { return trim(strip_tags($value)); }
function sanitize_textarea_field($value): string { return sanitize_text_field($value); }
function sanitize_key($value): string { return $value; }
function sanitize_email($value): string { return $value; }
function is_email($value): bool { return filter_var($value, FILTER_VALIDATE_EMAIL) !== false; }
function get_current_user_id(): int { return $GLOBALS['case'] === 'photo-member-cooldown' ? 1 : 0; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function wp_get_current_user(): object { return (object) ['ID' => 1, 'user_email' => 'anna@example.test', 'first_name' => 'Anna', 'display_name' => 'Anna', 'last_name' => 'Jansen']; }
function get_option($key, $default = false) { return $default; }
function get_transient($key) {
    if ($GLOBALS['case'] === 'photo-ip') return 5;
    if (str_contains($GLOBALS['case'], 'cooldown')) return 1;
    return str_contains($GLOBALS['case'], 'rate') ? 10 : 0;
}
function set_transient(...$args): bool { return true; }
function add_query_arg(...$args): string {
    return is_array($args[0]) ? $args[1] . '?' . http_build_query($args[0]) : $args[2] . '?' . http_build_query([$args[0] => $args[1]]);
}
function wp_safe_redirect($url): void {
    $expected = str_starts_with($GLOBALS['case'], 'photo-') ? 'photo_share_sent=1' : (str_contains($GLOBALS['case'], 'quantity') ? 'invalid_quantity' : 'rate_limited');
    if (!str_contains($url, $expected)) throw new RuntimeException('FAIL: unexpected request result');
    $GLOBALS['redirected'] = true;
    echo "PASS: request redirects and terminates\n";
}
class Request_Test_DB {
    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($sql) { return 1; }
}
class AVPVH_DB {
    public static function get_member_by_email($email): object { return (object) ['id' => 1, 'first_name' => 'Anna', 'suffix' => '', 'last_name' => 'Jansen', 'status' => 'active', 'wp_user_id' => null]; }
}
class AVBK_DB {
    public static function create_photo_share_request($data) { return null; }
}
$wpdb = new Request_Test_DB();
$_POST = ['first_name' => 'Anna', 'last_name' => 'Jansen', 'email' => 'anna@example.test', 'phone' => '0600000000',
    'quantity' => str_contains($case, 'quantity') ? '100' : '1', 'items' => [['quantity' => str_contains($case, 'quantity') ? 100 : 1]],
    'avbk_photo_share_submit' => 1, 'photo_share_email' => 'anna@example.test'];
register_shutdown_function(function () { if (empty($GLOBALS['redirected'])) echo "FAIL: no redirect\n"; });
if (str_starts_with($case, 'book-')) {
    require AVBK_PLUGIN_DIR . 'includes/class-book-order.php';
    (new AVBK_Book_Order())->handle_order();
} elseif (str_starts_with($case, 'tshirt-')) {
    require AVBK_PLUGIN_DIR . 'includes/class-tshirt-order.php';
    (new AVBK_Tshirt_Order())->handle_order();
} elseif ($case === 'congress-rate') {
    require AVBK_PLUGIN_DIR . 'includes/class-congress.php';
    (new AVBK_Congress())->handle_register();
} else {
    require AVBK_PLUGIN_DIR . 'includes/class-photo-share.php';
    (new AVBK_Photo_Share())->handle_actions();
}
echo "FAIL: request did not terminate\n";
