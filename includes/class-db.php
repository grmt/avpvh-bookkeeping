<?php
defined('ABSPATH') || exit;

class AVBK_DB {

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$wpdb->prefix}avb_contribution_rates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            year SMALLINT UNSIGNED NOT NULL,
            min_age TINYINT UNSIGNED NULL,
            max_age TINYINT UNSIGNED NULL,
            label VARCHAR(50) NOT NULL DEFAULT '',
            amount DECIMAL(8,2) NOT NULL,
            PRIMARY KEY (id),
            KEY year (year)
        ) $charset;");

        // Camp fees are age-bracketed per camp, same shape as
        // avb_contribution_rates (e.g. a real informatiefje: 0-3 free,
        // 4-12 €10/night, 13+ €20/night) — several rows per camp_id, not
        // one flat rate. camp_id is read via AVPVH_DB, not a real FK — see
        // class-db.php's cross-plugin note in the plan.
        dbDelta("CREATE TABLE {$wpdb->prefix}avb_camp_rates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            camp_id INT UNSIGNED NOT NULL,
            min_age TINYINT UNSIGNED NULL,
            max_age TINYINT UNSIGNED NULL,
            label VARCHAR(50) NOT NULL DEFAULT '',
            day_rate DECIMAL(8,2) NOT NULL,
            PRIMARY KEY (id),
            KEY camp_id (camp_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avb_fee_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            type ENUM('contribution','camp') NOT NULL,
            year SMALLINT UNSIGNED NULL,
            camp_id INT UNSIGNED NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            amount_due DECIMAL(8,2) NOT NULL,
            status ENUM('open','waived') NOT NULL DEFAULT 'open',
            is_estimated TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            estimate_reason VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY member_id (member_id),
            KEY member_type_year (member_id, type, year),
            KEY member_type_camp (member_id, type, camp_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avb_import_batches (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            filename VARCHAR(255) NOT NULL DEFAULT '',
            uploaded_by BIGINT UNSIGNED NULL,
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            row_count INT UNSIGNED NOT NULL DEFAULT 0,
            matched_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avb_transactions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            import_batch_id INT UNSIGNED NULL,
            transaction_date DATE NOT NULL,
            amount DECIMAL(8,2) NOT NULL,
            direction ENUM('in','out') NOT NULL,
            counterparty_name VARCHAR(255) NOT NULL DEFAULT '',
            counterparty_iban VARCHAR(34) NOT NULL DEFAULT '',
            description TEXT NULL,
            dedupe_hash CHAR(40) NOT NULL,
            status ENUM('unmatched','suggested','matched','ignored') NOT NULL DEFAULT 'unmatched',
            suggested_member_ids VARCHAR(100) NOT NULL DEFAULT '',
            suggested_type VARCHAR(20) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY dedupe_hash (dedupe_hash),
            KEY status_direction (status, direction),
            KEY counterparty_iban (counterparty_iban)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avb_transaction_allocations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            transaction_id INT UNSIGNED NOT NULL,
            fee_item_id INT UNSIGNED NOT NULL,
            member_id INT UNSIGNED NOT NULL,
            amount DECIMAL(8,2) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY transaction_id (transaction_id),
            KEY fee_item_id (fee_item_id),
            KEY member_id (member_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avb_known_ibans (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            iban VARCHAR(34) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY iban (iban),
            KEY member_id (member_id)
        ) $charset;");

        update_option('avbk_db_version', '1.0');
    }

    public static function maybe_upgrade(): void {
        global $wpdb;
        $version = get_option('avbk_db_version', '0');
        if (version_compare($version, '1.0', '<')) {
            self::install();
        }
        if (version_compare($version, '1.1', '<')) {
            // avb_camp_rates went from one flat rate per camp to an
            // age-bracketed table (several rows per camp) — see install().
            $has_min_age = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_camp_rates LIKE 'min_age'");
            if (!$has_min_age) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_camp_rates DROP INDEX camp_id");
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_camp_rates
                    ADD COLUMN min_age TINYINT UNSIGNED NULL AFTER camp_id,
                    ADD COLUMN max_age TINYINT UNSIGNED NULL AFTER min_age,
                    ADD COLUMN label VARCHAR(50) NOT NULL DEFAULT '' AFTER max_age,
                    ADD KEY camp_id (camp_id)");
            }
            update_option('avbk_db_version', '1.1');
        }
        if (version_compare($version, '1.2', '<')) {
            // Fee items generated from an assumed-adult rate (no birth date
            // on file) get flagged so the treasurer can spot/verify them —
            // see AVBK_Fee_Generation.
            $has_is_estimated = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_fee_items LIKE 'is_estimated'");
            if (!$has_is_estimated) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_fee_items
                    ADD COLUMN is_estimated TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER status,
                    ADD COLUMN estimate_reason VARCHAR(255) NOT NULL DEFAULT '' AFTER is_estimated");
            }
            update_option('avbk_db_version', '1.2');
        }
    }

    // -------------------------------------------------------------------
    // Contribution rates
    // -------------------------------------------------------------------

    public static function get_contribution_rates(int $year): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_contribution_rates WHERE year = %d ORDER BY min_age ASC",
            $year
        )) ?: [];
    }

    /** The rate row covering $age in $year, or null if none configured. */
    public static function get_rate_for_age(int $year, int $age): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_contribution_rates
             WHERE year = %d
               AND (min_age IS NULL OR min_age <= %d)
               AND (max_age IS NULL OR max_age >= %d)
             ORDER BY (min_age IS NOT NULL) DESC, (max_age IS NOT NULL) DESC
             LIMIT 1",
            $year, $age, $age
        )) ?: null;
    }

    /**
     * The "adult" bracket for $year — used when a member's birth date is
     * unknown so a fee item still generates (assumed adult, flagged as
     * estimated) rather than being silently skipped. The open-ended
     * (max_age IS NULL) bracket wins if one exists — that's the "everyone
     * else" catch-all in every rate table seen so far — otherwise whichever
     * bracket has the highest min_age.
     */
    public static function get_adult_contribution_rate(int $year): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_contribution_rates
             WHERE year = %d
             ORDER BY (max_age IS NULL) DESC, min_age DESC
             LIMIT 1",
            $year
        )) ?: null;
    }

    public static function save_contribution_rate(int $id, int $year, ?int $min_age, ?int $max_age, string $label, float $amount): int {
        global $wpdb;
        $data = [
            'year'    => $year,
            'min_age' => $min_age,
            'max_age' => $max_age,
            'label'   => $label,
            'amount'  => $amount,
        ];
        if ($id > 0) {
            $wpdb->update("{$wpdb->prefix}avb_contribution_rates", $data, ['id' => $id]);
            return $id;
        }
        $wpdb->insert("{$wpdb->prefix}avb_contribution_rates", $data);
        return (int) $wpdb->insert_id;
    }

    public static function delete_contribution_rate(int $id): void {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}avb_contribution_rates", ['id' => $id]);
    }

    // -------------------------------------------------------------------
    // Camp rates
    // -------------------------------------------------------------------

    /** All age-bracket rate rows for one camp, e.g. kids 0-3 free / 4-12 €10/night / 13+ €20/night. */
    public static function get_camp_rates_for_camp(int $camp_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_camp_rates WHERE camp_id = %d ORDER BY min_age ASC",
            $camp_id
        )) ?: [];
    }

    /** The rate row covering $age for this camp, or null if no bracket matches. */
    public static function get_camp_rate_for_age(int $camp_id, int $age): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_camp_rates
             WHERE camp_id = %d
               AND (min_age IS NULL OR min_age <= %d)
               AND (max_age IS NULL OR max_age >= %d)
             ORDER BY (min_age IS NOT NULL) DESC, (max_age IS NOT NULL) DESC
             LIMIT 1",
            $camp_id, $age, $age
        )) ?: null;
    }

    /** The "adult" bracket for this camp — same fallback rule as get_adult_contribution_rate(). */
    public static function get_adult_camp_rate(int $camp_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_camp_rates
             WHERE camp_id = %d
             ORDER BY (max_age IS NULL) DESC, min_age DESC
             LIMIT 1",
            $camp_id
        )) ?: null;
    }

    public static function get_camp_rates(): array {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}avb_camp_rates") ?: [];
    }

    /** Camps (from avpvh-members) with no rate brackets configured yet — camp fee items can't generate for these. */
    public static function get_camps_without_rate(): array {
        $rated_camp_ids = array_unique(array_map('intval', wp_list_pluck(self::get_camp_rates(), 'camp_id')));
        return array_values(array_filter(
            AVPVH_DB::get_camps(),
            fn($camp) => !in_array((int) $camp->id, $rated_camp_ids, true)
        ));
    }

    public static function save_camp_rate(int $id, int $camp_id, ?int $min_age, ?int $max_age, string $label, float $day_rate): int {
        global $wpdb;
        $data = [
            'camp_id'  => $camp_id,
            'min_age'  => $min_age,
            'max_age'  => $max_age,
            'label'    => $label,
            'day_rate' => $day_rate,
        ];
        if ($id > 0) {
            $wpdb->update("{$wpdb->prefix}avb_camp_rates", $data, ['id' => $id]);
            return $id;
        }
        $wpdb->insert("{$wpdb->prefix}avb_camp_rates", $data);
        return (int) $wpdb->insert_id;
    }

    public static function delete_camp_rate(int $id): void {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}avb_camp_rates", ['id' => $id]);
    }

    // -------------------------------------------------------------------
    // Fee items
    // -------------------------------------------------------------------

    public static function get_contribution_fee_item(int $member_id, int $year): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_fee_items WHERE member_id = %d AND type = 'contribution' AND year = %d",
            $member_id, $year
        )) ?: null;
    }

    public static function get_camp_fee_item(int $member_id, int $camp_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_fee_items WHERE member_id = %d AND type = 'camp' AND camp_id = %d",
            $member_id, $camp_id
        )) ?: null;
    }

    /**
     * The member's own open fee item for $type (their most recent camp's
     * camp fee, or the current year's contribution) — used by the review
     * queue to default a multi-member split to what each person actually
     * owes (nights x day-rate, or their age-bracket rate) instead of a
     * blind even split of the payment amount.
     */
    public static function find_relevant_open_fee_item(int $member_id, string $type): ?object {
        $item = null;
        if ($type === 'camp') {
            $camp = AVPVH_DB::get_current_camp();
            if ($camp) {
                $item = self::get_camp_fee_item($member_id, (int) $camp->id);
            }
        } elseif ($type === 'contribution') {
            $item = self::get_contribution_fee_item($member_id, (int) current_time('Y'));
        }
        return ($item && $item->status === 'open') ? $item : null;
    }

    /** Insert or update the member's contribution fee item for $year. Returns the fee_item id. */
    public static function upsert_contribution_fee_item(int $member_id, int $year, float $amount, string $description, bool $is_estimated = false, string $estimate_reason = ''): int {
        global $wpdb;
        $existing = self::get_contribution_fee_item($member_id, $year);
        if ($existing) {
            if ($existing->status === 'open') {
                $wpdb->update(
                    "{$wpdb->prefix}avb_fee_items",
                    ['amount_due' => $amount, 'description' => $description, 'is_estimated' => (int) $is_estimated, 'estimate_reason' => $estimate_reason],
                    ['id' => $existing->id]
                );
            }
            return (int) $existing->id;
        }
        $wpdb->insert("{$wpdb->prefix}avb_fee_items", [
            'member_id'       => $member_id,
            'type'            => 'contribution',
            'year'            => $year,
            'description'     => $description,
            'amount_due'      => $amount,
            'is_estimated'    => (int) $is_estimated,
            'estimate_reason' => $estimate_reason,
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Insert or update the member's camp fee item, kept current as attendance/nights change. Returns the fee_item id. */
    public static function upsert_camp_fee_item(int $member_id, int $camp_id, float $amount, string $description, bool $is_estimated = false, string $estimate_reason = ''): int {
        global $wpdb;
        $existing = self::get_camp_fee_item($member_id, $camp_id);
        if ($existing) {
            if ($existing->status === 'open') {
                $wpdb->update(
                    "{$wpdb->prefix}avb_fee_items",
                    ['amount_due' => $amount, 'description' => $description, 'is_estimated' => (int) $is_estimated, 'estimate_reason' => $estimate_reason],
                    ['id' => $existing->id]
                );
            }
            return (int) $existing->id;
        }
        $wpdb->insert("{$wpdb->prefix}avb_fee_items", [
            'member_id'       => $member_id,
            'type'            => 'camp',
            'camp_id'         => $camp_id,
            'description'     => $description,
            'amount_due'      => $amount,
            'is_estimated'    => (int) $is_estimated,
            'estimate_reason' => $estimate_reason,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function get_fee_item(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_fee_items WHERE id = %d", $id
        )) ?: null;
    }

    public static function get_fee_items_for_member(int $member_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_fee_items WHERE member_id = %d ORDER BY year DESC, created_at DESC",
            $member_id
        )) ?: [];
    }

    /** Open fee items for a member, oldest first — the FIFO allocation order. */
    public static function get_open_fee_items_for_member(int $member_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_fee_items
             WHERE member_id = %d AND status = 'open'
             ORDER BY COALESCE(year, 0) ASC, created_at ASC",
            $member_id
        )) ?: [];
    }

    public static function waive_fee_item(int $id): void {
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}avb_fee_items", ['status' => 'waived'], ['id' => $id]);
    }

    /** Amount already allocated (paid) towards a single fee item. */
    public static function get_fee_item_paid(int $fee_item_id): float {
        global $wpdb;
        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}avb_transaction_allocations WHERE fee_item_id = %d",
            $fee_item_id
        ));
    }

    /**
     * Full itemized balance for a member: every fee item with due/paid/
     * remaining, plus totals. Computed on read from fee_items + allocations
     * rather than cached, so it can never drift out of sync.
     */
    public static function get_member_balance(int $member_id): array {
        global $wpdb;
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT f.*, COALESCE(SUM(a.amount), 0) AS paid
             FROM {$wpdb->prefix}avb_fee_items f
             LEFT JOIN {$wpdb->prefix}avb_transaction_allocations a ON a.fee_item_id = f.id
             WHERE f.member_id = %d
             GROUP BY f.id
             ORDER BY f.year DESC, f.created_at DESC",
            $member_id
        )) ?: [];

        $total_due = 0.0;
        $total_paid = 0.0;
        foreach ($items as $item) {
            $item->paid = (float) $item->paid;
            $item->remaining = $item->status === 'waived' ? 0.0 : round((float) $item->amount_due - $item->paid, 2);
            if ($item->status !== 'waived') {
                $total_due += (float) $item->amount_due;
                $total_paid += $item->paid;
            }
        }

        return [
            'items'      => $items,
            'total_due'  => round($total_due, 2),
            'total_paid' => round($total_paid, 2),
            'balance'    => round($total_due - $total_paid, 2),
        ];
    }

    // -------------------------------------------------------------------
    // Import batches
    // -------------------------------------------------------------------

    public static function create_import_batch(string $filename, int $uploaded_by): int {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}avb_import_batches", [
            'filename'    => $filename,
            'uploaded_by' => $uploaded_by,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function update_import_batch_counts(int $batch_id, int $row_count, int $matched_count): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}avb_import_batches",
            ['row_count' => $row_count, 'matched_count' => $matched_count],
            ['id' => $batch_id]
        );
    }

    public static function get_import_batches(int $limit = 50): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_import_batches ORDER BY uploaded_at DESC LIMIT %d",
            $limit
        )) ?: [];
    }

    // -------------------------------------------------------------------
    // Transactions
    // -------------------------------------------------------------------

    public static function dedupe_hash(string $date, float $amount, string $iban, string $description): string {
        return sha1($date . '|' . number_format($amount, 2, '.', '') . '|' . strtoupper($iban) . '|' . trim($description));
    }

    public static function transaction_exists(string $hash): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}avb_transactions WHERE dedupe_hash = %s", $hash
        ));
    }

    public static function insert_transaction(array $row): int {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}avb_transactions", [
            'import_batch_id'      => $row['import_batch_id'] ?? null,
            'transaction_date'     => $row['transaction_date'],
            'amount'               => $row['amount'],
            'direction'            => $row['direction'],
            'counterparty_name'    => $row['counterparty_name'] ?? '',
            'counterparty_iban'    => $row['counterparty_iban'] ?? '',
            'description'          => $row['description'] ?? '',
            'dedupe_hash'          => $row['dedupe_hash'],
            'status'               => $row['status'] ?? 'unmatched',
            'suggested_member_ids' => $row['suggested_member_ids'] ?? '',
            'suggested_type'       => $row['suggested_type'] ?? '',
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function get_transaction(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_transactions WHERE id = %d", $id
        )) ?: null;
    }

    public static function update_transaction_status(int $id, string $status): void {
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}avb_transactions", ['status' => $status], ['id' => $id]);
    }

    public static function update_transaction_suggestion(int $id, string $status, string $suggested_member_ids, string $suggested_type): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}avb_transactions",
            ['status' => $status, 'suggested_member_ids' => $suggested_member_ids, 'suggested_type' => $suggested_type],
            ['id' => $id]
        );
    }

    /** Rows still needing the treasurer's attention — everything else applied itself. */
    public static function get_review_queue(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}avb_transactions
             WHERE direction = 'in' AND status IN ('suggested', 'unmatched')
             ORDER BY transaction_date DESC"
        ) ?: [];
    }

    public static function get_transactions(array $args = []): array {
        global $wpdb;
        $where = '1=1';
        $params = [];
        if (!empty($args['batch_id'])) {
            $where .= ' AND import_batch_id = %d';
            $params[] = (int) $args['batch_id'];
        }
        $sql = "SELECT * FROM {$wpdb->prefix}avb_transactions WHERE $where ORDER BY transaction_date DESC, id DESC";
        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        return $wpdb->get_results($sql) ?: [];
    }

    // -------------------------------------------------------------------
    // Allocations
    // -------------------------------------------------------------------

    public static function allocate(int $transaction_id, int $fee_item_id, int $member_id, float $amount): int {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}avb_transaction_allocations", [
            'transaction_id' => $transaction_id,
            'fee_item_id'    => $fee_item_id,
            'member_id'      => $member_id,
            'amount'         => $amount,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function get_allocations_for_transaction(int $transaction_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_transaction_allocations WHERE transaction_id = %d",
            $transaction_id
        )) ?: [];
    }

    // -------------------------------------------------------------------
    // Known IBANs — learned the first time a transaction is confirmed for
    // a member, so later imports from the same IBAN auto-match even with
    // a generic description.
    // -------------------------------------------------------------------

    public static function remember_iban(int $member_id, string $iban): void {
        global $wpdb;
        $iban = strtoupper(str_replace(' ', '', $iban));
        if ($iban === '') {
            return;
        }
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_known_ibans WHERE iban = %s", $iban
        ));
        if ($existing) {
            if ((int) $existing->member_id !== $member_id) {
                // IBAN moved to a different member (e.g. a shared/joint
                // account previously attributed to someone else) — the
                // most recent confirmation wins.
                $wpdb->update("{$wpdb->prefix}avb_known_ibans", ['member_id' => $member_id], ['id' => $existing->id]);
            }
            return;
        }
        $wpdb->insert("{$wpdb->prefix}avb_known_ibans", ['member_id' => $member_id, 'iban' => $iban]);
    }

    public static function find_member_id_by_iban(string $iban): ?int {
        global $wpdb;
        $iban = strtoupper(str_replace(' ', '', $iban));
        if ($iban === '') {
            return null;
        }
        $member_id = $wpdb->get_var($wpdb->prepare(
            "SELECT member_id FROM {$wpdb->prefix}avb_known_ibans WHERE iban = %s", $iban
        ));
        return $member_id ? (int) $member_id : null;
    }
}
