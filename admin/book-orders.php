<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$status_filter       = sanitize_key(wp_unslash($_GET['status'] ?? ''));
$distribution_filter = sanitize_key(wp_unslash($_GET['distribution_status'] ?? 'all'));
$payment_filter      = sanitize_key(wp_unslash($_GET['payment_status'] ?? 'all'));
$presentation_filter = sanitize_key(wp_unslash($_GET['presentation'] ?? 'all'));
$search_query        = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));

$filter_args = [];
if ($status_filter !== '' && $status_filter !== 'all') {
    $filter_args['status'] = $status_filter;
}
if ($distribution_filter !== '' && $distribution_filter !== 'all') {
    $filter_args['distribution_status'] = $distribution_filter;
}
if ($payment_filter !== '' && $payment_filter !== 'all') {
    $filter_args['payment_status'] = $payment_filter;
}
if ($presentation_filter !== '' && $presentation_filter !== 'all') {
    $filter_args['presentation'] = $presentation_filter;
}
if ($search_query !== '') {
    $filter_args['search'] = $search_query;
}

$orders = AVBK_DB::get_book_orders($filter_args);
$all_orders = AVBK_DB::get_book_orders([]); // For aggregate stats

// Calculate statistics over all orders
$stat_total_orders      = count($all_orders);
$stat_confirmed_orders  = 0;
$stat_total_books       = 0;
$stat_total_amount      = 0.0;
$stat_paid_amount       = 0.0;
$stat_attend_pres       = 0;
$stat_keep_updated      = 0;
$stat_pending_dist      = 0;
$stat_collected_dist    = 0;
$stat_distributed_dist  = 0;

foreach ($all_orders as $ord) {
    if ($ord->status === 'confirmed') {
        $stat_confirmed_orders++;
        $stat_total_books  += (int) $ord->quantity;
        $stat_total_amount += (float) $ord->total_amount;
        $stat_paid_amount  += min((float) $ord->total_amount, (float) $ord->fee_paid);

        if (!empty($ord->attend_presentation)) {
            $stat_attend_pres++;
        }
        if (!empty($ord->keep_updated)) {
            $stat_keep_updated++;
        }
        if ($ord->distribution_status === 'pending') {
            $stat_pending_dist += (int) $ord->quantity;
        } elseif ($ord->distribution_status === 'collected') {
            $stat_collected_dist += (int) $ord->quantity;
        } elseif ($ord->distribution_status === 'distributed') {
            $stat_distributed_dist += (int) $ord->quantity;
        }
    }
}
$stat_open_amount = max(0.0, $stat_total_amount - $stat_paid_amount);

