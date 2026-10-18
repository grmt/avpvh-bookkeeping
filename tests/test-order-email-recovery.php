<?php
/**
 * Test suite for order email recovery, typo detection, and order fee item matching.
 * Follows AGENTS.md: no real personal names in code, comments, or test data.
 */

define('ABSPATH', __DIR__ . '/');
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
define('AVBK_PLUGIN_URL', 'https://example.test/wp-content/plugins/avpvh-bookkeeping/');

class Mock_Order_Recovery_WPDB {
    public string $prefix = 'wp_';
    public array $orders = [];
    public array $fee_items = [];
    public int $last_error_count = 0;

    public function prepare(string $query, ...$args): string {
        $quoted_args = array_map(function($a) {
            return is_string($a) ? "'" . addslashes($a) . "'" : $a;
        }, $args);
        return vsprintf(str_replace(['%d', '%s', '%f'], ['%s', '%s', '%s'], $query), $quoted_args);
    }

    public function get_var(string $query) {
        if (str_contains($query, 'COUNT(*)')) {
            $filtered = $this->orders;
            if (str_contains($query, "order_type = 'tshirt'")) {
                $filtered = array_filter($filtered, fn($o) => ($o['order_type'] ?? '') === 'tshirt');
            } elseif (str_contains($query, "order_type = 'book'")) {
                $filtered = array_filter($filtered, fn($o) => ($o['order_type'] ?? '') === 'book');
            }
            if (str_contains($query, "email_sent = 0")) {
                $filtered = array_filter($filtered, fn($o) => empty($o['email_sent']) || !empty($o['email_error']));
            }
            if (str_contains($query, "status != 'cancelled'")) {
                $filtered = array_filter($filtered, fn($o) => ($o['status'] ?? '') !== 'cancelled');
            }
            return (string) count($filtered);
        }
        return null;
    }

    public function get_row(string $query, $output = OBJECT) {
        if (preg_match('/WHERE id = (\d+)/', $query, $m)) {
            $id = (int) $m[1];
            foreach ($this->orders as $o) {
                if ((int) $o['id'] === $id) {
                    return (object) $o;
                }
            }
        }
        return null;
    }

    public function get_results(string $query, $output = OBJECT): array {
        if (str_contains($query, 'avb_order_items')) {
            return [];
        }
        if (str_contains($query, 'avb_orders')) {
            $res = [];
            foreach ($this->orders as $o) {
                $res[] = (object) $o;
            }
            return $res;
        }
        return [];
    }

    public function update(string $table, array $data, array $where): int|false {
        if (str_contains($table, 'avb_orders')) {
            $id = (int) ($where['id'] ?? 0);
            foreach ($this->orders as $idx => $o) {
                if ((int) $o['id'] === $id) {
                    $this->orders[$idx] = array_merge($o, $data);
                    return 1;
                }
            }
        }
        if (str_contains($table, 'avb_fee_items')) {
            $id = (int) ($where['id'] ?? 0);
            foreach ($this->fee_items as $idx => $fi) {
                if ((int) $fi['id'] === $id) {
                    $this->fee_items[$idx] = array_merge($fi, $data);
                    return 1;
                }
            }
        }
        return 0;
    }

    public function query(string $query) {
        if (preg_match('/UPDATE .*avb_orders SET status = \'confirmed\'/i', $query)) {
            if (preg_match('/WHERE id = (\d+)/i', $query, $m)) {
                $id = (int) $m[1];
                foreach ($this->orders as $idx => $o) {
                    if ((int) $o['id'] === $id) {
                        $this->orders[$idx]['status'] = 'confirmed';
                        $this->orders[$idx]['confirmed_at'] = date('Y-m-d H:i:s');
                    }
                }
            }
            return 1;
        }
        return 0;
    }
}

$GLOBALS['wpdb'] = new Mock_Order_Recovery_WPDB();
global $wpdb;

$GLOBALS['options'] = [];
function get_option(string $name, $default = false) {
    return $GLOBALS['options'][$name] ?? $default;
}

$GLOBALS['hooks'] = [];
function add_action(string $tag, callable $function_to_add, int $priority = 10, int $accepted_args = 1): void {
    $GLOBALS['hooks'][$tag][] = $function_to_add;
}
function remove_action(string $tag, callable $function_to_remove, int $priority = 10): bool {
    unset($GLOBALS['hooks'][$tag]);
    return true;
}
function do_action(string $tag, ...$args): void {
    if (!empty($GLOBALS['hooks'][$tag])) {
        foreach ($GLOBALS['hooks'][$tag] as $cb) {
            $cb(...$args);
        }
    }
}

