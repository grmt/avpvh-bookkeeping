<?php
declare(strict_types=1);

/**
 * Renders the "E-mailadres bestelling verwerken" dialog, its script and the
 * book order table of Bestellingen (admin/orders.php), and checks what the
 * penningmeester sees: the note slot for a bounced address, the mail that
 * is checked before sending, and the notes column. Fictional data only.
 *
 * Run:
 *   php tests/test-orders-dialog.php
 */

define('ABSPATH', __DIR__ . '/');

function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_js($s) { return addslashes((string) $s); }
function esc_html__($s, $d = '') { return esc_html($s); }
function esc_html_e($s, $d = '') { echo esc_html($s); }
function __($s, $d = '') { return $s; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function home_url($p = '') { return 'https://example.test' . $p; }
function add_query_arg($args, $url = '') { return $url . (str_contains((string) $url, '?') ? '&' : '?') . http_build_query((array) $args); }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '">'; }
function wp_nonce_url($url, $action) { return $url . '&_wpnonce=' . $action; }
function selected($a, $b) { echo ((string) $a === (string) $b) ? ' selected' : ''; }
function checked($v, $c = true) { echo ((bool) $v === (bool) $c) ? ' checked' : ''; }
function wp_date($f, $t = null) { return '24-11-2026'; }
function mysql2date($f, $d) { return '24-11-2026'; }
function number_format_i18n($n, $d = 0) { return number_format((float) $n, $d, ',', '.'); }
function avpvh_format_name($m, $mode = '') { return $mode === 'list' ? $m->last_name . ', ' . $m->first_name : $m->first_name . ' ' . $m->last_name; }
function get_option($k, $d = false) { return $d; }
function wp_trim_words($t, $n = 55, $more = '…') { $w = preg_split('/\s+/', trim((string) $t)); return count($w) > $n ? implode(' ', array_slice($w, 0, $n)) . $more : implode(' ', $w); }

/** Anything else the table asks for answers with an empty value. */
abstract class Test_Empty_Stub {
    public static function __callStatic($name, $args) { return []; }
}
final class AVBK_DB extends Test_Empty_Stub {
    public static function suggest_email_fix($email) { return null; }
}
final class AVPVH_Roles extends Test_Empty_Stub {}

require_once dirname(__DIR__) . '/admin/orders.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}
function render(callable $fn): string {
    ob_start();
    $fn();
    return (string) ob_get_clean();
}

$members = [(object) ['id' => 12, 'first_name' => 'Dirk', 'last_name' => 'Jansen', 'email' => 'dirk@example.test']];
$dialog = render(fn() => avbk_render_orders_process_email_modal($members));
check(str_contains($dialog, 'id="avbk-pe-email-note"'), 'The dialog has a place for the bounced-address note');
check(str_contains($dialog, 'Je ziet de mail met betaalinstructies eerst (Mail controleren)'), 'The dialog says the mail is checked before it is sent');
check(str_contains($dialog, '>Opslaan en verder</button>'), 'The button says the next step follows');
check(str_contains($dialog, 'data-member-id="12" data-email="dirk@example.test"'), 'Members carry their address for the script');

$script = render(fn() => avbk_render_orders_script());
check(str_contains($script, 'function replaceBouncedEmail(memberEmail)'), 'The script replaces a bounced order address');
check(str_contains($script, 'replaceBouncedEmail(linkedMember.email)') && str_contains($script, '!replaceBouncedEmail(match.email)'), 'Both for an already linked member and for a member chosen in the dialog');
check(str_contains($script, 'peOrderBounced = !!(error && error.trim() !== \'\')'), 'Only when mail to the order address failed');

$order = (object) [
    'id' => 41, 'order_type' => 'book', 'created_at' => '2026-11-24 10:00:00', 'first_name' => 'Dirk', 'suffix' => '', 'last_name' => 'Jansen',
    'email' => 'dirk@exmaple.test', 'phone' => '', 'street' => '', 'house_number' => '', 'postal_code' => '', 'city' => '', 'country' => '',
    'quantity' => 1, 'total_amount' => 35.0, 'status' => 'confirmed', 'distribution_status' => 'pending', 'member_id' => 12,
    'notes' => 'Graag ophalen bij de presentatie', 'email_status' => 'failed', 'email_error' => '550 mailbox unavailable',
    'presentation' => '', 'keep_updated' => 0, 'confirm_token' => 't', 'fee_item_id' => null, 'items' => [],
    'fee_status' => 'open', 'fee_paid' => 0.0,
];
$table = render(fn() => avbk_render_book_orders_table([$order]));
check(str_contains($table, '<td class="avbk-order-notes">') && str_contains($table, 'Graag ophalen bij de presentatie'), 'The notes cell has its own class for a readable width');

exit($failures ? 1 : 0);
