<?php
defined('ABSPATH') || exit;

/**
 * Renders admin/companies.php's markup.
 *
 * @param array<string, mixed> $view $companies, $company, $company_id, $form_start, $notice, $quarter, $return_to, $transactions, $year.
 */
function avbk_render_companies(array $view): void {
    ['companies' => $companies, 'company' => $company, 'company_id' => $company_id, 'form_start' => $form_start, 'notice' => $notice, 'quarter' => $quarter, 'return_to' => $return_to, 'transactions' => $transactions, 'year' => $year] = $view;
// Variables and the form renderer are supplied after checking company access.
?>
<div class="wrap avbk-company">
    <?php if (is_admin()) : ?><h1>Bedrijfsboekhouding</h1><?php endif; ?>
    <?php if ($notice) : ?>
        <p role="status" class="avbk-company-notice <?php echo $notice['error'] ? 'avbk-company-error' : ''; ?>"><?php echo esc_html($notice['message']); ?></p>
    <?php endif; ?>
    <?php avbk_render_companies_picker($companies, $return_to, $company_id, $year, $quarter); ?>

    <?php if ($company) : ?>
        <?php if (is_admin()) : ?>
            <h2><?php echo esc_html($company->name . ' · ' . $year . ' Q' . $quarter); ?></h2>
        <?php else : ?>
            <h1 class="avbk-company-heading"><?php echo esc_html($company->name . ' · ' . $year . ' Q' . $quarter); ?></h1>
        <?php endif; ?>
        <p>Bankrekening: <strong><?php echo esc_html($company->iban); ?></strong>. Werkblad: <strong><?php echo esc_html(AVBK_Company_Workbook::sheet_name($company, $year, $quarter)); ?></strong>.</p>
        <?php require_once AVBK_PLUGIN_DIR . 'admin/company-report.php'; avbk_render_company_report($view); ?>
        <?php if (AVBK_Company_Payroll::enabled($company_id) && get_option('avbk_company_payroll_page_id')) : ?>
            <p><a class="button" href="<?php echo esc_url(add_query_arg(['company_id' => $company_id, 'book_year' => $year], get_permalink((int) get_option('avbk_company_payroll_page_id')))); ?>">Salaris en loonheffingen</a></p>
        <?php endif; ?>
        <?php avbk_render_company_bank_import($company_id, $form_start); ?>
        <?php require_once AVBK_PLUGIN_DIR . 'admin/company-cards.php'; avbk_render_company_cards($view); ?>
        <?php avbk_render_company_invoices($company_id, $form_start, $transactions); ?>
        <?php avbk_render_company_export($company_id, $company, $year, $quarter, $form_start, $transactions); ?>
        <?php avbk_render_company_transactions($transactions); ?>

        <?php avbk_render_company_settings($company_id, $form_start); ?>
    <?php endif; ?>

    <?php avbk_render_companies_create($form_start); ?>
</div>
<?php
}

/** Invoices and receipts: add form, unpaid invoices and the full list. */
function avbk_render_company_invoices($company_id, $form_start, $transactions): void {
?><section class="avbk-company-card">
            <h3>Facturen en bonnetjes</h3>
            <p>Upload de factuur of geef de vindplaats op, bijvoorbeeld een OneDrive-link of bestandspad. Verwijzingen worden bewaard; bestanden worden niet automatisch opgehaald. Een factuur vastleggen bevestigt nog geen BTW-bedrag.</p>
            <?php avbk_render_company_invoice_form($company_id, $form_start, $transactions); ?>
            <?php avbk_render_company_open_invoices($company_id, $form_start); ?>
            <ul><?php foreach (AVBK_Company_Documents::listing($company_id) as $document) :
                $tracked = $document->due_date !== null || $document->amount_total !== null;
                $overdue = $tracked && !$document->paid_at && $document->due_date !== null && $document->due_date < wp_date('Y-m-d');
            ?>
                <li>
                    <?php avbk_render_company_document($document, $company_id, $tracked, $overdue, $form_start); ?>
                </li>
            <?php endforeach; ?></ul>
        </section>
<?php
}

