<?php
/**
 * Standalone test suite for AVBK_Photo_Share.
 * Verifies email invitation flow, 5-day expiration, confirmation link handling,
 * Google Drive subfolder naming (YYYYMMDD-firstname-lastname),
 * requested upload link display without gallery export fallbacks.
 *
 * Strictly fictitious names only (see AGENTS.md).
 */

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
    define('AVBK_PLUGIN_URL', 'https://example.test/wp-content/plugins/avpvh-bookkeeping/');
    define('DAY_IN_SECONDS', 86400);
    define('HOUR_IN_SECONDS', 3600);
    define('MINUTE_IN_SECONDS', 60);

// Mock WordPress environment
$GLOBALS['current_user_id'] = 0;
$GLOBALS['users']           = [];
$GLOBALS['usermeta']        = [];
$GLOBALS['shortcodes']      = [];
$GLOBALS['enqueued_styles'] = [];
$GLOBALS['sent_mails']      = [];
$GLOBALS['mail_success']    = true;
$GLOBALS['last_redirect']   = '';
$GLOBALS['transients']      = [];

class WP_User {
    public int $ID;
    public string $user_email;
    public string $user_login;
    public string $display_name;
    public string $first_name;
    public string $last_name;

    public function __construct(int $id, string $login, string $email, string $display_name, string $first = '', string $last = '') {
        $this->ID           = $id;
        $this->user_login   = $login;
        $this->user_email   = $email;
        $this->display_name = $display_name;
        $this->first_name   = $first ?: $display_name;
        $this->last_name    = $last;
    }
}

class Mock_Photo_Shares_WPDB {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public array $photo_shares = [];

    public function prepare(string $query, ...$args): string {
        $replaced = $query;
        foreach ($args as $arg) {
            $val = is_numeric($arg) ? $arg : "'" . addslashes((string) $arg) . "'";
            $replaced = preg_replace('/%[sdf]/', (string) $val, $replaced, 1);
        }
        return $replaced;
    }

    public function get_charset_collate(): string {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    public function insert(string $table, array $data): int {
        $this->insert_id++;
        $id = $this->insert_id;
        $row = array_merge([
            'id'                => $id,
            'status'            => 'pending_confirmation',
            'drive_folder_id'   => '',
            'drive_folder_url'  => '',
            'drive_folder_name' => '',
            'email_sent'        => 0,
            'email_error'       => '',
            'created_at'        => gmdate('Y-m-d H:i:s'),
            'confirmed_at'      => null,
        ], $data);

        $this->photo_shares[$id] = (object) $row;
        return 1;
    }

    public function update(string $table, array $data, array $where): int {
        $id = $where['id'] ?? 0;
        if (isset($this->photo_shares[$id])) {
            foreach ($data as $k => $v) {
                $this->photo_shares[$id]->$k = $v;
            }
            return 1;
        }
        return 0;
    }

    public function get_row(string $query): ?object {
        if (preg_match("/WHERE id = (\d+)/", $query, $m)) {
            return $this->photo_shares[(int) $m[1]] ?? null;
        }
        if (preg_match("/WHERE confirm_token = '([^']+)'/", $query, $m)) {
            foreach ($this->photo_shares as $s) {
                if ($s->confirm_token === $m[1]) {
                    return $s;
                }
            }
        }
        if (preg_match("/WHERE email = '([^']+)'/", $query, $m)) {
            foreach ($this->photo_shares as $s) {
                if (strcasecmp($s->email, $m[1]) === 0) {
                    return $s;
                }
            }
        }
        if (preg_match("/WHERE wp_user_id = (\d+)/", $query, $m)) {
            foreach ($this->photo_shares as $s) {
                if ((int) ($s->wp_user_id ?? 0) === (int) $m[1]) {
                    return $s;
                }
            }
        }
        return null;
    }

    public function get_results(string $query): array {
        if (str_contains($query, "status = 'pending_confirmation' AND expires_at <")) {
            preg_match("/expires_at < '([^']+)'/", $query, $m);
            $cutoff = $m[1] ?? gmdate('Y-m-d H:i:s');
            $res = [];
            foreach ($this->photo_shares as $s) {
                if ($s->status === 'pending_confirmation' && $s->expires_at < $cutoff) {
                    $res[] = $s;
                }
            }
            return $res;
        }
        return array_values($this->photo_shares);
    }
}

$GLOBALS['wpdb'] = new Mock_Photo_Shares_WPDB();

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

function get_user_by(string $field, $val): ?WP_User {
    if ($field === 'email') {
        foreach ($GLOBALS['users'] as $u) {
            if (strcasecmp($u->user_email, (string) $val) === 0) return $u;
        }
    }
    return null;
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

function sanitize_text_field(string $val): string {
    return trim(strip_tags($val));
}

function sanitize_email(string $val): string {
    return trim($val);
}

function sanitize_key(string $val): string {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($val));
}

function sanitize_title(string $val): string {
    $val = strtolower(trim($val));
    $val = preg_replace('/[^a-z0-9_\-\s]/', '', $val);
    return preg_replace('/[\s_]+/', '-', $val);
}

function is_email(string $val): bool {
    return (bool) filter_var($val, FILTER_VALIDATE_EMAIL);
}

function wp_mail(string $to, string $subject, string $body, $headers = []): bool {
    $GLOBALS['sent_mails'][] = compact('to', 'subject', 'body');
    return !empty($GLOBALS['mail_success']);
}

function wp_safe_redirect(string $url): void {
    $GLOBALS['last_redirect'] = $url;
    throw new RuntimeException("Redirect: $url");
}

function check_admin_referer(string $action = '', string $query_arg = ''): void {
    if (empty($GLOBALS['valid_nonce'])) {
        throw new RuntimeException("Invalid nonce for $action");
    }
}

function wp_nonce_field(string $action, string $name = '_wpnonce'): void {
    echo '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce">';
}

function add_query_arg(...$args): string {
    if (count($args) === 1) return '?' . http_build_query($args[0]);
    if (count($args) === 2) {
        $url = $args[1];
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . http_build_query($args[0]);
    }
    return '';
}

function remove_query_arg($keys, string $url = ''): string {
    if (!$url) return '';
    $parts = parse_url($url);
    if (!isset($parts['query'])) return $url;
    parse_str($parts['query'], $query);
    foreach ((array) $keys as $k) unset($query[$k]);
    $new_query = http_build_query($query);
    $res = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '');
    return $new_query ? $res . '?' . $new_query : $res;
}

