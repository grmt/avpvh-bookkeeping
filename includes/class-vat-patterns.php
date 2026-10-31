<?php
defined('ABSPATH') || exit;

/** Explainable VAT suggestions from the same company's earlier worksheet entries. */
class AVBK_Vat_Patterns {
    /** Recognize billing references while keeping different services distinct. */
    public static function card_merchant(string $name): string {
        $name = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($name)));
        $services = [
            'google workspace' => '/^google\s+(?:workspace|gsuite|g\s+suite)\b/u',
            'google cloud' => '/^google\s+cloud\b/u',
            'google one' => '/^google\s+(?:google\s+)?one\b/u',
            'microsoft' => '/^microsoft(?:\s+g[0-9]+(?:\s|$)|$)/u',
            'chatgpt' => '/^(?:openai\s+)?chatgpt\s+(?:subscr|subscription)\b/u',
            'claude' => '/^(?:anthropic\s+)?claude(?:\s+ai)?\s+(?:sub|subscription)\b/u',
        ];
        foreach ($services as $service => $pattern) if (preg_match($pattern, $name)) return $service;
        return $name;
    }

    /** Store user-supplied historical evidence privately in the selected company. */
    public static function record_card_history(int $company_id, array $entries): void {
        AVBK_Companies::company($company_id, 'access');
        $key = 'avbk_company_card_vat_history_' . $company_id;
        $stored = get_option($key, []);
        if (!is_array($stored)) $stored = [];
        foreach ($entries as $entry) {
            $merchant = self::card_merchant(trim((string) ($entry['merchant'] ?? '')));
            $code = (string) ($entry['code'] ?? '');
            $date = AVBK_Bank_Import_Layout::parse_date((string) ($entry['date'] ?? ''), 'Y-m-d');
            $source = trim((string) ($entry['source'] ?? ''));
            $direction = (string) ($entry['direction'] ?? 'out');
            if ($merchant === '' || mb_strlen($merchant) > 255 || !in_array($code, ['0', '9', '21'], true) || !$date || $source === '' || mb_strlen($source) > 1000 || !in_array($direction, ['out', 'in'], true)) {
                throw new \RuntimeException('Vul een leverancier, historisch BTW-percentage, datum en controleerbare bron in.');
            }
            $record = ['merchant' => $merchant, 'code' => $code, 'date' => $date, 'source' => $source, 'direction' => $direction];
            $identity = hash('sha256', wp_json_encode($record));
            $stored[$identity] = $record + ['recorded_by' => get_current_user_id()];
        }
        if (!update_option($key, $stored, false) && get_option($key) !== $stored) throw new \RuntimeException('De historische BTW-patronen konden niet worden opgeslagen.');
    }
    public static function is_settlement(string $name, string $description): bool {
        return (bool) preg_match('/ING.*(?:Commercial\s*Cards|Business\s*Card)|creditcard.*(?:afrekening|settlement)/iu', $name . ' ' . $description);
    }
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
                $card = preg_match('/\bCC:(\d{4}-\d{2}-\d{2}):[a-f0-9]{64}\b/', (string) ($row[15] ?? ''), $card_match);
                $date = AVBK_Bank_Import_Layout::parse_date($card ? $card_match[1] : (string) ($row[0] ?? ''), 'auto');
                $own_account = $card ? (!empty($company->card_account) && AVBK_Company_Cards::account($row[2] ?? '') === $company->card_account) : AVBK_Companies::normalize_iban($row[2] ?? '') === $company->iban;
                if (!$date || $date >= $cutoff || !$own_account) continue;
                $code = trim((string) ($row[8] ?? ''));
                if (!in_array($code, ['0', '9', '21', 'x'], true) || str_starts_with($row[15] ?? '', 'BTW-')) continue;
                $direction = in_array(mb_strtolower($row[5] ?? ''), ['debit', 'af'], true) ? 'out' : 'in';
                $key = $card ? 'card:' . $direction . ':' . self::card_merchant($row[1] ?? '') : self::supplier($row[3] ?? '', $row[1] ?? '', $direction);
                $patterns[$key][] = ['code' => $code, 'vat' => is_numeric($row[9] ?? null) ? abs((float) $row[9]) : null, 'source' => trim($sheet) . '!I' . $number, 'date' => $date];
            }
        }
        if (AVBK_Companies::can((int) $company->id, 'view')) {
            $recorded = get_option('avbk_company_card_vat_history_' . (int) $company->id, []);
            foreach (is_array($recorded) ? $recorded : [] as $entry) {
                if (($entry['date'] ?? '') >= $cutoff) continue;
                $key = 'card:' . $entry['direction'] . ':' . $entry['merchant'];
                $patterns[$key][] = ['code' => $entry['code'], 'vat' => null, 'source' => $entry['source'], 'date' => $entry['date']];
            }
        }
        return $patterns;
    }

    public static function suggest(array $history, object $transaction): array {
        // A card settlement pays a liability; its total cannot supply the purchases' VAT rate.
        if (self::is_settlement($transaction->counterparty_name, $transaction->description)) {
            return ['status' => 'settlement', 'code' => null, 'source' => 'Creditcardafrekening: gebruik de afzonderlijke aankopen en facturen.'];
        }
        $entries = $history[self::supplier($transaction->counterparty_iban, $transaction->counterparty_name, $transaction->direction)] ?? [];
        return self::resolve($entries);
    }

    public static function suggest_card(array $history, object $transaction): array {
        $entries = $history['card:' . $transaction->direction . ':' . self::card_merchant($transaction->counterparty_name)] ?? [];
        $entries = array_merge($entries, $history[self::supplier('', $transaction->counterparty_name, $transaction->direction)] ?? []);
        return self::resolve($entries);
    }

    private static function resolve(array $entries): array {
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
