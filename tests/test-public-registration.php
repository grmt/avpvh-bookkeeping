<?php
/** Public form security regressions, using the book suite's synthetic fixtures. */
require __DIR__ . '/test-book-order.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-tshirt-order.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-congress.php';

class Public_Security_WPDB extends Mock_WPDB {
    public array $registrations = [];
    public bool $lock_available = true;
    public function get_var(string $query) {
        if (!$this->lock_available && str_contains($query, 'GET_LOCK(')) return 0;
        return parent::get_var($query);
    }
    public function insert(string $table, array $data): int {
        if (str_contains($table, 'avb_congress_registrations')) {
            $this->insert_id++;
            $this->registrations[$this->insert_id] = (object) ($data + ['id' => $this->insert_id]);
            return 1;
        }
        return parent::insert($table, $data);
    }
    public function get_row(string $query): ?object {
        if (str_contains($query, 'avb_congress_registrations')) {
            if (preg_match('/WHERE id = (\d+)/', $query, $m)) return $this->registrations[(int) $m[1]] ?? null;
        }
        return parent::get_row($query);
    }
    public function update(string $table, array $data, array $where): int {
        if (str_contains($table, 'avb_congress_registrations')) {
            $reg = $this->registrations[$where['id']] ?? null;
            if (!$reg) return 0;
            foreach ($data as $key => $value) $reg->$key = $value;
            return 1;
        }
        return parent::update($table, $data, $where);
    }
}
function security_redirect(callable $action): string {
    try { $action(); }
    catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'Redirect: ')) return substr($e->getMessage(), 10);
        throw $e;
    }
    throw new RuntimeException('Expected a redirect');
}

$fallback = home_url('/boek/');
foreach (['https://attacker.test/boek/', '//attacker.test/', 'javascript:alert(1)',
    'https://example.test.attacker.test/', 'https://example.test@attacker.test/',
    'https://attacker.test@example.test/', 'http://example.test/', 'https://example.test:8443/', ['url']] as $url) {
    check(avbk_public_page_url($url, $fallback) === $fallback, 'Untrusted page URL falls back to the site');
}
check(avbk_public_page_url('https://example.test/event/?redirect_to=https://attacker.test/#token', $fallback) === home_url('/event/'), 'Page query strings and fragments do not enter confirmation links');

foreach (['book', 'tshirt', 'congress'] as $kind) {
    reset_test_env();
    $GLOBALS['wpdb'] = new Public_Security_WPDB();
    $db = $GLOBALS['wpdb'];
    $_POST = ['first_name' => 'Anna', 'last_name' => 'Dijk', 'email' => 'anna@example.test',
        'phone' => '0600000000', 'quantity' => '2', 'page_url' => 'https://attacker.test/verify',
        'items' => [['quantity' => '2', 'size' => 'M']]];
    $controller = match ($kind) {
        'book' => new AVBK_Book_Order(), 'tshirt' => new AVBK_Tshirt_Order(), 'congress' => new AVBK_Congress(),
    };
    $submit = fn() => $kind === 'congress' ? $controller->handle_register() : $controller->handle_order();
    $url = security_redirect($submit);
    $request = $kind === 'congress' ? end($db->registrations) : end($db->orders);
    check(empty($request->member_id) && empty($request->fee_item_id), "$kind guest request remains unlinked before confirmation");
    check(empty($db->fee_items) && count($GLOBALS['members']) === 1, "$kind request creates no fee or member before confirmation");
    check(!str_contains($url, $request->confirm_token) && !str_contains($url, 'attacker.test'), "$kind redirect does not expose its bearer token");
    check(!str_contains(end($GLOBALS['sent_mails'])['body'], 'attacker.test'), "$kind email rejects the injected external URL");
    if ($kind === 'congress') AVBK_DB::confirm_congress_registration($request->id);
    else AVBK_DB::confirm_order($request->id);
    check($request->status === 'confirmed' && $request->member_id === 1, "$kind confirmation resolves the verified member email");
    if ($kind !== 'congress') {
        check(count($db->fee_items) === 1, "$kind confirmation creates one fee");
        AVBK_DB::confirm_order($request->id);
        check(count($db->fee_items) === 1, "$kind repeated confirmation creates no extra fee");
    }
    // Mail failure must not turn an unverified request into a charge or token redirect.
    $GLOBALS['mail_success'] = false;
    $url = security_redirect($submit);
    $failed = $kind === 'congress' ? end($db->registrations) : end($db->orders);
    check($failed->status === 'pending_confirmation' && empty($failed->fee_item_id), "$kind mail failure leaves the request pending");
    check(str_contains($url, 'email_failed') && !str_contains($url, $failed->confirm_token), "$kind mail failure does not disclose the token");
    $GLOBALS['mail_success'] = true;
    security_redirect($submit);
    $before = count($kind === 'congress' ? $db->registrations : $db->orders);
    $url = security_redirect($submit);
    check(str_contains($url, 'rate_limited') && $before === count($kind === 'congress' ? $db->registrations : $db->orders), "$kind repeated recipient requests are limited before writes");
}

