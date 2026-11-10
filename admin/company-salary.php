<?php
defined('ABSPATH') || exit;

/**
 * Renders admin/company-salary.php's markup.
 *
 * @param array<string, mixed> $view $companies, $company, $company_id, $form_start, $notice, $return_to, $year.
 */
function avbk_render_company_salary(array $view): void {
    ['companies' => $companies, 'company' => $company, 'company_id' => $company_id, 'form_start' => $form_start, 'notice' => $notice, 'return_to' => $return_to, 'year' => $year] = $view;
?>
<div class="wrap avbk-company">
    <h1 class="avbk-company-heading">Salaris · <?php echo esc_html($company->name); ?></h1>
    <?php if ($notice) : ?><p role="status" class="avbk-company-notice <?php echo $notice['error'] ? 'avbk-company-error' : ''; ?>"><?php echo esc_html($notice['message']); ?></p><?php endif; ?>
    <form method="get" action="<?php echo esc_url($return_to); ?>" class="avbk-company-picker">
        <?php if (is_admin()) : ?><input type="hidden" name="page" value="avbk-companies-payroll"><?php endif; ?>
        <label>Administratie <select name="company_id"><?php foreach ($companies as $item) : ?><option value="<?php echo esc_attr($item->id); ?>" <?php selected((int) $item->id, $company_id); ?>><?php echo esc_html($item->name); ?></option><?php endforeach; ?></select></label>
        <label>Boekjaar <input type="number" name="book_year" min="2000" max="2100" value="<?php echo esc_attr($year); ?>" required></label>
        <button type="submit" class="button">Tonen</button>
    </form>
    <p><a href="<?php echo esc_url(add_query_arg(['company_id' => $company_id, 'book_year' => $year], home_url('/'))); ?>">Naar bedrijfsboekhouding</a></p>
    <?php avbk_render_company_salary_automation($company_id, $form_start); ?>
    <?php require_once AVBK_PLUGIN_DIR . 'admin/company-payments.php'; avbk_render_company_payments($view); ?>
    <?php require_once AVBK_PLUGIN_DIR . 'admin/company-payroll.php'; avbk_render_company_payroll($view); ?>
</div>
<?php
}

/** The monthly payroll automation card. */
function avbk_render_company_salary_automation($company_id, $form_start): void {
    $automation = AVBK_Company_Payroll_Automation::settings($company_id); if ($automation) : $automation_status = get_option('avbk_payroll_automation_status_' . $company_id, []); ?>
        <section class="avbk-company-card"><h3>Maandelijkse verwerking</h3>
            <p><?php echo !empty($automation['enabled']) ? 'Actief' : 'Gepauzeerd'; ?> · elke <?php echo esc_html($automation['day']); ?>e vanaf <?php echo esc_html(sprintf('%02d:00', $automation['hour']) . ' ' . $automation['timezone']); ?> · e-mail naar <?php echo esc_html($automation['recipient']); ?>.</p>
            <p>Nieuwe salarisberichten worden gecontroleerd en passende documenten verwerkt. De herinnering bevat QR-codes voor betalingen die volgens hun deadline aan de beurt zijn. Gevonden bankbetalingen en salarisbetalingen die nog moeten worden afgestemd krijgen geen QR-code.</p>
            <?php avbk_render_company_salary_automation_status($automation_status); ?>
            <?php if (AVBK_Companies::can($company_id, 'access')) : $form_start('payroll_automation'); ?>
                <input type="hidden" name="automation_enabled" value="<?php echo empty($automation['enabled']) ? '1' : '0'; ?>"><button type="submit" class="button"><?php echo empty($automation['enabled']) ? 'Activeren' : 'Pauzeren'; ?></button>
            </form><?php endif; ?>
        </section>
    <?php endif; ?>
<?php
}

/** Last check and open issues of the payroll automation. */
function avbk_render_company_salary_automation_status($automation_status): void {
    if ($automation_status) : ?><p>Laatste controle: <?php echo esc_html($automation_status['last_check'] ?? 'onbekend'); ?> · mailstatus: <?php echo esc_html($automation_status['mail_status'] ?? 'onbekend'); ?>.</p><?php foreach ($automation_status['issues'] ?? [] as $issue) : ?><p class="avbk-company-notice avbk-company-error"><?php echo esc_html($issue); ?></p><?php endforeach; endif; ?>
<?php
}
