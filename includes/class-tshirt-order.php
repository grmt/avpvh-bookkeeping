<?php
defined('ABSPATH') || exit;

/**
 * [avpvh_bk_tshirt_order] (and alias [avpvh_bk_tshirt]) — public and member ordering
 * flow for anniversary / club T-shirts.
 *
 * Supports:
 *  - Multiple designs with photos and descriptions
 *  - Configurable sizes (S, M, L, XL, XXL, 3XL, etc.)
 *  - Line items repeater (order multiple designs and sizes in one transaction)
 *  - Guest checkout with automatic visitor account creation or logged-in member linking
 *  - Email check banner with Authelia activation link for unactivated members
 *  - Address registration and prefill
 *  - EPC QR-code generation and fee item creation (category = 'tshirt')
 *  - Confirmation email & confirmation landing page
 */
class AVBK_Tshirt_Order {

    public const DEFAULT_TITLE = 'Lustrumkleding 50 jaar AV Philips van Horne';
    public const DEFAULT_PRICE = 21.00;
    public const DEFAULT_PRICE_NOTE = 'Alle prijzen zijn op dit moment zonder btw. Het zou kunnen dat er alsnog btw moet worden afgedragen; in dat geval komt er op dit bedrag nog 21% btw bij.';
    public const DEFAULT_DISTRIBUTION_NOTICE = 'Kleding wordt in principe niet per post verzonden, maar kan worden opgehaald of wordt uitgereikt tijdens de jubileumactiviteiten. Verzending per post gebeurt alleen als het echt nodig is; de portokosten komen er dan wel bij.';
    public const DEFAULT_INTRO = 'Voor het 50-jarig jubileum van de vereniging is er nieuwe jubileumkleding ontworpen! Kies hieronder je favoriete design, het gewenste kledingstuk (T-shirt of Hoodie), de gewenste kleur en maat.';

