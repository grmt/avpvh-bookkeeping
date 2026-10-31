<?php
defined('ABSPATH') || exit;

/** A private, company-scoped view of the last exported quarterly worksheet. */
class AVBK_Company_Report {
    private static function key(int $company_id, int $year, int $quarter): string {
        AVBK_Companies::period($year, $quarter);
        return 'avbk_company_report_' . $company_id . '_' . $year . '_' . $quarter;
    }

    public static function fingerprint(array $transactions): string {
        $keys = array_map(fn($tx) => [(int) $tx->id, (string) ($tx->dedupe_hash ?? ''), $tx->transaction_date, (string) $tx->amount], $transactions);
        sort($keys);
        return hash('sha256', wp_json_encode($keys));
    }

    private static function cents($value): ?int {
        return is_numeric($value) && is_finite((float) $value) && abs((float) $value) < 1000000000000
            ? (int) round((float) $value * 100) : null;
    }

    private static function bank_key(string $date, $amount, string $direction, string $iban, string $name, string $description): string {
        return hash('sha256', wp_json_encode([$date, number_format((float) $amount, 2, '.', ''), $direction,
            AVBK_Companies::normalize_iban($iban), $name, $description]));
    }

    /** Read cached row values, never the workbook's stale or uncached total cells. */
    public static function build(string $path, object $company, array $transactions, int $year, int $quarter, string $filename, array $card_transactions = []): array {
        [$start, $end] = AVBK_Companies::period($year, $quarter);
        $sheet = AVBK_Company_Workbook::sheet_name($company, $year, $quarter);
        $values = AVBK_Xlsx_Reader::read_named_rows($path, $sheet);
        if (!in_array($values[1][0] ?? '', ['Date', 'Datum'], true) || !in_array($values[1][8] ?? '', ['BTW %', 'Btw %'], true)) {
            throw new \RuntimeException('Het BTW-werkblad heeft geen herkenbare bank- en BTW-kolommen.');
        }
        $totals = ['gross_cents' => 0, 'purchase_gross_cents' => 0, 'card_gross_cents' => 0, 'known_vat_cents' => 0, 'known_net_cents' => 0,
            'confirmed_vat_cents' => 0, 'confirmed_net_cents' => 0, 'pending_rows' => 0, 'unknown_rows' => 0];
        $rows = []; $date = null; $direction = ''; $bank_rows = 0; $bank_keys = []; $card_keys = [];
        foreach ($values as $number => $v) {
            if ($number === 1 || !array_filter($v, fn($value) => (string) $value !== '')) continue;
            $raw_date = (string) ($v[0] ?? '');
            $card = preg_match('/\bCC:(\d{4}-\d{2}-\d{2}):([a-f0-9]{64})\b/', (string) ($v[15] ?? ''), $card_match);
            $bank = $raw_date !== '' && !$card;
            if ($card) {
                $date = AVBK_Bank_Import_Layout::parse_date($card_match[1], 'Y-m-d');
                if ($raw_date !== '' || !$date || $date < $start || $date >= $end || empty($company->card_account)
                    || AVBK_Company_Cards::account($v[2] ?? '') !== $company->card_account) throw new \RuntimeException('Het resultaat bevat creditcardregels van een andere administratie of periode.');
                $direction = in_array(mb_strtolower($v[5] ?? ''), ['debit', 'af'], true) ? 'out' : 'in';
                $card_keys[$card_match[2]] = true;
            } elseif ($bank) {
                $date = AVBK_Bank_Import_Layout::parse_date($raw_date, 'Ymd');
                if (!$date || $date < $start || $date >= $end || AVBK_Companies::normalize_iban($v[2] ?? '') !== $company->iban) {
                    throw new \RuntimeException('Het resultaat bevat een bankregel van een andere administratie of periode.');
                }
                $direction = in_array(mb_strtolower($v[5] ?? ''), ['debit', 'af'], true) ? 'out' : 'in';
                $bank_keys[self::bank_key($date, $v[6] ?? 0, $direction, $v[3] ?? '', $v[1] ?? '', $v[13] ?? '')] = true;
                $bank_rows++;
            } elseif (!$date || preg_match('/^(?:(?:totaal|total)(?:\s|$)|voorlopig:)/iu', trim((string) ($v[1] ?? '')))) {
                continue;
            }
            $gross = self::cents($v[7] ?? null);
            if ($gross === null && ($bank || $card)) {
                $amount = self::cents($v[6] ?? null);
                $gross = $amount === null ? null : ($direction === 'out' ? 1 : -1) * $amount;
            }
            $vat = self::cents($v[9] ?? null); $net = self::cents($v[10] ?? null);
            $settlement = $bank && AVBK_Vat_Patterns::is_settlement($v[1] ?? '', $v[13] ?? '');
            $pending = str_starts_with((string) ($v[15] ?? ''), 'BTW-');
            $financial = $bank || $gross !== null || $vat !== null || $net !== null;
            $unknown = $financial && !$settlement && ($vat === null || $net === null);
            if (!$card) $totals['gross_cents'] += $gross ?? 0;
            if (!$settlement) $totals['purchase_gross_cents'] += $gross ?? 0;
            if ($card) $totals['card_gross_cents'] += $gross ?? 0;
            $totals['known_vat_cents'] += $vat ?? 0;
            $totals['known_net_cents'] += $net ?? 0;
            if (!$pending) {
                $totals['confirmed_vat_cents'] += $vat ?? 0;
                $totals['confirmed_net_cents'] += $net ?? 0;
            }
            if ($pending) $totals['pending_rows']++;
            if ($unknown) $totals['unknown_rows']++;
            $rows[] = ['number' => (int) $number, 'date' => $date, 'detail' => !$bank,
                'name' => (string) ($v[1] ?? ''), 'direction' => $direction, 'gross_cents' => $gross,
                'code' => trim((string) ($v[8] ?? '')), 'vat_cents' => $vat, 'net_cents' => $net,
                'source' => $card ? 'Creditcard' : ($bank ? 'Bank' : 'Detail'),
                'status' => $settlement ? 'settlement' : (!$financial ? 'detail' : ($unknown ? 'unknown' : ($pending ? 'suggested' : 'checked'))),
                'note' => trim(preg_replace('/;?\s*CC:\d{4}-\d{2}-\d{2}:[a-f0-9]{64}\b/', '', (string) ($v[15] ?? '')))];
        }
        if (!$bank_rows) throw new \RuntimeException('Het resultaat bevat geen bankregels uit het gekozen kwartaal.');
        foreach ($transactions as $tx) {
            if ((int) $tx->company_id !== (int) $company->id || !isset($bank_keys[self::bank_key($tx->transaction_date, $tx->amount,
                $tx->direction, $tx->counterparty_iban, $tx->counterparty_name, $tx->description)])) {
                throw new \RuntimeException('Het werkblad bevat niet alle geïmporteerde banktransacties. Werk de werkmap opnieuw bij.');
            }
        }
        foreach ($card_transactions as $tx) {
            if ((int) $tx->company_id !== (int) $company->id || ($tx->entry_type !== 'settlement' && !isset($card_keys[$tx->dedupe_hash]))) {
                throw new \RuntimeException('Het werkblad bevat niet alle geïmporteerde creditcardaankopen. Werk de werkmap opnieuw bij.');
            }
        }
        return ['version' => 1, 'company_id' => (int) $company->id, 'year' => $year, 'quarter' => $quarter,
            'sheet' => $sheet, 'filename' => sanitize_file_name($filename), 'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            'workbook_hash' => hash_file('sha256', $path), 'transactions_hash' => self::fingerprint($transactions),
            'cards_hash' => self::fingerprint($card_transactions), 'card_rows' => count($card_keys),
            'workbook_data' => filesize($path) <= 3 * 1024 * 1024 ? base64_encode(file_get_contents($path)) : null,
            'bank_rows' => $bank_rows, 'totals' => $totals, 'rows' => $rows];
    }

    public static function save(array $report): void {
        $company_id = (int) $report['company_id'];
        AVBK_Companies::company($company_id, 'export');
        $key = self::key($company_id, (int) $report['year'], (int) $report['quarter']);
        if (!update_option($key, $report, false) && get_option($key) !== $report) {
            throw new \RuntimeException('Het werkbladresultaat kon niet voor het portaal worden opgeslagen. Probeer opnieuw.');
        }
    }

    public static function get(int $company_id, int $year, int $quarter): ?array {
        AVBK_Companies::company($company_id);
        $report = get_option(self::key($company_id, $year, $quarter));
        if (!is_array($report) || ($report['version'] ?? null) !== 1 || ($report['company_id'] ?? null) !== $company_id
            || ($report['year'] ?? null) !== $year || ($report['quarter'] ?? null) !== $quarter) return null;
        return $report;
    }
}
