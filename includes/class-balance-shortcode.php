<?php
defined('ABSPATH') || exit;

/**
 * [avpvh_bk_balance] — full itemized fee history + running balance, meant
 * to sit right after [avpvh_member_profile] on the profile page (that
 * shortcode's class has no hook to inject into, so this is its own
 * separate shortcode rather than a patch to class-member-profile-form.php).
 *
 * Access mirrors the profile page plus one step further: a viewer sees
 * their own data, or a household/family member's or that household's own
 * partners' (AVPVH_DB::get_extended_household() — one hop wider than the
 * profile form's own get_manageable_members(), e.g. a housemate's
 * boyfriend/girlfriend who isn't blood family and may not even be a full
 * member), or — via ?member_id= — any member if they're bestuur
 * (penningmeester included, since AVPVH_Roles folds officer roles into
 * bestuur) or a real WP admin.
 */
class AVBK_Balance_Shortcode {

    /**
     * Pseudo fee-item id for the "Tegoed" row injected into the table
     * below — a real fee_item.id is always a positive DB auto-increment,
     * so a negative sentinel can never collide with one.
     */
    private const CREDIT_ROW_ID = -1;

    public function __construct() {
        add_shortcode('avpvh_bk_balance', [$this, 'render']);
        add_action('wp_enqueue_scripts', function () {
            // avpvh-members registers 'avpvh-sortable-table' on its own
            // wp_enqueue_scripts callback at priority 5, ahead of this
            // default-priority one — see enqueue_assets() in class-admin.php.
            wp_enqueue_style('avbk-balance', AVBK_PLUGIN_URL . 'assets/balance.css', ['avpvh-sortable-table'], avbk_asset_version('assets/balance.css'));
            wp_enqueue_script('avbk-balance', AVBK_PLUGIN_URL . 'assets/balance.js', ['avpvh-sortable-table'], avbk_asset_version('assets/balance.js'), true);
        });
        add_action('admin_post_avbk_submit_dispute', [$this, 'handle_dispute']);
        add_action('admin_post_avbk_apply_balance_selection', [$this, 'handle_apply_balance_selection']);
    }

