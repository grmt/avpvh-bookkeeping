<?php
defined('ABSPATH') || exit;

/**
 * [avpvh_bk_book_order] (and alias [avpvh_bk_book]) — public and member ordering
 * flow for the anniversary book ("Doorgraven! - 50 jaar AV Philips van Horne").
 *
 * Handles three views:
 *  - Default: Order form with book flaptekst, pricing note, distribution notice,
 *    address fields, copy quantity, and book presentation attendance/update options.
 *  - ?book_ordered=1: "Check your email" confirmation screen for anonymous guests.
 *  - ?book_token=...: Confirmed order view with order summary, fee amount & status,
 *    EPC QR code, bank transfer details, and link to member profile / login.
 */
class AVBK_Book_Order {

    public const DEFAULT_TITLE = 'Doorgraven! - 50 jaar AV Philips van Horne';
    public const DEFAULT_PRICE = 35.00;
    public const DEFAULT_PRICE_NOTE = 'Richtprijs circa € 35,- (definitieve prijs wordt nader vastgesteld)';
    public const DEFAULT_DISTRIBUTION_NOTICE = 'Het boek wordt in principe niet per post verzonden, maar kan worden opgehaald of wordt uitgereikt. Verzending per post gebeurt alleen als het echt nodig is; de portokosten komen er dan wel bij.';
    public const DEFAULT_PRESENTATION_NOTICE = 'Begin 2027 organiseren we ergens een feestelijke boekpresentatie.';
    public const DEFAULT_FLAPTEKST = "Vijftig jaar archeologie, vriendschap en plezier: dat is het verhaal van de Werkgroep Archeologie / Archeologische Vereniging Philips van Horne. In dit boek blikken we terug op de tweede vijfentwintig jaar. Een periode waarin de werkgroep Philips van Horne, verbonden aan de gelijknamige school, transformeerde tot een volwaardige vereniging. Daarbij bleven de kernwaarden overeind: met enthousiasme en doorzettingsvermogen meewerken aan opgravingen om zo bij te dragen aan behoud en waardering van archeologisch erfgoed en ondertussen genieten van cultuur, de mooie dingen van het leven en vooral ook van elkaar. Zongen de jongeren, zoals gedocumenteerd in het eerste jubileumboek Graven!, “Samen hier, veel plezier / Graven, schaven, potje bier / Kampvuur en een vuile plee: / Ga je ook mee?”, inmiddels rappen de jongeren “Misschien dat mijn sleuf weer volloopt / Je ruikt dixi en zweet als ik langsloop / Als je bitch wil graven is het geen probleem, dan ga ik er heen. Ik kom niet alleen / Want ik heb trek en stek. / Ik heb trek en stek”. Over de trekstek en meer lees je in Doorgraven!, dat herinneringen oproept aan het roemruchte lijfblad DGéén - door graven één -. In opvolging van Graven! geeft dit boek een unieke kijk op een halve eeuw samen ontdekken, beleven en (door)graven.";

