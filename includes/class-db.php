<?php
defined('ABSPATH') || exit;

class AVBK_DB {

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // for_students is independent of the age brackets — "scholier/
        // student" is a status (AVPVH_DB member.is_student), not derivable
        // from age alone (a 22-year-old can be either). A for_students=1
        // row wins over age when the member is flagged, regardless of
        // min_age/max_age on that row.
        dbDelta("CREATE TABLE {$wpdb->prefix}avb_contribution_rates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            year SMALLINT UNSIGNED NOT NULL,
            min_age TINYINT UNSIGNED NULL,
            max_age TINYINT UNSIGNED NULL,
            for_students TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
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
            type ENUM('contribution','camp','event') NOT NULL,
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

        // An IBAN is not 1:1 with a member — bank accounts are held in a
        // name, and one member can have several accounts, but a joint
        // account (a real example: "H. Post e/o M.C. Hendriks") also
        // genuinely belongs to more than one member at once. So this is a
        // plain many-to-many table: unique per (iban, member_id) pair, not
        // per iban.
        dbDelta("CREATE TABLE {$wpdb->prefix}avb_known_ibans (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            iban VARCHAR(34) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY iban_member (iban, member_id),
            KEY iban (iban),
            KEY member_id (member_id)
        ) $charset;");

        // A member's "I don't understand/agree with this" message about
        // their own balance — sent by e-mail to the penningmeester
        // immediately, but also kept here as a standing todo list (see
        // admin/disputes.php) so a question doesn't just disappear into an
        // inbox and get forgotten.
        dbDelta("CREATE TABLE {$wpdb->prefix}avb_disputes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            status ENUM('open','resolved') NOT NULL DEFAULT 'open',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL,
            resolved_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY member_id (member_id),
            KEY status (status)
        ) $charset;");

        // One row per public congress/reunion sign-up (see AVBK_Congress).
        // member_id starts NULL until find_or_create_member_for_registration()
        // resolves it (immediately, in practice — kept nullable defensively
        // for a future case where that resolution might legitimately fail).
        // confirm_token is the bearer credential for the public "view my
        // registration + QR" link mailed to the registrant — unguessable,
        // not tied to a login, by design (most registrants are not existing
        // WP users).
        dbDelta("CREATE TABLE {$wpdb->prefix}avb_congress_registrations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NULL,
            fee_item_id INT UNSIGNED NULL,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            suffix VARCHAR(50) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            email VARCHAR(255) NOT NULL DEFAULT '',
            phone VARCHAR(50) NOT NULL DEFAULT '',
            match_type VARCHAR(20) NOT NULL DEFAULT '',
            needs_review TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            review_note VARCHAR(255) NOT NULL DEFAULT '',
            confirm_token CHAR(43) NOT NULL,
            status ENUM('pending_confirmation','confirmed') NOT NULL DEFAULT 'pending_confirmation',
            email_sent TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            email_error VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_at TIMESTAMP NULL,
            PRIMARY KEY (id),
            UNIQUE KEY confirm_token (confirm_token),
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
        if (version_compare($version, '1.3', '<')) {
            // A student rate is a status flag, not another age bracket —
            // see the note on the table definition in install().
            $has_for_students = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_contribution_rates LIKE 'for_students'");
            if (!$has_for_students) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_contribution_rates
                    ADD COLUMN for_students TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER max_age");
            }
            update_option('avbk_db_version', '1.3');
        }
        if (version_compare($version, '1.4', '<')) {
            // avb_known_ibans was 1:1 (unique per iban) — a joint account
            // genuinely belongs to more than one member, so it needs to be
            // many-to-many (unique per iban+member pair instead). Existing
            // rows are already unique per iban, so they satisfy the new
            // constraint unchanged.
            $indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}avb_known_ibans WHERE Key_name = 'iban'");
            $has_old_unique_iban = false;
            foreach ($indexes as $idx) {
                if ((int) $idx->Non_unique === 0) {
                    $has_old_unique_iban = true;
                }
            }
            if ($has_old_unique_iban) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_known_ibans DROP INDEX iban");
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_known_ibans
                    ADD UNIQUE KEY iban_member (iban, member_id),
                    ADD KEY iban (iban)");
            }
            update_option('avbk_db_version', '1.4');
        }
        if (version_compare($version, '1.5', '<')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$wpdb->prefix}avb_disputes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                member_id INT UNSIGNED NOT NULL,
                message TEXT NOT NULL,
                status ENUM('open','resolved') NOT NULL DEFAULT 'open',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP NULL,
                resolved_by BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                KEY member_id (member_id),
                KEY status (status)
            ) {$wpdb->get_charset_collate()};");
            update_option('avbk_db_version', '1.5');
        }
        if (version_compare($version, '1.6', '<')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_fee_items MODIFY COLUMN type ENUM('contribution','camp','event') NOT NULL");
            dbDelta("CREATE TABLE {$wpdb->prefix}avb_congress_registrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                member_id INT UNSIGNED NULL,
                fee_item_id INT UNSIGNED NULL,
                first_name VARCHAR(100) NOT NULL DEFAULT '',
                suffix VARCHAR(50) NOT NULL DEFAULT '',
                last_name VARCHAR(100) NOT NULL DEFAULT '',
                email VARCHAR(255) NOT NULL DEFAULT '',
                phone VARCHAR(50) NOT NULL DEFAULT '',
                match_type VARCHAR(20) NOT NULL DEFAULT '',
                needs_review TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                review_note VARCHAR(255) NOT NULL DEFAULT '',
                confirm_token CHAR(43) NOT NULL,
                status ENUM('pending_confirmation','confirmed') NOT NULL DEFAULT 'pending_confirmation',
                email_sent TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                email_error VARCHAR(255) NOT NULL DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                confirmed_at TIMESTAMP NULL,
                PRIMARY KEY (id),
                UNIQUE KEY confirm_token (confirm_token),
                KEY member_id (member_id)
            ) {$wpdb->get_charset_collate()};");
            update_option('avbk_db_version', '1.6');
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

    /** The student rate for $year, if one is configured — checked before age, since student is a status flag, not an age bracket. */
    public static function get_student_contribution_rate(int $year): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_contribution_rates WHERE year = %d AND for_students = 1 LIMIT 1",
            $year
        )) ?: null;
    }

    /**
     * The rate row covering $age in $year, or null if none configured.
     * Excludes for_students rows — those only ever apply via the
     * is_student flag (get_student_contribution_rate), never by
     * coincidentally matching someone's age.
     */
    public static function get_rate_for_age(int $year, int $age): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_contribution_rates
             WHERE year = %d AND for_students = 0
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
     * estimated) rather than being silently skipped. Only ever a genuinely
     * open-ended bracket (max_age IS NULL) — the real "everyone else"
     * catch-all. Deliberately does NOT fall back to "whichever bracket has
     * the highest min_age" when no open-ended one exists: with only a
     * capped child bracket configured so far (e.g. "Kinderen 4-15"), that
     * would pick the child rate and mislabel it as an assumed-adult
     * amount — worse than just not generating a fee item yet. Excludes
     * for_students rows, same reason as get_rate_for_age().
     */
    public static function get_adult_contribution_rate(int $year): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_contribution_rates
             WHERE year = %d AND for_students = 0 AND max_age IS NULL
             ORDER BY min_age DESC
             LIMIT 1",
            $year
        )) ?: null;
    }

    public static function save_contribution_rate(int $id, int $year, ?int $min_age, ?int $max_age, string $label, float $amount, bool $for_students = false): int {
        global $wpdb;
        $data = [
            'year'         => $year,
            'min_age'      => $min_age,
            'max_age'      => $max_age,
            'for_students' => (int) $for_students,
            'label'        => $label,
            'amount'       => $amount,
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

    /** The "adult" bracket for this camp — same fallback rule (and same reasoning) as get_adult_contribution_rate(). */
    public static function get_adult_camp_rate(int $camp_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_camp_rates
             WHERE camp_id = %d AND max_age IS NULL
             ORDER BY min_age DESC
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

    /**
     * Everything the review queue's per-candidate line shows for one member
     * across the fee types a transaction was tagged with: amount still
     * open, the age/student or nights/dates fragment, an estimated-amount
     * warning, and the two "edit" links. Shared between the initial page
     * render and the AJAX endpoint that refreshes this when the treasurer
     * swaps the selected member on an already-rendered row — one source of
     * truth for both so they can never drift out of sync.
     */
    public static function get_member_fee_detail(int $member_id, array $types): array {
        $member = AVPVH_DB::get_member($member_id);
        $detail = [
            'share' => 0.0,
            'fragments_html' => '',
            'estimated_text' => '',
            'nights_edit_url' => '',
            'member_edit_url' => $member ? add_query_arg(['member_id' => $member_id], home_url('/member-profile/')) : '',
            'found' => false,
        ];
        if (!$member) {
            return $detail;
        }

        $fragments = [];
        foreach ($types as $type) {
            $item = self::find_relevant_open_fee_item($member_id, $type);
            if (!$item) {
                continue;
            }
            $detail['found'] = true;
            $detail['share'] += round((float) $item->amount_due - self::get_fee_item_paid((int) $item->id), 2);
            if (!empty($item->is_estimated)) {
                $detail['estimated_text'] = "\u{26A0} " . ($item->estimate_reason ?: 'Geschat bedrag.');
            }

            if ($type === 'contribution') {
                // Student is a status, not an age bracket — showing an age
                // next to a student-rate amount would misleadingly imply
                // age is what set the price.
                if (!empty($member->is_student)) {
                    $fragments[] = 'scholier/student';
                } elseif (!empty($member->birth_date)) {
                    $year = (int) ($item->year ?: current_time('Y'));
                    $fragments[] = 'leeftijd: ' . AVBK_Fee_Generation::age_on((string) $member->birth_date, "$year-01-01") . ' jaar';
                } elseif (!empty($member->birth_year)) {
                    $year = (int) ($item->year ?: current_time('Y'));
                    $fragments[] = 'leeftijd: ' . AVBK_Fee_Generation::age_from_year((int) $member->birth_year, "$year-01-01") . ' jaar (bij benadering)';
                }
            }
            if ($type === 'camp' && $item->camp_id) {
                $participation = AVPVH_DB::get_participation($member_id, (int) $item->camp_id);
                if ($participation && $participation->nights) {
                    $nights_parts = [(int) $participation->nights . ' nacht' . ((int) $participation->nights === 1 ? '' : 'en')];
                    // Actual dates present (not just the night count) — same
                    // "non-empty status = present" rule the Kampdeelname
                    // list itself uses for "Dagen aanwezig".
                    $days = AVPVH_DB::get_participation_days((int) $participation->id);
                    $present_dates = array_keys(array_filter($days, fn($status) => $status !== ''));
                    sort($present_dates);
                    if ($present_dates) {
                        $nights_parts[] = esc_html(wp_date('D d M', strtotime(reset($present_dates))))
                            . '&ndash;' . esc_html(wp_date('D d M', strtotime(end($present_dates))));
                    }
                    $fragments[] = 'inschrijving: ' . implode(', ', $nights_parts);
                    $detail['nights_edit_url'] = add_query_arg([
                        'page' => 'avpvh-kampdeelname-detail',
                        'camp_id' => (int) $item->camp_id,
                        'id' => (int) $participation->id,
                    ], admin_url('admin.php'));
                }
            }
        }
        $detail['share'] = round($detail['share'], 2);
        // Each fragment is already safe (plain text, or the date range
        // which carries a pre-escaped "&ndash;") — esc_html-ing the joined
        // result here would double-encode that entity.
        $detail['fragments_html'] = implode(' &middot; ', $fragments);
        return $detail;
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

    /**
     * A fee item's description is generated as "{base} ({rate label})" —
     * see AVBK_Fee_Generation — with no separate column for the label, so
     * this splits it back apart for display (a "Tarief" column, distinct
     * from the plain description). Falls back to treating the whole string
     * as the base with no label when there's no trailing "(...)" — always
     * true for older items generated before rate labels existed.
     */
    public static function split_fee_description(string $description): array {
        if (preg_match('/^(.*)\s\(([^)]+)\)$/', $description, $m)) {
            return ['base' => $m[1], 'label' => $m[2]];
        }
        return ['base' => $description, 'label' => ''];
    }

    /**
     * Human "how many" for one fee item — nights for a camp item (a camp
     * fee is nights × day-rate, but nights itself was never stored on the
     * fee item, only used to compute amount_due at generation time — so
     * this looks it up live from the participation record, same source of
     * truth the review queue already uses). Nothing for a flat per-year
     * contribution, which has no natural quantity.
     */
    public static function fee_item_quantity_label(object $item): string {
        if ($item->type === 'camp' && $item->camp_id) {
            $participation = AVPVH_DB::get_participation((int) $item->member_id, (int) $item->camp_id);
            if ($participation && $participation->nights) {
                $n = (int) $participation->nights;
                return $n . ' nacht' . ($n === 1 ? '' : 'en');
            }
        }
        return '';
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

    /** Each batch plus the date range of the transactions it actually contained — a treasurer re-uploading an export needs to see "which periods have I already covered", not just when/how big each upload was. */
    /** The most recent incoming transaction date actually imported — "payments are processed up to this date" for the member-facing popup/balance view. Falls back to a fixed placeholder date when nothing has been imported yet at all, rather than showing a blank/confusing "never". */
    public static function get_last_processed_date(): string {
        global $wpdb;
        $date = $wpdb->get_var(
            "SELECT MAX(transaction_date) FROM {$wpdb->prefix}avb_transactions WHERE direction = 'in'"
        );
        return $date ?: '2025-12-31';
    }

    public static function get_import_batches(int $limit = 50): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT b.*,
                    MIN(t.transaction_date) AS first_transaction_date,
                    MAX(t.transaction_date) AS last_transaction_date
             FROM {$wpdb->prefix}avb_import_batches b
             LEFT JOIN {$wpdb->prefix}avb_transactions t ON t.import_batch_id = b.id
             GROUP BY b.id
             ORDER BY b.uploaded_at DESC
             LIMIT %d",
            $limit
        )) ?: [];
    }

    // -------------------------------------------------------------------
    // Disputes — a member's "I don't understand/agree with this" message
    // about their own balance, kept as a standing todo list for the
    // penningmeester (see admin/disputes.php) alongside the e-mail sent
    // when it's submitted (AVBK_Balance_Shortcode::handle_dispute()).
    // -------------------------------------------------------------------

    public static function create_dispute(int $member_id, string $message): int {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}avb_disputes", [
            'member_id' => $member_id,
            'message'   => $message,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function get_disputes(string $status = 'open'): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_disputes WHERE status = %s ORDER BY created_at ASC",
            $status
        )) ?: [];
    }

    public static function count_open_disputes(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}avb_disputes WHERE status = 'open'"
        );
    }

    public static function resolve_dispute(int $id, int $resolved_by): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}avb_disputes",
            ['status' => 'resolved', 'resolved_at' => current_time('mysql'), 'resolved_by' => $resolved_by],
            ['id' => $id]
        );
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

    /** Adds this (iban, member) pairing if it's new — never removes any other member already linked to the same IBAN (joint accounts genuinely belong to more than one person). */
    public static function remember_iban(int $member_id, string $iban): void {
        global $wpdb;
        $iban = strtoupper(str_replace(' ', '', $iban));
        if ($iban === '') {
            return;
        }
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}avb_known_ibans WHERE iban = %s AND member_id = %d",
            $iban, $member_id
        ));
        if ($exists) {
            return;
        }
        $wpdb->insert("{$wpdb->prefix}avb_known_ibans", ['member_id' => $member_id, 'iban' => $iban]);
    }

    /** Every member known to be associated with this IBAN — could be more than one (joint account). */
    public static function get_member_ids_by_iban(string $iban): array {
        global $wpdb;
        $iban = strtoupper(str_replace(' ', '', $iban));
        if ($iban === '') {
            return [];
        }
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT member_id FROM {$wpdb->prefix}avb_known_ibans WHERE iban = %s", $iban
        )));
    }

    /** Only confident when exactly one member is known for this IBAN — a shared/joint account is ambiguous and belongs in manual review, not an auto-match. */
    public static function find_member_id_by_iban(string $iban): ?int {
        $member_ids = self::get_member_ids_by_iban($iban);
        return count($member_ids) === 1 ? $member_ids[0] : null;
    }

    // -------------------------------------------------------------------
    // Congress/reunion public registration — see AVBK_Congress.
    // -------------------------------------------------------------------

    /**
     * Resolves a registrant to an avm_members row: exact e-mail match wins
     * (strongest signal — LLDAP e-mail addresses are globally unique), then
     * an exact case-insensitive first+last name match. A single name match
     * also gets the submitted e-mail linked as a new identity so the
     * registrant can log in with it (OAuth or otherwise) afterwards without
     * a separate "link your account" step.
     *
     * Zero or ambiguous (>1) name matches both fall through to creating a
     * brand-new visitor member — cheap to reverse (deactivate/merge later)
     * versus the alternative of guessing wrong and attaching a payment to
     * the wrong person. The ambiguous case is flagged via $review_note so
     * the treasurer can spot and reconcile a likely duplicate.
     *
     * Returns ['member_id' => int, 'match_type' => 'email'|'name'|'new', 'review_note' => string].
     */
    public static function find_or_create_member_for_registration(
        string $first_name, string $suffix, string $last_name, string $email, string $phone
    ): array {
        $by_email = AVPVH_DB::get_member_by_email($email);
        if ($by_email) {
            return ['member_id' => (int) $by_email->id, 'match_type' => 'email', 'review_note' => ''];
        }

        $matches = AVPVH_DB::find_members_by_name($first_name, $last_name);
        if (count($matches) === 1) {
            $member_id = (int) $matches[0]->id;
            AVPVH_DB::ensure_identity($member_id, 'email', $email);
            return ['member_id' => $member_id, 'match_type' => 'name', 'review_note' => ''];
        }

        $review_note = '';
        if (count($matches) > 1) {
            $review_note = 'Mogelijk duplicaat — bestaande leden met dezelfde naam: '
                . implode(', ', array_map(fn($m) => avpvh_format_name($m, 'list'), $matches));
        }

        $base_uid = preg_replace('/[^a-z0-9._-]/', '.', strtolower("{$first_name}.{$last_name}"));
        $uid = $base_uid;
        $n = 1;
        while (AVPVH_LLDAP::get_user_display_name($uid) !== null) {
            $n++;
            $uid = "{$base_uid}{$n}";
        }
        $display_name = trim(preg_replace('/\s+/', ' ', "{$first_name} {$suffix} {$last_name}"));

        $created = AVPVH_LLDAP::create_user($uid, $email, $display_name);
        if (is_wp_error($created)) {
            // The registration itself must still succeed even if the LLDAP
            // write fails (e.g. e-mail already claimed by an account with no
            // matching avm_members row) — record it unlinked so the
            // treasurer can create/link the member by hand.
            return ['member_id' => 0, 'match_type' => 'new', 'review_note' => 'Kon geen lid aanmaken: ' . $created->get_error_message()];
        }

        $member_id = AVPVH_DB::create_member($uid, $first_name, $suffix, $last_name, null, 'visitor');
        if ($phone !== '') {
            AVPVH_DB::update_member_with_audit($member_id, ['phone' => $phone], ['%s']);
        }
        return ['member_id' => $member_id, 'match_type' => 'new', 'review_note' => $review_note];
    }

    /** Insert-or-reuse this member's open event fee item for $description (e.g. one row per congress edition, deduped by description so a re-submitted registration is a no-op, not a duplicate charge). Returns the fee_item id. */
    public static function upsert_event_fee_item(int $member_id, string $description, float $amount): int {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_fee_items WHERE member_id = %d AND type = 'event' AND description = %s",
            $member_id, $description
        ));
        if ($existing) {
            return (int) $existing->id;
        }
        $wpdb->insert("{$wpdb->prefix}avb_fee_items", [
            'member_id'   => $member_id,
            'type'        => 'event',
            'description' => $description,
            'amount_due'  => $amount,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function create_congress_registration(array $data): array {
        global $wpdb;
        $token = wp_generate_password(43, false, false);
        $wpdb->insert("{$wpdb->prefix}avb_congress_registrations", [
            'member_id'     => $data['member_id'] ?: null,
            'fee_item_id'   => $data['fee_item_id'] ?: null,
            'first_name'    => $data['first_name'],
            'suffix'        => $data['suffix'],
            'last_name'     => $data['last_name'],
            'email'         => $data['email'],
            'phone'         => $data['phone'],
            'match_type'    => $data['match_type'],
            'needs_review'  => $data['review_note'] !== '' ? 1 : 0,
            'review_note'   => $data['review_note'],
            'confirm_token' => $token,
        ]);
        return ['id' => (int) $wpdb->insert_id, 'token' => $token];
    }

    public static function get_congress_registration_by_token(string $token): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_congress_registrations WHERE confirm_token = %s", $token
        )) ?: null;
    }

    /** Idempotent — reopening the same confirmation link later just re-shows the QR without resetting confirmed_at. */
    public static function confirm_congress_registration(int $id): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}avb_congress_registrations SET status = 'confirmed', confirmed_at = COALESCE(confirmed_at, %s) WHERE id = %d",
            current_time('mysql'), $id
        ));
    }

    public static function mark_congress_email_result(int $id, bool $sent, string $error = ''): void {
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}avb_congress_registrations", [
            'email_sent'  => (int) $sent,
            'email_error' => $error,
        ], ['id' => $id]);
    }

    public static function get_congress_registrations(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}avb_congress_registrations ORDER BY created_at DESC"
        ) ?: [];
    }

    public static function count_congress_needs_attention(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}avb_congress_registrations WHERE needs_review = 1 OR email_sent = 0"
        );
    }
}
