<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-exceptions.php';

/** Company-specific workflow settings; source records remain available when a workflow is disabled. */
class AVBK_Company_Features {
    public static function get(int $company_id): array {
        AVBK_Companies::company($company_id);
        $stored = get_option('avbk_company_features_' . $company_id, []);
        return ['creditcard' => (bool) ($stored['creditcard'] ?? true),
            'payroll' => (bool) ($stored['payroll'] ?? get_option('avbk_company_payroll_enabled_' . $company_id, false)),
            'loans' => (bool) ($stored['loans'] ?? false),
            'ledger' => (bool) ($stored['ledger'] ?? false)];
    }

    public static function enabled(int $company_id, string $feature): bool {
        return self::get($company_id)[$feature] ?? false;
    }

    public static function save(int $company_id, array $features): void {
        AVBK_Companies::company($company_id, 'access');
        if (count($features) !== 4 || array_diff(array_keys($features), ['creditcard', 'payroll', 'loans', 'ledger']) || count(array_filter($features, 'is_bool')) !== 4) {
            throw new AVBK_Company_Exception('Ongeldige bedrijfsinstellingen.');
        }
        $key = 'avbk_company_features_' . $company_id;
        if (!update_option($key, $features, false) && get_option($key) !== $features) {
            throw new AVBK_Company_Exception('De bedrijfsinstellingen konden niet worden opgeslagen.');
        }
    }
}
