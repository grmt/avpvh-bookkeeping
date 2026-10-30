<?php
defined('ABSPATH') || exit;
// Variables are supplied by AVBK_Company_Admin::render() after checking company access.
$form_start = function (string $operation, bool $upload = false) use ($company_id, $year, $quarter, $return_to) {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" <?php if ($upload) echo 'enctype="multipart/form-data"'; ?>>
        <?php wp_nonce_field('avbk_company'); ?>
        <input type="hidden" name="action" value="avbk_company">
        <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
        <input type="hidden" name="company_id" value="<?php echo esc_attr($company_id); ?>">
        <input type="hidden" name="book_year" value="<?php echo esc_attr($year); ?>">
        <input type="hidden" name="quarter" value="<?php echo esc_attr($quarter); ?>">
        <input type="hidden" name="return_to" value="<?php echo esc_url($return_to); ?>">
    <?php
};
?>
<div class="wrap avbk-company">
    <h1>Bedrijfsboekhouding</h1>
    <?php if ($notice) : ?>
        <p role="status" class="avbk-company-notice <?php echo $notice['error'] ? 'avbk-company-error' : ''; ?>"><?php echo esc_html($notice['message']); ?></p>
    <?php endif; ?>
    <?php if ($companies) : ?>
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

    <?php if ($company) : ?>
        <h2><?php echo esc_html($company->name . ' · ' . $year . ' Q' . $quarter); ?></h2>
        <p>Bankrekening: <strong><?php echo esc_html($company->iban); ?></strong>. Werkblad: <strong><?php echo esc_html(AVBK_Company_Workbook::sheet_name($company, $year, $quarter)); ?></strong>.</p>
        <?php if (AVBK_Companies::can($company_id, 'import')) : ?>
            <section class="avbk-company-card">
                <h3>1. Bankexport uploaden</h3>
                <p>Kies een ING-export (.csv of .xlsx) voor deze bankrekening en dit kwartaal. Ontvangsten en uitgaven worden bewaard. Overlappende imports slaan bestaande transacties over.</p>
                <?php $form_start('import', true); ?>
                    <label for="avbk-company-bank">Bankexport</label>
                    <div class="avbk-dropzone"><span class="avbk-dropzone-text">Sleep een bestand hierheen, of klik om te kiezen</span><input id="avbk-company-bank" name="bank_export" type="file" accept=".csv,.xlsx" required></div>
                    <button type="submit" class="button button-primary">Uploaden en verwerken</button>
                </form>
            </section>
        <?php endif; ?>
        <section class="avbk-company-card">
            <h3>Facturen en bonnetjes</h3>
            <p>Upload de factuur of geef de vindplaats op, bijvoorbeeld een OneDrive-link of bestandspad. Verwijzingen worden bewaard; bestanden worden niet automatisch opgehaald. Een factuur vastleggen bevestigt nog geen BTW-bedrag.</p>
            <?php if (AVBK_Companies::can($company_id, 'import')) : ?>
                <?php $form_start('invoice', true); ?>
                    <label>Factuur (PDF, JPG, PNG of WebP; maximaal 3 MB) <input type="file" name="invoice" accept=".pdf,.jpg,.jpeg,.png,.webp"></label>
                    <label>Vindplaats / factuurverwijzing <input name="invoice_reference" maxlength="2000"></label>
                    <label>Bijbehorende banktransactie <select name="transaction_id"><option value="0">Nog niet gekoppeld</option>
                        <?php foreach ($transactions as $tx) : ?><option value="<?php echo esc_attr($tx->id); ?>"><?php echo esc_html($tx->transaction_date . ' · ' . $tx->counterparty_name . ' · € ' . number_format((float) $tx->amount, 2, ',', '.')); ?></option><?php endforeach; ?>
                    </select></label>
                    <button type="submit" class="button">Factuur toevoegen</button>
                </form>
            <?php endif; ?>
            <ul><?php foreach (AVBK_Company_Documents::listing($company_id) as $document) : ?>
                <li>
                    <?php if ($document->filename !== '') : ?>
                        <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'avbk_company_document', 'company_id' => $company_id, 'document_id' => $document->id], admin_url('admin-post.php')), 'avbk_company_document')); ?>"><?php echo esc_html($document->filename); ?></a>
                    <?php endif; ?>
                    <?php echo esc_html($document->source_reference); ?>
                    <?php if ($document->transaction_id) echo esc_html(' (transactie #' . $document->transaction_id . ')'); ?>
                </li>
            <?php endforeach; ?></ul>
        </section>
        <?php if (AVBK_Companies::can($company_id, 'export')) : ?>
            <section class="avbk-company-card">
                <h3>2. Het juiste kwartaalwerkblad bijwerken</h3>
                <p>Upload de nieuwste BTW-werkmap. Je krijgt de volledige werkmap terug met <strong><?php echo esc_html(AVBK_Company_Workbook::sheet_name($company, $year, $quarter)); ?></strong> bijgewerkt. Andere werkbladen blijven behouden. Bewaar de download als de nieuwe versie in OneDrive.</p>
                <p>Bestaande regels en BTW-aantekeningen van dit jaar blijven staan. Een tab met uitsluitend gegevens uit een vorig jaar wordt vernieuwd. Eerdere boekingen van dezelfde leverancier binnen dit bedrijf leveren BTW-suggesties op. Tegenstrijdige patronen en creditcardafrekeningen krijgen geen tarief toegewezen.</p>
                <p>Nieuwe regels krijgen in kolom P een BTW-controlemarkering en tellen nog niet mee in het BTW-totaal. Controleer de factuur en verwijder de markering pas nadat de behandeling klopt. Open de download in Excel om de totalen opnieuw te berekenen.</p>
                <?php $form_start('export', true); ?>
                    <label for="avbk-company-workbook">Nieuwste BTW-werkmap (.xlsx)</label>
                    <input id="avbk-company-workbook" name="workbook" type="file" accept=".xlsx" required>
                    <button type="submit" class="button" <?php disabled(!$transactions); ?>>Werkblad bijwerken en downloaden</button>
                </form>
            </section>
        <?php endif; ?>
        <h3>Geïmporteerde banktransacties (<?php echo esc_html(count($transactions)); ?>)</h3>
        <p>Dit is het bankoverzicht. De BTW-controle gebeurt voorlopig in de werkmap; het overzicht is nog geen volledige grootboek- of BTW-aangifteadministratie.</p>
        <div class="avbk-company-scroll"><table class="widefat striped">
            <thead><tr><th>Datum</th><th>Naam / omschrijving</th><th>Af / bij</th><th>Bedrag</th><th>Mededelingen</th></tr></thead>
            <tbody><?php foreach ($transactions as $tx) : ?><tr>
                <td><?php echo esc_html($tx->transaction_date); ?></td><td><?php echo esc_html($tx->counterparty_name); ?></td><td><?php echo $tx->direction === 'out' ? 'Af' : 'Bij'; ?></td><td>€ <?php echo esc_html(number_format((float) $tx->amount, 2, ',', '.')); ?></td><td><?php echo esc_html($tx->description); ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>

        <?php if (AVBK_Companies::can($company_id, 'access')) : ?>
            <section class="avbk-company-card">
                <h3>Toegang tot deze administratie</h3>
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
    <?php endif; ?>

    <?php if (current_user_can('manage_options')) : ?>
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
</div>
