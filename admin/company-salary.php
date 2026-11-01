<?php
defined('ABSPATH') || exit;
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
    <?php require AVBK_PLUGIN_DIR . 'admin/company-payments.php'; ?>
    <?php require AVBK_PLUGIN_DIR . 'admin/company-payroll.php'; ?>
</div>
