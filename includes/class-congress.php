<?php
defined('ABSPATH') || exit;

/**
 * [avpvh_bk_congress] — public (no login required) sign-up page for the
 * congress/reunion. Three states rendered by the same shortcode, driven by
 * the query string:
 *  - no params: the sign-up form.
 *  - ?registered=1: "check your e-mail" thank-you (the common path — the
 *    confirm link/QR live behind the e-mailed token, not this URL).
 *  - ?token=...: the confirmation + QR-to-pay view. Visiting this link is
 *    itself what marks the registration confirmed (see render_confirmation)
 *    — a deliberate GET side effect, same shape as e.g. WooCommerce's
 *    order-received page; the token is the bearer credential, most
 *    registrants aren't existing WP users so there's no session to check
 *    against.
 */
class AVBK_Congress {

    public function __construct() {
        add_shortcode('avpvh_bk_congress', [$this, 'render']);
        add_action('wp_enqueue_scripts', function () {
            wp_enqueue_style('avbk-congress', AVBK_PLUGIN_URL . 'assets/congress.css', [], avbk_asset_version('assets/congress.css'));
        });
        add_action('admin_post_avbk_congress_register', [$this, 'handle_register']);
        add_action('admin_post_nopriv_avbk_congress_register', [$this, 'handle_register']);
    }

    public function render(): string {
        if (isset($_GET['token'])) {
            return $this->render_confirmation(sanitize_text_field(wp_unslash($_GET['token'])));
        }
        if (!empty($_GET['registered'])) {
            return $this->render_thanks();
        }
        return $this->render_form();
    }

    /**
     * "Congres" is just another activity (AV-PvH Leden -> Activiteiten) —
     * the most recent one of that type, same "most recent by year" rule
     * AVPVH_DB::get_current_activity() already uses for camps, so there's no
     * separate setting pointing at "the active congress". Null if the
     * treasurer hasn't created one yet.
     */
    private function get_current_congress_activity(): ?object {
        $congres_type = current(array_filter(
            AVPVH_DB::get_activity_types(),
            fn($t) => $t->name === 'Congres'
        ));
        return $congres_type ? AVPVH_DB::get_current_activity((int) $congres_type->id) : null;
    }

