<?php
/**
 * Standalone test suite for AVBK_Photo_Share.
 * Verifies guest prompt, logged-in member resolution (meta, gallery, pending),
 * QR code SVG generation, hot link, and direct link display.
 *
 * Strictly fictitious names only (see AGENTS.md).
 */

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
    define('AVBK_PLUGIN_URL', 'https://example.test/wp-content/plugins/avpvh-bookkeeping/');

// Mock WordPress environment
$GLOBALS['current_user_id'] = 0;
$GLOBALS['users']           = [];
$GLOBALS['usermeta']        = [];
$GLOBALS['shortcodes']      = [];
$GLOBALS['enqueued_styles'] = [];

class WP_User {
    public int $ID;
    public string $user_email;
    public string $user_login;
    public string $display_name;

    public function __construct(int $id, string $login, string $email, string $display_name) {
        $this->ID           = $id;
        $this->user_login   = $login;
        $this->user_email   = $email;
        $this->display_name = $display_name;
    }
}

function is_user_logged_in(): bool {
    return ($GLOBALS['current_user_id'] ?? 0) > 0;
}

function get_current_user_id(): int {
    return $GLOBALS['current_user_id'] ?? 0;
}

function wp_get_current_user(): ?WP_User {
    $uid = get_current_user_id();
    return $GLOBALS['users'][$uid] ?? null;
}

function get_userdata(int $user_id): ?WP_User {
    return $GLOBALS['users'][$user_id] ?? null;
}

function get_user_meta(int $user_id, string $key, bool $single = false) {
    $val = $GLOBALS['usermeta'][$user_id][$key] ?? '';
    return $single ? $val : [$val];
}

function update_user_meta(int $user_id, string $key, $val): bool {
    $GLOBALS['usermeta'][$user_id][$key] = $val;
    return true;
}

function delete_user_meta(int $user_id, string $key): bool {
    unset($GLOBALS['usermeta'][$user_id][$key]);
    return true;
}

function add_shortcode(string $tag, callable $callback): void {
    $GLOBALS['shortcodes'][$tag] = $callback;
}

function add_action(string $tag, callable $callback): void {}

function wp_register_style(string $handle, string $src, array $deps = [], $ver = false): void {}

function wp_enqueue_style(string $handle): void {
    $GLOBALS['enqueued_styles'][] = $handle;
}

function wp_login_url(string $redirect = ''): string {
    return 'https://example.test/inloggen/' . ($redirect ? '?redirect_to=' . urlencode($redirect) : '');
}

function home_url(string $path = ''): string {
    return 'https://example.test' . $path;
}

function current_user_can(string $cap): bool {
    return true;
}

function esc_html(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string {
    return filter_var($url, FILTER_SANITIZE_URL) ?: '';
}

function esc_js(string $text): string {
    return addslashes($text);
}

function esc_url_raw(string $url): string {
    return trim($url);
}

function wp_unslash($val) {
    return is_string($val) ? stripslashes($val) : $val;
}

function avbk_asset_version(string $path): string {
    return '1.0';
}
}

// Mock Gallery Photo_Shares_DB
namespace Avpvh\Frontend {
    class Photo_Shares_DB {
        public static array $test_shares = [];

        public static function for_user(int $user_id): array {
            return self::$test_shares[$user_id] ?? [];
        }
    }
}

namespace {
    // Autoload vendor dependencies (chillerlan/php-qrcode)
    if (file_exists(AVBK_PLUGIN_DIR . 'vendor/autoload.php')) {
        require_once AVBK_PLUGIN_DIR . 'vendor/autoload.php';
    }

    require_once AVBK_PLUGIN_DIR . 'includes/class-qr.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-photo-share.php';

    // Helper assertions
    function assert_true(bool $cond, string $msg): void {
        if (!$cond) {
            echo "FAIL: {$msg}\n";
            exit(1);
        }
        echo "PASS {$msg}\n";
    }

    function assert_contains(string $needle, string $haystack, string $msg): void {
        if (!str_contains($haystack, $needle)) {
            echo "FAIL: {$msg}\nExpected to find: {$needle}\nIn: {$haystack}\n";
            exit(1);
        }
        echo "PASS {$msg}\n";
    }

    function assert_not_contains(string $needle, string $haystack, string $msg): void {
        if (str_contains($haystack, $needle)) {
            echo "FAIL: {$msg}\nExpected NOT to find: {$needle}\nIn: {$haystack}\n";
            exit(1);
        }
        echo "PASS {$msg}\n";
    }

    // Set up fictitious test users
    $GLOBALS['users'][1] = new WP_User(1, 'anna.jansen', 'anna@example.test', 'Anna Jansen');
    $GLOBALS['users'][2] = new WP_User(2, 'bram.bakker', 'bram@example.test', 'Bram Bakker');
    $GLOBALS['users'][3] = new WP_User(3, 'cas.de.vries', 'cas@example.test', 'Cas de Vries');

    $service = new AVBK_Photo_Share();

    // Test 1: Shortcodes registered
    assert_true(isset($GLOBALS['shortcodes']['avpvh_bk_photo_share']), 'Shortcode avpvh_bk_photo_share registered');
    assert_true(isset($GLOBALS['shortcodes']['avpvh_photo_share']), 'Shortcode avpvh_photo_share registered');