/** The company, year and quarter picker. */
function avbk_render_companies_picker($companies, $return_to, $company_id, $year, $quarter): void {
    if ($companies) : ?>
        <form method="get" action="<?php echo esc_url($return_to); ?>" class="avbk-company-picker">
            <?php if (is_admin()) : ?><input type="hidden" name="page" value="avbk-companies"><?php endif; ?>
            <label>Administratie <select name="company_id">
                <?php foreach ($companies as $item) : ?><option value="<?php echo esc_attr($item->id); ?>" <?php selected($company_id, (int) $item->id); ?>><?php echo esc_html($item->name); ?></option><?php endforeach; ?>
            </select></label>
            <label>Boekjaar <input type="number" name="book_year" min="2000" max="2100" value="<?php echo esc_attr($year); ?>" required></label>
            <label>Kwartaal <select name="quarter"><?php for ($q = 1; $q <= 4; $q++) : ?><option value="<?php echo esc_attr($q); ?>" <?php selected($quarter, $q); ?>>Q<?php echo esc_html($q); ?></option><?php endfor; ?></select></label>
            <button type="submit" class="button">Tonen</button>
        </form>
    <?php else : ?><p>Er zijn nog geen administraties waartoe je toegang hebt.</p><?php endif; ?>
<?php
}

/** The bank export upload form. */
function avbk_render_company_bank_import($company_id, $form_start): void {
    if (AVBK_Companies::can($company_id, 'import')) : ?>
            <section class="avbk-company-card">
                <h3>1. Bankexport uploaden</h3>
                <p>Kies een ING-export (.csv of .xlsx) voor deze bankrekening. Een export over meerdere kwartalen is toegestaan: alleen transacties uit het gekozen boekjaar en kwartaal worden verwerkt. Je ziet na de import hoeveel transacties buiten die periode zijn overgeslagen. Ontvangsten en uitgaven worden bewaard. Overlappende imports slaan bestaande transacties over.</p>
                <?php $form_start('import', true); ?>
                    <label for="avbk-company-bank">Bankexport</label>
                    <div class="avbk-dropzone"><span class="avbk-dropzone-text">Sleep een bestand hierheen, of klik om te kiezen</span><input id="avbk-company-bank" name="bank_export" type="file" accept=".csv,.xlsx" required></div>
                    <button type="submit" class="button button-primary">Uploaden en verwerken</button>
                </form>
            </section>
        <?php endif; ?>
<?php
}

/** The form for adding an invoice. */
function avbk_render_company_invoice_form($company_id, $form_start, $transactions): void {
    if (AVBK_Companies::can($company_id, 'import')) : ?>
                <?php $form_start('invoice', true); ?>
                    <label>Factuur (PDF, JPG, PNG of WebP; maximaal 3 MB) <input type="file" name="invoice" accept=".pdf,.jpg,.jpeg,.png,.webp"></label>
                    <label>Vindplaats / factuurverwijzing <input name="invoice_reference" maxlength="2000"></label>
                    <label>Bijbehorende banktransactie <select name="transaction_id"><option value="0">Nog niet gekoppeld</option>
                        <?php foreach ($transactions as $tx) : ?><option value="<?php echo esc_attr($tx->id); ?>"><?php echo esc_html($tx->transaction_date . ' · ' . $tx->counterparty_name . ' · € ' . number_format((float) $tx->amount, 2, ',', '.')); ?></option><?php endforeach; ?>
                    </select></label>
                    <label>Factuurdatum (optioneel) <input type="date" name="invoice_date"></label>
                    <label>Vervaldatum (optioneel) <input type="date" name="due_date"></label>
                    <label>Totaalbedrag (optioneel) <input type="text" name="amount_total" inputmode="decimal" placeholder="0,00"></label>
                    <button type="submit" class="button">Factuur toevoegen</button>
                </form>
            <?php endif; ?>
<?php
}

/** Unpaid invoices, each with a mark-as-paid form. */
function avbk_render_company_open_invoices($company_id, $form_start): void {
    $open_invoices = AVBK_Companies::can($company_id, 'import') ? AVBK_Company_Documents::unpaid($company_id) : []; ?>
            <?php if ($open_invoices) : ?>
                <h4>Openstaande facturen</h4>
                <ul class="avbk-company-open-invoices">
                    <?php foreach ($open_invoices as $open) : $overdue = $open->due_date < wp_date('Y-m-d'); ?>
                        <li class="<?php echo $overdue ? 'avbk-company-overdue' : ''; ?>">
                            <?php echo esc_html(avbk_company_open_invoice_label($open, $overdue)); ?>
                            <?php $form_start('invoice_mark_paid', false, 'avbk-company-inline-form'); ?>
                                <input type="hidden" name="document_id" value="<?php echo esc_attr($open->id); ?>">
                                <button type="submit" class="button-link">Markeer als betaald</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
<?php
}

