<?php
defined('ABSPATH') || exit;
if (!AVBK_Company_Payroll::enabled($company_id)) return;
$payroll_documents = AVBK_Company_Payroll::documents($company_id, $year);
?>
<section id="avbk-company-payroll" class="avbk-company-card">
    <h3>Loonadministratie · <?php echo esc_html($year); ?></h3>
    <p>Loonstrookjes en belastingopgaven voor <?php echo esc_html($company->name); ?>, voor alle maanden van dit boekjaar. Deze documenten staan los van de BTW-berekening.</p>
    <?php if (AVBK_Companies::can($company_id, 'import')) : ?>
        <?php $form_start('payroll_upload', true); ?>
            <label>Documentsoort <select name="payroll_kind"><?php foreach (AVBK_Company_Payroll::KINDS as $kind => $label) : ?><option value="<?php echo esc_attr($kind); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
            <label>Maand van het document <input type="month" name="payroll_period" min="<?php echo esc_attr($year . '-01'); ?>" max="<?php echo esc_attr($year . '-12'); ?>" required></label>
            <label>Bestanden <input type="file" name="payroll_documents[]" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple required></label>
            <p>Maximaal 20 bestanden, elk maximaal 3 MB en samen maximaal 20 MB. Kies per upload één documentsoort en één maand. Bestanden worden privé bij deze administratie opgeslagen.</p>
            <label>Toelichting / verwijzing <input name="payroll_reference" maxlength="1500"></label>
            <button type="submit" class="button">Loondocumenten uploaden</button>
        </form>
    <?php endif; ?>
    <?php if (!$payroll_documents) : ?>
        <p>Nog geen loonstrookjes of belastingopgaven voor dit boekjaar opgeslagen.</p>
    <?php else : ?>
        <div class="avbk-company-scroll"><table class="widefat striped">
            <thead><tr><th>Maand</th><th>Documentsoort</th><th>Bestand</th><th>Toelichting / verwijzing</th></tr></thead>
            <tbody><?php foreach ($payroll_documents as $payroll_document) : ?>
                <tr><td><?php echo esc_html(substr($payroll_document->document_period, 0, 7)); ?></td>
                    <td><?php echo esc_html(AVBK_Company_Payroll::KINDS[$payroll_document->document_kind]); ?></td>
                    <td><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'avbk_company_document', 'company_id' => $company_id, 'document_id' => $payroll_document->id], admin_url('admin-post.php')), 'avbk_company_document')); ?>"><?php echo esc_html($payroll_document->filename); ?></a></td>
                    <td><?php echo esc_html($payroll_document->source_reference); ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
    <p>Een belastingopgave is geen bewijs van betaling. Controleer de betaling van loonheffingen met de banktransacties van de betreffende maand.</p>
</section>
