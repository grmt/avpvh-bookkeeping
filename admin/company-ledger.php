<?php
defined('ABSPATH') || exit;
$money = fn($cents) => '€ ' . number_format($cents / 100, 2, ',', '.');
?>
<div class="wrap avbk-company">
    <h1 class="avbk-company-heading">Grootboek · <?php echo esc_html($company->name); ?></h1>
    <?php if ($notice) : ?><p role="status" class="avbk-company-notice <?php echo $notice['error'] ? 'avbk-company-error' : ''; ?>"><?php echo esc_html($notice['message']); ?></p><?php endif; ?>
    <form method="get" action="<?php echo esc_url($return_to); ?>" class="avbk-company-picker">
        <?php if (is_admin()) : ?><input type="hidden" name="page" value="avbk-companies-ledger"><?php endif; ?>
        <label>Administratie <select name="company_id"><?php foreach ($companies as $item) : ?><option value="<?php echo esc_attr($item->id); ?>" <?php selected((int) $item->id, $company_id); ?>><?php echo esc_html($item->name); ?></option><?php endforeach; ?></select></label>
        <button type="submit" class="button">Tonen</button>
    </form>
    <p><a href="<?php echo esc_url(add_query_arg(['company_id' => $company_id], home_url('/'))); ?>">Naar bedrijfsboekhouding</a></p>

    <section class="avbk-company-card">
        <h3>Rekeningschema</h3>
        <p>Een los grootboek met handmatige boekingen, naast de bank- en BTW-werkmap. Boekingen hier veranderen niets aan
        geïmporteerde banktransacties, facturen of de BTW-controle.</p>
        <?php if (!$accounts) : ?>
            <p>Er is nog geen rekeningschema voor deze administratie.</p>
            <?php if (AVBK_Companies::can($company_id, 'import')) : ?>
                <?php $form_start('ledger_seed_accounts'); ?>
                    <button type="submit" class="button button-primary">Standaard rekeningschema toevoegen</button>
                </form>
            <?php endif; ?>
        <?php else : ?>
            <div class="avbk-company-scroll"><table class="widefat striped">
                <thead><tr><th>Code</th><th>Naam</th><th>Type</th><th>Status</th></tr></thead>
                <tbody><?php foreach ($accounts as $account) : ?><tr>
                    <td><?php echo esc_html($account->code); ?></td>
                    <td><?php echo esc_html($account->name); ?></td>
                    <td><?php echo esc_html(AVBK_Company_Ledger::ACCOUNT_TYPES[$account->account_type] ?? $account->account_type); ?></td>
                    <td><?php echo $account->is_active ? 'Actief' : 'Inactief'; ?>
                        <?php if ($account->is_active && AVBK_Companies::can($company_id, 'import')) : ?>
                            <?php $form_start('ledger_deactivate_account', false, 'avbk-company-inline-form'); ?>
                                <input type="hidden" name="account_id" value="<?php echo esc_attr($account->id); ?>">
                                <button type="submit" class="button-link">Deactiveren</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
        <?php if (AVBK_Companies::can($company_id, 'import')) : ?>
            <details class="avbk-company-review"><summary>Rekening toevoegen</summary>
                <?php $form_start('ledger_add_account'); ?>
                    <label>Code <input name="account_code" maxlength="20" required></label>
                    <label>Naam <input name="account_name" maxlength="190" required></label>
                    <label>Type <select name="account_type">
                        <?php foreach (AVBK_Company_Ledger::ACCOUNT_TYPES as $key => $label) : ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <button type="submit" class="button">Rekening toevoegen</button>
                </form>
            </details>
        <?php endif; ?>
    </section>

    <?php if ($accounts && AVBK_Companies::can($company_id, 'import')) : ?>
        <section class="avbk-company-card">
            <h3>Nieuwe boeking</h3>
            <p>Debet en credit moeten gelijk zijn. Vul per regel een rekening in met óf een debetbedrag óf een creditbedrag.</p>
            <?php $form_start('ledger_entry'); ?>
                <label>Datum <input type="date" name="entry_date" required></label>
                <label>Omschrijving <input name="entry_description" maxlength="500" required></label>
                <label>Referentie (optioneel) <input name="entry_reference" maxlength="255"></label>
                <div class="avbk-company-scroll"><table class="widefat striped">
                    <thead><tr><th scope="col">Rekening</th><th scope="col">Debet</th><th scope="col">Credit</th><th scope="col">Toelichting</th></tr></thead>
                    <tbody><?php for ($i = 0; $i < 6; $i++) : ?><tr>
                        <td><label class="screen-reader-text" for="avbk-ledger-line-<?php echo (int) $i; ?>-account">Rekening, regel <?php echo (int) $i + 1; ?></label><select name="line_account_id[]" id="avbk-ledger-line-<?php echo (int) $i; ?>-account">
                            <option value="">—</option>
                            <?php foreach ($accounts as $account) : if (!$account->is_active) continue; ?>
                                <option value="<?php echo esc_attr($account->id); ?>"><?php echo esc_html($account->code . ' ' . $account->name); ?></option>
                            <?php endforeach; ?>
                        </select></td>
                        <td><label class="screen-reader-text" for="avbk-ledger-line-<?php echo (int) $i; ?>-debit">Debet, regel <?php echo (int) $i + 1; ?></label><input name="line_debit[]" id="avbk-ledger-line-<?php echo (int) $i; ?>-debit" inputmode="decimal" placeholder="0,00"></td>
                        <td><label class="screen-reader-text" for="avbk-ledger-line-<?php echo (int) $i; ?>-credit">Credit, regel <?php echo (int) $i + 1; ?></label><input name="line_credit[]" id="avbk-ledger-line-<?php echo (int) $i; ?>-credit" inputmode="decimal" placeholder="0,00"></td>
                        <td><label class="screen-reader-text" for="avbk-ledger-line-<?php echo (int) $i; ?>-note">Toelichting, regel <?php echo (int) $i + 1; ?></label><input name="line_note[]" id="avbk-ledger-line-<?php echo (int) $i; ?>-note" maxlength="255"></td>
                    </tr><?php endfor; ?></tbody>
                </table></div>
                <button type="submit" class="button button-primary">Boeking opslaan</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="avbk-company-card">
        <h3>Boekingen</h3>
        <?php if (!$entries) : ?><p>Nog geen boekingen.</p><?php endif; ?>
        <?php foreach (array_reverse($entries) as $entry) : $entry_lines = AVBK_Company_Ledger::lines($company_id, $entry->id); ?>
            <div class="avbk-company-scroll"><table class="widefat striped">
                <caption>
                    Boeking #<?php echo esc_html($entry->entry_number); ?> · <?php echo esc_html($entry->entry_date); ?> · <?php echo esc_html($entry->description); ?><?php if ($entry->reference !== '') echo ' · ' . esc_html($entry->reference); ?>
                    <?php if ($entry->voided_at) : ?><span class="avbk-company-overdue"> · teruggedraaid <?php echo esc_html($entry->voided_at); ?>: <?php echo esc_html($entry->void_reason); ?></span><?php endif; ?>
                </caption>
                <thead><tr><th scope="col">Rekening</th><th scope="col">Debet</th><th scope="col">Credit</th><th scope="col">Toelichting</th></tr></thead>
                <tbody><?php foreach ($entry_lines as $line) : ?><tr>
                    <td><?php echo esc_html($line->account_code . ' ' . $line->account_name); ?></td>
                    <td class="avbk-company-amount"><?php echo esc_html($line->debit_cents ? $money($line->debit_cents) : ''); ?></td>
                    <td class="avbk-company-amount"><?php echo esc_html($line->credit_cents ? $money($line->credit_cents) : ''); ?></td>
                    <td><?php echo esc_html($line->line_note); ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
            <?php if (!$entry->voided_at && AVBK_Companies::can($company_id, 'import')) : ?>
                <details class="avbk-company-review"><summary>Boeking terugdraaien</summary>
                    <?php $form_start('ledger_void'); ?>
                        <input type="hidden" name="entry_id" value="<?php echo esc_attr($entry->id); ?>">
                        <label>Reden <input name="void_reason" maxlength="500" required></label>
                        <button type="submit" class="button">Terugdraaien</button>
                    </form>
                </details>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>
</div>
