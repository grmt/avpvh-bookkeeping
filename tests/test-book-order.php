<?php
/**
 * Standalone regression checks for the anniversary book ordering flow.
 * No real names in tests or test data (see AGENTS.md).
 */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
define('AVBK_PLUGIN_URL', 'https://example.test/wp-content/plugins/avpvh-bookkeeping/');

class WP_Error {
    public function __construct(private string $code, private string $message) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}

class AVPVH_Roles {
    public static function current_user_has_role(string $role): bool {
        return !empty($GLOBALS['current_roles'][$role]);
    }
}

class AVPVH_DB {
    public static function get_member(int $id): ?object {
        return $GLOBALS['members'][$id] ?? null;
    }
    public static function get_member_by_email(string $email): ?object {
        foreach ($GLOBALS['members'] as $m) {
            if (strcasecmp($m->email, $email) === 0) return $m;
        }
        return null;
    }
    public static function find_members_by_name(string $first, string $last): array {
        $found = [];
        foreach ($GLOBALS['members'] as $m) {
            if (strcasecmp($m->first_name, $first) === 0 && strcasecmp($m->last_name, $last) === 0) {
                $found[] = $m;
            }
        }
        return $found;
    }
    public static function ensure_identity(int $member_id, string $type, string $val): void {}
    public static function create_member(string $uid, string $first, string $suffix, string $last, $birth, string $status): int {
        static $auto_id = 100;
        $id = ++$auto_id;
        $GLOBALS['members'][$id] = (object) [
            'id' => $id,
            'first_name' => $first,
            'suffix' => $suffix,
            'last_name' => $last,
            'email' => $GLOBALS['pending_member_email'] ?? '',
            'status' => $status,
        ];
        return $id;
    }
    public static function update_member_with_audit(int $id, array $data, array $fmt): void {
        if (isset($GLOBALS['members'][$id])) {
            foreach ($data as $k => $v) $GLOBALS['members'][$id]->$k = $v;
        }
    }
    public static function get_participation(int $member_id, int $activity_id): ?object { return null; }
    public static function get_activity(int $activity_id): ?object { return null; }
    public static function get_all_flags(): array { return []; }
    public static function get_activities(): array { return []; }
}

class AVPVH_Directory {
    public static function user_exists(string $uid): bool { return false; }
    public static function create_user(string $uid, string $email, string $name): bool {
        $GLOBALS['pending_member_email'] = $email;
        return true;
    }
}

class AVBK_QR {
    public static function for_fee_item(int $member_id, object $item): string {
        return '<svg viewBox="0 0 100 100"><rect width="100" height="100"/></svg>';
    }
    public static function fee_reference_code(int $member_id, array $ids): string {
        return 'PVH-' . $member_id;
    }
}

function avpvh_format_name(object $member, string $format = ''): string {
    return trim($member->first_name . ' ' . ($member->suffix ?? '')) . ' ' . $member->last_name;
}
function avpvh_get_member_by_wp_user(int $user_id): ?object {
    return $GLOBALS['wp_user_member'][$user_id] ?? null;
}
function avbk_asset_version(string $path): string { return '1.0'; }