/** The contents of one invoice or receipt entry, with its paid status. */
function avbk_render_company_document($document, $company_id, $tracked, $overdue, $form_start): void {
    if ($document->filename !== '') : ?>
                        <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'avbk_company_document', 'company_id' => $company_id, 'document_id' => $document->id], admin_url('admin-post.php')), 'avbk_company_document')); ?>"><?php echo esc_html($document->filename); ?></a>
                    <?php endif; ?>
                    <?php echo esc_html($document->source_reference); ?>
                    <?php if ($document->transaction_id) echo esc_html(' (transactie #' . $document->transaction_id . ')'); ?>
                    <?php if ($document->invoice_date) echo esc_html(' · Factuurdatum ' . $document->invoice_date); ?>
                    <?php if ($document->due_date) echo esc_html(' · Vervalt ' . $document->due_date); ?>
                    <?php if ($document->amount_total !== null) echo esc_html(' · € ' . number_format((float) $document->amount_total, 2, ',', '.')); ?>
                    <?php avbk_render_company_document_status($tracked, $document, $overdue, $company_id, $form_start); ?>
<?php
}

/** Paid status of a tracked invoice, with a form to change it. */
function avbk_render_company_document_status($tracked, $document, $overdue, $company_id, $form_start): void {
    if ($tracked) : ?>
                        <span class="avbk-company-status <?php echo $document->paid_at ? 'avbk-company-status-paid' : 'avbk-company-status-unpaid'; ?><?php echo $overdue ? ' avbk-company-overdue' : ''; ?>"><?php echo $document->paid_at ? 'Betaald' : ($overdue ? 'Te laat' : 'Openstaand'); ?></span>
                        <?php avbk_render_company_document_paid_form($company_id, $document, $form_start); ?>
                    <?php endif; ?>
<?php
}

/** The form that marks an invoice as paid or unpaid. */
function avbk_render_company_document_paid_form($company_id, $document, $form_start): void {
    if (AVBK_Companies::can($company_id, 'import')) : ?>
                            <?php if ($document->paid_at) : ?>
                                <?php $form_start('invoice_mark_unpaid', false, 'avbk-company-inline-form'); ?>
                                    <input type="hidden" name="document_id" value="<?php echo esc_attr($document->id); ?>">
                                    <button type="submit" class="button-link">Markeer als openstaand</button>
                                </form>
                            <?php else : ?>
                                <?php $form_start('invoice_mark_paid', false, 'avbk-company-inline-form'); ?>
                                    <input type="hidden" name="document_id" value="<?php echo esc_attr($document->id); ?>">
                                    <button type="submit" class="button-link">Markeer als betaald</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
<?php
}

/** The form that updates the quarter sheet in the VAT workbook. */
function avbk_render_company_export($company_id, $company, $year, $quarter, $form_start, $transactions): void {
    if (AVBK_Companies::can($company_id, 'export')) : ?>
            <section class="avbk-company-card">
                <h3>3. Het juiste kwartaalwerkblad bijwerken</h3>
                <p>Upload de nieuwste BTW-werkmap. Je krijgt de volledige werkmap terug met <strong><?php echo esc_html(AVBK_Company_Workbook::sheet_name($company, $year, $quarter)); ?></strong> bijgewerkt. Andere werkbladen blijven behouden. Bewaar de download als de nieuwe versie in OneDrive.</p>
                <p>Bestaande regels en BTW-aantekeningen van dit jaar blijven staan. Een tab met uitsluitend gegevens uit een vorig jaar wordt vernieuwd. Eerdere boekingen van dezelfde leverancier binnen dit bedrijf leveren BTW-suggesties op. Tegenstrijdige patronen en creditcardafrekeningen krijgen geen tarief toegewezen.</p>
                <p>Nieuwe regels krijgen in kolom P een BTW-controlemarkering en tellen nog niet mee in het gecontroleerde BTW-totaal. Na het bijwerken kun je bovenaan per regel de BTW controleren en bevestigen. Het portaal werkt dan de totalen en de Excel-download bij. Open de download in Excel om alle werkmapformules opnieuw te berekenen.</p>
                <?php $form_start('export', true); ?>
                    <label for="avbk-company-workbook">Nieuwste BTW-werkmap (.xlsx)</label>
                    <input id="avbk-company-workbook" name="workbook" type="file" accept=".xlsx" required>
                    <button type="submit" class="button" <?php disabled(!$transactions); ?>>Werkblad bijwerken en downloaden</button>
                    <p role="status" aria-live="polite"></p>
                </form>
            </section>
        <?php endif; ?>
<?php
}