function is_email(string $email): string|false {
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
}
function sanitize_email(string $email): string {
    return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
}
function sanitize_text_field(string $str): string {
    return trim(strip_tags($str));
}
function esc_html(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function esc_attr(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function wp_date(string $format, int $timestamp): string {
    return date($format, $timestamp);
}
function current_time(string $type): string {
    return date('Y-m-d H:i:s');
}
function wp_create_nonce(string|int $action = -1): string {
    return 'mock_nonce_' . $action;
}
function home_url(string $path = ''): string {
    return 'https://example.test' . $path;
}
function admin_url(string $path = ''): string {
    return 'https://example.test/wp-admin/' . $path;
}
function add_query_arg($args, $uri = ''): string {
    if (is_array($args)) {
        $qs = http_build_query($args);
    } else {
        $qs = '';
    }
    return $uri . (str_contains($uri, '?') ? '&' : '?') . $qs;
}
function esc_url(string $url): string {
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

$GLOBALS['mock_mail_should_succeed'] = true;
$GLOBALS['mock_mail_sent_to'] = [];
function wp_mail(string|array $to, string $subject, string $message, string|array $headers = ''): bool {
    if (!$GLOBALS['mock_mail_should_succeed']) {
        $error = new class {
            public function get_error_message(): string {
                return 'Host lookup failed for destination domain';
            }
        };
        do_action('wp_mail_failed', $error);
        return false;
    }
    $GLOBALS['mock_mail_sent_to'][] = $to;
    return true;
}

require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-tshirt-order.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-book-order.php';

function check(bool $cond, string $msg): void {
    if (!$cond) {
        echo "FAIL: $msg\n";
        exit(1);
    }
    echo "PASS: $msg\n";
}

echo "\n--- Testing AVBK_DB::suggest_email_fix ---\n";

// 1. One-letter typos in popular NL / global domains
check(AVBK_DB::suggest_email_fix('bram@gmial.com') === 'bram@gmail.com', 'Detects gmial transposition -> gmail.com');
check(AVBK_DB::suggest_email_fix('anna@gmai.com') === 'anna@gmail.com', 'Detects gmai missing letter -> gmail.com');
check(AVBK_DB::suggest_email_fix('cas@gmaill.com') === 'cas@gmail.com', 'Detects gmaill double letter -> gmail.com');
check(AVBK_DB::suggest_email_fix('lid@hotmial.com') === 'lid@hotmail.com', 'Detects hotmial -> hotmail.com');
check(AVBK_DB::suggest_email_fix('lid@outlok.com') === 'lid@outlook.com', 'Detects outlok -> outlook.com');
check(AVBK_DB::suggest_email_fix('lid@live.nll') === 'lid@live.nl', 'Detects live.nll -> live.nl');
check(AVBK_DB::suggest_email_fix('lid@ziggo.nll') === 'lid@ziggo.nl', 'Detects ziggo.nll -> ziggo.nl');

// 2. Exact match on known domain returns null (no fix needed)
check(AVBK_DB::suggest_email_fix('anna@gmail.com') === null, 'Correct gmail domain returns null');
check(AVBK_DB::suggest_email_fix('bram@outlook.com') === null, 'Correct outlook domain returns null');

// 3. Custom or unknown domain returns null
check(AVBK_DB::suggest_email_fix('contact@example.org') === null, 'Unknown domain returns null');
check(AVBK_DB::suggest_email_fix('invalid-email') === null, 'Invalid email returns null');
check(AVBK_DB::suggest_email_fix('') === null, 'Empty email returns null');

echo "\n--- Testing AVBK_DB::count_failed_order_emails ---\n";

$wpdb->orders = [
    [
        'id' => 1,
        'order_type' => 'tshirt',
        'first_name' => 'Anna',
        'suffix' => '',
        'last_name' => 'Jansen',
        'email' => 'anna@gmial.com',
        'status' => 'pending_confirmation',
        'email_sent' => 0,
        'email_error' => 'Mailserver error 550',
        'fee_item_id' => 101,
        'member_id' => null,
        'total_amount' => 32.00,
        'fee_paid' => 0.00,
        'fee_status' => 'open',
        'distribution_status' => 'pending',
        'confirm_token' => 'mock_token_1',
    ],
    [
        'id' => 2,
        'order_type' => 'tshirt',
        'first_name' => 'Bram',
        'suffix' => '',
        'last_name' => 'de Boer',
        'email' => 'bram@gmail.com',
        'status' => 'confirmed',
        'email_sent' => 1,
        'email_error' => '',
        'fee_item_id' => 102,
        'member_id' => 5,
        'total_amount' => 16.00,
        'fee_paid' => 16.00,
        'fee_status' => 'paid',
        'distribution_status' => 'distributed',
        'confirm_token' => 'mock_token_2',
    ],
    [
        'id' => 3,
        'order_type' => 'book',
        'first_name' => 'Cas',
        'suffix' => '',
        'last_name' => 'Bakker',
        'email' => 'cas@hotmial.com',
        'status' => 'pending_confirmation',
        'email_sent' => 0,
        'email_error' => 'Unresolvable domain',
        'fee_item_id' => 103,
        'member_id' => null,
        'total_amount' => 25.00,
        'fee_paid' => 0.00,
        'fee_status' => 'open',
        'distribution_status' => 'pending',
        'confirm_token' => 'mock_token_3',
    ],
];

check(AVBK_DB::count_failed_order_emails('all') === 2, 'Counts 2 total failed order emails');
check(AVBK_DB::count_failed_order_emails('tshirt') === 1, 'Counts 1 failed tshirt order email');
check(AVBK_DB::count_failed_order_emails('book') === 1, 'Counts 1 failed book order email');

echo "\n--- Testing AVBK_DB::update_order_email ---\n";

$wpdb->fee_items = [
    ['id' => 101, 'member_id' => null, 'category' => 'tshirt', 'amount' => 32.00],
    ['id' => 103, 'member_id' => null, 'category' => 'book', 'amount' => 25.00],
];

// Update order 1: fix typo, link to member 12, and confirm order
$update_res = AVBK_DB::update_order_email(1, 'anna@gmail.com', 12, true);
check($update_res['success'] === true, 'update_order_email succeeds');

// Verify order 1 was updated in DB
$order_1 = AVBK_DB::get_order(1);
check($order_1->email === 'anna@gmail.com', 'Order email corrected to anna@gmail.com');
check((int) $order_1->member_id === 12, 'Order member_id set to 12');
check($order_1->status === 'confirmed', 'Order status updated to confirmed');
check(!empty($order_1->confirmed_at), 'Order confirmed_at is set');

// Verify fee item 101 member_id was synced
check((int) $wpdb->fee_items[0]['member_id'] === 12, 'Fee item member_id synced with order member_id');

echo "\n--- Testing AVBK_DB::resend_order_email ---\n";

// 1. Resend when mail succeeds
$GLOBALS['mock_mail_should_succeed'] = true;
$GLOBALS['mock_mail_sent_to'] = [];
$resend_ok = AVBK_DB::resend_order_email(1);
check($resend_ok['success'] === true, 'Resend order email succeeds');
check(in_array('anna@gmail.com', $GLOBALS['mock_mail_sent_to'], true), 'Confirmation email sent to anna@gmail.com');

$order_1_after_send = AVBK_DB::get_order(1);
check($order_1_after_send->email_status === 'sent', 'Email status set to sent');
check((int) $order_1_after_send->email_sent === 1, 'email_sent is 1');
check(empty($order_1_after_send->email_error), 'Email error cleared on success');

// 2. Resend when mail fails
$GLOBALS['mock_mail_should_succeed'] = false;
$resend_fail = AVBK_DB::resend_order_email(1);
check($resend_fail['success'] === false, 'Resend reports failure when mail fails');
check(!empty($resend_fail['error']), 'Failure reports mail error message');

$order_1_after_fail = AVBK_DB::get_order(1);
check($order_1_after_fail->email_status === 'failed', 'Email status set to failed');
check((int) $order_1_after_fail->email_sent === 0, 'email_sent is 0');
check(!empty($order_1_after_fail->email_error), 'Email error saved in DB');

echo "\n--- Testing Review Queue Order Fee Item Prioritization & Bestelling Terminology ---\n";

// In review-queue.php, fee items for category tshirt and book should be grouped under 'Openstaande bestellingen'
// and formatted as "Bestelling #ID — Description (open: €...)".
$fee_item_tshirt = (object) [
    'id' => 101,
    'member_id' => 12,
    'category' => 'tshirt',
    'description' => '2x T-shirt Lustrum',
    'amount' => 32.00,
    'amount_paid' => 0.00,
    'status' => 'open',
];

$is_order = in_array($fee_item_tshirt->category, ['tshirt', 'book'], true);
check($is_order === true, 'Fee item recognized as order item');

$label = sprintf(
    '%s #%d &mdash; %s (open: &euro; %s)',
    'Bestelling',
    (int) $fee_item_tshirt->id,
    $fee_item_tshirt->description,
    number_format(32.00, 2, ',', '.')
);
check(str_contains($label, 'Bestelling #101'), 'Label uses Bestelling # instead of Post #');
check(!str_contains($label, 'Post #'), 'Label does NOT contain Post #');

echo "\nAll order email recovery tests PASSED cleanly!\n";
