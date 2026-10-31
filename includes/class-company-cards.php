<?php
defined('ABSPATH') || exit;

/** ING Commercial Cards exports stay separate from bank settlement payments. */
class AVBK_Company_Cards {
    public static function account(string $value): string {
        return strtoupper(preg_replace('/\s+/', '', trim($value, " \t\r\n'\"")));
    }

    public static function link(int $company_id, string $account): void {
        global $wpdb;
        $company = AVBK_Companies::company($company_id, 'access');
        $account = self::account($account);
        if (!preg_match('/^[A-Z0-9]{6,34}$/D', $account)) throw new \RuntimeException('Vul het creditcardaccountnummer uit het ING-afschrift in, niet het kaartnummer.');
        if (!empty($company->card_account) && $company->card_account !== $account) throw new \RuntimeException('Deze administratie heeft al een ander creditcardaccount. Laat de koppeling eerst controleren.');
        if ($wpdb->update($wpdb->prefix . 'avb_companies', ['card_account' => $account], ['id' => $company_id]) === false) {
            throw new \RuntimeException('Het creditcardaccount kon niet worden gekoppeld. Een account kan maar bij één administratie horen.');
        }
    }

    public static function transactions(int $company_id, int $year, int $quarter): array {
        global $wpdb;
        AVBK_Companies::company($company_id);
        [$start, $end] = AVBK_Companies::period($year, $quarter);
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}avb_company_card_transactions WHERE company_id = %d AND transaction_date >= %s AND transaction_date < %s ORDER BY transaction_date DESC, id DESC", $company_id, $start, $end));
    }

    public static function prepare(object $company, array $parsed, int $year, int $quarter, bool $statement = false): array {
        if (empty($company->card_account)) throw new \RuntimeException('Laat de directeur eerst het creditcardaccount aan deze administratie koppelen.');
        $required = ['Accountnummer', 'Kaartnummer', 'Transactiedatum', 'Boekingsdatum', 'Omschrijving', 'Valuta', 'Bedrag', 'Bedrag in EUR'];
        if (array_diff($required, $parsed['headers'] ?? [])) throw new \RuntimeException('Gebruik de ING Commercial Cards-transactie-export met Accountnummer en Bedrag in EUR.');
        [$start, $end] = AVBK_Companies::period($year, $quarter);
        $result = []; $outside_account = 0; $outside_period = 0; $occurrences = [];
        foreach ($parsed['rows'] as $row) {
            $line = (int) ($row['__row_number'] ?? 0);
            $account = self::account((string) ($row['Accountnummer'] ?? ''));
            $date = AVBK_Bank_Import_Layout::parse_date((string) ($row['Transactiedatum'] ?? ''), 'auto');
            $booking = AVBK_Bank_Import_Layout::parse_date((string) ($row['Boekingsdatum'] ?? ''), 'auto');
            $unknown_booking = $statement && trim((string) ($row['Boekingsdatum'] ?? '')) === '';
            $eur = trim((string) ($row['Bedrag in EUR'] ?? '')); $original = trim((string) ($row['Bedrag'] ?? ''));
            $currency = strtoupper(trim((string) ($row['Valuta'] ?? '')));
            $card = preg_replace('/[^0-9]/', '', (string) ($row['Kaartnummer'] ?? ''));
            $description = trim((string) ($row['Omschrijving'] ?? ''));
            $number = '/^[+-]?(?:\d+(?:\.\d{1,2})?|(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2})$/D';
            if (!preg_match('/^[A-Z0-9]{6,34}$/D', $account) || !$date || (!$booking && !$unknown_booking) || !preg_match($number, $eur) || !preg_match($number, $original)
                || !preg_match('/^[A-Z]{3}$/D', $currency) || strlen($card) < 4 || $description === '') {
                throw new \RuntimeException("Rij {$line}: ongeldige creditcardtransactie. Controleer account, kaartnummer, datums en bedragen."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- integer row diagnostic escaped by the portal template
            }
            $amount = AVBK_Bank_Import_Layout::parse_amount($eur, 'auto');
            $original_amount = AVBK_Bank_Import_Layout::parse_amount($original, 'auto');
            if ($amount == 0 || abs($amount) >= 1000000000000 || abs($original_amount) >= 1000000000000) throw new \RuntimeException('De creditcardexport bevat een ongeldig bedrag.');
            $identity = mb_strtolower(preg_replace('/\s+/', ' ', trim($description)));
            $base = hash('sha256', wp_json_encode([$account, substr($card, -4), $date, number_format($amount, 2, '.', ''), $currency, number_format($original_amount, 2, '.', ''), $identity]));
            $occurrence = $occurrences[$base] ?? 0; $occurrences[$base] = $occurrence + 1;
            if ($account !== $company->card_account) { $outside_account++; continue; }
            if ($date < $start || $date >= $end) { $outside_period++; continue; }
            $settlement = $amount > 0 && preg_match('/\b(?:betaling|payment|incasso|overboeking|settlement)\b/iu', $description);
            $raw = array_filter($row, fn($key) => !str_starts_with((string) $key, '__'), ARRAY_FILTER_USE_KEY);
            unset($raw['Naam op kaart']);
            $raw['Kaartnummer'] = '**** ' . substr($card, -4);
            $result[] = ['card_account' => $account, 'card_last4' => substr($card, -4), 'transaction_date' => $date,
                'booking_date' => $booking, 'amount' => number_format(abs($amount), 2, '.', ''), 'direction' => $amount < 0 ? 'out' : 'in',
                'entry_type' => $settlement ? 'settlement' : ($amount < 0 ? 'purchase' : 'refund'),
                'currency' => $currency, 'original_amount' => number_format($original_amount, 2, '.', ''), 'description' => $description,
                'raw_data' => wp_json_encode($raw), 'dedupe_hash' => hash('sha256', $base . ':' . $occurrence)];
        }
        if (!$result) throw new \RuntimeException('Geen creditcardtransacties voor het gekoppelde account en gekozen kwartaal. Controleer account en periode.');
        return ['rows' => $result, 'outside_account' => $outside_account, 'outside_period' => $outside_period];
    }

    public static function import(int $company_id, string $path, string $filename, int $year, int $quarter): array {
        $company = AVBK_Companies::company($company_id, 'import');
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) throw new \RuntimeException('Kies een CSV- of XLSX-transactie-export.');
        $parsed = $ext === 'csv' ? AVBK_Csv_Reader::read($path) : AVBK_Xlsx_Reader::read($path);
        return self::store($company_id, self::prepare($company, $parsed, $year, $quarter), $filename);
    }

    public static function import_statement(int $company_id, array $parsed, string $filename, int $year, int $quarter): array {
        if (!defined('WP_CLI') || !WP_CLI) throw new \RuntimeException('PDF-transacties kunnen alleen via de afschriftverwerking worden ingevoerd.');
        $company = AVBK_Companies::company($company_id, 'import');
        $reference = ltrim((string) ($parsed['customer_reference'] ?? ''), '0');
        if (!str_starts_with($company->iban, 'NL') || $reference === '' || ltrim(substr($company->iban, -10), '0') !== $reference
            || self::account((string) ($parsed['card_account'] ?? '')) !== ($company->card_account ?? '')) {
            throw new \RuntimeException('De klantreferentie en het creditcardaccount op het afschrift passen niet bij deze administratie.');
        }
        $sum = 0;
        foreach ($parsed['rows'] ?? [] as $row) $sum -= (int) round(AVBK_Bank_Import_Layout::parse_amount((string) ($row['Bedrag in EUR'] ?? ''), 'auto') * 100);
        if (!is_numeric($parsed['statement_total'] ?? null) || $sum !== (int) round((float) $parsed['statement_total'] * 100)) throw new \RuntimeException('De aankoopregels sluiten niet aan op het afschrifttotaal.');
        return self::store($company_id, self::prepare($company, $parsed, $year, $quarter, true), $filename);
    }

    private static function store(int $company_id, array $prepared, string $filename): array {
        global $wpdb;
        $added = 0; $duplicates = 0;
        if ($wpdb->query('START TRANSACTION') === false) throw new \RuntimeException('Kan creditcardimport niet starten.');
        try {
            foreach ($prepared['rows'] as $row) {
                if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}avb_company_card_transactions WHERE company_id = %d AND dedupe_hash = %s", $company_id, $row['dedupe_hash']))) { $duplicates++; continue; }
                if (!$wpdb->insert($wpdb->prefix . 'avb_company_card_transactions', $row + ['company_id' => $company_id,
                    'source_filename' => sanitize_file_name($filename), 'imported_by' => get_current_user_id()])) throw new \RuntimeException('Creditcardimport niet opgeslagen.');
                $added++;
            }
            if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Creditcardimport niet opgeslagen.');
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
        return ['added' => $added, 'duplicates' => $duplicates, 'outside_account' => $prepared['outside_account'], 'outside_period' => $prepared['outside_period']];
    }
}
