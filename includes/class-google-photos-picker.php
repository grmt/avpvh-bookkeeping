<?php
defined('ABSPATH') || exit;

/** User-consented Google Photos selections, bound to the current WordPress session. */
class AVBK_Google_Photos_Picker {
    private const SCOPE = 'https://www.googleapis.com/auth/photospicker.mediaitems.readonly';
    private const API = 'https://photospicker.googleapis.com/v1/';
    private const STATE_PREFIX = 'avbk-photos-';
    private const TTL = 3600;

    public function __construct() {
        foreach (['start', 'status', 'import', 'finish'] as $action) {
            add_action('wp_ajax_avbk_google_photos_' . $action, [$this, 'ajax_' . $action]);
            add_action('wp_ajax_nopriv_avbk_google_photos_' . $action, [$this, 'ajax_' . $action]);
        }
        // Reuse the website's registered Google redirect URI, keeping normal login intact.
        add_filter('rest_pre_dispatch', [$this, 'oauth_callback'], 10, 3);
    }

    public static function configured(): bool {
        return (string) get_option('avpvh_oauth_google_client_id', '') !== ''
            && (string) get_option('avpvh_oauth_google_client_secret', '') !== '';
    }

    private static function key(): string {
        return 'avbk_photos_' . hash('sha256', get_current_user_id() . ':' . wp_get_session_token());
    }

    private static function save(array $session): void {
        set_transient(self::key(), $session, self::TTL);
    }

    private static function session(): array {
        $session = get_transient(self::key());
        if (!is_array($session)) {
            throw new RuntimeException('Deze selectie is verlopen. Kies opnieuw uit Google Photos.');
        }
        return $session;
    }

