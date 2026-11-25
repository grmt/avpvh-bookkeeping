<?php
defined('ABSPATH') || exit;

/** Only link back to this site's origin; discard supplied query strings and fragments. */
function avbk_public_page_url($raw, string $fallback): string {
    $url = is_string($raw) ? esc_url_raw(wp_unslash($raw)) : '';
    $parts = wp_parse_url($url);
    $home = wp_parse_url(home_url('/'));
    if (!$parts || !$home || isset($parts['user']) || isset($parts['pass'])
        || strtolower($parts['scheme'] ?? '') !== strtolower($home['scheme'] ?? '')
        || strtolower($parts['host'] ?? '') !== strtolower($home['host'] ?? '')
        || ($parts['port'] ?? null) !== ($home['port'] ?? null)) {
        return $fallback;
    }
    $origin = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
    return $origin . ($parts['path'] ?? '/');
}

/** Shared throttling for public forms. Never trust forwarded IP headers. */
function avbk_public_rate_limit(string $scope, string $identity, int $limit, int $seconds): bool {
    global $wpdb;
    $key = 'avbk_public_' . substr(hash('sha256', $scope . ':' . strtolower(trim($identity))), 0, 40);
    // Transient read/modify/write alone allows concurrent requests through.
    if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $key)) !== '1') {
        return false;
    }
    try {
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        return set_transient($key, $count + 1, $seconds);
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
    }
}

/** Bound both total form traffic and repeated mail to one recipient across order types. */
function avbk_public_registration_allowed(string $email): bool {
    return avbk_public_rate_limit('registration_ip', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 10, 900)
        && avbk_public_rate_limit('registration_email', $email, 3, 3600);
}
