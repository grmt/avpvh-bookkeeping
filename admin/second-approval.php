<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$closed_through_year = (int) get_option('avbk_closed_through_year', 0);
$show_all_years = !empty($_GET['show_all_years']);
$pending = AVBK_DB::get_transactions_pending_second_approval($show_all_years || !$closed_through_year ? 0 : $closed_through_year + 1);
$current_user_id = get_current_user_id();
?>
<div class="wrap">
    <h1>Tweede controle</h1>
    <p class="description">Vier-ogen-principe: elke gekoppelde betaling moet, naast degene die 'm heeft bevestigd, ook door een <strong>andere</strong> persoon worden goedgekeurd. Dit verandert niets aan de betaling zelf &mdash; die is al verwerkt.</p>
    <?php if ($closed_through_year) : ?>
        <p class="description">
            <?php if ($show_all_years) : ?>
                Toont ook betalingen tot en met <?php echo esc_html($closed_through_year); ?> (afgesloten).
                <a href="<?php echo esc_url(remove_query_arg('show_all_years')); ?>">Verberg afgesloten jaren</a>.
            <?php else : ?>
                Betalingen tot en met <?php echo esc_html($closed_through_year); ?> zijn afgesloten en worden hier verborgen.
                <a href="<?php echo esc_url(add_query_arg('show_all_years', '1')); ?>">Toon oudere jaren</a>.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if (isset($_GET['approved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Goedgekeurd.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['approve_error'])) : ?>
        <div class="notice notice-error is-dismissible"><p>Kon niet goedkeuren &mdash; waarschijnlijk ben jij zelf degene die deze betaling heeft bevestigd.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['reverted'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Teruggezet naar "Te controleren" &mdash; de toewijzing is ongedaan gemaakt.</p></div>
    <?php endif; ?>

    <h2>Wachten op tweede akkoord (<?php echo esc_html(count($pending)); ?>)</h2>
    <?php if (!$pending) : ?>
        <p>Niets wachtend op een tweede akkoord.</p>
    <?php else : foreach ($pending as $tx) :
        $confirmer = $tx->confirmed_by ? get_userdata((int) $tx->confirmed_by) : null;
        $is_own = $tx->confirmed_by && (int) $tx->confirmed_by === $current_user_id;
        $allocations = AVBK_DB::get_allocations_for_transaction((int) $tx->id);
        ?>
        <div class="avbk-review-row" id="tx-<?php echo esc_attr($tx->id); ?>">
            <table class="wp-list-table widefat striped" style="max-width:700px">
                <tbody>
                    <tr><th>Excel regel</th><td><?php echo $tx->source_row ? esc_html($tx->source_row) : '&mdash;'; ?></td></tr>
                    <tr><th>Datum</th><td><?php echo esc_html(wp_date('d-m-Y', strtotime($tx->transaction_date))); ?></td></tr>
                    <tr><th>Bedrag</th><td>&euro; <?php echo esc_html(number_format((float) $tx->amount, 2, ',', '.')); ?></td></tr>
                    <tr><th>Naam</th><td><?php echo esc_html($tx->counterparty_name); ?></td></tr>
                    <tr><th>Omschrijving</th><td><?php echo AVBK_Matcher::format_description_html(AVBK_Matcher::strip_name_field($tx->description)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format_description_html() esc_html()'s the raw text first, then only wraps already-safe hardcoded labels in <strong>. ?></td></tr>
                    <tr><th>Bevestigd door</th><td><?php echo $confirmer ? esc_html($confirmer->display_name) : '&mdash; (automatisch bij import, of van vóór deze functie)'; ?></td></tr>
                    <tr><th>Toegewezen aan</th><td>
                        <?php if (!$allocations) : ?>
                            &mdash;
                        <?php else : foreach ($allocations as $a) :
                            $member = AVPVH_DB::get_member((int) $a->member_id);
                            $fee_item = AVBK_DB::get_fee_item((int) $a->fee_item_id);
                            ?>
                            <?php echo esc_html(($member ? avpvh_format_name($member, 'list') : 'onbekend lid') . ($fee_item ? ' — ' . $fee_item->description : '') . ': € ' . number_format((float) $a->amount, 2, ',', '.')); ?><br>
                        <?php endforeach; endif; ?>
                    </td></tr>
                </tbody>
            </table>

            <?php if ($is_own) : ?>
                <p class="description">Jij hebt deze betaling zelf bevestigd &mdash; een andere penningmeester/bestuurslid moet 'm goedkeuren.</p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                    <?php wp_nonce_field('avbk_second_approve_transaction'); ?>
                    <input type="hidden" name="action" value="avbk_second_approve_transaction">
                    <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">
                    <?php submit_button('Akkoord', 'primary', 'submit', false); ?>
                </form>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                <?php wp_nonce_field('avbk_revert_transaction_to_review'); ?>
                <input type="hidden" name="action" value="avbk_revert_transaction_to_review">
                <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">
                <?php if ($show_all_years) : ?><input type="hidden" name="show_all_years" value="1"><?php endif; ?>
                <?php submit_button('Terugzetten voor correctie', 'secondary', 'submit', false, ['onclick' => "return confirm('Toewijzing ongedaan maken en terugzetten naar \\'Te controleren\\'?');"]); ?>
            </form>
        </div>
        <hr>
    <?php endforeach; endif; ?>
</div>
