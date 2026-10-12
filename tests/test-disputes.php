<?php
/** Standalone regression checks; uses an in-memory DB and never sends mail. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
define('MINUTE_IN_SECONDS', 60);
$render_mode = in_array('--render', $_SERVER['argv'] ?? [], true);
if ($render_mode) ob_start();

class WP_Error {
    public function __construct(private string $code, private string $message) {}
    public function get_error_code(): string { return $this->code; }
}
class AVPVH_Roles {
    public static function current_user_has_role($role): bool { return $GLOBALS['treasurer']; }
}
class AVPVH_DB {
    public static function get_member($id): ?object { return $GLOBALS['members'][$id] ?? null; }
}
function current_user_can($capability): bool { return $GLOBALS['admin']; }
function get_current_user_id(): int { return 7; }
function current_time($format): string { return '2026-01-02 12:00:00'; }
function get_option($name, $default = false) { return $default; }
function sanitize_email($value): string { return trim($value); }
function sanitize_text_field($value): string { return trim(strip_tags($value)); }
function sanitize_textarea_field($value): string { return trim(strip_tags($value)); }
function sanitize_key($value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function wp_unslash($value) { return $value; }
function absint($value): int { return abs((int) $value); }
function is_email($value): bool { return (bool) filter_var($value, FILTER_VALIDATE_EMAIL); }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_generate_uuid4(): string { static $id = 0; return sprintf('00000000-0000-4000-8000-%012d', ++$id); }
function add_filter($name, $callback, $priority): void { $GLOBALS['filters'][$name] = $callback; }
function remove_filter($name, $callback, $priority): void { unset($GLOBALS['filters'][$name]); }
function set_transient($key, $value, $expiry): void { $GLOBALS['drafts'][$key] = $value; }
function delete_transient($key): void { unset($GLOBALS['drafts'][$key]); }
function get_transient($key) { return $GLOBALS['drafts'][$key] ?? false; }
function check_admin_referer($nonce): void { if (!$GLOBALS['valid_nonce']) throw new RuntimeException('Invalid nonce'); }
function wp_die(...$args): void { throw new RuntimeException('Access denied'); }
function wp_safe_redirect($url): void { throw new RuntimeException('Redirect: ' . $url); }
function add_query_arg($args, $url): string { return $url . '?' . http_build_query($args); }
function admin_url($path): string { return 'https://example.test/wp-admin/' . $path; }
function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value): string { return esc_html($value); }
function esc_textarea($value): string { return esc_html($value); }
function esc_url($value): string { return esc_attr($value); }
function mysql2date($format, $value): string { return $value; }
function avpvh_format_name($member, $format = ''): string { return 'Lid ' . $member->id; }
function get_userdata($id): object { return (object) ['display_name' => 'Behandelaar']; }
function wp_nonce_field($action): void { echo '<input type="hidden" name="_wpnonce" value="test-nonce">'; }
function wp_mail($to, $subject, $body, $headers): bool {
    $last = end($GLOBALS['wpdb']->events);
    check($last && $last['delivery_status'] === 'pending', 'answer is durable before mail');
    $GLOBALS['mails'][] = compact('to', 'subject', 'body', 'headers');
    if ($GLOBALS['mail_result'] === 'throw') throw new RuntimeException('Mock mail failure');
    return $GLOBALS['mail_result'];
}

class AVBK_Dispute_Test_DB {
    public string $prefix = 'test_';
    public int $insert_id = 0;
    public array $disputes = [];
    public array $events = [];
    public bool $fail_insert = false;
    public bool $fail_update = false;
    public bool $fail_delivery = false;
    private array $snapshot = [];
    public function prepare($sql, ...$args): string {
        return vsprintf(str_replace(['%d', '%s'], ['%s', '%s'], $sql), $args);
    }
    public function get_row($sql): ?object {
        preg_match('/WHERE id = (\d+)/', $sql, $match);
        return isset($this->disputes[$match[1] ?? 0]) ? (object) $this->disputes[$match[1]] : null;
    }
    public function get_var($sql) {
        foreach ($this->events as $id => $event) if (str_contains($sql, $event['request_key'])) return $id;
        return null;
    }
    public function get_results($sql): array {
        if (str_contains($sql, 'avb_dispute_events')) {
            preg_match('/dispute_id = (\d+)/', $sql, $match);
            return array_map(fn($event) => (object) $event, array_values(array_filter($this->events, fn($e) => $e['dispute_id'] === (int) ($match[1] ?? 0))));
        }
        $status = str_contains($sql, 'resolved') ? 'resolved' : 'open';
        return array_map(fn($row) => (object) $row, array_values(array_filter($this->disputes, fn($row) => $row['status'] === $status)));
    }
    public function insert($table, $data) {
        if ($this->fail_insert) return false;
        if (str_ends_with($table, 'avb_dispute_events')) {
            foreach ($this->events as $event) if ($event['request_key'] === $data['request_key']) return false;
            $this->insert_id++;
            $this->events[$this->insert_id] = ['id' => $this->insert_id] + $data;
        } else {
            $this->insert_id++;
            $this->disputes[$this->insert_id] = ['id' => $this->insert_id] + $data;
        }
        return 1;
    }
    public function update($table, $data, $where) {
        if ($this->fail_update || ($this->fail_delivery && isset($data['delivery_status']))) return false;
        if (str_ends_with($table, 'avb_dispute_events')) $this->events[$where['id']] = array_merge($this->events[$where['id']], $data);
        else $this->disputes[$where['id']] = array_merge($this->disputes[$where['id']], $data);
        return 1;
    }
    public function query($sql): int {
        if ($sql === 'START TRANSACTION') $this->snapshot = [$this->disputes, $this->events];
        if ($sql === 'ROLLBACK') [$this->disputes, $this->events] = $this->snapshot;
        return 1;
    }
}
require AVBK_PLUGIN_DIR . 'includes/class-db.php';
require AVBK_PLUGIN_DIR . 'includes/class-disputes.php';
require AVBK_PLUGIN_DIR . 'includes/class-admin.php';
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    echo "PASS $message\n";
}
function reset_case(): void {
    $GLOBALS['wpdb'] = new AVBK_Dispute_Test_DB();
    $GLOBALS['wpdb']->disputes[1] = ['id' => 1, 'member_id' => 10, 'submitted_by_member_id' => 11, 'message' => 'Fictieve vraag', 'status' => 'open', 'created_at' => '2026-01-01 12:00:00', 'resolved_at' => null, 'resolved_by' => null];
    $GLOBALS['members'] = [10 => (object) ['id' => 10, 'email' => 'member@example.test'], 11 => (object) ['id' => 11, 'email' => 'submitter@example.test']];
    $GLOBALS['admin'] = false;
    $GLOBALS['treasurer'] = true;
    $GLOBALS['valid_nonce'] = true;
    $GLOBALS['mail_result'] = true;
    $GLOBALS['mails'] = $GLOBALS['filters'] = $GLOBALS['drafts'] = [];
}
function act($action, $message = 'Fictieve tekst', $key = null) {
    return AVBK_Disputes::act(1, $action, $message, $key ?? wp_generate_uuid4());
}

reset_case();
check(act('note') === 'note_saved' && count($wpdb->events) === 1 && !$mails, 'internal note is recorded without mail');
check(act('reply') === 'reply_sent', 'reply accepted by mailer');
check($mails[0]['to'] === 'submitter@example.test', 'reply goes to submitter, not household subject');
check(end($wpdb->events)['delivery_status'] === 'sent' && !$filters, 'mail result recorded and hooks removed');
check($wpdb->disputes[1]['status'] === 'open', 'reply does not silently close dispute');
$key = wp_generate_uuid4();
act('reply', 'Fictief antwoord', $key);
$mail_count = count($mails);
check(act('reply', 'Fictief antwoord', $key) === 'duplicate' && count($mails) === $mail_count, 'replayed form does not resend');
check(act('resolve', 'Onderzocht') === 'resolved' && $wpdb->disputes[1]['resolved_by'] === 7, 'closure records actor and status');
check(act('reopen', 'Nog een vraag') === 'reopened' && $wpdb->disputes[1]['resolved_at'] === null, 'reopen retains earlier history');
check(array_column($wpdb->events, 'event_type') === ['note', 'reply', 'reply', 'resolved', 'reopened'], 'all actions retained in order');

foreach ([false, 'throw'] as $failure) {
    reset_case(); $mail_result = $failure;
    check(act('reply')->get_error_code() === 'reply_failed' && end($wpdb->events)['delivery_status'] === 'failed' && !$filters, 'mail failure is retained and hooks cleaned');
}
reset_case(); $wpdb->fail_insert = true;
check(act('reply')->get_error_code() === 'save_failed' && !$mails, 'failed audit write prevents mail');
reset_case(); $wpdb->fail_delivery = true;
check(act('reply')->get_error_code() === 'tracking_failed' && end($wpdb->events)['delivery_status'] === 'pending', 'unknown mail result is not claimed as sent');
reset_case(); $wpdb->fail_update = true;
check(act('resolve')->get_error_code() === 'save_failed' && !$wpdb->events && $wpdb->disputes[1]['status'] === 'open', 'status and audit roll back together');
reset_case(); unset($members[11]);
check(act('reply')->get_error_code() === 'missing_email' && !$mails && !$wpdb->events, 'deleted submitter does not fall back to another person');
reset_case(); $wpdb->disputes[1]['submitted_by_member_id'] = null;
check(act('reply') === 'reply_sent' && $mails[0]['to'] === 'member@example.test', 'legacy recipient falls back to recorded subject');
reset_case(); $treasurer = false;
check(act('reply')->get_error_code() === 'forbidden' && !$wpdb->events && !$mails, 'unauthorized action denied');
reset_case(); $treasurer = false; $admin = true;
check(act('note') === 'note_saved', 'administrator may manage disputes');
reset_case();
check(act('reply', '   ')->get_error_code() === 'empty_message' && !$mails, 'empty reply rejected');
check(act('reply', 'Test', 'invalid')->get_error_code() === 'invalid_action' && !$mails, 'malformed request rejected');

$handler = (new ReflectionClass(AVBK_Admin::class))->newInstanceWithoutConstructor();
reset_case(); $valid_nonce = false;
$_POST = ['id' => 1, 'dispute_action' => 'reply', 'message' => 'Fictief antwoord', 'request_key' => wp_generate_uuid4()];
try { $handler->handle_update_dispute(); } catch (RuntimeException $error) { check($error->getMessage() === 'Invalid nonce' && !$mails, 'handler rejects invalid nonce before sending'); }
reset_case(); $mail_result = false;
try { $handler->handle_update_dispute(); } catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'dispute_result=reply_failed') && ($drafts['avbk_dispute_draft_7_1']['message'] ?? '') === 'Fictief antwoord', 'failed reply redirects with reusable private draft');
}
echo "All dispute checks passed; no real email sent.\n";

reset_case();
$wpdb->disputes[1]['message'] = "Fictieve vraag over het overzicht.\n<script>alert('test')</script>";
act('note', 'Gecontroleerd in het overzicht.');
act('reply', 'Bedankt voor je bericht. De betaling is verwerkt.');
$_GET = ['dispute_id' => 1];
ob_start();
require AVBK_PLUGIN_DIR . 'admin/disputes.php';
$html = ob_get_clean();
check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'member message is escaped in rendered page');
check(str_contains($html, 'Antwoord versturen') && str_contains($html, 'Notitie opslaan') && str_contains($html, 'Actiehistorie (2)'), 'reply, private note and history appear together');
check(str_contains($html, 'submitter@example.test') && str_contains($html, 'Behandelaar'), 'recipient and action author visible');
if ($render_mode) {
    ob_end_clean();
    echo '<!doctype html><html lang="nl"><meta charset="utf-8"><link rel="stylesheet" href="file://' . AVBK_PLUGIN_DIR . 'assets/admin.css">';
    echo $html;
}
