<?php
/**
 * Bon opvragen: the penningmeester picks the member (the account's and
 * look-alike names first), the member gets a mail with a link to Te doen,
 * and the Uitgaven page shows the choice. Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
final class Redirected extends Exception {}
function add_action(...$args): void {}
function remove_action(...$args): void {}
function check_admin_referer($action) { return true; }
function current_user_can($cap) { return true; }
function wp_die($message) { throw new RuntimeException((string) $message); }
function wp_safe_redirect($url) { $GLOBALS['redirect'] = $url; throw new Redirected(); }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function home_url($path = '') { return 'https://example.test' . $path; }
function add_query_arg($args, $url) { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args); }
function get_option($name, $default = false) { return $default; }
function sanitize_email($email) { return trim((string) $email); }
function sanitize_text_field($text) { return trim((string) $text); }
function is_email($email) { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
function wp_date($format, $timestamp) { return gmdate($format, $timestamp); }
function wp_mail($to, $subject, $body, $headers) { $GLOBALS['mails'][] = compact('to', 'subject', 'body', 'headers'); return $GLOBALS['mail_ok'] ?? true; }
function avbk_log_failure(string $where, string $reason, array $ids = []): void { $GLOBALS['failures_logged'][] = "$where: $reason"; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return (string) $url; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="x">'; }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key)); }
function wp_unslash($value) { return $value; }
function wp_kses($text, $allowed) { return (string) $text; }
function selected($a, $b) { echo (string) $a === (string) $b ? ' selected' : ''; }
function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function avpvh_format_name($member, $style = '') { return $member->first_name . ' ' . $member->last_name; }
final class AVPVH_Roles { public static function current_user_has_role($role) { return true; } }
$GLOBALS['member_rows'] = [
    71 => (object) ['id' => 71, 'first_name' => 'Anna', 'last_name' => 'Jansen', 'email' => 'anna@example.test'],
    72 => (object) ['id' => 72, 'first_name' => 'Bram', 'last_name' => 'Jansen', 'email' => 'bram@example.test'],
    73 => (object) ['id' => 73, 'first_name' => 'Cas', 'last_name' => 'Pieters', 'email' => 'lid73@avpvh.local'],
];
final class AVPVH_DB {
    public static function get_member(int $id): ?object { return $GLOBALS['member_rows'][$id] ?? null; }
    public static function get_activities(): array { return [(object) ['id' => 3, 'name' => 'Kamp', 'year' => 2026]]; }
}
final class AVBK_Matcher {
    public static function find_payer_candidates(string $name, array $members): array {
        return array_values(array_map(static fn($m) => ['member' => $m, 'score' => 80], array_filter($members, static fn($m) => str_contains($name, $m->last_name))));
    }
    public static function strip_name_field(string $description): string { return preg_replace('/^Name: [^:]*? Description: /', '', $description); }
}
final class AVBK_DB {
    public static array $requests = [];
    public static array $ibans = ['NL00TEST0000000001' => [72, 71]];
    public static function get_member_ids_by_iban(string $iban): array { return self::$ibans[$iban] ?? []; }
    public static function get_transaction(int $id): ?object { return $GLOBALS['transactions'][$id] ?? null; }
    public static function create_document_request(int $transaction_id, int $member_id): int {
        if ($member_id === 73 && !empty($GLOBALS['save_fails'])) { return 0; }
        self::$requests[$transaction_id] = (object) ['id' => count(self::$requests) + 1, 'transaction_id' => $transaction_id, 'member_id' => $member_id, 'document_path' => '', 'requested_at' => '2026-11-20 10:00:00'];
        return self::$requests[$transaction_id]->id;
    }
    public static function get_document_request_for_transaction(int $id): ?object { return self::$requests[$id] ?? null; }
    public static function get_unexplained_outgoing(int $min_year): array { return [$GLOBALS['transactions'][5]]; }
    public static function get_reimbursement_candidates(): array { return []; }
    public static function get_expense_categories(): array { return ['Attenties']; }
    public static function get_payable_members(): array { return array_values($GLOBALS['member_rows']); }
    public static function get_returned_payment_candidates(object $tx): array { return []; }
    public static function find_member_id_by_iban(string $iban): ?int { return $iban === 'NL00TEST0000000001' ? 72 : null; }
    public static function log_mail(array $row): void { $GLOBALS['logged_mails'][] = $row['kind']; }
}
require dirname(__DIR__) . '/includes/class-document-requests.php';
require dirname(__DIR__) . '/includes/class-expenses.php';
require dirname(__DIR__) . '/admin/expenses.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}
function request(int $tx, int $member): string {
    $_POST = ['transaction_id' => $tx, 'member_id' => $member];
    try {
        (new AVBK_Document_Requests())->handle_request();
    } catch (Redirected $redirect) {
        return $GLOBALS['redirect'];
    }
    return '';
}

$shared = (object) ['id' => 5, 'direction' => 'out', 'amount' => 110.0, 'transaction_date' => '2026-02-10', 'counterparty_iban' => 'NL00TEST0000000001', 'counterparty_name' => 'A. Jansen', 'description' => 'Name: A. Jansen Description: declaratie bloemen'];
$unknown = (object) ['id' => 6, 'direction' => 'out', 'amount' => 34.17, 'transaction_date' => '2026-03-30', 'counterparty_iban' => '', 'counterparty_name' => 'Hr C Pieters', 'description' => ''];
$nobody = (object) ['id' => 7, 'direction' => 'out', 'amount' => 9.0, 'transaction_date' => '2026-03-30', 'counterparty_iban' => '', 'counterparty_name' => '', 'description' => ''];
$incoming = (object) ['id' => 8, 'direction' => 'in', 'amount' => 9.0, 'transaction_date' => '2026-03-30', 'counterparty_iban' => '', 'counterparty_name' => '', 'description' => ''];
$GLOBALS['transactions'] = [5 => $shared, 6 => $unknown, 7 => $nobody, 8 => $incoming];
$all = array_values($GLOBALS['member_rows']);

// Suggestions
check(AVBK_Document_Requests::suggested_member_ids([72, 71], [['member' => $GLOBALS['member_rows'][71]]]) === [72, 71], 'Account holders first, a name match only once');
check(array_column(AVBK_Document_Requests::suggestions($shared, $all), 'id') === [72, 71], 'A shared account offers both holders');
check(array_column(AVBK_Document_Requests::suggestions($unknown, $all), 'id') === [73], 'No account: the look-alike name');
check(AVBK_Document_Requests::suggestions($nobody, $all) === [], 'No account and no name: nothing to suggest');

// The mail
[$subject, $body] = AVBK_Document_Requests::request_mail($GLOBALS['member_rows'][71], $shared, 'https://example.test/te-doen', 'de penningmeester');
check($subject === '[AV-PvH] Bon gevraagd bij een betaling', 'Subject');
check(str_starts_with($body, "Dag Anna,\n\n"), 'Greets the member');
check(str_contains($body, '€ 110,00 op 10-02-2026 (declaratie bloemen)'), 'Names the payment without the bank name field');
check(str_contains($body, "foto of pdf") && str_contains($body, "https://example.test/te-doen"), 'Asks for a photo or pdf and links to Te doen');
check(AVBK_Document_Requests::member_page_url(71) === 'https://example.test/leden/beheer/member-profile/?member_id=71#te-doen', 'The link opens Te doen');

// Asking
$url = request(5, 71);
check(str_contains($url, 'document_requested=mailed') && str_ends_with($url, '#tx-5'), 'Request saved and mailed');
check(AVBK_DB::$requests[5]->member_id === 71, 'For the chosen member, not the first account holder');
check(count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0]['to'] === 'anna@example.test' && in_array('Reply-To: penningmeester@avphilipsvanhorne.nl', $GLOBALS['mails'][0]['headers'], true), 'Mailed to the member, replies to the penningmeester');
check(($GLOBALS['logged_mails'] ?? []) === ['document_request'], 'Logged in Verzonden mails as Bon opvragen');

check(str_contains(request(6, 73), 'document_requested=no_address'), 'No usable address: asked, not mailed');
check(count($GLOBALS['mails']) === 1, 'and no mail sent');

$GLOBALS['mail_ok'] = false;
AVBK_DB::$requests = [];
check(str_contains(request(5, 72), 'document_requested=not_mailed'), 'A failed mail is reported');

check(str_contains(request(5, 99), 'explain_failed=request_failed'), 'An unknown member is refused');
check(str_contains(request(8, 71), 'explain_failed=request_failed'), 'An incoming payment is refused');
$GLOBALS['save_fails'] = true;
check(str_contains(request(6, 73), 'explain_failed=request_failed'), 'A request that is not saved is refused');
check(in_array('document request: no_member', $GLOBALS['failures_logged'], true) && in_array('document request: not_saved', $GLOBALS['failures_logged'], true), 'Every refusal is logged');

// The Uitgaven page
AVBK_DB::$requests = [];
ob_start();
avbk_render_expense_document_request($shared, AVBK_Document_Requests::suggestions($shared, $all));
$html = ob_get_clean();
check(str_contains($html, 'data-options="avbk-expense-members"') && str_contains($html, '<optgroup label="Voorstel">'), 'A member select with the suggestions, filled with all members by the page script');
check(strpos($html, 'value="72"') < strpos($html, 'value="71"') && !str_contains($html, 'kies een lid'), 'Suggestions in order, the first one chosen');
check(str_contains($html, 'krijgt een mail'), 'Says the member is mailed');
check(!str_contains($html, '<details>'), 'With a suggestion the request is shown straight away');
ob_start();
avbk_render_expense_document_request($nobody, AVBK_Document_Requests::suggestions($nobody, $all));
$collapsed = ob_get_clean();
check(str_contains($collapsed, 'kies een lid') && str_contains($collapsed, '<details>') && str_contains($collapsed, '<summary>Bon opvragen bij een lid</summary>'), 'Without a suggestion the request is folded away, and the member has to be chosen');
AVBK_DB::create_document_request(5, 71);
ob_start();
avbk_render_expense_document_request($shared, AVBK_Document_Requests::suggestions($shared, $all));
check(str_contains(ob_get_clean(), 'Bon gevraagd aan Anna Jansen'), 'An open request shows who was asked');

// The open tab: every member once in a template, each payment gets the request
AVBK_DB::$requests = [];
ob_start();
avbk_render_expenses_open(0, 0);
$page = ob_get_clean();
check(substr_count($page, '<template id="avbk-expense-members"><optgroup label="Alle leden">') === 1 && substr_count($page, 'value="73"') === 1, 'All members once per page');
check(str_contains($page, 'name="member_id"') && str_contains($page, 'Bon opvragen'), 'The payment offers the request');
check(str_contains($page, 'Deze rekening hoort bij een lid') && str_contains($page, 'member_id=72&refund_tx=5">Koppel die bij het lid</a>'), "An account of one member links to that member's refunds");
check(str_contains($page, 'Gebruik dan Terugbetalen bij <a href="https://example.test/wp-admin/admin.php?page=avbk-members&member_id=72&refund_tx=5">Bram Jansen</a> of <a href="https://example.test/wp-admin/admin.php?page=avbk-members&member_id=71&refund_tx=5">Anna Jansen</a>.'), 'No returned payment found: links to Terugbetalen of each account holder');
check(str_contains(avbk_expense_refund_links([], 7), 'via het <a href="https://example.test/wp-admin/admin.php?page=avbk-members">Ledenoverzicht</a>'), 'Nobody known: a link to the Ledenoverzicht');
check(substr_count(avbk_expense_refund_links([$GLOBALS['member_rows'][71]], 7), '<a ') === 1 && !str_contains(avbk_expense_refund_links([$GLOBALS['member_rows'][71]], 7), ' of '), 'One member: one link');
check(str_contains(avbk_expense_refund_links(array_values($GLOBALS['member_rows']), 7), 'Anna Jansen</a>, <a'), 'Three members: a list ending in "of"');

// Notices
foreach (['mailed' => 'notice-success', 'not_mailed' => 'mail is niet verstuurd', 'no_address' => 'geen bruikbaar e-mailadres', 'odd' => 'heeft een mail gekregen'] as $outcome => $expected) {
    $_GET = ['document_requested' => $outcome];
    ob_start();
    avbk_render_expenses_notices();
    check(str_contains(ob_get_clean(), $expected), "Notice for $outcome");
}
$_GET = ['explain_failed' => 'request_failed'];
ob_start();
avbk_render_expenses_notices();
check(str_contains(ob_get_clean(), 'Kies het lid aan wie je de bon vraagt'), 'A refused request says to choose the member');
$_GET = [];

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
