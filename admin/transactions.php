<?php
defined('ABSPATH') || exit;

/** Renders the admin/transactions.php page. */
function avbk_render_transactions(): void {
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
$context = compact('activities', 'activity_names', 'status_label', 'ignore_reason_label', 'show_all_years', 'closed_through_year');
?>
<div class="wrap">
    <h1>Alle transacties</h1>
    <p class="description">Elke geïmporteerde rij uit een bankexport, inclusief uitgaande betalingen (declaraties, boodschappen) &mdash; die worden nooit aan een lid gekoppeld, alleen bewaard voor het overzicht.</p>
    <?php avbk_render_transactions_tagged_notice(); ?>
    <?php avbk_render_transactions_closed_years($closed_through_year, $show_all_years); ?>
    <p class="description">Zoek of filter in de velden onder de kolomkoppen. Klik op een kolomkop om te sorteren.</p>
    <div data-avpvh-sortable>
    <div class="avpvh-table-tools">
        <button type="button" class="button button-small avpvh-col-toggle-btn">Kolommen</button>
        <span class="avpvh-col-toggle-panel" hidden></span>
    </div>
    <div class="avpvh-table-wrap">
    <table data-storage-key="avbk_transactions_hidden_cols" class="widefat striped avpvh-sortable-table">
        <thead>
            <tr>
                <th scope="col" data-col="regel" data-type="number">Regel</th>
                <th scope="col" data-col="import" data-type="number">Import</th>
                <th scope="col" data-col="datum" data-type="date">Datum</th>
                <th scope="col" data-col="richting" data-filter="select">Richting</th>
                <th scope="col" data-col="bedrag" data-type="number">Bedrag</th>
                <th scope="col" data-col="naam">Naam</th>
                <th scope="col" data-col="omschrijving">Omschrijving</th>
                <th scope="col" data-col="activiteit" data-filter="select">Activiteit</th>
                <th scope="col" data-col="status" data-filter="select">Status</th>
                <th scope="col" data-col="toegewezen" data-type="number">Toegewezen</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$transactions) : ?>
            <tr><td colspan="10">Nog geen transacties geïmporteerd.</td></tr>
        <?php else : foreach ($transactions as $tx) : ?>
            <?php avbk_render_transaction_row($tx, $context); ?>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
    </div>
    <p class="description">Een rood gemarkeerde regel is <strong>gekoppeld</strong> aan een lid, maar het bedrag is niet (volledig) op een bijdrage afgeboekt &mdash; vaak doordat de betaling werd bevestigd vóórdat de bijbehorende bijdrage-regel bestond (bijv. een activiteitsbetaling vóór de aanmeldingen-sheet is geïmporteerd). Ga naar "Tweede controle" om zo'n transactie via "Terugzetten voor correctie" terug te zetten en opnieuw te bevestigen, nu de bijdrage wel bestaat.</p>
</div>
<?php
}

/** Result notice of tagging an activity. */
function avbk_render_transactions_tagged_notice(): void {
    if (isset($_GET['activity_tagged'])) : ?>
        <div class="notice <?php echo $_GET['activity_tagged'] === '1' ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo $_GET['activity_tagged'] === '1' ? 'Activiteit opgeslagen.' : 'Activiteit niet gewijzigd: deze transactie heeft al een toewijzingsregel.'; ?></p></div>
    <?php endif; ?>
<?php
}

/**
 * One transaction row.
 *
 * @param array<string, mixed> $context $activities, $activity_names, $status_label, $ignore_reason_label, $show_all_years, $closed_through_year.
 */
