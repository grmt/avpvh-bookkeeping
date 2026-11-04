<?php
defined('ABSPATH') || exit;
$money = fn($cents) => $cents === null ? 'Onbekend' : '€ ' . number_format($cents / 100, 2, ',', '.');
?>
<section id="avbk-company-result" class="avbk-company-card">
    <h3>Resultaat kwartaalwerkblad</h3>
    <?php if (!$report) : ?>
        <p>Werk hieronder de BTW-werkmap bij. Daarna verschijnen hier de regels, BTW en bedragen exclusief BTW van het bijgewerkte kwartaalwerkblad.</p>
    <?php else : $totals = $report['totals'];
        $linked_invoices = AVBK_Company_Documents::linked_by_transaction($company_id);
        $unlinked_invoices = AVBK_Companies::can($company_id, 'import') ? AVBK_Company_Documents::unlinked($company_id) : [];
    ?>
        <p><strong><?php echo esc_html($report['sheet']); ?></strong> · <?php echo esc_html($report['filename']); ?> · bijgewerkt <?php echo esc_html($report['generated_at']); ?></p>
        <?php if (!$report_stale && !empty($report['workbook_data']) && AVBK_Companies::can($company_id, 'export')) : ?>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'avbk_company_workbook', 'company_id' => $company_id, 'book_year' => $year, 'quarter' => $quarter], admin_url('admin-post.php')), 'avbk_company_workbook')); ?>">Bijgewerkte Excel-werkmap downloaden</a></p>
        <?php endif; ?>
        <?php if ($report_stale) : ?><p class="avbk-company-notice avbk-company-error">De bankgegevens zijn gewijzigd sinds dit resultaat. Werk de BTW-werkmap opnieuw bij voor een actueel overzicht.</p><?php endif; ?>
        <div class="avbk-company-totals">
            <div><span>Incl. BTW · bank en kaart, zonder kaartafrekeningen</span><strong><?php echo esc_html($money($totals['purchase_gross_cents'] ?? $totals['gross_cents'])); ?></strong></div>
            <div><span>BTW · bekende bedragen incl. suggesties</span><strong><?php echo esc_html($money($totals['known_vat_cents'])); ?></strong></div>
            <div><span>Excl. BTW · bekende bedragen incl. suggesties</span><strong><?php echo esc_html($money($totals['known_net_cents'])); ?></strong></div>
        </div>
        <p>Uitgaven zijn positief en ontvangsten negatief, zoals in het werkblad. Het BTW-saldo is geen berekende BTW-aangifte.</p>
        <p>Creditcardaankopen in dit werkblad: <strong><?php echo esc_html($report['card_rows'] ?? 0); ?></strong>. Bankmutaties inclusief afrekeningen: <?php echo esc_html($money($totals['gross_cents'])); ?>. Kaartaankopen inclusief BTW: <?php echo esc_html($money($totals['card_gross_cents'] ?? 0)); ?>.</p>
        <?php if (empty($report['card_rows']) && AVBK_Company_Features::enabled($company_id, 'creditcard')) : ?><p class="avbk-company-notice avbk-company-error">Creditcardaankopen ontbreken nog in dit resultaat. De BTW-berekening is onvolledig totdat de aankoopregels en bijbehorende facturen zijn verwerkt.</p><?php endif; ?>
        <?php if ($totals['pending_rows'] || $totals['unknown_rows']) : ?>
            <p class="avbk-company-notice"><strong>Voorlopig:</strong> <?php echo esc_html($totals['pending_rows']); ?> regels hebben een BTW-controlemarkering; bij <?php echo esc_html($totals['unknown_rows']); ?> regels ontbreekt BTW of het bedrag exclusief BTW. Onbekende bedragen zijn niet opgeteld. Controleer de facturen voordat je deze bedragen gebruikt.</p>
        <?php endif; ?>
        <p>Zonder BTW-controlemarkering, overeenkomstig het werkbladtotaal: <strong>BTW <?php echo esc_html($money($totals['confirmed_vat_cents'])); ?></strong> · <strong>excl. BTW <?php echo esc_html($money($totals['confirmed_net_cents'])); ?></strong>. De markering in de werkmap bepaalt deze selectie; dit is geen automatische factuurcontrole.</p>
        <?php if (!$report_stale && !empty($report['workbook_data']) && AVBK_Companies::can($company_id, 'import')) : ?>
            <p>Klopt de BTW bij een regel? Klik op <strong>OK</strong> om die direct te bevestigen. Voor een ander tarief of een notitie open je <strong>BTW controleren</strong>. Bevestigde regels tellen mee in het gecontroleerde totaal en de Excel-download wordt direct bijgewerkt. Een bankafrekening van de creditcard telt niet opnieuw als kosten.</p>
        <?php endif; ?>
        <div class="avbk-company-scroll"><table class="widefat striped">
            <caption><?php echo esc_html($report['bank_rows']); ?> bankregels en <?php echo esc_html($report['card_rows'] ?? 0); ?> kaarttransacties, gegroepeerd per afschrift. De rijnummers verwijzen naar Excel.</caption>
            <thead><tr><th>Rij</th><th>Bron</th><th>Datum</th><th>Naam / omschrijving</th><th>Af / bij</th><th>Incl. BTW</th><th>BTW %</th><th>BTW</th><th>Excl. BTW</th><th>Controle</th></tr></thead>
            <tbody><?php
            $display_groups = isset($company, $transactions, $card_transactions)
                ? AVBK_Company_Card_Groups::build($report, $company, $report_stale ? [] : $transactions, $card_transactions)
                : [['id' => 'bank', 'statement' => null, 'rows' => $report['rows']]];
            foreach ($display_groups as $display_group) : $statement = $display_group['statement']; ?>
                <tr id="avbk-company-group-<?php echo esc_attr($display_group['id']); ?>" class="avbk-company-group"><th colspan="10" scope="rowgroup">
                    <?php if ($statement) : ?>
                        Creditcardafschrift <?php echo esc_html($statement['date']); ?>
                        · <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'avbk_company_document', 'company_id' => $company_id, 'document_id' => $statement['document_id']], admin_url('admin-post.php')), 'avbk_company_document')); ?>">PDF bekijken</a>
                        <span>Afschrifttotaal: <?php echo esc_html($money($statement['total_cents'])); ?> · Aankopen/retouren in dit kwartaal: <?php echo esc_html($display_group['card_count']); ?> · <?php echo esc_html($money($display_group['card_cents'])); ?>.</span>
                        <span><?php echo esc_html($display_group['paid'] ? 'Bankafrekening hieronder gekoppeld op bedrag en datum; geen extra kosten.' : ($display_group['ambiguous'] ? 'Meerdere mogelijke afrekeningen: controleer de koppeling.' : 'Geen bijbehorende bankafrekening gevonden in de ingelezen gegevens van dit kwartaal.')); ?> Het afschrift kan ook aankopen buiten dit kwartaal bevatten.</span>
                    <?php else : ?>
                        <?php echo esc_html(['bank' => 'Overige banktransacties', 'cards-unlinked' => 'Creditcardtransacties zonder gekoppeld afschrift', 'settlements-unlinked' => 'Creditcardafrekeningen zonder gekoppeld afschrift'][$display_group['id']]); ?>
                        <?php if ($display_group['id'] !== 'bank') : ?><span>Er is geen eenduidige koppeling met een verwerkt afschrift. Alle bedragen blijven zichtbaar.</span><?php endif; ?>
                    <?php endif; ?>
                </th></tr>
                <?php foreach ($display_group['rows'] as $result_row) : ?>
                <tr id="avbk-company-row-<?php echo esc_attr(($result_row['review_key'] ?? '') ?: 'number-' . $result_row['number']); ?>">
                    <td><?php echo esc_html($result_row['number']); ?></td><td><?php echo esc_html($result_row['source'] ?? 'Bank'); ?></td><td><?php echo esc_html($result_row['date']); ?></td>
                    <td><?php if ($result_row['detail']) echo '↳ '; ?><?php echo esc_html($result_row['name']); ?></td>
                    <td><?php echo $result_row['direction'] === 'out' ? 'Af' : 'Bij'; ?></td>
                    <td class="avbk-company-amount"><?php echo esc_html($money($result_row['gross_cents'])); ?></td>
                    <td><?php echo esc_html($result_row['code'] === '' ? '—' : ($result_row['code'] === 'x' ? 'Vast bedrag' : $result_row['code'] . '%')); ?></td>
                    <td class="avbk-company-amount"><?php echo esc_html($money($result_row['vat_cents'])); ?></td>
                    <td class="avbk-company-amount"><?php echo esc_html($money($result_row['net_cents'])); ?></td>
                    <td><span class="avbk-company-status"><?php echo esc_html(['unknown' => 'Onbekend', 'suggested' => 'Suggestie · controleren', 'instructed' => 'BTW-indeling door gebruiker opgegeven', 'checked' => 'Gecontroleerd / zonder controlemarkering', 'detail' => 'Detailregel', 'settlement' => 'Afrekening · geen extra kosten'][$result_row['status']]); ?></span><?php if ($result_row['note'] !== '') : ?><small><?php echo esc_html($result_row['note']); ?></small><?php endif; ?>
                        <?php if (!empty($result_row['tx_id']) && !empty($linked_invoices[$result_row['tx_id']])) : ?>
                            <small>Factuur: <?php foreach ($linked_invoices[$result_row['tx_id']] as $i => $invoice_doc) : ?><?php if ($i) echo ', '; ?><?php if ($invoice_doc->filename !== '') : ?><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'avbk_company_document', 'company_id' => $company_id, 'document_id' => $invoice_doc->id], admin_url('admin-post.php')), 'avbk_company_document')); ?>"><?php echo esc_html($invoice_doc->filename); ?></a><?php else : echo esc_html($invoice_doc->source_reference); endif; ?><?php endforeach; ?></small>
                        <?php endif; ?>
                        <?php if (!$report_stale && !empty($report['workbook_data']) && !empty($result_row['review_key']) && AVBK_Companies::can($company_id, 'import')) :
                            $saved_review = $report['reviews'][$result_row['review_key']] ?? null; ?>
                            <?php if ($result_row['status'] !== 'checked' && in_array($result_row['code'], ['0', '9', '21', 'x'], true) && $result_row['vat_cents'] !== null && $result_row['net_cents'] !== null) : ?>
                                <?php $form_start('review', false, 'avbk-company-quick-review'); ?>
                                    <input type="hidden" name="workbook_hash" value="<?php echo esc_attr($report['workbook_hash']); ?>">
                                    <input type="hidden" name="review_key" value="<?php echo esc_attr($result_row['review_key']); ?>">
                                    <input type="hidden" name="vat_code" value="<?php echo esc_attr($result_row['code']); ?>">
                                    <input type="hidden" name="fixed_vat" value="<?php echo esc_attr($result_row['code'] === 'x' ? number_format(abs($result_row['vat_cents']) / 100, 2, '.', '') : ''); ?>">
                                    <input type="hidden" name="review_note" value="<?php echo esc_attr($saved_review['note'] ?? ''); ?>">
                                    <button type="submit" name="review_state" value="checked" class="button button-primary avbk-company-ok" aria-label="<?php echo esc_attr('BTW bevestigen voor ' . $result_row['name'] . ' op ' . $result_row['date']); ?>">OK</button>
                                    <span class="avbk-company-review-message" role="status" aria-live="polite"></span>
                                </form>
                            <?php endif; ?>
                            <details class="avbk-company-review"><summary>BTW controleren</summary>
                                <?php $form_start('review'); ?>
                                    <input type="hidden" name="workbook_hash" value="<?php echo esc_attr($report['workbook_hash']); ?>">
                                    <input type="hidden" name="review_key" value="<?php echo esc_attr($result_row['review_key']); ?>">
                                    <label>BTW-tarief <select name="vat_code">
                                        <?php foreach (['' => 'Onbekend', '0' => '0%', '9' => '9%', '21' => '21%', 'x' => 'Vast BTW-bedrag'] as $code => $label) : ?>
                                            <option value="<?php echo esc_attr($code); ?>" <?php selected($result_row['code'], (string) $code); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select></label>
                                    <label>Vast BTW-bedrag (alleen bij vast bedrag, positief in euro) <input name="fixed_vat" inputmode="decimal" value="<?php echo esc_attr($result_row['code'] === 'x' && $result_row['vat_cents'] !== null ? number_format(abs($result_row['vat_cents']) / 100, 2, ',', '') : ''); ?>"></label>
                                    <label>Factuurverwijzing / notitie <input name="review_note" maxlength="500" value="<?php echo esc_attr($saved_review['note'] ?? ''); ?>"></label>
                                    <button type="submit" name="review_state" value="checked" class="button button-primary">Bevestigen</button>
                                    <button type="submit" name="review_state" value="pending" class="button">Opslaan · nog controleren</button>
                                </form>
                            </details>
                            <?php if (!empty($result_row['tx_id']) && empty($linked_invoices[$result_row['tx_id']]) && $unlinked_invoices) : ?>
                                <details class="avbk-company-review"><summary>Factuur koppelen</summary>
                                    <?php $form_start('invoice_link'); ?>
                                        <input type="hidden" name="transaction_id" value="<?php echo esc_attr($result_row['tx_id']); ?>">
                                        <label>Factuur <select name="document_id" required>
                                            <option value="">Kies een factuur</option>
                                            <?php foreach ($unlinked_invoices as $doc) : ?>
                                                <option value="<?php echo esc_attr($doc->id); ?>"><?php echo esc_html($doc->filename !== '' ? $doc->filename : $doc->source_reference); ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                        <button type="submit" class="button">Koppelen</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endforeach; ?></tbody>
        </table></div>
        <p>BTW-controles in het portaal worden bewaard voor dit bedrijf en kwartaal, ook als je later een oudere werkmap uploadt. Ze krijgen voorrang op de BTW-cellen in die upload. Overige wijzigingen in Excel verschijnen hier nadat je de werkmap opnieuw uploadt en bijwerkt. Regels met handmatige aankoopdetails controleer je in Excel.</p>
        <?php if (!empty($report['review_log'])) : ?>
            <details><summary>Wijzigingshistorie (<?php echo esc_html(count($report['review_log'])); ?>)</summary>
                <ul><?php foreach (array_reverse($report['review_log']) as $event) : ?>
                    <li><?php echo esc_html($event['after']['at'] . ' · ' . $event['date'] . ' · ' . $event['name'] . ' · gebruiker #' . $event['after']['by'] . ' · BTW ' . ($event['before']['code'] === '' ? 'onbekend' : $event['before']['code']) . ' → ' . ($event['after']['code'] === '' ? 'onbekend' : $event['after']['code']) . ' · ' . $money($event['after']['vat_cents']) . ' · ' . ($event['after']['state'] === 'checked' ? 'bevestigd' : 'nog controleren') . ($event['after']['note'] !== '' ? ' · ' . $event['after']['note'] : '')); ?></li>
                <?php endforeach; ?></ul>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</section>