    public const DEFAULT_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];
    public const DEFAULT_COLORS = ['Zwart', 'Wit', 'Beige', 'Donkergroen'];

    public const DEFAULT_TYPES = [
        [
            'id'    => 'tshirt',
            'name'  => 'T-shirt',
            'price' => 21.00,
        ],
        [
            'id'    => 'hoodie',
            'name'  => 'Hoodie',
            'price' => 35.00,
        ],
    ];

    public const DEFAULT_DESIGNS = [
        [
            'id'          => 'design_1',
            'name'        => 'Design 1 (Kampenlijst)',
            'description' => 'Unisex kledingstuk met op de rug de chronologische lijst van historische opgravingskampen en op de borst het herkenbare verenigingskruiwagentje.',
            'image'       => 'assets/images/design-1.png',
            'active'      => 1,
        ],
        [
            'id'          => 'design_2',
            'name'        => 'Design 2 (Ceci n\'est pas une tjoepkesbak)',
            'description' => 'Unisex kledingstuk met de \'Ceci n\'est pas une tjoepkesbak\' illustratie op de rug en het verenigingskruiwagentje op de borst.',
            'image'       => 'assets/images/design-2.png',
            'active'      => 1,
        ],
    ];

    public function __construct() {
        add_shortcode('avpvh_bk_tshirt_order',    [$this, 'render']);
        add_shortcode('avpvh_bk_tshirt',          [$this, 'render']);
        add_shortcode('avpvh_bk_jubileumkleding', [$this, 'render']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_avbk_tshirt_order',        [$this, 'handle_order']);
        add_action('admin_post_nopriv_avbk_tshirt_order', [$this, 'handle_order']);
        add_action('admin_post_avbk_check_tshirt_email',        [$this, 'handle_check_email']);
        add_action('admin_post_nopriv_avbk_check_tshirt_email', [$this, 'handle_check_email']);
        add_action('wp_ajax_avbk_check_tshirt_email',           [$this, 'handle_check_email']);
        add_action('wp_ajax_nopriv_avbk_check_tshirt_email',    [$this, 'handle_check_email']);
    }

    public static function get_available_types(): array {
        $saved = get_option('avbk_tshirt_types', null);
        if (is_array($saved) && !empty($saved)) {
            return $saved;
        }
        return self::DEFAULT_TYPES;
    }

    public static function get_available_designs(): array {
        $saved = get_option('avbk_tshirt_designs', null);
        if (is_array($saved) && !empty($saved)) {
            return $saved;
        }
        return self::DEFAULT_DESIGNS;
    }

    public static function get_design_image_url(string $image): string {
        if ($image === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $image)) {
            return $image;
        }
        return AVBK_PLUGIN_URL . ltrim($image, '/');
    }

    public static function get_available_sizes(): array {
        $sizes_str = get_option('avbk_tshirt_sizes', implode(', ', self::DEFAULT_SIZES));
        $sizes = array_filter(array_map('trim', explode(',', (string) $sizes_str)));
        return !empty($sizes) ? array_values($sizes) : self::DEFAULT_SIZES;
    }

    public static function get_available_colors(): array {
        $colors_str = get_option('avbk_tshirt_colors', implode(', ', self::DEFAULT_COLORS));
        $colors = array_filter(array_map('trim', explode(',', (string) $colors_str)));
        return !empty($colors) ? array_values($colors) : self::DEFAULT_COLORS;
    }

    public function enqueue_assets(): void {
        wp_enqueue_style(
            'avbk-book-order',
            AVBK_PLUGIN_URL . 'assets/book-order.css',
            [],
            avbk_asset_version('assets/book-order.css')
        );
        wp_enqueue_style(
            'avbk-tshirt-order',
            AVBK_PLUGIN_URL . 'assets/tshirt-order.css',
            ['avbk-book-order'],
            avbk_asset_version('assets/tshirt-order.css')
        );
        wp_enqueue_script(
            'avbk-tshirt-order',
            AVBK_PLUGIN_URL . 'assets/tshirt-order.js',
            [],
            avbk_asset_version('assets/tshirt-order.js'),
            true
        );
    }

    public function render(): string {
        if (!empty($_GET['tshirt_token'])) {
            return $this->render_confirmation(sanitize_text_field(wp_unslash($_GET['tshirt_token'])));
        }
        if (!empty($_GET['tshirt_ordered'])) {
            return $this->render_thanks();
        }
        return $this->render_form();
    }

    private function render_form(): string {
        $title               = get_option('avbk_tshirt_title', self::DEFAULT_TITLE) ?: self::DEFAULT_TITLE;
        $price               = (float) (get_option('avbk_tshirt_price', self::DEFAULT_PRICE) ?: self::DEFAULT_PRICE);
        $price_note          = get_option('avbk_tshirt_price_note', self::DEFAULT_PRICE_NOTE) ?: self::DEFAULT_PRICE_NOTE;
        $intro               = get_option('avbk_tshirt_intro', self::DEFAULT_INTRO) ?: self::DEFAULT_INTRO;
        $distribution_notice = get_option('avbk_tshirt_distribution_notice', self::DEFAULT_DISTRIBUTION_NOTICE) ?: self::DEFAULT_DISTRIBUTION_NOTICE;

        $designs = self::get_available_designs();
        $active_designs = array_filter($designs, fn($d) => !empty($d['active']));
        if (empty($active_designs)) {
            $active_designs = $designs;
        }
        $types = self::get_available_types();
        $sizes = self::get_available_sizes();
        $colors = self::get_available_colors();

        $current_user_id = get_current_user_id();
        $member = null;
        if ($current_user_id && function_exists('avpvh_get_member_by_wp_user')) {
            $member = avpvh_get_member_by_wp_user($current_user_id);
        }
        $existing_address = ($member && !empty($member->id)) ? AVBK_DB::get_member_active_address((int) $member->id) : null;

        $error = sanitize_key(wp_unslash($_GET['tshirt_error'] ?? ''));
        $email_check   = sanitize_key(wp_unslash($_GET['email_check'] ?? ''));
        $checked_email = sanitize_email(wp_unslash($_GET['checked_email'] ?? ''));

        $first_t = reset($types);
        $first_price = isset($first_t['price']) && (float) $first_t['price'] > 0 ? (float) $first_t['price'] : $price;

        $min_type_price = null;
        foreach ($types as $t) {
            if (isset($t['price']) && (float) $t['price'] > 0) {
                $min_type_price = $min_type_price === null ? (float) $t['price'] : min($min_type_price, (float) $t['price']);
            }
        }
        $starting_price = $min_type_price !== null ? $min_type_price : $price;

        ob_start();
        ?>
        <div class="avbk-book-order avbk-tshirt-order">
            <h2><?php echo esc_html($title); ?> &mdash; Bestellen</h2>

            <?php if ($error === 'missing_fields') : ?>
                <div class="avbk-book-notice avbk-book-error">
                    Vul alle verplichte velden in: voornaam, achternaam en een geldig e-mailadres.
                </div>
            <?php elseif ($error === 'invalid_email') : ?>
                <div class="avbk-book-notice avbk-book-error">
                    Vul een geldig e-mailadres in.
                </div>
            <?php elseif ($error === 'no_items') : ?>
                <div class="avbk-book-notice avbk-book-error">
                    Selecteer minimaal één kledingstuk om te bestellen.
                </div>
            <?php endif; ?>

            <?php if ($intro !== '') : ?>
                <div class="avbk-book-intro-card">
                    <p class="avbk-tshirt-intro-text"><?php echo nl2br(esc_html($intro)); ?></p>
                </div>
            <?php endif; ?>

            <!-- Design Showcase Gallery -->
            <div class="avbk-tshirt-gallery">
                <h3>1. Bekijk de designs</h3>
                <div class="avbk-tshirt-cards-grid">
                    <?php foreach ($active_designs as $design) : ?>
                        <?php
                        $img_url = self::get_design_image_url($design['image'] ?? '');
                        ?>
                        <div class="avbk-tshirt-card" data-design-id="<?php echo esc_attr($design['id']); ?>" data-design-name="<?php echo esc_attr($design['name']); ?>">
                            <div class="avbk-tshirt-card-media">
                                <?php if (!empty($img_url)) : ?>
                                    <img src="<?php echo esc_url($img_url); ?>" alt="<?php echo esc_attr($design['name']); ?>" loading="lazy" class="avbk-tshirt-card-img">
                                    <a href="<?php echo esc_url($img_url); ?>" target="_blank" rel="noopener" class="avbk-tshirt-zoom-link" title="Bekijk afbeelding op ware grootte">&#128269; Vergroten</a>
                                <?php else : ?>
                                    <div class="avbk-tshirt-card-placeholder">
                                        <span class="avbk-tshirt-icon">&#128085;</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="avbk-tshirt-card-body">
                                <h4 class="avbk-tshirt-card-title"><?php echo esc_html($design['name']); ?></h4>
                                <?php if (!empty($design['description'])) : ?>
                                    <p class="avbk-tshirt-card-desc"><?php echo esc_html($design['description']); ?></p>
                                <?php endif; ?>
                                <div class="avbk-tshirt-card-pricing">
                                    <?php foreach ($types as $t) : ?>
                                        <span class="avbk-price-badge"><?php echo esc_html($t['name']); ?>: &euro;&nbsp;<?php echo number_format((float) $t['price'], 2, ',', '.'); ?></span>
                                    <?php endforeach; ?>
                                    <span class="avbk-price-vat">(op dit moment zonder btw)</span>
                                </div>
                                <div class="avbk-tshirt-card-buttons">
                                    <?php foreach ($types as $t) : ?>
                                        <button type="button" class="button <?php echo $t['id'] === 'tshirt' ? 'button-primary' : 'button-secondary'; ?> avbk-select-design-type-btn" data-design-id="<?php echo esc_attr($design['id']); ?>" data-product-type="<?php echo esc_attr($t['id']); ?>">
                                            ＋ Bestel <?php echo esc_html($t['name']); ?> (&euro;&nbsp;<?php echo number_format((float) $t['price'], 0, ',', '.'); ?>,-)
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="avbk-book-notice avbk-book-notice-info">
                <strong>Afhalen &amp; uitreiken:</strong> <?php echo esc_html($distribution_notice); ?>
            </div>

            <div class="avbk-book-notice avbk-book-notice-price">
                <strong>Prijs:</strong> Vanaf &euro;&nbsp;<?php echo esc_html(number_format($starting_price, 2, ',', '.')); ?> per kledingstuk.
                <?php if (!empty($price_note)) : ?>
                    <span class="avbk-book-price-note"><?php echo esc_html($price_note); ?></span>
                <?php endif; ?>
                <span class="avbk-book-price-note" style="display:block; margin-top:.35rem; font-weight:600; color:#856404;">Let op: bestellen verplicht tot betaling.</span>
            </div>

            <?php if (!$member) : ?>
                <div class="avbk-book-login-banner" id="avbk-login-banner">
                    <p class="avbk-book-login-intro">
                        <strong>Heb je al een account als lid of bezoeker?</strong>
                        <a href="<?php echo esc_url(home_url('/avpvh-login/')); ?>">Log hier in</a>
                        om direct te bestellen en je bestelling aan je profiel te koppelen.
                    </p>
                    <div class="avbk-book-check-email-box">
                        <p class="avbk-book-check-email-prompt">
                            Weet je niet of je al bekend bent? Vul dan eerst hier je e-mailadres in. Als dat e-mailadres bekend is, maar je bent nog nooit ingelogd, dan krijg je een mailtje met een link om een wachtwoord aan te maken:
                        </p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-book-check-email-form" id="avbk-check-email-form">
                            <?php wp_nonce_field('avbk_check_tshirt_email'); ?>
                            <input type="hidden" name="action" value="avbk_check_tshirt_email">
                            <input type="hidden" name="page_url" value="<?php echo esc_url(get_permalink()); ?>">
                            <div class="avbk-book-check-email-row">
                                <input type="email" id="avbk_check_email_input" name="check_email" class="avbk-check-email-input" placeholder="jouw.email@example.nl" value="<?php echo esc_attr($checked_email); ?>" required>
                                <button type="submit" id="avbk_check_email_btn" class="button avbk-check-email-btn">Controleren</button>
                            </div>
                        </form>
                        <div id="avbk-check-email-result" class="avbk-check-email-result-container">
                            <?php if ($email_check === 'reset_sent') : ?>
                                <div class="avbk-book-notice avbk-book-notice-success">
                                    <p><strong>E-mail verzonden!</strong> Je e-mailadres (<?php echo esc_html($checked_email); ?>) is bekend in onze administratie, maar je bent nog niet eerder ingelogd. We hebben je een e-mail gestuurd met een link om een wachtwoord aan te maken. Zodra je een wachtwoord hebt aangemaakt, kun je <a href="<?php echo esc_url(home_url('/avpvh-login/')); ?>">inloggen</a> en bestellen.</p>
                                </div>
                            <?php elseif ($email_check === 'already_active') : ?>
                                <?php
                                $authelia_url = (class_exists('AVPVH_Nav_Auth') && defined('AVPVH_Nav_Auth::AUTHELIA_URL')) ? AVPVH_Nav_Auth::AUTHELIA_URL : 'https://auth.avphilipsvanhorne.nl';
                                $reset_url = add_query_arg('username', rawurlencode($checked_email), rtrim($authelia_url, '/') . '/reset-password/step1');
                                ?>
                                <div class="avbk-book-notice avbk-book-notice-info">
                                    <p>Dit e-mailadres is bekend en je account is al actief. <a href="<?php echo esc_url(home_url('/avpvh-login/')); ?>">Log hier in</a> om direct te bestellen. Weet je je wachtwoord niet meer? <a href="<?php echo esc_url($reset_url); ?>" target="_blank" rel="noopener">Wachtwoord opnieuw instellen</a>.</p>
                                </div>
                            <?php elseif ($email_check === 'not_found') : ?>
                                <div class="avbk-book-notice avbk-book-notice-neutral">
                                    <p>Dit e-mailadres is nog niet bekend bij ons. Je hoeft niet eerst in te loggen: vul hieronder je gegevens in om te bestellen. Er wordt dan automatisch een account voor je aangemaakt.</p>
                                </div>
                            <?php elseif ($email_check === 'invalid_email') : ?>
                                <div class="avbk-book-notice avbk-book-error">
                                    <p>Vul een geldig e-mailadres in om te controleren.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-book-order-form avbk-tshirt-order-form">
                <?php wp_nonce_field('avbk_tshirt_order'); ?>
                <input type="hidden" name="action" value="avbk_tshirt_order">
                <input type="hidden" name="page_url" value="<?php echo esc_url(get_permalink()); ?>">
                <input type="hidden" id="avbk_tshirt_unit_price" value="<?php echo esc_attr($first_price); ?>">

                <!-- Honeypot -->
                <div style="display:none;" aria-hidden="true">
                    <input type="text" name="website" tabindex="-1" autocomplete="off">
                </div>

                <!-- Clothing selection repeater -->
                <div class="avbk-form-section avbk-tshirt-items-section" id="avbk-tshirt-selector-section">
                    <h3>2. Stel je bestelling samen</h3>
                    <p class="description">Kies per kledingstuk het gewenste design, type (T-shirt of Hoodie), kleur, maat en aantal. Je kunt eenvoudig meerdere kledingstukken toevoegen.</p>

                    <div class="avbk-tshirt-items-repeater avbk-tshirt-items-table" id="avbk-tshirt-items-body">
                        <div class="avbk-tshirt-item-card avbk-tshirt-item-row" data-row-index="0">
                            <div class="avbk-tshirt-card-top">
                                <div class="avbk-tshirt-card-badge">
                                    <span class="avbk-tshirt-badge-icon">&#128085;</span>
                                    <strong>Kledingstuk <span class="avbk-tshirt-item-idx">1</span></strong>
                                </div>
                                <button type="button" class="avbk-remove-item-btn" title="Verwijder dit kledingstuk" style="display:none;">
                                    &times; Verwijderen
                                </button>
                            </div>

                            <div class="avbk-tshirt-card-grid">
                                <div class="avbk-tshirt-field avbk-col-design">
                                    <label class="avbk-field-label">1. Kies design *</label>
                                    <select name="items[0][design]" class="avbk-tshirt-design-select" required>
                                        <?php foreach ($active_designs as $design) : ?>
                                            <option value="<?php echo esc_attr($design['id']); ?>" data-name="<?php echo esc_attr($design['name']); ?>">
                                                <?php echo esc_html($design['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="avbk-tshirt-field avbk-col-type">
                                    <label class="avbk-field-label">2. Kledingstuk *</label>
                                    <select name="items[0][product_type]" class="avbk-tshirt-type-select" required>
                                        <?php foreach ($types as $t) : ?>
                                            <option value="<?php echo esc_attr($t['id']); ?>" data-name="<?php echo esc_attr($t['name']); ?>" data-price="<?php echo esc_attr(number_format((float) $t['price'], 2, '.', '')); ?>">
                                                <?php echo esc_html($t['name']); ?> (&euro;&nbsp;<?php echo number_format((float) $t['price'], 2, ',', '.'); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="avbk-tshirt-field avbk-col-color">
                                    <label class="avbk-field-label">3. Kleur *</label>
                                    <select name="items[0][color]" class="avbk-tshirt-color-select">
                                        <?php foreach ($colors as $color) : ?>
                                            <option value="<?php echo esc_attr($color); ?>"><?php echo esc_html($color); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="avbk-tshirt-field avbk-col-size">
                                    <label class="avbk-field-label">4. Maat *</label>
                                    <select name="items[0][size]" class="avbk-tshirt-size-select" required>
                                        <?php foreach ($sizes as $size) : ?>
                                            <option value="<?php echo esc_attr($size); ?>"><?php echo esc_html($size); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="avbk-tshirt-field avbk-col-qty">
                                    <label class="avbk-field-label">Aantal *</label>
                                    <input type="number" name="items[0][quantity]" class="avbk-tshirt-qty-input" value="1" min="1" max="99" required>
                                </div>
                            </div>

                            <div class="avbk-tshirt-card-footer">
                                <div class="avbk-tshirt-price-meta">
                                    <span class="avbk-col-unit-price avbk-tshirt-unit-price">Stukprijs: <strong>&euro;&nbsp;<span class="avbk-price-val"><?php echo number_format($first_price, 2, ',', '.'); ?></span></strong></span>
                                    <span class="avbk-col-subtotal avbk-tshirt-subtotal">Subtotaal: <strong>&euro;&nbsp;<span class="avbk-subtotal-val"><?php echo number_format($first_price, 2, ',', '.'); ?></span></strong></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="avbk-tshirt-add-wrap">
                        <button type="button" id="avbk-add-shirt-btn" class="button button-secondary avbk-add-shirt-btn">
                            ＋ Extra kledingstuk toevoegen
                        </button>
                    </div>

                    <div class="avbk-tshirt-totals-box">
                        <div class="avbk-tshirt-totals-row">
                            <span class="avbk-tshirt-totals-label">Totaalbedrag:</span>
                            <strong class="avbk-tshirt-grand-total">&euro;&nbsp;<span id="avbk-grand-total-val"><?php echo number_format($first_price, 2, ',', '.'); ?></span></strong>
                        </div>
                        <?php if (!empty($price_note)) : ?>
                            <p class="avbk-tshirt-price-note-text"><?php echo esc_html($price_note); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Template for Javascript row cloning -->
                <template id="avbk-tshirt-row-template">
                    <div class="avbk-tshirt-item-card avbk-tshirt-item-row" data-row-index="__INDEX__">
                        <div class="avbk-tshirt-card-top">
                            <div class="avbk-tshirt-card-badge">
                                <span class="avbk-tshirt-badge-icon">&#128085;</span>
                                <strong>Kledingstuk <span class="avbk-tshirt-item-idx">__INDEX_PLUS_1__</span></strong>
                            </div>
                            <button type="button" class="avbk-remove-item-btn" title="Verwijder dit kledingstuk">
                                &times; Verwijderen
                            </button>
                        </div>

                        <div class="avbk-tshirt-card-grid">
                            <div class="avbk-tshirt-field avbk-col-design">
                                <label class="avbk-field-label">1. Kies design *</label>
                                <select name="items[__INDEX__][design]" class="avbk-tshirt-design-select" required>
                                    <?php foreach ($active_designs as $design) : ?>
                                        <option value="<?php echo esc_attr($design['id']); ?>" data-name="<?php echo esc_attr($design['name']); ?>">
                                            <?php echo esc_html($design['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="avbk-tshirt-field avbk-col-type">
                                <label class="avbk-field-label">2. Kledingstuk *</label>
                                <select name="items[__INDEX__][product_type]" class="avbk-tshirt-type-select" required>
                                    <?php foreach ($types as $t) : ?>
                                        <option value="<?php echo esc_attr($t['id']); ?>" data-name="<?php echo esc_attr($t['name']); ?>" data-price="<?php echo esc_attr(number_format((float) $t['price'], 2, '.', '')); ?>">
                                            <?php echo esc_html($t['name']); ?> (&euro;&nbsp;<?php echo number_format((float) $t['price'], 2, ',', '.'); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="avbk-tshirt-field avbk-col-color">
                                <label class="avbk-field-label">3. Kleur *</label>
                                <select name="items[__INDEX__][color]" class="avbk-tshirt-color-select">
                                    <?php foreach ($colors as $color) : ?>
                                        <option value="<?php echo esc_attr($color); ?>"><?php echo esc_html($color); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="avbk-tshirt-field avbk-col-size">
                                <label class="avbk-field-label">4. Maat *</label>
                                <select name="items[__INDEX__][size]" class="avbk-tshirt-size-select" required>
                                    <?php foreach ($sizes as $size) : ?>
                                        <option value="<?php echo esc_attr($size); ?>"><?php echo esc_html($size); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="avbk-tshirt-field avbk-col-qty">
                                <label class="avbk-field-label">Aantal *</label>
                                <input type="number" name="items[__INDEX__][quantity]" class="avbk-tshirt-qty-input" value="1" min="1" max="99" required>
                            </div>
                        </div>

                        <div class="avbk-tshirt-card-footer">
                            <div class="avbk-tshirt-price-meta">
                                <span class="avbk-col-unit-price avbk-tshirt-unit-price">Stukprijs: <strong>&euro;&nbsp;<span class="avbk-price-val"><?php echo number_format($first_price, 2, ',', '.'); ?></span></strong></span>
                                <span class="avbk-col-subtotal avbk-tshirt-subtotal">Subtotaal: <strong>&euro;&nbsp;<span class="avbk-subtotal-val"><?php echo number_format($first_price, 2, ',', '.'); ?></span></strong></span>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Personal Information -->
                <?php if ($member) : ?>
                    <div class="avbk-book-member-box" style="margin-bottom: 1.5rem;">
                        <p class="avbk-book-member-identity">
                            Ingelogd als: <strong><?php echo esc_html(avpvh_format_name($member)); ?></strong>
                            (<?php echo esc_html($member->email); ?>)
                        </p>
                        <p class="avbk-book-subtext">Je bestelling wordt direct gekoppeld aan je account.</p>
                    </div>
                <?php else : ?>
                    <fieldset class="avbk-book-fieldset">
                        <legend>Persoonsgegevens</legend>
                        <div class="avbk-form-row avbk-form-row-3">
                            <p class="avbk-form-field">
                                <label for="first_name">Voornaam *</label>
                                <input type="text" id="first_name" name="first_name" required>
                            </p>
                            <p class="avbk-form-field">
                                <label for="suffix">Tussenvoegsel</label>
                                <input type="text" id="suffix" name="suffix" class="avbk-input-sm">
                            </p>
                            <p class="avbk-form-field">
                                <label for="last_name">Achternaam *</label>
                                <input type="text" id="last_name" name="last_name" required>
                            </p>
                        </div>
                        <div class="avbk-form-row avbk-form-row-2">
                            <p class="avbk-form-field">
                                <label for="email">E-mailadres *</label>
                                <input type="email" id="email" name="email" required>
                            </p>
                            <p class="avbk-form-field">
                                <label for="phone">Telefoonnummer</label>
                                <input type="tel" id="phone" name="phone">
                            </p>
                        </div>
                    </fieldset>
                <?php endif; ?>

                <!-- Address (Optional) -->
                <fieldset class="avbk-book-fieldset">
                    <legend>Adresgegevens (optioneel)</legend>
                    <p class="description" style="margin: 0 0 1rem; color: #646970;">Optioneel: alleen nodig indien het kledingstuk per post verzonden moet worden omdat afhalen of uitreiken niet mogelijk is.</p>
                    <div class="avbk-form-row avbk-form-row-2">
                        <p class="avbk-form-field avbk-form-field-street">
                            <label for="street">Straatnaam</label>
                            <input type="text" id="street" name="street" value="<?php echo esc_attr($existing_address->street ?? ''); ?>">
                        </p>
                        <p class="avbk-form-field avbk-form-field-housenr">
                            <label for="house_number">Huisnummer (+ toevoeging)</label>
                            <input type="text" id="house_number" name="house_number" class="avbk-input-sm" value="<?php echo esc_attr($existing_address->house_number ?? ''); ?>">
                        </p>
                    </div>
                    <div class="avbk-form-row avbk-form-row-3">
                        <p class="avbk-form-field">
                            <label for="postal_code">Postcode</label>
                            <input type="text" id="postal_code" name="postal_code" class="avbk-input-sm" value="<?php echo esc_attr($existing_address->postal_code ?? ''); ?>">
                        </p>
                        <p class="avbk-form-field">
                            <label for="city">Woonplaats</label>
                            <input type="text" id="city" name="city" value="<?php echo esc_attr($existing_address->city ?? ''); ?>">
                        </p>
                        <p class="avbk-form-field">
                            <label for="country">Land</label>
                            <input type="text" id="country" name="country" value="<?php echo esc_attr($existing_address->country ?? 'Nederland'); ?>">
                        </p>
                    </div>
                </fieldset>

                <!-- Notes -->
                <fieldset class="avbk-book-fieldset">
                    <legend>Opmerkingen</legend>
                    <p class="avbk-form-field">
                        <label for="notes">Heb je specifieke wensen of opmerkingen?</label>
                        <textarea id="notes" name="notes" rows="3" placeholder="Bijv. opmerking over levering, afhalen of maat..."></textarea>
                    </p>
                </fieldset>

                <div class="avbk-order-commitment-box">
                    <strong>Let op: Bestellen betekent betalen!</strong><br>
                    Met het afronden van je bestelling ga je een betalingsverplichting aan. Na het plaatsen ontvang je direct de betaalinstructies (via bankoverschrijving of iDEAL QR-code) om het bedrag over te maken.
                </div>

                <div class="avbk-book-submit-wrap" style="text-align: center; margin: 2rem 0 1.5rem;">
                    <button type="submit" class="button button-primary avbk-submit-btn">Bestelling plaatsen (met betaalverplichting) &rarr;</button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_thanks(): string {
        ob_start();
        ?>
        <div class="avbk-book-order">
            <div class="avbk-book-notice avbk-book-notice-success">
                <h2>Controleer je e-mail</h2>
                <p>We hebben je een bevestigingsmail gestuurd met een persoonlijke link om je T-shirt bestelling te bevestigen.</p>
                <p>Klik op de link in die e-mail om je bestelling definitief te maken en de betaalinstructies te openen.</p>
                <p><em>Geen mail ontvangen? Controleer ook je spam-/ongewenste mail map.</em></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_confirmation(string $token): string {
        $order = AVBK_DB::get_order_by_token($token);
        if (!$order || $order->order_type !== 'tshirt') {
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
            AVBK_DB::confirm_order((int) $order->id);
            $order->status = 'confirmed';
        }

        $fee_item = $order->fee_item_id ? AVBK_DB::get_fee_item((int) $order->fee_item_id) : null;
        $remaining = $fee_item ? AVBK_DB::get_fee_item_remaining($fee_item) : (float) $order->total_amount;
        $is_paid = $fee_item && ($fee_item->status === 'waived' || $remaining <= 0.005);
        $qr = ($order->member_id && $fee_item && !$is_paid && class_exists('AVBK_QR')) ? AVBK_QR::for_fee_item((int) $order->member_id, $fee_item) : null;

        $title = get_option('avbk_tshirt_title', self::DEFAULT_TITLE) ?: self::DEFAULT_TITLE;
        $distribution_notice = get_option('avbk_tshirt_distribution_notice', self::DEFAULT_DISTRIBUTION_NOTICE) ?: self::DEFAULT_DISTRIBUTION_NOTICE;
        $price_note = get_option('avbk_tshirt_price_note', self::DEFAULT_PRICE_NOTE) ?: self::DEFAULT_PRICE_NOTE;

        ob_start();
        ?>
        <div class="avbk-book-order avbk-tshirt-order">
            <h2>Bestelling bevestigd &mdash; <?php echo esc_html($title); ?></h2>

            <?php if (!empty($_GET['email_failed'])) : ?>
                <div class="avbk-book-notice avbk-book-warning">
                    <p>We konden geen bevestigingsmail versturen &mdash; bewaar deze pagina om je bestelling en de QR-code later terug te vinden.</p>
                </div>
            <?php endif; ?>

            <div class="avbk-book-notice avbk-book-notice-success">
                <p>Bedankt <?php echo esc_html($order->first_name); ?>, je bestelling (#<?php echo (int) $order->id; ?>) is ontvangen en bevestigd!</p>
            </div>

            <div class="avbk-book-summary-box">
                <h3>Besteloverzicht</h3>
                <div class="avbk-tshirt-table-responsive">
                    <table class="avbk-tshirt-summary-table widefat striped" style="margin-bottom: .75rem;">
                        <thead>
                            <tr>
                                <th>Design</th>
                                <th>Maat</th>
                                <th style="width: 80px;">Aantal</th>
                                <th style="width: 110px;">Stukprijs</th>
                                <th style="width: 110px;">Totaal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order->items as $item) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($item->title); ?></strong></td>
                                    <td><span class="badge"><?php echo esc_html($item->variant); ?></span></td>
                                    <td><?php echo (int) $item->quantity; ?></td>
                                    <td>&euro;&nbsp;<?php echo esc_html(number_format((float) $item->unit_price, 2, ',', '.')); ?></td>
                                    <td>&euro;&nbsp;<?php echo esc_html(number_format((float) $item->total_price, 2, ',', '.')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" style="text-align: right;">Totaalbedrag:</th>
                                <th>&euro;&nbsp;<?php echo esc_html(number_format((float) $order->total_amount, 2, ',', '.')); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                    <?php if (!empty($price_note)) : ?>
                        <p class="description" style="margin-top: .5rem; margin-bottom: 1.25rem; color: #646970; font-size: .88rem;"><?php echo esc_html($price_note); ?></p>
                    <?php endif; ?>
                </div>

                <table class="avbk-book-summary-table">
                    <?php if (!empty($order->street) || !empty($order->city)) : ?>
                        <tr>
                            <th>Geregistreerd adres:</th>
                            <td><?php echo esc_html(trim($order->street . ' ' . $order->house_number . ', ' . $order->postal_code . ' ' . $order->city . ', ' . $order->country, ', ')); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Uitreiking:</th>
                        <td><em><?php echo esc_html($distribution_notice); ?></em></td>
                    </tr>
                    <?php if (!empty($order->notes)) : ?>
                        <tr>
                            <th>Opmerkingen:</th>
                            <td><?php echo esc_html($order->notes); ?></td>
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
                <?php else : ?>
                    <div class="avbk-book-notice avbk-book-warning" style="margin-bottom: 1.25rem;">
                        <p><strong>Let op: Bestellen betekent betalen.</strong> Met deze bestelling is een betalingsverplichting ontstaan. Voldoe het openstaande bedrag van &euro;&nbsp;<?php echo esc_html(number_format($remaining, 2, ',', '.')); ?> z.s.m. via onderstaande instructies.</p>
                    </div>

                    <?php if ($qr) : ?>
                        <p class="avbk-book-amount-due">Nog te betalen: <strong>&euro;&nbsp;<?php echo esc_html(number_format($remaining, 2, ',', '.')); ?></strong></p>
                        <div class="avbk-book-qr-render">
                            <?php echo $qr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
                <?php endif; ?>
            </div>

            <?php if (is_user_logged_in()) : ?>
                <p class="avbk-book-back-profile" style="margin-top:2rem;">
                    <a href="<?php echo esc_url(home_url('/member-profile/#bijdrage')); ?>">&larr; Terug naar je profiel</a>
                </p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_order(): void {
        check_admin_referer('avbk_tshirt_order');

        $page_url = esc_url_raw(wp_unslash($_POST['page_url'] ?? '')) ?: home_url('/tshirt/');

        // Honeypot check
        if (!empty($_POST['website'])) {
            wp_safe_redirect(add_query_arg('tshirt_ordered', '1', $page_url));
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
        $notes        = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));

        // Parse line items
        $raw_items = $_POST['items'] ?? [];
        if (!is_array($raw_items) || empty($raw_items)) {
            wp_safe_redirect(add_query_arg('tshirt_error', 'no_items', $page_url));
            exit;
        }

        $unit_price = (float) (get_option('avbk_tshirt_price', self::DEFAULT_PRICE) ?: self::DEFAULT_PRICE);
        $designs_map = [];
        $designs_price_map = [];
        foreach (self::get_available_designs() as $d) {
            $designs_map[$d['id']] = $d['name'];
            if (isset($d['price']) && (float) $d['price'] > 0) {
                $designs_price_map[$d['id']] = (float) $d['price'];
            }
        }

        $types_map = [];
        foreach (self::get_available_types() as $t) {
            $types_map[$t['id']] = [
                'name'  => $t['name'],
                'price' => (float) ($t['price'] ?? $unit_price),
            ];
        }

        $items = [];
        $total_quantity = 0;
        $total_amount   = 0.0;

        foreach ($raw_items as $raw_item) {
            $qty = max(0, (int) ($raw_item['quantity'] ?? 0));
            if ($qty <= 0) {
                continue;
            }
            $design_id   = sanitize_key($raw_item['design'] ?? '');
            $type_id     = sanitize_key($raw_item['product_type'] ?? 'tshirt');
            $size        = sanitize_text_field(wp_unslash($raw_item['size'] ?? ''));
            $color       = sanitize_text_field(wp_unslash($raw_item['color'] ?? ''));

            $design_name = $designs_map[$design_id] ?? ($design_id ?: 'Design 1');
            $type_info   = $types_map[$type_id] ?? ['name' => 'T-shirt', 'price' => $unit_price];
            $type_name   = $type_info['name'];
            $item_price  = (float) $type_info['price'];

            if (isset($designs_price_map[$design_id]) && (float) $designs_price_map[$design_id] > 0) {
                $item_price = (float) $designs_price_map[$design_id];
            }

            $full_title = "{$design_name} — {$type_name}";
            if ($color !== '') {
                $full_title .= " ({$color})";
            }

            $line_total = round($qty * $item_price, 2);

            $items[] = [
                'item_key'    => $design_id . ($type_id !== '' ? '_' . $type_id : ''),
                'title'       => $full_title,
                'variant'     => $size,
                'quantity'    => $qty,
                'unit_price'  => $item_price,
                'total_price' => $line_total,
            ];
            $total_quantity += $qty;
            $total_amount   += $line_total;
        }

        if (empty($items) || $total_quantity <= 0) {
            wp_safe_redirect(add_query_arg('tshirt_error', 'no_items', $page_url));
            exit;
        }

        $member_id  = 0;
        $first_name = '';
        $suffix     = '';
        $last_name  = '';
        $email      = '';
        $phone      = '';

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
                wp_safe_redirect(add_query_arg('tshirt_error', $err, $page_url));
                exit;
            }

            // Find existing member or create visitor member
            $match = AVBK_DB::find_or_create_member_for_registration($first_name, $suffix, $last_name, $email, $phone);
            $member_id = (int) ($match['member_id'] ?? 0);
        }

        // Save active address only if provided
        if ($member_id > 0 && ($street !== '' || $postal_code !== '' || $city !== '')) {
            AVBK_DB::save_member_address($member_id, [
                'street'       => $street,
                'house_number' => $house_number,
                'postal_code'  => $postal_code,
                'city'         => $city,
                'country'      => $country,
            ]);
        }

        // Create fee item
        $title_opt = get_option('avbk_tshirt_title', self::DEFAULT_TITLE) ?: self::DEFAULT_TITLE;
        $fee_item_id = 0;
        if ($member_id > 0) {
            $fee_item_id = AVBK_DB::create_tshirt_fee_item($member_id, $total_quantity, $total_amount, $title_opt);
        }

        $status = $member ? 'confirmed' : 'pending_confirmation';

        $order_result = AVBK_DB::create_order([
            'order_type'          => 'tshirt',
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
            'quantity'            => $total_quantity,
            'unit_price'          => $total_quantity > 0 ? round($total_amount / $total_quantity, 2) : $unit_price,
            'total_amount'        => $total_amount,
            'notes'               => $notes,
            'status'              => $status,
        ], $items);

        $confirm_link = add_query_arg('tshirt_token', $order_result['token'], $page_url);

        // Build item breakdown text for email
        $items_text = '';
        foreach ($items as $it) {
            $items_text .= "- {$it['quantity']}x {$it['title']} (Maat: {$it['variant']}) — € " . number_format($it['total_price'], 2, ',', '.') . "\n";
        }

        $price_note_opt = get_option('avbk_tshirt_price_note', self::DEFAULT_PRICE_NOTE) ?: self::DEFAULT_PRICE_NOTE;
        $price_note_email = !empty($price_note_opt) ? "Prijsnotitie: {$price_note_opt}\n\n" : '';

        // If user was logged in, order is confirmed immediately.
        if ($member) {
            $subject = "Bevestiging bestelling {$title_opt}";
            $body = "Beste {$first_name},\n\n"
                . "Bedankt voor je bestelling van {$total_quantity} kledingstuk(ken) van '{$title_opt}':\n\n"
                . "{$items_text}\n"
                . "Totaalbedrag: € " . number_format($total_amount, 2, ',', '.') . "\n"
                . "{$price_note_email}"
                . "Let op: met deze bestelling is een betalingsverplichting ontstaan (bestellen betekent betalen).\n"
                . "Je bestelling en QR-code om te betalen kun je bekijken via deze link:\n{$confirm_link}\n\n"
                . "Met vriendelijke groet,\nAV Philips van Horne";
            wp_mail($email, $subject, $body);

            wp_safe_redirect($confirm_link);
            exit;
        }

        // Guest visitor: send verification link
        $subject = "Bevestig je bestelling — {$title_opt}";
        $body = "Beste {$first_name},\n\n"
            . "Bedankt voor je bestelling van {$total_quantity} kledingstuk(ken) van '{$title_opt}':\n\n"
            . "{$items_text}\n"
            . "Totaalbedrag: € " . number_format($total_amount, 2, ',', '.') . "\n"
            . "{$price_note_email}"
            . "Let op: met deze bestelling ga je een betalingsverplichting aan (bestellen betekent betalen).\n"
            . "Klik op onderstaande link om je bestelling definitief te bevestigen en de QR-code voor betaling te openen:\n{$confirm_link}\n\n"
            . "Met vriendelijke groet,\nAV Philips van Horne";

        $mail_error = '';
        $capture_error = function ($wp_error) use (&$mail_error) {
            $mail_error = $wp_error->get_error_message();
        };
        add_action('wp_mail_failed', $capture_error);
        $sent = wp_mail($email, $subject, $body);
        remove_action('wp_mail_failed', $capture_error);

        AVBK_DB::mark_order_email_result((int) $order_result['id'], $sent, $mail_error);

        if ($sent) {
            wp_safe_redirect(add_query_arg('tshirt_ordered', '1', $page_url));
        } else {
            AVBK_DB::confirm_order((int) $order_result['id']);
            wp_safe_redirect(add_query_arg(['tshirt_token' => $order_result['token'], 'email_failed' => '1'], $page_url));
        }
        exit;
    }

    public function handle_check_email(): void {
        check_admin_referer('avbk_check_tshirt_email');

        $email    = sanitize_email(wp_unslash($_POST['check_email'] ?? ''));
        $page_url = esc_url_raw(wp_unslash($_POST['page_url'] ?? '')) ?: home_url('/tshirt/');
        $is_ajax  = !empty($_POST['ajax']) || (defined('DOING_AJAX') && DOING_AJAX);

        if (!is_email($email)) {
            if ($is_ajax) {
                wp_send_json_error(['message' => 'Vul een geldig e-mailadres in om te controleren.', 'status' => 'invalid_email']);
            }
            wp_safe_redirect(add_query_arg(['email_check' => 'invalid_email'], $page_url));
            exit;
        }

        $member = AVBK_DB::find_member_by_email($email);
        $authelia_url = (class_exists('AVPVH_Nav_Auth') && defined('AVPVH_Nav_Auth::AUTHELIA_URL'))
            ? AVPVH_Nav_Auth::AUTHELIA_URL
            : 'https://auth.avphilipsvanhorne.nl';
        $reset_url = add_query_arg('username', rawurlencode($email), rtrim($authelia_url, '/') . '/reset-password/step1');
        $login_url = home_url('/avpvh-login/');

        if (!$member) {
            if ($is_ajax) {
                wp_send_json_success([
                    'status'  => 'not_found',
                    'email'   => $email,
                    'message' => 'Dit e-mailadres is nog niet bekend bij ons. Je hoeft niet eerst in te loggen: vul hieronder je gegevens in om te bestellen. Er wordt dan automatisch een account voor je aangemaakt.',
                ]);
            }
            wp_safe_redirect(add_query_arg(['email_check' => 'not_found', 'checked_email' => rawurlencode($email)], $page_url));
            exit;
        }

        $has_logged_in = AVBK_DB::member_has_logged_in($member, $email);

        if ($has_logged_in) {
            if ($is_ajax) {
                wp_send_json_success([
                    'status'    => 'already_active',
                    'email'     => $email,
                    'login_url' => $login_url,
                    'reset_url' => $reset_url,
                    'message'   => 'Dit e-mailadres is bekend en je account is al actief.',
                ]);
            }
            wp_safe_redirect(add_query_arg(['email_check' => 'already_active', 'checked_email' => rawurlencode($email)], $page_url));
            exit;
        }

        $transient_key = 'avbk_reset_sent_' . md5(strtolower($email));
        $already_sent = (bool) get_transient($transient_key);

        if (!$already_sent) {
            $first_name = !empty($member->first_name) ? (string) $member->first_name : 'lid';
            $subject = 'AV Philips van Horne — Wachtwoord aanmaken';
            $body = "Beste {$first_name},\n\n"
                . "Je e-mailadres ({$email}) is bekend bij AV Philips van Horne, maar je bent nog niet eerder ingelogd.\n\n"
                . "Via onderstaande link kun je een wachtwoord aanmaken:\n"
                . "{$reset_url}\n\n"
                . "Zodra je een wachtwoord hebt aangemaakt, kun je inloggen om direct te bestellen en je bestelling aan je profiel te koppelen:\n"
                . "{$page_url}\n\n"
                . "Met vriendelijke groet,\n"
                . "AV Philips van Horne";

            wp_mail($email, $subject, $body);
            set_transient($transient_key, 1, 600);
        }

        if ($is_ajax) {
            wp_send_json_success([
                'status'    => 'reset_sent',
                'email'     => $email,
                'login_url' => $login_url,
                'message'   => 'Je e-mailadres is bekend in onze administratie, maar je bent nog niet eerder ingelogd. We hebben je een e-mail gestuurd met een link om een wachtwoord aan te maken. Zodra je een wachtwoord hebt aangemaakt, kun je inloggen en direct bestellen.',
            ]);
        }

        wp_safe_redirect(add_query_arg(['email_check' => 'reset_sent', 'checked_email' => rawurlencode($email)], $page_url));
        exit;
    }
}