function current_user_can(string $cap): bool { return !empty($GLOBALS['is_admin']); }
function get_current_user_id(): int { return $GLOBALS['current_user_id'] ?? 0; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function current_time(string $type): string {
    return $type === 'mysql' ? '2026-10-09 10:00:00' : '2026-10-09';
}
function wp_date(string $fmt, int $ts): string { return date($fmt, $ts); }
function get_option(string $name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option(string $name, $value): bool { $GLOBALS['options'][$name] = $value; return true; }
function sanitize_email(string $val): string { return trim($val); }
function sanitize_text_field(string $val): string { return trim(strip_tags($val)); }
function sanitize_textarea_field(string $val): string { return trim(strip_tags($val)); }
function sanitize_key(string $val): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($val)); }
function wp_unslash($val) { return $val; }
function is_email(string $val): bool { return (bool) filter_var($val, FILTER_VALIDATE_EMAIL); }
function is_wp_error($val): bool { return $val instanceof WP_Error; }
function wp_generate_password(int $len = 12, bool $special = false, bool $extra = false): string {
    return substr(bin2hex(random_bytes(ceil($len / 2))), 0, $len);
}
function check_admin_referer(string $action): void {
    if (empty($GLOBALS['valid_nonce'])) throw new RuntimeException("Invalid nonce for $action");
}
function wp_nonce_field(string $action): void {
    echo '<input type="hidden" name="_wpnonce" value="test-nonce">';
}
function wp_safe_redirect(string $url): void {
    $GLOBALS['last_redirect'] = $url;
    throw new RuntimeException("Redirect: $url");
}
function add_query_arg(...$args): string {
    if (count($args) === 1) {
        return '?' . http_build_query($args[0]);
    }
    if (count($args) === 2) {
        if (is_array($args[0])) {
            $url = $args[1];
            $sep = str_contains($url, '?') ? '&' : '?';
            return $url . $sep . http_build_query($args[0]);
        }
        return '?' . http_build_query([$args[0] => $args[1]]);
    }
    if (count($args) === 3) {
        $url = $args[2];
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . http_build_query([$args[0] => $args[1]]);
    }
    return '';
}
function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
function home_url(string $path = ''): string { return 'https://example.test' . $path; }
function get_permalink(): string { return 'https://example.test/jubileumboek/'; }
function esc_html(string $val): string { return htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); }
function esc_attr(string $val): string { return esc_html($val); }
function esc_textarea(string $val): string { return esc_html($val); }
function esc_url(string $val): string { return esc_attr($val); }
function esc_url_raw(string $val): string { return $val; }
function wp_mail(string $to, string $subject, string $body, $headers = []): bool {
    $GLOBALS['sent_mails'][] = compact('to', 'subject', 'body');
    return !empty($GLOBALS['mail_success']);
}
function add_action(string $tag, $callback): void {}
function remove_action(string $tag, $callback): void {}
function add_shortcode(string $tag, $callback): void { $GLOBALS['shortcodes'][$tag] = $callback; }
function wp_enqueue_style(string $handle, string $src, array $deps = [], $ver = false): void {}
function selected($val, $current, bool $echo = true) {
    $res = ((string) $val === (string) $current) ? ' selected="selected"' : '';
    if ($echo) echo $res;
    return $res;
}
function checked($val, $current = true, bool $echo = true) {
    $res = ((bool) $val === (bool) $current) ? ' checked="checked"' : '';
    if ($echo) echo $res;
    return $res;
}
function submit_button(string $text = 'Opslaan'): void {
    echo '<button type="submit">' . esc_html($text) . '</button>';
}
function add_menu_page(...$args): void {}
function add_submenu_page(...$args): void {}
function wp_die(string $msg = '', int $code = 403): void {
    throw new RuntimeException("wp_die: $msg ($code)");
}

class Mock_WPDB {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public array $book_orders = [];
    public array $fee_items = [];
    public array $addresses = [];
    public array $allocations = [];

    public function prepare(string $query, ...$args): string {
        $replaced = $query;
        foreach ($args as $arg) {
            $val = is_numeric($arg) ? $arg : "'" . addslashes((string) $arg) . "'";
            $replaced = preg_replace('/%[sdf]/', (string) $val, $replaced, 1);
        }
        return $replaced;
    }

