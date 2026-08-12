<?php
defined('ABSPATH') || exit;

/**
 * Login popup showing a member's real open balance (contribution + camp,
 * not just a single yes/no flag) with a scan-to-pay QR code. Supersedes
 * avpvh-members' AVPVH_Fee_Popup (which is left in place, unused).
 *
 * CSP on this site blocks inline scripts, including what wp_localize_script
 * generates — per avpvh-members/AGENTS.md, config is passed via a
 * <script type="application/json"> tag instead (same pattern as
 * AVPVH_Access::inject_login_form's #avpvh-login-config).
 */
class AVBK_Fee_Popup {

    const USER_META = '_avbk_show_popup';
    const DISMISS_COOKIE = 'avbk_popup_dismissed';

    public function __construct() {
        add_action('wp_login', [$this, 'check_on_login'], 10, 2);
        add_action('wp_footer', [$this, 'maybe_render_popup']);
        add_action('wp_ajax_avbk_dismiss_popup', [$this, 'ajax_dismiss']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function check_on_login(string $user_login, \WP_User $user): void {
        $member = avpvh_get_member_by_wp_user($user->ID);
        if (!$member || $member->status !== 'active') {
            return;
        }
        $balance = AVBK_DB::get_member_balance((int) $member->id);
        if ($balance['balance'] > 0.005) {
            update_user_meta($user->ID, self::USER_META, 1);
        } else {
            delete_user_meta($user->ID, self::USER_META);
        }
    }

    private function should_show(): ?object {
        if (!is_user_logged_in() || isset($_COOKIE[self::DISMISS_COOKIE])) {
            return null;
        }
        $user_id = get_current_user_id();
        if (!get_user_meta($user_id, self::USER_META, true)) {
            return null;
        }
        $member = avpvh_get_member_by_wp_user($user_id);
        return $member ?: null;
    }

    public function maybe_render_popup(): void {
        $member = $this->should_show();
        if (!$member) {
            return;
        }

        $balance = AVBK_DB::get_member_balance((int) $member->id);
        if ($balance['balance'] <= 0.005) {
            delete_user_meta(get_current_user_id(), self::USER_META);
            return;
        }

        $reference = AVBK_QR::remittance_for_balance($balance['items'], (int) $member->id);
        $qr_svg = AVBK_QR::for_member_balance((int) $member->id, $balance['balance'], $balance['items']);

        $config = wp_json_encode([
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('avbk_dismiss_popup'),
        ]);
        ?>
        <script type="application/json" id="avbk-fee-popup-config"><?php echo $config; ?></script>
        <div id="avbk-fee-popup" class="avbk-fee-popup-overlay" role="dialog" aria-modal="true" aria-labelledby="avbk-fee-popup-title">
            <div class="avbk-fee-popup-box">
                <h2 id="avbk-fee-popup-title">Openstaand saldo</h2>
                <ul class="avbk-fee-popup-items">
                    <?php foreach ($balance['items'] as $item) :
                        if ($item->status === 'waived' || $item->remaining <= 0.005) {
                            continue;
                        } ?>
                        <li>
                            <span>
                                <?php echo esc_html($item->description); ?>
                                <?php if (!empty($item->is_estimated)) : ?>
                                    <br><small style="color:#b32d2e;font-weight:600">&#9888; <?php echo esc_html($item->estimate_reason ?: 'Geschat bedrag.'); ?></small>
                                <?php endif; ?>
                            </span>
                            <span>&euro; <?php echo esc_html(number_format((float) $item->remaining, 2, ',', '.')); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="avbk-fee-popup-total">
                    Totaal open: <strong>&euro; <?php echo esc_html(number_format((float) $balance['balance'], 2, ',', '.')); ?></strong>
                </p>
                <?php if ($qr_svg) : ?>
                    <div class="avbk-fee-popup-qr"><?php echo $qr_svg; ?></div>
                    <p class="avbk-fee-popup-qr-hint">Gebruik de QR code met de scan functie in je <strong>bankieren app</strong> (niet met de camera app) om de betaling klaar te zetten.</p>
                    <p class="avbk-fee-popup-ref">Vermeld bij een overschrijving: <code><?php echo esc_html($reference); ?></code></p>
                <?php endif; ?>
                <button id="avbk-fee-dismiss" class="button"><?php esc_html_e('Sluiten', 'avpvh-bookkeeping'); ?></button>
            </div>
        </div>
        <?php
    }

    public function ajax_dismiss(): void {
        check_ajax_referer('avbk_dismiss_popup', 'nonce');
        delete_user_meta(get_current_user_id(), self::USER_META);
        wp_send_json_success();
    }

    public function enqueue_assets(): void {
        if (!$this->should_show()) {
            return;
        }
        wp_enqueue_style('avbk-fee-popup', AVBK_PLUGIN_URL . 'assets/fee-popup.css', [], avbk_asset_version('assets/fee-popup.css'));
        wp_enqueue_script('avbk-fee-popup', AVBK_PLUGIN_URL . 'assets/fee-popup.js', [], avbk_asset_version('assets/fee-popup.js'), true);
    }
}