/** The imported bank transactions of the period. */
function avbk_render_company_transactions($transactions): void {
?><h3>Geïmporteerde banktransacties (<?php echo esc_html(count($transactions)); ?>)</h3>
        <p>Dit is het bankoverzicht. Het kwartaalwerkblad met BTW, bedragen exclusief BTW en de controle per regel staat bovenaan.</p>
        <div class="avbk-company-scroll"><table class="widefat striped">
            <thead><tr><th>Datum</th><th>Naam / omschrijving</th><th>Af / bij</th><th>Bedrag</th><th>Mededelingen</th></tr></thead>
            <tbody><?php foreach ($transactions as $tx) : ?><tr>
                <td><?php echo esc_html($tx->transaction_date); ?></td><td><?php echo esc_html($tx->counterparty_name); ?></td><td><?php echo $tx->direction === 'out' ? 'Af' : 'Bij'; ?></td><td>€ <?php echo esc_html(number_format((float) $tx->amount, 2, ',', '.')); ?></td><td><?php echo esc_html($tx->description); ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
<?php
}

/** Feature settings and access management of a company. */
function avbk_render_company_settings($company_id, $form_start): void {
    if (AVBK_Companies::can($company_id, 'access')) : ?>
            <section class="avbk-company-card">
                <h3>Bedrijfsinstellingen en toegang</h3>
                <?php $features = AVBK_Company_Features::get($company_id); $form_start('company_features'); ?>
                    <label><input type="checkbox" name="feature_creditcard" value="1" <?php checked($features['creditcard']); ?>> Creditcard gebruiken</label>
                    <label><input type="checkbox" name="feature_payroll" value="1" <?php checked($features['payroll']); ?>> Loonadministratie voor werknemers</label>
                    <label><input type="checkbox" name="feature_loans" value="1" <?php checked($features['loans']); ?>> Leningen van toepassing</label>
                    <p>Het vinkje leningen legt vast dat dit bedrijf leningen heeft. Het leningsoverzicht is nog niet beschikbaar.</p>
                    <label><input type="checkbox" name="feature_ledger" value="1" <?php checked($features['ledger']); ?>> Grootboek gebruiken</label>
                    <p>Schakelt het handmatige grootboek in: een rekeningschema en boekingen, los van de bank- en BTW-werkmap.</p>
                    <button type="submit" class="button">Bedrijfsinstellingen opslaan</button>
                </form>
                <p>Directeur: uploaden, downloaden en toegang beheren. Boekhouder: uploaden en downloaden. Accountant: lezen en downloaden. Toegang geldt alleen voor dit bedrijf.</p>
                <ul><?php foreach (AVBK_Companies::members($company_id) as $membership) : $user = get_userdata((int) $membership->user_id); ?>
                    <li><?php echo esc_html(($user ? $user->user_email : 'Verwijderde gebruiker') . ' — ' . (AVBK_Companies::ROLES[$membership->role] ?? 'Onbekende rol')); ?></li>
                <?php endforeach; ?></ul>
                <?php $form_start('access'); ?>
                    <label>E-mailadres bestaand WordPress-account <input type="email" name="email" autocomplete="off" required></label>
                    <label>Rol <select name="role"><?php foreach (AVBK_Companies::ROLES as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?><option value="remove">Toegang intrekken</option></select></label>
                    <button type="submit" class="button">Toegang bijwerken</button>
                </form>
            </section>
        <?php endif; ?>
<?php
}

/** The form for adding a company administration (site admins only). */
function avbk_render_companies_create($form_start): void {
    if (current_user_can('manage_options')) : ?>
        <section class="avbk-company-card">
            <h2>Administratie toevoegen</h2>
            <?php $form_start('create'); ?>
                <label>Bedrijfsnaam <input name="company_name" maxlength="190" required></label>
                <label>Bankrekening (IBAN) <input name="iban" maxlength="42" required></label>
                <label>Werkbladprefix <input name="sheet_prefix" maxlength="25" required></label>
                <p>Gebruik het deel vóór “ Q1”, “ Q2”, enzovoort uit de bestaande werkbladnamen.</p>
                <button type="submit" class="button">Administratie toevoegen</button>
            </form>
        </section>
    <?php endif; ?>
<?php
}

/** "<file or reference> · vervalt <date>[ · € amount][ · te laat]" for an unpaid invoice. */
function avbk_company_open_invoice_label(object $open, bool $overdue): string {
    return ($open->filename !== '' ? $open->filename : $open->source_reference) . ' · vervalt ' . $open->due_date . ($open->amount_total !== null ? ' · € ' . number_format((float) $open->amount_total, 2, ',', '.') : '') . ($overdue ? ' · te laat' : '');
}