function current_time(string $type, int $gmt = 0): string {
    return gmdate('Y-m-d H:i:s');
}

function date_i18n(string $fmt, int $ts): string {
    return date($fmt, $ts);
}

function get_bloginfo(string $show = ''): string {
    return 'AV Philips van Horne';
}

function wp_specialchars_decode(string $str, int $quote_style = ENT_NOQUOTES): string {
    return htmlspecialchars_decode($str, $quote_style);
}

function get_transient(string $key) {
    return $GLOBALS['transients'][$key] ?? false;
}

function set_transient(string $key, $val, int $ttl = 0): bool {
    $GLOBALS['transients'][$key] = $val;
    return true;
}

function avbk_asset_version(string $path): string {
    return '1.0';
}

class AVPVH_DB {
    public static function get_member_by_wp_user(int $user_id): ?object {
        return $GLOBALS['members'][$user_id] ?? null;
    }
    public static function get_member_by_email(string $email): ?object {
        foreach ($GLOBALS['members'] ?? [] as $m) {
            if (strcasecmp($m->email, $email) === 0) return $m;
        }
        return null;
    }
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

    require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';
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

    // Set up fictitious test users (see AGENTS.md)
    $GLOBALS['users'][1] = new WP_User(1, 'anna.jansen', 'anna@example.test', 'Anna Jansen', 'Anna', 'Jansen');
    $GLOBALS['users'][2] = new WP_User(2, 'bram.bakker', 'bram@example.test', 'Bram Bakker', 'Bram', 'Bakker');
    $GLOBALS['users'][3] = new WP_User(3, 'cas.de.vries', 'cas@example.test', 'Cas de Vries', 'Cas', 'de Vries');

    $GLOBALS['members'][1] = (object) ['id' => 10, 'first_name' => 'Anna', 'suffix' => '', 'last_name' => 'Jansen', 'email' => 'anna@example.test', 'status' => 'active', 'wp_user_id' => 1];
    $GLOBALS['members'][2] = (object) ['id' => 20, 'first_name' => 'Bram', 'suffix' => 'van', 'last_name' => 'Bakker', 'email' => 'bram@example.test', 'status' => 'active', 'wp_user_id' => 2];

    $service = new AVBK_Photo_Share();

    // 1. Shortcode registration
    assert_true(isset($GLOBALS['shortcodes']['avpvh_bk_photo_share']), 'Shortcode avpvh_bk_photo_share registered');

