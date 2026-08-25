<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$pending = AVBK_DB::get_reimbursements('pending');
$paid = AVBK_DB::get_reimbursements('paid');
$rejected = AVBK_DB::get_reimbursements('rejected');
?>
<div class="wrap">
    <h1>Declaraties</h1>
    <p class="description">Bonnetjes die leden hebben ingediend om terugbetaald te krijgen. Scan de QR code met de bankieren-app om het bedrag over te maken, en klik daarna &ldquo;Betaald&rdquo;.</p>

    <?php if (isset($_GET['paid'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Gemarkeerd als betaald.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['rejected'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Afgewezen.</p></div>
    <?php endif; ?>

    <h2>Openstaand (<?php echo esc_html(count($pending)); ?>)</h2>
    <?php if (!$pending) : ?>
        <p>Niets openstaand.</p>
    <?php else : foreach ($pending as $r) :
        $member = AVPVH_DB::get_member((int) $r->member_id);
        $activity = $r->activity_id ? AVPVH_DB::get_activity((int) $r->activity_id) : null;
        $member_name = $member ? avpvh_format_name($member) : 'Onbekend lid';
        $remittance = 'Declaratie' . ($r->description ? ': ' . $r->description : '') . ($activity ? ' (' . $activity->name . ')' : '');
        $view_url = wp_nonce_url(add_query_arg(['action' => 'avbk_view_receipt', 'id' => $r->id], admin_url('admin-post.php')), 'avbk_view_receipt');
        $qr = AVBK_QR::for_reimbursement($r->iban, $member_name, (float) $r->amount, $remittance);
        ?>
        <div class="avbk-reimbursement-card">
            <table class="wp-list-table widefat striped" style="max-width:700px">
                <tbody>
                    <tr><th>Lid</th><td><?php echo esc_html($member_name); ?></td></tr>
                    <tr><th>Datum</th><td><?php echo esc_html(wp_date('d-m-Y H:i', strtotime($r->created_at))); ?></td></tr>
                    <tr><th>Activiteit</th><td><?php echo $activity ? esc_html($activity->name . ' (' . $activity->year . ')') : '&mdash;'; ?></td></tr>
                    <tr><th>Omschrijving</th><td><?php echo esc_html($r->description ?: '&mdash;'); ?></td></tr>
                    <tr><th>Bedrag</th><td>&euro; <?php echo esc_html(number_format((float) $r->amount, 2, ',', '.')); ?><?php echo $r->ocr_amount ? ' (OCR herkende € ' . esc_html(number_format((float) $r->ocr_amount, 2, ',', '.')) . ')' : ''; ?></td></tr>
                    <tr><th>Rekeningnummer</th><td><?php echo esc_html($r->iban); ?></td></tr>
                    <tr><th>Bonnetje</th><td><a href="<?php echo esc_url($view_url); ?>" target="_blank">bekijk foto</a></td></tr>
                </tbody>
            </table>

            <?php if ($qr) : ?>
                <div class="avbk-reimbursement-qr"><?php echo $qr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- server-rendered SVG from chillerlan/php-qrcode, not user input; esc_html() would break the markup. ?></div>
            <?php else : ?>
                <p class="description">Geen QR mogelijk &mdash; rekeningnummer ontbreekt of is ongeldig.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                <?php wp_nonce_field('avbk_mark_reimbursement_paid'); ?>
                <input type="hidden" name="action" value="avbk_mark_reimbursement_paid">
                <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                <?php submit_button('Betaald', 'primary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                <?php wp_nonce_field('avbk_reject_reimbursement'); ?>
                <input type="hidden" name="action" value="avbk_reject_reimbursement">
                <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                <?php submit_button('Afwijzen', 'secondary', 'submit', false, ['onclick' => "return confirm('Declaratie afwijzen?');"]); ?>
            </form>
        </div>
        <hr>
    <?php endforeach; endif; ?>

    <?php if ($paid || $rejected) : ?>
        <h2>Afgehandeld</h2>
        <table class="wp-list-table widefat striped">
            <thead><tr><th>Datum</th><th>Lid</th><th>Omschrijving</th><th>Bedrag</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach (array_merge($paid, $rejected) as $r) :
                $member = AVPVH_DB::get_member((int) $r->member_id); ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo esc_html(wp_date('d-m-Y', strtotime($r->created_at))); ?></td>
                    <td><?php echo $member ? esc_html(avpvh_format_name($member, 'list')) : '&mdash;'; ?></td>
                    <td><?php echo esc_html($r->description ?: '&mdash;'); ?></td>
                    <td>&euro; <?php echo esc_html(number_format((float) $r->amount, 2, ',', '.')); ?></td>
                    <td><?php echo $r->status === 'paid' ? 'Betaald' : 'Afgewezen'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