    public function esc_like(string $val): string { return addcslashes($val, '_%\\'); }
    public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }

    public function insert(string $table, array $data): int {
        $this->insert_id++;
        $id = $this->insert_id;
        $row = array_merge(['id' => $id, 'created_at' => current_time('mysql')], $data);

        if (str_contains($table, 'avb_book_orders')) {
            $this->book_orders[$id] = (object) $row;
        } elseif (str_contains($table, 'avb_fee_items')) {
            $this->fee_items[$id] = (object) $row;
        } elseif (str_contains($table, 'avm_addresses')) {
            $this->addresses[$id] = (object) $row;
        }
        return 1;
    }

    public function update(string $table, array $data, array $where): int {
        if (str_contains($table, 'avb_book_orders')) {
            $id = (int) ($where['id'] ?? 0);
            if (isset($this->book_orders[$id])) {
                foreach ($data as $k => $v) $this->book_orders[$id]->$k = $v;
                return 1;
            }
        } elseif (str_contains($table, 'avm_addresses')) {
            $id = (int) ($where['id'] ?? 0);
            if (isset($this->addresses[$id])) {
                foreach ($data as $k => $v) $this->addresses[$id]->$k = $v;
                return 1;
            }
        }
        return 0;
    }

    public function query(string $query): int {
        // Handle confirm_book_order
        if (preg_match('/UPDATE .*avb_book_orders SET status = \'confirmed\'.*WHERE id = (\d+)/', $query, $m)) {
            $id = (int) $m[1];
            if (isset($this->book_orders[$id])) {
                $this->book_orders[$id]->status = 'confirmed';
                if (empty($this->book_orders[$id]->confirmed_at)) {
                    $this->book_orders[$id]->confirmed_at = current_time('mysql');
                }
                return 1;
            }
        }
        // Handle closing addresses: SET valid_until = ... WHERE member_id = ...
        if (preg_match('/UPDATE .*avm_addresses\s+SET valid_until = \'([^\']+)\'\s+WHERE member_id = (\d+)/', $query, $m)) {
            $valid_until = $m[1];
            $mid = (int) $m[2];
            foreach ($this->addresses as $addr) {
                if ((int) $addr->member_id === $mid && (empty($addr->valid_until) || $addr->valid_until > $valid_until)) {
                    $addr->valid_until = $valid_until;
                }
            }
            return 1;
        }
        return 1;
    }

    public function get_row(string $query): ?object {
        if (str_contains($query, 'avb_book_orders')) {
            if (preg_match('/WHERE id = (\d+)/', $query, $m)) {
                return $this->book_orders[(int) $m[1]] ?? null;
            }
            if (preg_match('/WHERE confirm_token = \'([^\']+)\'/', $query, $m)) {
                foreach ($this->book_orders as $o) {
                    if ($o->confirm_token === $m[1]) return $o;
                }
            }
        }
        if (str_contains($query, 'avb_fee_items')) {
            if (preg_match('/WHERE id = (\d+)/', $query, $m)) {
                return $this->fee_items[(int) $m[1]] ?? null;
            }
        }
        if (str_contains($query, 'avm_addresses')) {
            if (preg_match('/WHERE member_id = (\d+)/', $query, $m)) {
                $mid = (int) $m[1];
                $active = [];
                foreach ($this->addresses as $a) {
                    if ((int) $a->member_id === $mid && (empty($a->valid_until) || $a->valid_until >= current_time('Y-m-d'))) {
                        $active[] = $a;
                    }
                }
                return end($active) ?: null;
            }
        }
        return null;
    }

    public function get_var(string $query) {
        if (str_contains($query, 'COUNT(*)') && str_contains($query, 'avb_book_orders')) {
            $count = 0;
            foreach ($this->book_orders as $o) {
                if ($o->status === 'confirmed' && $o->distribution_status === 'pending') {
                    $count++;
                }
            }
            return $count;
        }
        if (str_contains($query, 'SELECT quantity FROM') && str_contains($query, 'avb_book_orders')) {
            if (preg_match('/fee_item_id = (\d+)/', $query, $m)) {
                $fid = (int) $m[1];
                foreach ($this->book_orders as $o) {
                    if ((int) ($o->fee_item_id ?? 0) === $fid) return $o->quantity;
                }
            }
        }
        return null;
    }

    public function get_results(string $query): array {
        if (str_contains($query, 'avb_book_orders')) {
            $list = [];
            foreach ($this->book_orders as $o) {
                $row = clone $o;
                $row->fee_amount_due = $this->fee_items[$o->fee_item_id]->amount_due ?? $o->total_amount;
                $row->fee_status = $this->fee_items[$o->fee_item_id]->status ?? 'open';
                $row->fee_paid = 0.0;
                $list[] = $row;
            }
            return $list;
        }
        return [];
    }
}

require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-book-order.php';

function check(bool $condition, string $msg): void {
    if (!$condition) throw new RuntimeException("FAILED: $msg");
    echo "PASS $msg\n";
}

