<?php
/** Amount-free payment request and upload regressions; fictitious data only. */
$fixture = sys_get_temp_dir() . '/avbk-payment-fixture-' . getmypid() . '/';
define('ABSPATH', $fixture);
define('MINUTE_IN_SECONDS', 60);
define('AVBK_PLUGIN_DIR', '/var/www/html/wp-content-pvh/plugins/avpvh-bookkeeping/');
mkdir($fixture . 'wp-admin/includes', 0700, true);
foreach (['file', 'media', 'image'] as $name) { file_put_contents($fixture . 'wp-admin/includes/' . $name . '.php', '<?php'); }
class WP_Error {
    public function __construct(public string $code, private string $message) {}
    public function get_error_message() { return $this->message; }
}
class AVPVH_Roles { public static function current_user_has_role($role) { return $GLOBALS['treasurer'] ?? false; } }
class AVBK_QR { public static function svg($payload) { return '<svg data-url="' . htmlspecialchars($payload, ENT_QUOTES) . '"></svg>'; } }
class Response extends RuntimeException {}
function current_user_can($cap) { return $GLOBALS['admin'] ?? false; }
function get_current_user_id() { return 7; }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; $GLOBALS['autoload'] = $autoload; return true; }
function wp_parse_url($url, $component) { return parse_url($url, $component); }
function esc_url_raw($url) { return $url; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function wp_check_filetype_and_ext($path, $name, $mimes) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return ['ext' => $ext, 'type' => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'pdf' => 'application/pdf'][$ext] ?? 'text/plain'];
}
function media_handle_upload($name, $parent, $data, $overrides) {
    $GLOBALS['upload_calls'] = ($GLOBALS['upload_calls'] ?? 0) + 1;
    if ($GLOBALS['upload_fail'] ?? false) { return new WP_Error('upload_failed', 'fixture failure'); }
    $GLOBALS['attachments'][14] = ['url' => 'https://example.test/uploads/qr.png', 'mime' => 'image/png'];
    return 14;
}
function wp_get_attachment_url($id) { return $GLOBALS['attachments'][$id]['url'] ?? false; }
function get_post_mime_type($id) { return $GLOBALS['attachments'][$id]['mime'] ?? false; }
function get_post_meta($id, $key, $single) { return $GLOBALS['attachment_paths'][$id] ?? ''; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function add_action(...$args) {}
function add_filter($hook, $callback, $priority, $accepted = 1) { if ($hook === 'upload_dir') { $GLOBALS['upload_filter'] = $callback; } }
function remove_filter($hook, $callback, $priority) { unset($GLOBALS['upload_filter']); }
function add_shortcode(...$args) {}
function shortcode_atts($default, $attributes) { return array_merge($default, $attributes); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($key)); }
function wp_unslash($value) { return $value; }
function wp_die($message, $title, $args) { throw new Response($message, $args['response']); }
function check_admin_referer($action) {
    if (($_POST['_wpnonce'] ?? '') !== hash('sha256', $action)) { throw new Response('invalid nonce', 403); }
}
function wp_nonce_field($action) { echo '<input name="_wpnonce" value="' . hash('sha256', $action) . '">'; }
function submit_button($label) { echo '<button>' . esc_html($label) . '</button>'; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_safe_redirect($url) { throw new Response($url, 302); }
function check($condition, $message) { if (!$condition) { throw new RuntimeException('FAIL: ' . $message); } echo 'PASS: ' . $message . "\n"; }
require dirname(__DIR__) . '/includes/class-product-payment.php';
try {
    $controller = new AVBK_Product_Payment();
    check(AVBK_Product_Payment::render('book') === '', 'unconfigured products have no shared request');
    check(is_wp_error(AVBK_Product_Payment::save('tshirt', 'https://pay.example.test/shirt', [], false)), 'visitors cannot change requests');
    $GLOBALS['treasurer'] = true;
    check(AVBK_Product_Payment::save('tshirt', 'https://pay.example.test/shirt?description=shirt', [], false) === true, 'treasurer can configure clothing request');
    check($GLOBALS['autoload'] === false, 'payment settings are not autoloaded');
    $original = AVBK_Product_Payment::get('tshirt');
    foreach (['javascript:alert(1)', 'http://pay.example.test/shirt', 'https://user:password@pay.example.test/shirt', 'not a link'] as $url) {
        check(is_wp_error(AVBK_Product_Payment::save('tshirt', $url, [], false)), 'invalid payment URL rejected');
        check(AVBK_Product_Payment::get('tshirt') === $original, 'invalid URL preserves previous request');
    }
    check(is_wp_error(AVBK_Product_Payment::save('other', '', [], false)), 'unknown product rejected');
    check(AVBK_Product_Payment::save('book', 'https://pay.example.test/book', [], false) === true, 'book request configured separately');
    $GLOBALS['treasurer'] = false;
    $html = AVBK_Product_Payment::render('tshirt', 42.50, 123);
    check(str_contains($html, '42,50') && str_contains($html, '#123'), 'remaining amount and order number displayed');
    check(str_contains($html, 'href="https://pay.example.test/shirt?description=shirt"'), 'provider URL retained without injecting amount');
    check(str_contains($html, 'data-url="https://pay.example.test/shirt?description=shirt"'), 'generated QR contains payment URL');
    check(!str_contains($html, 'pay.example.test/book'), 'product requests remain separate');
    check(str_contains($controller->shortcode(['product' => 'book']), 'uit je bestelbevestiging'), 'public payment page asks for amount from confirmation');
    check($controller->shortcode(['product' => 'unknown']) === '', 'unknown product shortcode returns no request');

    $GLOBALS['treasurer'] = true;
    $path = $fixture . 'upload.png';
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG3cAAAAASUVORK5CYII='));
    $file = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => 'request.png'];
    check(AVBK_Product_Payment::save('tshirt', $original['url'], $file, false) === true, 'valid image upload accepted for treasurer');
    check(AVBK_Product_Payment::get('tshirt')['attachment_id'] === 14, 'upload saved with product request');
    check(!isset($GLOBALS['upload_filter']), 'upload override removed after successful upload');
    $dirs = ['basedir' => '/var/www/html/wp-content/uploads', 'baseurl' => 'https://example.test/wp-content/uploads', 'subdir' => '/private/2026/10', 'error' => 'old private path error'];
    $public = AVBK_Product_Payment::public_upload_dir($dirs);
    check($public['path'] === '/var/www/html/wp-content-pvh/uploads/public/payment-requests/2026/10', 'payment uploads use actual public content volume');
    check($public['url'] === 'https://example.test/wp-content/uploads/public/payment-requests/2026/10' && !$public['error'], 'payment URL uses public media route');
    check(AVBK_Product_Payment::public_upload_dir($public) === $public, 'public upload path override is idempotent');
    $GLOBALS['attachment_paths'][14] = 'public/payment-requests/2026/10/qr.png';
    check(AVBK_Product_Payment::public_file_path('/incorrect/uploads/qr.png', 14) === '/var/www/html/wp-content-pvh/uploads/public/payment-requests/2026/10/qr.png', 'attachment management resolves actual public file');
    $GLOBALS['attachment_paths'][14] = 'private/2026/10/photo.png';
    check(AVBK_Product_Payment::public_file_path('/protected/photo.png', 14) === '/protected/photo.png', 'private attachment path preserved');
    $GLOBALS['attachment_paths'][14] = 'public/payment-requests/../../private/photo.png';
    check(AVBK_Product_Payment::public_file_path('/original/photo.png', 14) === '/original/photo.png', 'attachment path traversal rejected');
    $html = AVBK_Product_Payment::render('tshirt', 21.00);
    check(str_contains($html, '/uploads/qr.png') && !str_contains($html, '<svg'), 'uploaded image replaces generated link QR');
    $before_upload_error = AVBK_Product_Payment::get('tshirt');
    $GLOBALS['upload_fail'] = true;
    check(is_wp_error(AVBK_Product_Payment::save('tshirt', 'https://pay.example.test/replacement', $file, true)), 'storage error reported');
    check(AVBK_Product_Payment::get('tshirt') === $before_upload_error, 'failed upload preserves previous link and image');
    check(!isset($GLOBALS['upload_filter']), 'upload override removed after failed upload');
    $GLOBALS['upload_fail'] = false;
    $calls = $GLOBALS['upload_calls'];
    file_put_contents($path, '<?php echo "fictional";');
    check(is_wp_error(AVBK_Product_Payment::save('tshirt', $original['url'], $file, false)), 'disguised script is rejected as an image');
    $file['name'] = 'request.pdf';
    check(is_wp_error(AVBK_Product_Payment::save('tshirt', $original['url'], $file, false)), 'disguised script is rejected as PDF');
    file_put_contents($path, str_repeat('x', 3 * 1024 * 1024 + 1));
    check(is_wp_error(AVBK_Product_Payment::save('tshirt', $original['url'], $file, false)), 'oversize upload rejected');
    check($GLOBALS['upload_calls'] === $calls, 'rejected files never reach media upload');
    $file['error'] = UPLOAD_ERR_INI_SIZE;
    check(is_wp_error(AVBK_Product_Payment::save('tshirt', $original['url'], $file, false)), 'PHP upload failure rejected');
    $GLOBALS['attachments'][14] = ['url' => 'https://example.test/uploads/request.pdf', 'mime' => 'application/pdf'];
    check(str_contains(AVBK_Product_Payment::render('tshirt'), 'Bekijk het betaalverzoek (PDF)'), 'PDF upload offered as document');
    check(AVBK_Product_Payment::save('tshirt', $original['url'], [], true) === true, 'upload can be removed from product');
    check(AVBK_Product_Payment::get('tshirt')['attachment_id'] === 0 && AVBK_Product_Payment::get('tshirt')['url'] === $original['url'], 'upload removal retains payment link');

    ob_start(); AVBK_Product_Payment::render_settings('book', 'Boeken'); $settings = ob_get_clean();
    check(str_contains($settings, 'multipart/form-data') && str_contains($settings, 'name="qr_upload"'), 'settings offer native upload form without JavaScript');
    $GLOBALS['treasurer'] = false;
    ob_start(); AVBK_Product_Payment::render_settings('book', 'Boeken'); $settings = ob_get_clean();
    check($settings === '', 'settings hidden from visitors');
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['product' => 'book', 'payment_url' => 'https://pay.example.test/new-book'];
    try { $controller->handle_save(); } catch (Response $response) { check($response->getCode() === 403, 'controller checks financial permissions'); }
    $GLOBALS['treasurer'] = true; $_SERVER['REQUEST_METHOD'] = 'GET';
    try { $controller->handle_save(); } catch (Response $response) { check($response->getCode() === 405, 'controller rejects GET'); }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    try { $controller->handle_save(); } catch (Response $response) { check($response->getCode() === 403, 'controller requires nonce'); }
    $_POST['_wpnonce'] = hash('sha256', 'avbk_save_product_payment_tshirt');
    try { $controller->handle_save(); } catch (Response $response) { check($response->getCode() === 403, 'nonce is bound to product'); }
    $_POST['_wpnonce'] = hash('sha256', 'avbk_save_product_payment_book');
    try { $controller->handle_save(); } catch (Response $response) { check($response->getCode() === 302 && str_contains($response->getMessage(), 'product_payment_saved=1'), 'confirmed save redirects to product settings'); }
    check(AVBK_Product_Payment::get('book')['url'] === 'https://pay.example.test/new-book', 'controller saves selected product');
    echo "All product payment tests passed.\n";
} finally {
    foreach (glob($fixture . 'wp-admin/includes/*') as $file) { unlink($file); }
    if (is_file($fixture . 'upload.png')) { unlink($fixture . 'upload.png'); }
    rmdir($fixture . 'wp-admin/includes'); rmdir($fixture . 'wp-admin'); rmdir($fixture);
}