    public function render(): string {
        if (!is_user_logged_in()) {
            return '';
        }
        $own_member = avpvh_get_member_by_wp_user(get_current_user_id());
        if (!$own_member) {
            return '';
        }

        $target_id = (int) $own_member->id;
        $requested_id = isset($_GET['member_id']) ? (int) $_GET['member_id'] : 0;

        if ($requested_id && $requested_id !== $target_id) {
            $can_view_any = current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('bestuur');
            $allowed_ids = wp_list_pluck(AVPVH_DB::get_extended_household((int) $own_member->id), 'id');
            if ($can_view_any || in_array($requested_id, array_map('intval', $allowed_ids), true)) {
                $target_id = $requested_id;
            }
            // Otherwise silently fall back to the viewer's own data rather
            // than revealing whether that member_id exists.
        }

        $target_member = AVPVH_DB::get_member($target_id);
        if (!$target_member) {
            return '';
        }

        // "Betaal ook voor" — fold a household/family member's open items
        // into this same view + a single combined QR, for the common
        // real-world case of one bank transfer covering a whole family
        // (see AVBK_Matcher's docblock: "Lidgeld Anna, Bram en Cas").
        // Candidates are $target_member's own household, not
        // the viewer's — the two only differ when bestuur/admin is viewing
        // someone else's page via ?member_id=, and it's that person's
        // household that makes sense to combine with.
        $household = array_values(array_filter(
            AVPVH_DB::get_extended_household($target_id),
            fn($m) => (int) $m->id !== $target_id
        ));
        $requested_also = array_map('intval', (array) ($_GET['also'] ?? []));
        $also_ids = array_values(array_intersect($requested_also, wp_list_pluck($household, 'id')));

        $combined_ids = array_merge([$target_id], $also_ids);
        $combined_members = [$target_id => $target_member];
        foreach ($household as $hm) {
            if (in_array((int) $hm->id, $also_ids, true)) {
                $combined_members[(int) $hm->id] = $hm;
            }
        }
        $show_member_column = count($combined_ids) > 1;

        // A closed year is (by definition) fully settled — hide its items
        // here entirely, from both the itemized list and the totals, so
        // what a member sees always matches what they're shown they owe.
        // Nothing is deleted or locked: AVBK_DB::get_member_balance() and
        // "Alle transacties" (with "toon oudere jaren") still have it all.
        // AVBK_DB::get_member_balance_excluding_closed() is the one shared
        // place this filtering happens — every other spot that shows a
        // member's balance (admin/members-balance.php, class-fee-popup.php)
        // calls it too, so a bug like fee-popup.php once having its own
        // unfiltered copy can't happen again by drifting out of sync here.
        $items = [];
        $total_due = 0.0;
        $total_paid = 0.0;
        foreach ($combined_ids as $mid) {
            $b = AVBK_DB::get_member_balance_excluding_closed($mid);
            foreach ($b['items'] as $item) {
                $items[] = $item;
                $total_due += $item->status === 'waived' ? 0.0 : (float) $item->amount_due;
                $total_paid += $item->status === 'waived' ? 0.0 : $item->paid;
            }
        }
        $balance = [
            'items'      => $items,
            'total_due'  => round($total_due, 2),
            'total_paid' => round($total_paid, 2),
            'balance'    => round($total_due - $total_paid, 2),
        ];

        // An explicit pay[] selection is used by the treasurer's payment-
        // request link and by the checkboxes below. With no parameter, all
        // open items stay selected, preserving the original whole-balance
        // behavior. IDs are intersected with the already access-checked
        // combined item list, so a crafted URL cannot expose/pay another
        // member's fee item.
        // Credit (teveel betaald, nog te verrekenen) — always the viewer's
        // own, never the combined household's: see AVBK_DB::apply_credit(),
        // which only ever applies a member's credit to that same member's
        // own open item. Shown as a negative-amount row in the same table
        // as every other item, selectable with the same checkbox, so
        // verrekenen is just "tick this plus whatever it covers" instead of
        // a separate form — only when there's actually something open to
        // net it against; AVBK_QR::remittance_for_balance()/
        // remittance_for_combined() already skip any item (real or this
        // one) with remaining <= 0.005, so a negative row never leaks into
        // the QR description or reference code on its own.
        $available_credit = AVBK_DB::get_total_credit_for_member($target_id);
        $own_open_items = array_values(array_filter(
            $items,
            fn($item) => (int) $item->member_id === $target_id && $item->status !== 'waived' && $item->remaining > 0.005
        ));
        // A joint ("en/of") account's leftover is one shared pot that every
        // co-owner independently sees as "their" credit (see
        // AVBK_DB::get_credit_transactions_for_member()) — applying it is
        // already safe (whoever does it first shrinks what the other still
        // sees), but it must stay a deliberate choice: never pre-ticked by
        // default like a solo credit is, and called out by name, so two
        // household members don't each casually "verreken" it via an
        // unrelated QR-update click without realizing it's the same money.
        $credit_coowner_ids = [];
        foreach (AVBK_DB::get_credit_transactions_for_member($target_id) as $credit_row) {
            foreach ($credit_row->shared_with_ids as $coowner_id) {
                $credit_coowner_ids[$coowner_id] = true;
            }
        }
        $credit_coowners = array_filter(array_map(
            fn($id) => AVPVH_DB::get_member($id),
            array_keys($credit_coowner_ids)
        ));
        $credit_item = null;
        if ($available_credit > 0.005 && $target_id === (int) $own_member->id && $own_open_items) {
            $credit_item = (object) [
                'id'              => self::CREDIT_ROW_ID,
                'member_id'       => $target_id,
                'description'     => 'Tegoed (eerder teveel betaald)',
                'type'            => '',
                'category'        => '',
                'activity_id'     => 0,
                'amount_due'      => -$available_credit,
                'paid'            => 0.0,
                'remaining'       => -$available_credit,
                'status'          => 'open',
                'is_estimated'    => 0,
                'estimate_reason' => '',
            ];
        }
        $display_items = $credit_item ? array_merge($items, [$credit_item]) : $items;

        $open_item_ids = array_map(
            fn($item) => (int) $item->id,
            array_values(array_filter($items, fn($item) => $item->status !== 'waived' && $item->remaining > 0.005))
        );
        $default_pay_ids = $open_item_ids;
        if ($credit_item) {
            $open_item_ids[] = self::CREDIT_ROW_ID;
            if (!$credit_coowners) {
                $default_pay_ids[] = self::CREDIT_ROW_ID;
            }
        }
        $requested_pay_ids = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($_GET['pay'] ?? [])
        ))));
        $selected_pay_ids = isset($_GET['pay'])
            ? array_values(array_intersect($requested_pay_ids, $open_item_ids))
            : $default_pay_ids;
        $selected_items = array_values(array_filter(
            $display_items,
            fn($item) => in_array((int) $item->id, $selected_pay_ids, true)
        ));
        $selected_total = round(array_sum(array_map(fn($item) => (float) $item->remaining, $selected_items)), 2);

        ob_start();
        ?>
        <div class="avbk-balance" id="bijdrage">
            <h2>Bijdrage &mdash; <?php echo esc_html(avpvh_format_name($target_member)); ?></h2>

            <?php if (!empty($_GET['dispute_sent'])) : ?>
                <p class="avbk-balance-notice">Je bericht is verstuurd naar de penningmeester.</p>
            <?php endif; ?>
            <p class="avbk-balance-processed">Betalingen zijn verwerkt tot en met <?php echo esc_html(wp_date('d-m-Y', strtotime(AVBK_DB::get_last_processed_date()))); ?>.</p>

            <?php if (!empty($_GET['credit_applied'])) : ?>
                <p class="avbk-balance-notice">Tegoed verrekend.</p>
            <?php endif; ?>
            <?php if ($available_credit > 0.005 && $target_id === (int) $own_member->id && !$own_open_items) : ?>
                <p class="avbk-balance-credit">Je hebt <strong>&euro; <?php echo esc_html(number_format($available_credit, 2, ',', '.')); ?> tegoed</strong> (van een eerdere betaling die meer was dan op dat moment openstond). Er staat op dit moment niets open om dit mee te verrekenen.
                <?php if ($credit_coowners) : ?>Dit komt van een rekening die ook op naam staat van <?php echo esc_html(implode(', ', array_map('avpvh_format_name', $credit_coowners))); ?> &mdash; die/diegene ziet dit bedrag ook, het is maar één keer te verrekenen.<?php endif; ?></p>
            <?php endif; ?>
            <?php if ($credit_item && $credit_coowners) : ?>
                <p class="avbk-balance-credit">Het tegoed hieronder komt van een rekening die ook op naam staat van <strong><?php echo esc_html(implode(', ', array_map('avpvh_format_name', $credit_coowners))); ?></strong> &mdash; die/diegene ziet dit bedrag ook op hun eigen overzicht. Het is maar één keer te verrekenen, dus de regel staat hieronder niet automatisch aangevinkt &mdash; stem eerst af wie het gebruikt.</p>
            <?php endif; ?>

            <?php
            $target_book_orders = AVBK_DB::get_book_orders_for_member((int) $target_id);
            $active_target_book_orders = array_values(array_filter($target_book_orders, fn($o) => $o->status !== 'cancelled'));
            if (!empty($active_target_book_orders)) :
                $total_ordered_books = array_sum(array_column($active_target_book_orders, 'quantity'));
                $book_title = get_option('avbk_book_title', 'Doorgraven! - 50 jaar AV Philips van Horne') ?: 'Doorgraven! - 50 jaar AV Philips van Horne';
            ?>
                <div class="avbk-profile-book-card">
                    <div class="avbk-profile-book-content">
                        <span class="avbk-profile-book-icon" aria-hidden="true">&#128214;</span>
                        <div class="avbk-profile-book-text">
                            <strong>Jubileumboek:</strong>
                            Je hebt <strong><?php echo (int) $total_ordered_books; ?> exempla<?php echo (int) $total_ordered_books === 1 ? 'ar' : 'ren'; ?></strong> van <em><?php echo esc_html($book_title); ?></em> besteld.
                            <div class="avbk-profile-book-action">
                                <a href="<?php echo esc_url(home_url('/boek/')); ?>">Bestelling bekijken, opmerking toevoegen of bijbestellen &rarr;</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($household) : ?>
                <form method="get" class="avbk-balance-also-form" action="#bijdrage">
                    <?php if ($requested_id) : ?><input type="hidden" name="member_id" value="<?php echo esc_attr($target_id); ?>"><?php endif; ?>
                    <fieldset>
                        <legend>Betaal ook voor:</legend>
                        <?php foreach ($household as $hm) : ?>
                            <label>
                                <input type="checkbox" name="also[]" value="<?php echo esc_attr($hm->id); ?>" <?php checked(in_array((int) $hm->id, $also_ids, true)); ?> onchange="this.form.requestSubmit()">
                                <?php echo esc_html(avpvh_format_name($hm)); ?>
                            </label>
                        <?php endforeach; ?>
                        <noscript><button type="submit" class="button button-small">Toepassen</button></noscript>
                    </fieldset>
                </form>
            <?php endif; ?>

            <div data-avpvh-sortable>
            <div class="avpvh-table-tools">
                <button type="button" class="button button-small avpvh-col-toggle-btn">Kolommen</button>
                <span class="avpvh-col-toggle-panel" hidden></span>
            </div>
            <div class="avpvh-table-wrap">
            <table class="avpvh-sortable-table" data-storage-key="avbk_balance_hidden_cols">
                <thead>
                    <tr>
                        <?php if ($balance['balance'] > 0.005) : ?><th scope="col" data-col="betalen">Betalen</th><?php endif; ?>
                        <?php if ($show_member_column) : ?><th scope="col" data-col="lid" data-filter="select">Lid</th><?php endif; ?>
                        <th scope="col" data-col="omschrijving">Omschrijving</th>
                        <th scope="col" data-col="tarief" class="avpvh-col-optional">Tarief</th>
                        <th scope="col" data-col="aantal" class="avpvh-col-optional">Aantal</th>
                        <th scope="col" data-col="bedrag" data-type="number">Bedrag</th>
                        <th scope="col" data-col="betaald" data-type="number" class="avpvh-col-optional">Betaald</th>
                        <th scope="col" data-col="openstaand" data-type="number">Openstaand</th>
                        <th scope="col" data-col="status" data-filter="select">Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$display_items) : ?>
                    <tr><td colspan="<?php echo ($show_member_column ? 8 : 7) + ($balance['balance'] > 0.005 ? 1 : 0); ?>">Nog geen bijdragen geregistreerd.</td></tr>
                <?php else : foreach ($display_items as $item) :
                    $is_credit_row = (int) $item->id === self::CREDIT_ROW_ID;
                    $status = $is_credit_row
                        ? 'Tegoed'
                        : ($item->status === 'waived' ? 'Kwijtgescholden' : ($item->remaining <= 0.005 ? 'Betaald' : 'Open'));
                    $status_class = $is_credit_row
                        ? 'avbk-status-credit'
                        : ($item->status === 'waived' ? 'avbk-status-waived' : ($item->remaining <= 0.005 ? 'avbk-status-paid' : 'avbk-status-open'));
                    $parts = AVBK_DB::split_fee_description((string) $item->description);
                    $qty = AVBK_DB::fee_item_quantity_label($item);
                    ?>
                    <tr class="<?php echo esc_attr($status_class); ?>">
                        <?php if ($balance['balance'] > 0.005) : ?>
                            <td>
                                <?php if ($is_credit_row || ($item->status !== 'waived' && $item->remaining > 0.005)) : ?>
                                    <input type="checkbox" name="pay[]" value="<?php echo esc_attr($item->id); ?>" form="avbk-balance-payment-selection" <?php checked(in_array((int) $item->id, $selected_pay_ids, true)); ?> aria-label="<?php echo esc_attr(sprintf('Selecteer %s voor betaling', $parts['base'])); ?>">
                                <?php else : ?>&mdash;<?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <?php if ($show_member_column) : ?>
                            <td class="avbk-cell-wrap-words"><?php echo esc_html(avpvh_format_name($combined_members[(int) $item->member_id] ?? $target_member)); ?></td>
                        <?php endif; ?>
                        <td class="avbk-col-description">
                            <?php echo esc_html($parts['base']); ?>
                            <?php if (!empty($item->is_estimated)) : ?>
                                <span class="avbk-estimate-flag" tabindex="0" role="button" aria-label="Toelichting op geschat bedrag">
                                    &#9888;
                                    <span class="avbk-estimate-tooltip" role="tooltip"><?php echo esc_html($item->estimate_reason ?: 'Geschat bedrag.'); ?></span>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $parts['label'] !== '' ? esc_html($parts['label']) : '&mdash;'; ?></td>
                        <td><?php echo $qty !== '' ? esc_html($qty) : '&mdash;'; ?></td>
                        <td class="avbk-cell-nowrap">&euro; <?php echo esc_html(number_format((float) $item->amount_due, 2, ',', '.')); ?></td>
                        <td class="avbk-cell-nowrap">&euro; <?php echo esc_html(number_format((float) $item->paid, 2, ',', '.')); ?></td>
                        <td class="avbk-cell-nowrap">&euro; <?php echo esc_html(number_format((float) $item->remaining, 2, ',', '.')); ?></td>
                        <td><span class="avbk-status-badge"><?php echo esc_html($status); ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <?php
                    // One cell per logical column (no colspan) so this row
                    // lines up correctly with thead/tbody regardless of
                    // which columns the visitor has hidden — colspan cells
                    // don't collapse cleanly across columns hidden via
                    // display:none and end up drifting out of alignment.
                    ?>
                    <tr>
                        <?php if ($balance['balance'] > 0.005) : ?><td></td><?php endif; ?>
                        <?php if ($show_member_column) : ?><th scope="row">Totaal</th><?php else : ?><th scope="row" class="avbk-col-description">Totaal</th><?php endif; ?>
                        <?php if ($show_member_column) : ?><td class="avbk-col-description"></td><?php endif; ?>
                        <td class="avpvh-col-optional"></td>
                        <td class="avpvh-col-optional"></td>
                        <td></td>
                        <td class="avpvh-col-optional"></td>
                        <td class="avbk-cell-nowrap">&euro; <?php echo esc_html(number_format((float) $balance['balance'], 2, ',', '.')); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </div>
            </div>
            <?php if ($balance['balance'] > 0.005) : ?>
                <form id="avbk-balance-payment-selection" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-balance-also-form">
                    <?php wp_nonce_field('avbk_apply_balance_selection'); ?>
                    <input type="hidden" name="action" value="avbk_apply_balance_selection">
                    <input type="hidden" name="redirect_url" value="<?php echo esc_url(get_permalink()); ?>">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($target_id); ?>">
                    <?php foreach ($also_ids as $also_id) : ?>
                        <input type="hidden" name="also[]" value="<?php echo esc_attr($also_id); ?>">
                    <?php endforeach; ?>
                    <button type="submit" class="button">QR voor selectie bijwerken</button>
                    <span>Geselecteerd: &euro; <?php echo esc_html(number_format($selected_total, 2, ',', '.')); ?></span>
                    <?php if ($credit_item) : ?>
                        <p class="description">Vink je tegoed aan samen met andere posten? Dan wordt het automatisch verrekend; de penningmeester krijgt hier een melding van.</p>
                    <?php endif; ?>
                </form>
                <?php
                if ($show_member_column) {
                    $entries = [];
                    foreach ($selected_items as $item) {
                        $entries[] = ['item' => $item, 'name' => ($combined_members[(int) $item->member_id] ?? $target_member)->first_name];
                    }
                    $qr = AVBK_QR::for_combined_balance($target_id, $selected_total, $entries);
                    $reference_text = AVBK_QR::remittance_for_combined($entries, $target_id);
                } else {
                    $qr = AVBK_QR::for_member_balance($target_id, $selected_total, $selected_items);
                    $reference_text = AVBK_QR::remittance_for_balance($selected_items, $target_id);
                }
                ?>
                <?php if ($qr) : ?>
                    <div class="avbk-balance-qr"><?php echo $qr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- server-rendered SVG from chillerlan/php-qrcode, not user input; esc_html() would break the markup. ?></div>
                    <p class="avbk-balance-qr-hint">Gebruik de QR code met de scan functie in je <strong>bankieren app</strong> (niet met de camera app) om de betaling klaar te zetten.</p>
                    <?php
                    $club_iban = trim((string) get_option('avbk_club_iban', ''));
                    $club_iban_display = $club_iban ? trim(chunk_split($club_iban, 4, ' ')) : '';
                    $club_name = trim((string) get_option('avbk_club_name', 'Archeologische Vereniging Philips van Horne'));
                    ?>
                    <p class="avbk-balance-qr-ref">Wil je de boeking zelf doen? Gebruik dan de volgende gegevens:<br>
                        <?php if ($club_iban) : ?>IBAN: <code><?php echo esc_html($club_iban_display); ?></code><br><?php endif; ?>
                        Ten name van: <?php echo esc_html($club_name); ?><br>
                        Omschrijving: <code><?php echo esc_html($reference_text); ?></code>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <details class="avbk-balance-dispute">
                <summary>Klopt dit niet? Stuur een bericht naar de penningmeester</summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avbk_submit_dispute'); ?>
                    <input type="hidden" name="action" value="avbk_submit_dispute">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($target_id); ?>">
                    <input type="hidden" name="redirect_url" value="<?php echo esc_url(get_permalink()); ?>">
                    <textarea name="message" rows="4" class="avbk-balance-dispute-textarea" required placeholder="Bijv.: ik heb dit al betaald, of dit bedrag klopt volgens mij niet, ..."></textarea>
                    <br>
                    <button type="submit" class="button">Versturen</button>
                </form>
            </details>

            <p class="avbk-balance-declare-link"><a href="<?php echo esc_url(home_url('/leden/beheer/declareren/')); ?>">Bonnetje declareren &rarr;</a></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * A member's "I don't understand/agree with this" message about their
     * own balance — mailed to the penningmeester immediately (best-effort;
     * a delivery failure doesn't block the dispute from still being
     * recorded) and kept as a todo item in AVBK_DB::get_disputes() /
     * admin/disputes.php, so it doesn't just disappear into an inbox.
     */
    public function handle_dispute(): void {
        check_admin_referer('avbk_submit_dispute');
        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }

        $member_id = (int) ($_POST['member_id'] ?? 0);
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        $redirect_url = esc_url_raw(wp_unslash($_POST['redirect_url'] ?? '')) ?: home_url('/');

        $own_member = avpvh_get_member_by_wp_user(get_current_user_id());
        if (!$own_member || !$message) {
            wp_safe_redirect($redirect_url);
            exit;
        }
        // Only allow disputing a balance the viewer was actually allowed to
        // see in the first place — same rule render() itself uses.
        $can_view_any = current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('bestuur');
        $allowed_ids = wp_list_pluck(AVPVH_DB::get_extended_household((int) $own_member->id), 'id');
        if ($member_id !== (int) $own_member->id && !$can_view_any && !in_array($member_id, array_map('intval', $allowed_ids), true)) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }

        $member = AVPVH_DB::get_member($member_id);
        if (!$member) {
            wp_safe_redirect($redirect_url);
            exit;
        }

        if (!AVBK_DB::create_dispute($member_id, $message, (int) $own_member->id)) {
            wp_die('Je bericht kon niet worden opgeslagen. Ga terug en probeer opnieuw.', 'Fout', ['response' => 500]);
        }

        $to = get_option('avbk_penningmeester_email', 'info@avphilipsvanhorne.nl');
        $submitted_by = avpvh_format_name($own_member);
        $subject = sprintf('[AV-PvH] Bezwaar/vraag over saldo van %s', avpvh_format_name($member));
        $body = sprintf(
            "%s heeft een bericht gestuurd over het openstaande saldo van %s.\n\nBericht:\n%s\n\nOverzicht: %s",
            $submitted_by,
            avpvh_format_name($member),
            $message,
            admin_url('admin.php?page=avbk-members&member_id=' . $member_id)
        );
        wp_mail($to, $subject, $body);

        wp_safe_redirect(add_query_arg('dispute_sent', '1', $redirect_url));
        exit;
    }

    /**
     * The balance page's single "QR voor selectie bijwerken" form posts
     * here with whatever was checked. Most submissions are a plain
     * selection update (no DB change — just which items the QR/total
     * below should reflect), but when the synthetic "Tegoed" row
     * (self::CREDIT_ROW_ID) is among the checked ids, this also applies
     * that credit — oldest-transaction-first via AVBK_DB::apply_credit_fifo()
     * — to the other checked, still-open items before redirecting, so
     * ticking the credit row alongside what it should cover is itself the
     * "verrekenen" action. Always the logged-in member's own credit
     * against their own items, never a household member's — the same
     * restriction AVBK_DB::apply_credit() itself enforces, and the only
     * reason the "Tegoed" row is ever rendered for $own_member in the
     * first place. The penningmeester gets a mailtje whenever credit was
     * actually applied, same as the self-service flow this replaces.
     */
    public function handle_apply_balance_selection(): void {
        check_admin_referer('avbk_apply_balance_selection');
        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }
        $redirect_url = esc_url_raw(wp_unslash($_POST['redirect_url'] ?? '')) ?: home_url('/');
        $own_member = avpvh_get_member_by_wp_user(get_current_user_id());
        if (!$own_member) {
            wp_safe_redirect($redirect_url);
            exit;
        }

        $member_id = (int) ($_POST['member_id'] ?? $own_member->id);
        $also_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['also'] ?? [])))));
        $requested_pay_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['pay'] ?? [])))));
        $credit_selected = in_array(self::CREDIT_ROW_ID, $requested_pay_ids, true);
        $real_pay_ids = array_values(array_filter($requested_pay_ids, fn($id) => $id !== self::CREDIT_ROW_ID));

        $applied_total = 0.0;
        $applied_count = 0;
        if ($credit_selected) {
            $own_open_items = array_values(array_filter(
                AVBK_DB::get_member_balance_excluding_closed((int) $own_member->id)['items'],
                fn($item) => in_array((int) $item->id, $real_pay_ids, true) && $item->status !== 'waived' && (float) $item->remaining > 0.005
            ));
            foreach ($own_open_items as $item) {
                $available = AVBK_DB::get_total_credit_for_member((int) $own_member->id);
                if ($available <= 0.005) {
                    break;
                }
                $applied = AVBK_DB::apply_credit_fifo((int) $own_member->id, (int) $item->id, min($available, (float) $item->remaining));
                if ($applied > 0.005) {
                    $applied_total = round($applied_total + $applied, 2);
                    $applied_count++;
                }
            }
            if ($applied_total > 0.005) {
                $to = get_option('avbk_penningmeester_email', 'info@avphilipsvanhorne.nl');
                $subject = sprintf('[AV-PvH] %s heeft tegoed verrekend', avpvh_format_name($own_member));
                $body = sprintf(
                    "%s heeft zelf € %s tegoed verrekend met %d openstaande post(en).\n\nOverzicht: %s",
                    avpvh_format_name($own_member),
                    number_format($applied_total, 2, ',', '.'),
                    $applied_count,
                    admin_url('admin.php?page=avbk-members&member_id=' . $own_member->id)
                );
                wp_mail($to, $subject, $body);
            }
        }

        $redirect = add_query_arg(array_filter([
            'member_id' => $member_id,
            'also'      => $also_ids ?: null,
            'pay'       => $real_pay_ids ?: null,
        ]), $redirect_url);
        if ($applied_total > 0.005) {
            $redirect = add_query_arg('credit_applied', '1', $redirect);
        }
        wp_safe_redirect($redirect . '#bijdrage');
        exit;
    }
}
