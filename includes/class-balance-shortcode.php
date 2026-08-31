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

    public function __construct() {
        add_shortcode('avpvh_bk_balance', [$this, 'render']);
        add_action('wp_enqueue_scripts', function () {
            wp_enqueue_style('avbk-balance', AVBK_PLUGIN_URL . 'assets/balance.css', [], avbk_asset_version('assets/balance.css'));
            wp_enqueue_script('avbk-balance', AVBK_PLUGIN_URL . 'assets/balance.js', [], avbk_asset_version('assets/balance.js'), true);
        });
        add_action('admin_post_avbk_submit_dispute', [$this, 'handle_dispute']);
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
        $closed_through_year = (int) get_option('avbk_closed_through_year', 0);

        $items = [];
        $total_due = 0.0;
        $total_paid = 0.0;
        foreach ($combined_ids as $mid) {
            $b = AVBK_DB::get_member_balance($mid);
            foreach ($b['items'] as $item) {
                if ($closed_through_year && AVBK_DB::fee_item_book_year($item) <= $closed_through_year) {
                    continue;
                }
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
        $open_item_ids = array_map(
            fn($item) => (int) $item->id,
            array_values(array_filter($items, fn($item) => $item->status !== 'waived' && $item->remaining > 0.005))
        );
        $requested_pay_ids = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($_GET['pay'] ?? [])
        ))));
        $selected_pay_ids = isset($_GET['pay'])
            ? array_values(array_intersect($requested_pay_ids, $open_item_ids))
            : $open_item_ids;
        $selected_items = array_values(array_filter(
            $items,
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

            <div class="avbk-balance-table-tools">
                <button type="button" class="button button-small avbk-col-toggle-btn">Kolommen</button>
                <div class="avbk-col-toggle-panel" hidden></div>
            </div>
            <div class="avbk-balance-table-wrap">
            <table class="avbk-balance-table" id="avbk-balance-table">
                <thead>
                    <tr class="avbk-balance-header-row">
                        <?php if ($balance['balance'] > 0.005) : ?><th data-col="betalen">Betalen</th><?php endif; ?>
                        <?php if ($show_member_column) : ?><th data-col="lid" data-filter="select">Lid</th><?php endif; ?>
                        <th data-col="omschrijving">Omschrijving</th>
                        <th data-col="tarief" class="avbk-col-optional">Tarief</th>
                        <th data-col="aantal" class="avbk-col-optional">Aantal</th>
                        <th data-col="bedrag" data-type="number">Bedrag</th>
                        <th data-col="betaald" data-type="number" class="avbk-col-optional">Betaald</th>
                        <th data-col="openstaand" data-type="number">Openstaand</th>
                        <th data-col="status" data-filter="select">Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$balance['items']) : ?>
                    <tr><td colspan="<?php echo ($show_member_column ? 8 : 7) + ($balance['balance'] > 0.005 ? 1 : 0); ?>">Nog geen bijdragen geregistreerd.</td></tr>
                <?php else : foreach ($balance['items'] as $item) :
                    $status = $item->status === 'waived'
                        ? 'Kwijtgescholden'
                        : ($item->remaining <= 0.005 ? 'Betaald' : 'Open');
                    $status_class = $item->status === 'waived'
                        ? 'avbk-status-waived'
                        : ($item->remaining <= 0.005 ? 'avbk-status-paid' : 'avbk-status-open');
                    $parts = AVBK_DB::split_fee_description((string) $item->description);
                    $qty = AVBK_DB::fee_item_quantity_label($item);
                    ?>
                    <tr class="<?php echo esc_attr($status_class); ?>">
                        <?php if ($balance['balance'] > 0.005) : ?>
                            <td>
                                <?php if ($item->status !== 'waived' && $item->remaining > 0.005) : ?>
                                    <input type="checkbox" name="pay[]" value="<?php echo esc_attr($item->id); ?>" form="avbk-balance-payment-selection" <?php checked(in_array((int) $item->id, $selected_pay_ids, true)); ?>>
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
                    // One <th> per logical column (no colspan) so this row
                    // lines up correctly with thead/tbody regardless of
                    // which columns the visitor has hidden — colspan cells
                    // don't collapse cleanly across columns hidden via
                    // display:none and end up drifting out of alignment.
                    ?>
                    <tr>
                        <?php if ($balance['balance'] > 0.005) : ?><th></th><?php endif; ?>
                        <?php if ($show_member_column) : ?><th>Totaal</th><?php else : ?><th class="avbk-col-description">Totaal</th><?php endif; ?>
                        <?php if ($show_member_column) : ?><th class="avbk-col-description"></th><?php endif; ?>
                        <th class="avbk-col-optional"></th>
                        <th class="avbk-col-optional"></th>
                        <th></th>
                        <th class="avbk-col-optional"></th>
                        <th class="avbk-cell-nowrap">&euro; <?php echo esc_html(number_format((float) $balance['balance'], 2, ',', '.')); ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
            </div>
            <?php if ($balance['balance'] > 0.005) : ?>
                <form id="avbk-balance-payment-selection" method="get" action="#bijdrage" class="avbk-balance-also-form">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($target_id); ?>">
                    <?php foreach ($also_ids as $also_id) : ?>
                        <input type="hidden" name="also[]" value="<?php echo esc_attr($also_id); ?>">
                    <?php endforeach; ?>
                    <button type="submit" class="button">QR voor selectie bijwerken</button>
                    <span>Geselecteerd: &euro; <?php echo esc_html(number_format($selected_total, 2, ',', '.')); ?></span>
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
                    <p class="avbk-balance-qr-ref">Gebruik bij een handmatige overschrijving de referentie:<br><code><?php echo esc_html($reference_text); ?></code></p>
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

        AVBK_DB::create_dispute($member_id, $message);

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
}
