<?php
defined('ABSPATH') || exit;

/**
 * Logboek: who changed what in the bookkeeping, and when.
 *
 * Two layers write to one table:
 *  - every bookkeeping admin-post request (record_request()), with the ids
 *    it was about and, once it redirects, the outcome the redirect carries
 *    (e.g. "order_email_processed=41&mail_sent=0");
 *  - the data changes themselves (log()), called from AVBK_DB where orders,
 *    fee items, allocations and transactions change, with old and new
 *    values. Rows from one request share a request_id.
 *
 * Logging never breaks the action it records: a failed write only goes to
 * the server log. Rows are kept seven years, like the books themselves.
 */
class AVBK_Audit implements AVBK_Component {

    public const PRUNE_HOOK = 'avbk_audit_prune';
    public const KEEP_YEARS = 7;

    /** Request fields that identify what an action was about; nothing else from the request is stored. */
    private const ID_FIELDS = [
        'id', 'order_id', 'member_id', 'fee_item_id', 'fee_item_ids', 'transaction_id', 'activity_id',
        'source_activity_id', 'company_id', 'document_id', 'receipt_id', 'dispute_id', 'entry_id', 'account_id', 'year',
    ];

    /** Actions that only read, export or preview: not worth a row. */
    private const READ_ONLY_ACTIONS = [
        'avbk_export_book_orders', 'avbk_export_tshirt_orders', 'avbk_export_tshirt_matrix', 'avbk_view_receipt',
        'avbk_preview_payment_request', 'avbk_preview_request_payment_email', 'avbk_generate_flyer',
        'avbk_company_workbook', 'avbk_company_document',
    ];

    /** Redirect parameters that are never stored. */
    private const SECRET_PARAMS = ['_wpnonce', 'nonce', 'token', 'tshirt_token', 'book_token', 'key'];

    private static string $request_id = '';
    private static int $request_row = 0;

    public function register(): void {
        add_action('admin_init', [self::class, 'record_request'], 1);
        add_filter('wp_redirect', [self::class, 'record_outcome'], PHP_INT_MAX);
        add_action(self::PRUNE_HOOK, [self::class, 'prune']);
        if (!wp_next_scheduled(self::PRUNE_HOOK)) {
            wp_schedule_event(strtotime('tomorrow 04:00'), 'daily', self::PRUNE_HOOK);
        }
    }

