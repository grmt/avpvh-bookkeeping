<?php
/** Tests admin overview pending-reimbursements tile and reimbursement card rendering. Fictional data only. */
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);

function current_user_can($cap) { return true; }
function wp_die($msg) { throw new RuntimeException($msg); }
function get_option($name, $default = false) { return $default; }
function current_time($format) { return $format === 'Y' ? '2026' : '2026-11-27 12:00:00'; }
function wp_list_pluck($list, $field) { return array_map(static fn($i) => $i->$field ?? null, $list); }
function esc_url($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $url = '') { return $url . '?' . http_build_query($args); }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($action) . '">'; }
function wp_nonce_url($url, $action) { return $url . '&amp;_wpnonce=' . $action; }
function submit_button($text, $type = 'primary', $name = 'submit', $wrap = true) { echo '<button type="submit">' . esc_html($text) . '</button>'; }
function wp_date($format, $ts = null) { return date($format, $ts ?? time()); }
function selected($a, $b) { if ((string) $a === (string) $b) echo 'selected="selected"'; }
function sanitize_key($k) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)); }
function wp_unslash($v) { return $v; }
function avpvh_format_name($m, $mode = '') { return $m->first_name . ' ' . $m->last_name; }

final class AVPVH_Roles {
    public static function current_user_has_role(string $role): bool { return true; }
}
final class AVPVH_DB {
    public static function get_members(array $args = []): array { return []; }
    public static function get_activities(): array { return [(object) ['id' => 9, 'name' => 'Zomerkamp', 'year' => 2026, 'start_date' => '2026-08-01', 'end_date' => '2026-08-08']]; }
    public static function get_member(int $id): ?object { return (object) ['id' => $id, 'first_name' => 'Piet', 'last_name' => 'Jansen']; }
    public static function get_activity(int $id): ?object { return self::get_activities()[0]; }
}
final class AVBK_Fee_Generation {
    public static function find_stale_fee_items(): array { return []; }
}
final class AVBK_QR {
    public static function for_reimbursement(string $iban, string $name, float $amount, string $remittance): string { return '<svg></svg>'; }
}
final class AVBK_DB {
    public static array $pending_summary = ['count' => 2, 'amount' => 834.40];
    public static function get_review_queue(): array { return []; }
    public static function get_import_batches(int $limit): array { return []; }
    public static function get_camps_without_rate(int $from_year): array { return []; }
    public static function get_last_payment_dates(array $ids): array { return []; }
    public static function get_assigned_payment_counts_by_year(): array { return []; }
    public static function get_transaction_date_ranges_by_year(): array { return []; }
    public static function get_total_credit_for_member(int $id): float { return 0.0; }
    public static function get_total_donations(): float { return 5.0; }
    public static function get_outgoing_summary(int $year): array { return ['total' => 100.0, 'open_count' => 1, 'open_amount' => 50.0]; }
    public static function count_open_disputes(): int { return 0; }
    public static function get_pending_reimbursements_summary(): array { return self::$pending_summary; }
    public static function get_member_balance(int $id): array { return ['items' => []]; }
    public static function get_known_ibans_for_member(int $id): array { return [(object) ['iban' => 'NL00TEST0000000001', 'account_name' => 'Piet Jansen']]; }
    public static function get_reimbursement_receipts(int $id): array {
        return [(object) ['id' => 11, 'receipt_path' => 'bon.jpg', 'date' => '2026-08-03', 'ocr_date' => '2026-08-03', 'store' => 'Bakker', 'description' => 'brood', 'amount' => 834.40]];
    }
}

require dirname(__DIR__) . '/admin/overview.php';
require dirname(__DIR__) . '/admin/reimbursements.php';

$failures = 0;
function check(bool $ok, string $label): void { global $failures; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) $failures++; }

// 1. Overview tile shows pending declarations count + total amount and links to avbk-reimbursements.
ob_start();
avbk_render_overview();
$overview_html = (string) ob_get_clean();
check(str_contains($overview_html, 'page=avbk-reimbursements') && str_contains($overview_html, 'Declaraties wachten op betaling (€ 834,40)'), 'overview renders pending reimbursements tile with count and total amount');

AVBK_DB::$pending_summary = ['count' => 0, 'amount' => 0.0];
ob_start();
avbk_render_overview();
$overview_zero = (string) ob_get_clean();
check(str_contains($overview_zero, 'Declaraties wachten op betaling</span>'), 'overview omits euro amount when no declarations are waiting');

// 2. Admin reimbursement card renders declared amount without the raw OCR total note.
$reimbursement = (object) [
    'id' => 42, 'member_id' => 7, 'activity_id' => 9, 'description' => 'boodschappen',
    'member_note' => 'voorgeschoten', 'amount' => 834.40, 'ocr_amount' => 2354.73,
    'iban' => 'NL00TEST0000000001', 'created_at' => '2026-11-27 23:01:00',
];
ob_start();
avbk_render_reimbursement_card($reimbursement, AVPVH_DB::get_activities());
$card_html = (string) ob_get_clean();
check(str_contains($card_html, '€ 834,40') || str_contains($card_html, '&euro; 834,40'), 'admin card shows declared amount');
check(!str_contains($card_html, 'OCR herkende totaal'), 'admin card omits raw OCR total note');

exit($failures ? 1 : 0);
