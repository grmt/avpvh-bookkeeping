<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

// Included from the detail view, right after member-household-balance.php —
// reuses its $household_members (same household scope, no need to recompute).
//
// A credit transaction paid from one shared account can belong to several
// household members at once (see AVBK_DB::get_credit_transactions_for_member()'s
// own docblock) — summing each member's own total would double-count that
// same leftover once per person it touches. The household total here is
// deduplicated by transaction id instead.
$household_credit_transactions = [];
$household_credit_per_member = [];
foreach ($household_members as $household_id => $household_member) {
    $credit_rows = AVBK_DB::get_credit_transactions_for_member($household_id);
    $member_credit = 0.0;
    foreach ($credit_rows as $row) {
        $member_credit += $row->credit;
        if (!isset($household_credit_transactions[$row->id])) {
            $household_credit_transactions[$row->id] = $row;
        }
    }
    $household_credit_per_member[$household_id] = round($member_credit, 2);
}
$household_credit_total = round(array_sum(array_map(fn($r) => $r->credit, $household_credit_transactions)), 2);
?>
<?php if ($household_credit_total > 0.005) : ?>
<section aria-labelledby="avbk-household-credit-heading">
    <h3 id="avbk-household-credit-heading">Familie en huisgenoten — tegoeden</h3>
    <p class="description">
        Een tegoed van één gezamenlijke betaling kan bij meerdere personen tegelijk staan — het totaal hieronder telt zo'n gedeeld bedrag maar één keer mee, niet per persoon die het aanraakt.
    </p>
    <table id="avbk-household-credits" class="widefat striped">
        <thead><tr><th scope="col">Lid</th><th scope="col">Tegoed</th><th scope="col"><span class="screen-reader-text">Acties</span></th></tr></thead>
        <tbody>
        <?php foreach ($household_members as $household_id => $household_member) :
            if ($household_credit_per_member[$household_id] <= 0.005) {
                continue;
            }
            $household_detail_url = add_query_arg(
                ['page' => 'avbk-members', 'member_id' => $household_id],
                admin_url('admin.php')
            );
            ?>
            <tr>
                <td><a href="<?php echo esc_url($household_detail_url); ?>"><?php echo esc_html(avpvh_format_name($household_member)); ?></a><?php if ($household_id === $detail_member_id) : ?> (dit lid)<?php endif; ?></td>
                <td>&euro; <?php echo esc_html(number_format($household_credit_per_member[$household_id], 2, ',', '.')); ?></td>
                <td><a class="button button-small" href="<?php echo esc_url($household_detail_url); ?>">Bekijk rekening</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th scope="row">Totaal familie en huisgenoten</th><td>&euro; <?php echo esc_html(number_format($household_credit_total, 2, ',', '.')); ?></td><td></td></tr></tfoot>
    </table>
    <details style="margin:1rem 0">
        <summary><strong>Tegoed per transactie</strong></summary>
        <table id="avbk-household-credit-transactions" class="widefat striped" style="margin-top:.5rem">
            <thead><tr><th scope="col">Datum</th><th scope="col">Omschrijving</th><th scope="col">Nog beschikbaar</th></tr></thead>
            <tbody>
            <?php foreach ($household_credit_transactions as $row) : ?>
                <tr>
                    <td><?php echo esc_html(wp_date('d-m-Y', strtotime($row->transaction_date))); ?></td>
                    <td><?php echo esc_html($row->description ?: $row->counterparty_name); ?></td>
                    <td>&euro; <?php echo esc_html(number_format((float) $row->credit, 2, ',', '.')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
</section>
<?php endif; ?>
