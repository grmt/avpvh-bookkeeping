<?php
declare(strict_types=1);

/** Run: php tests/test-login-payment-popup.php. No WordPress or member data required. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_URL', 'https://example.test/plugin/');
class WP_User { public int $ID = 1; }
class AVBK_DB {
    public static function get_member_balance_excluding_closed(int $id): array {
        ++$GLOBALS['balance_queries'];
        return ['balance' => $GLOBALS['balance'], 'items' => []];
    }
    public static function get_last_processed_date(): string { return '2026-01-01'; }
}
class AVBK_QR {
    public static function remittance_for_balance(array $items, int $id): string { return 'TEST'; }
    public static function for_member_balance(int $id, float $balance, array $items): string { return ''; }
}
$options = [];
$meta = [];
$assets = [];
$balance_queries = 0;
$balance = 25.0;
function add_action(...$args): void {}
function current_user_can(string $cap): bool { return $GLOBALS['can_manage'] ?? false; }
class AVPVH_Roles {
    public static function current_user_has_role(string $role): bool { return false; }
}
class AVBK_Test_Redirect extends RuntimeException {}
function check_admin_referer(string $action): void {
    if (($_POST['_wpnonce'] ?? '') !== 'valid') throw new RuntimeException('Invalid nonce');
}
function wp_die(string $message, $code = null): void { throw new RuntimeException($message); }
function wp_unslash(string $value): string { return $value; }
function sanitize_text_field(string $value): string { return strip_tags($value); }
function sanitize_textarea_field(string $value): string { return strip_tags($value); }
function sanitize_email(string $value): string { return $value; }
function update_option(string $key, $value): void { $GLOBALS['options'][$key] = $value; }
function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
function wp_safe_redirect(string $url): void { throw new AVBK_Test_Redirect($url); }
function get_option(string $key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function is_user_logged_in(): bool { return true; }
function get_current_user_id(): int { return 1; }
function avpvh_get_member_by_wp_user(int $id): object { return (object) ['id' => 1, 'status' => 'active']; }
function update_user_meta(int $id, string $key, $value): void { $GLOBALS['meta'][$key] = $value; }
function delete_user_meta(int $id, string $key): void { unset($GLOBALS['meta'][$key]); }
function get_user_meta(int $id, string $key, bool $single) { return $GLOBALS['meta'][$key] ?? null; }
function wp_enqueue_style(...$args): void { $GLOBALS['assets'][] = $args[0]; }
function wp_enqueue_script(...$args): void { $GLOBALS['assets'][] = $args[0]; }
function avbk_asset_version(string $path): string { return 'test'; }
function wp_json_encode($value): string { return json_encode($value); }
function admin_url(string $path): string { return 'https://example.test/wp-admin/' . $path; }
function home_url(string $path): string { return 'https://example.test' . $path; }
function wp_create_nonce(string $name): string { return 'test-nonce'; }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_url(string $text): string { return esc_html($text); }
function esc_html_e(string $text, string $domain): void { echo esc_html($text); }
function wp_date(string $format, int $time): string { return date($format, $time); }
require dirname(__DIR__) . '/includes/class-fee-popup.php';

function check_popup(string $label, bool $ok): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . "\n";
}
function rendered_popup(AVBK_Fee_Popup $popup): string {
    ob_start();
    $popup->maybe_render_popup();
    return ob_get_clean();
}
$popup = new AVBK_Fee_Popup();
$meta[AVBK_Fee_Popup::USER_META] = 1;
$popup->enqueue_assets();
check_popup('default off suppresses an already queued popup', rendered_popup($popup) === '' && !$assets && $balance_queries === 0);
$popup->check_on_login('test-account', new WP_User());
check_popup('disabled login clears old popup flags without balance queries', !$meta && $balance_queries === 0);
$options[AVBK_Fee_Popup::ENABLED_OPTION] = 1;
$popup->check_on_login('test-account', new WP_User());
check_popup('admin opt-in queues a popup for an open balance', ($meta[AVBK_Fee_Popup::USER_META] ?? null) === 1);
$popup->enqueue_assets();
check_popup('opt-in loads the popup assets', count($assets) === 2);
check_popup('opt-in renders the existing payment dialog', str_contains(rendered_popup($popup), 'id="avbk-fee-popup"'));
$options[AVBK_Fee_Popup::ENABLED_OPTION] = 0;
$assets = [];
$balance_queries = 0;
$popup->enqueue_assets();
check_popup('switching off suppresses an existing session immediately', rendered_popup($popup) === '' && !$assets && $balance_queries === 0);
$options[AVBK_Fee_Popup::ENABLED_OPTION] = 1;
$balance = 0.0;
$popup->check_on_login('test-account', new WP_User());
check_popup('a settled balance clears the queue after opt-in', !$meta);

require dirname(__DIR__) . '/includes/class-admin.php';
$admin = (new ReflectionClass(AVBK_Admin::class))->newInstanceWithoutConstructor();
foreach ([1, 0] as $enabled) {
    $GLOBALS['can_manage'] = true;
    $_POST = ['_wpnonce' => 'valid', 'login_payment_popup_enabled' => (string) $enabled];
    try { $admin->handle_save_settings(); } catch (AVBK_Test_Redirect $redirect) {}
    check_popup('settings form saves popup state ' . $enabled, $options[AVBK_Fee_Popup::ENABLED_OPTION] === $enabled);
}
$GLOBALS['can_manage'] = false;
$_POST = ['_wpnonce' => 'valid', 'login_payment_popup_enabled' => '1'];
try {
    $admin->handle_save_settings();
    throw new RuntimeException('Unauthorized settings request was accepted');
} catch (RuntimeException $error) {
    check_popup('ordinary accounts cannot enable the popup', $error->getMessage() === 'Geen toegang.' && $options[AVBK_Fee_Popup::ENABLED_OPTION] === 0);
}
$GLOBALS['can_manage'] = true;
$_POST['_wpnonce'] = 'invalid';
try {
    $admin->handle_save_settings();
    throw new RuntimeException('Settings accepted an invalid nonce');
} catch (RuntimeException $error) {
    check_popup('settings require a valid nonce', $error->getMessage() === 'Invalid nonce' && $options[AVBK_Fee_Popup::ENABLED_OPTION] === 0);
}
