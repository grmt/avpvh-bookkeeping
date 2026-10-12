<?php
/** Upload and Picker regressions, using only fictitious users and bytes. */
namespace Avpvh\Frontend {
    class Share_Drive {
        public static bool $enabled = false;
        public static $service;
        public static function has_account(): bool { return self::$enabled; }
        public static function drive() { return self::$service; }
    }
}
namespace Avpvh\Vendor\Google\Service\Drive {
    class DriveFile {
        public array $data;
        public function __construct(array $data) { $this->data = $data; }
    }
}
namespace {
    require __DIR__ . '/test-photo-share.php';
    class Photo_JSON_Response extends Exception {
        public array $data; public bool $success; public int $status;
        public function __construct(array $data, bool $success, int $status) {
            $this->data = $data; $this->success = $success; $this->status = $status;
        }
    }
    class Photo_Redirect extends Exception {}
    function wp_send_json_success(array $data): void { throw new Photo_JSON_Response($data, true, 200); }
    function wp_send_json_error(array $data, int $status): void { throw new Photo_JSON_Response($data, false, $status); }
    function check_ajax_referer(...$args): bool { return !empty($GLOBALS['valid_nonce']); }
    function wp_get_session_token(): string { return $GLOBALS['session_token'] ?? 'fixture-session'; }
    function wp_validate_auth_cookie(...$args): int { return $GLOBALS['cookie_user_id'] ?? 0; }
    function wp_set_current_user(int $id): void { $GLOBALS['current_user_id'] = $id; }
    function wp_parse_url(string $url) { return parse_url($url); }
    function rest_url(string $path): string { return home_url('/wp-json/' . $path); }
    function delete_transient(string $key): void { unset($GLOBALS['transients'][$key]); }
    function wp_redirect(string $url): void { throw new Photo_Redirect($url); }
    function sanitize_file_name(string $name): string { return basename(str_replace('\\', '/', $name)); }
    function wp_get_image_mime(string $path) { return @getimagesize($path)['mime'] ?? false; }
    function wp_json_encode($value): string { return json_encode($value); }
    function is_wp_error($value): bool { return false; }
    function wp_remote_retrieve_response_code(array $response): int { return $response['status']; }
    function wp_remote_retrieve_body(array $response): string { return $response['body']; }
    function wp_remote_request(string $url, array $args): array {
        $GLOBALS['http_calls'][] = compact('url', 'args');
        $next = array_shift($GLOBALS['http_queue']);
        if (!$next) throw new LogicException('Unexpected HTTP call: ' . $url);
        return ['status' => $next['status'] ?? 200, 'body' => json_encode($next['json'] ?? [])];
    }
    function wp_remote_post(string $url, array $args): array { return wp_remote_request($url, $args); }
    function wp_tempnam(string $name): string { return tempnam(sys_get_temp_dir(), $name); }
    function wp_delete_file(string $path): void { unlink($path); }
    function wp_safe_remote_get(string $url, array $args): array {
        $GLOBALS['download_calls'][] = compact('url', 'args');
        file_put_contents($args['filename'], $GLOBALS['png_bytes']);
        return ['status' => 200];
    }
    function add_option(string $key, $value, ...$args): bool {
        if (isset($GLOBALS['options'][$key])) return false;
        $GLOBALS['options'][$key] = $value; return true;
    }
    function delete_option(string $key): void { unset($GLOBALS['options'][$key]); }
    class Photo_Drive_Files {
        public array $creates = []; public bool $wrong_parent = false;
        public function get(string $id, array $args) {
            return new class($this->wrong_parent) {
                public bool $wrong;
                public function __construct(bool $wrong) { $this->wrong = $wrong; }
                public function getTrashed(): bool { return false; }
                public function getMimeType(): string { return 'application/vnd.google-apps.folder'; }
                public function getParents(): array { return [$this->wrong ? 'different-parent' : AVBK_Photo_Share::ROOT_FOLDER_ID]; }
            };
        }
        public function listFiles(array $args) { return new class { public function getFiles(): array { return []; } }; }
        public function create($metadata, array $args) {
            $this->creates[] = ['metadata' => $metadata->data, 'args' => $args];
            return new class { public function getId(): string { return 'copied-fixture'; } };
        }
    }
    class Photo_REST_Request {
        private array $params;
        public function __construct(array $params) { $this->params = $params; }
        public function get_param(string $key) { return $this->params[$key] ?? null; }
        public function get_route(): string { return '/avpvh/v1/oauth/google/callback'; }
    }
    function photo_ajax(callable $callback): Photo_JSON_Response {
        try { $callback(); } catch (Photo_JSON_Response $e) { return $e; }
        throw new LogicException('Expected AJAX JSON response');
    }
    function photo_reject(callable $callback, string $message): void {
        try { $callback(); assert_true(false, $message); }
        catch (RuntimeException $e) { assert_true(true, $message); }
    }
    function photo_queue(array $json, int $status = 200): void { $GLOBALS['http_queue'][] = compact('json', 'status'); }
    function photo_start(AVBK_Google_Photos_Picker $picker): array {
        $response = photo_ajax([$picker, 'ajax_start']);
        parse_str(parse_url($response->data['url'], PHP_URL_QUERY), $params);
        return $params;
    }
    $picker = new AVBK_Google_Photos_Picker(); $upload = new AVBK_Photo_Upload();
    $GLOBALS['http_queue'] = []; $GLOBALS['http_calls'] = []; $GLOBALS['download_calls'] = [];
    $GLOBALS['png_bytes'] = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6bV0AAAAASUVORK5CYII=');
    $path = tempnam(sys_get_temp_dir(), 'avbk-photo-test'); file_put_contents($path, $GLOBALS['png_bytes']);
    assert_true(AVBK_Photo_Upload::validate($path, 'foto.PNG')['mime'] === 'image/png', 'PNG bytes accepted regardless of extension case');
    photo_reject(static fn() => AVBK_Photo_Upload::validate($path, 'renamed.jpg'), 'Mismatched extension rejected');
    photo_reject(static fn() => AVBK_Photo_Upload::validate($path, 'photo.png', 4), 'Oversized photo rejected');
    photo_reject(static fn() => AVBK_Photo_Upload::validate($path, 'photo.svg'), 'SVG excluded');
    file_put_contents($path, '<?php echo "not an image";');
    photo_reject(static fn() => AVBK_Photo_Upload::validate($path, 'photo.jpg'), 'Non-image renamed JPEG rejected');
    file_put_contents($path, $GLOBALS['png_bytes']);
    assert_true(AVBK_Photo_Upload::limit() === 25 * 1024 * 1024, 'Photo cap stays 25 MB');
    $GLOBALS['current_user_id'] = 0;
    assert_true(photo_ajax([$upload, 'upload'])->status === 401, 'Guest upload rejected');
    assert_true(photo_ajax([$picker, 'ajax_start'])->status === 401, 'Guest Picker rejected');
    $GLOBALS['current_user_id'] = 1; $GLOBALS['valid_nonce'] = false;
    assert_true(photo_ajax([$upload, 'upload'])->status === 403, 'Missing nonce rejected');
    $GLOBALS['valid_nonce'] = true; $GLOBALS['current_user_id'] = 3;
    assert_true(photo_ajax([$picker, 'ajax_start'])->status === 403, 'No confirmed share means no Picker');
    $GLOBALS['current_user_id'] = 1;
    photo_reject(static fn() => AVBK_Photo_Upload::own_share(), 'Placeholder folder is not writable');
    $GLOBALS['wpdb']->photo_shares[(int) $req->id]->drive_folder_id = 'own-folder';
    $_FILES['photo'] = ['tmp_name' => $path, 'name' => 'photo.png', 'error' => UPLOAD_ERR_OK];
    assert_true(photo_ajax([$upload, 'upload'])->status === 400, 'Forged local tmp path rejected'); unset($_FILES['photo']);
    \Avpvh\Frontend\Share_Drive::$enabled = true;
    $files = new Photo_Drive_Files(); \Avpvh\Frontend\Share_Drive::$service = (object) ['files' => $files];
    $_POST['folder_id'] = 'someone-else'; AVBK_Photo_Upload::store($path, 'photo.png');
    assert_true($files->creates[0]['metadata']['parents'] === ['own-folder'], 'Submitted folder ignored; current user destination used');
    assert_true($files->creates[0]['args']['data'] === $GLOBALS['png_bytes'], 'Original photo bytes sent to Drive');
    $files->wrong_parent = true;
    photo_reject(static fn() => AVBK_Photo_Upload::store($path, 'photo.png'), 'Folder outside anniversary root rejected'); $files->wrong_parent = false;
    foreach (['http://lh3.googleusercontent.com/a', 'https://googleusercontent.com.evil.test/a', 'https://evil.test/a', 'https://user@lh3.googleusercontent.com/a', 'https://lh3.googleusercontent.com:443/a'] as $url) {
        assert_true(!AVBK_Google_Photos_Picker::trusted_url($url, 'googleusercontent.com'), 'Untrusted download URL rejected');
    }
    assert_true(AVBK_Google_Photos_Picker::trusted_url('https://lh3.googleusercontent.com/a', 'googleusercontent.com'), 'Google media host accepted');
    assert_true($picker->oauth_callback('normal-login', null, new Photo_REST_Request(['state' => 'login-fixture'])) === 'normal-login', 'Ordinary Google sign-in left intact');
    $oauth = photo_start($picker);
    assert_true($oauth['scope'] === 'https://www.googleapis.com/auth/photospicker.mediaitems.readonly', 'Only selected-photos scope requested');
    assert_true($oauth['redirect_uri'] === rest_url('avpvh/v1/oauth/google/callback'), 'Existing callback reused');
    assert_true($oauth['code_challenge_method'] === 'S256', 'PKCE used');
    $GLOBALS['cookie_user_id'] = 2;
    try { $picker->oauth_callback(null, null, new Photo_REST_Request(['state' => $oauth['state'], 'code' => 'fake-code'])); }
    catch (RuntimeException $e) { assert_contains('google_photos_error', $e->getMessage(), 'Different user callback rejected'); }
    assert_true(count($GLOBALS['http_calls']) === 0, 'Wrong user cannot exchange code');
    $GLOBALS['cookie_user_id'] = 1; $oauth = photo_start($picker); $GLOBALS['session_token'] = 'other-session';
    try { $picker->oauth_callback(null, null, new Photo_REST_Request(['state' => $oauth['state'], 'code' => 'fake-code'])); }
    catch (RuntimeException $e) { assert_contains('google_photos_error', $e->getMessage(), 'Same user in different session rejected'); }
    unset($GLOBALS['session_token']); $oauth = photo_start($picker);
    photo_queue(['access_token' => 'fixture-token', 'expires_in' => 3600, 'scope' => $oauth['scope']]);
    photo_queue(['id' => 'picker-session', 'pickerUri' => 'https://photos.google.com/picker/fixture', 'pollingConfig' => ['pollInterval' => '3.5s', 'timeoutIn' => '600s']]);
    $GLOBALS['current_user_id'] = 0;
    try { $picker->oauth_callback(null, null, new Photo_REST_Request(['state' => $oauth['state'], 'code' => 'fake-code'])); }
    catch (Photo_Redirect $e) { assert_true($e->getMessage() === 'https://photos.google.com/picker/fixture/autoclose', 'Picker opens with automatic tab close'); }
    assert_true(get_current_user_id() === 1, 'Validated login cookie restores user after REST reset');
    assert_true($GLOBALS['http_calls'][0]['args']['body']['code_verifier'] !== '', 'Server-held verifier used in code exchange');
    $GLOBALS['current_user_id'] = 2;
    assert_true(photo_ajax([$picker, 'ajax_status'])->status === 403, 'Other user cannot read selected photos'); $GLOBALS['current_user_id'] = 1;
    photo_queue(['mediaItemsSet' => false, 'pollingConfig' => ['pollInterval' => '3.5s', 'timeoutIn' => '60s']]);
    $waiting = photo_ajax([$picker, 'ajax_status']);
    assert_true(!$waiting->data['ready'] && $waiting->data['interval'] === 3.5, 'Google polling interval honored');
    $calls = count($GLOBALS['http_calls']); photo_ajax([$picker, 'ajax_status']);
    assert_true(count($GLOBALS['http_calls']) === $calls, 'Rapid focus events do not hammer Google');
    $session_key = 'avbk_photos_' . hash('sha256', '1:fixture-session'); $GLOBALS['transients'][$session_key]['next_poll'] = 0;
    photo_queue(['mediaItemsSet' => true]);
    photo_queue(['mediaItems' => [
        ['id' => 'photo-1', 'type' => 'PHOTO', 'mediaFile' => ['filename' => 'phone.heic', 'mimeType' => 'image/heic', 'baseUrl' => 'https://lh3.googleusercontent.com/photo-1']],
        ['id' => 'video-1', 'type' => 'VIDEO', 'mediaFile' => ['filename' => 'movie.mp4', 'mimeType' => 'video/mp4']],
    ], 'nextPageToken' => 'page2']);
    photo_queue(['mediaItems' => [['id' => 'photo-2', 'type' => 'PHOTO', 'mediaFile' => ['filename' => 'second.png', 'mimeType' => 'image/png', 'baseUrl' => 'https://lh3.googleusercontent.com/photo-2']]]]);
    $selected = photo_ajax([$picker, 'ajax_status']);
    assert_true($selected->data['ready'] && count($selected->data['items']) === 2 && $selected->data['skipped'] === 1, 'Pagination handled; video excluded');
    assert_not_contains('googleusercontent', json_encode($selected->data), 'Media URLs kept on server');
    assert_not_contains('fixture-token', json_encode($selected->data), 'Bearer token kept on server');
    $_POST['index'] = 99; assert_true(!photo_ajax([$picker, 'ajax_import'])->success, 'Unselected item rejected');
    $_POST['index'] = 0; $imported = photo_ajax([$picker, 'ajax_import']);
    assert_true($imported->success && $imported->data['name'] === 'phone.png', 'Google-converted photo gets actual extension');
    $download = $GLOBALS['download_calls'][0];
    assert_true($download['url'] === 'https://lh3.googleusercontent.com/photo-1=d', 'Original-download parameter used');
    assert_true($download['args']['headers']['Authorization'] === 'Bearer fixture-token', 'Media download authenticated');
    assert_true($download['args']['redirection'] === 0, 'Token cannot follow cross-host redirects');
    assert_true(!file_exists($download['args']['filename']), 'Downloaded temp file removed');
    $count = count($files->creates); photo_ajax([$picker, 'ajax_import']);
    assert_true(count($files->creates) === $count, 'Retry cannot duplicate a copied photo');
    photo_queue([]); assert_true(photo_ajax([$picker, 'ajax_finish'])->success, 'Selection cleanup works');
    assert_true(!get_transient($session_key), 'Stored media and token cleared');
    assert_true(end($GLOBALS['http_calls'])['args']['method'] === 'DELETE', 'Remote Picker session deleted');
    unlink($path); echo "\nAll photo upload and Picker regressions passed.\n";
}