function avbk_render_transaction_row(object $tx, array $context): void {
    ['activities' => $activities, 'activity_names' => $activity_names, 'status_label' => $status_label, 'ignore_reason_label' => $ignore_reason_label, 'show_all_years' => $show_all_years, 'closed_through_year' => $closed_through_year] = $context;
    // "Gekoppeld" only means a member was identified — it says
    // nothing about whether the bedrag actually landed on a
    // bijdrage. Surface that separately so a payment confirmed
    // before its bijdrage-regel existed (silently €0 toegewezen —
    // see incident 2026-08-29) is visible here instead of only
    // discoverable by checking the ledenoverzicht by hand.
    $summary = avbk_transaction_allocation_summary($tx, $activity_names);
    $shortfall = $tx->direction === 'in' && $tx->status === 'matched' ? round((float) $tx->amount - $summary['allocated'], 2) : 0.0;
    $display_status = avbk_transaction_display_status($tx, $status_label, $ignore_reason_label);
?><tr id="tx-<?php echo esc_attr($tx->id); ?>"<?php echo $shortfall > 0.005 ? ' style="background:#fcf0f1"' : ''; ?>>
                <td data-sort-value="<?php echo esc_attr((int) $tx->source_row); ?>"><?php echo $tx->source_row ? esc_html($tx->source_row) : '&mdash;'; ?></td>
                <td data-sort-value="<?php echo esc_attr((int) $tx->import_batch_id); ?>">
                    <?php avbk_render_transaction_import($tx); ?>
                </td>
                <td data-sort-value="<?php echo esc_attr($tx->transaction_date); ?>"><?php echo esc_html(wp_date('D d M Y', strtotime($tx->transaction_date))); ?></td>
                <td><?php echo $tx->direction === 'in' ? 'Bij' : 'Af'; ?></td>
                <td data-sort-value="<?php echo esc_attr(number_format((float) $tx->amount, 2, '.', '')); ?>">&euro; <?php echo esc_html(number_format((float) $tx->amount, 2, ',', '.')); ?></td>
                <td><?php echo esc_html($tx->counterparty_name); ?></td>
                <?php $clean_description = AVBK_Matcher::strip_name_field($tx->description); ?>
                <td data-filter-value="<?php echo esc_attr($clean_description); ?>"><?php echo esc_html(mb_strimwidth($clean_description, 0, 100, '…')); ?></td>
                <?php [$tag_label, $display_parts] = avbk_transaction_activity_label($tx, $summary, $activity_names); ?>
                <td data-filter-value="<?php echo esc_attr($tag_label); ?>">
                    <?php avbk_render_transaction_activity($summary['allocations'], $display_parts, $tag_label, $tx, $show_all_years, $activities, $activity_names); ?>
                </td>
                <td data-filter-value="<?php echo esc_attr($display_status); ?>">
                    <?php avbk_render_transaction_status($tx, $display_status); ?>
                </td>
                <td data-sort-value="<?php echo esc_attr(number_format($summary['allocated'], 2, '.', '')); ?>">
                    <?php avbk_render_transaction_allocated($tx, $shortfall, $closed_through_year, $summary['allocated']); ?>
                </td>
            </tr>
<?php
}

/**
 * What a transaction's allocations add up to: the allocated amount (only for
 * a matched incoming payment), the activities, a donation and merchandise
 * orders they cover.
 *
 * @param array<int, string> $activity_names
 * @return array{allocations: object[], allocated: float, activity_names: array<int, string>, donation: bool, order_links: array<int, array{label: string, url: string}>}
 */
function avbk_transaction_allocation_summary(object $tx, array $activity_names): array {
    $allocations = AVBK_DB::get_allocations_for_transaction((int) $tx->id);
    $allocated = $tx->direction === 'in' && $tx->status === 'matched'
        ? array_reduce($allocations, static fn(float $sum, $a) => $sum + (float) $a->amount, 0.0)
        : 0.0;
    $allocated_activity_names = [];
    $has_donation_allocation = false;
    $allocated_order_links = [];
    foreach ($allocations as $allocation) {
        $fee_item = AVBK_DB::get_fee_item((int) $allocation->fee_item_id);
        if (!$fee_item) {
            continue;
        }
        if (!empty($fee_item->activity_id) && isset($activity_names[(int) $fee_item->activity_id])) {
            $allocated_activity_names[(int) $fee_item->activity_id] = $activity_names[(int) $fee_item->activity_id];
        }
        if (($fee_item->category ?? '') === 'Schenking') {
            $has_donation_allocation = true;
        }
        $order_link = avbk_transaction_order_link($fee_item);
        if ($order_link) {
            $allocated_order_links[$order_link['order_id']] = ['label' => $order_link['label'], 'url' => $order_link['url']];
        }
    }
    return ['allocations' => $allocations, 'allocated' => $allocated, 'activity_names' => $allocated_activity_names, 'donation' => $has_donation_allocation, 'order_links' => $allocated_order_links];
}

/**
 * "Toegewezen zonder activiteit" is wrong for a boek-/t-shirtbestelling — die
 * ís toegewezen, alleen niet aan een avb_activities-rij (fee_item->activity_id
 * blijft leeg voor merchandise). Link in plaats daarvan door naar de
 * bestelling zelf.
 *
 * @return array{order_id: int, label: string, url: string}|null
 */
function avbk_transaction_order_link(object $fee_item): ?array {
    if (!in_array($fee_item->category ?? '', ['tshirt', 'book'], true)) {
        return null;
    }
    $order = AVBK_DB::get_order_by_fee_item((int) $fee_item->id);
    if (!$order) {
        return null;
    }
    return [
        'order_id' => (int) $order->id,
        'label' => $fee_item->category === 'tshirt' ? 'T-shirt' : 'Jubileumboek',
        'url'   => add_query_arg(['page' => 'avbk-orders', 'tab' => $fee_item->category], admin_url('admin.php')) . '#order-' . (int) $order->id,
    ];
}

/**
 * The activity label of a transaction (for filtering) and its display parts
 * (activities, order links, donation).
 *
 * @param array<string, mixed> $summary  From avbk_transaction_allocation_summary().
 * @param array<int, string>   $activity_names
 * @return array{0: string, 1: string[]}
 */