    // A gallery export is not an upload request, even when its filter is named.
    $GLOBALS['current_user_id'] = 3;
    \Avpvh\Frontend\Photo_Shares_DB::$test_shares[3] = [(object) [
        'status' => 'ready',
        'drive_folder_id' => 'gallery-export-fixture',
        'description' => 'Filteromschrijving: kampen en jaartallen',
    ]];
    $unrequested_html = $service->render_shortcode();
    assert_contains('Stuur mij een activatielink', $unrequested_html, 'Gallery export does not replace the upload request form');
    assert_not_contains('gallery-export-fixture', $unrequested_html, 'Gallery download is not shown as an upload share');
    assert_not_contains('Filteromschrijving', $unrequested_html, 'Gallery filter description is not shown');
    assert_true(AVBK_Photo_Share::get_user_share(3) === null, 'No upload share is resolved without a confirmed request');

    update_user_meta(3, 'photo_share_url', 'https://drive.google.com/drive/folders/legacy-fixture');
    update_user_meta(3, 'photo_share_folder_id', 'legacy-fixture');
    assert_true(AVBK_Photo_Share::get_user_share(3) === null, 'Stored folder metadata alone does not count as an upload request');
    assert_not_contains('legacy-fixture', $service->render_shortcode(), 'Unrequested legacy folder link is not displayed');
    $GLOBALS['current_user_id'] = 0;

    // 2. Request creation with 5-day expiration token
    $req = AVBK_DB::create_photo_share_request([
        'member_id'  => 10,
        'wp_user_id' => 1,
        'first_name' => 'Anna',
        'suffix'     => '',
        'last_name'  => 'Jansen',
        'email'      => 'anna@example.test',
    ]);
    assert_true($req !== null, 'Photo share request created');
    assert_true(strlen($req->confirm_token) === 43, 'Generated 43-character confirmation token');
    assert_true($req->status === 'pending_confirmation', 'Initial status is pending_confirmation');

    // Verify 5-day expiration
    $diff_seconds = strtotime($req->expires_at) - strtotime($req->created_at);
    assert_true($diff_seconds === 5 * 86400, 'Expiration date is exactly 5 days (432000s) from creation');

    $GLOBALS['current_user_id'] = 1;
    $pending_html = $service->render_shortcode();
    assert_contains('Verificatielink verzonden', $pending_html, 'Unconfirmed request shows its confirmation status');
    assert_not_contains('avbk-photo-share-link', $pending_html, 'Pending request does not show an upload link');
    $GLOBALS['current_user_id'] = 0;

    // 3. Email sending
    $sent = AVBK_Photo_Share::send_confirmation_email($req);
    assert_true($sent === true, 'Confirmation email dispatched');
    assert_true(count($GLOBALS['sent_mails']) === 1, 'One email recorded in sent_mails');
    $mail = $GLOBALS['sent_mails'][0];
    assert_contains('anna@example.test', $mail['to'], 'Email addressed to Anna');
    assert_contains('Activeer jouw persoonlijke fotomap', $mail['subject'], 'Subject mentions fotomap activation');
    assert_contains($req->confirm_token, $mail['body'], 'Email body contains unique confirmation token link');
    assert_contains('5 dagen geldig', $mail['body'], 'Email body warns link is 5 days valid');

    // 4. Folder naming format: YYYYMMDD-firstname-lastname
    $folder_data = AVBK_Photo_Share::create_drive_folder_for_share($req);
    $expected_folder_name = date('Ymd') . '-anna-jansen';
    assert_true($folder_data['folder_name'] === $expected_folder_name, 'Subfolder name matches YYYYMMDD-firstname-lastname format (' . $folder_data['folder_name'] . ')');

    // With suffix:
    $req_suffix = (object) ['id' => 2, 'first_name' => 'Bram', 'suffix' => 'van', 'last_name' => 'Bakker', 'email' => 'bram@example.test', 'confirm_token' => 'abc'];
    $folder_data_suffix = AVBK_Photo_Share::create_drive_folder_for_share($req_suffix);
    $expected_suffix_name = date('Ymd') . '-bram-van-bakker';
    assert_true($folder_data_suffix['folder_name'] === $expected_suffix_name, 'Subfolder with suffix matches (' . $folder_data_suffix['folder_name'] . ')');

    // 5. Confirmation link processing within 5 days
    $_GET['confirm_photo_share'] = $req->confirm_token;
    try {
        $service->handle_actions();
        assert_true(false, 'Expected redirect on confirmation');
    } catch (RuntimeException $e) {
        assert_contains('photo_share_confirmed=1', $e->getMessage(), 'Redirected to confirmation view');
    }
    unset($_GET['confirm_photo_share']);