// Email access plus a copied name never claims a member's blank/placeholder address.
reset_test_env();
$GLOBALS['members'][1]->email = 'anna@avpvh.local';
$res = AVBK_DB::find_or_create_member_for_registration('Anna', '', 'Dijk', 'other@example.test', '', false);
check($res['member_id'] !== 1 && $GLOBALS['members'][1]->email === 'anna@avpvh.local', 'Verified different email cannot claim a placeholder record by name');
check($res['review_note'] !== '', 'Potential name duplicate is flagged for review');
$GLOBALS['members'][1]->email = '';
$res = AVBK_DB::find_or_create_member_for_registration('Anna', '', 'Dijk', 'another@example.test', '', false);
check($res['member_id'] !== 1 && $GLOBALS['members'][1]->email === '', 'Verified different email cannot claim a blank email record by name');

foreach (['book', 'tshirt'] as $kind) {
    foreach (['100', '-1', '1.5', '999999999999999999999999'] as $quantity) {
        reset_test_env();
        $_POST = ['first_name' => 'Cas', 'last_name' => 'Jansen', 'email' => 'cas@example.test', 'quantity' => $quantity,
            'items' => [['quantity' => $quantity, 'size' => 'M']]];
        $controller = $kind === 'book' ? new AVBK_Book_Order() : new AVBK_Tshirt_Order();
        $url = security_redirect(fn() => $controller->handle_order());
        check(str_contains($url, 'invalid_quantity') && empty($GLOBALS['wpdb']->orders) && empty($GLOBALS['wpdb']->fee_items), "$kind rejects invalid quantity before creating records");
    }
}
reset_test_env();
$_POST = ['items' => [['quantity' => 60], ['quantity' => 60]]];
$url = security_redirect(fn() => (new AVBK_Tshirt_Order())->handle_order());
check(str_contains($url, 'invalid_quantity'), 'Garment quantity limit applies to the aggregate across lines');
reset_test_env();
for ($i = 0; $i < 10; $i++) check(avbk_public_registration_allowed("recipient$i@example.test"), 'Registration traffic accepted within IP budget');
check(!avbk_public_registration_allowed('next@example.test'), 'IP budget blocks changing recipients');
check(!AVBK_DB::is_https_payment_url('javascript:alert(1)') && !AVBK_DB::is_https_payment_url('http://pay.example.test/'), 'Payment links reject unsafe schemes');
check(AVBK_DB::is_https_payment_url('https://pay.example.test/payment'), 'Payment links accept HTTPS');
echo "Public registration security tests passed.\n";

$GLOBALS['wpdb'] = new Public_Security_WPDB();
$GLOBALS['wpdb']->lock_available = false;
check(!avbk_public_registration_allowed('blocked@example.test'), 'Unavailable form lock leaves the request unprocessed');

// Error responses keep the form usable and explain retry/quantity choices.
foreach (['book', 'tshirt', 'congress'] as $kind) {
    reset_test_env();
    $controller = match ($kind) { 'book' => new AVBK_Book_Order(), 'tshirt' => new AVBK_Tshirt_Order(), 'congress' => new AVBK_Congress() };
    foreach (['rate_limited' => 'Te veel aanvragen', 'email_failed' => 'bevestigingsmail kon niet', 'invalid_quantity' => $kind === 'book' ? '99' : 'maximaal 99'] as $error => $message) {
        if ($kind === 'congress' && $error === 'invalid_quantity') continue;
        $_GET = [$kind . '_error' => $error];
        $html = $controller->render();
        check(str_contains($html, $message), "$kind form displays an actionable response for $error");
    }
}
$_GET = [];
(new AVBK_Book_Order())->enqueue_assets();
(new AVBK_Tshirt_Order())->enqueue_assets();
check(($GLOBALS['scripts']['avbk-tshirt-order'] ?? []) === ['avbk-order-email-links'], 'Order forms load shared account-link formatting before the garment script');
