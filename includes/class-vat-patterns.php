<?php
defined('ABSPATH') || exit;

/** Explainable VAT suggestions from the same company's earlier worksheet entries. */
class AVBK_Vat_Patterns {
    private static function supplier(string $iban, string $name, string $direction): string {
        $identity = $iban !== '' ? AVBK_Companies::normalize_iban($iban) : mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
        return $direction . ':' . $identity;
    }

    public static function history(string $path, object $company, int $year, int $quarter): array {
        [$cutoff] = AVBK_Companies::period($year, $quarter);
        $patterns = [];
        foreach (AVBK_Xlsx_Reader::sheet_names($path) as $sheet) {
            if (!preg_match('/^' . preg_quote($company->sheet_prefix, '/') . ' Q[1-4]\s*$/iuD', $sheet)) continue;
            foreach (AVBK_Xlsx_Reader::read_named_rows($path, $sheet) as $number => $row) {
                $date = AVBK_Bank_Import_Layout::parse_date((string) ($row[0] ?? ''), 'Ymd');
                if (!$date || $date >= $cutoff || AVBK_Companies::normalize_iban($row[2] ?? '') !== $company->iban) continue;
                $code = trim((string) ($row[8] ?? ''));
                if (!in_array($code, ['0', '9', '21', 'x'], true) || str_starts_with($row[15] ?? '', 'BTW-')) continue;
                $direction = in_array(mb_strtolower($row[5] ?? ''), ['debit', 'af'], true) ? 'out' : 'in';
                $key = self::supplier($row[3] ?? '', $row[1] ?? '', $direction);
                $patterns[$key][] = ['code' => $code, 'vat' => is_numeric($row[9] ?? null) ? abs((float) $row[9]) : null, 'source' => trim($sheet) . '!I' . $number, 'date' => $date];
            }
        }
        return $patterns;
    }

    public static function suggest(array $history, object $transaction): array {
        // A card settlement pays a liability; its total cannot supply the purchases' VAT rate.
        if (preg_match('/ING.*(?:Commercial\s*Cards|Business\s*Card)|creditcard.*(?:afrekening|settlement)/iu', $transaction->counterparty_name . ' ' . $transaction->description)) {
            return ['status' => 'settlement', 'code' => null, 'source' => 'Creditcardafrekening: gebruik de afzonderlijke aankopen en facturen.'];
        }
        $entries = $history[self::supplier($transaction->counterparty_iban, $transaction->counterparty_name, $transaction->direction)] ?? [];
        if (!$entries) return ['status' => 'unknown', 'code' => null, 'source' => 'Geen eerder BTW-patroon gevonden.'];
        $codes = array_values(array_unique(array_column($entries, 'code')));
        if (count($codes) !== 1) return ['status' => 'conflict', 'code' => null, 'source' => 'Eerdere boekingen hebben verschillende BTW-behandelingen.'];
        usort($entries, fn($a, $b) => strcmp($b['date'], $a['date']));
        $code = $codes[0]; $fixed = null;
        if ($code === 'x') {
            $amounts = array_unique(array_map(fn($e) => $e['vat'] === null ? '' : number_format($e['vat'], 2, '.', ''), $entries));
            if (count($amounts) !== 1 || $amounts[0] === '') return ['status' => 'conflict', 'code' => null, 'source' => 'Het eerdere vaste BTW-bedrag is niet consistent.'];
            $fixed = (float) $amounts[0];
        }
        return ['status' => 'suggested', 'code' => $code, 'fixed_vat' => $fixed, 'source' => $entries[0]['source'], 'observations' => count($entries)];
    }
}
