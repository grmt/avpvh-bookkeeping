<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$detail_member_id = (int) ($_GET['member_id'] ?? 0);
$members = AVPVH_DB::get_members(['status' => 'active']);
?>
<div class="wrap">
    <h1>Ledenoverzicht</h1>

    <?php if (isset($_GET['waived'])) : ?>
        <div class="notice notice-success"><p>Bijdrage kwijtgescholden.</p></div>
    <?php endif; ?>

    <?php if ($detail_member_id) :
        $member = AVPVH_DB::get_member($detail_member_id);
        if ($member) :
            $balance = AVBK_DB::get_member_balance($detail_member_id); ?>
            <h2><?php echo esc_html(avpvh_format_name($member)); ?></h2>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=avbk-members')); ?>">&larr; Terug naar ledenoverzicht</a></p>
            <table class="wp-list-table widefat striped">
                <thead><tr><th>Omschrijving</th><th>Bedrag</th><th>Betaald</th><th>Openstaand</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (!$balance['items']) : ?>
                    <tr><td colspan="6">Nog geen bijdragen geregistreerd.</td></tr>
                <?php else : foreach ($balance['items'] as $item) : ?>
                    <tr>
                        <td><?php echo esc_html($item->description); ?></td>
                        <td>&euro; <?php echo esc_html(number_format((float) $item->amount_due, 2, ',', '.')); ?></td>
                        <td>&euro; <?php echo esc_html(number_format((float) $item->paid, 2, ',', '.')); ?></td>
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
                    <tr><th colspan="3">Totaal openstaand</th><th colspan="3">&euro; <?php echo esc_html(number_format((float) $balance['balance'], 2, ',', '.')); ?></th></tr>
                </tfoot>
            </table>
        <?php endif;
    else : ?>
        <table class="wp-list-table widefat striped">
            <thead><tr><th>Naam</th><th>Openstaand saldo</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($members as $m) :
                $balance = AVBK_DB::get_member_balance((int) $m->id); ?>
                <tr>
                    <td><?php echo esc_html(avpvh_format_name($m, 'list')); ?></td>
                    <td<?php echo $balance['balance'] > 0.005 ? ' style="color:#b8600a;font-weight:bold"' : ''; ?>>
                        &euro; <?php echo esc_html(number_format($balance['balance'], 2, ',', '.')); ?>
                    </td>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=avbk-members&member_id=' . $m->id)); ?>">Details</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
