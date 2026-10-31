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
    <?php if (isset($_GET['order_email_processed'])) : ?>
        <div class="notice notice-success is-dismissible">
            <p>Bestelling #<?php echo (int) $_GET['order_email_processed']; ?> succesvol bijgewerkt<?php echo !empty($_GET['mail_sent']) ? ' (bevestigingsmail verzonden naar besteller).' : ' (zonder e-mail).'; ?></p>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['process_error'])) : ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html(urldecode((string) $_GET['process_error'])); ?></p>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['order_added'])) : ?>
        <div class="notice notice-success is-dismissible">
            <p>Bestelling #<?php echo (int) $_GET['order_added']; ?> toegevoegd.</p>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['order_deleted'])) : ?>
        <div class="notice notice-success is-dismissible">
            <p>Bestelling #<?php echo (int) $_GET['order_deleted']; ?> succesvol verwijderd<?php echo !empty($_GET['email_sent']) ? ' (annuleringsmail verstuurd naar besteller).' : ' (zonder e-mail).'; ?></p>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['delete_error'])) : ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html(urldecode((string) $_GET['delete_error'])); ?></p>
        </div>
    <?php endif; ?>

    <?php
    $all_members = class_exists('AVPVH_DB') ? AVPVH_DB::get_members() : [];
    // Sorted by name so both the "koppel aan lid" picker below and the
    // manual-add-order member selects are browsable alphabetically instead
    // of in whatever order the query happens to return.
    usort($all_members, function ($a, $b) {
        return strcasecmp($a->last_name . $a->first_name, $b->last_name . $b->first_name);
    });
    ?>

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
        // Filtering/sorting now happens client-side in the table itself
        // (avpvh-sortable-table, see avpvh-members/assets/sortable-table.js) — always load the full set.
        $all_orders = AVBK_DB::get_orders(['order_type' => 'tshirt']);
        $orders = $all_orders;
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

        <!-- Handmatig een bestelling toevoegen — voor een bestelling die buiten
             het bestelformulier om is gedaan (bijv. op papier) en niet in een
             bulk-import stond, zodat er alsnog een fee item + order voor
             bestaat om te verrekenen en mee te nemen in de inkoopmatrix. -->
        <details class="card" style="max-width: 100%; margin: 0 0 1.5rem; padding: 1.25rem 1.5rem;">
            <summary style="cursor: pointer; font-weight: 600; font-size: 1.1rem;">&#10133; Bestelling toevoegen (handmatig)</summary>
            <p class="description">Voor een bestelling die niet via het bestelformulier binnenkwam — bijv. gemist in een import, of op papier besteld. Maakt dezelfde post + bestelling aan als een normale bestelling (status: bevestigd); betalen/verrekenen en uitreiking-status gaan daarna via de gebruikelijke tools hieronder.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avbk_admin_add_tshirt_order'); ?>
                <input type="hidden" name="action" value="avbk_admin_add_tshirt_order">
                <input type="hidden" name="redirect_url" value="<?php echo esc_url(add_query_arg('tab', 'tshirt', $page_url)); ?>">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="avbk-add-order-member">Lid</label></th>
                        <td>
                            <select name="member_id" id="avbk-add-order-member" required style="min-width: 260px;">
                                <option value="">&mdash; kies lid &mdash;</option>
                                <?php
                                $order_form_members = $all_members;
                                usort($order_form_members, fn($a, $b) => strcasecmp($a->last_name . $a->first_name, $b->last_name . $b->first_name));
                                foreach ($order_form_members as $m) :
                                ?>
                                    <option value="<?php echo esc_attr($m->id); ?>"><?php echo esc_html(avpvh_format_name($m, 'list')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Kledingstukken</th>
                        <td>
                            <table class="widefat" style="max-width: 820px;">
                                <thead>
                                    <tr>
                                        <th>Design</th><th>Type</th><th>Kleur</th><th>Maat</th><th>Aantal</th><th>Prijs (optioneel)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($i = 0; $i < 3; $i++) : ?>
                                    <tr>
                                        <td>
                                            <select name="items[<?php echo (int) $i; ?>][design]">
                                                <?php foreach (AVBK_Tshirt_Order::get_available_designs() as $d) : ?>
                                                    <option value="<?php echo esc_attr($d['id']); ?>"><?php echo esc_html($d['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="items[<?php echo (int) $i; ?>][product_type]">
                                                <?php foreach (AVBK_Tshirt_Order::get_available_types() as $t) : ?>
                                                    <option value="<?php echo esc_attr($t['id']); ?>"><?php echo esc_html($t['name']); ?> (&euro;<?php echo esc_html(number_format((float) $t['price'], 2, ',', '.')); ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="items[<?php echo (int) $i; ?>][color]">
                                                <?php foreach (AVBK_Tshirt_Order::get_available_colors() as $c) : ?>
                                                    <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="items[<?php echo (int) $i; ?>][size]">
                                                <?php foreach (AVBK_Tshirt_Order::get_available_sizes() as $s) : ?>
                                                    <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" name="items[<?php echo (int) $i; ?>][quantity]" value="0" min="0" max="20" style="width: 4em;">
                                        </td>
                                        <td style="white-space: nowrap;">
                                            &euro; <input type="text" name="items[<?php echo (int) $i; ?>][price]" placeholder="auto" style="width: 5em;">
                                        </td>
                                    </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                            <p class="description">Laat "Aantal" op 0 staan voor een regel die je niet gebruikt. Laat "Prijs" leeg voor de huidige design/type-prijs, of vul een destijds afgesproken andere prijs in (bijv. pre-congres).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Uitreiking</th>
                        <td>
                            <label><input type="checkbox" name="already_distributed" value="1"> Dit is al geleverd/uitgereikt</label>
                            <p class="description">Vink aan voor een bestelling die al bij het lid is &mdash; telt dan niet mee in de inkoopmatrix hierboven (die is voor wat nog bij de leverancier besteld moet worden).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="avbk-add-order-notes">Notitie</label></th>
                        <td><input type="text" id="avbk-add-order-notes" name="notes" class="regular-text" placeholder="bijv. reden voor handmatige toevoeging"></td>
                    </tr>
                </table>
                <button type="submit" class="button button-primary">Bestelling toevoegen</button>
            </form>
        </details>

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
                <div style="font-size: 1.8rem; font-weight: bold; color: #1d2327;"><?php echo (int) $stat_total_shirts; ?></div>
                <div style="font-size: .85rem; color: #646970;"><?php echo (int) $stat_confirmed_orders; ?> bevestigde bestellingen (van <?php echo (int) $stat_total_orders; ?>)</div>
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
                <div style="font-size: 1.8rem; font-weight: bold; color: #dba617;"><?php echo (int) $stat_pending_dist; ?> open</div>
                <div style="font-size: .85rem; color: #646970;"><?php echo (int) $stat_collected_dist; ?> afgehaald &bull; <?php echo (int) $stat_distributed_dist; ?> uitgereikt</div>
            </div>
        </div>

        <?php
        $failed_tshirt_emails = AVBK_DB::count_failed_order_emails('tshirt');
        if ($failed_tshirt_emails > 0) : ?>
            <div class="notice notice-warning" style="display: flex; align-items: center; justify-content: space-between; padding: 10px 15px; margin: 1.25rem 0;">
                <p style="margin: 0; font-size: 0.95rem;">
                    <strong>&#9888; Let op:</strong> Er zijn <strong><?php echo (int) $failed_tshirt_emails; ?></strong> bestelling(en) waarbij de bevestigingsmail niet verzonden kon worden of een fout bevat (bijvoorbeeld door een typefout in het e-mailadres).
                </p>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-orders', 'tab' => 'tshirt', 'f_besteller' => 'mislukt'], admin_url('admin.php'))); ?>" class="button button-secondary" style="white-space: nowrap; margin-left: 15px;">
                    Toon mislukte e-mails &rarr;
                </a>
            </div>
        <?php endif; ?>

        <!-- Orders Table -->
        <div data-avpvh-sortable>
        <div class="avpvh-table-tools">
            <button type="button" class="button button-small avpvh-col-toggle-btn">Kolommen</button>
            <span class="avpvh-col-toggle-panel" hidden></span>
        </div>
        <div class="avpvh-table-wrap">
        <table data-storage-key="avbk_orders_tshirt_hidden_cols" class="widefat striped avpvh-sortable-table">
            <thead>
                <tr>
                    <th scope="col" data-col="id" data-type="number">ID</th>
                    <th scope="col" data-col="datum" data-type="date">Datum</th>
                    <th scope="col" data-col="besteller">Besteller</th>
                    <th scope="col" data-col="adres">Adres</th>
                    <th scope="col" data-col="shirts">Bestelde shirts</th>
                    <th scope="col" data-col="bedrag" data-type="number">Bedrag</th>
                    <th scope="col" data-col="betaling" data-filter="select">Betaling</th>
                    <th scope="col" data-col="uitreiking" data-filter="select">Uitreiking</th>
                    <th scope="col" data-col="opmerkingen">Opmerkingen</th>
                    <th scope="col"><span class="screen-reader-text">Acties</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)) : ?>
                    <tr>
                        <td colspan="10">Geen T-shirt bestellingen gevonden.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($orders as $order) :
                        $full_name          = trim($order->first_name . ' ' . $order->suffix) . ' ' . $order->last_name;
                        $is_paid            = $order->fee_status === 'waived' || ((float) $order->fee_paid >= (float) $order->total_amount - 0.005);
                        $member_profile_url = $order->member_id ? admin_url('admin.php?page=avpvh-member-detail&id=' . (int) $order->member_id) : '';
                        $email_suggestion   = AVBK_DB::suggest_email_fix((string) $order->email);
                        // email_sent = 0 by itself isn't reliable evidence of
                        // failure (see AVBK_DB::get_order()'s email_status
                        // comment, incident 2026-10-19) — only a captured
                        // email_error is.
                        $has_email_issue    = !empty($order->email_error);
                    ?>
                        <tr id="order-<?php echo (int) $order->id; ?>">
                            <td>#<?php echo (int) $order->id; ?></td>
                            <td data-sort-value="<?php echo esc_attr(wp_date('Y-m-d', strtotime($order->created_at))); ?>"><?php echo esc_html(wp_date('d-m-Y', strtotime($order->created_at))); ?></td>
                            <td>
                                <strong>
                                    <?php if ($member_profile_url) : ?>
                                        <a href="<?php echo esc_url($member_profile_url); ?>"><?php echo esc_html($full_name); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($full_name); ?>
                                        <span style="color: #646970; font-weight: normal; font-size: 0.8rem;" title="Deze bestelling is nog niet aan een ledenprofiel gekoppeld. Gebruik 'Koppel aan lid' via het envelop-icoon hieronder.">(niet gekoppeld)</span>
                                    <?php endif; ?>
                                </strong>
                                <br><small>
                                    <a href="mailto:<?php echo esc_attr($order->email); ?>"><?php echo esc_html($order->email); ?></a>
                                    <button type="button" class="button-link avbk-process-email-btn"
                                            data-order-id="<?php echo (int) $order->id; ?>"
                                            data-order-name="<?php echo esc_attr($full_name); ?>"
                                            data-order-email="<?php echo esc_attr($order->email); ?>"
                                            data-order-suggestion="<?php echo esc_attr($email_suggestion ?? ''); ?>"
                                            data-order-member-id="<?php echo (int) ($order->member_id ?? 0); ?>"
                                            data-order-status="<?php echo esc_attr($order->status); ?>"
                                            data-order-error="<?php echo esc_attr($order->email_error ?? ''); ?>"
                                            title="E-mailadres wijzigen of verwerken"
                                            style="color: #2271b1; text-decoration: none; margin-left: 4px; vertical-align: middle; cursor: pointer;">
                                        &#9998;
                                    </button>
                                </small>
                                <?php if ($order->phone) : ?>
                                    <br><small><?php echo esc_html($order->phone); ?></small>
                                <?php endif; ?>
                                <?php if ($has_email_issue) : ?>
                                    <br><button type="button" class="avbk-process-email-btn"
                                            data-order-id="<?php echo (int) $order->id; ?>"
                                            data-order-name="<?php echo esc_attr($full_name); ?>"
                                            data-order-email="<?php echo esc_attr($order->email); ?>"
                                            data-order-suggestion="<?php echo esc_attr($email_suggestion ?? ''); ?>"
                                            data-order-member-id="<?php echo (int) ($order->member_id ?? 0); ?>"
                                            data-order-status="<?php echo esc_attr($order->status); ?>"
                                            data-order-error="<?php echo esc_attr($order->email_error ?? ''); ?>"
                                            title="<?php echo esc_attr($order->email_error ? 'Fout: ' . $order->email_error . ' — Klik om te corrigeren' : 'E-mail niet verzonden — Klik om te corrigeren'); ?>"
                                            style="margin-top: 3px; padding: 2px 7px; border: 1px solid #d63638; border-radius: 3px; font-size: .75rem; font-weight: 500; background: #d63638; color: #fff; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; line-height: 1.3;">
                                        &#9888; E-mail mislukt &bull; Corrigeer &rarr;
                                    </button>
                                    <?php if (!empty($order->email_error)) : ?>
                                        <br><small style="color:#d63638; font-size:0.75rem;" title="<?php echo esc_attr($order->email_error); ?>"><?php echo esc_html(wp_trim_words($order->email_error, 5)); ?></small>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($email_suggestion) : ?>
                                    <br><button type="button" class="avbk-process-email-btn"
                                            data-order-id="<?php echo (int) $order->id; ?>"
                                            data-order-name="<?php echo esc_attr($full_name); ?>"
                                            data-order-email="<?php echo esc_attr($order->email); ?>"
                                            data-order-suggestion="<?php echo esc_attr($email_suggestion); ?>"
                                            data-order-member-id="<?php echo (int) ($order->member_id ?? 0); ?>"
                                            data-order-status="<?php echo esc_attr($order->status); ?>"
                                            data-order-error="<?php echo esc_attr($order->email_error ?? ''); ?>"
                                            title="Klik om suggestie toe te passen"
                                            style="margin-top: 3px; padding: 2px 7px; border: 1px solid #72aee6; border-radius: 3px; font-size: .75rem; background: #f0f6fc; color: #2271b1; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; line-height: 1.3;">
                                        &#128161; Bedoelde: <code><?php echo esc_html($email_suggestion); ?></code>? &rarr;
                                    </button>
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
                            <td data-sort-value="<?php echo esc_attr(number_format((float) $order->total_amount, 2, '.', '')); ?>">&euro;&nbsp;<?php echo number_format((float) $order->total_amount, 2, ',', '.'); ?></td>
                            <td data-filter-value="<?php echo $is_paid ? 'Betaald' : 'Open'; ?>">
                                <?php if ($is_paid) : ?>
                                    <span style="color: #00a32a; font-weight: bold;">&#10004; Betaald</span>
                                <?php else : ?>
                                    <span style="color: #d63638; font-weight: bold;">Open</span>
                                    <?php if (!empty($order->fee_item_id) && !empty($order->member_id)) : ?>
                                        <br><small><a href="<?php echo esc_url(admin_url('admin.php?page=avbk-members&member_id=' . (int) $order->member_id)); ?>" title="Bekijk bestelling in ledenoverzicht">Bestelling #<?php echo (int) $order->fee_item_id; ?> &rarr;</a></small>
                                    <?php endif; ?>
                                    <?php if ((float) $order->fee_paid > 0.005) : ?>
                                        <br><small>(&euro;&nbsp;<?php echo number_format((float) $order->fee_paid, 2, ',', '.'); ?> ontvangen)</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td data-filter-value="<?php echo esc_attr(['pending' => 'In afwachting', 'collected' => 'Opgehaald', 'distributed' => 'Uitgereikt'][$order->distribution_status] ?? $order->distribution_status); ?>">
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                                    <?php wp_nonce_field('avbk_update_order_distribution'); ?>
                                    <input type="hidden" name="action" value="avbk_update_order_distribution">
                                    <input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
                                    <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI'] ?? add_query_arg(['page' => 'avbk-orders', 'tab' => 'tshirt'], admin_url('admin.php'))); ?>">
                                    <select name="distribution_status" onchange="this.form.submit()" style="font-size: .85rem; padding: 2px 24px 2px 6px;" aria-label="Uitgiftestatus voor bestelling <?php echo (int) $order->id; ?>">
                                        <option value="pending" <?php selected($order->distribution_status, 'pending'); ?>>In afwachting</option>
                                        <option value="collected" <?php selected($order->distribution_status, 'collected'); ?>>Opgehaald</option>
                                        <option value="distributed" <?php selected($order->distribution_status, 'distributed'); ?>>Uitgereikt</option>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <?php echo $order->notes ? esc_html($order->notes) : '&mdash;'; ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small avbk-process-email-btn"
                                        data-order-id="<?php echo (int) $order->id; ?>"
                                        data-order-name="<?php echo esc_attr($full_name); ?>"
                                        data-order-email="<?php echo esc_attr($order->email); ?>"
                                        data-order-suggestion="<?php echo esc_attr($email_suggestion ?? ''); ?>"
                                        data-order-member-id="<?php echo (int) ($order->member_id ?? 0); ?>"
                                        data-order-status="<?php echo esc_attr($order->status); ?>"
                                        data-order-error="<?php echo esc_attr($order->email_error ?? ''); ?>"
                                        style="margin-bottom: 4px; width: 100%; white-space: nowrap;">
                                    &#9998; E-mail verwerken
                                </button>
                                <button type="button" class="button button-small button-link-delete avbk-delete-order-btn"
                                        data-order-id="<?php echo (int) $order->id; ?>"
                                        data-order-name="<?php echo esc_attr($full_name); ?>"
                                        data-order-email="<?php echo esc_attr($order->email); ?>"
                                        data-order-type="tshirt"
                                        style="color: #b32d2e; width: 100%;">
                                    &#128465; Verwijderen
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>

    <?php elseif ($active_tab === 'book') : ?>
        <?php
        // Filtering/sorting now happens client-side in the table itself
        // (avpvh-sortable-table, see avpvh-members/assets/sortable-table.js) — always load the full set.
        $all_orders = AVBK_DB::get_orders(['order_type' => 'book']);
        $orders = $all_orders;

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

        <!-- Handmatig een bestelling toevoegen — zie de T-shirts-tab voor
             dezelfde functie. Prijs per exemplaar is hier een vrij veld
             (niet automatisch de huidige avbk_book_price) zodat een oude,
             destijds afgesproken prijs (bijv. een pre-congres bestelling)
             kan worden ingevoerd zoals die toen gold, niet zoals de prijs
             nu toevallig is. -->
        <details class="card" style="max-width: 100%; margin: 0 0 1.5rem; padding: 1.25rem 1.5rem;">
            <summary style="cursor: pointer; font-weight: 600; font-size: 1.1rem;">&#10133; Bestelling toevoegen (handmatig)</summary>
            <p class="description">Voor een bestelling die niet via het bestelformulier binnenkwam — bijv. een pre-congres bestelling tegen een andere prijs dan nu, of op papier besteld. Maakt dezelfde post + bestelling aan als een normale bestelling (status: bevestigd); betalen/verrekenen en uitreiking-status gaan daarna via de gebruikelijke tools hieronder.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avbk_admin_add_book_order'); ?>
                <input type="hidden" name="action" value="avbk_admin_add_book_order">
                <input type="hidden" name="redirect_url" value="<?php echo esc_url(add_query_arg('tab', 'book', $page_url)); ?>">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="avbk-add-book-member">Lid</label></th>
                        <td>
                            <select name="member_id" id="avbk-add-book-member" required style="min-width: 260px;">
                                <option value="">&mdash; kies lid &mdash;</option>
                                <?php
                                $order_form_members = $all_members;
                                usort($order_form_members, fn($a, $b) => strcasecmp($a->last_name . $a->first_name, $b->last_name . $b->first_name));
                                foreach ($order_form_members as $m) :
                                ?>
                                    <option value="<?php echo esc_attr($m->id); ?>"><?php echo esc_html(avpvh_format_name($m, 'list')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="avbk-add-book-qty">Aantal</label></th>
                        <td><input type="number" id="avbk-add-book-qty" name="quantity" value="1" min="1" max="20" style="width: 6em;"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="avbk-add-book-price">Prijs per exemplaar</label></th>
                        <td>
                            &euro; <input type="text" id="avbk-add-book-price" name="unit_price" value="<?php echo esc_attr(number_format((float) (get_option('avbk_book_price', AVBK_Book_Order::DEFAULT_PRICE) ?: AVBK_Book_Order::DEFAULT_PRICE), 2, ',', '.')); ?>" style="width: 6em;">
                            <p class="description">Standaard de huidige prijs &mdash; pas aan voor een destijds afgesproken andere prijs (bijv. pre-congres).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Uitreiking</th>
                        <td>
                            <label><input type="checkbox" name="already_distributed" value="1"> Dit is al geleverd/uitgereikt</label>
                            <p class="description">Vink aan voor een bestelling die al bij het lid is &mdash; staat dan direct op "Uitgereikt" in plaats van "In afwachting".</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="avbk-add-book-notes">Notitie</label></th>
                        <td><input type="text" id="avbk-add-book-notes" name="notes" class="regular-text" placeholder="bijv. reden voor handmatige toevoeging"></td>
                    </tr>
                </table>
                <button type="submit" class="button button-primary">Bestelling toevoegen</button>
            </form>
        </details>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin: 1.5rem 0;">
            <div class="card" style="flex: 1 1 180px; margin: 0; padding: 1rem;">
                <h3 style="margin: 0 0 .5rem; font-size: .9rem; color: #50575e; text-transform: uppercase;">Exemplaren besteld</h3>
                <div style="font-size: 1.8rem; font-weight: bold; color: #1d2327;"><?php echo (int) $stat_total_books; ?></div>
                <div style="font-size: .85rem; color: #646970;"><?php echo (int) $stat_confirmed_orders; ?> bevestigde bestellingen (van <?php echo (int) $stat_total_orders; ?>)</div>
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
                <div style="font-size: 1.8rem; font-weight: bold; color: #dba617;"><?php echo (int) $stat_pending_dist; ?> open</div>
                <div style="font-size: .85rem; color: #646970;"><?php echo (int) $stat_collected_dist; ?> afgehaald &bull; <?php echo (int) $stat_distributed_dist; ?> uitgereikt</div>
            </div>
            <div class="card" style="flex: 1 1 180px; margin: 0; padding: 1rem;">
                <h3 style="margin: 0 0 .5rem; font-size: .9rem; color: #50575e; text-transform: uppercase;">Presentatie begin 2027</h3>
                <div style="font-size: 1.8rem; font-weight: bold; color: #2271b1;"><?php echo (int) $stat_attend_pres; ?> aanwezig</div>
                <div style="font-size: .85rem; color: #646970;"><?php echo (int) $stat_keep_updated; ?> wil op de hoogte blijven</div>
            </div>
        </div>

        <?php
        $failed_book_emails = AVBK_DB::count_failed_order_emails('book');
        if ($failed_book_emails > 0) : ?>
            <div class="notice notice-warning" style="display: flex; align-items: center; justify-content: space-between; padding: 10px 15px; margin: 1.25rem 0;">
                <p style="margin: 0; font-size: 0.95rem;">
                    <strong>&#9888; Let op:</strong> Er zijn <strong><?php echo (int) $failed_book_emails; ?></strong> boekbestelling(en) waarbij de bevestigingsmail niet verzonden kon worden of een fout bevat (bijvoorbeeld door een typefout in het e-mailadres).
                </p>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-orders', 'tab' => 'book', 'f_besteller' => 'mislukt'], admin_url('admin.php'))); ?>" class="button button-secondary" style="white-space: nowrap; margin-left: 15px;">
                    Toon mislukte e-mails &rarr;
                </a>
            </div>
        <?php endif; ?>

        <div data-avpvh-sortable>
        <div class="avpvh-table-tools">
            <button type="button" class="button button-small avpvh-col-toggle-btn">Kolommen</button>
            <span class="avpvh-col-toggle-panel" hidden></span>
        </div>
        <div class="avpvh-table-wrap">
        <table data-storage-key="avbk_orders_book_hidden_cols" class="widefat striped avpvh-sortable-table">
            <thead>
                <tr>
                    <th scope="col" data-col="id" data-type="number">ID</th>
                    <th scope="col" data-col="datum" data-type="date">Datum</th>
                    <th scope="col" data-col="besteller">Besteller</th>
                    <th scope="col" data-col="adres">Adres</th>
                    <th scope="col" data-col="aantal" data-type="number">Aantal</th>
                    <th scope="col" data-col="bedrag" data-type="number">Bedrag</th>
                    <th scope="col" data-col="betaling" data-filter="select">Betaling</th>
                    <th scope="col" data-col="presentatie" data-filter="select">Presentatie</th>
                    <th scope="col" data-col="uitreiking" data-filter="select">Uitreiking</th>
                    <th scope="col" data-col="opmerkingen">Opmerkingen</th>
                    <th scope="col"><span class="screen-reader-text">Acties</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)) : ?>
                    <tr>
                        <td colspan="11">Geen boekbestellingen gevonden.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($orders as $order) :
                        $full_name          = trim($order->first_name . ' ' . $order->suffix) . ' ' . $order->last_name;
                        $is_paid            = $order->fee_status === 'waived' || ((float) $order->fee_paid >= (float) $order->total_amount - 0.005);
                        $member_profile_url = $order->member_id ? admin_url('admin.php?page=avpvh-member-detail&id=' . (int) $order->member_id) : '';
                        $email_suggestion   = AVBK_DB::suggest_email_fix((string) $order->email);
                        // email_sent = 0 by itself isn't reliable evidence of
                        // failure (see AVBK_DB::get_order()'s email_status
                        // comment, incident 2026-10-19) — only a captured
                        // email_error is.
                        $has_email_issue    = !empty($order->email_error);
                    ?>
                        <tr id="order-<?php echo (int) $order->id; ?>">
                            <td>#<?php echo (int) $order->id; ?></td>
                            <td data-sort-value="<?php echo esc_attr(wp_date('Y-m-d', strtotime($order->created_at))); ?>"><?php echo esc_html(wp_date('d-m-Y', strtotime($order->created_at))); ?></td>
                            <td>
                                <strong>
                                    <?php if ($member_profile_url) : ?>
                                        <a href="<?php echo esc_url($member_profile_url); ?>"><?php echo esc_html($full_name); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($full_name); ?>
                                        <span style="color: #646970; font-weight: normal; font-size: 0.8rem;" title="Deze bestelling is nog niet aan een ledenprofiel gekoppeld. Gebruik 'Koppel aan lid' via het envelop-icoon hieronder.">(niet gekoppeld)</span>
                                    <?php endif; ?>
                                </strong>
                                <br><small>
                                    <a href="mailto:<?php echo esc_attr($order->email); ?>"><?php echo esc_html($order->email); ?></a>
                                    <button type="button" class="button-link avbk-process-email-btn"
                                            data-order-id="<?php echo (int) $order->id; ?>"
                                            data-order-name="<?php echo esc_attr($full_name); ?>"
                                            data-order-email="<?php echo esc_attr($order->email); ?>"
                                            data-order-suggestion="<?php echo esc_attr($email_suggestion ?? ''); ?>"
                                            data-order-member-id="<?php echo (int) ($order->member_id ?? 0); ?>"
                                            data-order-status="<?php echo esc_attr($order->status); ?>"
                                            data-order-error="<?php echo esc_attr($order->email_error ?? ''); ?>"
                                            title="E-mailadres wijzigen of verwerken"
                                            style="color: #2271b1; text-decoration: none; margin-left: 4px; vertical-align: middle; cursor: pointer;">
                                        &#9998;
                                    </button>
                                </small>
                                <?php if ($order->phone) : ?>
                                    <br><small><?php echo esc_html($order->phone); ?></small>
                                <?php endif; ?>
                                <?php if ($has_email_issue) : ?>
                                    <br><button type="button" class="avbk-process-email-btn"
                                            data-order-id="<?php echo (int) $order->id; ?>"
                                            data-order-name="<?php echo esc_attr($full_name); ?>"
                                            data-order-email="<?php echo esc_attr($order->email); ?>"
                                            data-order-suggestion="<?php echo esc_attr($email_suggestion ?? ''); ?>"
                                            data-order-member-id="<?php echo (int) ($order->member_id ?? 0); ?>"
                                            data-order-status="<?php echo esc_attr($order->status); ?>"
                                            data-order-error="<?php echo esc_attr($order->email_error ?? ''); ?>"
                                            title="<?php echo esc_attr($order->email_error ? 'Fout: ' . $order->email_error . ' — Klik om te corrigeren' : 'E-mail niet verzonden — Klik om te corrigeren'); ?>"
                                            style="margin-top: 3px; padding: 2px 7px; border: 1px solid #d63638; border-radius: 3px; font-size: .75rem; font-weight: 500; background: #d63638; color: #fff; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; line-height: 1.3;">
                                        &#9888; E-mail mislukt &bull; Corrigeer &rarr;
                                    </button>
                                    <?php if (!empty($order->email_error)) : ?>
                                        <br><small style="color:#d63638; font-size:0.75rem;" title="<?php echo esc_attr($order->email_error); ?>"><?php echo esc_html(wp_trim_words($order->email_error, 5)); ?></small>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($email_suggestion) : ?>
                                    <br><button type="button" class="avbk-process-email-btn"
                                            data-order-id="<?php echo (int) $order->id; ?>"
                                            data-order-name="<?php echo esc_attr($full_name); ?>"
                                            data-order-email="<?php echo esc_attr($order->email); ?>"
                                            data-order-suggestion="<?php echo esc_attr($email_suggestion); ?>"
                                            data-order-member-id="<?php echo (int) ($order->member_id ?? 0); ?>"
                                            data-order-status="<?php echo esc_attr($order->status); ?>"
                                            data-order-error="<?php echo esc_attr($order->email_error ?? ''); ?>"
                                            title="Klik om suggestie toe te passen"
                                            style="margin-top: 3px; padding: 2px 7px; border: 1px solid #72aee6; border-radius: 3px; font-size: .75rem; background: #f0f6fc; color: #2271b1; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; line-height: 1.3;">
                                        &#128161; Bedoelde: <code><?php echo esc_html($email_suggestion); ?></code>? &rarr;
                                    </button>
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
                            <td data-sort-value="<?php echo esc_attr(number_format((float) $order->total_amount, 2, '.', '')); ?>">&euro;&nbsp;<?php echo number_format((float) $order->total_amount, 2, ',', '.'); ?></td>
                            <td data-filter-value="<?php echo $is_paid ? 'Betaald' : 'Open'; ?>">
                                <?php if ($is_paid) : ?>
                                    <span style="color: #00a32a; font-weight: bold;">&#10004; Betaald</span>
                                <?php else : ?>
                                    <span style="color: #d63638; font-weight: bold;">Open</span>
                                    <?php if (!empty($order->fee_item_id) && !empty($order->member_id)) : ?>
                                        <br><small><a href="<?php echo esc_url(admin_url('admin.php?page=avbk-members&member_id=' . (int) $order->member_id)); ?>" title="Bekijk bestelling in ledenoverzicht">Bestelling #<?php echo (int) $order->fee_item_id; ?> &rarr;</a></small>
                                    <?php endif; ?>
                                    <?php if ((float) $order->fee_paid > 0.005) : ?>
                                        <br><small>(&euro;&nbsp;<?php echo number_format((float) $order->fee_paid, 2, ',', '.'); ?> ontvangen)</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td data-filter-value="<?php
                                $presentatie_parts = [];
                                if (!empty($order->attend_presentation)) { $presentatie_parts[] = 'Aanwezig'; }
                                if (!empty($order->keep_updated)) { $presentatie_parts[] = 'Info'; }
                                echo esc_attr($presentatie_parts ? implode(', ', $presentatie_parts) : 'Geen keuze');
                            ?>">
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
                            <td data-filter-value="<?php echo esc_attr(['pending' => 'In afwachting', 'collected' => 'Opgehaald', 'distributed' => 'Uitgereikt'][$order->distribution_status] ?? $order->distribution_status); ?>">
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                                    <?php wp_nonce_field('avbk_update_order_distribution'); ?>
                                    <input type="hidden" name="action" value="avbk_update_order_distribution">
                                    <input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
                                    <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI'] ?? add_query_arg(['page' => 'avbk-orders', 'tab' => 'book'], admin_url('admin.php'))); ?>">
                                    <select name="distribution_status" onchange="this.form.submit()" style="font-size: .85rem; padding: 2px 24px 2px 6px;" aria-label="Uitgiftestatus voor bestelling <?php echo (int) $order->id; ?>">
                                        <option value="pending" <?php selected($order->distribution_status, 'pending'); ?>>In afwachting</option>
                                        <option value="collected" <?php selected($order->distribution_status, 'collected'); ?>>Opgehaald</option>
                                        <option value="distributed" <?php selected($order->distribution_status, 'distributed'); ?>>Uitgereikt</option>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <?php echo $order->notes ? esc_html($order->notes) : '&mdash;'; ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small avbk-process-email-btn"
                                        data-order-id="<?php echo (int) $order->id; ?>"
                                        data-order-name="<?php echo esc_attr($full_name); ?>"
                                        data-order-email="<?php echo esc_attr($order->email); ?>"
                                        data-order-suggestion="<?php echo esc_attr($email_suggestion ?? ''); ?>"
                                        data-order-member-id="<?php echo (int) ($order->member_id ?? 0); ?>"
                                        data-order-status="<?php echo esc_attr($order->status); ?>"
                                        data-order-error="<?php echo esc_attr($order->email_error ?? ''); ?>"
                                        style="margin-bottom: 4px; width: 100%; white-space: nowrap;">
                                    &#9998; E-mail verwerken
                                </button>
                                <button type="button" class="button button-small button-link-delete avbk-delete-order-btn"
                                        data-order-id="<?php echo (int) $order->id; ?>"
                                        data-order-name="<?php echo esc_attr($full_name); ?>"
                                        data-order-email="<?php echo esc_attr($order->email); ?>"
                                        data-order-type="book"
                                        style="color: #b32d2e; width: 100%;">
                                    &#128465; Verwijderen
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>

    <?php elseif ($active_tab === 'settings') : ?>
        <?php
        $designs = AVBK_Tshirt_Order::get_available_designs();
        $types = AVBK_Tshirt_Order::get_available_types();
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
                        <p class="description">Bijv. <code>Alle prijzen zijn zonder btw.</code></p>
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

            <h3>Kledingstuk types &amp; prijzen</h3>
            <p class="description">Beheer de beschikbare types kledingstukken en hun vaste prijzen.</p>

            <table class="widefat striped" style="max-width: 600px; margin-bottom: 1.5rem;" id="avbk_types_editor_table">
                <thead>
                    <tr>
                        <th scope="col" style="width: 140px;">Type code</th>
                        <th scope="col" style="width: 200px;">Naam</th>
                        <th scope="col" style="width: 120px;">Prijs (&euro;)</th>
                    </tr>
                </thead>
                <tbody id="avbk_types_editor_body">
                    <?php foreach ($types as $idx => $t) : ?>
                        <tr>
                            <td>
                                <input type="text" name="tshirt_types[<?php echo (int) $idx; ?>][id]" value="<?php echo esc_attr($t['id'] ?? ''); ?>" style="width:100%;" required aria-label="Type code voor kledingstuk <?php echo (int) ($idx + 1); ?>">
                            </td>
                            <td>
                                <input type="text" name="tshirt_types[<?php echo (int) $idx; ?>][name]" value="<?php echo esc_attr($t['name'] ?? ''); ?>" style="width:100%;" required aria-label="Naam voor kledingstuk <?php echo (int) ($idx + 1); ?>">
                            </td>
                            <td>
                                <input type="number" step="0.50" name="tshirt_types[<?php echo (int) $idx; ?>][price]" value="<?php echo esc_attr(number_format((float) ($t['price'] ?? 21.00), 2, '.', '')); ?>" style="width:100%;" required aria-label="Prijs voor kledingstuk <?php echo (int) ($idx + 1); ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3>Kleding designs &amp; illustraties</h3>
            <p class="description">Beheer hier de beschikbare designs. Je kunt per item een naam, omschrijving, afbeeldings-URL (of relatief plugin-pad) opgeven.</p>

            <table class="widefat striped" style="max-width: 960px; margin-bottom: 1.5rem;" id="avbk_designs_editor_table">
                <thead>
                    <tr>
                        <th scope="col" style="width: 130px;">ID / Code</th>
                        <th scope="col" style="width: 200px;">Naam design</th>
                        <th scope="col">Omschrijving</th>
                        <th scope="col" style="width: 220px;">Foto URL / Pad</th>
                        <th scope="col" style="width: 85px;">Prijs (&euro;)</th>
                        <th scope="col" style="width: 55px; text-align: center;">Actief</th>
                    </tr>
                </thead>
                <tbody id="avbk_designs_editor_body">
                    <?php foreach ($designs as $idx => $d) : ?>
                        <tr>
                            <td>
                                <input type="text" name="tshirt_designs[<?php echo (int) $idx; ?>][id]" value="<?php echo esc_attr($d['id'] ?? ''); ?>" style="width:100%;" required aria-label="ID / code voor design <?php echo (int) ($idx + 1); ?>">
                            </td>
                            <td>
                                <input type="text" name="tshirt_designs[<?php echo (int) $idx; ?>][name]" value="<?php echo esc_attr($d['name'] ?? ''); ?>" style="width:100%;" required aria-label="Naam voor design <?php echo (int) ($idx + 1); ?>">
                            </td>
                            <td>
                                <input type="text" name="tshirt_designs[<?php echo (int) $idx; ?>][description]" value="<?php echo esc_attr($d['description'] ?? ''); ?>" style="width:100%;" aria-label="Omschrijving voor design <?php echo (int) ($idx + 1); ?>">
                            </td>
                            <td>
                                <input type="text" name="tshirt_designs[<?php echo (int) $idx; ?>][image]" value="<?php echo esc_attr($d['image'] ?? ''); ?>" placeholder="assets/images/design-1.png" style="width:100%;" aria-label="Foto URL of pad voor design <?php echo (int) ($idx + 1); ?>">
                            </td>
                            <td>
                                <input type="number" step="0.50" name="tshirt_designs[<?php echo (int) $idx; ?>][price]" value="<?php echo isset($d['price']) && $d['price'] !== '' && (float) $d['price'] > 0 ? esc_attr(number_format((float) $d['price'], 2, '.', '')) : ''; ?>" placeholder="Standaard" style="width:100%;" aria-label="Afwijkende prijs voor design <?php echo (int) ($idx + 1); ?>">
                            </td>
                            <td style="text-align: center;">
                                <input type="checkbox" name="tshirt_designs[<?php echo (int) $idx; ?>][active]" value="1" <?php checked(!empty($d['active'])); ?> aria-label="Design <?php echo (int) ($idx + 1); ?> actief">
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
                        <p class="description">Bijv. <code>De definitieve prijs hangt af van het totale aantal bestelde exemplaren. Alle prijzen zijn zonder btw.</code></p>
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
                        <textarea id="book_flaptekst" name="book_flaptekst" rows="12" class="large-text" style="width:100%; max-width:600px;"><?php echo esc_textarea(get_option('avbk_book_flaptekst', AVBK_Book_Order::DEFAULT_FLAPTEKST)); ?></textarea>
                        <p class="description">Ondersteunt alinea's, witregels en HTML (zoals <code>&lt;em&gt;</code>, <code>&lt;blockquote&gt;</code>, <code>&lt;br&gt;</code> en <code>&lt;strong&gt;</code>).</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Alle instellingen opslaan'); ?>
        </form>
        <?php
        $payment_error_key = 'avbk_product_payment_error_' . get_current_user_id();
        $payment_error = get_transient($payment_error_key);
        delete_transient($payment_error_key);
        if ($payment_error) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($payment_error); ?></p></div>
        <?php elseif (isset($_GET['product_payment_saved']) && $_GET['product_payment_saved'] === '1') : ?>
            <div class="notice notice-success"><p>Het betaalverzoek is opgeslagen.</p></div>
        <?php endif; ?>
        <?php AVBK_Product_Payment::render_settings('tshirt', 'T-shirts en hoodies'); ?>
        <?php AVBK_Product_Payment::render_settings('book', 'Boeken'); ?>
    <?php endif; ?>

    <!-- Delete order confirmation modal -->
    <div id="avbk-delete-order-modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
        <div style="background: #fff; border-radius: 4px; padding: 22px 24px; max-width: 480px; width: 90%; box-shadow: 0 4px 15px rgba(0,0,0,0.3); box-sizing: border-box;">
            <h3 style="margin-top: 0; color: #b32d2e; font-size: 1.2rem;">Bestelling verwijderen</h3>
            <p id="avbk-delete-order-desc" style="font-size: 0.95rem; margin-bottom: 0.75rem;">
                Weet je zeker dat je bestelling <strong id="avbk-delete-order-id"></strong> van <strong id="avbk-delete-order-name"></strong> wilt verwijderen?
            </p>
            <p style="font-size: 0.85rem; color: #646970; margin-bottom: 1.25rem;">
                De bijbehorende openstaande post in de boekhouding wordt hiermee ook automatisch opgeruimd (of kwijtgescholden indien reeds afgehandeld).
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="avbk-delete-order-form">
                <?php wp_nonce_field('avbk_delete_order'); ?>
                <input type="hidden" name="action" value="avbk_delete_order">
                <input type="hidden" name="order_id" id="avbk-delete-modal-order-id" value="">
                <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI'] ?? admin_url('admin.php?page=avbk-orders')); ?>">

                <div id="avbk-delete-email-option" style="margin: 15px 0; padding: 12px; background: #f0f0f1; border-radius: 4px; border: 1px solid #dcdcde;">
                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-size: 0.9rem;">
                        <input type="checkbox" name="send_email" id="avbk-delete-send-email" value="1" style="margin-top: 2px;">
                        <span>Stuur een annuleringsmail naar de besteller (<span id="avbk-delete-order-email" style="font-weight: 600;"></span>)</span>
                    </label>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" class="button" id="avbk-delete-modal-cancel">Annuleren</button>
                    <button type="submit" class="button button-primary" style="background: #b32d2e; border-color: #b32d2e;">Ja, verwijder bestelling</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Process order email modal -->
    <div id="avbk-process-email-modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
        <div style="background: #fff; border-radius: 4px; padding: 22px 24px; max-width: 520px; width: 90%; box-shadow: 0 4px 15px rgba(0,0,0,0.3); box-sizing: border-box; max-height: 90vh; overflow-y: auto;">
            <h3 style="margin-top: 0; color: #1d2327; font-size: 1.2rem;">E-mailadres bestelling verwerken</h3>
            <p style="font-size: 0.95rem; margin-bottom: 0.75rem; color: #50575e;">
                Bestelling <strong id="avbk-pe-order-id"></strong> van <strong id="avbk-pe-order-name"></strong>
            </p>

            <div id="avbk-pe-error-box" style="display: none; background: #fcf0f1; border-left: 4px solid #d63638; padding: 8px 12px; margin-bottom: 14px; font-size: 0.85rem; color: #b32d2e;">
                <strong>Verzendfout:</strong> <span id="avbk-pe-error-text"></span>
            </div>

            <div id="avbk-pe-suggestion-box" style="display: none; background: #f0f6fc; border-left: 4px solid #2271b1; padding: 8px 12px; margin-bottom: 14px; font-size: 0.85rem; align-items: center; justify-content: space-between; gap: 8px;">
                <div>
                    <span>&#128161; Mogelijke typefout herkend: <strong id="avbk-pe-suggested-email"></strong></span>
                </div>
                <button type="button" class="button button-small" id="avbk-pe-apply-suggestion" style="white-space: nowrap;">Overnemen</button>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="avbk-process-email-form">
                <?php wp_nonce_field('avbk_process_order_email'); ?>
                <input type="hidden" name="action" value="avbk_process_order_email">
                <input type="hidden" name="order_id" id="avbk-pe-form-order-id" value="">
                <input type="hidden" name="redirect_url" value="<?php echo esc_url($_SERVER['REQUEST_URI'] ?? admin_url('admin.php?page=avbk-orders')); ?>">

                <div style="margin-bottom: 14px;">
                    <label for="avbk-pe-email" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.9rem;">E-mailadres besteller:</label>
                    <input type="email" name="email" id="avbk-pe-email" class="regular-text" style="width: 100%;" required>
                </div>

                <div style="margin-bottom: 14px;">
                    <label for="avbk-pe-member" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.9rem;">Koppel aan lid (optioneel):</label>
                    <input type="text" id="avbk-pe-member" list="avbk-pe-member-list" autocomplete="off" placeholder="Typ om te zoeken… (leeg = gastbestelling)" style="width: 100%; max-width: 100%; box-sizing: border-box;">
                    <input type="hidden" name="member_id" id="avbk-pe-member-id" value="">
                    <datalist id="avbk-pe-member-list">
                        <?php foreach ($all_members as $m) :
                            // 'list' format ("Achternaam, Voornaam") so the
                            // visible order matches the last-name sort above,
                            // same convention as the member picker in the
                            // "Bestelling toevoegen" form further up this page.
                            $m_name = avpvh_format_name($m, 'list');
                        ?>
                            <option value="<?php echo esc_attr($m_name . ' (' . $m->email . ')'); ?>" data-member-id="<?php echo (int) $m->id; ?>" data-email="<?php echo esc_attr($m->email); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <p class="description" style="margin-top: 4px; font-size: 0.8rem;">
                        Als de besteller een lid is, wordt de bijbehorende boekhoudpost automatisch aan dit lid gekoppeld. Leeg e-mailadres hierboven? Kies hier het lid en het e-mailadres wordt overgenomen.
                    </p>
                </div>

                <div id="avbk-pe-confirm-box" style="margin: 12px 0; padding: 10px; background: #fff8e5; border-radius: 4px; border: 1px solid #dba617;">
                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-size: 0.9rem;">
                        <input type="checkbox" name="confirm_order" id="avbk-pe-confirm" value="1" checked style="margin-top: 2px;">
                        <span><strong>Bestelling direct bevestigen</strong><br><small style="color: #646970;">Status wijzigen van 'In afwachting van bevestiging' naar 'Bevestigd'.</small></span>
                    </label>
                </div>

                <div style="margin: 12px 0; padding: 10px; background: #f0f0f1; border-radius: 4px; border: 1px solid #dcdcde;">
                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-size: 0.9rem;">
                        <input type="checkbox" name="resend_email" id="avbk-pe-resend" value="1" checked style="margin-top: 2px;">
                        <span><strong>Bevestigingsmail (opnieuw) versturen</strong><br><small style="color: #646970;">Stuurt de e-mail met betaalinstructies direct naar het opgegeven e-mailadres.</small></span>
                    </label>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" class="button" id="avbk-pe-cancel">Annuleren</button>
                    <button type="submit" class="button button-primary">Opslaan en verwerken</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Delete order modal
        var delModal     = document.getElementById('avbk-delete-order-modal');
        var delCancelBtn = document.getElementById('avbk-delete-modal-cancel');
        var delOrderId   = document.getElementById('avbk-delete-modal-order-id');
        var delSpanId    = document.getElementById('avbk-delete-order-id');
        var delSpanName  = document.getElementById('avbk-delete-order-name');
        var delSpanEmail = document.getElementById('avbk-delete-order-email');
        var delEmailBox  = document.getElementById('avbk-delete-email-option');
        var delCheckbox  = document.getElementById('avbk-delete-send-email');

        document.querySelectorAll('.avbk-delete-order-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var id    = this.getAttribute('data-order-id');
                var name  = this.getAttribute('data-order-name');
                var email = this.getAttribute('data-order-email');

                delOrderId.value = id;
                delSpanId.textContent = '#' + id;
                delSpanName.textContent = name || 'de besteller';

                if (email && email.trim() !== '') {
                    delSpanEmail.textContent = email;
                    delEmailBox.style.display = 'block';
                    delCheckbox.checked = false;
                } else {
                    delEmailBox.style.display = 'none';
                    delCheckbox.checked = false;
                }

                delModal.style.display = 'flex';
            });
        });

        if (delCancelBtn) {
            delCancelBtn.addEventListener('click', function() {
                delModal.style.display = 'none';
            });
        }

        if (delModal) {
            delModal.addEventListener('click', function(e) {
                if (e.target === delModal) {
                    delModal.style.display = 'none';
                }
            });
        }

        // Process order email modal
        var peModal         = document.getElementById('avbk-process-email-modal');
        var peCancelBtn     = document.getElementById('avbk-pe-cancel');
        var peFormOrderId   = document.getElementById('avbk-pe-form-order-id');
        var peSpanId        = document.getElementById('avbk-pe-order-id');
        var peSpanName      = document.getElementById('avbk-pe-order-name');
        var peInputEmail    = document.getElementById('avbk-pe-email');
        var peMemberInput   = document.getElementById('avbk-pe-member');
        var peMemberIdInput = document.getElementById('avbk-pe-member-id');
        var peConfirmBox    = document.getElementById('avbk-pe-confirm-box');
        var peConfirmCheck  = document.getElementById('avbk-pe-confirm');
        var peResendCheck   = document.getElementById('avbk-pe-resend');
        var peErrorBox      = document.getElementById('avbk-pe-error-box');
        var peErrorText     = document.getElementById('avbk-pe-error-text');
        var peSuggBox       = document.getElementById('avbk-pe-suggestion-box');
        var peSuggEmail     = document.getElementById('avbk-pe-suggested-email');
        var peApplySuggBtn  = document.getElementById('avbk-pe-apply-suggestion');

        var currentSuggestion    = '';
        var lastAppliedMemberEmail = '';

        // Looked up by the exact "Naam (email)" label text the datalist
        // option shows, and by member id (for pre-filling an already-linked
        // order) — built once from the <option>s already rendered in PHP
        // instead of duplicating the member list into a separate JS blob.
        var peMemberByLabel = {};
        var peMemberById    = {};
        document.querySelectorAll('#avbk-pe-member-list option').forEach(function (opt) {
            var entry = {
                id:    opt.getAttribute('data-member-id'),
                email: opt.getAttribute('data-email') || '',
                label: opt.value
            };
            peMemberByLabel[opt.value] = entry;
            peMemberById[entry.id] = entry;
        });

        function applyMemberSelection() {
            if (!peMemberInput || !peMemberIdInput) return;
            var match = peMemberByLabel[peMemberInput.value];
            if (!match) {
                // Free text that doesn't (yet) match a known member: treat
                // as "no member" rather than keeping a stale id, but leave
                // the e-mail field alone — the visitor may still be typing
                // a search term.
                peMemberIdInput.value = '';
                return;
            }
            peMemberIdInput.value = match.id;
            // Overwrite the e-mail field only when it's still empty or
            // still holds the previously-selected member's e-mail — a
            // value the visitor typed in by hand is never clobbered, but
            // picking a *different* member now does update it (previously
            // it only filled once, when the field was empty).
            if (!peInputEmail.value || peInputEmail.value === lastAppliedMemberEmail) {
                peInputEmail.value = match.email;
            }
            lastAppliedMemberEmail = match.email;
        }
        if (peMemberInput) {
            peMemberInput.addEventListener('input', applyMemberSelection);
        }

        document.querySelectorAll('.avbk-process-email-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var id       = this.getAttribute('data-order-id');
                var name     = this.getAttribute('data-order-name');
                var email    = this.getAttribute('data-order-email');
                var sugg     = this.getAttribute('data-order-suggestion');
                var memberId = this.getAttribute('data-order-member-id');
                var status   = this.getAttribute('data-order-status');
                var error    = this.getAttribute('data-order-error');

                peFormOrderId.value = id;
                peSpanId.textContent = '#' + id;
                peSpanName.textContent = name || 'de besteller';
                peInputEmail.value = email || '';

                var linkedMember = (memberId && memberId !== '0') ? peMemberById[memberId] : null;
                if (peMemberInput && peMemberIdInput) {
                    peMemberInput.value = linkedMember ? linkedMember.label : '';
                    peMemberIdInput.value = linkedMember ? linkedMember.id : '';
                }
                lastAppliedMemberEmail = linkedMember ? linkedMember.email : '';
                // Order already correctly linked to a member (e.g. a name
                // mismatch resolved via "Koppel aan lid") but has no e-mail
                // of its own — pull the member's known e-mail instead of
                // leaving a required field empty with nothing to type.
                if (!peInputEmail.value && linkedMember) {
                    peInputEmail.value = linkedMember.email;
                }

                currentSuggestion = sugg || '';
                if (currentSuggestion && currentSuggestion !== email) {
                    peSuggEmail.textContent = currentSuggestion;
                    peSuggBox.style.display = 'flex';
                } else {
                    peSuggBox.style.display = 'none';
                }

                if (error && error.trim() !== '') {
                    peErrorText.textContent = error;
                    peErrorBox.style.display = 'block';
                } else {
                    peErrorBox.style.display = 'none';
                }

                if (status === 'pending_confirmation') {
                    peConfirmBox.style.display = 'block';
                    peConfirmCheck.checked = true;
                } else {
                    peConfirmBox.style.display = 'none';
                    peConfirmCheck.checked = false;
                }

                peResendCheck.checked = true;
                peModal.style.display = 'flex';
            });
        });

        if (peApplySuggBtn) {
            peApplySuggBtn.addEventListener('click', function() {
                if (currentSuggestion) {
                    peInputEmail.value = currentSuggestion;
                    peSuggBox.style.display = 'none';
                }
            });
        }

        if (peCancelBtn) {
            peCancelBtn.addEventListener('click', function() {
                peModal.style.display = 'none';
            });
        }

        if (peModal) {
            peModal.addEventListener('click', function(e) {
                if (e.target === peModal) {
                    peModal.style.display = 'none';
                }
            });
        }
    });
    </script>
</div>