function reset_test_env(): void {
    $GLOBALS['wpdb'] = new Mock_WPDB();
    $GLOBALS['options'] = [
        'avbk_book_title' => 'Doorgraven! - 50 jaar AV Philips van Horne',
        'avbk_book_price' => 35.00,
        'avbk_book_price_note' => 'Richtprijs circa € 35,- (definitieve prijs wordt nader vastgesteld)',
        'avbk_book_distribution_notice' => 'Let op: boeken worden niet per post verzonden, maar kunnen worden opgehaald of worden uitgereikt.',
        'avbk_book_presentation_notice' => 'Begin 2027 organiseren we ergens een feestelijke boekpresentatie.',
        'avbk_book_flaptekst' => AVBK_Book_Order::DEFAULT_FLAPTEKST,
    ];
    $GLOBALS['members'] = [
        1 => (object) [
            'id' => 1,
            'first_name' => 'Anna',
            'suffix' => 'van',
            'last_name' => 'Dijk',
            'email' => 'anna@example.test',
            'phone' => '0612345678',
            'status' => 'active',
        ],
    ];
    $GLOBALS['wp_user_member'] = [
        42 => $GLOBALS['members'][1],
    ];
    $GLOBALS['current_user_id'] = 0;
    $GLOBALS['is_admin'] = false;
    $GLOBALS['current_roles'] = [];
    $GLOBALS['valid_nonce'] = true;
    $GLOBALS['mail_success'] = true;
    $GLOBALS['sent_mails'] = [];
    $GLOBALS['last_redirect'] = '';
}

// -------------------------------------------------------------------------
// Test Suite
// -------------------------------------------------------------------------
reset_test_env();

// 1. Order creation
$created = AVBK_DB::create_book_order([
    'first_name' => 'Bram',
    'suffix' => '',
    'last_name' => 'Bakker',
    'email' => 'bram@example.test',
    'phone' => '0698765432',
    'street' => 'Kerkstraat',
    'house_number' => '10',
    'postal_code' => '1234 AB',
    'city' => 'Dorp',
    'country' => 'Nederland',
    'quantity' => 2,
    'unit_price' => 35.00,
    'total_amount' => 70.00,
    'attend_presentation' => 1,
    'keep_updated' => 1,
    'status' => 'pending_confirmation',
]);

check(!empty($created['id']), 'Order created with numeric ID');
check(strlen($created['token']) === 43, 'Order created with 43-character token');

$order = AVBK_DB::get_book_order($created['id']);
check($order !== null && $order->first_name === 'Bram', 'Order fetched by ID matches name');
check((int) $order->quantity === 2, 'Quantity stored accurately as 2');
check((float) $order->total_amount === 70.00, 'Total amount stored accurately as 70.00');
check((int) $order->attend_presentation === 1, 'Presentation interest flag stored');
check((int) $order->keep_updated === 1, 'Keep updated flag stored');
check($order->distribution_status === 'pending', 'Default distribution status is pending');

// 2. Lookup by token
$by_token = AVBK_DB::get_book_order_by_token($created['token']);
check($by_token !== null && (int) $by_token->id === $created['id'], 'Order fetched by unique token');

// 3. Confirm order
check($order->status === 'pending_confirmation', 'Initial status is pending_confirmation');
AVBK_DB::confirm_book_order($created['id']);
$confirmed = AVBK_DB::get_book_order($created['id']);
check($confirmed->status === 'confirmed', 'Order marked confirmed');
check(!empty($confirmed->confirmed_at), 'confirmed_at set upon confirmation');
$first_confirmed_at = $confirmed->confirmed_at;
AVBK_DB::confirm_book_order($created['id']);
$confirmed_again = AVBK_DB::get_book_order($created['id']);
check($confirmed_again->confirmed_at === $first_confirmed_at, 'confirm_book_order is idempotent');

// 4. Distribution status updates
check(AVBK_DB::update_book_order_distribution($created['id'], 'collected'), 'Valid distribution update succeeds');
$order_dist = AVBK_DB::get_book_order($created['id']);
check($order_dist->distribution_status === 'collected', 'Distribution status changed to collected');
check(!AVBK_DB::update_book_order_distribution($created['id'], 'invalid_status'), 'Invalid distribution status rejected');

// 5. Count pending distribution
$count_pending = AVBK_DB::count_pending_distribution_book_orders();
check($count_pending === 0, 'Collected order does not count as pending distribution');
AVBK_DB::update_book_order_distribution($created['id'], 'pending');
check(AVBK_DB::count_pending_distribution_book_orders() === 1, 'Pending order counts in pending distribution tally');

