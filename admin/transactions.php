<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$transactions = AVBK_DB::get_transactions();
$status_label = [
    'matched'   => 'Gekoppeld',
    'suggested' => 'Suggestie',
    'unmatched' => 'Niet gekoppeld',
    'ignored'   => 'Genegeerd',
];
?>
<div class="wrap">
    <h1>Alle transacties</h1>
    <p class="description">Elke geïmporteerde rij uit een bankexport, inclusief uitgaande betalingen (declaraties, boodschappen) &mdash; die worden nooit aan een lid gekoppeld, alleen bewaard voor het overzicht.</p>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr><th>Datum</th><th>Richting</th><th>Bedrag</th><th>Naam</th><th>Omschrijving</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php if (!$transactions) : ?>
            <tr><td colspan="6">Nog geen transacties geïmporteerd.</td></tr>
        <?php else : foreach ($transactions as $tx) : ?>
            <tr>
                <td><?php echo esc_html(wp_date('d M Y', strtotime($tx->transaction_date))); ?></td>
                <td><?php echo $tx->direction === 'in' ? 'Bij' : 'Af'; ?></td>
                <td>&euro; <?php echo esc_html(number_format((float) $tx->amount, 2, ',', '.')); ?></td>
                <td><?php echo esc_html($tx->counterparty_name); ?></td>
                <td><?php echo esc_html(mb_strimwidth($tx->description, 0, 100, '…')); ?></td>
                <td><?php echo esc_html($status_label[$tx->status] ?? $tx->status); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