function avbk_transaction_activity_label(object $tx, array $summary, array $activity_names): array {
    $label_parts = [];
    $display_parts = [];
    if ($summary['activity_names']) {
        $label_parts[] = implode(', ', $summary['activity_names']);
        $display_parts[] = esc_html(implode(', ', $summary['activity_names']));
    }
    foreach ($summary['order_links'] as $link) {
        $label_parts[] = $link['label'];
        $display_parts[] = '<a href="' . esc_url($link['url']) . '">' . esc_html($link['label']) . '</a>';
    }
    if ($summary['donation']) {
        $label_parts[] = 'Schenking';
        $display_parts[] = 'Schenking';
    }
    if ($summary['allocations']) {
        return [$label_parts ? implode(', ', $label_parts) : 'Toegewezen zonder activiteit', $display_parts];
    }
    $tag_label = !empty($tx->activity_id) && isset($activity_names[(int) $tx->activity_id]) ? $activity_names[(int) $tx->activity_id] : 'Geen activiteit';
    return [$tag_label, $display_parts];
}

/**
 * @param array<string, string> $status_label
 * @param array<string, string> $ignore_reason_label
 */
function avbk_transaction_display_status(object $tx, array $status_label, array $ignore_reason_label): string {
    $display_status = !empty($tx->duplicate_of) ? 'Duplicaat' : ($status_label[$tx->status] ?? $tx->status);
    if ($tx->status === 'ignored' && isset($ignore_reason_label[$tx->ignore_reason])) {
        $display_status .= ' — ' . $ignore_reason_label[$tx->ignore_reason];
    }
    return $display_status;
}

/** Notice about hidden closed years. */
function avbk_render_transactions_closed_years($closed_through_year, $show_all_years): void {
    if ($closed_through_year) : ?>
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
<?php
}

/** The import batch a transaction came from. */
function avbk_render_transaction_import($tx): void {
    if ($tx->import_batch_id) : ?>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-import'], admin_url('admin.php'))); ?>" title="<?php echo esc_attr($tx->import_uploaded_at ?: ''); ?>">#<?php echo esc_html($tx->import_batch_id); ?></a>
                        <?php if (!empty($tx->import_filename)) : ?><br><span class="description"><?php echo esc_html($tx->import_filename); ?></span><?php endif; ?>
                    <?php else : ?>&mdash;<?php endif; ?>
<?php
}

/** The activity cell: what the payment is allocated to, or a form to tag an activity. */
function avbk_render_transaction_activity($allocations, $display_parts, $tag_label, $tx, $show_all_years, $activities, $activity_names): void {
    if ($allocations) : ?>
                        <?php echo $display_parts ? wp_kses_post(implode(', ', $display_parts)) : esc_html($tag_label); ?>
                    <?php elseif ($tx->direction !== 'in') : ?>
                        &mdash;
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:.25rem;align-items:center">
                            <?php wp_nonce_field('avbk_set_transaction_activity'); ?>
                            <input type="hidden" name="action" value="avbk_set_transaction_activity">
                            <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">
                            <input type="hidden" name="show_all_years" value="<?php echo $show_all_years ? '1' : ''; ?>">
                            <select name="activity_id" style="max-width:17em" aria-label="<?php echo esc_attr(sprintf('Koppel transactie #%d aan activiteit', $tx->id)); ?>">
                                <option value="0">&mdash; geen activiteit &mdash;</option>
                                <?php foreach ($activities as $activity) : ?>
                                    <option value="<?php echo esc_attr($activity->id); ?>" <?php selected((int) ($tx->activity_id ?? 0), (int) $activity->id); ?>><?php echo esc_html($activity_names[(int) $activity->id]); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-small">Opslaan</button>
                        </form>
                    <?php endif; ?>
<?php
}

/** The status of a transaction, with a restore form for an ignored payment. */
function avbk_render_transaction_status($tx, $display_status): void {
    if (!empty($tx->duplicate_of)) :
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
<?php
}

/** The allocated amount of a transaction, red with a link when it falls short. */
function avbk_render_transaction_allocated($tx, $shortfall, $closed_through_year, $allocated): void {
    if ($tx->direction !== 'in') : ?>
                        &mdash;
                    <?php elseif ($tx->status !== 'matched') : ?>
                        &mdash;
                    <?php elseif ($shortfall > 0.005) :
                        $tx_year = (int) wp_date('Y', strtotime($tx->transaction_date));
                        $second_approval_args = ['page' => 'avbk-second-approval'];
                        if ($closed_through_year && $tx_year <= $closed_through_year) {
                            $second_approval_args['show_all_years'] = '1';
                        }
                        $second_approval_url = add_query_arg($second_approval_args, admin_url('admin.php')) . '#tx-' . (int) $tx->id;
                        ?>
                        <a href="<?php echo esc_url($second_approval_url); ?>" style="color:#a00">&euro; <?php echo esc_html(number_format($allocated, 2, ',', '.')); ?> van &euro; <?php echo esc_html(number_format((float) $tx->amount, 2, ',', '.')); ?> &mdash; niet volledig toegewezen (verschil: &euro; <?php echo esc_html(number_format($shortfall, 2, ',', '.')); ?>)</a>
                    <?php else : ?>
                        &euro; <?php echo esc_html(number_format($allocated, 2, ',', '.')); ?>
                    <?php endif; ?>
<?php
}
