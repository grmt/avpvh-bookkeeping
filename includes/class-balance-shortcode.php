<?php
defined('ABSPATH') || exit;

/**
 * [avpvh_bk_balance] — full itemized fee history + running balance, meant
 * to sit right after [avpvh_member_profile] on the profile page (that
 * shortcode's class has no hook to inject into, so this is its own
 * separate shortcode rather than a patch to class-member-profile-form.php).
 *
 * Access mirrors the profile page: a viewer sees their own data, or a
 * household/family member's (AVPVH_DB::get_manageable_members — the same
 * self-or-household rule the profile form itself uses), or — via
 * ?member_id= — any member if they're bestuur (penningmeester included,
 * since AVPVH_Roles folds officer roles into bestuur) or a real WP admin.
 */
class AVBK_Balance_Shortcode {

    public function __construct() {
        add_shortcode('avpvh_bk_balance', [$this, 'render']);
        add_action('wp_enqueue_scripts', function () {
            wp_enqueue_style('avbk-balance', AVBK_PLUGIN_URL . 'assets/balance.css', [], avbk_asset_version('assets/balance.css'));
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
            $allowed_ids = wp_list_pluck(AVPVH_DB::get_manageable_members((int) $own_member->id), 'id');
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
        // (see AVBK_Matcher's docblock: "Lidgeld Anna, Bram en
        // Cas"). Candidates are $target_member's own household, not
        // the viewer's — the two only differ when bestuur/admin is viewing
        // someone else's page via ?member_id=, and it's that person's
        // household that makes sense to combine with.
        $household = array_values(array_filter(
            AVPVH_DB::get_manageable_members($target_id),
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

        $items = [];
        $total_due = 0.0;
        $total_paid = 0.0;
        foreach ($combined_ids as $mid) {
            $b = AVBK_DB::get_member_balance($mid);
            array_push($items, ...$b['items']);
            $total_due += $b['total_due'];
            $total_paid += $b['total_paid'];
        }
        $balance = [
            'items'      => $items,
            'total_due'  => round($total_due, 2),
            'total_paid' => round($total_paid, 2),
            'balance'    => round($total_due - $total_paid, 2),
        ];

        ob_start();
        ?>
        <div class="avbk-balance">
            <h2>Bijdrage &mdash; <?php echo esc_html(avpvh_format_name($target_member)); ?></h2>

            <?php if (!empty($_GET['dispute_sent'])) : ?>
                <p class="avbk-balance-notice">Je bericht is verstuurd naar de penningmeester.</p>
            <?php endif; ?>
            <p class="avbk-balance-processed">Betalingen zijn verwerkt tot en met <?php echo esc_html(wp_date('d-m-Y', strtotime(AVBK_DB::get_last_processed_date()))); ?>.</p>

            <?php if ($household) : ?>
                <form method="get" class="avbk-balance-also-form">
                    <?php if ($requested_id) : ?><input type="hidden" name="member_id" value="<?php echo esc_attr($target_id); ?>"><?php endif; ?>
                    <fieldset>
                        <legend>Betaal ook voor:</legend>
                        <?php foreach ($household as $hm) : ?>
                            <label>
                                <input type="checkbox" name="also[]" value="<?php echo esc_attr($hm->id); ?>" <?php checked(in_array((int) $hm->id, $also_ids, true)); ?>>
                                <?php echo esc_html(avpvh_format_name($hm)); ?>
                            </label>
                        <?php endforeach; ?>
                        <button type="submit" class="button button-small">Toepassen</button>
                    </fieldset>
                </form>
            <?php endif; ?>

            <table class="avbk-balance-table">
                <thead>
                    <tr>
                        <?php if ($show_member_column) : ?><th>Lid</th><?php endif; ?>
                        <th>Omschrijving</th><th>Tarief</th><th>Aantal</th><th>Bedrag</th><th>Betaald</th><th>Openstaand</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$balance['items']) : ?>
                    <tr><td colspan="<?php echo $show_member_column ? 8 : 7; ?>">Nog geen bijdragen geregistreerd.</td></tr>
                <?php else : foreach ($balance['items'] as $item) :
                    $status = $item->status === 'waived'
                        ? 'Kwijtgescholden'
                        : ($item->remaining <= 0.005 ? 'Betaald' : 'Open');
                    $parts = AVBK_DB::split_fee_description((string) $item->description);
                    $qty = AVBK_DB::fee_item_quantity_label($item);
                    ?>
                    <tr>
                        <?php if ($show_member_column) : ?>
                            <td><?php echo esc_html(avpvh_format_name($combined_members[(int) $item->member_id] ?? $target_member)); ?></td>
                        <?php endif; ?>
                        <td>
                            <?php echo esc_html($parts['base']); ?>
                            <?php if (!empty($item->is_estimated)) : ?>
                                <br><span style="color:#b32d2e;font-weight:600">&#9888; <?php echo esc_html($item->estimate_reason ?: 'Geschat bedrag.'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $parts['label'] !== '' ? esc_html($parts['label']) : '&mdash;'; ?></td>
                        <td><?php echo $qty !== '' ? esc_html($qty) : '&mdash;'; ?></td>
                        <td>&euro; <?php echo esc_html(number_format((float) $item->amount_due, 2, ',', '.')); ?></td>
                        <td>&euro; <?php echo esc_html(number_format((float) $item->paid, 2, ',', '.')); ?></td>
                        <td>&euro; <?php echo esc_html(number_format((float) $item->remaining, 2, ',', '.')); ?></td>
                        <td><?php echo esc_html($status); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="<?php echo $show_member_column ? 6 : 5; ?>">Totaal openstaand</th>
                        <th colspan="2">&euro; <?php echo esc_html(number_format((float) $balance['balance'], 2, ',', '.')); ?></th>
                    </tr>
                </tfoot>
            </table>
            <?php if ($balance['balance'] > 0.005) :
                if ($show_member_column) {
                    $entries = [];
                    foreach ($items as $item) {
                        if ($item->status === 'waived' || $item->remaining <= 0.005) {
                            continue;
                        }
                        $entries[] = ['item' => $item, 'name' => ($combined_members[(int) $item->member_id] ?? $target_member)->first_name];
                    }
                    $qr = AVBK_QR::for_combined_balance($target_id, $balance['balance'], $entries);
                    $reference_text = AVBK_QR::remittance_for_combined($entries, $target_id);
                } else {
                    $qr = AVBK_QR::for_member_balance($target_id, $balance['balance'], $balance['items']);
                    $reference_text = AVBK_QR::remittance_for_balance($balance['items'], $target_id);
                }
                ?>
                <?php if ($qr) : ?>
                    <div class="avbk-balance-qr"><?php echo $qr; ?></div>
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
        $allowed_ids = wp_list_pluck(AVPVH_DB::get_manageable_members((int) $own_member->id), 'id');
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