// 6. Address management
AVBK_DB::save_member_address(1, [
    'street' => 'Hoofdstraat',
    'house_number' => '42',
    'postal_code' => '5678 CD',
    'city' => 'Stad',
    'country' => 'Nederland',
]);
$addr1 = AVBK_DB::get_member_active_address(1);
check($addr1 !== null && $addr1->street === 'Hoofdstraat', 'Address saved for member');
check(empty($addr1->valid_until), 'Address is currently active (valid_until is null)');

// Saving the same address does not create duplicate rows
$addr_count_before = count($GLOBALS['wpdb']->addresses);
AVBK_DB::save_member_address(1, [
    'street' => 'Hoofdstraat',
    'house_number' => '42',
    'postal_code' => '5678 CD',
    'city' => 'Stad',
    'country' => 'Nederland',
]);
check(count($GLOBALS['wpdb']->addresses) === $addr_count_before, 'Duplicate address submission ignored');

// Saving updated address closes previous address and adds new one
AVBK_DB::save_member_address(1, [
    'street' => 'Nieuwestraat',
    'house_number' => '1',
    'postal_code' => '9999 ZZ',
    'city' => 'Stad',
    'country' => 'Nederland',
]);
$addr_active = AVBK_DB::get_member_active_address(1);
check($addr_active->street === 'Nieuwestraat', 'Active address updated to new street');
check(!empty($GLOBALS['wpdb']->addresses[$addr1->id]->valid_until), 'Previous address closed with valid_until date');

// 7. Book fee item and quantity label
$fee_item_id = AVBK_DB::create_book_fee_item(1, 2, 70.00, 'Doorgraven! - 50 jaar AV Philips van Horne');
check($fee_item_id > 0, 'Book fee item created in database');
$fee_item = $GLOBALS['wpdb']->fee_items[$fee_item_id];
check($fee_item->type === 'other' && $fee_item->category === 'book', 'Fee item has type=other and category=book');
check((float) $fee_item->amount_due === 70.00, 'Fee item has correct amount_due');

// Link order to fee item
$GLOBALS['wpdb']->book_orders[$created['id']]->fee_item_id = $fee_item_id;
$qty_label = AVBK_DB::fee_item_quantity_label($fee_item);
check($qty_label === '2 exemplaren', 'fee_item_quantity_label returns "2 exemplaren"');

$single_fee_id = AVBK_DB::create_book_fee_item(1, 1, 35.00);
$single_order = AVBK_DB::create_book_order(['fee_item_id' => $single_fee_id, 'quantity' => 1]);
$single_fee_item = $GLOBALS['wpdb']->fee_items[$single_fee_id];
check(AVBK_DB::fee_item_quantity_label($single_fee_item) === '1 exemplaar', 'fee_item_quantity_label returns "1 exemplaar"');

// 8. Frontend Shortcode Form Rendering
$book_order = new AVBK_Book_Order();
$form_html = $book_order->render();
check(str_contains($form_html, 'Over het boek'), 'Order form contains book flaptekst heading');
check(str_contains($form_html, 'Boekpresentatie begin 2027'), 'Order form contains 2027 presentation section');
check(str_contains($form_html, 'boeken worden niet per post verzonden'), 'Order form includes distribution notice');
check(str_contains($form_html, '35,00'), 'Order form displays 35,00 price');
check(str_contains($form_html, 'name="first_name"'), 'Guest sees name fields');

// 9. Logged-in member rendering pre-fills address
$GLOBALS['current_user_id'] = 42; // Member Anna
$member_form_html = $book_order->render();
check(str_contains($member_form_html, 'Ingelogd als:'), 'Member sees logged-in identification');
check(str_contains($member_form_html, 'Nieuwestraat'), 'Member address pre-filled with active address');
check(!str_contains($member_form_html, 'name="first_name"'), 'Member does not see redundant name input');

// 10. Guest order submission flow
reset_test_env();
$_POST = [
    'action' => 'avbk_book_order',
    '_wpnonce' => 'test-nonce',
    'page_url' => 'https://example.test/jubileumboek/',
    'website' => '', // honeypot empty
    'first_name' => 'Cas',
    'suffix' => '',
    'last_name' => 'Vermeer',
    'email' => 'cas@example.test',
    'phone' => '0611223344',
    'street' => 'Molenweg',
    'house_number' => '5',
    'postal_code' => '4321 XY',
    'city' => 'Dorp',
    'country' => 'Nederland',
    'quantity' => '1',
    'attend_presentation' => '1',
    'keep_updated' => '1',
    'notes' => 'Graag gesigneerd exemplaar als dat kan',
];