    // Test 2: Guest user (not logged in)
    $GLOBALS['current_user_id'] = 0;
    $guest_html = $service->render_shortcode();
    assert_contains('avbk-photo-share-auth-box', $guest_html, 'Guest sees auth prompt box');
    assert_contains('redirect_to=', $guest_html, 'Login URL includes redirect_to parameter');
    assert_not_contains('avbk-photo-share-qr-frame', $guest_html, 'Guest does not see QR code');

    // Test 3: Logged-in member with explicit user_meta 'photo_share_url'
    $GLOBALS['current_user_id'] = 1;
    $test_url = 'https://drive.google.com/drive/folders/1TEST_ANNA_FOLDER_XYZ';
    update_user_meta(1, 'photo_share_url', $test_url);

    $share = AVBK_Photo_Share::get_user_share(1);
    assert_true($share !== null, 'User 1 share resolved from meta');
    assert_true($share['url'] === $test_url, 'Share URL matches meta value');
    assert_true($share['source'] === 'meta', 'Share source identified as meta');

    $member_html = $service->render_shortcode();
    assert_contains('avbk-photo-share-card', $member_html, 'Member with share sees share card');
    assert_contains('<svg', $member_html, 'Share card includes inline SVG QR code');
    assert_contains('avbk-qr', $member_html, 'QR code has avbk-qr class');
    assert_contains($test_url, $member_html, 'Share card includes direct URL');
    assert_contains('target="_blank"', $member_html, 'Hot link opens in new tab');
    assert_contains('rel="noopener noreferrer"', $member_html, 'Hot link has secure rel attribute');
    assert_contains('Kopieer link', $member_html, 'Share card includes copy link button');
    assert_contains('Scan met je smartphone', $member_html, 'Share card includes smartphone scan instruction');

    // Test 4: Logged-in member with user_meta 'photo_share_folder_id'
    $GLOBALS['current_user_id'] = 2;
    update_user_meta(2, 'photo_share_folder_id', '1TEST_BRAM_ID_999');

    $share2 = AVBK_Photo_Share::get_user_share(2);
    assert_true($share2 !== null, 'User 2 share resolved from folder_id');
    assert_true($share2['url'] === 'https://drive.google.com/drive/folders/1TEST_BRAM_ID_999', 'Share URL constructed correctly from folder ID');

    // Test 5: Logged-in member with ready share in Gallery Photo_Shares_DB
    $GLOBALS['current_user_id'] = 3;
    \Avpvh\Frontend\Photo_Shares_DB::$test_shares[3] = [
        (object) [
            'id'              => 42,
            'status'          => 'ready',
            'drive_folder_id' => '1TEST_CAS_GALLERY_123',
            'description'     => '✓ Cas Jubileum selectie',
        ],
    ];

    $share3 = AVBK_Photo_Share::get_user_share(3);
    assert_true($share3 !== null, 'User 3 share resolved from gallery Photo_Shares_DB');
    assert_true($share3['url'] === 'https://drive.google.com/drive/folders/1TEST_CAS_GALLERY_123', 'Gallery share URL matches drive_folder_id');
    assert_true($share3['source'] === 'gallery', 'Share source identified as gallery');

    $cas_html = $service->render_shortcode();
    assert_contains('1TEST_CAS_GALLERY_123', $cas_html, 'Cas sees his gallery share folder link');
    assert_contains('<svg', $cas_html, 'Cas sees QR code SVG');

    // Test 6: Logged-in member with NO share
    $GLOBALS['users'][4]        = new WP_User(4, 'daan.meijer', 'daan@example.test', 'Daan Meijer');
    $GLOBALS['current_user_id'] = 4;

    $share4 = AVBK_Photo_Share::get_user_share(4);
    assert_true($share4 === null, 'User 4 has no share');

    $daan_html = $service->render_shortcode();
    assert_contains('avbk-photo-share-pending-box', $daan_html, 'User without share sees pending box');
    assert_contains('Daan Meijer', $daan_html, 'Pending box addresses member by display name');
    assert_contains(AVBK_Photo_Share::ROOT_FOLDER_URL, $daan_html, 'Pending box links to central Google Drive folder');
    assert_not_contains('avbk-photo-share-qr-frame', $daan_html, 'Pending box does not display QR code frame');

    // Test 7: Admin profile saving
    $_POST['avbk_photo_share_url'] = 'https://drive.google.com/drive/folders/1MANUAL_OVERRIDE_URL';
    $service->save_user_profile_field(4);
    assert_true(get_user_meta(4, 'photo_share_url', true) === 'https://drive.google.com/drive/folders/1MANUAL_OVERRIDE_URL', 'Admin save stores photo_share_url in user meta');

    // Clearing field deletes user meta
    $_POST['avbk_photo_share_url'] = '';
    $service->save_user_profile_field(4);
    assert_true(get_user_meta(4, 'photo_share_url', true) === '', 'Admin save with empty input removes user meta');

    echo "\nAll AVBK_Photo_Share tests passed cleanly!\n";
}