    private function render_form(): string {
        $activity = $this->get_current_congress_activity();
        $label = $activity ? $activity->name : 'Congres/Reünie';
        ob_start();
        ?>
        <div class="avbk-congress">
            <h2>Aanmelden &mdash; <?php echo esc_html($label); ?></h2>
            <?php if (!empty($_GET['congress_error'])) : ?>
                <p class="avbk-congress-notice avbk-congress-error">Vul alle velden in &mdash; voornaam, achternaam, e-mailadres en telefoonnummer zijn verplicht.</p>
            <?php endif; ?>
            <p>Vul onderstaand formulier in om je aan te melden. Na aanmelding ontvang je een bevestigingsmail met een link naar de QR-code om te betalen.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-congress-form">
                <?php wp_nonce_field('avbk_congress_register'); ?>
                <input type="hidden" name="action" value="avbk_congress_register">
                <input type="hidden" name="page_url" value="<?php echo esc_url(get_permalink()); ?>">
                <div class="avbk-congress-honeypot" aria-hidden="true">
                    <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>
                <p><label>Voornaam *<br><input type="text" name="first_name" required></label></p>
                <p><label>Tussenvoegsel<br><input type="text" name="suffix"></label></p>
                <p><label>Achternaam *<br><input type="text" name="last_name" required></label></p>
                <p><label>E-mailadres *<br><input type="email" name="email" required></label></p>
                <p><label>Telefoonnummer *<br><input type="tel" name="phone" required></label></p>
                <button type="submit" class="button button-primary">Aanmelden</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_thanks(): string {
        ob_start();
        ?>
        <div class="avbk-congress">
            <h2>Bedankt voor je aanmelding!</h2>
            <p>Check je e-mail: we hebben je een bevestigingslink gestuurd. Klik op de link in die e-mail om je aanmelding te bevestigen en de QR-code voor betaling te bekijken.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_confirmation(string $token): string {
        $reg = AVBK_DB::get_congress_registration_by_token($token);
        if (!$reg) {
            ob_start();
            ?>
            <div class="avbk-congress"><p>Ongeldige of verlopen link.</p></div>
            <?php
            return ob_get_clean();
        }
        AVBK_DB::confirm_congress_registration((int) $reg->id);

        $fee_item = $reg->fee_item_id ? AVBK_DB::get_fee_item((int) $reg->fee_item_id) : null;
        $remaining = $fee_item ? round((float) $fee_item->amount_due - AVBK_DB::get_fee_item_paid((int) $fee_item->id), 2) : 0.0;
        $qr = ($reg->member_id && $fee_item) ? AVBK_QR::for_fee_item((int) $reg->member_id, $fee_item) : null;
        $providers = ($reg->member_id && class_exists('AVPVH_OAuth')) ? AVPVH_OAuth::configured_providers() : [];

        ob_start();
        ?>
        <div class="avbk-congress">
            <h2>Aanmelding bevestigd</h2>
            <p>Bedankt <?php echo esc_html($reg->first_name); ?>, je aanmelding is bevestigd.</p>

            <?php if (!empty($_GET['email_failed'])) : ?>
                <p class="avbk-congress-notice avbk-congress-error">We konden geen bevestigingsmail versturen &mdash; bewaar deze pagina of link om je QR-code later terug te vinden.</p>
            <?php endif; ?>

            <?php if ($fee_item) : ?>
                <p><?php echo esc_html($fee_item->description); ?>: &euro; <?php echo esc_html(number_format($remaining, 2, ',', '.')); ?></p>
                <?php if ($qr) : ?>
                    <div class="avbk-congress-qr"><?php echo $qr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- server-rendered SVG from chillerlan/php-qrcode, not user input; esc_html() would break the markup. ?></div>
                    <p class="avbk-congress-qr-hint">Gebruik de QR code met de scan functie in je <strong>bankieren app</strong> (niet met de camera app) om de betaling klaar te zetten.</p>
                    <p class="avbk-congress-qr-ref">Gebruik bij een handmatige overschrijving de referentie:<br><code><?php echo esc_html(AVBK_QR::reference_code((int) $reg->member_id) . ': ' . $fee_item->description); ?></code></p>
                <?php elseif ($remaining > 0.005) : ?>
                    <p>Er kon geen QR-code worden gegenereerd. Neem contact op met de penningmeester (<?php echo esc_html(get_option('avbk_penningmeester_email', 'info@avphilipsvanhorne.nl')); ?>) om te betalen.</p>
                <?php endif; ?>
            <?php else : ?>
                <p>Er is nog geen bedrag geregistreerd voor je aanmelding. Neem contact op met de penningmeester als je vragen hebt over de betaling.</p>
            <?php endif; ?>

            <?php if ($providers) : ?>
                <h3>Account</h3>
                <p>Log in om je gegevens te bekijken of te wijzigen:</p>
                <p>
                <?php foreach ($providers as $key => $config) : ?>
                    <a class="button" href="<?php echo esc_url(AVPVH_OAuth::login_url($key)); ?>">Inloggen met <?php echo esc_html($config['label']); ?></a>
                <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_register(): void {
        check_admin_referer('avbk_congress_register');

        $page_url = esc_url_raw(wp_unslash($_POST['page_url'] ?? '')) ?: home_url('/');

        // Honeypot — a hidden field no real visitor fills in. Silently
        // pretend success rather than telling a bot it was caught.
        if (!empty($_POST['website'])) {
            wp_safe_redirect(add_query_arg('registered', '1', $page_url));
            exit;
        }

        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $suffix     = sanitize_text_field(wp_unslash($_POST['suffix'] ?? ''));
        $last_name  = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $email      = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone      = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));

        if ($first_name === '' || $last_name === '' || !is_email($email) || $phone === '') {
            wp_safe_redirect(add_query_arg('congress_error', '1', $page_url));
            exit;
        }

        $match = AVBK_DB::find_or_create_member_for_registration($first_name, $suffix, $last_name, $email, $phone);
        $member_id = $match['member_id'];

        $fee_item_id = 0;
        if ($member_id) {
            $activity = $this->get_current_congress_activity();
            $member = AVPVH_DB::get_member($member_id);
            if ($activity && $member) {
                $reference_date = $activity->start_date ?: current_time('Y-m-d');
                $computed = AVBK_Fee_Generation::compute_activity_rate($member, $activity, 1, $reference_date);
                if ($computed && $computed['amount'] > 0) {
                    $label = $computed['rate']->label !== '' ? " ({$computed['rate']->label})" : '';
                    $fee_item_id = AVBK_DB::upsert_event_fee_item(
                        $member_id, $activity->name . $label, $computed['amount'], (int) $activity->id
                    );
                }
            }
        }

        $registration = AVBK_DB::create_congress_registration([
            'member_id'   => $member_id,
            'fee_item_id' => $fee_item_id,
            'first_name'  => $first_name,
            'suffix'      => $suffix,
            'last_name'   => $last_name,
            'email'       => $email,
            'phone'       => $phone,
            'match_type'  => $match['match_type'],
            'review_note' => $match['review_note'],
        ]);

        $confirm_link = add_query_arg('token', $registration['token'], $page_url);
        $subject = 'Bevestig je aanmelding — Congres/Reünie AV Philips van Horne';
        $body = "Beste {$first_name},\n\nBedankt voor je aanmelding.\n\nBevestig je aanmelding en bekijk de QR-code om te betalen via deze link:\n{$confirm_link}\n\nMet vriendelijke groet,\nAV Philips van Horne";

        $mail_error = '';
        $capture_error = function ($wp_error) use (&$mail_error) {
            $mail_error = $wp_error->get_error_message();
        };
        add_action('wp_mail_failed', $capture_error);
        $sent = wp_mail($email, $subject, $body);
        remove_action('wp_mail_failed', $capture_error);
        AVBK_DB::mark_congress_email_result((int) $registration['id'], $sent, $mail_error);

        if ($sent) {
            wp_safe_redirect(add_query_arg('registered', '1', $page_url));
        } else {
            // No working e-mail link to send them to — confirm immediately
            // and show the QR right here instead of stranding them.
            AVBK_DB::confirm_congress_registration((int) $registration['id']);
            wp_safe_redirect(add_query_arg(['token' => $registration['token'], 'email_failed' => '1'], $page_url));
        }
        exit;
    }
}
