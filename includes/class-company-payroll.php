<?php
defined('ABSPATH') || exit;

/** Company-scoped payroll evidence, separate from invoice and VAT calculations. */
class AVBK_Company_Payroll {
    public const KINDS = ['payslip' => 'Loonstrookje', 'payroll_tax' => 'Belastingopgave loonheffingen'];

    public static function enabled(int $company_id): bool {
        AVBK_Companies::company($company_id);
        return (bool) get_option('avbk_company_payroll_enabled_' . $company_id, false);
    }

    public static function configure(int $company_id, bool $enabled): void {
        AVBK_Companies::company($company_id, 'access');
        $key = 'avbk_company_payroll_enabled_' . $company_id;
        if (!update_option($key, $enabled, false) && (bool) get_option($key, false) !== $enabled) throw new \RuntimeException('De instelling voor loonadministratie kon niet worden opgeslagen.');
    }

    public static function period(string $value): string {
        if (!preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/D', $value)) throw new \RuntimeException('Kies de maand waarop het loonstrookje of de belastingopgave betrekking heeft.');
        return $value . '-01';
    }

    public static function documents(int $company_id, int $year): array {
        global $wpdb;
        AVBK_Companies::company($company_id);
        AVBK_Companies::period($year, 1);
        if (!self::enabled($company_id)) return [];
        return $wpdb->get_results($wpdb->prepare("SELECT id, source_reference, filename, document_kind, document_period, added_at FROM {$wpdb->prefix}avb_company_documents WHERE company_id = %d AND document_kind IN ('payslip','payroll_tax') AND document_period >= %s AND document_period < %s ORDER BY document_period DESC, document_kind, id DESC LIMIT 500", $company_id, $year . '-01-01', ($year + 1) . '-01-01'));
    }

    public static function save_payments(int $company_id, string $period, array $entries): void {
        AVBK_Companies::company($company_id, 'import');
        $date = self::period($period);
        if (!self::enabled($company_id) || !$entries || count($entries) > 20) throw new \RuntimeException('Ongeldige loonbetalingen.');
        foreach ($entries as &$entry) {
            $doc = AVBK_Company_Documents::document($company_id, (int) ($entry['document_id'] ?? 0));
            $kind = $entry['kind'] ?? '';
            $iban = AVBK_Companies::normalize_iban((string) ($entry['iban'] ?? ''));
            $name = trim((string) ($entry['name'] ?? '')); $reference = trim((string) ($entry['reference'] ?? ''));
            if (!isset(self::KINDS[$kind]) || $doc->document_kind !== $kind || $doc->document_period !== $date
                || !AVBK_Companies::valid_iban($iban) || $name === '' || mb_strlen($name) > 70 || $reference === '' || mb_strlen($reference) > 140
                || preg_match('/[\x00-\x1f\x7f]/', $name . $reference) || !is_int($entry['amount_cents'] ?? null) || $entry['amount_cents'] <= 0 || $entry['amount_cents'] > 99999999999
                || !in_array($entry['state'] ?? '', ['pending', 'review'], true) || mb_strlen($entry['note'] ?? '') > 2000) throw new \RuntimeException('Controleer bron, ontvanger, IBAN, bedrag en betalingskenmerk.');
            if ($kind === 'payroll_tax' && !preg_match('/^\d{16}$/D', $reference)) throw new \RuntimeException('Gebruik het volledige 16-cijferige betalingskenmerk van de belastingopgave.');
            if (!empty($entry['due_date']) && !AVBK_Bank_Import_Layout::parse_date($entry['due_date'], 'Y-m-d')) throw new \RuntimeException('Ongeldige uiterste betaaldatum.');
            $entry['iban'] = $iban; $entry['name'] = $name; $entry['reference'] = $reference;
            $entry['prepared_by'] = get_current_user_id(); $entry['prepared_at'] = gmdate('Y-m-d H:i:s') . ' UTC';
        }
        unset($entry);
        $key = 'avbk_company_payroll_payments_' . $company_id . '_' . substr($period, 0, 4);
        $payments = get_option($key, []); $payments[$period] = $entries;
        if (!update_option($key, $payments, false) && get_option($key) !== $payments) throw new \RuntimeException('De betaalgegevens konden niet worden opgeslagen.');
    }

    public static function payments(int $company_id, int $year): array {
        AVBK_Companies::company($company_id);
        AVBK_Companies::period($year, 1);
        return self::enabled($company_id) ? get_option('avbk_company_payroll_payments_' . $company_id . '_' . $year, []) : [];
    }

    public static function payload(array $payment): ?string {
        if ($payment['state'] !== 'pending') return null;
        return AVBK_QR::epc_payload_to($payment['iban'], $payment['name'], $payment['amount_cents'] / 100, $payment['reference']);
    }
}
