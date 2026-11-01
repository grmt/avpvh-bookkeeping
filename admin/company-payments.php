<?php
defined('ABSPATH') || exit;
$payroll_payments = AVBK_Company_Payroll::payments($company_id, $year);
?>
<?php foreach (array_reverse($payroll_payments, true) as $payment_period => $period_payments) : ?>
<section class="avbk-company-card">
    <h3>Betalingen · loontijdvak <?php echo esc_html($payment_period); ?></h3>
    <details class="avbk-payroll-payments"><summary class="button">Betaalgegevens en QR-codes · <?php echo esc_html($payment_period); ?></summary>
        <p>Scan met een bankapp die SEPA-QR ondersteunt, of gebruik de getoonde betaalgegevens. Kies in je bank de rekening van <?php echo esc_html($company->name . ' (' . $company->iban . ')'); ?>. Je bevestigt de overboeking in je bank; een QR-code aanmaken registreert geen betaling.</p>
        <div class="avbk-payroll-payment-grid">
            <?php foreach ($period_payments as $payment) : $payload = AVBK_Company_Payroll::payload($payment); $svg = $payload ? AVBK_QR::svg($payload) : null; ?>
                <article class="avbk-payroll-payment-card">
                    <h4><?php echo esc_html(AVBK_Company_Payroll::KINDS[$payment['kind']]); ?> · € <?php echo esc_html(number_format($payment['amount_cents'] / 100, 2, ',', '.')); ?></h4>
                    <?php if ($payment['state'] === 'review') : ?><p class="avbk-company-notice avbk-company-error">Eerst afstemmen · geen betaal-QR</p><?php endif; ?>
                    <dl><dt>Ontvanger</dt><dd><?php echo esc_html($payment['name']); ?></dd><dt>IBAN</dt><dd><?php echo esc_html($payment['iban']); ?></dd><dt><?php echo $payment['kind'] === 'payroll_tax' ? 'Betalingskenmerk' : 'Omschrijving'; ?></dt><dd><?php echo esc_html($payment['reference']); ?></dd>
                        <?php if (!empty($payment['due_date'])) : ?><dt>Uiterste betaaldatum volgens opgave</dt><dd><?php echo esc_html($payment['due_date']); ?></dd><?php endif; ?>
                    </dl>
                    <p><?php echo esc_html($payment['note'] ?? ''); ?></p>
                    <?php if ($svg) : ?><div class="avbk-payroll-qr"><?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted SVG generated locally by the QR library from validated payment data ?></div>
                    <?php elseif ($payload) : ?><p>De QR-code kon niet worden gegenereerd. Gebruik de betaalgegevens hierboven.</p><?php endif; ?>
                    <p><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'avbk_company_document', 'company_id' => $company_id, 'document_id' => $payment['document_id']], admin_url('admin-post.php')), 'avbk_company_document')); ?>">Bronbestand downloaden</a></p>
                </article>
            <?php endforeach; ?>
        </div>
    </details>
</section>
<?php endforeach; ?>
