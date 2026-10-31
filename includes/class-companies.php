<?php
defined('ABSPATH') || exit;

/** Company administrations have their own records and company-scoped access. */
class AVBK_Companies {
    public const ROLES = ['ceo' => 'Directeur / CEO', 'bookkeeper' => 'Boekhouder', 'accountant' => 'Accountant (lezen en downloaden)'];
    private const PERMISSIONS = [
        'ceo' => ['view', 'import', 'export', 'access'],
        'bookkeeper' => ['view', 'import', 'export'],
        'accountant' => ['view', 'export'],
    ];

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}avb_companies (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            iban VARCHAR(34) NOT NULL,
            sheet_prefix VARCHAR(25) NOT NULL,
            card_account VARCHAR(64) NULL DEFAULT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY iban (iban),
            UNIQUE KEY sheet_prefix (sheet_prefix),
            UNIQUE KEY card_account (card_account)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}avb_company_users (
            company_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL,
            PRIMARY KEY  (company_id,user_id),
            KEY user_id (user_id)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}avb_company_transactions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            transaction_date DATE NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            direction VARCHAR(3) NOT NULL,
            counterparty_name VARCHAR(255) NOT NULL DEFAULT '',
            counterparty_iban VARCHAR(34) NOT NULL DEFAULT '',
            description TEXT NOT NULL,
            raw_data LONGTEXT NOT NULL,
            dedupe_hash CHAR(64) NOT NULL,
            source_filename VARCHAR(255) NOT NULL,
            imported_by BIGINT UNSIGNED NOT NULL,
            imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY company_hash (company_id,dedupe_hash),
            KEY company_date (company_id,transaction_date)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}avb_company_documents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            transaction_id BIGINT UNSIGNED NULL,
            source_reference TEXT NOT NULL,
            filename VARCHAR(255) NOT NULL DEFAULT '',
            mime_type VARCHAR(50) NOT NULL DEFAULT '',
            document_data LONGTEXT NULL,
            content_hash CHAR(64) NOT NULL,
            added_by BIGINT UNSIGNED NOT NULL,
            added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY company_document (company_id,content_hash),
            KEY company_transaction (company_id,transaction_id)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}avb_company_card_transactions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            card_account VARCHAR(64) NOT NULL,
            card_last4 VARCHAR(4) NOT NULL,
            transaction_date DATE NOT NULL,
            booking_date DATE NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            direction VARCHAR(3) NOT NULL,
            entry_type VARCHAR(20) NOT NULL,
            currency VARCHAR(3) NOT NULL,
            original_amount DECIMAL(14,2) NOT NULL,
            description TEXT NOT NULL,
            raw_data LONGTEXT NOT NULL,
            dedupe_hash CHAR(64) NOT NULL,
            source_filename VARCHAR(255) NOT NULL,
            imported_by BIGINT UNSIGNED NOT NULL,
            imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY company_hash (company_id,dedupe_hash),
            KEY company_date (company_id,transaction_date)
        ) ENGINE=InnoDB $charset;");
        update_option('avbk_company_schema', '2', false);
    }

    public static function normalize_iban(string $iban): string {
        return strtoupper(preg_replace('/\s+/', '', $iban));
    }

    public static function valid_iban(string $iban): bool {
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $iban)) return false;
        $remainder = 0;
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $char) {
            foreach (str_split(ctype_alpha($char) ? (string) (ord($char) - 55) : $char) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }
        return $remainder === 1;
    }

    public static function role_allows(string $role, string $permission): bool {
        return in_array($permission, self::PERMISSIONS[$role] ?? [], true);
    }

    public static function can(int $company_id, string $permission): bool {
        global $wpdb;
        if ($company_id < 1 || get_current_user_id() < 1 || !in_array($permission, ['view', 'import', 'export', 'access'], true)) return false;
        if (current_user_can('manage_options')) return true;
        $role = $wpdb->get_var($wpdb->prepare(
            "SELECT role FROM {$wpdb->prefix}avb_company_users WHERE company_id = %d AND user_id = %d",
            $company_id, get_current_user_id()
        ));
        return self::role_allows((string) $role, $permission);
    }

    public static function company(int $id, string $permission = 'view'): object {
        global $wpdb;
        if (!self::can($id, $permission)) throw new \RuntimeException('Geen toegang tot deze administratie.');
        $company = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}avb_companies WHERE id = %d", $id));
        if (!$company) throw new \RuntimeException('Administratie niet gevonden.');
        return $company;
    }

    public static function accessible(): array {
        global $wpdb;
        if (get_current_user_id() < 1) return [];
        if (current_user_can('manage_options')) {
            return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}avb_companies ORDER BY name");
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.* FROM {$wpdb->prefix}avb_companies c INNER JOIN {$wpdb->prefix}avb_company_users u ON u.company_id = c.id WHERE u.user_id = %d AND u.role IN ('ceo','bookkeeper','accountant') ORDER BY c.name",
            get_current_user_id()
        ));
    }

    public static function create(string $name, string $iban, string $prefix): int {
        global $wpdb;
        if (!current_user_can('manage_options')) throw new \RuntimeException('Alleen de sitebeheerder kan administraties toevoegen.');
        $iban = self::normalize_iban($iban);
        $name = trim($name); $prefix = trim($prefix);
        if ($name === '' || mb_strlen($name) > 190 || !self::valid_iban($iban) || $prefix === '' || mb_strlen($prefix) > 25 || preg_match('/[\\\\\/?*\[\]:\x00-\x1f]/u', $prefix) || str_contains($prefix, "'")) {
            throw new \RuntimeException('Vul een bedrijfsnaam, geldig IBAN en werkbladprefix van maximaal 25 tekens in.');
        }
        if (!$wpdb->insert($wpdb->prefix . 'avb_companies', ['name' => $name, 'iban' => $iban, 'sheet_prefix' => $prefix, 'created_by' => get_current_user_id()])) {
            throw new \RuntimeException('Administratie niet opgeslagen. IBAN en werkbladprefix moeten uniek zijn.');
        }
        return (int) $wpdb->insert_id;
    }

    /** Assign existing WordPress accounts; company roles grant no site administration. */
    public static function set_access(int $company_id, int $user_id, string $role): void {
        global $wpdb;
        self::company($company_id, 'access');
        if (!get_userdata($user_id) || !isset(self::ROLES[$role]) && $role !== 'remove') throw new \RuntimeException('Ongeldige gebruiker of rol.');
        if ($role === 'remove') {
            $result = $wpdb->delete($wpdb->prefix . 'avb_company_users', ['company_id' => $company_id, 'user_id' => $user_id]);
        } else {
            $result = $wpdb->replace($wpdb->prefix . 'avb_company_users', ['company_id' => $company_id, 'user_id' => $user_id, 'role' => $role]);
        }
        if ($result === false) throw new \RuntimeException('Toegang niet opgeslagen.');
    }

    public static function members(int $company_id): array {
        global $wpdb;
        self::company($company_id, 'access');
        return $wpdb->get_results($wpdb->prepare("SELECT user_id, role FROM {$wpdb->prefix}avb_company_users WHERE company_id = %d ORDER BY user_id", $company_id));
    }

    public static function period(int $year, int $quarter): array {
        if ($year < 2000 || $year > 2100 || $quarter < 1 || $quarter > 4) throw new \RuntimeException('Ongeldig boekjaar of kwartaal.');
        $start = sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1);
        return [$start, (new \DateTimeImmutable($start))->modify('+3 months')->format('Y-m-d')];
    }

    public static function transactions(int $company_id, int $year, int $quarter): array {
        global $wpdb;
        self::company($company_id);
        [$start, $end] = self::period($year, $quarter);
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_company_transactions WHERE company_id = %d AND transaction_date >= %s AND transaction_date < %s ORDER BY transaction_date DESC, id DESC",
            $company_id, $start, $end
        ));
    }

    /** Validate the entire export, then select rows for the requested quarter. */
    public static function prepare_import(object $company, array $parsed, int $year, int $quarter): array {
        [$start, $end] = self::period($year, $quarter);
        $result = [];
        $layout = AVBK_Bank_Import_Layout::resolve(['preset' => 'auto']);
        foreach ($parsed['rows'] as $row) {
            $line = (int) ($row['__row_number'] ?? 0);
            $own_iban = self::normalize_iban((string) ($row['Rekening'] ?? $row['Account'] ?? ''));
            $date = AVBK_Bank_Import_Layout::parse_date(AVBK_Bank_Import_Layout::value($row, $layout, 'date'), 'auto');
            $raw_amount = AVBK_Bank_Import_Layout::value($row, $layout, 'amount');
            $direction = mb_strtolower(AVBK_Bank_Import_Layout::value($row, $layout, 'direction'));
            if ($own_iban !== $company->iban || !$date || !preg_match('/^[+-]?(?:\d+(?:\.\d{1,2})?|(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2})$/D', $raw_amount) || !in_array($direction, ['bij', 'af', 'credit', 'debit'], true)) {
                throw new \RuntimeException("Rij {$line}: controleer rekening, datum, bedrag en af/bij. Alle rijen moeten bij deze administratie horen en geldige transactiegegevens bevatten."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text exception with an integer row number; escaped with esc_html() by admin/companies.php when displayed
            }
            $tx = AVBK_Matcher::parse_row($row, $layout);
            if (!$tx || $tx['amount'] <= 0 || $tx['amount'] >= 1000000000000) {
                throw new \RuntimeException("Rij {$line}: ongeldig bedrag."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text exception with an integer row number; escaped with esc_html() by admin/companies.php when displayed
            }
            // Longer exports are accepted, but even excluded rows must pass validation.
            if ($date < $start || $date >= $end) continue;
            $hash = hash('sha256', wp_json_encode([$date, number_format($tx['amount'], 2, '.', ''), $tx['direction'], $tx['counterparty_iban'], $tx['counterparty_name'], $tx['description']]));
            $raw = array_filter($row, fn($key) => !str_starts_with((string) $key, '__'), ARRAY_FILTER_USE_KEY);
            $result[] = [
                'transaction_date' => $date, 'amount' => number_format($tx['amount'], 2, '.', ''),
                'direction' => $tx['direction'], 'counterparty_name' => $tx['counterparty_name'],
                'counterparty_iban' => $tx['counterparty_iban'], 'description' => $tx['description'],
                'raw_data' => wp_json_encode($raw), 'dedupe_hash' => $hash,
            ];
        }
        if (!$parsed['rows']) throw new \RuntimeException('Geen transacties gevonden. Upload een ING-bankexport met kopregel.');
        if (!$result) throw new \RuntimeException('Geen transacties gevonden voor het gekozen kwartaal. Controleer de gekozen periode en het bankexportbestand.');
        return $result;
    }

    public static function import(int $company_id, string $path, string $filename, int $year, int $quarter): array {
        global $wpdb;
        $company = self::company($company_id, 'import');
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) throw new \RuntimeException('Kies een csv- of xlsx-bankexport.');
        $parsed = $ext === 'csv' ? AVBK_Csv_Reader::read($path) : AVBK_Xlsx_Reader::read($path);
        $rows = self::prepare_import($company, $parsed, $year, $quarter);
        $added = 0; $duplicates = 0;
        if ($wpdb->query('START TRANSACTION') === false) throw new \RuntimeException('Kan import niet starten.');
        try {
            foreach ($rows as $row) {
                // A unique company/hash index also protects concurrent overlapping uploads.
                $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}avb_company_transactions WHERE company_id = %d AND dedupe_hash = %s", $company_id, $row['dedupe_hash']));
                if ($existing) { $duplicates++; continue; }
                $ok = $wpdb->insert($wpdb->prefix . 'avb_company_transactions', $row + ['company_id' => $company_id, 'source_filename' => sanitize_file_name($filename), 'imported_by' => get_current_user_id()]);
                if (!$ok) throw new \RuntimeException('Import niet opgeslagen. Probeer het opnieuw; er zijn geen gedeeltelijke wijzigingen bewaard.');
                $added++;
            }
            if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Import niet opgeslagen.');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        return ['added' => $added, 'duplicates' => $duplicates, 'outside_period' => count($parsed['rows']) - count($rows)];
    }
}
