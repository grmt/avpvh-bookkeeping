<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$pending = AVBK_DB::get_reimbursements('pending');
$paid = AVBK_DB::get_reimbursements('paid');
$rejected = AVBK_DB::get_reimbursements('rejected');
$withdrawn = AVBK_DB::get_reimbursements('withdrawn');
$activities = AVPVH_DB::get_activities();
$all_known_ibans = AVBK_DB::get_all_known_ibans();
?>
<div class="wrap">
    <h1>Declaraties</h1>

    <datalist id="avbk-all-ibans">
        <?php foreach ($all_known_ibans as $known) : ?>
            <option value="<?php echo esc_attr($known->iban); ?>"><?php echo esc_html($known->account_name ?: $known->iban); ?></option>
        <?php endforeach; ?>
    </datalist>
    <p class="description">Bonnetjes die leden hebben ingediend om terugbetaald te krijgen. Scan de QR code met de bankieren-app om het bedrag over te maken, en klik daarna &ldquo;Betaald&rdquo;.</p>

    <?php if (isset($_GET['paid'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Gemarkeerd als betaald.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['rejected'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Afgewezen.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Wijziging opgeslagen.</p></div>
    <?php endif; ?>

    <h2>Openstaand (<?php echo esc_html(count($pending)); ?>)</h2>
    <?php if (!$pending) : ?>
        <p>Niets openstaand.</p>
    <?php else : foreach ($pending as $r) :
        $member = AVPVH_DB::get_member((int) $r->member_id);
        $activity = $r->activity_id ? AVPVH_DB::get_activity((int) $r->activity_id) : null;
        $member_name = $member ? avpvh_format_name($member) : 'Onbekend lid';
        $remittance = 'Declaratie' . ($r->description ? ': ' . $r->description : '') . ($activity ? ' (' . $activity->name . ')' : '');
        $qr = AVBK_QR::for_reimbursement($r->iban, $member_name, (float) $r->amount, $remittance);
        $known_ibans = AVBK_DB::get_known_ibans_for_member((int) $r->member_id);
        $iban_account_name = '';
        foreach ($known_ibans as $known) {
            if ($known->iban === $r->iban) {
                $iban_account_name = $known->account_name;
                break;
            }
        }
        $receipts = AVBK_DB::get_reimbursement_receipts((int) $r->id);
        // A wide ±14 day buffer around the activity's own dates — early
        // purchases (camp shopping the week before) and late submissions
        // are normal, this is just a nudge for the penningmeester to take
        // a closer look, never a block. Flagged if ANY attached receipt
        // falls outside the window.
        $window_start = ($activity && $activity->start_date) ? strtotime($activity->start_date) - 14 * DAY_IN_SECONDS : null;
        $window_end = ($activity && $activity->start_date) ? strtotime($activity->end_date ?: $activity->start_date) + 14 * DAY_IN_SECONDS : null;
        ?>
        <div class="avbk-reimbursement-card">
            <table class="wp-list-table widefat striped" style="max-width:700px">
                <tbody>
                    <tr><th>Lid</th><td><?php echo esc_html($member_name); ?></td></tr>
                    <tr><th>Datum</th><td><?php echo esc_html(wp_date('d-m-Y H:i', strtotime($r->created_at))); ?></td></tr>
                    <tr><th>Activiteit</th><td><?php echo $activity ? esc_html($activity->name . ' (' . $activity->year . ')') : '&mdash;'; ?></td></tr>
                    <tr><th>Omschrijving</th><td><?php echo esc_html($r->description ?: '&mdash;'); ?></td></tr>
                    <tr><th>Bedrag</th><td>&euro; <?php echo esc_html(number_format((float) $r->amount, 2, ',', '.')); ?><?php echo $r->ocr_amount ? ' (OCR herkende totaal € ' . esc_html(number_format((float) $r->ocr_amount, 2, ',', '.')) . ')' : ''; ?></td></tr>
                    <tr><th>Rekeningnummer</th><td><?php echo esc_html($r->iban); ?><?php echo $iban_account_name ? ' &mdash; ' . esc_html($iban_account_name) : ''; ?><?php echo count($known_ibans) > 1 ? ' <span class="description">(meerdere rekeningen bekend bij dit lid &mdash; zie &ldquo;aanpassen&rdquo; hieronder)</span>' : ''; ?></td></tr>
                    <tr><th>Bonnetje(s)</th><td>
                        <?php foreach ($receipts as $rec) :
                            $rec_view_url = wp_nonce_url(add_query_arg(['action' => 'avbk_view_receipt', 'id' => $r->id, 'receipt' => $rec->id], admin_url('admin-post.php')), 'avbk_view_receipt');
                            $rec_date_flag = $rec->ocr_date && $window_start && (strtotime($rec->ocr_date) < $window_start || strtotime($rec->ocr_date) > $window_end);
                            ?>
                            <span class="avbk-receipt-item">
                                <img src="<?php echo esc_url($rec_view_url); ?>" alt="Bonnetje van <?php echo esc_attr($member_name); ?>" class="avbk-receipt-thumb">
                                <br><a href="<?php echo esc_url($rec_view_url); ?>" target="_blank">open in nieuw tabblad</a>
                                <?php if ($rec_date_flag) : ?><br><span class="avbk-badge avbk-badge-warn">&#9888; datum wijkt af van activiteit</span><?php endif; ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-receipt-edit-form">
                                    <?php wp_nonce_field('avbk_update_receipt'); ?>
                                    <input type="hidden" name="action" value="avbk_update_receipt">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                                    <input type="hidden" name="receipt_id" value="<?php echo esc_attr($rec->id); ?>">
                                    <input type="date" name="ocr_date" value="<?php echo esc_attr($rec->ocr_date); ?>">
                                    <input type="text" name="ocr_store" value="<?php echo esc_attr($rec->ocr_store); ?>" placeholder="Winkel">
                                    <button type="submit" class="button button-small">Opslaan</button>
                                </form>
                            </span>
                        <?php endforeach; ?>
                    </td></tr>
                </tbody>
            </table>

            <?php if ($qr) : ?>
                <div class="avbk-reimbursement-qr"><?php echo $qr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- server-rendered SVG from chillerlan/php-qrcode, not user input; esc_html() would break the markup. ?></div>
            <?php else : ?>
                <p class="description">Geen QR mogelijk &mdash; rekeningnummer ontbreekt of is ongeldig.</p>
            <?php endif; ?>

            <details class="avbk-reimbursement-edit">
                <summary>Bedrag, omschrijving, activiteit of rekeningnummer aanpassen</summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avbk_update_reimbursement'); ?>
                    <input type="hidden" name="action" value="avbk_update_reimbursement">
                    <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                    <p>
                        <label>Activiteit<br>
                            <select name="activity_id">
                                <option value="">&mdash; geen (algemene onkosten) &mdash;</option>
                                <?php foreach ($activities as $a) : ?>
                                    <option value="<?php echo esc_attr($a->id); ?>" <?php selected((int) $r->activity_id, (int) $a->id); ?>>
                                        <?php echo esc_html($a->name . ' (' . $a->year . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </p>
                    <p>
                        <label>Omschrijving<br>
                            <input type="text" name="description" class="regular-text" value="<?php echo esc_attr($r->description); ?>">
                        </label>
                    </p>
                    <p>
                        <label>Bedrag<br>
                            &euro; <input type="text" name="amount" value="<?php echo esc_attr(number_format((float) $r->amount, 2, ',', '')); ?>">
                        </label>
                    </p>
                    <?php
                    $iban_matches_known = false;
                    foreach ($known_ibans as $known) {
                        if ($known->iban === $r->iban) {
                            $iban_matches_known = true;
                            break;
                        }
                    }
                    ?>
                    <p>
                        <label>Rekeningnummer<br>
                            <select class="avbk-iban-select" data-target="avbk-iban-manual-<?php echo (int) $r->id; ?>">
                                <?php foreach ($known_ibans as $known) : ?>
                                    <option value="<?php echo esc_attr($known->iban); ?>" <?php selected($r->iban, $known->iban); ?>>
                                        <?php echo esc_html(($known->account_name ?: $known->iban) . ' (' . $known->iban . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="" <?php selected($iban_matches_known, false); ?>>Ander rekeningnummer&hellip;</option>
                            </select>
                            <input type="text" name="iban" id="avbk-iban-manual-<?php echo (int) $r->id; ?>" class="regular-text" list="avbk-all-ibans" value="<?php echo esc_attr($r->iban); ?>" <?php echo $iban_matches_known ? 'hidden' : ''; ?>>
                            <p class="description">Zoekt in alle bekende rekeningnummers, of vul een geheel nieuw nummer in.</p>
                        </label>
                    </p>
                    <?php submit_button('Wijziging opslaan', 'secondary', 'submit', false); ?>
                </form>
            </details>

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

    <?php if ($paid || $rejected || $withdrawn) : ?>
        <h2>Afgehandeld</h2>
        <table class="wp-list-table widefat striped">
            <thead><tr><th>Datum</th><th>Lid</th><th>Omschrijving</th><th>Bedrag</th><th>Status</th></tr></thead>
            <tbody>
            <?php
            $status_labels = ['paid' => 'Betaald', 'rejected' => 'Afgewezen', 'withdrawn' => 'Ingetrokken door lid'];
            foreach (array_merge($paid, $rejected, $withdrawn) as $r) :
                $member = AVPVH_DB::get_member((int) $r->member_id); ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo esc_html(wp_date('d-m-Y', strtotime($r->created_at))); ?></td>
                    <td><?php echo $member ? esc_html(avpvh_format_name($member, 'list')) : '&mdash;'; ?></td>
                    <td><?php echo esc_html($r->description ?: '&mdash;'); ?></td>
                    <td>&euro; <?php echo esc_html(number_format((float) $r->amount, 2, ',', '.')); ?></td>
                    <td><?php echo esc_html($status_labels[$r->status] ?? $r->status); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
