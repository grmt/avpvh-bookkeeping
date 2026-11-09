<?php
/**
 * Every error a user sees also ends up in the PHP error log (docker logs of
 * the site), as one "avbk: <action> failed: reason=..." line, so the server's
 * status page and daily status mail can count it and it can be traced
 * afterwards.
 *
 * Three routes cover the plugin's error output without touching each call:
 * - wp_die() (access denied, expired nonce, not found, ...) through the
 *   wp_die handlers, for this plugin's own requests;
 * - redirects that carry an error flag (book_error=..., import_error=1, ...)
 *   through the wp_redirect filter;
 * - AJAX errors through avbk_send_json_error(), used instead of
 *   wp_send_json_error(), since the error data is gone by the time a hook
 *   runs.
 * Handlers with more context call avbk_log_failure() themselves.
 *
 * Log lines hold ids, counts, reason codes and the plugin's own messages,
 * with e-mail addresses masked: never names or other member data.
 */
defined('ABSPATH') || exit;

/** Redirect query args that mean "this failed" (see avbk_register_error_logging()). */
const AVBK_ERROR_REDIRECT_ARGS = [
    'book_error', 'tshirt_error', 'process_error', 'reimbursement_error', 'google_photos_error',
    'photo_share_error', 'credit_apply_failed', 'congress_error', 'import_error', 'confirm_failed',
    'email_failed', 'dispute_result',
];

/** Masks e-mail addresses and squeezes whitespace, so a value fits one log field. */
function avbk_log_value(string $value, int $max = 200): string {
    $value = (string) preg_replace('/[^\s<>"\'@]+@[^\s<>"\'@]+/', '<address>', $value);
    $value = (string) preg_replace('/\s+/', '_', trim($value));
    return mb_substr($value, 0, $max);
}

/**
 * Writes a failed user action to the PHP error log.
 *
 * @param array<string, int|string|bool> $context
 */
function avbk_log_failure(string $action, string $reason, array $context = []): void {
    $parts = ['user=' . (function_exists('get_current_user_id') ? get_current_user_id() : 0)];
    foreach ($context as $key => $value) {
        $parts[] = $key . '=' . (is_bool($value) ? ($value ? '1' : '0') : avbk_log_value((string) $value));
    }
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: failed actions must be traceable in the server log.
    error_log(sprintf('avbk: %s failed: reason=%s %s', $action, avbk_log_value($reason, 80), implode(' ', $parts)));
}

/** The admin-post/AJAX action or admin page of the current request, e.g. "avbk_book_order" or "avbk-members". */
function avbk_request_action(): string {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- only read to label a log line; reduced to [a-z0-9_-] below.
    $action = $_REQUEST['action'] ?? ($_GET['page'] ?? '');
    return is_string($action) ? strtolower((string) preg_replace('/[^a-zA-Z0-9_-]/', '', $action)) : '';
}

/** True for requests handled by this plugin: avbk_* actions and avbk-* admin pages. */
function avbk_is_own_request(): bool {
    $action = avbk_request_action();
    return str_starts_with($action, 'avbk_') || str_starts_with($action, 'avbk-');
}

/**
 * wp_send_json_error() that also logs the error.
 *
 * @param mixed $data
 */
function avbk_send_json_error($data = null, ?int $status_code = null): void {
    $message = is_string($data) ? $data : (is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : '');
    avbk_log_failure(avbk_request_action() ?: 'ajax', 'json_error', array_filter([
        'status' => $status_code ?? '',
        'message' => $message,
    ], static fn($v) => $v !== ''));
    wp_send_json_error($data, $status_code);
}

/** Hooks wp_die() and error redirects of this plugin's requests into the log. */
function avbk_register_error_logging(): void {
    $wrap = static function ($handler) {
        if (!is_callable($handler)) {
            return $handler;
        }
        return static function ($message, $title = '', $args = []) use ($handler) {
            if (avbk_is_own_request()) {
                $text = $message instanceof WP_Error ? $message->get_error_message() : (is_string($message) ? $message : '');
                // wp_send_json() ends an AJAX request with an empty wp_die(); that's not an error.
                if (trim(wp_strip_all_tags($text)) !== '') {
                    $status = is_array($args) ? ($args['response'] ?? '') : (is_int($title) ? $title : '');
                    avbk_log_failure(avbk_request_action(), 'die', array_filter([
                        'status' => (string) $status,
                        'message' => wp_strip_all_tags($text),
                    ], static fn($v) => $v !== ''));
                }
            }
            $handler($message, $title, $args);
        };
    };
    add_filter('wp_die_handler', $wrap, PHP_INT_MAX);
    add_filter('wp_die_ajax_handler', $wrap, PHP_INT_MAX);
    add_filter('wp_die_json_handler', $wrap, PHP_INT_MAX);

    add_filter('wp_redirect', static function ($location) {
        if (!avbk_is_own_request() || !is_string($location)) {
            return $location;
        }
        parse_str((string) wp_parse_url($location, PHP_URL_QUERY), $query);
        foreach (AVBK_ERROR_REDIRECT_ARGS as $arg) {
            if (!isset($query[$arg]) || !is_string($query[$arg])) {
                continue;
            }
            // dispute_result also carries success values; only save_failed is an error.
            if ($arg === 'dispute_result' && $query[$arg] !== 'save_failed') {
                continue;
            }
            avbk_log_failure(avbk_request_action(), $arg, ['value' => $query[$arg]]);
        }
        return $location;
    }, PHP_INT_MAX);
}