    // Check confirmed status in database
    $confirmed_share = AVBK_DB::get_photo_share_by_id((int) $req->id);
    assert_true($confirmed_share->status === 'confirmed', 'Share status updated to confirmed');
    assert_true($confirmed_share->confirmed_at !== null, 'confirmed_at timestamp recorded');
    assert_true(!empty($confirmed_share->drive_folder_url), 'drive_folder_url stored on share row');
    assert_true(get_user_meta(1, 'photo_share_url', true) === $confirmed_share->drive_folder_url, 'User meta photo_share_url updated for user 1');

    // 6. Confirmed member view displays only the requested upload link.
    $GLOBALS['current_user_id'] = 1;
    $confirmed_html = $service->render_shortcode();
    assert_contains('href="' . $confirmed_share->drive_folder_url . '"', $confirmed_html, 'Link points to the confirmed requested folder');
    assert_contains('Open je persoonlijke uploadmap', $confirmed_html, 'Confirmed member sees a clearly labelled upload link');
    assert_true(substr_count($confirmed_html, '<a ') === 1, 'Confirmed view has exactly one link');
    assert_not_contains('<svg', $confirmed_html, 'Confirmed view has no QR code');
    assert_not_contains('avbk-photo-share-card', $confirmed_html, 'Confirmed view has no upload card');
    assert_not_contains($confirmed_share->drive_folder_name, $confirmed_html, 'Folder title is not displayed');
    assert_not_contains('<input', $confirmed_html, 'Confirmed view has no copy input');
    assert_not_contains('<button', $confirmed_html, 'Confirmed view has no extra buttons');

    // The confirmation URL also returns the same single link for a guest.
    $GLOBALS['current_user_id'] = 0;
    $_GET['photo_share_confirmed'] = 1;
    $_GET['share_token'] = $req->confirm_token;
    assert_true($service->render_shortcode() === $confirmed_html, 'Confirmed email link shows the same minimal view without login');
    unset($_GET['photo_share_confirmed'], $_GET['share_token']);
    $GLOBALS['current_user_id'] = 1;

    // 7. Expired link handling (after 5 days)
    $expired_req = AVBK_DB::create_photo_share_request([
        'member_id'  => 20,
        'wp_user_id' => 2,
        'first_name' => 'Bram',
        'suffix'     => 'van',
        'last_name'  => 'Bakker',
        'email'      => 'bram@example.test',
    ]);
    // Force expiration in past
    $GLOBALS['wpdb']->photo_shares[(int) $expired_req->id]->expires_at = gmdate('Y-m-d H:i:s', time() - 3600);

    $_GET['confirm_photo_share'] = $expired_req->confirm_token;
    try {
        $service->handle_actions();
        assert_true(false, 'Expected redirect on expired token');
    } catch (RuntimeException $e) {
        assert_contains('photo_share_expired=1', $e->getMessage(), 'Expired token redirected with photo_share_expired=1');
    }
    unset($_GET['confirm_photo_share']);

    $check_expired = AVBK_DB::get_photo_share_by_id((int) $expired_req->id);
    assert_true($check_expired->status === 'expired', 'Expired share marked as expired');

    // Expired alert rendering
    $_GET['photo_share_expired'] = 1;
    $expired_html = $service->render_shortcode();
    assert_contains('Verificatielink verlopen', $expired_html, 'Renders expired alert message');
    unset($_GET['photo_share_expired']);

    // 8. Cron cleanup of expired unconfirmed shares
    $old_pending = AVBK_DB::create_photo_share_request([
        'member_id'  => 30,
        'first_name' => 'Cas',
        'last_name'  => 'de Vries',
        'email'      => 'cas@example.test',
    ]);
    $GLOBALS['wpdb']->photo_shares[(int) $old_pending->id]->expires_at = gmdate('Y-m-d H:i:s', time() - 7200);

    $cleaned = AVBK_DB::cleanup_expired_photo_shares();
    assert_true($cleaned >= 1, 'Cleanup expired unconfirmed shares cleaned up at least 1 share');
    assert_true(AVBK_DB::get_photo_share_by_id((int) $old_pending->id)->status === 'expired', 'Old pending share status is expired after cleanup');

    // 9. Guest form submission
    $GLOBALS['current_user_id'] = 0;
    $GLOBALS['valid_nonce']     = true;
    $_POST['avbk_photo_share_submit'] = '1';
    $_POST['photo_share_email']       = 'anna@example.test';

    try {
        $service->handle_actions();
        assert_true(false, 'Expected redirect on guest submission');
    } catch (RuntimeException $e) {
        assert_contains('photo_share_sent=1', $e->getMessage(), 'Guest redirected with photo_share_sent=1');
    }
    unset($_POST['avbk_photo_share_submit'], $_POST['photo_share_email']);

    echo "\nAll AVBK_Photo_Share tests passed cleanly!\n";
}
