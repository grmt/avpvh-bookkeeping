<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$active_tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'tshirt'));
if (!in_array($active_tab, ['tshirt', 'book', 'settings'], true)) {
    $active_tab = 'tshirt';
}

$page_url = admin_url('admin.php?page=avbk-orders');
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Bestellingen &mdash; Merchandise &amp; Boeken</h1>
    <hr class="wp-header-end">

    <?php if (isset($_GET['distribution_updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Status uitreiking bijgewerkt.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['settings_saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Instellingen succesvol opgeslagen.</p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper" style="margin-bottom: 1.5rem;">
        <a href="<?php echo esc_url(add_query_arg('tab', 'tshirt', $page_url)); ?>" class="nav-tab <?php echo $active_tab === 'tshirt' ? 'nav-tab-active' : ''; ?>">
            &#128085; T-shirts (Lustrum)
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'book', $page_url)); ?>" class="nav-tab <?php echo $active_tab === 'book' ? 'nav-tab-active' : ''; ?>">
            &#128214; Jubileumboek (Doorgraven!)
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'settings', $page_url)); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
            &#9881; Instellingen
        </a>
    </nav>

    <?php if ($active_tab === 'tshirt') : ?>
        <?php
        $status_filter       = sanitize_key(wp_unslash($_GET['status'] ?? ''));
        $distribution_filter = sanitize_key(wp_unslash($_GET['distribution_status'] ?? 'all'));
        $payment_filter      = sanitize_key(wp_unslash($_GET['payment_status'] ?? 'all'));
        $search_query        = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));

        $filter_args = ['order_type' => 'tshirt'];
        if ($status_filter !== '' && $status_filter !== 'all') {
            $filter_args['status'] = $status_filter;
        }
        if ($distribution_filter !== '' && $distribution_filter !== 'all') {
            $filter_args['distribution_status'] = $distribution_filter;
        }
        if ($payment_filter !== '' && $payment_filter !== 'all') {
            $filter_args['payment_status'] = $payment_filter;
        }
        if ($search_query !== '') {
            $filter_args['search'] = $search_query;
        }

        $orders = AVBK_DB::get_orders($filter_args);
        $all_orders = AVBK_DB::get_orders(['order_type' => 'tshirt']);
        $matrix = AVBK_DB::get_order_matrix('tshirt');

        // Statistics over all confirmed T-shirt orders
        $stat_total_orders     = count($all_orders);
        $stat_confirmed_orders = 0;
        $stat_total_shirts     = 0;
        $stat_total_amount     = 0.0;
        $stat_paid_amount      = 0.0;
        $stat_pending_dist     = 0;
        $stat_collected_dist   = 0;
        $stat_distributed_dist = 0;

        foreach ($all_orders as $ord) {
            if ($ord->status === 'confirmed') {
                $stat_confirmed_orders++;
                $stat_total_shirts  += (int) $ord->quantity;
                $stat_total_amount  += (float) $ord->total_amount;
                $stat_paid_amount   += min((float) $ord->total_amount, (float) $ord->fee_paid);

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

        $export_orders_url = add_query_arg(array_merge($_GET, ['action' => 'avbk_export_tshirt_orders']), admin_url('admin-post.php'));
        $export_matrix_url = add_query_arg(['action' => 'avbk_export_tshirt_matrix'], admin_url('admin-post.php'));
        ?>

        <div style="display: flex; gap: .75rem; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap;">
            <a href="<?php echo esc_url($export_orders_url); ?>" class="button button-secondary">&#128229; Exporteer bestellingen (CSV)</a>
            <a href="<?php echo esc_url($export_matrix_url); ?>" class="button button-secondary">&#128202; Exporteer inkoopmatrix (CSV)</a>
            <a href="<?php echo esc_url(home_url('/tshirt/')); ?>" class="button button-link" target="_blank" rel="noopener">&#128279; Bekijk bestelpagina</a>
        </div>

        <!-- Inkoopmatrix Card -->
        <div class="card" style="max-width: 100%; margin: 0 0 1.5rem; padding: 1.25rem 1.5rem;">
            <h2 style="margin-top: 0; margin-bottom: .25rem; font-size: 1.2rem;">&#128202; Inkoopmatrix &mdash; T-shirts</h2>
            <p class="description" style="margin-top: 0; margin-bottom: 1rem;">
                Overzicht van alle bevestigde T-shirt bestellingen per design en maat om door te geven aan de leverancier/drukker.
            </p>

            <div style="overflow-x: auto;">
                <table class="widefat striped" style="border: 1px solid #c3c4c7; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f0f0f1;">
                            <th style="padding: 10px; font-weight: bold; width: 280px;">Design / Model</th>
                            <?php foreach ($matrix['variants'] as $var) : ?>
                                <th style="padding: 10px; text-align: center; font-weight: bold; min-width: 60px;"><?php echo esc_html($var); ?></th>
                            <?php endforeach; ?>
                            <th style="padding: 10px; text-align: right; font-weight: bold; width: 110px; background: #e5edf5;">Totaal shirts</th>
                            <th style="padding: 10px; text-align: right; font-weight: bold; width: 110px; background: #e5edf5;">Omzet (&euro;)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($matrix['designs'])) : ?>
                            <tr>
                                <td colspan="<?php echo count($matrix['variants']) + 3; ?>" style="padding: 15px; text-align: center; color: #646970;">
                                    Nog geen bevestigde T-shirt bestellingen om weer te geven in de inkoopmatrix.
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($matrix['designs'] as $design) :
                                $row_qty = $matrix['totals_by_design'][$design] ?? 0;
                                $unit_price = (float) (get_option('avbk_tshirt_price', AVBK_Tshirt_Order::DEFAULT_PRICE) ?: AVBK_Tshirt_Order::DEFAULT_PRICE);
                                $row_eur = round($row_qty * $unit_price, 2);
                            ?>
                                <tr>
                                    <td style="padding: 8px 10px;"><strong><?php echo esc_html($design); ?></strong></td>
                                    <?php foreach ($matrix['variants'] as $var) :
                                        $cnt = $matrix['cells'][$design][$var] ?? 0;
                                    ?>
                                        <td style="padding: 8px 10px; text-align: center; <?php echo $cnt > 0 ? 'font-weight: bold; background: #f0f8ff;' : 'color: #8c8f94;'; ?>">
                                            <?php echo $cnt > 0 ? (int) $cnt : '&mdash;'; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td style="padding: 8px 10px; text-align: right; font-weight: bold; background: #f7f9fb;">
                                        <?php echo (int) $row_qty; ?>
                                    </td>
                                    <td style="padding: 8px 10px; text-align: right; background: #f7f9fb;">
                                        &euro;&nbsp;<?php echo number_format($row_eur, 2, ',', '.'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: #e5edf5; font-weight: bold; border-top: 2px solid #c3c4c7;">
                            <td style="padding: 10px;">TOTAAL PER MAAT</td>
                            <?php foreach ($matrix['variants'] as $var) :
                                $col_cnt = $matrix['totals_by_variant'][$var] ?? 0;
                            ?>
                                <td style="padding: 10px; text-align: center; font-size: 1.05rem;">
                                    <?php echo (int) $col_cnt; ?>
                                </td>
                            <?php endforeach; ?>
                            <td style="padding: 10px; text-align: right; font-size: 1.1rem; color: #2271b1;">
                                <?php echo (int) $matrix['grand_total']; ?>
                            </td>
                            <td style="padding: 10px; text-align: right; font-size: 1.05rem;">
                                &euro;&nbsp;<?php echo number_format((float) $matrix['total_revenue'], 2, ',', '.'); ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Summary KPI Cards -->
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin: 1.5rem 0;">
            <div class="card" style="flex: 1 1 180px; margin: 0; padding: 1rem;">
                <h3 style="margin: 0 0 .5rem; font-size: .9rem; color: #50575e; text-transform: uppercase;">Shirts besteld</h3>
                <div style="font-size: 1.8rem; font-weight: bold; color: #1d2327;"><?php echo $stat_total_shirts; ?></div>
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
                <h3 style="margin: 0 0 .5rem; font-size: .9rem; color: #50575e; text-transform: uppercase;">Uitreiking shirts</h3>
                <div style="font-size: 1.8rem; font-weight: bold; color: #dba617;"><?php echo $stat_pending_dist; ?> open</div>
                <div style="font-size: .85rem; color: #646970;"><?php echo $stat_collected_dist; ?> afgehaald &bull; <?php echo $stat_distributed_dist; ?> uitgereikt</div>
            </div>
        </div>

        <!-- Filter form -->
        <form method="get" style="margin: 1.25rem 0; display: flex; gap: .75rem; flex-wrap: wrap; align-items: center;">
            <input type="hidden" name="page" value="avbk-orders">
            <input type="hidden" name="tab" value="tshirt">

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

            <label for="filter_status">Bestelstatus:
                <select name="status" id="filter_status" onchange="this.form.submit()">
                    <option value="all" <?php selected($status_filter, 'all'); ?>>Alle bestellingen</option>
                    <option value="confirmed" <?php selected($status_filter, 'confirmed'); ?>>Bevestigd</option>
                    <option value="pending_confirmation" <?php selected($status_filter, 'pending_confirmation'); ?>>In afwachting van bevestiging</option>
                </select>
            </label>

            <input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Zoek op naam, e-mail, plaats..." style="min-width: 200px;">
            <button type="submit" class="button">Filteren</button>
            <?php if ($distribution_filter !== 'all' || $payment_filter !== 'all' || $status_filter !== '' || $search_query !== '') : ?>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-orders', 'tab' => 'tshirt'], admin_url('admin.php'))); ?>" class="button">Reset</a>
            <?php endif; ?>
        </form>

        <!-- Orders Table -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th style="width: 90px;">Datum</th>
                    <th style="width: 170px;">Besteller</th>
                    <th>Adres</th>
                    <th style="width: 240px;">Bestelde shirts</th>
                    <th style="width: 90px;">Bedrag</th>
                    <th style="width: 110px;">Betaling</th>
                    <th style="width: 150px;">Uitreiking</th>
                    <th>Opmerkingen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)) : ?>
                    <tr>
                        <td colspan="9">Geen T-shirt bestellingen gevonden.</td>
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
                            <td>
                                <?php if (!empty($order->items)) : ?>
                                    <ul style="margin: 0; padding-left: 1rem; list-style: square;">
                                        <?php foreach ($order->items as $it) : ?>
                                            <li>
                                                <strong><?php echo (int) $it->quantity; ?>&times;</strong>
                                                <?php echo esc_html($it->title); ?>
                                                <span class="badge" style="background:#2271b1;color:#fff;padding:0 5px;border-radius:3px;font-size:.75rem;"><?php echo esc_html($it->variant); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else : ?>
                                    <em><?php echo (int) $order->quantity; ?> shirt(s)</em>
                                <?php endif; ?>
                            </td>
                            <td>&euro;&nbsp;<?php echo number_format((float) $order->total_amount, 2, ',', '.'); ?></td>
                            <td>
                                <?php if ($is_paid) : ?>
                                    <span style="color: #00a32a; font-weight: bold;">&#10004; Betaald</span>
                                <?php else : ?>
                                    <span style="color: #d63638; font-weight: bold;">Open</span>
                                    <?php if ((float) $order->fee_paid > 0.005) : ?>
                                        <br><small>(&euro;&nbsp;<?php echo number_format((float) $order->fee_paid, 2, ',', '.'); ?> ontvangen)</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                                    <?php wp_nonce_field('avbk_update_order_distribution'); ?>
                                    <input type="hidden" name="action" value="avbk_update_order_distribution">
                                    <input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
                                    <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI'] ?? add_query_arg(['page' => 'avbk-orders', 'tab' => 'tshirt'], admin_url('admin.php'))); ?>">
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

    <?php elseif ($active_tab === 'book') : ?>
        <?php
        $status_filter       = sanitize_key(wp_unslash($_GET['status'] ?? ''));
        $distribution_filter = sanitize_key(wp_unslash($_GET['distribution_status'] ?? 'all'));
        $payment_filter      = sanitize_key(wp_unslash($_GET['payment_status'] ?? 'all'));
        $presentation_filter = sanitize_key(wp_unslash($_GET['presentation'] ?? 'all'));
        $search_query        = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));

        $filter_args = ['order_type' => 'book'];
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

        $orders = AVBK_DB::get_orders($filter_args);
        $all_orders = AVBK_DB::get_orders(['order_type' => 'book']);

        // Calculate statistics over all book orders
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

        <div style="display: flex; gap: .75rem; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap;">
            <a href="<?php echo esc_url($export_url); ?>" class="button button-secondary">&#128229; Exporteer naar CSV</a>
            <a href="<?php echo esc_url(home_url('/boek/')); ?>" class="button button-link" target="_blank" rel="noopener">&#128279; Bekijk bestelpagina</a>
        </div>

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
            <input type="hidden" name="page" value="avbk-orders">
            <input type="hidden" name="tab" value="book">

            <label for="filter_dist_book">Uitreiking:
                <select name="distribution_status" id="filter_dist_book" onchange="this.form.submit()">
                    <option value="all" <?php selected($distribution_filter, 'all'); ?>>Alle uitreikstatussen</option>
                    <option value="pending" <?php selected($distribution_filter, 'pending'); ?>>In afwachting</option>
                    <option value="collected" <?php selected($distribution_filter, 'collected'); ?>>Opgehaald</option>
                    <option value="distributed" <?php selected($distribution_filter, 'distributed'); ?>>Uitgereikt</option>
                </select>
            </label>

            <label for="filter_pay_book">Betaling:
                <select name="payment_status" id="filter_pay_book" onchange="this.form.submit()">
                    <option value="all" <?php selected($payment_filter, 'all'); ?>>Alle betaalstatussen</option>
                    <option value="paid" <?php selected($payment_filter, 'paid'); ?>>Betaald</option>
                    <option value="open" <?php selected($payment_filter, 'open'); ?>>Open</option>
                </select>
            </label>

            <label for="filter_pres_book">Presentatie:
                <select name="presentation" id="filter_pres_book" onchange="this.form.submit()">
                    <option value="all" <?php selected($presentation_filter, 'all'); ?>>Alle presentatiekeuzes</option>
                    <option value="attend" <?php selected($presentation_filter, 'attend'); ?>>Aanwezig willen zijn</option>
                    <option value="update" <?php selected($presentation_filter, 'update'); ?>>Op de hoogte houden</option>
                </select>
            </label>

            <label for="filter_status_book">Bestelstatus:
                <select name="status" id="filter_status_book" onchange="this.form.submit()">
                    <option value="all" <?php selected($status_filter, 'all'); ?>>Alle bestellingen</option>
                    <option value="confirmed" <?php selected($status_filter, 'confirmed'); ?>>Bevestigd</option>
                    <option value="pending_confirmation" <?php selected($status_filter, 'pending_confirmation'); ?>>In afwachting van bevestiging</option>
                </select>
            </label>

            <input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Zoek op naam, e-mail, plaats..." style="min-width: 200px;">
            <button type="submit" class="button">Filteren</button>
            <?php if ($distribution_filter !== 'all' || $payment_filter !== 'all' || $presentation_filter !== 'all' || $status_filter !== '' || $search_query !== '') : ?>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-orders', 'tab' => 'book'], admin_url('admin.php'))); ?>" class="button">Reset</a>
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
                    <th>Opmerkingen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)) : ?>
                    <tr>
                        <td colspan="10">Geen boekbestellingen gevonden.</td>
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
                            <td>&euro;&nbsp;<?php echo number_format((float) $order->total_amount, 2, ',', '.'); ?></td>
                            <td>
                                <?php if ($is_paid) : ?>
                                    <span style="color: #00a32a; font-weight: bold;">&#10004; Betaald</span>
                                <?php else : ?>
                                    <span style="color: #d63638; font-weight: bold;">Open</span>
                                    <?php if ((float) $order->fee_paid > 0.005) : ?>
                                        <br><small>(&euro;&nbsp;<?php echo number_format((float) $order->fee_paid, 2, ',', '.'); ?> ontvangen)</small>
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
                                    <?php wp_nonce_field('avbk_update_order_distribution'); ?>
                                    <input type="hidden" name="action" value="avbk_update_order_distribution">
                                    <input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
                                    <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI'] ?? add_query_arg(['page' => 'avbk-orders', 'tab' => 'book'], admin_url('admin.php'))); ?>">
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

    <?php elseif ($active_tab === 'settings') : ?>
        <?php
        $designs = AVBK_Tshirt_Order::get_available_designs();
        $sizes_str = get_option('avbk_tshirt_sizes', implode(', ', AVBK_Tshirt_Order::DEFAULT_SIZES));
        $colors_str = get_option('avbk_tshirt_colors', implode(', ', AVBK_Tshirt_Order::DEFAULT_COLORS));
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('avbk_save_order_settings'); ?>
            <input type="hidden" name="action" value="avbk_save_order_settings">
            <input type="hidden" name="redirect_url" value="<?php echo esc_url(add_query_arg(['page' => 'avbk-orders', 'tab' => 'settings'], admin_url('admin.php'))); ?>">

            <h2>Kleding instellingen ([avpvh_bk_tshirt_order])</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="tshirt_title">Titel</label></th>
                    <td>
                        <input type="text" id="tshirt_title" name="tshirt_title" class="regular-text" style="width:100%; max-width:600px;" value="<?php echo esc_attr(get_option('avbk_tshirt_title', AVBK_Tshirt_Order::DEFAULT_TITLE)); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tshirt_price">Standaardprijs per stuk (&euro;)</label></th>
                    <td>
                        <input type="number" step="0.50" id="tshirt_price" name="tshirt_price" class="small-text" value="<?php echo esc_attr(number_format((float) get_option('avbk_tshirt_price', AVBK_Tshirt_Order::DEFAULT_PRICE), 2, '.', '')); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tshirt_price_note">Prijsnotitie (bijv. BTW)</label></th>
                    <td>
                        <input type="text" id="tshirt_price_note" name="tshirt_price_note" class="regular-text" style="width:100%; max-width:600px;" value="<?php echo esc_attr(get_option('avbk_tshirt_price_note', AVBK_Tshirt_Order::DEFAULT_PRICE_NOTE)); ?>">
                        <p class="description">Bijv. <code>Alle prijzen zijn inclusief btw.</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tshirt_intro">Inleidende tekst</label></th>
                    <td>
                        <textarea id="tshirt_intro" name="tshirt_intro" rows="3" class="large-text" style="width:100%; max-width:600px;"><?php echo esc_textarea(get_option('avbk_tshirt_intro', AVBK_Tshirt_Order::DEFAULT_INTRO)); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tshirt_distribution_notice">Uitleg distributie / afhalen</label></th>
                    <td>
                        <textarea id="tshirt_distribution_notice" name="tshirt_distribution_notice" rows="2" class="large-text" style="width:100%; max-width:600px;"><?php echo esc_textarea(get_option('avbk_tshirt_distribution_notice', AVBK_Tshirt_Order::DEFAULT_DISTRIBUTION_NOTICE)); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tshirt_sizes">Beschikbare maten</label></th>
                    <td>
                        <input type="text" id="tshirt_sizes" name="tshirt_sizes" class="regular-text" style="width:100%; max-width:600px;" value="<?php echo esc_attr($sizes_str); ?>">
                        <p class="description">Komma-gescheiden lijst van beschikbare kledingmaten (bijv. <code>XS, S, M, L, XL, XXL, 3XL</code>).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tshirt_colors">Beschikbare kleuren</label></th>
                    <td>
                        <input type="text" id="tshirt_colors" name="tshirt_colors" class="regular-text" style="width:100%; max-width:600px;" value="<?php echo esc_attr($colors_str); ?>">
                        <p class="description">Komma-gescheiden lijst van beschikbare kleuren (bijv. <code>Zwart, Wit, Beige, Donkergroen</code>).</p>
                    </td>
                </tr>
            </table>

            <h3>Kleding designs, modellen &amp; foto's</h3>
            <p class="description">Beheer hier de beschikbare modellen en designs. Je kunt per item een naam, omschrijving, afbeeldings-URL (foto/mockup) en optioneel een afwijkende stukprijs opgeven.</p>

            <table class="widefat striped" style="max-width: 960px; margin-bottom: 1.5rem;" id="avbk_designs_editor_table">
                <thead>
                    <tr>
                        <th style="width: 130px;">ID / Code</th>
                        <th style="width: 190px;">Naam design / model</th>
                        <th>Omschrijving</th>
                        <th style="width: 190px;">Foto URL</th>
                        <th style="width: 85px;">Prijs (&euro;)</th>
                        <th style="width: 55px; text-align: center;">Actief</th>
                    </tr>
                </thead>
                <tbody id="avbk_designs_editor_body">
                    <?php foreach ($designs as $idx => $d) : ?>
                        <tr>
                            <td>
                                <input type="text" name="tshirt_designs[<?php echo $idx; ?>][id]" value="<?php echo esc_attr($d['id'] ?? ''); ?>" style="width:100%;" required>
                            </td>
                            <td>
                                <input type="text" name="tshirt_designs[<?php echo $idx; ?>][name]" value="<?php echo esc_attr($d['name'] ?? ''); ?>" style="width:100%;" required>
                            </td>
                            <td>
                                <input type="text" name="tshirt_designs[<?php echo $idx; ?>][description]" value="<?php echo esc_attr($d['description'] ?? ''); ?>" style="width:100%;">
                            </td>
                            <td>
                                <input type="url" name="tshirt_designs[<?php echo $idx; ?>][image]" value="<?php echo esc_attr($d['image'] ?? ''); ?>" placeholder="https://..." style="width:100%;">
                            </td>
                            <td>
                                <input type="number" step="0.50" name="tshirt_designs[<?php echo $idx; ?>][price]" value="<?php echo isset($d['price']) && $d['price'] !== '' && (float) $d['price'] > 0 ? esc_attr(number_format((float) $d['price'], 2, '.', '')) : ''; ?>" placeholder="Standaard" style="width:100%;">
                            </td>
                            <td style="text-align: center;">
                                <input type="checkbox" name="tshirt_designs[<?php echo $idx; ?>][active]" value="1" <?php checked(!empty($d['active'])); ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <hr style="margin: 2rem 0 1.5rem;">

            <h2>Jubileumboek instellingen ([avpvh_bk_book_order])</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="book_title">Titel</label></th>
                    <td>
                        <input type="text" id="book_title" name="book_title" class="regular-text" style="width:100%; max-width:600px;" value="<?php echo esc_attr(get_option('avbk_book_title', AVBK_Book_Order::DEFAULT_TITLE)); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="book_price">Prijs per exemplaar (&euro;)</label></th>
                    <td>
                        <input type="number" step="0.50" id="book_price" name="book_price" class="small-text" value="<?php echo esc_attr(number_format((float) get_option('avbk_book_price', AVBK_Book_Order::DEFAULT_PRICE), 2, '.', '')); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="book_price_note">Prijsnotitie (bijv. BTW)</label></th>
                    <td>
                        <input type="text" id="book_price_note" name="book_price_note" class="regular-text" style="width:100%; max-width:600px;" value="<?php echo esc_attr(get_option('avbk_book_price_note', AVBK_Book_Order::DEFAULT_PRICE_NOTE)); ?>">
                        <p class="description">Bijv. <code>Inclusief btw.</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="book_distribution_notice">Uitleg distributie / afhalen</label></th>
                    <td>
                        <textarea id="book_distribution_notice" name="book_distribution_notice" rows="2" class="large-text" style="width:100%; max-width:600px;"><?php echo esc_textarea(get_option('avbk_book_distribution_notice', AVBK_Book_Order::DEFAULT_DISTRIBUTION_NOTICE)); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="book_presentation_notice">Toelichting boekpresentatie</label></th>
                    <td>
                        <textarea id="book_presentation_notice" name="book_presentation_notice" rows="2" class="large-text" style="width:100%; max-width:600px;"><?php echo esc_textarea(get_option('avbk_book_presentation_notice', AVBK_Book_Order::DEFAULT_PRESENTATION_NOTICE)); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="book_flaptekst">Flaptekst</label></th>
                    <td>
                        <textarea id="book_flaptekst" name="book_flaptekst" rows="6" class="large-text" style="width:100%; max-width:600px;"><?php echo esc_textarea(get_option('avbk_book_flaptekst', AVBK_Book_Order::DEFAULT_FLAPTEKST)); ?></textarea>
                    </td>
                </tr>
            </table>

            <?php submit_button('Alle instellingen opslaan'); ?>
        </form>
    <?php endif; ?>
</div>
