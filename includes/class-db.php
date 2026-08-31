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

        // Replaces avb_contribution_rates + avb_camp_rates (both left in
        // place, unused, after the 1.8 migration copies their rows here) —
        // "everything you can be asked to contribute for is an activity",
        // so one shared, age-bracketed rate table for all of them.
        // activity_id is an avm_camps.id (a camp, "Contributie" (year in its own column),
        // "Congres" (year in its own column), ...), read via AVPVH_DB, not a real FK.
        dbDelta("CREATE TABLE {$wpdb->prefix}avb_activity_rates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            activity_id INT UNSIGNED NOT NULL,
            min_age TINYINT UNSIGNED NULL,
            max_age TINYINT UNSIGNED NULL,
            for_students TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            label VARCHAR(50) NOT NULL DEFAULT '',
            rate DECIMAL(8,2) NOT NULL,
            PRIMARY KEY (id),
            KEY activity_id (activity_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avb_member_student_years (
            member_id INT UNSIGNED NOT NULL,
            year SMALLINT UNSIGNED NOT NULL,
            is_student TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (member_id, year),
            KEY year (year)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avb_fee_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            type ENUM('contribution','camp','event','other') NOT NULL,
            year SMALLINT UNSIGNED NULL,
            activity_id INT UNSIGNED NULL,
            category VARCHAR(50) NOT NULL DEFAULT '',
            description VARCHAR(255) NOT NULL DEFAULT '',
            amount_due DECIMAL(8,2) NOT NULL,
            status ENUM('open','waived') NOT NULL DEFAULT 'open',
            is_estimated TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            estimate_reason VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY member_id (member_id),
            KEY member_type_year (member_id, type, year),
            KEY member_type_activity (member_id, type, activity_id)
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
            duplicate_of INT UNSIGNED NULL,
            activity_id INT UNSIGNED NULL,
            status ENUM('unmatched','suggested','matched','ignored','duplicate') NOT NULL DEFAULT 'unmatched',
            ignore_reason VARCHAR(30) NOT NULL DEFAULT '',
            suggested_member_ids VARCHAR(100) NOT NULL DEFAULT '',
            suggested_type VARCHAR(20) NOT NULL DEFAULT '',
            draft_data TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY dedupe_hash (dedupe_hash),
            KEY duplicate_of (duplicate_of),
            KEY activity_id (activity_id),
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
        // account (e.g. "H. Jansen e/o M.C. Bakker") also genuinely
        // belongs to more than one member at once. So this is a
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

        // Source metadata for a participation imported from an external
        // registration sheet. The participation itself remains owned by
        // avpvh-members; this table only preserves facts from the source
        // form that its generic participation schema does not contain.
        dbDelta("CREATE TABLE {$wpdb->prefix}avb_sheet_participation_meta (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            activity_id INT UNSIGNED NOT NULL,
            member_id INT UNSIGNED NOT NULL,
            registered_at DATETIME NULL,
            source_timestamp VARCHAR(100) NOT NULL DEFAULT '',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY activity_member (activity_id, member_id),
            KEY registered_at (registered_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avb_payment_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            fee_item_id INT UNSIGNED NOT NULL,
            member_id INT UNSIGNED NOT NULL,
            activity_id INT UNSIGNED NOT NULL,
            sent_to VARCHAR(255) NOT NULL DEFAULT '',
            sent_by BIGINT UNSIGNED NULL,
            requested_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY fee_item_id (fee_item_id),
            KEY activity_member (activity_id, member_id),
            KEY requested_at (requested_at)
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
        if (version_compare($version, '1.7', '<')) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_fee_items MODIFY COLUMN type ENUM('contribution','camp','event','other') NOT NULL");
            $has_category = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_fee_items LIKE 'category'");
            if (!$has_category) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_fee_items ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT '' AFTER camp_id");
            }
            update_option('avbk_db_version', '1.7');
        }
        if (version_compare($version, '1.8', '<')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$wpdb->prefix}avb_activity_rates (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                activity_id INT UNSIGNED NOT NULL,
                min_age TINYINT UNSIGNED NULL,
                max_age TINYINT UNSIGNED NULL,
                for_students TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                label VARCHAR(50) NOT NULL DEFAULT '',
                rate DECIMAL(8,2) NOT NULL,
                PRIMARY KEY (id),
                KEY activity_id (activity_id)
            ) {$wpdb->get_charset_collate()};");

            // Only copy once — re-running this migration block (e.g. after
            // a version_compare edge case) must not duplicate rows.
            $already_migrated = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}avb_activity_rates");
            if ($already_migrated === 0) {
                foreach ($wpdb->get_results("SELECT * FROM {$wpdb->prefix}avb_camp_rates") as $r) {
                    $wpdb->insert("{$wpdb->prefix}avb_activity_rates", [
                        'activity_id' => $r->camp_id, 'min_age' => $r->min_age, 'max_age' => $r->max_age,
                        'for_students' => 0, 'label' => $r->label, 'rate' => $r->day_rate,
                    ]);
                }

                $contributie_type_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}avm_activity_types WHERE name = %s", 'Contributie'
                ));
                if (!$contributie_type_id) {
                    $max_sort = (int) $wpdb->get_var("SELECT COALESCE(MAX(sort_order), 0) FROM {$wpdb->prefix}avm_activity_types");
                    $wpdb->insert("{$wpdb->prefix}avm_activity_types", ['name' => 'Contributie', 'sort_order' => $max_sort + 1]);
                    $contributie_type_id = (int) $wpdb->insert_id;
                }

                $years = $wpdb->get_col("SELECT DISTINCT year FROM {$wpdb->prefix}avb_contribution_rates");
                foreach ($years as $year) {
                    $year = (int) $year;
                    // Bare "Contributie", not "Contributie" (year in its own column) — year
                    // lives in its own column, same convention as a camp
                    // ("Zonneveld" + year=2026, not "Zonneveld 2026").
                    $activity_name = 'Contributie';
                    $activity_id = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}avm_camps WHERE name = %s AND year = %d", $activity_name, $year
                    ));
                    if (!$activity_id) {
                        $wpdb->insert("{$wpdb->prefix}avm_camps", [
                            'name' => $activity_name, 'year' => $year, 'type_id' => $contributie_type_id,
                        ]);
                        $activity_id = (int) $wpdb->insert_id;
                    }
                    foreach ($wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}avb_contribution_rates WHERE year = %d", $year
                    )) as $r) {
                        $wpdb->insert("{$wpdb->prefix}avb_activity_rates", [
                            'activity_id' => $activity_id, 'min_age' => $r->min_age, 'max_age' => $r->max_age,
                            'for_students' => $r->for_students, 'label' => $r->label, 'rate' => $r->amount,
                        ]);
                    }
                }
            }
            // avb_camp_rates / avb_contribution_rates stay in place, unused
            // (same non-destructive convention as the earlier avm_fees
            // migration) — nothing in the codebase reads them after this.
            update_option('avbk_db_version', '1.8');
        }
        if (version_compare($version, '1.9', '<')) {
            // avb_fee_items.camp_id really means "which avm_activities row"
            // (a camp, "Contributie" (year in its own column), "Congres" (year in its own column), an event, ...
            // — see upsert_event_fee_item()), matching avpvh-members' own
            // avm_camps -> avm_activities rename. Rename only — the fee
            // type value itself ('camp' in the type ENUM) is unrelated and
            // stays, since it's a real, distinct fee-category label, not a
            // naming artifact.
            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_fee_items LIKE 'camp_id'");
            if ($column_exists) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_fee_items
                    CHANGE COLUMN camp_id activity_id INT UNSIGNED NULL");
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_fee_items
                    RENAME INDEX member_type_camp TO member_type_activity");
            }
            update_option('avbk_db_version', '1.9');
        }
        if (version_compare($version, '1.10', '<')) {
            // A treasurer can save an in-progress split without confirming
            // it yet — the raw posted rows (member/activity/amount), not
            // processed into fee items/allocations until Bevestigen. One
            // draft per transaction, so a plain nullable column rather than
            // a separate table.
            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_transactions LIKE 'draft_data'");
            if (!$column_exists) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_transactions ADD COLUMN draft_data TEXT NULL AFTER suggested_type");
            }
            update_option('avbk_db_version', '1.10');
        }
        if (version_compare($version, '1.11', '<')) {
            // Receipt reimbursements — the club owes the member here, so
            // the QR at pay-time targets the member's own IBAN, not the
            // club's (see AVBK_QR::for_reimbursement()). receipt_path is a
            // random filename under a non-public directory (see
            // AVBK_Reimbursements), never a public uploads:// URL.
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$wpdb->prefix}avb_reimbursements (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                member_id INT UNSIGNED NOT NULL,
                activity_id INT UNSIGNED NULL,
                description VARCHAR(255) NOT NULL DEFAULT '',
                amount DECIMAL(8,2) NOT NULL,
                ocr_amount DECIMAL(8,2) NULL,
                receipt_path VARCHAR(255) NOT NULL DEFAULT '',
                iban VARCHAR(34) NOT NULL DEFAULT '',
                status ENUM('pending','paid','rejected') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                paid_at TIMESTAMP NULL,
                paid_by BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                KEY member_id (member_id),
                KEY status (status)
            ) {$wpdb->get_charset_collate()};");
            update_option('avbk_db_version', '1.11');
        }
        if (version_compare($version, '1.12', '<')) {
            // The bank's "tenaamstelling" for an IBAN (or, for a member-
            // submitted reimbursement IBAN, the member's own name) —
            // without it, two known IBANs for the same member are
            // indistinguishable in the UI (e.g. a household's shared
            // account vs. someone's personal one).
            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_known_ibans LIKE 'account_name'");
            if (!$column_exists) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_known_ibans ADD COLUMN account_name VARCHAR(255) NOT NULL DEFAULT ''");
            }
            update_option('avbk_db_version', '1.12');
        }
        if (version_compare($version, '1.13', '<')) {
            // Duplicate-receipt detection (see find_duplicate_receipt()):
            // receipt_hash catches the exact same photo re-uploaded,
            // ocr_date/ocr_store catch a re-photographed copy of the same
            // paper receipt (different bytes, same purchase).
            if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_reimbursements LIKE 'receipt_hash'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_reimbursements ADD COLUMN receipt_hash CHAR(64) NOT NULL DEFAULT ''");
            }
            if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_reimbursements LIKE 'ocr_date'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_reimbursements ADD COLUMN ocr_date DATE NULL");
            }
            if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_reimbursements LIKE 'ocr_store'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_reimbursements ADD COLUMN ocr_store VARCHAR(255) NOT NULL DEFAULT ''");
            }
            if (!$wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}avb_reimbursements WHERE Key_name = 'receipt_hash'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_reimbursements ADD KEY receipt_hash (receipt_hash)");
            }
            update_option('avbk_db_version', '1.13');
        }
        if (version_compare($version, '1.14', '<')) {
            // A declarant withdrawing their own accidental/duplicate
            // submission is a different fact than the penningmeester
            // rejecting it — keep it out of 'rejected' so the history
            // still shows who actually made that call.
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_reimbursements
                MODIFY COLUMN status ENUM('pending','paid','rejected','withdrawn') NOT NULL DEFAULT 'pending'");
            update_option('avbk_db_version', '1.14');
        }
        if (version_compare($version, '1.15', '<')) {
            // Multiple receipt photos per declaration (e.g. one trip, three
            // shops) — a child table rather than widening avb_reimbursements
            // further. Old single-receipt declarations keep their photo on
            // the parent row's own receipt_path/receipt_hash/ocr_* columns
            // (left as-is, never backfilled into this table) — see
            // AVBK_DB::get_reimbursement_receipts()'s fallback for why that
            // needs no migration of its own.
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$wpdb->prefix}avb_reimbursement_receipts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                reimbursement_id INT UNSIGNED NOT NULL,
                receipt_path VARCHAR(255) NOT NULL DEFAULT '',
                receipt_hash CHAR(64) NOT NULL DEFAULT '',
                ocr_amount DECIMAL(8,2) NULL,
                ocr_date DATE NULL,
                ocr_store VARCHAR(255) NOT NULL DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY reimbursement_id (reimbursement_id),
                KEY receipt_hash (receipt_hash)
            ) {$wpdb->get_charset_collate()};");
            update_option('avbk_db_version', '1.15');
        }
        if (version_compare($version, '1.16', '<')) {
            // A declaration is one activity but can bundle several
            // purchases — each needs its own free-text description (member-
            // editable, pre-filled with OCR's store+date guess), so this
            // lives per receipt rather than once on the parent row. The
            // parent's own description column stays as a joined summary for
            // list views (see AVBK_Reimbursements::handle_submit()).
            if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_reimbursement_receipts LIKE 'description'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_reimbursement_receipts ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT '' AFTER receipt_hash");
            }
            update_option('avbk_db_version', '1.16');
        }
        if (version_compare($version, '1.17', '<')) {
            // Four-eyes control: who first confirmed/matched a payment
            // (confirmed_by), and whether a second, different person has
            // since signed off on it (second_approved_by/_at). Applies
            // retroactively to every already-'matched' transaction too —
            // they simply start out with second_approved_by NULL, same as
            // any new one, so they land in the review queue automatically.
            if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_transactions LIKE 'confirmed_by'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_transactions ADD COLUMN confirmed_by BIGINT UNSIGNED NULL");
            }
            if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_transactions LIKE 'second_approved_by'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_transactions ADD COLUMN second_approved_by BIGINT UNSIGNED NULL");
            }
            if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_transactions LIKE 'second_approved_at'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_transactions ADD COLUMN second_approved_at TIMESTAMP NULL");
            }
            update_option('avbk_db_version', '1.17');
        }
        if (version_compare($version, '1.18', '<')) {
            // The original xlsx row number (see AVBK_Xlsx_Reader::read())
            // for cross-checking an imported transaction against the
            // source spreadsheet — NULL for anything imported before this
            // column existed.
            if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_transactions LIKE 'source_row'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_transactions ADD COLUMN source_row INT UNSIGNED NULL");
            }
            update_option('avbk_db_version', '1.18');
        }
        if (version_compare($version, '1.19', '<')) {
            // Contribution fee items predate their activity_id link. The
            // review queue selects a concrete "Contributie (year)"
            // activity, so backfill every historic item—not only active
            // members touched by the normal yearly generator—by its year.
            $years = array_map('intval', $wpdb->get_col(
                "SELECT DISTINCT year FROM {$wpdb->prefix}avb_fee_items
                 WHERE type = 'contribution' AND year IS NOT NULL AND activity_id IS NULL"
            ));
            foreach ($years as $year) {
                $activity = AVPVH_DB::get_activity_by_name_year('Contributie', $year);
                if ($activity) {
                    $wpdb->update(
                        "{$wpdb->prefix}avb_fee_items",
                        ['activity_id' => (int) $activity->id],
                        ['type' => 'contribution', 'year' => $year, 'activity_id' => null]
                    );
                }
            }
            update_option('avbk_db_version', '1.19');
        }
        if (version_compare($version, '1.20', '<')) {
            // ING exports the same transaction with translated field labels
            // and different date separators when the account language
            // changes. The old raw-description hash treated those as two
            // payments. Record which safe, unconfirmed copies duplicate the
            // already-linked survivor so the queue and history can explain
            // what happened without deleting any bank data.
            if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_transactions LIKE 'duplicate_of'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_transactions ADD COLUMN duplicate_of INT UNSIGNED NULL AFTER dedupe_hash, ADD KEY duplicate_of (duplicate_of)");
            }
            self::mark_semantic_transaction_duplicates();
            update_option('avbk_db_version', '1.20');
        }
        if (version_compare($version, '1.21', '<')) {
            // An allocation is final bookkeeping and may only belong to a
            // matched transaction. Earlier reset/recompute paths could
            // leave allocations behind after putting the bank row back in
            // suggested/unmatched state; those ghost payments then reduced
            // a contribution even though the source transaction was still
            // visibly waiting in the review queue. Undo only that invalid
            // state, preserving every bank row for correct reassignment.
            $inconsistent_ids = array_map('intval', $wpdb->get_col(
                "SELECT DISTINCT t.id
                 FROM {$wpdb->prefix}avb_transactions t
                 JOIN {$wpdb->prefix}avb_transaction_allocations a ON a.transaction_id = t.id
                 WHERE t.status != 'matched'"
            ));
            foreach ($inconsistent_ids as $transaction_id) {
                self::revert_transaction_to_review($transaction_id);
            }
            update_option('avbk_db_version', '1.21');
        }
        if (version_compare($version, '1.22', '<')) {
            // The treasurer explicitly requested that every incoming 2026
            // payment be assigned again. Some rows were subsequently
            // auto-marked matched from a known IBAN even when that attempt
            // allocated €0 (or only part of the bank amount). Version 1.22
            // both accompanies the all-or-nothing auto-match fix and runs
            // the existing safe year reset once: bank rows stay intact,
            // while allocations/approvals are removed and all non-duplicate
            // 2026 receipts return to the normal review queue.
            self::revert_assigned_payments_for_year(2026);
            update_option('avbk_db_version', '1.22');
        }
        if (version_compare($version, '1.23', '<')) {
            // A transaction register should contain each real bank mutation
            // once. Version 1.20 conservatively kept legacy translated-
            // export copies and merely linked them with duplicate_of. Now
            // that semantic duplicate detection runs before insert, remove
            // only those proven, allocation-free copies whose survivor is
            // still present. Never touch an original or an allocated row.
            $wpdb->query(
                "DELETE duplicate_row
                 FROM {$wpdb->prefix}avb_transactions duplicate_row
                 JOIN {$wpdb->prefix}avb_transactions survivor
                   ON survivor.id = duplicate_row.duplicate_of
                 LEFT JOIN {$wpdb->prefix}avb_transaction_allocations allocation
                   ON allocation.transaction_id = duplicate_row.id
                 WHERE duplicate_row.duplicate_of IS NOT NULL
                   AND allocation.id IS NULL"
            );
            update_option('avbk_db_version', '1.23');
        }
        if (version_compare($version, '1.24', '<')) {
            // "Ignored" used to conflate an outgoing row automatically
            // excluded during import with an incoming payment deliberately
            // dismissed by the treasurer. Preserve that provenance.
            if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_transactions LIKE 'ignore_reason'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_transactions ADD COLUMN ignore_reason VARCHAR(30) NOT NULL DEFAULT '' AFTER status");
            }
            $wpdb->query("UPDATE {$wpdb->prefix}avb_transactions SET ignore_reason = 'import_outgoing' WHERE status = 'ignored' AND direction = 'out' AND ignore_reason = ''");
            $wpdb->query("UPDATE {$wpdb->prefix}avb_transactions SET ignore_reason = 'manual_review' WHERE status = 'ignored' AND direction = 'in' AND ignore_reason = ''");
            update_option('avbk_db_version', '1.24');
        }
        if (version_compare($version, '1.25', '<')) {
            // Version 1.23 deleted only rows that version 1.20 had already
            // marked. The Dutch/English description canonicalization was
            // improved afterwards, so translated copies missed by that
            // first pass remained unmarked and therefore survived 1.23.
            // Re-run the current comparison before deleting only proven,
            // allocation-free copies. Originals and allocated rows remain.
            self::mark_semantic_transaction_duplicates();
            $wpdb->query(
                "DELETE duplicate_row
                 FROM {$wpdb->prefix}avb_transactions duplicate_row
                 JOIN {$wpdb->prefix}avb_transactions survivor
                   ON survivor.id = duplicate_row.duplicate_of
                 LEFT JOIN {$wpdb->prefix}avb_transaction_allocations allocation
                   ON allocation.transaction_id = duplicate_row.id
                 WHERE duplicate_row.duplicate_of IS NOT NULL
                   AND allocation.id IS NULL"
            );
            update_option('avbk_db_version', '1.25');
        }
        if (version_compare($version, '1.26', '<')) {
            // One ING service-charge row has no Omschrijving/Description
            // field, so it uses normalize_dedupe_description()'s fallback.
            // Re-run after canonicalizing translated labels in that path.
            self::mark_semantic_transaction_duplicates();
            $wpdb->query(
                "DELETE duplicate_row
                 FROM {$wpdb->prefix}avb_transactions duplicate_row
                 JOIN {$wpdb->prefix}avb_transactions survivor
                   ON survivor.id = duplicate_row.duplicate_of
                 LEFT JOIN {$wpdb->prefix}avb_transaction_allocations allocation
                   ON allocation.transaction_id = duplicate_row.id
                 WHERE duplicate_row.duplicate_of IS NOT NULL
                   AND allocation.id IS NULL"
            );
            update_option('avbk_db_version', '1.26');
        }
        if (version_compare($version, '1.27', '<')) {
            $charset = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$wpdb->prefix}avb_sheet_participation_meta (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                activity_id INT UNSIGNED NOT NULL,
                member_id INT UNSIGNED NOT NULL,
                registered_at DATETIME NULL,
                source_timestamp VARCHAR(100) NOT NULL DEFAULT '',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY activity_member (activity_id, member_id),
                KEY registered_at (registered_at)
            ) $charset;");
            update_option('avbk_db_version', '1.27');
        }
        if (version_compare($version, '1.28', '<')) {
            $charset = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$wpdb->prefix}avb_payment_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                fee_item_id INT UNSIGNED NOT NULL,
                member_id INT UNSIGNED NOT NULL,
                activity_id INT UNSIGNED NOT NULL,
                sent_to VARCHAR(255) NOT NULL DEFAULT '',
                sent_by BIGINT UNSIGNED NULL,
                requested_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY fee_item_id (fee_item_id),
                KEY activity_member (activity_id, member_id),
                KEY requested_at (requested_at)
            ) $charset;");
            update_option('avbk_db_version', '1.28');
        }
        if (version_compare($version, '1.29', '<')) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_transactions MODIFY status ENUM('unmatched','suggested','matched','ignored','duplicate') NOT NULL DEFAULT 'unmatched'");
            $wpdb->query("UPDATE {$wpdb->prefix}avb_transactions SET status = 'duplicate' WHERE duplicate_of IS NOT NULL");
            update_option('avbk_db_version', '1.29');
        }
        if (version_compare($version, '1.30', '<')) {
            $charset = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$wpdb->prefix}avb_member_student_years (
                member_id INT UNSIGNED NOT NULL,
                year SMALLINT UNSIGNED NOT NULL,
                is_student TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (member_id, year),
                KEY year (year)
            ) $charset;");
            update_option('avbk_db_version', '1.30');
        }
        if (version_compare($version, '1.31', '<')) {
            $has_activity_id = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avb_transactions LIKE 'activity_id'");
            if (!$has_activity_id) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avb_transactions ADD COLUMN activity_id INT UNSIGNED NULL AFTER duplicate_of, ADD KEY activity_id (activity_id)");
            }
            update_option('avbk_db_version', '1.31');
        }
    }

    // -------------------------------------------------------------------
    // Contribution rates
    // -------------------------------------------------------------------

    public static function get_member_student_years(int $member_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT year, is_student FROM {$wpdb->prefix}avb_member_student_years WHERE member_id = %d ORDER BY year DESC",
            $member_id
        )) ?: [];
    }

    public static function set_member_student_year(int $member_id, int $year, bool $is_student): void {
        global $wpdb;
        $wpdb->replace("{$wpdb->prefix}avb_member_student_years", [
            'member_id' => $member_id,
            'year' => $year,
            'is_student' => $is_student ? 1 : 0,
        ], ['%d', '%d', '%d']);
    }

    public static function delete_member_student_year(int $member_id, int $year): void {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}avb_member_student_years", ['member_id' => $member_id, 'year' => $year], ['%d', '%d']);
    }

    /** A year-specific status wins; otherwise retain the member's current flag. */
    public static function is_member_student_for_year(object $member, int $year): bool {
        global $wpdb;
        $override = $wpdb->get_var($wpdb->prepare(
            "SELECT is_student FROM {$wpdb->prefix}avb_member_student_years WHERE member_id = %d AND year = %d",
            (int) $member->id,
            $year
        ));
        return $override === null ? !empty($member->is_student) : (bool) $override;
    }

    /** All age-bracket rate rows for one activity, e.g. kids 0-3 free / 4-12 €10 / 13+ €20 — a camp (per night), contribution (per year), or any other activity. */
    public static function get_activity_rates(int $activity_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_activity_rates WHERE activity_id = %d ORDER BY min_age ASC",
            $activity_id
        )) ?: [];
    }

    /** The student rate for this activity, if one is configured — checked before age, since student is a status flag, not an age bracket. */
    public static function get_student_activity_rate(int $activity_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_activity_rates WHERE activity_id = %d AND for_students = 1 LIMIT 1",
            $activity_id
        )) ?: null;
    }

    /**
     * The rate row covering $age for this activity, or null if none
     * configured. Excludes for_students rows — those only ever apply via
     * the is_student flag (get_student_activity_rate), never by
     * coincidentally matching someone's age.
     */
    public static function get_rate_for_age(int $activity_id, int $age): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_activity_rates
             WHERE activity_id = %d AND for_students = 0
               AND (min_age IS NULL OR min_age <= %d)
               AND (max_age IS NULL OR max_age >= %d)
             ORDER BY (min_age IS NOT NULL) DESC, (max_age IS NOT NULL) DESC
             LIMIT 1",
            $activity_id, $age, $age
        )) ?: null;
    }

    /**
     * The "adult" bracket for this activity — used when a member's birth
     * date is unknown so a fee item still generates (assumed adult,
     * flagged as estimated) rather than being silently skipped. Only ever
     * a genuinely open-ended bracket (max_age IS NULL) — the real
     * "everyone else" catch-all. Deliberately does NOT fall back to
     * "whichever bracket has the highest min_age" when no open-ended one
     * exists: with only a capped child bracket configured so far (e.g.
     * "Kinderen 4-15"), that would pick the child rate and mislabel it as
     * an assumed-adult amount — worse than just not generating a fee item
     * yet. Excludes for_students rows, same reason as get_rate_for_age().
     */
    public static function get_adult_activity_rate(int $activity_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_activity_rates
             WHERE activity_id = %d AND for_students = 0 AND max_age IS NULL
             ORDER BY min_age DESC
             LIMIT 1",
            $activity_id
        )) ?: null;
    }

    public static function save_activity_rate(int $id, int $activity_id, ?int $min_age, ?int $max_age, string $label, float $rate, bool $for_students = false): int {
        global $wpdb;
        $data = [
            'activity_id'  => $activity_id,
            'min_age'      => $min_age,
            'max_age'      => $max_age,
            'for_students' => (int) $for_students,
            'label'        => $label,
            'rate'         => $rate,
        ];
        if ($id > 0) {
            $wpdb->update("{$wpdb->prefix}avb_activity_rates", $data, ['id' => $id]);
            return $id;
        }
        $wpdb->insert("{$wpdb->prefix}avb_activity_rates", $data);
        return (int) $wpdb->insert_id;
    }

    public static function delete_activity_rate(int $id): void {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}avb_activity_rates", ['id' => $id]);
    }

    /** Camps (from avpvh-members, type "Kamp" only — contribution/other activities auto-generate differently and aren't flagged here) with no rate brackets configured yet — camp fee items can't generate for these. */
    public static function get_camps_without_rate(): array {
        global $wpdb;
        $rated_activity_ids = array_unique(array_map('intval', $wpdb->get_col("SELECT DISTINCT activity_id FROM {$wpdb->prefix}avb_activity_rates")));
        return array_values(array_filter(
            AVPVH_DB::get_activities(),
            fn($camp) => ($camp->type_name ?? '') === 'Kamp' && !in_array((int) $camp->id, $rated_activity_ids, true)
        ));
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

    public static function get_camp_fee_item(int $member_id, int $activity_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_fee_items WHERE member_id = %d AND type = 'camp' AND activity_id = %d",
            $member_id, $activity_id
        )) ?: null;
    }

    /**
     * The concrete avm_activities row currently active for a given activity
     * *type* name (Kamp/Congres/Contributie/...) — "current" meaning most
     * recent by year. Used only to default the review queue's initial
     * suggestion to a specific activity when the bank description merely
     * names a type ("KAMP EN CONTRIBUTIE"); the treasurer's own row picks
     * the exact activity directly (see get_member_fee_detail_for_activity()),
     * so this heuristic never runs once a human has made a choice.
     */
    public static function get_current_activity_for_type_name(string $type_name): ?object {
        $type = current(array_filter(
            AVPVH_DB::get_activity_types(),
            fn($t) => $t->name === $type_name
        ));
        return $type ? AVPVH_DB::get_current_activity((int) $type->id) : null;
    }

    /**
     * Everything the review queue's per-row line shows for one member
     * against one specific, treasurer-chosen activity: amount still open,
     * the rate category or nights/dates fragment, earlier-payment links
     * when nothing remains, and an estimated-amount warning. Shared between
     * the initial page render and the AJAX endpoint that refreshes this
     * when the treasurer swaps the selected member or activity on an
     * already-rendered row — one source of truth for both so they can
     * never drift out of sync. A direct member+activity match (rather than
     * the old member+type "current item" guess) is unambiguous even when a
     * member has two open items of the same type across different years.
     */
    public static function get_member_fee_detail_for_activity(int $member_id, int $activity_id): array {
        global $wpdb;
        $member = AVPVH_DB::get_member($member_id);
        $detail = [
            'share' => 0.0,
            'fragments_html' => '',
            'estimated_text' => '',
            'estimated_warning' => false,
            'found' => false,
        ];
        if (!$member || !$activity_id) {
            return $detail;
        }
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_fee_items WHERE member_id = %d AND activity_id = %d AND status = 'open' LIMIT 1",
            $member_id, $activity_id
        ));
        if (!$item) {
            // A registered one-off activity (Weekend/Feest/...) can have no
            // fee item yet because its participant list is being built from
            // confirmed payments. That must not hide member status or the
            // activity's configured payment schedule. If a rate applies,
            // expose it as a known suggested amount; confirmation will then
            // create the participation and paid fee item for this activity.
            $activity = AVPVH_DB::get_activity($activity_id);
            $fragments = [];
            if (!empty($member->is_student)) {
                $fragments[] = 'scholier/student';
            }
            $requires_generated_fee = $activity
                && isset(self::activity_fee_type_map()[$activity->type_name]);
            if ($activity && !$requires_generated_fee) {
                $reference_date = $activity->start_date
                    ?: ((int) ($activity->year ?? current_time('Y'))) . '-01-01';
                $computed = AVBK_Fee_Generation::compute_activity_rate($member, $activity, 1, $reference_date);
                if ($computed) {
                    $detail['found'] = true;
                    $detail['share'] = (float) $computed['amount'];
                    if ($computed['rate']->label !== '') {
                        $fragments[] = 'tariefcategorie: ' . esc_html($computed['rate']->label);
                    }
                    if ($computed['is_estimated']) {
                        $reason = $computed['reason'] ?: 'Geschat bedrag.';
                        $detail['estimated_warning'] = !str_starts_with($reason, 'Alleen geboortejaar ');
                        $detail['estimated_text'] = ($detail['estimated_warning'] ? "\u{26A0} " : '') . $reason;
                    }
                }
            }
            $detail['fragments_html'] = implode(' &middot; ', $fragments);
            return $detail;
        }
        $detail['found'] = true;
        $paid = self::get_fee_item_paid((int) $item->id);
        $detail['share'] = round((float) $item->amount_due - $paid, 2);
        if (!empty($item->is_estimated)) {
            $reason = $item->estimate_reason ?: 'Geschat bedrag.';
            $detail['estimated_warning'] = !str_starts_with($reason, 'Alleen geboortejaar ');
            $detail['estimated_text'] = ($detail['estimated_warning'] ? "\u{26A0} " : '') . $reason;
        }

        $fragments = [];
        if ($item->type === 'contribution') {
            // Show the category that actually selected the amount, not the
            // member's derived age. Labels belong to the activity's payment
            // schedule and may be anything the treasurer configured.
            $activity = AVPVH_DB::get_activity($activity_id);
            $year = (int) ($item->year ?: ($activity->year ?? current_time('Y')));
            $computed = $activity
                ? AVBK_Fee_Generation::compute_activity_rate($member, $activity, 1, "$year-01-01")
                : null;
            if ($computed && $computed['rate']->label !== '') {
                $fragments[] = 'tariefcategorie: ' . esc_html($computed['rate']->label);
            }
        } elseif ($item->type === 'camp') {
            $participation = AVPVH_DB::get_participation($member_id, $activity_id);
            if ($participation && $participation->nights) {
                $activity = AVPVH_DB::get_activity($activity_id);
                $computed = $activity
                    ? AVBK_Fee_Generation::compute_activity_rate(
                        $member,
                        $activity,
                        (int) $participation->nights,
                        $activity->start_date ?: current_time('Y-m-d')
                    )
                    : null;
                if ($computed && $computed['rate']->label !== '') {
                    $fragments[] = 'tariefcategorie: ' . esc_html($computed['rate']->label);
                }
                $nights_parts = [(int) $participation->nights . ' nacht' . ((int) $participation->nights === 1 ? '' : 'en')];
                // Actual dates present (not just the night count) — same
                // "non-empty status = present" rule the Kampdeelname list
                // itself uses for "Dagen aanwezig".
                $days = AVPVH_DB::get_participation_days((int) $participation->id);
                $present_dates = array_keys(array_filter($days, fn($status) => $status !== ''));
                sort($present_dates);
                if ($present_dates) {
                    $nights_parts[] = esc_html(wp_date('D d M', strtotime(reset($present_dates))))
                        . '&ndash;' . esc_html(wp_date('D d M', strtotime(end($present_dates))));
                }
                $nights_edit_url = add_query_arg([
                    'page' => 'avpvh-activity-participation-detail',
                    'activity_id' => $activity_id,
                    'id' => (int) $participation->id,
                ], admin_url('admin.php'));
                // The nights/dates themselves are the click target — no
                // separate "wijzig overnachtingen" link cluttering every
                // row (there's a one-time hint above the queue instead).
                $fragments[] = '<a href="' . esc_url($nights_edit_url) . '" target="_blank">inschrijving: ' . implode(', ', $nights_parts) . '</a>';
            }
        }
        if ($detail['share'] > 0.005 && $paid <= 0.005) {
            $fragments[] = 'openstaand v&oacute;&oacute;r deze betaling: &euro; '
                . esc_html(number_format($detail['share'], 2, ',', '.'));
        }
        if ($paid > 0.005) {
            $payment_links = [];
            foreach (self::get_payments_for_fee_item((int) $item->id) as $payment) {
                $transaction_url = add_query_arg(
                    ['page' => 'avbk-transactions', 'show_all_years' => '1'],
                    admin_url('admin.php')
                ) . '#tx-' . (int) $payment->transaction_id;
                $import_source = !empty($payment->import_batch_id)
                    ? ' · import #' . (int) $payment->import_batch_id
                        . (!empty($payment->import_filename) ? ': ' . esc_html($payment->import_filename) : '')
                    : '';
                $payment_links[] = '<a href="' . esc_url($transaction_url) . '">transactie #'
                    . (int) $payment->transaction_id . ' van '
                    . esc_html(wp_date('d-m-Y', strtotime($payment->transaction_date)))
                    . ' (&euro; ' . esc_html(number_format((float) $payment->allocated_amount, 2, ',', '.'))
                    . $import_source . ')</a>';
            }
            if ($payment_links) {
                if ($detail['share'] <= 0.005) {
                    $fragments[] = '<strong>al betaald</strong> via ' . implode(', ', $payment_links);
                } else {
                    $fragments[] = '<strong>eerder &euro; '
                        . esc_html(number_format($paid, 2, ',', '.'))
                        . ' betaald</strong> via ' . implode(', ', $payment_links)
                        . '; nog open &euro; ' . esc_html(number_format($detail['share'], 2, ',', '.'));
                }
            }
        }
        // Each fragment is already safe (plain text, escaped dynamic text,
        // or hand-built links with escaped URLs/labels) — esc_html-ing the
        // joined result here would double-encode/strip it.
        $detail['fragments_html'] = implode(' &middot; ', $fragments);
        return $detail;
    }

    /**
     * Same shape as get_member_fee_detail_for_activity(), for a review-queue
     * row picking a category with no tarieventabel at all (Weekend, Drank,
     * Overig, ...) — there's no fee_item/rate to compute a bedrag from, but
     * the treasurer typing one in by hand can still benefit from knowing
     * the member is a scholier/student, same as they'd see for Contributie.
     * 'share'/'found' stay at their empty defaults; only 'fragments_html'
     * is ever populated here.
     */
    public static function get_member_status_detail(int $member_id): array {
        $detail = ['share' => 0.0, 'fragments_html' => '', 'estimated_text' => '', 'estimated_warning' => false, 'found' => false];
        $member = AVPVH_DB::get_member($member_id);
        if ($member && !empty($member->is_student)) {
            $detail['fragments_html'] = 'scholier/student';
        }
        return $detail;
    }

    /** Insert or update the member's contribution fee item for $year. Returns the fee_item id. */
    public static function upsert_contribution_fee_item(int $member_id, int $year, float $amount, string $description, bool $is_estimated = false, string $estimate_reason = '', int $activity_id = 0): int {
        global $wpdb;
        $existing = self::get_contribution_fee_item($member_id, $year);
        $data = [
            'amount_due'      => $amount,
            'description'     => $description,
            'is_estimated'    => (int) $is_estimated,
            'estimate_reason' => $estimate_reason,
        ];
        if ($activity_id > 0) {
            // Review-queue activity choices use the concrete activity id;
            // without this link their AJAX amount lookup cannot find an
            // otherwise perfectly valid contribution fee item.
            $data['activity_id'] = $activity_id;
        }
        if ($existing) {
            if ($existing->status === 'open') {
                $wpdb->update(
                    "{$wpdb->prefix}avb_fee_items",
                    $data,
                    ['id' => $existing->id]
                );
            }
            return (int) $existing->id;
        }
        $wpdb->insert("{$wpdb->prefix}avb_fee_items", [
            'member_id'       => $member_id,
            'type'            => 'contribution',
            'year'            => $year,
        ] + $data);
        return (int) $wpdb->insert_id;
    }

    /** Insert or update the member's camp fee item, kept current as attendance/nights change. Returns the fee_item id. */
    public static function upsert_camp_fee_item(int $member_id, int $activity_id, float $amount, string $description, bool $is_estimated = false, string $estimate_reason = ''): int {
        global $wpdb;
        $existing = self::get_camp_fee_item($member_id, $activity_id);
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
            'activity_id'     => $activity_id,
            'description'     => $description,
            'amount_due'      => $amount,
            'is_estimated'    => (int) $is_estimated,
            'estimate_reason' => $estimate_reason,
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * A one-off charge outside the recurring contribution/camp system —
     * drank, eten, boek, t-shirt, or anything else the treasurer notices on
     * a bank transaction. Unlike upsert_contribution_fee_item()/
     * upsert_camp_fee_item(), this always inserts a new row rather than
     * updating an existing one: two "Drank" charges for the same member
     * are two real, separate charges, never a correction of each other.
     */
    public static function create_other_fee_item(int $member_id, string $category, string $description, float $amount, int $activity_id = 0): int {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}avb_fee_items", [
            'member_id'   => $member_id,
            'type'        => 'other',
            'category'    => $category,
            'description' => $description !== '' ? "{$category} ({$description})" : $category,
            'amount_due'  => $amount,
            'activity_id' => $activity_id ?: null,
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * Which activity-type names (from AVPVH_DB::get_activity_types(), the
     * same admin-editable list an activity's own type uses) correspond to
     * an existing, already-generated fee item (avb_fee_items.type) versus
     * a one-off category (Drank/Eten/Overig/...) that gets a brand new fee
     * item created on the spot. The review queue's own rows match a
     * specific activity directly (see get_member_fee_detail_for_activity())
     * and no longer need this map for that; it's still used to (a) resolve
     * an initial suggestion's bare type name ("KAMP EN CONTRIBUTIE") to a
     * concrete current activity, and (b) AVBK_Import::apply_payment()'s
     * fully-automatic bank-import path, which only ever has a guessed type
     * list to prioritize against, never a human-picked activity.
     */
    public static function activity_fee_type_map(): array {
        return ['Contributie' => 'contribution', 'Kamp' => 'camp', 'Congres' => 'event'];
    }

    /** Admin member-detail link used throughout bookkeeping. */
    public static function member_edit_url(int $member_id): string {
        return $member_id ? add_query_arg([
            'page' => 'avpvh-member-detail',
            'id'   => $member_id,
        ], admin_url('admin.php')) : '';
    }

    /**
     * Members who can plausibly appear as a payer on a bank transaction —
     * active members plus visitors (bezoekers), who pay one-off camp/event
     * fees but aren't full members and so are excluded from the yearly
     * contribution generation. Excludes only 'inactive' (former members).
     * AVPVH_DB::get_members()'s status filter is a single exact match, not
     * an IN-list, so this fetches both and merges/re-sorts in PHP.
     */
    public static function get_payable_members(): array {
        $members = array_merge(
            AVPVH_DB::get_members(['status' => 'active']),
            AVPVH_DB::get_members(['status' => 'visitor'])
        );
        usort($members, fn($a, $b) => strcmp($a->last_name, $b->last_name) ?: strcmp($a->first_name, $b->first_name));
        return $members;
    }

    /**
     * Household candidates for assigning a bank payment.
     *
     * The members plugin normally supplies this through explicit family
     * relations and its current-address lookup. Some migrated address
     * histories contain an old dated row without an end date alongside the
     * undated current address, though. In that case its current-address
     * ordering picks the historical row and silently splits a real family.
     * For payment suggestions, prefer the undated address record and merge
     * those actual housemates into the normal extended household.
     */
    public static function get_payment_household_candidates(int $member_id): array {
        global $wpdb;

        $candidates = [];
        foreach (AVPVH_DB::get_extended_household($member_id) as $member) {
            $candidates[(int) $member->id] = $member;
        }

        $address = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_addresses
             WHERE member_id = %d
               AND (valid_from IS NULL OR valid_from <= %s)
               AND (valid_until IS NULL OR valid_until >= %s)
             ORDER BY (valid_from IS NULL) DESC, valid_from DESC, id DESC
             LIMIT 1",
            $member_id,
            current_time('Y-m-d'),
            current_time('Y-m-d')
        ));
        if (!$address || !$address->street) {
            return array_values($candidates);
        }

        $member_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT m.id
             FROM {$wpdb->prefix}avm_members m
             JOIN {$wpdb->prefix}avm_addresses a ON a.id = (
                 SELECT a2.id FROM {$wpdb->prefix}avm_addresses a2
                 WHERE a2.member_id = m.id
                   AND (a2.valid_from IS NULL OR a2.valid_from <= %s)
                   AND (a2.valid_until IS NULL OR a2.valid_until >= %s)
                 ORDER BY (a2.valid_from IS NULL) DESC, a2.valid_from DESC, a2.id DESC
                 LIMIT 1
             )
             WHERE m.status IN ('active', 'visitor')
               AND LOWER(TRIM(a.street)) = LOWER(TRIM(%s))
               AND LOWER(TRIM(a.house_number)) = LOWER(TRIM(%s))
               AND LOWER(TRIM(a.postal_code)) = LOWER(TRIM(%s))",
            current_time('Y-m-d'),
            current_time('Y-m-d'),
            $address->street,
            $address->house_number,
            $address->postal_code
        ));
        foreach ($member_ids as $candidate_id) {
            $candidate = AVPVH_DB::get_member((int) $candidate_id);
            if ($candidate) {
                $candidates[(int) $candidate->id] = $candidate;
            }
        }
        return array_values($candidates);
    }

    /** Every open (non-waived) contribution/camp fee item — AVBK_Fee_Generation::find_stale_fee_items() recomputes each against today's rate table/birth data to catch the "edited after the fee item was generated" class of bug (a birth date fixed, nights corrected — anything other than the one save that already triggers a refresh). */
    public static function get_open_contribution_and_camp_fee_items(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}avb_fee_items WHERE status = 'open' AND type IN ('contribution', 'camp')"
        ) ?: [];
    }

    public static function get_fee_item(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_fee_items WHERE id = %d", $id
        )) ?: null;
    }

    /** Calendar/book year for any fee item, including event/other rows whose own year column is null. */
    public static function fee_item_book_year(object $item): int {
        if (!empty($item->year)) {
            return (int) $item->year;
        }
        if (!empty($item->activity_id)) {
            $activity = AVPVH_DB::get_activity((int) $item->activity_id);
            if ($activity && !empty($activity->year)) {
                return (int) $activity->year;
            }
        }
        return !empty($item->created_at) ? (int) wp_date('Y', strtotime($item->created_at)) : 0;
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
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_fee_items
             WHERE member_id = %d AND status = 'open'
             ORDER BY COALESCE(year, 0) ASC, created_at ASC",
            $member_id
        )) ?: [];
        $closed_through_year = (int) get_option('avbk_closed_through_year', 0);
        return $closed_through_year
            ? array_values(array_filter($items, fn($item) => self::fee_item_book_year($item) > $closed_through_year))
            : $items;
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

    /** Earlier transactions that already paid a fee item, for the review queue's explanatory hotlinks when the remaining amount is zero. */
    public static function get_payments_for_fee_item(int $fee_item_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.transaction_id, a.amount AS allocated_amount, t.transaction_date,
                    t.import_batch_id, b.filename AS import_filename
             FROM {$wpdb->prefix}avb_transaction_allocations a
             JOIN {$wpdb->prefix}avb_transactions t ON t.id = a.transaction_id
             LEFT JOIN {$wpdb->prefix}avb_import_batches b ON b.id = t.import_batch_id
             WHERE a.fee_item_id = %d
             ORDER BY t.transaction_date ASC, t.id ASC",
            $fee_item_id
        )) ?: [];
    }

    /** Preserve the original form timestamp for a sheet-imported attendee. */
    public static function save_sheet_participation_meta(
        int $activity_id,
        int $member_id,
        ?string $registered_at,
        string $source_timestamp = ''
    ): void {
        global $wpdb;
        if ($activity_id <= 0 || $member_id <= 0 || ($registered_at === null && $source_timestamp === '')) {
            return;
        }
        $existing = self::get_sheet_participation_meta($activity_id, $member_id);
        if ($existing && $existing->registered_at && (!$registered_at || $registered_at >= $existing->registered_at)) {
            return; // keep the first/original registration when a later duplicate row appears
        }
        $data = [
            'activity_id'      => $activity_id,
            'member_id'        => $member_id,
            'registered_at'    => $registered_at,
            'source_timestamp' => mb_substr($source_timestamp, 0, 100),
        ];
        if ($existing) {
            $wpdb->update(
                "{$wpdb->prefix}avb_sheet_participation_meta",
                $data,
                ['id' => (int) $existing->id],
                ['%d', '%d', '%s', '%s'],
                ['%d']
            );
            return;
        }
        $wpdb->insert(
            "{$wpdb->prefix}avb_sheet_participation_meta",
            $data,
            ['%d', '%d', '%s', '%s']
        );
    }

    public static function get_sheet_participation_meta(int $activity_id, int $member_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_sheet_participation_meta
             WHERE activity_id = %d AND member_id = %d",
            $activity_id,
            $member_id
        )) ?: null;
    }

    /** Audit a successfully sent request; failed mail attempts are not marked as sent. */
    public static function log_payment_request(int $fee_item_id, int $member_id, int $activity_id, string $sent_to): void {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}avb_payment_requests", [
            'fee_item_id' => $fee_item_id,
            'member_id' => $member_id,
            'activity_id' => $activity_id,
            'sent_to' => $sent_to,
            'sent_by' => get_current_user_id() ?: null,
            'requested_at' => current_time('mysql'),
        ], ['%d', '%d', '%d', '%s', '%d', '%s']);
    }

    public static function get_last_payment_request(int $fee_item_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_payment_requests
             WHERE fee_item_id = %d ORDER BY requested_at DESC, id DESC LIMIT 1",
            $fee_item_id
        )) ?: null;
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
     * get_member_balance() with fee items from closed book years dropped —
     * a closed year is by definition fully settled, so it shouldn't still
     * show up as an open item once closed. Same "toon oudere jaren" escape
     * hatch as the transactions/second-approval admin pages: pass
     * $include_closed to get the unfiltered balance back.
     */
    public static function get_member_balance_excluding_closed(int $member_id, bool $include_closed = false): array {
        $balance = self::get_member_balance($member_id);
        $closed_through_year = (int) get_option('avbk_closed_through_year', 0);
        if ($include_closed || !$closed_through_year) {
            return $balance;
        }

        $items = array_values(array_filter(
            $balance['items'],
            fn($item) => self::fee_item_book_year($item) > $closed_through_year
        ));
        $total_due = 0.0;
        $total_paid = 0.0;
        foreach ($items as $item) {
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
     * get_member_balance_excluding_closed() bucketed into the current
     * book year vs everything else it still returned — in practice a
     * not-yet-closed previous year, since a genuinely closed year is by
     * definition fully settled and never contributes a nonzero remaining
     * here (see fee_item_book_year()/avbk_closed_through_year). Backs
     * both the Ledenoverzicht list's optional per-year columns and each
     * member's detail-page breakdown; 'items' is the same filtered list
     * get_member_balance_excluding_closed() would return, so callers can
     * further split it into "this year only" for the default (non-toggled)
     * view without a second query.
     */
    public static function get_member_balance_by_year(int $member_id, bool $include_closed = false): array {
        $balance = self::get_member_balance_excluding_closed($member_id, $include_closed);
        $current_year = (int) current_time('Y');
        $current = 0.0;
        $other = 0.0;
        foreach ($balance['items'] as $item) {
            if ($item->status === 'waived') {
                continue;
            }
            if (self::fee_item_book_year($item) === $current_year) {
                $current += (float) $item->remaining;
            } else {
                $other += (float) $item->remaining;
            }
        }
        return [
            'items'   => $balance['items'],
            'current' => round($current, 2),
            'other'   => round($other, 2),
            'total'   => round($current + $other, 2),
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
        if ($item->type === 'camp' && $item->activity_id) {
            $participation = AVPVH_DB::get_participation((int) $item->member_id, (int) $item->activity_id);
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

    /**
     * Covered bank-export range plus first/last actual transaction per year.
     * ING filenames contain the requested export period (ISO or Dutch date
     * order); that is the authoritative answer to "processed from when?".
     */
    public static function get_transaction_date_ranges_by_year(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT YEAR(transaction_date) AS book_year,
                    MIN(transaction_date) AS first_date,
                    MAX(transaction_date) AS last_date
             FROM {$wpdb->prefix}avb_transactions
             GROUP BY YEAR(transaction_date)"
        ) ?: [];
        $ranges = [];
        foreach ($rows as $row) {
            $ranges[(int) $row->book_year] = $row;
        }
        $filenames = $wpdb->get_col("SELECT filename FROM {$wpdb->prefix}avb_import_batches") ?: [];
        foreach ($filenames as $filename) {
            $period = self::import_filename_period((string) $filename);
            if (!$period) continue;
            [$from, $until] = $period;
            for ($year = (int) substr($from, 0, 4); $year <= (int) substr($until, 0, 4); $year++) {
                if (!isset($ranges[$year])) {
                    $ranges[$year] = (object) ['book_year' => $year, 'first_date' => null, 'last_date' => null];
                }
                $covered_from = max($from, $year . '-01-01');
                $covered_until = min($until, $year . '-12-31');
                if (empty($ranges[$year]->covered_from) || $covered_from < $ranges[$year]->covered_from) {
                    $ranges[$year]->covered_from = $covered_from;
                }
                if (empty($ranges[$year]->covered_until) || $covered_until > $ranges[$year]->covered_until) {
                    $ranges[$year]->covered_until = $covered_until;
                }
            }
        }
        return $ranges;
    }

    /** Two date tokens at the end of an ING export filename define its requested range. */
    private static function import_filename_period(string $filename): ?array {
        preg_match_all('/(?<!\d)(\d{4}-\d{2}-\d{2}|\d{2}-\d{2}-\d{4})(?!\d)/', $filename, $matches);
        if (count($matches[1] ?? []) < 2) return null;
        $tokens = array_slice($matches[1], -2);
        $dates = [];
        foreach ($tokens as $token) {
            $format = preg_match('/^\d{4}-/', $token) ? '!Y-m-d' : '!d-m-Y';
            $date = \DateTimeImmutable::createFromFormat($format, $token, wp_timezone());
            if (!$date) return null;
            $dates[] = $date->format('Y-m-d');
        }
        sort($dates, SORT_STRING);
        return $dates;
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
    // Reimbursements — a member submits a receipt photo + amount, asking
    // the penningmeester to pay them back (see AVBK_Reimbursements). The
    // club owes the member here, the reverse of every other flow in this
    // plugin — AVBK_QR::for_reimbursement() targets the member's own IBAN.
    // -------------------------------------------------------------------

    /** Creates the parent row only — the caller adds one or more receipts via add_reimbursement_receipt(). */
    public static function create_reimbursement(array $data): int {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}avb_reimbursements", [
            'member_id'   => (int) $data['member_id'],
            'activity_id' => $data['activity_id'] ?: null,
            'description' => (string) $data['description'],
            'amount'      => (float) $data['amount'],
            'ocr_amount'  => $data['ocr_amount'] !== null ? (float) $data['ocr_amount'] : null,
            'iban'        => strtoupper(str_replace(' ', '', (string) $data['iban'])),
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function add_reimbursement_receipt(int $reimbursement_id, array $data): int {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}avb_reimbursement_receipts", [
            'reimbursement_id' => $reimbursement_id,
            'receipt_path'     => (string) $data['receipt_path'],
            'receipt_hash'     => (string) ($data['receipt_hash'] ?? ''),
            'description'      => (string) ($data['description'] ?? ''),
            'ocr_amount'       => $data['ocr_amount'] !== null ? (float) $data['ocr_amount'] : null,
            'ocr_date'         => $data['ocr_date'] ?: null,
            'ocr_store'        => (string) ($data['ocr_store'] ?? ''),
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * @return object[] One entry per attached receipt photo, each with
     * ->id, ->receipt_path, ->receipt_hash, ->description, ->ocr_amount,
     * ->ocr_date, ->ocr_store. Declarations from before multi-receipt
     * support kept their one photo directly on the avb_reimbursements row
     * instead of this child table — synthesized here (id 0, description
     * taken from the parent's own field) so callers never need to special-
     * case old data.
     */
    public static function get_reimbursement_receipts(int $reimbursement_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_reimbursement_receipts WHERE reimbursement_id = %d ORDER BY id ASC",
            $reimbursement_id
        ));
        if ($rows) {
            return $rows;
        }
        $r = self::get_reimbursement($reimbursement_id);
        if ($r && $r->receipt_path) {
            return [(object) [
                'id'               => 0,
                'reimbursement_id' => $reimbursement_id,
                'receipt_path'     => $r->receipt_path,
                'receipt_hash'     => $r->receipt_hash,
                'description'      => $r->description,
                'ocr_amount'       => $r->ocr_amount,
                'ocr_date'         => $r->ocr_date,
                'ocr_store'        => $r->ocr_store,
            ]];
        }
        return [];
    }

    public static function delete_reimbursement_receipt(int $id): void {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}avb_reimbursement_receipts", ['id' => $id]);
    }

    /**
     * Updates one receipt's own free-text description (the member's/
     * penningmeester's editable note — pre-filled at submit time with
     * OCR's store+date guess, see AVBK_Reimbursements). $receipt_id of 0
     * means a legacy pre-multi-receipt declaration (see
     * get_reimbursement_receipts()'s synthesized entry) — its description
     * lives directly on the parent row, so this also refreshes the
     * parent's own joined-summary description (see
     * refresh_reimbursement_description_summary()) for a child-table row.
     */
    public static function update_reimbursement_receipt_description(int $reimbursement_id, int $receipt_id, string $description): void {
        global $wpdb;
        if ($receipt_id > 0) {
            $wpdb->update("{$wpdb->prefix}avb_reimbursement_receipts", ['description' => $description], ['id' => $receipt_id]);
            self::refresh_reimbursement_description_summary($reimbursement_id);
        } else {
            $wpdb->update("{$wpdb->prefix}avb_reimbursements", ['description' => $description], ['id' => $reimbursement_id]);
        }
    }

    /** Keeps the parent row's own description a joined summary of its receipts' descriptions — purely for list views that show one declaration per line (member's "Eerdere declaraties", admin's "Afgehandeld"), never shown once you're looking at the receipts themselves. */
    public static function refresh_reimbursement_description_summary(int $reimbursement_id): void {
        global $wpdb;
        $descriptions = array_filter(wp_list_pluck(self::get_reimbursement_receipts($reimbursement_id), 'description'));
        $wpdb->update(
            "{$wpdb->prefix}avb_reimbursements",
            ['description' => implode('; ', $descriptions)],
            ['id' => $reimbursement_id]
        );
    }

    /**
     * A duplicate is either the exact same photo re-uploaded (receipt_hash
     * match — robust, not user-editable) or a re-photographed copy of the
     * same paper receipt: identical OCR-guessed date and OCR-guessed store
     * for the same member (dropping the amount from this comparison, since
     * with multiple receipts per declaration a receipt's own amount isn't
     * tracked separately from the declaration's total). Scoped to one
     * member — two housemates each legitimately declaring their own copy
     * of a shared purchase is out of scope here.
     *
     * Excludes 'withdrawn' declarations — the declarant pulling one back
     * themselves (e.g. an accidental duplicate) means it was never really
     * declared, so its receipt shouldn't keep blocking a genuine future
     * declaration of that same purchase. A 'rejected' one stays blocking:
     * the penningmeester actually looked at it and said no, so it can be
     * fixed and resubmitted, but not just re-uploaded unchanged.
     */
    public static function find_duplicate_receipt(int $member_id, string $receipt_hash, ?string $ocr_date, ?string $ocr_store): ?object {
        global $wpdb;
        if ($receipt_hash !== '') {
            $by_hash = $wpdb->get_row($wpdb->prepare(
                "SELECT rr.* FROM {$wpdb->prefix}avb_reimbursement_receipts rr
                 JOIN {$wpdb->prefix}avb_reimbursements r ON r.id = rr.reimbursement_id
                 WHERE r.member_id = %d AND rr.receipt_hash = %s AND r.status != 'withdrawn' LIMIT 1",
                $member_id, $receipt_hash
            ));
            if ($by_hash) {
                return $by_hash;
            }
            // Legacy single-receipt declarations never got a child-table
            // row (see get_reimbursement_receipts()) — check the parent's
            // own columns too so those aren't a blind spot.
            $by_hash_legacy = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}avb_reimbursements WHERE member_id = %d AND receipt_hash = %s AND status != 'withdrawn' LIMIT 1",
                $member_id, $receipt_hash
            ));
            if ($by_hash_legacy) {
                return $by_hash_legacy;
            }
        }
        if ($ocr_date === null || $ocr_store === null || $ocr_store === '') {
            return null;
        }
        $by_fields = $wpdb->get_row($wpdb->prepare(
            "SELECT rr.* FROM {$wpdb->prefix}avb_reimbursement_receipts rr
             JOIN {$wpdb->prefix}avb_reimbursements r ON r.id = rr.reimbursement_id
             WHERE r.member_id = %d AND rr.ocr_date = %s AND LOWER(TRIM(rr.ocr_store)) = LOWER(TRIM(%s)) AND r.status != 'withdrawn'
             LIMIT 1",
            $member_id, $ocr_date, $ocr_store
        ));
        if ($by_fields) {
            return $by_fields;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_reimbursements
             WHERE member_id = %d AND ocr_date = %s AND LOWER(TRIM(ocr_store)) = LOWER(TRIM(%s)) AND status != 'withdrawn'
             LIMIT 1",
            $member_id, $ocr_date, $ocr_store
        )) ?: null;
    }

    public static function get_reimbursement(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_reimbursements WHERE id = %d", $id
        )) ?: null;
    }

    public static function get_reimbursements(string $status = 'pending'): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_reimbursements WHERE status = %s ORDER BY created_at ASC",
            $status
        )) ?: [];
    }

    public static function get_reimbursements_for_member(int $member_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_reimbursements WHERE member_id = %d ORDER BY created_at DESC",
            $member_id
        )) ?: [];
    }

    public static function count_pending_reimbursements(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}avb_reimbursements WHERE status = 'pending'"
        );
    }

    public static function mark_reimbursement_paid(int $id, int $paid_by): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}avb_reimbursements",
            ['status' => 'paid', 'paid_at' => current_time('mysql'), 'paid_by' => $paid_by],
            ['id' => $id]
        );
    }

    public static function reject_reimbursement(int $id): void {
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}avb_reimbursements", ['status' => 'rejected'], ['id' => $id]);
    }

    /** Penningmeester corrections before paying out — e.g. the member picked the wrong known IBAN. Never touches status/paid fields. */
    /** Description isn't set here — it's a per-receipt field, see update_reimbursement_receipt_description(); the parent's own description column is just a joined summary refreshed from those. */
    public static function update_reimbursement(int $id, array $data): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}avb_reimbursements",
            [
                'activity_id' => $data['activity_id'] ?: null,
                'amount'      => $data['amount'],
                'iban'        => $data['iban'],
            ],
            ['id' => $id]
        );
    }

    /**
     * The declarant correcting their own submission — a description typo,
     * a wrong IBAN, or (per the ownership+status guard baked into the
     * WHERE clause) fixing an accidental duplicate submission, but only
     * ever while it's still 'pending': once the penningmeester has paid or
     * rejected it, the record should reflect what was actually decided.
     */
    public static function member_update_reimbursement(int $id, int $member_id, array $data): bool {
        global $wpdb;
        $updated = $wpdb->update(
            "{$wpdb->prefix}avb_reimbursements",
            [
                'activity_id' => $data['activity_id'] ?: null,
                'amount'      => $data['amount'],
                'iban'        => $data['iban'],
            ],
            ['id' => $id, 'member_id' => $member_id, 'status' => 'pending']
        );
        return (bool) $updated;
    }

    /** The declarant withdrawing their own still-pending submission — e.g. an accidental duplicate. Same ownership+status guard as member_update_reimbursement(). */
    public static function withdraw_reimbursement(int $id, int $member_id): bool {
        global $wpdb;
        $updated = $wpdb->update(
            "{$wpdb->prefix}avb_reimbursements",
            ['status' => 'withdrawn'],
            ['id' => $id, 'member_id' => $member_id, 'status' => 'pending']
        );
        return (bool) $updated;
    }

    // -------------------------------------------------------------------
    // Transactions
    // -------------------------------------------------------------------

    /**
     * Language-independent transaction identity. ING translates its own
     * labels (Naam/Name, Omschrijving/Description, Valutadatum/Value date)
     * and changes date separators between exports; only the user's actual
     * payment memo plus stable transaction fields should affect dedupe.
     */
    public static function dedupe_hash(
        string $date,
        float $amount,
        string $iban,
        string $description,
        string $counterparty_name = '',
        string $direction = ''
    ): string {
        return sha1(implode('|', [
            strtolower(trim($direction)),
            $date,
            number_format($amount, 2, '.', ''),
            strtoupper(preg_replace('/\s+/', '', $iban)),
            self::normalize_dedupe_text($counterparty_name),
            self::normalize_dedupe_description($description),
        ]));
    }

    private static function normalize_dedupe_description(string $description): string {
        // Prefer the payer's own memo and discard ING's translated wrapper.
        if (preg_match(
            '/(?:Omschrijving|Description):\s*(.*?)(?=\s+(?:IBAN|Datum\/Tijd|Date\/time|Valutadatum|Value date|Kenmerk|Reference|Overige partij|Other party|Mutatiesoort|Transaction type):|$)/iu',
            $description,
            $match
        )) {
            return self::normalize_dedupe_text($match[1]);
        }
        // Some ING-generated rows (notably service charges) have no
        // Omschrijving/Description wrapper at all. Their remaining labels
        // are still translated, e.g. "Valutadatum" versus "Value date".
        // Canonicalize every known label before hashing the whole fallback
        // text, otherwise the same mutation from an NL and EN export gets
        // two identities despite identical invoice/reference data.
        $description = preg_replace([
            '/\b(?:Naam|Name):/iu',
            '/\b(?:Omschrijving|Description):/iu',
            '/\b(?:Datum\/Tijd|Date\/time):/iu',
            '/\b(?:Valutadatum|Value date):/iu',
            '/\b(?:Kenmerk|Reference):/iu',
            '/\b(?:Overige partij|Other party):/iu',
            '/\b(?:Mutatiesoort|Transaction type):/iu',
        ], [
            'name:', 'description:', 'datetime:', 'valuedate:',
            'reference:', 'otherparty:', 'transactiontype:',
        ], $description);
        return self::normalize_dedupe_text($description);
    }

    private static function normalize_dedupe_text(string $value): string {
        $value = mb_strtolower(remove_accents(trim($value)));
        $value = preg_replace('/\b(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})\b/u', '$3$2$1', $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    public static function transaction_exists(string $hash): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}avb_transactions WHERE dedupe_hash = %s", $hash
        ));
    }

    /** Finds a previous row with the same canonical identity, including legacy rows whose stored hash used the untranslated raw description. */
    public static function find_semantic_duplicate(array $transaction): ?object {
        global $wpdb;
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_transactions
             WHERE transaction_date = %s AND amount = %f AND direction = %s AND UPPER(counterparty_iban) = UPPER(%s)
             ORDER BY id ASC",
            $transaction['transaction_date'],
            $transaction['amount'],
            $transaction['direction'],
            $transaction['counterparty_iban']
        ));
        $hash = self::dedupe_hash(
            $transaction['transaction_date'],
            (float) $transaction['amount'],
            $transaction['counterparty_iban'],
            $transaction['description'],
            $transaction['counterparty_name'] ?? '',
            $transaction['direction']
        );
        foreach ($candidates as $candidate) {
            $candidate_hash = self::dedupe_hash(
                $candidate->transaction_date,
                (float) $candidate->amount,
                $candidate->counterparty_iban,
                $candidate->description,
                $candidate->counterparty_name,
                $candidate->direction
            );
            if (hash_equals($hash, $candidate_hash)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Marks only harmless duplicate copies: no allocation and still waiting
     * for review. A linked/matched row is always preferred as survivor;
     * ambiguous matched duplicates are left untouched for human review.
     */
    public static function mark_semantic_transaction_duplicates(): int {
        global $wpdb;
        $transactions = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}avb_transactions ORDER BY id ASC") ?: [];
        $allocated_ids = array_fill_keys(array_map('intval', $wpdb->get_col(
            "SELECT DISTINCT transaction_id FROM {$wpdb->prefix}avb_transaction_allocations"
        )), true);
        $groups = [];
        foreach ($transactions as $transaction) {
            $hash = self::dedupe_hash(
                $transaction->transaction_date,
                (float) $transaction->amount,
                $transaction->counterparty_iban,
                $transaction->description,
                $transaction->counterparty_name,
                $transaction->direction
            );
            $groups[$hash][] = $transaction;
        }

        $marked = 0;
        foreach ($groups as $duplicates) {
            if (count($duplicates) < 2) {
                continue;
            }
            usort($duplicates, static function ($a, $b) use ($allocated_ids): int {
                $a_score = (isset($allocated_ids[(int) $a->id]) ? 2 : 0) + ($a->status === 'matched' ? 1 : 0);
                $b_score = (isset($allocated_ids[(int) $b->id]) ? 2 : 0) + ($b->status === 'matched' ? 1 : 0);
                return $b_score <=> $a_score ?: (int) $a->id <=> (int) $b->id;
            });
            $survivor = $duplicates[0];
            foreach (array_slice($duplicates, 1) as $duplicate) {
                $safe_unreviewed_incoming = in_array($duplicate->status, ['suggested', 'unmatched'], true);
                $safe_imported_outgoing = $duplicate->direction === 'out'
                    && $duplicate->status === 'ignored'
                    && ($duplicate->ignore_reason ?? '') === 'import_outgoing';
                if (($safe_unreviewed_incoming || $safe_imported_outgoing)
                    && !isset($allocated_ids[(int) $duplicate->id])) {
                    $wpdb->update(
                        "{$wpdb->prefix}avb_transactions",
                        ['status' => 'duplicate', 'duplicate_of' => (int) $survivor->id],
                        ['id' => (int) $duplicate->id]
                    );
                    $marked++;
                }
            }
        }
        return $marked;
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
            'duplicate_of'         => $row['duplicate_of'] ?? null,
            'status'               => $row['status'] ?? 'unmatched',
            'ignore_reason'        => $row['ignore_reason'] ?? '',
            'suggested_member_ids' => $row['suggested_member_ids'] ?? '',
            'suggested_type'       => $row['suggested_type'] ?? '',
            'source_row'           => $row['source_row'] ?? null,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function get_transaction(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_transactions WHERE id = %d", $id
        )) ?: null;
    }

    /** Tags an unallocated bank row with an activity; real allocations are immutable here. */
    public static function set_transaction_activity(int $id, int $activity_id): bool {
        global $wpdb;
        $has_allocations = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}avb_transaction_allocations WHERE transaction_id = %d",
            $id
        ));
        if ($has_allocations) {
            return false;
        }
        return $wpdb->update(
            "{$wpdb->prefix}avb_transactions",
            ['activity_id' => $activity_id ?: null],
            ['id' => $id],
            ['%d'],
            ['%d']
        ) !== false;
    }

    public static function update_transaction_status(int $id, string $status): void {
        global $wpdb;
        $data = ['status' => $status];
        if ($status !== 'ignored') {
            $data['ignore_reason'] = '';
        }
        // Whoever's request caused this transaction to become 'matched' —
        // covers both the manual confirm-transaction flow and the
        // automatic exact-IBAN/reference-code match at import time — is
        // the "first pair of eyes" a second person must differ from, see
        // second_approve_transaction().
        if ($status === 'matched') {
            $data['confirmed_by'] = get_current_user_id() ?: null;
        }
        $wpdb->update("{$wpdb->prefix}avb_transactions", $data, ['id' => $id]);
    }

    public static function ignore_transaction(int $id, string $reason): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}avb_transactions",
            ['status' => 'ignored', 'ignore_reason' => sanitize_key($reason)],
            ['id' => $id]
        );
    }

    /** Marks an incoming, unallocated transaction as a duplicate of another bank row. */
    public static function mark_transaction_duplicate(int $id, int $duplicate_of): bool {
        global $wpdb;
        if ($id <= 0 || $duplicate_of <= 0 || $id === $duplicate_of) {
            return false;
        }
        $transaction = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}avb_transactions WHERE id = %d", $id));
        $survivor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}avb_transactions WHERE id = %d", $duplicate_of));
        if (!$transaction || !$survivor || $transaction->direction !== 'in' || !in_array($transaction->status, ['suggested', 'unmatched'], true)) {
            return false;
        }
        if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}avb_transaction_allocations WHERE transaction_id = %d", $id))) {
            return false;
        }
        $updated = $wpdb->update(
            "{$wpdb->prefix}avb_transactions",
            ['status' => 'duplicate', 'duplicate_of' => $duplicate_of],
            ['id' => $id],
            ['%s', '%d'],
            ['%d']
        );
        return $updated !== false;
    }

    /**
     * @return object[] Every 'matched' transaction not yet second-approved,
     * oldest first — includes ones confirmed before this feature existed
     * (confirmed_by NULL) and today's newly-matched ones alike, since the
     * four-eyes check was added retroactively for all of them.
     */
    public static function get_transactions_pending_second_approval(int $min_year = 0): array {
        global $wpdb;
        if ($min_year) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}avb_transactions
                 WHERE status = 'matched' AND second_approved_by IS NULL AND YEAR(transaction_date) >= %d
                 ORDER BY transaction_date ASC, id ASC",
                $min_year
            )) ?: [];
        }
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}avb_transactions
             WHERE status = 'matched' AND second_approved_by IS NULL
             ORDER BY transaction_date ASC, id ASC"
        ) ?: [];
    }

    public static function count_pending_second_approval(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}avb_transactions WHERE status = 'matched' AND second_approved_by IS NULL"
        );
    }

    /**
     * Records a second, independent sign-off. Refuses when the approver is
     * the very same person who confirmed it in the first place — the
     * entire point of a four-eyes check — but only when confirmed_by is
     * actually known; a legacy transaction confirmed before this feature
     * existed has no recorded confirmer to compare against, so anyone can
     * be its second approver.
     */
    public static function second_approve_transaction(int $id, int $user_id): bool {
        global $wpdb;
        $tx = self::get_transaction($id);
        if (!$tx || $tx->status !== 'matched' || $tx->second_approved_by) {
            return false;
        }
        if ($tx->confirmed_by && (int) $tx->confirmed_by === $user_id) {
            return false;
        }
        $wpdb->update(
            "{$wpdb->prefix}avb_transactions",
            ['second_approved_by' => $user_id, 'second_approved_at' => current_time('mysql')],
            ['id' => $id]
        );
        return true;
    }

    public static function update_transaction_suggestion(int $id, string $status, string $suggested_member_ids, string $suggested_type): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}avb_transactions",
            ['status' => $status, 'suggested_member_ids' => $suggested_member_ids, 'suggested_type' => $suggested_type],
            ['id' => $id]
        );
        // Deliberately doesn't touch draft_data — a saved draft is the
        // treasurer's own deliberate in-progress edit, and a recompute of
        // the *automatic* suggestion (which this row won't even use while
        // a draft exists — see get_transaction_draft()) must never
        // silently overwrite it.
    }

    /** Saves the treasurer's in-progress row edits (member/activity/amount) for transaction $id without touching fee_items/allocations/status — see get_transaction_draft(). */
    public static function save_transaction_draft(int $id, array $rows): void {
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}avb_transactions", ['draft_data' => wp_json_encode($rows)], ['id' => $id]);
    }

    /** The treasurer's saved-but-not-yet-confirmed rows for transaction $id, or null if there's no draft — used to rebuild the confirm form instead of the automatic suggestion. */
    public static function get_transaction_draft(int $id): ?array {
        global $wpdb;
        $json = $wpdb->get_var($wpdb->prepare("SELECT draft_data FROM {$wpdb->prefix}avb_transactions WHERE id = %d", $id));
        if (!$json) {
            return null;
        }
        $rows = json_decode($json, true);
        return is_array($rows) ? $rows : null;
    }

    /** Called once a transaction is actually confirmed (or the treasurer explicitly discards the draft) — a confirmed transaction, or one back to showing the automatic suggestion, has no in-progress draft left to keep. */
    public static function clear_transaction_draft(int $id): void {
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}avb_transactions", ['draft_data' => null], ['id' => $id]);
    }

    /** Rows still needing the treasurer's attention — everything else applied itself. */
    public static function get_review_queue(string $order = 'asc'): array {
        global $wpdb;
        $sql_order = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        return $wpdb->get_results(
            "SELECT t.*, b.filename AS import_filename, b.uploaded_at AS import_uploaded_at
             FROM {$wpdb->prefix}avb_transactions t
             LEFT JOIN {$wpdb->prefix}avb_import_batches b ON b.id = t.import_batch_id
             WHERE t.direction = 'in' AND t.status IN ('suggested', 'unmatched')
             ORDER BY t.transaction_date {$sql_order}, t.id {$sql_order}"
        ) ?: [];
    }

    /**
     * @param int[] $member_ids
     * @return array<int,string> member_id => most recent transaction_date they were paid against, keyed only for members with at least one payment.
     */
    public static function get_last_payment_dates(array $member_ids): array {
        global $wpdb;
        if (!$member_ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($member_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.member_id, MAX(t.transaction_date) AS last_payment
             FROM {$wpdb->prefix}avb_transaction_allocations a
             JOIN {$wpdb->prefix}avb_transactions t ON t.id = a.transaction_id
             WHERE a.member_id IN ($placeholders)
             GROUP BY a.member_id",
            $member_ids
        ));
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->member_id] = $row->last_payment;
        }
        return $result;
    }

    public static function get_transactions(array $args = []): array {
        global $wpdb;
        $where = '1=1';
        $params = [];
        if (!empty($args['batch_id'])) {
            $where .= ' AND t.import_batch_id = %d';
            $params[] = (int) $args['batch_id'];
        }
        if (!empty($args['min_year'])) {
            $where .= ' AND YEAR(t.transaction_date) >= %d';
            $params[] = (int) $args['min_year'];
        }
        // $where is only ever the literal '1=1' or '1=1 AND import_batch_id
        // = %d', never raw input — prepare() below runs whenever $params
        // (the actual values) is non-empty. Calling prepare() unconditionally
        // would trip WP's "no placeholders" doing_it_wrong notice on the
        // empty-$args path, so PHPCS can't see this is already safe.
        $sql = "SELECT t.*, b.filename AS import_filename, b.uploaded_at AS import_uploaded_at
                FROM {$wpdb->prefix}avb_transactions t
                LEFT JOIN {$wpdb->prefix}avb_import_batches b ON b.id = t.import_batch_id
                WHERE $where ORDER BY t.transaction_date DESC, t.id DESC";
        if ($params) {
            $sql = $wpdb->prepare($sql, $params); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        return $wpdb->get_results($sql) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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

    /** Rolls back an unsuccessful automatic allocation attempt. */
    public static function clear_transaction_allocations(int $transaction_id): void {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}avb_transaction_allocations", ['transaction_id' => $transaction_id]);
    }

    /**
     * Number of assigned incoming bank payments per year. Duplicate import
     * copies are deliberately excluded: they are bookkeeping safeguards,
     * not payments that should ever be assigned independently.
     *
     * @return array<int,int> year => transaction count, newest year first.
     */
    public static function get_assigned_payment_counts_by_year(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT YEAR(transaction_date) AS payment_year, COUNT(*) AS payment_count
             FROM {$wpdb->prefix}avb_transactions
             WHERE direction = 'in' AND duplicate_of IS NULL
               AND (status = 'matched' OR EXISTS (
                   SELECT 1 FROM {$wpdb->prefix}avb_transaction_allocations a WHERE a.transaction_id = {$wpdb->prefix}avb_transactions.id
               ))
             GROUP BY YEAR(transaction_date)
             ORDER BY payment_year DESC"
        ) ?: [];

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->payment_year] = (int) $row->payment_count;
        }
        return $counts;
    }

    /**
     * Sends every assigned incoming payment in one calendar year back to
     * the normal review queue. Bank rows/import history are preserved; only
     * their allocations and approvals are undone via the same safe path as
     * the per-transaction correction action.
     */
    public static function revert_assigned_payments_for_year(int $year): int {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}avb_transactions
             WHERE direction = 'in' AND duplicate_of IS NULL
               AND (status = 'matched' OR EXISTS (
                   SELECT 1 FROM {$wpdb->prefix}avb_transaction_allocations a WHERE a.transaction_id = {$wpdb->prefix}avb_transactions.id
               ))
               AND YEAR(transaction_date) = %d
             ORDER BY transaction_date ASC, id ASC",
            $year
        ));

        foreach ($ids as $id) {
            self::revert_transaction_to_review((int) $id);
        }
        return count($ids);
    }

    /**
     * Undoes a wrong confirmation spotted during second-controle: removes
     * every allocation this transaction made and puts it back in the
     * review queue (as 'suggested' when usable suggestion fields remain,
     * otherwise 'unmatched') to be redone
     * correctly. An ad-hoc "other" fee item (Drank/Overig/...) created
     * solely for this transaction is deleted outright rather than left
     * behind half-paid and orphaned; a real contribution/camp/event item
     * just has its allocation removed, which reopens it (remaining =
     * amount_due - paid is always computed live from allocations, never
     * stored) — the item itself is never touched.
     */
    public static function revert_transaction_to_review(int $transaction_id): void {
        global $wpdb;
        $transaction = self::get_transaction($transaction_id);
        $review_status = $transaction
            && (trim((string) $transaction->suggested_member_ids) !== '' || trim((string) $transaction->suggested_type) !== '')
                ? 'suggested'
                : 'unmatched';
        foreach (self::get_allocations_for_transaction($transaction_id) as $a) {
            $other_allocations = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}avb_transaction_allocations WHERE fee_item_id = %d AND id != %d",
                $a->fee_item_id, $a->id
            ));
            $wpdb->delete("{$wpdb->prefix}avb_transaction_allocations", ['id' => $a->id]);
            if (!$other_allocations) {
                $fee_item = self::get_fee_item((int) $a->fee_item_id);
                if ($fee_item && $fee_item->type === 'other') {
                    $wpdb->delete("{$wpdb->prefix}avb_fee_items", ['id' => $a->fee_item_id]);
                }
            }
        }
        $wpdb->update(
            "{$wpdb->prefix}avb_transactions",
            [
                'status'              => $review_status,
                'ignore_reason'       => '',
                'confirmed_by'        => null,
                'second_approved_by'  => null,
                'second_approved_at'  => null,
                'draft_data'          => null,
            ],
            ['id' => $transaction_id]
        );
    }

    // -------------------------------------------------------------------
    // Known IBANs — learned the first time a transaction is confirmed for
    // a member, so later imports from the same IBAN auto-match even with
    // a generic description.
    // -------------------------------------------------------------------

    /** Adds this (iban, member) pairing if it's new — never removes any other member already linked to the same IBAN (joint accounts genuinely belong to more than one person). */
    public static function remember_iban(int $member_id, string $iban, string $account_name = ''): void {
        global $wpdb;
        $iban = strtoupper(str_replace(' ', '', $iban));
        if ($iban === '') {
            return;
        }
        // ON DUPLICATE KEY UPDATE only fills in account_name if it was
        // still empty — a payment's counterparty_name is the bank's own
        // "tenaamstelling" and should win over a blank, but never overwrite
        // a name we already have from an earlier, possibly more complete,
        // sighting of the same (iban, member) pair.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}avb_known_ibans (member_id, iban, account_name) VALUES (%d, %s, %s)
             ON DUPLICATE KEY UPDATE account_name = IF(account_name = '', VALUES(account_name), account_name)",
            $member_id, $iban, $account_name
        ));
    }

    /**
     * Every distinct IBAN known system-wide, regardless of member — lets
     * the penningmeester search/pick an account outside the declarant's
     * own household (e.g. paying out to someone else entirely) when
     * correcting a reimbursement, rather than being limited to
     * get_known_ibans_for_member()'s narrower household scope.
     * @return object[] Each with ->iban and ->account_name.
     */
    public static function get_all_known_ibans(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT iban, MAX(account_name) AS account_name FROM {$wpdb->prefix}avb_known_ibans GROUP BY iban ORDER BY account_name, iban"
        );
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

    /**
     * The reverse lookup — every IBAN seen for this member, their literal
     * housemates (same address — a shared account is often registered to
     * just one of them) and housemates' partners, most recently learned
     * first, deduped by IBAN. Used to suggest where to pay a reimbursement
     * (see AVBK_Reimbursements).
     *
     * Deliberately narrower than get_manageable_members(), which is
     * "family OR same address" (edit permissions) and would also pull in
     * grown-up children who've moved out — see
     * class-member-profile-form.php's own housemates/family_elsewhere
     * split for the same distinction, replicated here.
     * @return object[] Each with ->iban and ->account_name.
     */
    public static function get_known_ibans_for_member(int $member_id): array {
        global $wpdb;
        $household_ids = [$member_id];
        foreach (AVPVH_DB::get_manageable_members($member_id) as $hg) {
            if ((int) $hg->id !== $member_id && AVPVH_DB::has_same_address($member_id, (int) $hg->id)) {
                $household_ids[] = (int) $hg->id;
            }
        }
        $today = current_time('Y-m-d');
        foreach ($household_ids as $housemate_id) {
            foreach (AVPVH_DB::get_relationships($housemate_id) as $rel) {
                if ($rel->category !== 'partner') {
                    continue;
                }
                if ($rel->valid_from && $rel->valid_from > $today) {
                    continue;
                }
                if ($rel->valid_until && $rel->valid_until < $today) {
                    continue;
                }
                if (!in_array((int) $rel->other_id, $household_ids, true)) {
                    $household_ids[] = (int) $rel->other_id;
                }
            }
        }

        $placeholders = implode(',', array_fill(0, count($household_ids), '%d'));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT iban, MAX(account_name) AS account_name, MAX(created_at) AS created_at
             FROM {$wpdb->prefix}avb_known_ibans WHERE member_id IN ($placeholders)
             GROUP BY iban ORDER BY created_at DESC",
            $household_ids
        ));
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
    /** $activity_id (optional): the event's own avm_activities.id (e.g. "Congres 2026") — lets an event fee item be traced back to its activity/rate, same as a camp fee item, without changing the dedupe key (still member+type+description, so a description change intentionally starts a fresh item). */
    public static function get_fee_item_for_member_activity(int $member_id, int $activity_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_fee_items WHERE member_id = %d AND activity_id = %d",
            $member_id, $activity_id
        )) ?: null;
    }

    public static function upsert_event_fee_item(int $member_id, string $description, float $amount, int $activity_id = 0): int {
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
            'activity_id' => $activity_id ?: null,
            'description' => $description,
            'amount_due'  => $amount,
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * $data may additionally carry 'extra_attendees' (array of
     * {name,email,allergies,photo_consent,newsletter} for people 2-5 on a
     * sheet-imported registration — JSON-encoded, or omitted entirely for
     * the plugin's own single-person public sign-up form),
     * 'sheet_timestamp' (the Google Form response's own timestamp, for
     * dedup on re-import) and 'status' (defaults to
     * 'pending_confirmation' — a sheet import passes 'confirmed' directly,
     * since there's no e-mail-link confirmation step for those).
     */
    public static function create_congress_registration(array $data): array {
        global $wpdb;
        $token = wp_generate_password(43, false, false);
        $wpdb->insert("{$wpdb->prefix}avb_congress_registrations", [
            'member_id'        => $data['member_id'] ?: null,
            'fee_item_id'      => $data['fee_item_id'] ?: null,
            'first_name'       => $data['first_name'],
            'suffix'           => $data['suffix'],
            'last_name'        => $data['last_name'],
            'email'            => $data['email'],
            'phone'            => $data['phone'],
            'match_type'       => $data['match_type'],
            'needs_review'     => $data['review_note'] !== '' ? 1 : 0,
            'review_note'      => $data['review_note'],
            'confirm_token'    => $token,
            'extra_attendees'  => isset($data['extra_attendees']) ? wp_json_encode($data['extra_attendees']) : null,
            'sheet_timestamp'  => $data['sheet_timestamp'] ?? null,
            'payer_name'       => $data['payer_name'] ?? '',
            'status'           => $data['status'] ?? 'pending_confirmation',
            'confirmed_at'     => ($data['status'] ?? '') === 'confirmed' ? current_time('mysql') : null,
        ]);
        return ['id' => (int) $wpdb->insert_id, 'token' => $token];
    }

    public static function get_congress_registration_by_token(string $token): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_congress_registrations WHERE confirm_token = %s", $token
        )) ?: null;
    }

    public static function get_congress_registration_by_sheet_timestamp(string $timestamp): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_congress_registrations WHERE sheet_timestamp = %s", $timestamp
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

}
