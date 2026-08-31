<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$closed_through_year = (int) get_option('avbk_closed_through_year', 0);
$show_all_years = !empty($_GET['show_all_years']);
$transactions = AVBK_DB::get_transactions($show_all_years || !$closed_through_year ? [] : ['min_year' => $closed_through_year + 1]);
$activities = AVPVH_DB::get_activities();
$activity_names = [];
foreach ($activities as $activity) {
    $activity_names[(int) $activity->id] = $activity->name . ' (' . $activity->year . ')';
}
$status_label = [
    'matched'   => 'Gekoppeld',
    'suggested' => 'Suggestie',
    'unmatched' => 'Niet gekoppeld',
    'ignored'   => 'Genegeerd',
    'duplicate' => 'Dubbele betaling',
];
$ignore_reason_label = [
    'import_outgoing' => 'automatisch bij import (uitgaand)',
    'manual_review'   => 'handmatig na controle',
];
?>
<div class="wrap">
    <h1>Alle transacties</h1>
    <p class="description">Elke geïmporteerde rij uit een bankexport, inclusief uitgaande betalingen (declaraties, boodschappen) &mdash; die worden nooit aan een lid gekoppeld, alleen bewaard voor het overzicht.</p>
    <?php if (isset($_GET['activity_tagged'])) : ?>
        <div class="notice <?php echo $_GET['activity_tagged'] === '1' ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo $_GET['activity_tagged'] === '1' ? 'Activiteit opgeslagen.' : 'Activiteit niet gewijzigd: deze transactie heeft al een toewijzingsregel.'; ?></p></div>
    <?php endif; ?>
    <?php if ($closed_through_year) : ?>
        <p class="description">
            <?php if ($show_all_years) : ?>
                Toont ook transacties tot en met <?php echo esc_html($closed_through_year); ?> (afgesloten).
                <a href="<?php echo esc_url(remove_query_arg('show_all_years')); ?>">Verberg afgesloten jaren</a>.
            <?php else : ?>
                Transacties tot en met <?php echo esc_html($closed_through_year); ?> zijn afgesloten en worden hier verborgen.
                <a href="<?php echo esc_url(add_query_arg('show_all_years', '1')); ?>">Toon oudere jaren</a>.
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <p class="description">Zoek of filter in de velden onder de kolomkoppen. Klik op een kolomkop om te sorteren.</p>
    <div class="avbk-balance-table-tools">
        <button type="button" class="button button-small avbk-col-toggle-btn">Kolommen</button>
        <div class="avbk-col-toggle-panel" hidden></div>
    </div>
    <div class="avbk-balance-table-wrap">
    <table id="avbk-balance-table" data-storage-key="avbk_transactions_hidden_cols" class="wp-list-table widefat striped avbk-balance-table">
        <thead>
            <tr class="avbk-balance-header-row">
                <th data-col="regel" data-type="number">Regel</th>
                <th data-col="import" data-type="number">Import</th>
                <th data-col="datum" data-type="date">Datum</th>
                <th data-col="richting" data-filter="select">Richting</th>
                <th data-col="bedrag" data-type="number">Bedrag</th>
                <th data-col="naam">Naam</th>
                <th data-col="omschrijving">Omschrijving</th>
                <th data-col="activiteit" data-filter="select">Activiteit</th>
                <th data-col="status" data-filter="select">Status</th>
                <th data-col="toegewezen" data-type="number">Toegewezen</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$transactions) : ?>
            <tr><td colspan="10">Nog geen transacties geïmporteerd.</td></tr>
        <?php else : foreach ($transactions as $tx) :
            // "Gekoppeld" only means a member was identified — it says
            // nothing about whether the bedrag actually landed on a
            // bijdrage. Surface that separately so a payment confirmed
            // before its bijdrage-regel existed (silently €0 toegewezen —
            // see incident 2026-08-29) is visible here instead of only
            // discoverable by checking the ledenoverzicht by hand.
            $allocations = AVBK_DB::get_allocations_for_transaction((int) $tx->id);
            $allocated = 0.0;
            if ($tx->direction === 'in' && $tx->status === 'matched') {
                foreach ($allocations as $a) {
                    $allocated += (float) $a->amount;
                }
            }
            $allocated_activity_names = [];
            foreach ($allocations as $allocation) {
                $fee_item = AVBK_DB::get_fee_item((int) $allocation->fee_item_id);
                if ($fee_item && !empty($fee_item->activity_id) && isset($activity_names[(int) $fee_item->activity_id])) {
                    $allocated_activity_names[(int) $fee_item->activity_id] = $activity_names[(int) $fee_item->activity_id];
                }
            }
            $shortfall = $tx->direction === 'in' && $tx->status === 'matched' ? round((float) $tx->amount - $allocated, 2) : 0.0;
            $display_status = !empty($tx->duplicate_of) ? 'Duplicaat' : ($status_label[$tx->status] ?? $tx->status);
            if ($tx->status === 'ignored' && isset($ignore_reason_label[$tx->ignore_reason])) {
                $display_status .= ' — ' . $ignore_reason_label[$tx->ignore_reason];
            }
            ?>
            <tr id="tx-<?php echo esc_attr($tx->id); ?>"<?php echo $shortfall > 0.005 ? ' style="background:#fcf0f1"' : ''; ?>>
                <td data-sort-value="<?php echo esc_attr((int) $tx->source_row); ?>"><?php echo $tx->source_row ? esc_html($tx->source_row) : '&mdash;'; ?></td>
                <td data-sort-value="<?php echo esc_attr((int) $tx->import_batch_id); ?>">
                    <?php if ($tx->import_batch_id) : ?>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-import'], admin_url('admin.php'))); ?>" title="<?php echo esc_attr($tx->import_uploaded_at ?: ''); ?>">#<?php echo esc_html($tx->import_batch_id); ?></a>
                        <?php if (!empty($tx->import_filename)) : ?><br><span class="description"><?php echo esc_html($tx->import_filename); ?></span><?php endif; ?>
                    <?php else : ?>&mdash;<?php endif; ?>
                </td>
                <td data-sort-value="<?php echo esc_attr($tx->transaction_date); ?>"><?php echo esc_html(wp_date('D d M Y', strtotime($tx->transaction_date))); ?></td>
                <td><?php echo $tx->direction === 'in' ? 'Bij' : 'Af'; ?></td>
                <td data-sort-value="<?php echo esc_attr(number_format((float) $tx->amount, 2, '.', '')); ?>">&euro; <?php echo esc_html(number_format((float) $tx->amount, 2, ',', '.')); ?></td>
                <td><?php echo esc_html($tx->counterparty_name); ?></td>
                <?php $clean_description = AVBK_Matcher::strip_name_field($tx->description); ?>
                <td data-filter-value="<?php echo esc_attr($clean_description); ?>"><?php echo esc_html(mb_strimwidth($clean_description, 0, 100, '…')); ?></td>
                <?php $tag_label = $allocations
                    ? ($allocated_activity_names ? implode(', ', $allocated_activity_names) : 'Toegewezen zonder activiteit')
                    : (!empty($tx->activity_id) && isset($activity_names[(int) $tx->activity_id]) ? $activity_names[(int) $tx->activity_id] : 'Geen activiteit'); ?>
                <td data-filter-value="<?php echo esc_attr($tag_label); ?>">
                    <?php if ($allocations) : ?>
                        <?php echo esc_html($tag_label); ?>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:.25rem;align-items:center">
                            <?php wp_nonce_field('avbk_set_transaction_activity'); ?>
                            <input type="hidden" name="action" value="avbk_set_transaction_activity">
                            <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">
                            <input type="hidden" name="show_all_years" value="<?php echo $show_all_years ? '1' : ''; ?>">
                            <select name="activity_id" style="max-width:17em">
                                <option value="0">&mdash; geen activiteit &mdash;</option>
                                <?php foreach ($activities as $activity) : ?>
                                    <option value="<?php echo esc_attr($activity->id); ?>" <?php selected((int) ($tx->activity_id ?? 0), (int) $activity->id); ?>><?php echo esc_html($activity_names[(int) $activity->id]); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-small">Opslaan</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td data-filter-value="<?php echo esc_attr($display_status); ?>">
                    <?php if (!empty($tx->duplicate_of)) :
                        $duplicate_url = add_query_arg(['page' => 'avbk-transactions', 'show_all_years' => '1'], admin_url('admin.php')) . '#tx-' . (int) $tx->duplicate_of;
                    ?>Dubbele betaling van <a href="<?php echo esc_url($duplicate_url); ?>">#<?php echo esc_html($tx->duplicate_of); ?></a><?php
                    else :
                        echo esc_html($display_status);
                    endif; ?>
                    <?php if ($tx->direction === 'in' && $tx->status === 'ignored' && empty($tx->duplicate_of)) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:.35rem">
                            <?php wp_nonce_field('avbk_restore_ignored_transaction'); ?>
                            <input type="hidden" name="action" value="avbk_restore_ignored_transaction">
                            <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">
                            <button type="submit" class="button button-small">Opnieuw controleren</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td data-sort-value="<?php echo esc_attr(number_format($allocated, 2, '.', '')); ?>">
                    <?php if ($tx->direction !== 'in') : ?>
                        &mdash;
                    <?php elseif ($tx->status !== 'matched') : ?>
                        &mdash;
                    <?php elseif ($shortfall > 0.005) : ?>
                        <span style="color:#a00">&euro; <?php echo esc_html(number_format($allocated, 2, ',', '.')); ?> van &euro; <?php echo esc_html(number_format((float) $tx->amount, 2, ',', '.')); ?> &mdash; niet volledig toegewezen</span>
                    <?php else : ?>
                        &euro; <?php echo esc_html(number_format($allocated, 2, ',', '.')); ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
    <p class="description">Een rood gemarkeerde regel is <strong>gekoppeld</strong> aan een lid, maar het bedrag is niet (volledig) op een bijdrage afgeboekt &mdash; vaak doordat de betaling werd bevestigd vóórdat de bijbehorende bijdrage-regel bestond (bijv. een activiteitsbetaling vóór de aanmeldingen-sheet is geïmporteerd). Ga naar "Tweede controle" om zo'n transactie via "Terugzetten voor correctie" terug te zetten en opnieuw te bevestigen, nu de bijdrage wel bestaat.</p>
</div>
