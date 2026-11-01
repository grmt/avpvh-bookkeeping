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
}