$export_url = add_query_arg(array_merge($_GET, ['action' => 'avbk_export_book_orders']), admin_url('admin-post.php'));
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Boekbestellingen &mdash; Jubileumboek</h1>
    <a href="<?php echo esc_url($export_url); ?>" class="page-title-action">Exporteer naar CSV</a>
    <hr class="wp-header-end">

    <?php if (isset($_GET['distribution_updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Status uitreiking bijgewerkt.</p></div>
    <?php endif; ?>

    <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin: 1.5rem 0;">
        <div class="card" style="flex: 1 1 180px; margin: 0; padding: 1rem;">
            <h3 style="margin: 0 0 .5rem; font-size: .9rem; color: #50575e; text-transform: uppercase;">Exemplaren besteld</h3>
            <div style="font-size: 1.8rem; font-weight: bold; color: #1d2327;"><?php echo $stat_total_books; ?></div>
            <div style="font-size: .85rem; color: #646970;"><?php echo $stat_confirmed_orders; ?> bevestigde bestellingen (van <?php echo $stat_total_orders; ?>)</div>
        </div>
        <div class="card" style="flex: 1 1 180px; margin: 0; padding: 1rem;">
            <h3 style="margin: 0 0 .5rem; font-size: .9rem; color: #50575e; text-transform: uppercase;">Totaalbedrag</h3>
            <div style="font-size: 1.8rem; font-weight: bold; color: #1d2327;">&euro; <?php echo number_format($stat_total_amount, 2, ',', '.'); ?></div>
            <div style="font-size: .85rem; color: #00a32a;">&euro; <?php echo number_format($stat_paid_amount, 2, ',', '.'); ?> betaald</div>
            <?php if ($stat_open_amount > 0.005) : ?>
                <div style="font-size: .85rem; color: #d63638;">&euro; <?php echo number_format($stat_open_amount, 2, ',', '.'); ?> nog open</div>
            <?php endif; ?>
        </div>
        <div class="card" style="flex: 1 1 180px; margin: 0; padding: 1rem;">
            <h3 style="margin: 0 0 .5rem; font-size: .9rem; color: #50575e; text-transform: uppercase;">Uitreiking boeken</h3>
            <div style="font-size: 1.8rem; font-weight: bold; color: #dba617;"><?php echo $stat_pending_dist; ?> open</div>
            <div style="font-size: .85rem; color: #646970;"><?php echo $stat_collected_dist; ?> afgehaald &bull; <?php echo $stat_distributed_dist; ?> uitgereikt</div>
        </div>
        <div class="card" style="flex: 1 1 180px; margin: 0; padding: 1rem;">
            <h3 style="margin: 0 0 .5rem; font-size: .9rem; color: #50575e; text-transform: uppercase;">Presentatie begin 2027</h3>
            <div style="font-size: 1.8rem; font-weight: bold; color: #2271b1;"><?php echo $stat_attend_pres; ?> aanwezig</div>
            <div style="font-size: .85rem; color: #646970;"><?php echo $stat_keep_updated; ?> wil op de hoogte blijven</div>
        </div>
    </div>

    <form method="get" style="margin: 1.25rem 0; display: flex; gap: .75rem; flex-wrap: wrap; align-items: center;">
        <input type="hidden" name="page" value="avbk-book-orders">

        <label for="filter_dist">Uitreiking:
            <select name="distribution_status" id="filter_dist" onchange="this.form.submit()">
                <option value="all" <?php selected($distribution_filter, 'all'); ?>>Alle uitreikstatussen</option>
                <option value="pending" <?php selected($distribution_filter, 'pending'); ?>>In afwachting</option>
                <option value="collected" <?php selected($distribution_filter, 'collected'); ?>>Opgehaald</option>
                <option value="distributed" <?php selected($distribution_filter, 'distributed'); ?>>Uitgereikt</option>
            </select>
        </label>

        <label for="filter_pay">Betaling:
            <select name="payment_status" id="filter_pay" onchange="this.form.submit()">
                <option value="all" <?php selected($payment_filter, 'all'); ?>>Alle betaalstatussen</option>
                <option value="paid" <?php selected($payment_filter, 'paid'); ?>>Betaald</option>
                <option value="open" <?php selected($payment_filter, 'open'); ?>>Open</option>
            </select>
        </label>

        <label for="filter_pres">Presentatie:
            <select name="presentation" id="filter_pres" onchange="this.form.submit()">
                <option value="all" <?php selected($presentation_filter, 'all'); ?>>Alle presentatiekeuzes</option>
                <option value="attend" <?php selected($presentation_filter, 'attend'); ?>>Aanwezig willen zijn</option>
                <option value="update" <?php selected($presentation_filter, 'update'); ?>>Op de hoogte houden</option>
            </select>
        </label>

        <label for="filter_status">Bestelstatus:
            <select name="status" id="filter_status" onchange="this.form.submit()">
                <option value="all" <?php selected($status_filter, 'all'); ?>>Alle bestellingen</option>
                <option value="confirmed" <?php selected($status_filter, 'confirmed'); ?>>Bevestigd</option>
                <option value="pending_confirmation" <?php selected($status_filter, 'pending_confirmation'); ?>>In afwachting van bevestiging</option>
            </select>
        </label>

        <input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Zoek op naam, e-mail, plaats..." style="min-width: 200px;">
        <button type="submit" class="button">Filteren</button>
        <?php if ($distribution_filter !== 'all' || $payment_filter !== 'all' || $presentation_filter !== 'all' || $status_filter !== '' || $search_query !== '') : ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=avbk-book-orders')); ?>" class="button">Reset</a>
        <?php endif; ?>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 50px;">ID</th>
                <th style="width: 90px;">Datum</th>
                <th style="width: 170px;">Besteller</th>
                <th>Adres</th>
                <th style="width: 80px;">Aantal</th>
                <th style="width: 90px;">Bedrag</th>
                <th style="width: 110px;">Betaling</th>
                <th style="width: 120px;">Presentatie</th>
                <th style="width: 150px;">Uitreiking</th>
                <th>Opmerking</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($orders)) : ?>
                <tr>
                    <td colspan="10">Geen bestellingen gevonden.</td>
                </tr>
            <?php else : ?>
                <?php foreach ($orders as $order) :
                    $full_name = trim($order->first_name . ' ' . $order->suffix) . ' ' . $order->last_name;
                    $is_paid = $order->fee_status === 'waived' || ((float) $order->fee_paid >= (float) $order->total_amount - 0.005);
                    $member_profile_url = $order->member_id ? admin_url('admin.php?page=avpvh-member-detail&id=' . (int) $order->member_id) : '';
                ?>
                    <tr>
                        <td>#<?php echo (int) $order->id; ?></td>
                        <td><?php echo esc_html(wp_date('d-m-Y', strtotime($order->created_at))); ?></td>
                        <td>
                            <strong>
                                <?php if ($member_profile_url) : ?>
                                    <a href="<?php echo esc_url($member_profile_url); ?>"><?php echo esc_html($full_name); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($full_name); ?>
                                <?php endif; ?>
                            </strong>
                            <br><small><a href="mailto:<?php echo esc_attr($order->email); ?>"><?php echo esc_html($order->email); ?></a></small>
                            <?php if ($order->phone) : ?>
                                <br><small><?php echo esc_html($order->phone); ?></small>
                            <?php endif; ?>
                            <?php if ($order->status === 'pending_confirmation') : ?>
                                <br><span class="badge" style="background:#f0ad4e;color:#fff;padding:1px 5px;border-radius:3px;font-size:.75rem;">Niet bevestigd</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html($order->street . ' ' . $order->house_number); ?><br>
                            <small><?php echo esc_html($order->postal_code . ' ' . $order->city); ?></small>
                            <?php if ($order->country && $order->country !== 'Nederland') : ?>
                                <br><small>(<?php echo esc_html($order->country); ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo (int) $order->quantity; ?></strong></td>
                        <td>&euro; <?php echo number_format((float) $order->total_amount, 2, ',', '.'); ?></td>
                        <td>
                            <?php if ($is_paid) : ?>
                                <span style="color: #00a32a; font-weight: bold;">&#10004; Betaald</span>
                            <?php else : ?>
                                <span style="color: #d63638; font-weight: bold;">Open</span>
                                <?php if ((float) $order->fee_paid > 0.005) : ?>
                                    <br><small>(&euro; <?php echo number_format((float) $order->fee_paid, 2, ',', '.'); ?> ontvangen)</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($order->attend_presentation)) : ?>
                                <span title="Aanwezig bij presentatie">&#128077; Aanwezig</span><br>
                            <?php endif; ?>
                            <?php if (!empty($order->keep_updated)) : ?>
                                <small title="Op de hoogte houden">&#128233; Info</small>
                            <?php endif; ?>
                            <?php if (empty($order->attend_presentation) && empty($order->keep_updated)) : ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                                <?php wp_nonce_field('avbk_update_book_distribution'); ?>
                                <input type="hidden" name="action" value="avbk_update_book_distribution">
                                <input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
                                <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI'] ?? admin_url('admin.php?page=avbk-book-orders')); ?>">
                                <select name="distribution_status" onchange="this.form.submit()" style="font-size: .85rem; padding: 2px 6px;">
                                    <option value="pending" <?php selected($order->distribution_status, 'pending'); ?>>In afwachting</option>
                                    <option value="collected" <?php selected($order->distribution_status, 'collected'); ?>>Opgehaald</option>
                                    <option value="distributed" <?php selected($order->distribution_status, 'distributed'); ?>>Uitgereikt</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <?php echo $order->notes ? esc_html($order->notes) : '&mdash;'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
