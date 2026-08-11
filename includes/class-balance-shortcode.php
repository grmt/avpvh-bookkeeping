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

        $balance = AVBK_DB::get_member_balance($target_id);

        ob_start();
        ?>
        <div class="avbk-balance">
            <h2>Bijdrage &mdash; <?php echo esc_html(avpvh_format_name($target_member)); ?></h2>
            <table class="avbk-balance-table">
                <thead>
                    <tr><th>Omschrijving</th><th>Bedrag</th><th>Betaald</th><th>Openstaand</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php if (!$balance['items']) : ?>
                    <tr><td colspan="5">Nog geen bijdragen geregistreerd.</td></tr>
                <?php else : foreach ($balance['items'] as $item) :
                    $status = $item->status === 'waived'
                        ? 'Kwijtgescholden'
                        : ($item->remaining <= 0.005 ? 'Betaald' : 'Open');
                    ?>
                    <tr>
                        <td>
                            <?php echo esc_html($item->description); ?>
                            <?php if (!empty($item->is_estimated)) : ?>
                                <br><span style="color:#b32d2e;font-weight:600">&#9888; <?php echo esc_html($item->estimate_reason ?: 'Geschat bedrag.'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>&euro; <?php echo esc_html(number_format((float) $item->amount_due, 2, ',', '.')); ?></td>
                        <td>&euro; <?php echo esc_html(number_format((float) $item->paid, 2, ',', '.')); ?></td>
                        <td>&euro; <?php echo esc_html(number_format((float) $item->remaining, 2, ',', '.')); ?></td>
                        <td><?php echo esc_html($status); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3">Totaal openstaand</th>
                        <th colspan="2">&euro; <?php echo esc_html(number_format((float) $balance['balance'], 2, ',', '.')); ?></th>
                    </tr>
                </tfoot>
            </table>
            <?php if ($balance['balance'] > 0.005) :
                $qr = AVBK_QR::for_member_balance($target_id, $balance['balance']); ?>
                <?php if ($qr) : ?>
                    <div class="avbk-balance-qr">
                        <?php echo $qr; ?>
                        <p>Referentie: <code><?php echo esc_html(AVBK_QR::reference_code($target_id)); ?></code></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