    public static function install(): bool {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$wpdb->prefix}avb_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            request_id CHAR(36) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            actor VARCHAR(20) NOT NULL DEFAULT '',
            action VARCHAR(80) NOT NULL,
            object_type VARCHAR(20) NOT NULL DEFAULT '',
            object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            member_id INT UNSIGNED NULL,
            details LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY object (object_type, object_id),
            KEY member_id (member_id),
            KEY request_id (request_id)
        ) {$wpdb->get_charset_collate()};");
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'avb_audit_log')))
            === $wpdb->prefix . 'avb_audit_log';
    }

    /**
     * One change. $details holds what changed, e.g. ['amount_due' => [45.0, 30.0]]
     * for old → new, or a snapshot of what was removed.
     */
    public static function log(string $action, string $object_type = '', int $object_id = 0, array $details = [], ?int $member_id = null): int {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }
        $inserted = $wpdb->insert("{$wpdb->prefix}avb_audit_log", [
            'created_at'  => current_time('mysql', true),
            'request_id'  => self::request_id(),
            'user_id'     => get_current_user_id(),
            'actor'       => self::actor(),
            'action'      => substr($action, 0, 80),
            'object_type' => substr($object_type, 0, 20),
            'object_id'   => max(0, $object_id),
            'member_id'   => $member_id && $member_id > 0 ? $member_id : null,
            'details'     => $details ? wp_json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
        if (!$inserted) {
            avbk_log_failure('audit log', 'insert_failed', ['action' => $action, 'object' => $object_type . ':' . $object_id]);
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /** admin_init on admin-post.php: one row per bookkeeping action, before it runs. */
    public static function record_request(): void {
        if (($GLOBALS['pagenow'] ?? '') !== 'admin-post.php') {
            return;
        }
        $action = sanitize_key(wp_unslash($_REQUEST['action'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only recorded; the action's own handler checks its nonce.
        if (!self::is_recorded_action($action)) {
            return;
        }
        $ids = self::request_ids(wp_unslash($_REQUEST)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $member_id = isset($ids['member_id']) && is_int($ids['member_id']) ? $ids['member_id'] : null;
        self::$request_row = self::log('request:' . $action, '', 0, $ids ? ['ids' => $ids] : [], $member_id);
    }

    /** wp_redirect: the outcome of the recorded request, from the redirect's own query string. */
    public static function record_outcome($location) {
        global $wpdb;
        if (!self::$request_row || !is_string($location) || !isset($wpdb)) {
            return $location;
        }
        $outcome = self::redirect_outcome($location);
        if ($outcome) {
            $details = json_decode((string) $wpdb->get_var($wpdb->prepare(
                "SELECT details FROM {$wpdb->prefix}avb_audit_log WHERE id = %d", self::$request_row
            )), true) ?: [];
            $details['outcome'] = $outcome;
            $wpdb->update("{$wpdb->prefix}avb_audit_log", ['details' => wp_json_encode($details, JSON_UNESCAPED_UNICODE)], ['id' => self::$request_row]);
        }
        self::$request_row = 0;
        return $location;
    }

    public static function is_recorded_action(string $action): bool {
        return str_starts_with($action, 'avbk_') && !in_array($action, self::READ_ONLY_ACTIONS, true);
    }

    /** The identifying ids of a request, as ints (lists for *_ids); nothing else. */
    public static function request_ids(array $request): array {
        $ids = [];
        foreach (self::ID_FIELDS as $field) {
            if (!isset($request[$field]) || $request[$field] === '') {
                continue;
            }
            $value = $request[$field];
            if (is_array($value)) {
                $list = array_values(array_filter(array_map('intval', $value)));
                if ($list) {
                    $ids[$field] = array_slice($list, 0, 50);
                }
            } elseif (is_numeric($value) && (int) $value > 0) {
                $ids[$field] = (int) $value;
            }
        }
        return $ids;
    }

    /** The redirect's query parameters, minus page navigation and anything secret. */
    public static function redirect_outcome(string $location): array {
        $query = (string) wp_parse_url($location, PHP_URL_QUERY);
        if ($query === '') {
            return [];
        }
        parse_str($query, $params);
        unset($params['page'], $params['tab']);
        foreach (array_keys($params) as $key) {
            if (in_array(strtolower((string) $key), self::SECRET_PARAMS, true) || str_contains(strtolower((string) $key), 'token')) {
                unset($params[$key]);
            }
        }
        return array_map(static fn($value) => is_array($value) ? array_slice($value, 0, 50) : mb_substr((string) $value, 0, 300), $params);
    }

    /** Daily: remove rows older than the retention period. */
    public static function prune(): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}avb_audit_log WHERE created_at < %s",
            gmdate('Y-m-d H:i:s', strtotime('-' . self::KEEP_YEARS . ' years'))
        ));
    }

    /**
     * @return array{rows: object[], total: int}
     */
    public static function search(array $filters, int $limit = 100, int $offset = 0): array {
        global $wpdb;
        $where = ['1=1'];
        $args = [];
        if (!empty($filters['member_id'])) {
            $where[] = 'member_id = %d';
            $args[] = (int) $filters['member_id'];
        }
        if (!empty($filters['object_type'])) {
            $where[] = 'object_type = %s';
            $args[] = (string) $filters['object_type'];
            if (!empty($filters['object_id'])) {
                $where[] = 'object_id = %d';
                $args[] = (int) $filters['object_id'];
            }
        }
        if (!empty($filters['request_id'])) {
            $where[] = 'request_id = %s';
            $args[] = (string) $filters['request_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'created_at >= %s';
            $args[] = get_gmt_from_date($filters['from'] . ' 00:00:00');
        }
        if (!empty($filters['to'])) {
            $where[] = 'created_at <= %s';
            $args[] = get_gmt_from_date($filters['to'] . ' 23:59:59');
        }
        if (empty($filters['with_requests'])) {
            $where[] = "action NOT LIKE 'request:%%'";
        }
        $table = "{$wpdb->prefix}avb_audit_log";
        $sql_where = implode(' AND ', $where);
        $total = (int) $wpdb->get_var($args ? $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$sql_where}", ...$args) : "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}");
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d",
            ...array_merge($args, [max(1, $limit), max(0, $offset)])
        )) ?: [];
        return ['rows' => $rows, 'total' => $total];
    }

    /** Dutch label for an action key; an unknown request shows its action name. */
    public static function label(string $action): string {
        $labels = [
            'order.created'          => 'Bestelling geplaatst',
            'order.confirmed'        => 'Bestelling bevestigd',
            'order.deleted'          => 'Bestelling verwijderd',
            'order.email_changed'    => 'E-mailadres van bestelling gewijzigd',
            'order.distribution'     => 'Uitgiftestatus gewijzigd',
            'order.notes'            => 'Notitie bij bestelling gewijzigd',
            'fee_item.created'       => 'Bijdrage aangemaakt',
            'fee_item.waived'        => 'Bijdrage kwijtgescholden',
            'fee_item.amount'        => 'Bedrag van bijdrage gewijzigd',
            'allocation.added'       => 'Betaling gekoppeld',
            'allocation.cleared'     => 'Koppelingen van transactie gewist',
            'transaction.reverted'   => 'Transactie terug naar controle',
            'transactions.reverted_year' => 'Betalingen van een jaar terug naar controle',
            'transaction.ignored'    => 'Transactie genegeerd',
            'transaction.duplicate'  => 'Transactie als dubbel gemarkeerd',
            'credit.donated'         => 'Tegoed geschonken',
        ];
        if (isset($labels[$action])) {
            return $labels[$action];
        }
        return str_starts_with($action, 'request:') ? 'Actie ' . substr($action, 8) : $action;
    }

    private static function request_id(): string {
        if (self::$request_id === '') {
            self::$request_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
        }
        return self::$request_id;
    }

    private static function actor(): string {
        if (defined('WP_CLI') && WP_CLI) {
            return 'wp-cli';
        }
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return 'systeem';
        }
        if (!get_current_user_id()) {
            return 'website';
        }
        $manager = current_user_can('manage_options')
            || (class_exists('AVPVH_Roles') && AVPVH_Roles::current_user_has_role('penningmeester'));
        return $manager ? 'beheer' : 'lid';
    }
}