    public function __construct() {
        add_shortcode('avpvh_bk_book_order', [$this, 'render']);
        add_shortcode('avpvh_bk_book',       [$this, 'render']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_avbk_book_order',        [$this, 'handle_order']);
        add_action('admin_post_nopriv_avbk_book_order', [$this, 'handle_order']);
    }

    public function enqueue_assets(): void {
        wp_enqueue_style(
            'avbk-book-order',
            AVBK_PLUGIN_URL . 'assets/book-order.css',
            [],
            avbk_asset_version('assets/book-order.css')
        );
    }

    public function render(): string {
        if (!empty($_GET['book_token'])) {
            return $this->render_confirmation(sanitize_text_field(wp_unslash($_GET['book_token'])));
        }
        if (!empty($_GET['book_ordered'])) {
            return $this->render_thanks();
        }
        return $this->render_form();
    }

    private function render_form(): string {
        $title               = get_option('avbk_book_title', self::DEFAULT_TITLE) ?: self::DEFAULT_TITLE;
        $price               = (float) (get_option('avbk_book_price', self::DEFAULT_PRICE) ?: self::DEFAULT_PRICE);
        $price_note          = get_option('avbk_book_price_note', self::DEFAULT_PRICE_NOTE) ?: self::DEFAULT_PRICE_NOTE;
        $flaptekst           = get_option('avbk_book_flaptekst', self::DEFAULT_FLAPTEKST) ?: self::DEFAULT_FLAPTEKST;
        $distribution_notice = get_option('avbk_book_distribution_notice', self::DEFAULT_DISTRIBUTION_NOTICE) ?: self::DEFAULT_DISTRIBUTION_NOTICE;
        $presentation_notice = get_option('avbk_book_presentation_notice', self::DEFAULT_PRESENTATION_NOTICE) ?: self::DEFAULT_PRESENTATION_NOTICE;

        $current_user_id = get_current_user_id();
        $member = null;
        if ($current_user_id && function_exists('avpvh_get_member_by_wp_user')) {
            $member = avpvh_get_member_by_wp_user($current_user_id);
        }
        $existing_address = ($member && !empty($member->id)) ? AVBK_DB::get_member_active_address((int) $member->id) : null;

        $error = sanitize_key(wp_unslash($_GET['book_error'] ?? ''));

        ob_start();
        ?>
        <div class="avbk-book-order">
            <h2><?php echo esc_html($title); ?> &mdash; Bestellen</h2>

            <?php if ($error === 'missing_fields') : ?>
                <div class="avbk-book-notice avbk-book-error">
                    Vul alle verplichte velden in (inclusief je adres).
                </div>
            <?php elseif ($error === 'invalid_email') : ?>
                <div class="avbk-book-notice avbk-book-error">
                    Vul een geldig e-mailadres in.
                </div>
            <?php elseif ($error === 'invalid_quantity') : ?>
                <div class="avbk-book-notice avbk-book-error">
                    Kies een geldig aantal exemplaren (minimaal 1).
                </div>
            <?php endif; ?>

            <div class="avbk-book-intro-card">
                <h3>Over het boek</h3>
                <div class="avbk-book-flaptekst">
                    <?php echo nl2br(esc_html($flaptekst)); ?>
                </div>
            </div>

            <div class="avbk-book-notice avbk-book-notice-info">
                <strong>Afhalen &amp; uitreiken:</strong> <?php echo esc_html($distribution_notice); ?>
            </div>

            <div class="avbk-book-notice avbk-book-notice-price">
                <strong>Prijs:</strong> &euro;&nbsp;<?php echo esc_html(number_format($price, 2, ',', '.')); ?> per exemplaar.
                <span class="avbk-book-price-note"><?php echo esc_html($price_note); ?></span>
            </div>

            <?php if (!$member) : ?>
                <div class="avbk-book-login-banner">
                    <p>
                        Heb je al een account als lid of bezoeker?
                        <a href="<?php echo esc_url(home_url('/avpvh-login/')); ?>">Log hier in</a>
                        om direct te bestellen en je bestelling aan je profiel te koppelen.
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-book-order-form">
                <?php wp_nonce_field('avbk_book_order'); ?>
                <input type="hidden" name="action" value="avbk_book_order">
                <input type="hidden" name="page_url" value="<?php echo esc_url(get_permalink()); ?>">

                <div class="avbk-book-honeypot" aria-hidden="true" style="display:none;">
                    <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>

                <?php if ($member) : ?>
                    <div class="avbk-book-member-box">
                        <p class="avbk-book-member-identity">
                            Ingelogd als: <strong><?php echo esc_html(function_exists('avpvh_format_name') ? avpvh_format_name($member) : ($member->first_name . ' ' . $member->last_name)); ?></strong>
                            (<?php echo esc_html($member->email); ?>)
                        </p>
                        <p class="avbk-book-subtext">Als je bent ingelogd, vragen we om je adres in te vullen of te verifiëren. Dit adres wordt direct opgeslagen op je profiel.</p>
                    </div>
                <?php else : ?>
                    <fieldset class="avbk-book-fieldset">
                        <legend>Persoonsgegevens</legend>
                        <div class="avbk-form-row avbk-form-row-3">
                            <p class="avbk-form-field">
                                <label for="avbk_first_name">Voornaam *</label>
                                <input type="text" id="avbk_first_name" name="first_name" required>
                            </p>
                            <p class="avbk-form-field">
                                <label for="avbk_suffix">Tussenvoegsel</label>
                                <input type="text" id="avbk_suffix" name="suffix">
                            </p>
                            <p class="avbk-form-field">
                                <label for="avbk_last_name">Achternaam *</label>
                                <input type="text" id="avbk_last_name" name="last_name" required>
                            </p>
                        </div>
                        <div class="avbk-form-row avbk-form-row-2">
                            <p class="avbk-form-field">
                                <label for="avbk_email">E-mailadres *</label>
                                <input type="email" id="avbk_email" name="email" required>
                            </p>
                            <p class="avbk-form-field">
                                <label for="avbk_phone">Telefoonnummer</label>
                                <input type="tel" id="avbk_phone" name="phone">
                            </p>
                        </div>
                    </fieldset>
                <?php endif; ?>

                <fieldset class="avbk-book-fieldset">
                    <legend>Adresgegevens *</legend>
                    <div class="avbk-form-row avbk-form-row-2">
                        <p class="avbk-form-field avbk-form-field-street">
                            <label for="avbk_street">Straat *</label>
                            <input type="text" id="avbk_street" name="street" value="<?php echo esc_attr($existing_address->street ?? ''); ?>" required>
                        </p>
                        <p class="avbk-form-field avbk-form-field-housenr">
                            <label for="avbk_house_number">Huisnummer *</label>
                            <input type="text" id="avbk_house_number" name="house_number" value="<?php echo esc_attr($existing_address->house_number ?? ''); ?>" required>
                        </p>
                    </div>
                    <div class="avbk-form-row avbk-form-row-3">
                        <p class="avbk-form-field">
                            <label for="avbk_postal_code">Postcode *</label>
                            <input type="text" id="avbk_postal_code" name="postal_code" value="<?php echo esc_attr($existing_address->postal_code ?? ''); ?>" required>
                        </p>
                        <p class="avbk-form-field">
                            <label for="avbk_city">Woonplaats *</label>
                            <input type="text" id="avbk_city" name="city" value="<?php echo esc_attr($existing_address->city ?? ''); ?>" required>
                        </p>
                        <p class="avbk-form-field">
                            <label for="avbk_country">Land</label>
                            <input type="text" id="avbk_country" name="country" value="<?php echo esc_attr($existing_address->country ?? 'Nederland'); ?>" required>
                        </p>
                    </div>
                </fieldset>

                <fieldset class="avbk-book-fieldset">
                    <legend>Bestelling</legend>
                    <p class="avbk-form-field avbk-form-field-qty">
                        <label for="avbk_quantity">Aantal exemplaren *</label>
                        <select id="avbk_quantity" name="quantity">
                            <?php for ($i = 1; $i <= 10; $i++) : ?>
                                <option value="<?php echo $i; ?>"><?php echo $i . ($i === 1 ? ' exemplaar' : ' exemplaren') . ' (€ ' . number_format($price * $i, 2, ',', '.') . ')'; ?></option>
                            <?php endfor; ?>
                        </select>
                    </p>

                    <div class="avbk-book-presentation-box">
                        <h4>Boekpresentatie begin 2027</h4>
                        <p class="avbk-book-presentation-desc"><?php echo esc_html($presentation_notice); ?></p>
                        <label class="avbk-checkbox-row">
                            <input type="checkbox" name="attend_presentation" value="1">
                            <span>Ik wil graag aanwezig zijn bij de boekpresentatie (begin 2027)</span>
                        </label>
                        <label class="avbk-checkbox-row">
                            <input type="checkbox" name="keep_updated" value="1" checked>
                            <span>Hou mij op de hoogte van de boekpresentatie en het laatste nieuws rond het jubileumboek</span>
                        </label>
                    </div>

                    <p class="avbk-form-field">
                        <label for="avbk_notes">Eventuele opmerking (optioneel)</label>
                        <textarea id="avbk_notes" name="notes" rows="3"></textarea>
                    </p>
                </fieldset>

                <div class="avbk-book-submit-wrap">
                    <button type="submit" class="button button-primary avbk-book-submit-btn">Bestelling plaatsen</button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_thanks(): string {
        $title = get_option('avbk_book_title', self::DEFAULT_TITLE) ?: self::DEFAULT_TITLE;
        ob_start();
        ?>
        <div class="avbk-book-order">
            <h2>Bedankt voor je bestelling!</h2>
            <div class="avbk-book-notice avbk-book-notice-success">
                <p>We hebben een bevestigingsmail naar je verzonden. Klik op de link in die e-mail om je bestelling te bevestigen en de QR-code voor betaling te bekijken.</p>
            </div>
            <p>Heb je al een account of ben je geregistreerd als bezoeker? Je kunt ook <a href="<?php echo esc_url(home_url('/avpvh-login/')); ?>">inloggen</a> om je bestellingen en je volledige overzicht (zoals het congres en boeken) op je <a href="<?php echo esc_url(home_url('/member-profile/#bijdrage')); ?>">profielpagina</a> te bekijken.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_confirmation(string $token): string {
        $order = AVBK_DB::get_book_order_by_token($token);
        if (!$order) {
            ob_start();
            ?>
            <div class="avbk-book-order">
                <div class="avbk-book-notice avbk-book-error">
                    <p>Ongeldige of verlopen bestellink.</p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        if ($order->status !== 'confirmed') {
            AVBK_DB::confirm_book_order((int) $order->id);
            $order->status = 'confirmed';
        }

        $fee_item = $order->fee_item_id ? AVBK_DB::get_fee_item((int) $order->fee_item_id) : null;
        $remaining = $fee_item ? AVBK_DB::get_fee_item_remaining($fee_item) : (float) $order->total_amount;
        $is_paid = $fee_item && ($fee_item->status === 'waived' || $remaining <= 0.005);
        $qr = ($order->member_id && $fee_item && !$is_paid && class_exists('AVBK_QR')) ? AVBK_QR::for_fee_item((int) $order->member_id, $fee_item) : null;

        $title = get_option('avbk_book_title', self::DEFAULT_TITLE) ?: self::DEFAULT_TITLE;
        $distribution_notice = get_option('avbk_book_distribution_notice', self::DEFAULT_DISTRIBUTION_NOTICE) ?: self::DEFAULT_DISTRIBUTION_NOTICE;

        $providers = ($order->member_id && class_exists('AVPVH_OAuth')) ? AVPVH_OAuth::configured_providers() : [];

        ob_start();
        ?>
        <div class="avbk-book-order">
            <h2>Bestelling bevestigd &mdash; <?php echo esc_html($title); ?></h2>

            <?php if (!empty($_GET['email_failed'])) : ?>
                <div class="avbk-book-notice avbk-book-warning">
                    <p>We konden geen bevestigingsmail versturen &mdash; bewaar deze pagina om je bestelling en de QR-code later terug te vinden.</p>
                </div>
            <?php endif; ?>

            <div class="avbk-book-notice avbk-book-notice-success">
                <p>Bedankt <?php echo esc_html($order->first_name); ?>, je bestelling is ontvangen en bevestigd!</p>
            </div>

            <div class="avbk-book-summary-box">
                <h3>Besteloverzicht</h3>
                <table class="avbk-book-summary-table">
                    <tr>
                        <th>Boek:</th>
                        <td><?php echo esc_html($title); ?></td>
                    </tr>
                    <tr>
                        <th>Aantal:</th>
                        <td><?php echo (int) $order->quantity . ((int) $order->quantity === 1 ? ' exemplaar' : ' exemplaren'); ?></td>
                    </tr>
                    <tr>
                        <th>Stukprijs:</th>
                        <td>&euro; <?php echo esc_html(number_format((float) $order->unit_price, 2, ',', '.')); ?> <span class="avbk-book-sub">(richtprijs)</span></td>
                    </tr>
                    <tr>
                        <th>Totaalbedrag:</th>
                        <td><strong>&euro; <?php echo esc_html(number_format((float) $order->total_amount, 2, ',', '.')); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Geregistreerd adres:</th>
                        <td><?php echo esc_html($order->street . ' ' . $order->house_number . ', ' . $order->postal_code . ' ' . $order->city . ', ' . $order->country); ?></td>
                    </tr>
                    <tr>
                        <th>Bezorging / Distributie:</th>
                        <td><em><?php echo esc_html($distribution_notice); ?></em></td>
                    </tr>
                    <?php if (!empty($order->attend_presentation) || !empty($order->keep_updated)) : ?>
                        <tr>
                            <th>Boekpresentatie begin 2027:</th>
                            <td>
                                <?php if (!empty($order->attend_presentation)) : ?>
                                    <div>&#10003; Aangegeven aanwezig te willen zijn</div>
                                <?php endif; ?>
                                <?php if (!empty($order->keep_updated)) : ?>
                                    <div>&#10003; Op de hoogte houden van nieuws &amp; datum</div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>

            <div class="avbk-book-payment-box">
                <h3>Betaling</h3>
                <?php if ($is_paid) : ?>
                    <div class="avbk-book-paid-badge">
                        <p><strong>&#10004; Betaald!</strong> Deze bestelling is voldaan. Bedankt voor je betaling.</p>
                    </div>
                <?php elseif ($qr) : ?>
                    <p class="avbk-book-amount-due">Nog te betalen: <strong>&euro;&nbsp;<?php echo esc_html(number_format($remaining, 2, ',', '.')); ?></strong></p>
                    <div class="avbk-book-qr-render">
                        <?php echo $qr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- server-rendered SVG from chillerlan/php-qrcode ?>
                    </div>
                    <p class="avbk-book-qr-hint">Gebruik de scanfunctie in je <strong>bankieren app</strong> (niet met de camera app) om de betaling direct klaar te zetten.</p>
                    <p class="avbk-book-qr-ref">
                        Bij een handmatige overschrijving:<br>
                        IBAN: <code><?php echo esc_html(get_option('avbk_club_iban', '')); ?></code> t.n.v. <code><?php echo esc_html(get_option('avbk_club_name', '')); ?></code><br>
                        Referentie: <strong><code><?php echo esc_html((class_exists('AVBK_QR') ? AVBK_QR::fee_reference_code((int) $order->member_id, [(int) $fee_item->id]) : ('PVH-' . $order->member_id)) . ': ' . $fee_item->description); ?></code></strong>
                    </p>
                <?php else : ?>
                    <p>Bedrag: &euro; <?php echo esc_html(number_format((float) $order->total_amount, 2, ',', '.')); ?></p>
                    <p>Neem contact op met de penningmeester (<?php echo esc_html(get_option('avbk_penningmeester_email', 'info@avphilipsvanhorne.nl')); ?>) voor betalingsinstructies.</p>
                <?php endif; ?>
            </div>

            <div class="avbk-book-profile-section">
                <h3>Profiel en overzicht</h3>
                <p>Zowel leden als bezoekers hebben een eigen profiel waarop alle bijdragen (zoals de congresfee en boeken) bij elkaar staan.</p>
                <?php if (is_user_logged_in()) : ?>
                    <p>
                        <a class="button button-primary" href="<?php echo esc_url(home_url('/member-profile/#bijdrage')); ?>">
                            Bekijk je saldo en profiel &rarr;
                        </a>
                    </p>
                <?php else : ?>
                    <p>
                        <a class="button button-primary" href="<?php echo esc_url(home_url('/avpvh-login/')); ?>">
                            Inloggen om je profiel en saldo te bekijken &rarr;
                        </a>
                    </p>
                    <?php if ($providers) : ?>
                        <div class="avbk-book-oauth-providers">
                            <?php foreach ($providers as $key => $config) : ?>
                                <a class="button" href="<?php echo esc_url(AVPVH_OAuth::login_url($key)); ?>">Inloggen met <?php echo esc_html($config['label']); ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_order(): void {
        check_admin_referer('avbk_book_order');

        $page_url = esc_url_raw(wp_unslash($_POST['page_url'] ?? '')) ?: home_url('/');

        // Honeypot check
        if (!empty($_POST['website'])) {
            wp_safe_redirect(add_query_arg('book_ordered', '1', $page_url));
            exit;
        }

        $current_user_id = get_current_user_id();
        $member = null;
        if ($current_user_id && function_exists('avpvh_get_member_by_wp_user')) {
            $member = avpvh_get_member_by_wp_user($current_user_id);
        }

        $street       = sanitize_text_field(wp_unslash($_POST['street'] ?? ''));
        $house_number = sanitize_text_field(wp_unslash($_POST['house_number'] ?? ''));
        $postal_code  = sanitize_text_field(wp_unslash($_POST['postal_code'] ?? ''));
        $city         = sanitize_text_field(wp_unslash($_POST['city'] ?? ''));
        $country      = sanitize_text_field(wp_unslash($_POST['country'] ?? 'Nederland')) ?: 'Nederland';

        $quantity            = max(1, (int) ($_POST['quantity'] ?? 1));
        $attend_presentation = !empty($_POST['attend_presentation']) ? 1 : 0;
        $keep_updated        = !empty($_POST['keep_updated']) ? 1 : 0;
        $notes               = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));

        if ($street === '' || $house_number === '' || $postal_code === '' || $city === '') {
            wp_safe_redirect(add_query_arg('book_error', 'missing_fields', $page_url));
            exit;
        }

        $member_id = 0;
        $first_name = '';
        $suffix = '';
        $last_name = '';
        $email = '';
        $phone = '';

        if ($member) {
            $member_id  = (int) $member->id;
            $first_name = (string) $member->first_name;
            $suffix     = (string) ($member->suffix ?? '');
            $last_name  = (string) $member->last_name;
            $email      = (string) $member->email;
            $phone      = (string) ($member->phone ?? '');
        } else {
            $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
            $suffix     = sanitize_text_field(wp_unslash($_POST['suffix'] ?? ''));
            $last_name  = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
            $email      = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            $phone      = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));

            if ($first_name === '' || $last_name === '' || !is_email($email)) {
                $err = !is_email($email) && $email !== '' ? 'invalid_email' : 'missing_fields';
                wp_safe_redirect(add_query_arg('book_error', $err, $page_url));
                exit;
            }

            // Find existing member or create visitor member
            $match = AVBK_DB::find_or_create_member_for_registration($first_name, $suffix, $last_name, $email, $phone);
            $member_id = (int) ($match['member_id'] ?? 0);
        }

        // Save member address to avm_addresses
        if ($member_id > 0) {
            AVBK_DB::save_member_address($member_id, [
                'street'       => $street,
                'house_number' => $house_number,
                'postal_code'  => $postal_code,
                'city'         => $city,
                'country'      => $country,
            ]);
        }

        $unit_price   = (float) (get_option('avbk_book_price', self::DEFAULT_PRICE) ?: self::DEFAULT_PRICE);
        $total_amount = round($unit_price * $quantity, 2);
        $book_title   = get_option('avbk_book_title', self::DEFAULT_TITLE) ?: self::DEFAULT_TITLE;

        // Create fee item
        $fee_item_id = 0;
        if ($member_id > 0) {
            $fee_item_id = AVBK_DB::create_book_fee_item($member_id, $quantity, $total_amount, $book_title);
        }

        $status = $member ? 'confirmed' : 'pending_confirmation';

        $order_result = AVBK_DB::create_book_order([
            'member_id'           => $member_id ?: null,
            'fee_item_id'         => $fee_item_id ?: null,
            'first_name'          => $first_name,
            'suffix'              => $suffix,
            'last_name'           => $last_name,
            'email'               => $email,
            'phone'               => $phone,
            'street'              => $street,
            'house_number'        => $house_number,
            'postal_code'         => $postal_code,
            'city'                => $city,
            'country'             => $country,
            'quantity'            => $quantity,
            'unit_price'          => $unit_price,
            'total_amount'        => $total_amount,
            'attend_presentation' => $attend_presentation,
            'keep_updated'        => $keep_updated,
            'notes'               => $notes,
            'status'              => $status,
        ]);

        $confirm_link = add_query_arg('book_token', $order_result['token'], $page_url);

        // If user was logged in, order is confirmed immediately. Redirect to confirmation view.
        if ($member) {
            // Send courtesy confirmation mail
            $subject = "Bevestiging bestelling {$book_title}";
            $body = "Beste {$first_name},\n\nBedankt voor je bestelling van {$quantity} exemplaar/exemplaren van '{$book_title}'.\n\nJe bestelling en QR-code om te betalen kun je bekijken via deze link:\n{$confirm_link}\n\nOf bekijk je actuele saldo direct op je profiel:\n" . home_url('/member-profile/#bijdrage') . "\n\nMet vriendelijke groet,\nAV Philips van Horne";
            wp_mail($email, $subject, $body);

            wp_safe_redirect($confirm_link);
            exit;
        }

        // For non-logged-in visitors: send confirmation email with token
        $subject = "Bevestig je bestelling &mdash; {$book_title}";
        $body = "Beste {$first_name},\n\nBedankt voor je bestelling van '{$book_title}'.\n\nKlik op onderstaande link om je bestelling te bevestigen en de QR-code voor betaling te openen:\n{$confirm_link}\n\nMet vriendelijke groet,\nAV Philips van Horne";

        $mail_error = '';
        $capture_error = function ($wp_error) use (&$mail_error) {
            $mail_error = $wp_error->get_error_message();
        };
        add_action('wp_mail_failed', $capture_error);
        $sent = wp_mail($email, $subject, $body);
        remove_action('wp_mail_failed', $capture_error);

        AVBK_DB::mark_book_order_email_result((int) $order_result['id'], $sent, $mail_error);

        if ($sent) {
            wp_safe_redirect(add_query_arg('book_ordered', '1', $page_url));
        } else {
            // Confirm immediately if mail fails so user is not stranded
            AVBK_DB::confirm_book_order((int) $order_result['id']);
            wp_safe_redirect(add_query_arg(['book_token' => $order_result['token'], 'email_failed' => '1'], $page_url));
        }
        exit;
    }
}
