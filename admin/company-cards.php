<?php
defined('ABSPATH') || exit;
$creditcard_enabled = AVBK_Company_Features::enabled($company_id, 'creditcard');
if (!$creditcard_enabled && !$card_transactions) return;
?>
<section id="avbk-company-cards" class="avbk-company-card">
    <h3>2. Creditcardafschriften</h3>
    <?php if ($creditcard_enabled) : ?><p>Selecteer of sleep meerdere PDF-afschriften en ING Commercial Cards-transactie-exports (CSV/XLSX) tegelijk. Maximaal 20 bestanden en samen 20 MB. PDF's worden privé bewaard om de aankopen en facturen uit te werken; een PDF-upload alleen voegt nog geen transacties of BTW toe. CSV/XLSX leest de afzonderlijke transacties in.</p>
    <?php else : ?><p>Creditcard is uitgeschakeld. Eerder geïmporteerde transacties blijven zichtbaar voor dit kwartaal.</p><?php endif; ?>
    <?php if ($creditcard_enabled && AVBK_Companies::can($company_id, 'import')) : ?>
        <?php $form_start('card_import', true); ?>
            <label for="avbk-company-card-file">Creditcardafschriften en transactie-exports (per PDF maximaal 3 MB)</label>
            <div class="avbk-dropzone"><span class="avbk-dropzone-text">Sleep je creditcardbestanden hierheen, of klik om te kiezen</span><input id="avbk-company-card-file" name="card_statement[]" type="file" accept=".pdf,.csv,.xlsx" multiple data-max-files="20" data-max-bytes="20971520" required></div>
            <button type="submit" class="button">Creditcardbestanden uploaden</button>
        </form>
    <?php endif; ?>
    <p>Gekoppeld creditcardaccount: <strong><?php echo esc_html($company->card_account ?? 'Nog niet gekoppeld'); ?></strong>. Voor CSV/XLSX is deze koppeling nodig om een export met meerdere accounts over de juiste bedrijven te verdelen.</p>
    <?php if ($creditcard_enabled && AVBK_Companies::can($company_id, 'access') && empty($company->card_account)) : ?>
        <?php $form_start('card_account'); ?>
            <label>Creditcardaccountnummer uit het afschrift of de kolom “Accountnummer” <input name="card_account" maxlength="64" required></label>
            <button type="submit" class="button">Account aan deze administratie koppelen</button>
        </form>
    <?php endif; ?>
    <p>Kaartaankopen en terugbetalingen komen als detailregels in het kwartaalwerkblad. De maandelijkse betaling aan de creditcard is een afrekening en levert geen extra BTW of kosten op. Gebruik voor BTW de factuur van iedere aankoop.</p>
    <?php if (!$card_transactions) : ?>
        <p><strong>Nog geen creditcardtransacties voor dit kwartaal.</strong> De getoonde BTW-bedragen zijn nog niet volledig zolang de bijbehorende kaartaankopen ontbreken.</p>
    <?php else :
        $card_lookup = []; $card_source_rows = [];
        foreach ($card_transactions as $card_tx) {
            $key = 'card:' . $card_tx->dedupe_hash;
            $card_lookup[$key] = $card_tx;
            $card_source_rows[] = ['source' => 'Creditcard', 'review_key' => $key, 'status' => $card_tx->entry_type === 'settlement' ? 'settlement' : 'detail',
                'gross_cents' => ($card_tx->direction === 'out' ? 1 : -1) * (int) round((float) $card_tx->amount * 100)];
        }
        $card_source_groups = AVBK_Company_Card_Groups::build(['rows' => $card_source_rows], $company, [], $card_transactions);
    ?>
        <div class="avbk-company-scroll"><table class="widefat striped">
            <thead><tr><th>Transactiedatum</th><th>Boekingsdatum</th><th>Kaart</th><th>Omschrijving</th><th>Soort</th><th>Bedrag EUR</th><th>Oorspronkelijk</th><th>Afschrift</th></tr></thead>
            <tbody><?php foreach ($card_source_groups as $card_source_group) : ?>
                <tr class="avbk-company-group"><th colspan="8" scope="rowgroup"><?php echo esc_html($card_source_group['statement'] ? 'Creditcardafschrift ' . $card_source_group['statement']['date'] : 'Kaarttransacties zonder gekoppeld afschrift'); ?></th></tr>
                <?php foreach ($card_source_group['rows'] as $card_source_row) : $card_tx = $card_lookup[$card_source_row['review_key']]; ?><tr>
                <td><?php echo esc_html($card_tx->transaction_date); ?></td><td><?php echo esc_html($card_tx->booking_date ?? 'Niet vermeld op afschrift'); ?></td><td>**** <?php echo esc_html($card_tx->card_last4); ?></td>
                <td><?php echo esc_html($card_tx->description); ?></td><td><?php echo esc_html(['purchase' => 'Aankoop', 'refund' => 'Terugbetaling', 'settlement' => 'Afrekening · geen extra kosten'][$card_tx->entry_type]); ?></td>
                <td class="avbk-company-amount"><?php echo $card_tx->direction === 'out' ? 'Af ' : 'Bij '; ?>€ <?php echo esc_html(number_format((float) $card_tx->amount, 2, ',', '.')); ?></td>
                <td><?php echo esc_html($card_tx->currency . ' ' . $card_tx->original_amount); ?></td>
                <td><?php $card_raw = json_decode($card_tx->raw_data, true) ?: []; $statement_id = (int) ($card_raw['Afschrift document_id'] ?? 0); ?>
                    <?php if ($statement_id) : ?><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'avbk_company_document', 'company_id' => $company_id, 'document_id' => $statement_id], admin_url('admin-post.php')), 'avbk_company_document')); ?>"><?php echo esc_html($card_tx->source_filename); ?></a>
                    <?php else : echo esc_html($card_tx->source_filename); endif; ?>
                </td>
            </tr><?php endforeach; endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
