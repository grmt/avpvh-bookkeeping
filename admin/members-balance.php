<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$detail_member_id = (int) ($_GET['member_id'] ?? 0);
$members = AVPVH_DB::get_members(['status' => 'active']);
$closed_through_year = (int) get_option('avbk_closed_through_year', 0);
$show_all_years = !empty($_GET['show_all_years']);
?>
<div class="wrap">
    <h1>Ledenoverzicht</h1>

    <?php if (isset($_GET['waived'])) : ?>
        <div class="notice notice-success"><p>Bijdrage kwijtgescholden.</p></div>
    <?php elseif (isset($_GET['payment_requested'])) : ?>
        <div class="notice notice-success"><p>Betaalverzoek verstuurd.</p></div>
    <?php elseif (isset($_GET['payment_request_failed'])) : ?>
        <div class="notice notice-error"><p>Kies minimaal één openstaande post en controleer of het lid een geldig e-mailadres heeft.</p></div>
    <?php endif; ?>

    <?php if ($detail_member_id) :
        $member = AVPVH_DB::get_member($detail_member_id);
        if ($member) :
            $balance = AVBK_DB::get_member_balance_excluding_closed($detail_member_id, $show_all_years); ?>
            <h2><?php echo esc_html(avpvh_format_name($member)); ?></h2>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=avbk-members')); ?>">&larr; Terug naar ledenoverzicht</a>
                &nbsp;|&nbsp;
                <a href="<?php echo esc_url(AVBK_DB::member_edit_url($detail_member_id)); ?>" target="_blank">Bewerk lid (o.a. scholier/student, geboortedatum)</a>
            </p>
            <?php if ($closed_through_year) : ?>
                <p class="description">
                    <?php if ($show_all_years) : ?>
                        Toont ook bijdragen tot en met <?php echo esc_html($closed_through_year); ?> (afgesloten).
                        <a href="<?php echo esc_url(remove_query_arg('show_all_years')); ?>">Verberg afgesloten jaren</a>.
                    <?php else : ?>
                        Bijdragen tot en met <?php echo esc_html($closed_through_year); ?> zijn afgesloten en worden hier verborgen.
                        <a href="<?php echo esc_url(add_query_arg('show_all_years', '1')); ?>">Toon oudere jaren</a>.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <?php $student_years = AVBK_DB::get_member_student_years($detail_member_id); ?>
            <?php if (isset($_GET['student_year_saved'])) : ?><div class="notice notice-success inline"><p>Studentstatus op 1 januari opgeslagen. Genereer de betreffende bijdrage opnieuw om het bedrag bij te werken.</p></div><?php endif; ?>
            <details style="margin:1rem 0"<?php echo isset($_GET['student_year_saved']) ? ' open' : ''; ?>>
                <summary><strong>Historische studentstatus op 1 januari</strong></summary>
                <p class="description">Een vastgelegde jaarstatus gaat voor op het huidige studentvinkje en wordt gebruikt voor alle activiteiten in dat kalenderjaar.</p>
                <?php if ($student_years) : ?>
                    <table class="widefat striped" style="max-width:520px;margin:.5rem 0"><thead><tr><th>Jaar</th><th>Status op 1 januari</th><th></th></tr></thead><tbody>
                    <?php foreach ($student_years as $student_year) : ?><tr>
                        <td><?php echo esc_html($student_year->year); ?></td>
                        <td><?php echo $student_year->is_student ? 'Scholier/student' : 'Geen scholier/student'; ?></td>
                        <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('avbk_delete_student_year'); ?>
                            <input type="hidden" name="action" value="avbk_delete_student_year"><input type="hidden" name="member_id" value="<?php echo esc_attr($detail_member_id); ?>"><input type="hidden" name="year" value="<?php echo esc_attr($student_year->year); ?>">
                            <button class="button button-small" type="submit">Verwijderen</button>
                        </form></td>
                    </tr><?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avbk_save_student_year'); ?>
                    <input type="hidden" name="action" value="avbk_save_student_year"><input type="hidden" name="member_id" value="<?php echo esc_attr($detail_member_id); ?>">
                    <label>Jaar <input type="number" name="year" min="1900" max="2200" value="<?php echo esc_attr(current_time('Y')); ?>" required style="width:7em"></label>
                    <label style="margin-left:1rem"><input type="checkbox" name="is_student" value="1"> Scholier/student op 1 januari</label>
                    <button class="button" type="submit">Jaarstatus opslaan</button>
                </form>
            </details>
            <table class="wp-list-table widefat striped">
                <thead><tr><th>Kies</th><th>Omschrijving</th><th>Bedrag</th><th>Betaald</th><th>Betaling(en)</th><th>Openstaand</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (!$balance['items']) : ?>
                    <tr><td colspan="8">Nog geen bijdragen geregistreerd.</td></tr>
                <?php else : foreach ($balance['items'] as $item) :
                    $payments = AVBK_DB::get_payments_for_fee_item((int) $item->id);
                    ?>
                    <tr>
                        <td>
                            <?php if ($item->status === 'open' && $item->remaining > 0.005) : ?>
                                <input type="checkbox" name="fee_item_ids[]" value="<?php echo esc_attr($item->id); ?>" form="avbk-payment-selection">
                            <?php else : ?>&mdash;<?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html($item->description); ?>
                            <?php if (!empty($item->is_estimated)) : ?>
                                <?php $estimate_reason = $item->estimate_reason ?: 'Geschat bedrag.';
                                $estimate_is_warning = !str_starts_with($estimate_reason, 'Alleen geboortejaar '); ?>
                                <br><span<?php echo $estimate_is_warning ? ' style="color:#b32d2e;font-weight:600"' : ' style="color:#646970"'; ?>><?php echo $estimate_is_warning ? '&#9888; ' : ''; ?><?php echo esc_html($estimate_reason); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>&euro; <?php echo esc_html(number_format((float) $item->amount_due, 2, ',', '.')); ?></td>
                        <td>&euro; <?php echo esc_html(number_format((float) $item->paid, 2, ',', '.')); ?></td>
                        <td>
                            <?php if (!$payments) : ?>
                                &mdash;
                            <?php else : foreach ($payments as $payment) :
                                $transaction_url = add_query_arg(
                                    ['page' => 'avbk-transactions', 'show_all_years' => '1'],
                                    admin_url('admin.php')
                                ) . '#tx-' . (int) $payment->transaction_id;
                                ?>
                                <div>
                                    <a href="<?php echo esc_url($transaction_url); ?>">
                                        <?php echo esc_html(wp_date('d-m-Y', strtotime($payment->transaction_date))); ?>
                                        &mdash; transactie #<?php echo esc_html($payment->transaction_id); ?>
                                    </a>
                                    (&euro; <?php echo esc_html(number_format((float) $payment->allocated_amount, 2, ',', '.')); ?>)
                                </div>
                            <?php endforeach; endif; ?>
                        </td>
                        <td>&euro; <?php echo esc_html(number_format((float) $item->remaining, 2, ',', '.')); ?></td>
                        <td><?php echo $item->status === 'waived' ? 'Kwijtgescholden' : ($item->remaining <= 0.005 ? 'Betaald' : 'Open'); ?></td>
                        <td>
                            <?php if ($item->status === 'open' && $item->remaining > 0.005) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                    <?php wp_nonce_field('avbk_waive_fee_item'); ?>
                                    <input type="hidden" name="action" value="avbk_waive_fee_item">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($item->id); ?>">
                                    <input type="hidden" name="member_id" value="<?php echo esc_attr($detail_member_id); ?>">
                                    <button type="submit" class="button button-small" onclick="return confirm('Deze bijdrage kwijtschelden?');">Kwijtschelden</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr><th colspan="5">Totaal openstaand</th><th colspan="3">&euro; <?php echo esc_html(number_format((float) $balance['balance'], 2, ',', '.')); ?></th></tr>
                </tfoot>
            </table>
            <?php if ($balance['balance'] > 0.005) :
                $household_ids = array_values(array_filter(array_map(
                    fn($household_member) => (int) $household_member->id,
                    AVPVH_DB::get_extended_household($detail_member_id)
                ), fn($household_id) => $household_id !== $detail_member_id));
                $full_balance_url = add_query_arg(
                    ['member_id' => $detail_member_id, 'also' => $household_ids],
                    home_url('/leden/beheer/member-profile/')
                ) . '#bijdrage';
                ?>
                <p>
                    <a class="button" href="<?php echo esc_url($full_balance_url); ?>">Volledige rekening, huisgenoten en QR</a>
                </p>
                <form id="avbk-payment-selection" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avbk_request_balance_payment'); ?>
                    <input type="hidden" name="action" value="avbk_request_balance_payment">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($detail_member_id); ?>">
                    <p class="description">Vink één of meerdere openstaande posten aan.</p>
                    <button type="submit" name="request_mode" value="show" class="button">Bekijk selectie en maak QR</button>
                    <button type="submit" name="request_mode" value="mail" class="button button-primary">Mail betaalverzoek voor selectie</button>
                </form>
            <?php endif; ?>
        <?php endif;
    else : ?>
        <p class="description">Zoek of filter in de velden onder de kolomkoppen. Klik op <strong>Naam</strong>, <strong>Openstaand saldo</strong> of <strong>Status</strong> om te sorteren.</p>
        <?php if ($closed_through_year) : ?>
            <p class="description">
                <?php if ($show_all_years) : ?>
                    Toont ook bijdragen tot en met <?php echo esc_html($closed_through_year); ?> (afgesloten).
                    <a href="<?php echo esc_url(remove_query_arg('show_all_years')); ?>">Verberg afgesloten jaren</a>.
                <?php else : ?>
                    Bijdragen tot en met <?php echo esc_html($closed_through_year); ?> zijn afgesloten en tellen hier niet meer mee.
                    <a href="<?php echo esc_url(add_query_arg('show_all_years', '1')); ?>">Toon oudere jaren</a>.
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <div class="avbk-balance-table-tools">
            <button type="button" class="button button-small avbk-col-toggle-btn">Kolommen</button>
            <div class="avbk-col-toggle-panel" hidden></div>
        </div>
        <div class="avbk-balance-table-wrap">
        <table id="avbk-balance-table" data-storage-key="avbk_members_hidden_cols" class="wp-list-table widefat striped avbk-balance-table">
            <thead><tr class="avbk-balance-header-row"><th data-col="naam">Naam</th><th data-col="saldo" data-type="number">Openstaand saldo</th><th data-col="status" data-filter="select">Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($members as $m) :
                $balance = AVBK_DB::get_member_balance_excluding_closed((int) $m->id, $show_all_years);
                $has_estimate_warning = (bool) array_filter($balance['items'], fn($i) =>
                    !empty($i->is_estimated)
                    && $i->status === 'open'
                    && !str_starts_with((string) $i->estimate_reason, 'Alleen geboortejaar ')
                );
                $balance_status = $balance['balance'] > 0.005 ? 'Openstaand' : 'Betaald';
                ?>
                <tr>
                    <td><?php echo esc_html(avpvh_format_name($m, 'list')); ?></td>
                    <td<?php echo $balance['balance'] > 0.005 ? ' style="color:#b8600a;font-weight:bold"' : ''; ?>>
                        &euro; <?php echo esc_html(number_format($balance['balance'], 2, ',', '.')); ?>
                        <?php if ($has_estimate_warning) : ?>
                            <span style="color:#b32d2e" title="Bevat een geschat bedrag (geen geboortedatum bekend)">&#9888;</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($balance_status); ?></td>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=avbk-members&member_id=' . $m->id)); ?>">Details</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
