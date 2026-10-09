<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

// Included from the detail view only. Use the same household scope as the
// existing combined balance/QR page; do not infer relationships from names.
$household_members = [$detail_member_id => $member];
foreach (AVPVH_DB::get_extended_household($detail_member_id) as $household_member) {
    $household_id = (int) $household_member->id;
    if ($household_id > 0 && $household_id !== $detail_member_id) {
        $household_members[$household_id] = $household_member;
    }
}
$household_open_items = [];
$household_open_totals = [];
$household_total = 0.0;
foreach ($household_members as $household_id => $household_member) {
    $household_balance = $household_id === $detail_member_id
        ? $by_year
        : AVBK_DB::get_member_balance_excluding_closed($household_id, $show_all_years);
    // Unlike the individual detail table, this section includes all open
    // book years. Count payable items, not a net balance that could hide
    // an unpaid fee behind an overpayment on another item.
    $open_items = array_values(array_filter(
        $household_balance['items'],
        fn($item) => $item->status === 'open' && (float) $item->remaining > 0.005
    ));
    $open_total = round(array_sum(array_map(fn($item) => (float) $item->remaining, $open_items)), 2);
    $household_open_totals[$household_id] = ['count' => count($open_items), 'amount' => $open_total];
    $household_total += $open_total;
    foreach ($open_items as $open_item) {
        $household_open_items[] = ['member' => $household_member, 'item' => $open_item];
    }
}
$household_total = round($household_total, 2);
$household_ids = array_values(array_filter(array_keys($household_members), fn($id) => $id !== $detail_member_id));
$full_balance_url = add_query_arg(
    ['member_id' => $detail_member_id, 'also' => $household_ids],
    home_url('/leden/beheer/member-profile/')
) . '#bijdrage';
?>
<section aria-labelledby="avbk-household-heading">
    <h3 id="avbk-household-heading">Familie en huisgenoten — openstaande posten</h3>
    <p class="description">
        Dit overzicht bevat alle openstaande posten van dit lid en de gekoppelde familieleden en huisgenoten, niet alleen boekjaar <?php echo esc_html($current_book_year); ?>.
        <?php if ($closed_through_year && !$show_all_years) : ?>
            Afgesloten jaren tot en met <?php echo esc_html($closed_through_year); ?> zijn verborgen.
            <a href="<?php echo esc_url(add_query_arg(['show_all_years' => '1', 'per_year' => '1'])); ?>">Toon ook afgesloten jaren</a>.
        <?php elseif ($closed_through_year) : ?>
            Afgesloten jaren zijn hier ook zichtbaar; de actuele rekening en QR hieronder bevatten deze niet.
            <a href="<?php echo esc_url(remove_query_arg('show_all_years')); ?>">Verberg afgesloten jaren</a>.
        <?php endif; ?>
    </p>
    <?php if (count($household_members) === 1) : ?>
        <p class="description">Er zijn geen andere familieleden of huisgenoten gekoppeld in de ledenadministratie.</p>
    <?php endif; ?>
    <table id="avbk-household-balances" class="widefat striped">
        <thead><tr><th>Lid</th><th>Openstaande posten</th><th>Openstaand bedrag</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($household_members as $household_id => $household_member) :
            $household_detail_url = add_query_arg(
                ['page' => 'avbk-members', 'member_id' => $household_id, 'per_year' => '1', 'show_all_years' => $show_all_years ? '1' : false],
                admin_url('admin.php')
            );
            ?>
            <tr>
                <td><a href="<?php echo esc_url($household_detail_url); ?>"><?php echo esc_html(avpvh_format_name($household_member)); ?></a><?php if ($household_id === $detail_member_id) : ?> (dit lid)<?php endif; ?></td>
                <td><?php echo esc_html($household_open_totals[$household_id]['count']); ?></td>
                <td>&euro; <?php echo esc_html(number_format($household_open_totals[$household_id]['amount'], 2, ',', '.')); ?></td>
                <td><a class="button button-small" href="<?php echo esc_url($household_detail_url); ?>">Bekijk rekening</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th>Totaal familie en huisgenoten</th><th><?php echo esc_html(count($household_open_items)); ?></th><th>&euro; <?php echo esc_html(number_format($household_total, 2, ',', '.')); ?></th><th></th></tr></tfoot>
    </table>
    <?php if ($household_open_items) : ?>
        <details open style="margin:1rem 0">
            <summary><strong>Openstaande posten per persoon</strong></summary>
            <table id="avbk-household-open-items" class="widefat striped" style="margin-top:.5rem">
                <thead><tr><th>Lid</th><th>Boekjaar</th><th>Omschrijving</th><th>Openstaand</th></tr></thead>
                <tbody>
                <?php foreach ($household_open_items as $entry) : ?>
                    <tr>
                        <td><?php echo esc_html(avpvh_format_name($entry['member'])); ?></td>
                        <td><?php echo esc_html(AVBK_DB::fee_item_book_year($entry['item'])); ?></td>
                        <td><?php echo esc_html($entry['item']->description); ?></td>
                        <td>&euro; <?php echo esc_html(number_format((float) $entry['item']->remaining, 2, ',', '.')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php else : ?>
        <p>Geen openstaande posten voor dit lid en de gekoppelde familieleden en huisgenoten binnen de getoonde jaren.</p>
    <?php endif; ?>
    <p><a class="button" href="<?php echo esc_url($full_balance_url); ?>">Volledige rekening, huisgenoten en QR</a></p>
</section>
