<?php
/**
 * Standalone test suite for generalized orders (avb_orders, avb_order_items),
 * multi-item T-shirt orders, and the purchasing inkoopmatrix.
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
    public static function get_identity_by_email(string $email): ?object {
        return null;
    }
    public static function get_login_stats_for_email(string $email): object {
        $first = $GLOBALS['login_stats'][$email]['first_login'] ?? null;
        $last  = $GLOBALS['login_stats'][$email]['last_login'] ?? null;
        return (object) ['first_login' => $first, 'last_login' => $last];
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
function wp_json_encode($data): string { return json_encode($data); }
function get_transient(string $key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient(string $key, $val, int $ttl = 0): bool { $GLOBALS['transients'][$key] = $val; return true; }
function delete_transient(string $key): bool { unset($GLOBALS['transients'][$key]); return true; }
function wp_verify_nonce(string $nonce, string $action = ''): bool { return !empty($GLOBALS['valid_nonce']); }
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
function get_permalink(): string { return 'https://example.test/tshirt/'; }
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
function wp_enqueue_script(string $handle, string $src, array $deps = [], $ver = false, bool $in_footer = false): void {}
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

class Mock_Orders_WPDB {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public array $orders = [];
    public array $order_items = [];
    public array $fee_items = [];
    public array $addresses = [];

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

        if (str_contains($table, 'avb_orders') && !str_contains($table, 'order_items')) {
            $this->orders[$id] = (object) $row;
        } elseif (str_contains($table, 'avb_order_items')) {
            $this->order_items[$id] = (object) $row;
        } elseif (str_contains($table, 'avb_fee_items')) {
            $this->fee_items[$id] = (object) $row;
        } elseif (str_contains($table, 'avm_addresses')) {
            $this->addresses[$id] = (object) $row;
        }
        return 1;
    }

    public function update(string $table, array $data, array $where): int {
        if (str_contains($table, 'avb_orders') && !str_contains($table, 'order_items')) {
            $id = (int) ($where['id'] ?? 0);
            if (isset($this->orders[$id])) {
                foreach ($data as $k => $v) $this->orders[$id]->$k = $v;
                return 1;
            }
        }
        return 0;
    }

    public function query(string $query): int {
        if (preg_match('/UPDATE .*avb_orders SET status = \'confirmed\'.*WHERE id = (\d+)/', $query, $m)) {
            $id = (int) $m[1];
            if (isset($this->orders[$id])) {
                $this->orders[$id]->status = 'confirmed';
                if (empty($this->orders[$id]->confirmed_at)) {
                    $this->orders[$id]->confirmed_at = current_time('mysql');
                }
                return 1;
            }
        }
        return 1;
    }

    public function get_row(string $query): ?object {
        if (str_contains($query, 'avb_orders') && !str_contains($query, 'order_items')) {
            if (preg_match('/WHERE id = (\d+)/', $query, $m)) {
                return $this->orders[(int) $m[1]] ?? null;
            }
            if (preg_match('/WHERE confirm_token = \'([^\']+)\'/', $query, $m)) {
                foreach ($this->orders as $o) {
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
        if (str_contains($query, 'COUNT(*)') && str_contains($query, 'avb_orders')) {
            $count = 0;
            $type_match = null;
            if (preg_match('/order_type = \'([^\']+)\'/', $query, $tm)) {
                $type_match = $tm[1];
            }
            foreach ($this->orders as $o) {
                if ($o->status === 'confirmed' && $o->distribution_status === 'pending') {
                    if ($type_match === null || $o->order_type === $type_match) {
                        $count++;
                    }
                }
            }
            return $count;
        }
        if (str_contains($query, 'SELECT SUM(i.quantity)')) {
            if (preg_match('/fee_item_id = (\d+)/', $query, $m)) {
                $fid = (int) $m[1];
                $sum = 0;
                foreach ($this->orders as $o) {
                    if ((int) ($o->fee_item_id ?? 0) === $fid) {
                        foreach ($this->order_items as $item) {
                            if ((int) $item->order_id === (int) $o->id) {
                                $sum += (int) $item->quantity;
                            }
                        }
                    }
                }
                return $sum > 0 ? $sum : null;
            }
        }
        return null;
    }

    public function get_results(string $query): array {
        if (str_contains($query, 'avb_order_items') && !str_contains($query, 'SUM(i.quantity)')) {
            if (preg_match('/WHERE order_id = (\d+)/', $query, $m)) {
                $oid = (int) $m[1];
                $res = [];
                foreach ($this->order_items as $item) {
                    if ((int) $item->order_id === $oid) $res[] = $item;
                }
                return $res;
            }
            if (preg_match('/WHERE order_id IN \(([^)]+)\)/', $query, $m)) {
                $oids = array_map('intval', explode(',', $m[1]));
                $res = [];
                foreach ($this->order_items as $item) {
                    if (in_array((int) $item->order_id, $oids, true)) $res[] = $item;
                }
                return $res;
            }
        }

        // Matrix aggregation query
        if (str_contains($query, 'SUM(i.quantity) AS qty') && str_contains($query, 'avb_order_items')) {
            $type_match = 'tshirt';
            if (preg_match('/o.order_type = \'([^\']+)\'/', $query, $m)) {
                $type_match = $m[1];
            }

            // Aggregate items of confirmed orders of this type
            $grouped = [];
            foreach ($this->order_items as $it) {
                $order = $this->orders[$it->order_id] ?? null;
                if ($order && $order->order_type === $type_match && $order->status === 'confirmed') {
                    $key = $it->title . '|' . $it->variant;
                    if (!isset($grouped[$key])) {
                        $grouped[$key] = [
                            'title'   => $it->title,
                            'variant' => $it->variant,
                            'qty'     => 0,
                            'eur'     => 0.0,
                        ];
                    }
                    $grouped[$key]['qty'] += (int) $it->quantity;
                    $grouped[$key]['eur'] += (float) $it->total_price;
                }
            }
            return array_values(array_map(fn($g) => (object) $g, $grouped));
        }

        if (str_contains($query, 'avb_orders') && !str_contains($query, 'order_items')) {
            $list = [];
            $type_match = null;
            if (preg_match('/o.order_type = \'([^\']+)\'/', $query, $m)) {
                $type_match = $m[1];
            }
            foreach ($this->orders as $o) {
                if ($type_match !== null && $o->order_type !== $type_match) {
                    continue;
                }
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
require_once AVBK_PLUGIN_DIR . 'includes/class-tshirt-order.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-admin.php';

function check(bool $condition, string $msg): void {
    if (!$condition) throw new RuntimeException("FAILED: $msg");
    echo "PASS $msg\n";
}

function reset_test_env(): void {
    $GLOBALS['wpdb'] = new Mock_Orders_WPDB();
    $GLOBALS['options'] = [
        'avbk_tshirt_title'               => 'Lustrum T-shirts 50 jaar AV Philips van Horne',
        'avbk_tshirt_price'               => 17.50,
        'avbk_tshirt_price_note'          => 'Richtprijs circa € 17,50 per stuk',
        'avbk_tshirt_intro'               => 'Kies je model en maat.',
        'avbk_tshirt_distribution_notice' => 'T-shirts worden uitgereikt tijdens het lustrum.',
        'avbk_tshirt_sizes'               => 'S, M, L, XL, XXL, 3XL',
        'avbk_tshirt_designs'             => AVBK_Tshirt_Order::DEFAULT_DESIGNS,
    ];
    $GLOBALS['members'] = [
        1 => (object) [
            'id' => 1,
            'first_name' => 'Bram',
            'suffix' => '',
            'last_name' => 'Jansen',
            'email' => 'bram@example.test',
            'phone' => '0612345678',
            'status' => 'active',
        ],
    ];
    $GLOBALS['valid_nonce'] = true;
    $GLOBALS['is_admin'] = false;
    $GLOBALS['current_roles'] = [];
    $GLOBALS['current_user_id'] = 0;
    $GLOBALS['mail_success'] = true;
    $GLOBALS['sent_mails'] = [];
}

// -------------------------------------------------------------
// Test 1: Direct unified order creation with multiple line items
// -------------------------------------------------------------
reset_test_env();

$order_data = [
    'order_type'   => 'tshirt',
    'first_name'   => 'Cas',
    'last_name'    => 'Peters',
    'email'        => 'cas@example.test',
    'street'       => 'Kerkstraat',
    'house_number' => '10',
    'postal_code'  => '6001AA',
    'city'         => 'Weert',
    'status'       => 'confirmed',
];

$items = [
    [
        'item_key'    => 'jubileum_zwart',
        'title'       => 'Jubileumlogo 50 jaar (Zwart)',
        'variant'     => 'L',
        'quantity'    => 2,
        'unit_price'  => 17.50,
        'total_price' => 35.00,
    ],
    [
        'item_key'    => 'dgeen_wit',
        'title'       => 'DGéén Doorgraven! (Wit)',
        'variant'     => 'M',
        'quantity'    => 1,
        'unit_price'  => 17.50,
        'total_price' => 17.50,
    ],
];

$res = AVBK_DB::create_order($order_data, $items);
check($res['id'] > 0, 'Unified order created with ID');
check(strlen($res['token']) === 43, 'Unique 43-character token generated');

$fetched = AVBK_DB::get_order($res['id']);
check($fetched !== null, 'Order fetched by ID');
check($fetched->order_type === 'tshirt', 'Order type is tshirt');
check((int) $fetched->quantity === 3, 'Total order quantity calculated as 3');
check((float) $fetched->total_amount === 52.50, 'Total order amount calculated as 52.50');
check(count($fetched->items) === 2, 'Two distinct line items attached');
check($fetched->items[0]->variant === 'L' && (int) $fetched->items[0]->quantity === 2, 'Item 1: 2x Size L');
check($fetched->items[1]->variant === 'M' && (int) $fetched->items[1]->quantity === 1, 'Item 2: 1x Size M');

// -------------------------------------------------------------
// Test 2: Inkoopmatrix calculation
// -------------------------------------------------------------
// Add a second confirmed order
$order_data2 = [
    'order_type' => 'tshirt',
    'first_name' => 'Dirk',
    'last_name'  => 'Verhoeven',
    'email'      => 'dirk@example.test',
    'status'     => 'confirmed',
];
$items2 = [
    [
        'item_key'    => 'jubileum_zwart',
        'title'       => 'Jubileumlogo 50 jaar (Zwart)',
        'variant'     => 'L',
        'quantity'    => 1,
        'unit_price'  => 17.50,
        'total_price' => 17.50,
    ],
    [
        'item_key'    => 'jubileum_zwart',
        'title'       => 'Jubileumlogo 50 jaar (Zwart)',
        'variant'     => 'XL',
        'quantity'    => 2,
        'unit_price'  => 17.50,
        'total_price' => 35.00,
    ],
];
AVBK_DB::create_order($order_data2, $items2);

$matrix = AVBK_DB::get_order_matrix('tshirt');
check(is_array($matrix['designs']), 'Matrix returns designs array');
check(in_array('S', $matrix['variants'], true) && in_array('L', $matrix['variants'], true), 'Matrix contains standard sizes');
// Jubileum zwart in size L: 2 from order 1 + 1 from order 2 = 3
check(($matrix['cells']['Jubileumlogo 50 jaar (Zwart)']['L'] ?? 0) === 3, 'Inkoopmatrix: 3x Jubileum Zwart in size L');
// Jubileum zwart in size XL: 2 from order 2 = 2
check(($matrix['cells']['Jubileumlogo 50 jaar (Zwart)']['XL'] ?? 0) === 2, 'Inkoopmatrix: 2x Jubileum Zwart in size XL');
// DGéén wit in size M: 1 from order 1 = 1
check(($matrix['cells']['DGéén Doorgraven! (Wit)']['M'] ?? 0) === 1, 'Inkoopmatrix: 1x DGéén Wit in size M');
// Grand total = 3 + 2 + 1 = 6 shirts
check($matrix['grand_total'] === 6, 'Inkoopmatrix grand total is 6 shirts');
check((float) $matrix['total_revenue'] === 105.00, 'Inkoopmatrix total revenue is 105.00 EUR');

// -------------------------------------------------------------
// Test 3: fee_item_quantity_label for tshirt
// -------------------------------------------------------------
$tshirt_fee_id = AVBK_DB::create_tshirt_fee_item(1, 3, 52.50, 'Lustrum T-shirts');
check($tshirt_fee_id > 0, 'T-shirt fee item created');
$tshirt_fee = $GLOBALS['wpdb']->fee_items[$tshirt_fee_id];
check($tshirt_fee->category === 'tshirt', 'Fee item category is tshirt');

// Link order 1 to fee item
$GLOBALS['wpdb']->orders[$res['id']]->fee_item_id = $tshirt_fee_id;
$label = AVBK_DB::fee_item_quantity_label($tshirt_fee);
check($label === '3 shirts', 'fee_item_quantity_label returns "3 shirts" for multiple');

$single_tshirt_fee_id = AVBK_DB::create_tshirt_fee_item(1, 1, 17.50);
$single_tshirt_order = AVBK_DB::create_order([
    'order_type'  => 'tshirt',
    'fee_item_id' => $single_tshirt_fee_id,
], [
    ['title' => 'Shirt', 'variant' => 'M', 'quantity' => 1, 'unit_price' => 17.50],
]);
$single_fee_item = $GLOBALS['wpdb']->fee_items[$single_tshirt_fee_id];
check(AVBK_DB::fee_item_quantity_label($single_fee_item) === '1 shirt', 'fee_item_quantity_label returns "1 shirt" for singular');

// -------------------------------------------------------------
// Test 4: Frontend T-shirt shortcode form rendering
// -------------------------------------------------------------
reset_test_env();
$controller = new AVBK_Tshirt_Order();
$html = $controller->render();

check(str_contains($html, 'Lustrum T-shirts 50 jaar'), 'Form contains title heading');
check(str_contains($html, 'Beschikbare designs'), 'Form displays designs gallery');
check(str_contains($html, 'Jubileumlogo 50 jaar (Zwart)'), 'Form lists active design 1');
check(str_contains($html, 'avbk-tshirt-items-table'), 'Form renders line items table repeater');
check(str_contains($html, 'avbk-add-shirt-btn'), 'Form contains "+ Extra T-shirt toevoegen" button');
check(str_contains($html, 'avbk-book-check-email-box'), 'Form contains email check banner for guests');

// -------------------------------------------------------------
// Test 5: Guest checkout with line items
// -------------------------------------------------------------
$_POST = [
    '_wpnonce'     => 'test-nonce',
    'first_name'   => 'Emma',
    'suffix'       => '',
    'last_name'    => 'Smeets',
    'email'        => 'emma@example.test',
    'phone'        => '0698765432',
    'street'       => 'Beekstraat',
    'house_number' => '5',
    'postal_code'  => '6001BB',
    'city'         => 'Weert',
    'country'      => 'Nederland',
    'items'        => [
        [
            'design'   => 'jubileum_zwart',
            'size'     => 'M',
            'quantity' => '1',
        ],
        [
            'design'   => 'pvh_navy',
            'size'     => 'S',
            'quantity' => '2',
        ],
    ],
];

try {
    $controller->handle_order();
    check(false, 'Expected redirect after order');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'tshirt_ordered=1'), 'Guest redirected to thank you verification screen');
}

check(count($GLOBALS['sent_mails']) === 1, 'Verification email sent to guest');
$mail = end($GLOBALS['sent_mails']);
check($mail['to'] === 'emma@example.test', 'Email sent to correct guest address');
check(str_contains($mail['body'], 'tshirt_token='), 'Email contains unique confirmation link with tshirt_token');
check(str_contains($mail['body'], '3 T-shirt(s)'), 'Email mentions 3 T-shirt(s)');

$last_order = end($GLOBALS['wpdb']->orders);
check($last_order->status === 'pending_confirmation', 'Guest order starts as pending_confirmation');
check((int) $last_order->quantity === 3, 'Guest order quantity is 3');
check((float) $last_order->total_amount === 52.50, 'Guest order total amount is 52.50');

// -------------------------------------------------------------
// Test 6: Confirming order via token
// -------------------------------------------------------------
$_GET['tshirt_token'] = $last_order->confirm_token;
$confirm_html = $controller->render();
unset($_GET['tshirt_token']);

check(str_contains($confirm_html, 'Bestelling bevestigd'), 'Confirmation screen rendered');
check(str_contains($confirm_html, 'Emma'), 'Confirmation greets customer');
check(str_contains($confirm_html, 'Beekstraat 5'), 'Registered address displayed');
check(str_contains($confirm_html, 'Jubileumlogo 50 jaar (Zwart)'), 'Summary table shows item 1');
check(str_contains($confirm_html, 'Archeologie Philips van Horne (Navy)'), 'Summary table shows item 2');
check($last_order->status === 'confirmed', 'Visiting confirmation link confirms order in database');

// -------------------------------------------------------------
// Test 7: Logged-in member checkout with line items
// -------------------------------------------------------------
$GLOBALS['current_user_id'] = 1;
$GLOBALS['wp_user_member'][1] = $GLOBALS['members'][1]; // Bram Jansen

$_POST = [
    '_wpnonce'     => 'test-nonce',
    'street'       => 'Molenstraat',
    'house_number' => '12',
    'postal_code'  => '6001CC',
    'city'         => 'Weert',
    'country'      => 'Nederland',
    'items'        => [
        [
            'design'   => 'dgeen_wit',
            'size'     => 'XL',
            'quantity' => '2',
        ],
    ],
];

try {
    $controller->handle_order();
    check(false, 'Expected redirect after member order');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'tshirt_token='), 'Member redirected immediately to confirmation page');
}

$member_order = end($GLOBALS['wpdb']->orders);
check($member_order->status === 'confirmed', 'Member order confirmed immediately');
check((int) $member_order->quantity === 2, 'Member order quantity is 2');
check((float) $member_order->total_amount === 35.00, 'Member order total amount is 35.00');
check((int) $member_order->member_id === 1, 'Order linked to member ID 1');

// -------------------------------------------------------------
// Test 8: Admin distribution update and settings handlers
// -------------------------------------------------------------
$admin = new AVBK_Admin();
$GLOBALS['is_admin'] = true;

$_POST = [
    '_wpnonce'            => 'test-nonce',
    'action'              => 'avbk_update_order_distribution',
    'order_id'            => (string) $member_order->id,
    'distribution_status' => 'collected',
];

try {
    $admin->handle_update_order_distribution();
    check(false, 'Expected redirect after distribution update');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'distribution_updated=1'), 'Admin redirected with distribution_updated parameter');
}
check($member_order->distribution_status === 'collected', 'Order distribution status updated to collected');

echo "\nAll generalized order and T-shirt tests passed cleanly!\n";