    private static function json_response($response): array {
        $json = !is_wp_error($response) ? json_decode(wp_remote_retrieve_body($response), true) : null;
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) < 200
            || wp_remote_retrieve_response_code($response) >= 300) {
            foreach ($json['error']['details'] ?? [] as $detail) {
                if (($detail['reason'] ?? '') === 'SERVICE_DISABLED') {
                    throw new RuntimeException('Google Photos is nog niet geactiveerd op deze website. Gebruik voorlopig ‘Foto’s van je toestel’.');
                }
            }
            throw new RuntimeException('Google Photos is niet bereikbaar of de toestemming is verlopen. Probeer opnieuw.');
        }
        if (!is_array($json)) {
            throw new RuntimeException('Google Photos gaf geen geldige reactie. Probeer opnieuw.');
        }
        return $json;
    }

    private static function api(array $session, string $path, string $method = 'GET', ?array $body = null): array {
        return self::json_response(wp_remote_request(self::API . $path, [
            'method' => $method, 'timeout' => 30, 'redirection' => 0,
            'headers' => ['Authorization' => 'Bearer ' . $session['token'], 'Content-Type' => 'application/json'],
            'body' => $body === null ? null : wp_json_encode($body),
        ]));
    }

    private static function create_session(array $session): array {
        $picker = self::api($session, 'sessions', 'POST', ['pickingConfig' => ['maxItemCount' => '100']]);
        $url = (string) ($picker['pickerUri'] ?? '');
        if (empty($picker['id']) || !self::trusted_url($url, 'photos.google.com')) {
            throw new RuntimeException('Google Photos kon geen selectie openen. Probeer opnieuw.');
        }
        $session['id'] = (string) $picker['id'];
        $session['url'] = rtrim($url, '/') . '/autoclose';
        $session['interval'] = max(1, (float) ($picker['pollingConfig']['pollInterval'] ?? 5));
        $session['deadline'] = time() + max(0, (float) ($picker['pollingConfig']['timeoutIn'] ?? 600));
        $session['expires'] = min($session['expires'], strtotime($picker['expireTime'] ?? '') ?: time() + self::TTL);
        self::save($session);
        return $session;
    }

    public static function trusted_url(string $url, string $domain): bool {
        $parts = wp_parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        return ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['port']) && ($host === $domain || str_ends_with($host, '.' . $domain));
    }

    public function ajax_start(): void {
        AVBK_Photo_Upload::authorize();
        if (!self::configured()) {
            wp_send_json_error(['message' => 'Google Photos is nog niet gekoppeld.'], 503);
        }
        // Cancel an earlier selection before issuing another authorization request.
        self::cleanup();
        $state = self::STATE_PREFIX . bin2hex(random_bytes(24));
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        set_transient('avbk_photos_state_' . hash('sha256', $state), [
            'user_id' => get_current_user_id(), 'session_hash' => hash('sha256', wp_get_session_token()), 'verifier' => $verifier,
        ], 600);
        self::save(['pending' => true, 'state_key' => 'avbk_photos_state_' . hash('sha256', $state), 'expires' => time() + 600]);
        $url = add_query_arg([
            'client_id' => get_option('avpvh_oauth_google_client_id'),
            'redirect_uri' => rest_url('avpvh/v1/oauth/google/callback'),
            'response_type' => 'code', 'scope' => self::SCOPE, 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'access_type' => 'online', 'prompt' => 'select_account',
        ], 'https://accounts.google.com/o/oauth2/v2/auth');
        wp_send_json_success(['url' => $url]);
    }

    /** Only our own states are intercepted; every ordinary sign-in keeps its original callback. */
    public function oauth_callback($result, $server, $request) {
        $state = (string) $request->get_param('state');
        if ($request->get_route() !== '/avpvh/v1/oauth/google/callback' || !str_starts_with($state, self::STATE_PREFIX)) {
            return $result;
        }
        $state_key = 'avbk_photos_state_' . hash('sha256', $state);
        $owner = get_transient($state_key);
        delete_transient($state_key);
        // Plain REST OAuth redirects carry the login cookie, but no wp_rest nonce.
        $user_id = wp_validate_auth_cookie('', 'logged_in');
        if (!$user_id || !is_array($owner) || (int) $owner['user_id'] !== (int) $user_id
            || !hash_equals($owner['session_hash'], hash('sha256', wp_get_session_token()))) {
            wp_safe_redirect(add_query_arg('google_photos_error', 'expired', home_url('/foto-delen/')));
            exit;
        }
        wp_set_current_user($user_id);
        try {
            AVBK_Photo_Upload::own_share();
            $current = self::session();
            if (($current['state_key'] ?? '') !== $state_key || $request->get_param('error') || !$request->get_param('code')) {
                throw new RuntimeException('De toestemming voor Google Photos is geannuleerd. Kies opnieuw als je foto’s wilt delen.');
            }
            $token = self::json_response(wp_remote_post('https://oauth2.googleapis.com/token', [
                'timeout' => 30, 'redirection' => 0,
                'body' => ['code' => (string) $request->get_param('code'),
                    'client_id' => get_option('avpvh_oauth_google_client_id'),
                    'client_secret' => get_option('avpvh_oauth_google_client_secret'),
                    'redirect_uri' => rest_url('avpvh/v1/oauth/google/callback'),
                    'grant_type' => 'authorization_code', 'code_verifier' => $owner['verifier']],
            ]));
            if (empty($token['access_token']) || !in_array(self::SCOPE, explode(' ', $token['scope'] ?? ''), true)) {
                throw new RuntimeException('Geef Google toestemming om de geselecteerde foto’s te delen.');
            }
            $session = self::create_session(['token' => $token['access_token'], 'expires' => time() + min(self::TTL, (int) ($token['expires_in'] ?? self::TTL))]);
            // Host checked against Google's picker domain above. No bearer token in this URL.
            wp_redirect($session['url']);
            exit;
        } catch (RuntimeException $e) {
            self::save(['error' => $e->getMessage(), 'expires' => time() + 600]);
            wp_safe_redirect(add_query_arg('google_photos_error', 'failed', home_url('/foto-delen/')));
            exit;
        }
    }

    public function ajax_status(): void {
        AVBK_Photo_Upload::authorize();
        try {
            $session = self::session();
            if (!empty($session['error'])) {
                throw new RuntimeException($session['error']);
            }
            if (time() >= $session['expires']) {
                throw new RuntimeException('Deze selectie is verlopen. Kies opnieuw uit Google Photos.');
            }
            if (!empty($session['pending'])) {
                wp_send_json_success(['ready' => false, 'interval' => 5, 'timeout' => max(0, $session['expires'] - time())]);
            }
            if (!isset($session['items'])) {
                // Honor Google's poll interval on the server too, including repeated tab focus events.
                if (time() < ($session['next_poll'] ?? 0)) {
                    wp_send_json_success(['ready' => false, 'url' => $session['url'], 'interval' => $session['interval'], 'timeout' => max(0, $session['deadline'] - time())]);
                }
                $picker = self::api($session, 'sessions/' . rawurlencode($session['id']));
                if (empty($picker['mediaItemsSet'])) {
                    $session['interval'] = max(1, (float) ($picker['pollingConfig']['pollInterval'] ?? $session['interval']));
                    $session['deadline'] = time() + max(0, (float) ($picker['pollingConfig']['timeoutIn'] ?? 0));
                    $session['next_poll'] = time() + (int) ceil($session['interval']);
                    self::save($session);
                    wp_send_json_success(['ready' => false, 'url' => $session['url'], 'interval' => $session['interval'], 'timeout' => max(0, $session['deadline'] - time())]);
                }
                $items = []; $skipped = 0; $page = ''; $pages = 0;
                do {
                    if (++$pages > 10) {
                        throw new RuntimeException('De selectie kon niet worden opgehaald. Kies maximaal 100 foto’s opnieuw.');
                    }
                    $response = self::api($session, 'mediaItems?' . http_build_query(['sessionId' => $session['id'], 'pageSize' => 100, 'pageToken' => $page]));
                    foreach ($response['mediaItems'] ?? [] as $item) {
                        if (($item['type'] ?? '') !== 'PHOTO' || !isset(AVBK_Photo_Upload::TYPES[$item['mediaFile']['mimeType'] ?? ''])) {
                            $skipped++; continue;
                        }
                        if (empty($item['id']) || !self::trusted_url((string) ($item['mediaFile']['baseUrl'] ?? ''), 'googleusercontent.com')) {
                            throw new RuntimeException('Google Photos gaf geen geldige foto terug. Kies opnieuw.');
                        }
                        $items[] = $item;
                    }
                    $page = (string) ($response['nextPageToken'] ?? '');
                    if (count($items) + $skipped > 100 || ($page !== '' && count($items) + $skipped >= 100)) {
                        throw new RuntimeException('Kies maximaal 100 foto’s per keer.');
                    }
                } while ($page !== '');
                $session['items'] = $items;
                $session['skipped'] = $skipped;
                self::save($session);
            }
            wp_send_json_success(['ready' => true, 'skipped' => $session['skipped'], 'items' => array_map(static function ($item) {
                return ['name' => sanitize_file_name($item['mediaFile']['filename'] ?? 'foto')];
            }, $session['items'])]);
        } catch (RuntimeException $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }

    public function ajax_import(): void {
        AVBK_Photo_Upload::authorize();
        $lock = self::key() . '_lock';
        if (!add_option($lock, time(), '', false)) {
            if ((int) get_option($lock) < time() - 300) {
                delete_option($lock);
            }
            wp_send_json_error(['message' => 'Een foto wordt nog opgeslagen. Probeer zo opnieuw.'], 409);
        }
        $path = ''; $error = ''; $name = '';
        try {
            $session = self::session();
            if (time() >= $session['expires']) {
                throw new RuntimeException('Deze selectie is verlopen. Kies opnieuw uit Google Photos.');
            }
            $index = filter_var(wp_unslash($_POST['index'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $item = $index !== false ? ($session['items'][$index] ?? null) : null;
            if (!$item) {
                throw new RuntimeException('Kies eerst foto’s in Google Photos.');
            }
            $name = sanitize_file_name($item['mediaFile']['filename'] ?? 'foto');
            if (empty($session['copied'][$index])) {
                $url = $item['mediaFile']['baseUrl'] . '=d';
                if (!self::trusted_url($url, 'googleusercontent.com')) {
                    throw new RuntimeException('Deze foto kan niet worden opgehaald. Kies opnieuw.');
                }
                $path = wp_tempnam('avbk-photo');
                if (!$path) {
                    throw new RuntimeException('Er is tijdelijk geen ruimte om de foto op te slaan.');
                }
                $response = wp_safe_remote_get($url, [
                    'headers' => ['Authorization' => 'Bearer ' . $session['token']],
                    'timeout' => 60, 'redirection' => 0, 'stream' => true, 'filename' => $path,
                    'limit_response_size' => AVBK_Photo_Upload::MAX_BYTES + 1,
                ]);
                if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                    throw new RuntimeException('Het ophalen van deze foto is niet gelukt. Probeer opnieuw.');
                }
                // Google may deliver a JPEG for an original HEIC; use the actual format.
                $mime = wp_get_image_mime($path);
                if (!isset(AVBK_Photo_Upload::TYPES[$mime])) {
                    throw new RuntimeException('Dit bestand is geen ondersteunde foto.');
                }
                if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), AVBK_Photo_Upload::TYPES[$mime], true)) {
                    $name = pathinfo($name, PATHINFO_FILENAME) . '.' . AVBK_Photo_Upload::TYPES[$mime][0];
                }
                $session['copied'][$index] = AVBK_Photo_Upload::store($path, $name, AVBK_Photo_Upload::MAX_BYTES, $session['id'] . ':' . $item['id']);
                self::save($session);
            }
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } finally {
            if ($path !== '') {
                wp_delete_file($path);
            }
            delete_option($lock);
        }
        if ($error !== '') {
            wp_send_json_error(['message' => $error], 400);
        }
        wp_send_json_success(['name' => $name, 'message' => 'Foto opgeslagen in je persoonlijke uploadmap.']);
    }

    private static function cleanup(): void {
        $session = get_transient(self::key());
        if (is_array($session) && !empty($session['id']) && !empty($session['token'])) {
            try {
                self::api($session, 'sessions/' . rawurlencode($session['id']), 'DELETE');
            } catch (RuntimeException $e) {
                // Local access still expires immediately; the remote session has its own expiry.
            }
        }
        if (is_array($session) && !empty($session['state_key'])) {
            delete_transient($session['state_key']);
        }
        delete_transient(self::key());
    }

    public function ajax_finish(): void {
        AVBK_Photo_Upload::authorize();
        self::cleanup();
        wp_send_json_success([]);
    }
}