try {
    $book_order->handle_order();
    check(false, 'Expected redirect after guest order');
} catch (RuntimeException $e) {
    echo "Actual redirect: " . $e->getMessage() . "\n";
    check(str_contains($e->getMessage(), 'book_ordered=1'), 'Guest redirected to thank you page');
}
check(count($GLOBALS['sent_mails']) === 1, 'Confirmation email dispatched to guest');
check(str_contains($GLOBALS['sent_mails'][0]['to'], 'cas@example.test'), 'Email sent to correct guest recipient');
check(str_contains($GLOBALS['sent_mails'][0]['body'], 'book_token='), 'Email contains unique token confirmation link');

// 11. Confirmation view with token
$guest_order = end($GLOBALS['wpdb']->book_orders);
check($guest_order->status === 'pending_confirmation', 'Guest order starts as pending_confirmation');

$_GET = ['book_token' => $guest_order->confirm_token];
$conf_html = $book_order->render();
check(str_contains($conf_html, 'Bestelling bevestigd'), 'Confirmation view displayed');
check(str_contains($conf_html, 'Molenweg 5'), 'Confirmation view shows registered address');
check(str_contains($conf_html, 'Cas'), 'Confirmation view greets customer');
$refreshed_guest_order = AVBK_DB::get_book_order($guest_order->id);
check($refreshed_guest_order->status === 'confirmed', 'Viewing confirmation page marks order as confirmed');

// 12. Logged-in member order submission flow
reset_test_env();
$GLOBALS['current_user_id'] = 42; // Logged-in member
$_POST = [
    'action' => 'avbk_book_order',
    '_wpnonce' => 'test-nonce',
    'page_url' => 'https://example.test/jubileumboek/',
    'website' => '',
    'street' => 'Dorpsstraat',
    'house_number' => '15',
    'postal_code' => '5555 AA',
    'city' => 'Weert',
    'country' => 'Nederland',
    'quantity' => '3',
    'attend_presentation' => '1',
    'keep_updated' => '0',
    'notes' => '',
];

try {
    $book_order->handle_order();
    check(false, 'Expected redirect after member order');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'book_token='), 'Member redirected immediately to confirmation page');
}
$member_order = end($GLOBALS['wpdb']->book_orders);
check($member_order->status === 'confirmed', 'Logged-in member order confirmed immediately');
check((int) $member_order->quantity === 3, 'Member order quantity is 3');
check((float) $member_order->total_amount === 105.00, 'Member order total amount is 3 * 35.00 = 105.00');
$saved_addr = AVBK_DB::get_member_active_address(1);
check($saved_addr->street === 'Dorpsstraat', 'Member address updated in avm_addresses table');

// 13. Honeypot traps bots silently
$_POST['website'] = 'https://spambot.test';
try {
    $book_order->handle_order();
    check(false, 'Expected redirect for bot');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'book_ordered=1'), 'Bot redirected to thank-you without creating order');
}

// 14. Admin distribution update permission check
require_once AVBK_PLUGIN_DIR . 'includes/class-admin.php';
$admin = new AVBK_Admin();

$GLOBALS['is_admin'] = false;
$GLOBALS['current_roles'] = [];
$_POST = [
    'action' => 'avbk_update_book_distribution',
    '_wpnonce' => 'test-nonce',
    'order_id' => $member_order->id,
    'distribution_status' => 'distributed',
];

try {
    $admin->handle_update_book_distribution();
    check(false, 'Unauthorized user should be blocked');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'wp_die: Geen toegang'), 'Unauthorized user blocked from updating distribution');
}

// 15. Authorized admin updates distribution status
$GLOBALS['current_roles'] = ['penningmeester' => true];
try {
    $admin->handle_update_book_distribution();
    check(false, 'Expected redirect after distribution update');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'distribution_updated=1'), 'Redirect with distribution_updated parameter');
}
$updated_member_order = AVBK_DB::get_book_order($member_order->id);
check($updated_member_order->distribution_status === 'distributed', 'Order distribution status updated to distributed');

echo "\nAll book order tests passed cleanly!\n";

