<?php
defined('ABSPATH') || exit;
$money = fn($cents) => $cents === null ? 'Onbekend' : '€ ' . number_format($cents / 100, 2, ',', '.');
?>
<section id="avbk-company-result" class="avbk-company-card">
    <h3>Resultaat kwartaalwerkblad</h3>
    <?php if (!$report) : ?>
        <p>Werk hieronder de BTW-werkmap bij. Daarna verschijnen hier de regels, BTW en bedragen exclusief BTW van het bijgewerkte kwartaalwerkblad.</p>
    <?php else : $totals = $report['totals']; ?>
        <p><strong><?php echo esc_html($report['sheet']); ?></strong> · <?php echo esc_html($report['filename']); ?> · bijgewerkt <?php echo esc_html($report['generated_at']); ?></p>
        <?php if ($report_stale) : ?><p class="avbk-company-notice avbk-company-error">De bankgegevens zijn gewijzigd sinds dit resultaat. Werk de BTW-werkmap opnieuw bij voor een actueel overzicht.</p><?php endif; ?>
        <div class="avbk-company-totals">
            <div><span>Incl. BTW · bank en kaart, zonder kaartafrekeningen</span><strong><?php echo esc_html($money($totals['purchase_gross_cents'] ?? $totals['gross_cents'])); ?></strong></div>
            <div><span>BTW · bekende bedragen incl. suggesties</span><strong><?php echo esc_html($money($totals['known_vat_cents'])); ?></strong></div>
            <div><span>Excl. BTW · bekende bedragen incl. suggesties</span><strong><?php echo esc_html($money($totals['known_net_cents'])); ?></strong></div>
        </div>
        <p>Uitgaven zijn positief en ontvangsten negatief, zoals in het werkblad. Het BTW-saldo is geen berekende BTW-aangifte.</p>
        <p>Creditcardaankopen in dit werkblad: <strong><?php echo esc_html($report['card_rows'] ?? 0); ?></strong>. Bankmutaties inclusief afrekeningen: <?php echo esc_html($money($totals['gross_cents'])); ?>. Kaartaankopen inclusief BTW: <?php echo esc_html($money($totals['card_gross_cents'] ?? 0)); ?>.</p>
        <?php if (empty($report['card_rows'])) : ?><p class="avbk-company-notice avbk-company-error">Creditcardaankopen ontbreken nog in dit resultaat. De BTW-berekening is onvolledig totdat de aankoopregels en bijbehorende facturen zijn verwerkt.</p><?php endif; ?>
        <?php if ($totals['pending_rows'] || $totals['unknown_rows']) : ?>
            <p class="avbk-company-notice"><strong>Voorlopig:</strong> <?php echo esc_html($totals['pending_rows']); ?> regels hebben een BTW-controlemarkering; bij <?php echo esc_html($totals['unknown_rows']); ?> regels ontbreekt BTW of het bedrag exclusief BTW. Onbekende bedragen zijn niet opgeteld. Controleer de facturen voordat je deze bedragen gebruikt.</p>
        <?php endif; ?>
        <p>Zonder BTW-controlemarkering, overeenkomstig het werkbladtotaal: <strong>BTW <?php echo esc_html($money($totals['confirmed_vat_cents'])); ?></strong> · <strong>excl. BTW <?php echo esc_html($money($totals['confirmed_net_cents'])); ?></strong>. De markering in de werkmap bepaalt deze selectie; dit is geen automatische factuurcontrole.</p>
        <div class="avbk-company-scroll"><table class="widefat striped">
            <caption><?php echo esc_html($report['bank_rows']); ?> bankregels, met eventuele detailregels uit het kwartaalwerkblad</caption>
            <thead><tr><th>Rij</th><th>Bron</th><th>Datum</th><th>Naam / omschrijving</th><th>Af / bij</th><th>Incl. BTW</th><th>BTW %</th><th>BTW</th><th>Excl. BTW</th><th>Controle</th></tr></thead>
            <tbody><?php foreach ($report['rows'] as $result_row) : ?>
                <tr>
                    <td><?php echo esc_html($result_row['number']); ?></td><td><?php echo esc_html($result_row['source'] ?? 'Bank'); ?></td><td><?php echo esc_html($result_row['date']); ?></td>
                    <td><?php if ($result_row['detail']) echo '↳ '; ?><?php echo esc_html($result_row['name']); ?></td>
                    <td><?php echo $result_row['direction'] === 'out' ? 'Af' : 'Bij'; ?></td>
                    <td class="avbk-company-amount"><?php echo esc_html($money($result_row['gross_cents'])); ?></td>
                    <td><?php echo esc_html($result_row['code'] === '' ? '—' : ($result_row['code'] === 'x' ? 'Vast bedrag' : $result_row['code'] . '%')); ?></td>
                    <td class="avbk-company-amount"><?php echo esc_html($money($result_row['vat_cents'])); ?></td>
                    <td class="avbk-company-amount"><?php echo esc_html($money($result_row['net_cents'])); ?></td>
                    <td><span class="avbk-company-status"><?php echo esc_html(['unknown' => 'Onbekend', 'suggested' => 'Suggestie · controleren', 'checked' => 'Zonder controlemarkering', 'detail' => 'Detailregel', 'settlement' => 'Afrekening · geen extra kosten'][$result_row['status']]); ?></span><?php if ($result_row['note'] !== '') : ?><small><?php echo esc_html($result_row['note']); ?></small><?php endif; ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <p>Dit overzicht bewaart het laatst gegenereerde resultaat. Wijzigingen die je daarna in Excel maakt verschijnen hier nadat je die werkmap opnieuw uploadt en bijwerkt.</p>
    <?php endif; ?>
</section>
