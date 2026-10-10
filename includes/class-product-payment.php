<?php
defined('ABSPATH') || exit;

/** Shared, amount-free payment requests configured separately for each product. */
class AVBK_Product_Payment {
    private const PRODUCTS = ['tshirt', 'book'];
    private const MIMES = ['png' => 'image/png', 'jpg|jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf'];

    public function __construct() {
        add_action('admin_post_avbk_save_product_payment', [$this, 'handle_save']);
        add_shortcode('avpvh_bk_product_payment', [$this, 'shortcode']);
        add_filter('get_attached_file', [self::class, 'public_file_path'], 10, 2);
    }

    private static function can_manage(): bool {
        return current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('penningmeester');
    }

    public static function get(string $product): array {
        if (!in_array($product, self::PRODUCTS, true)) {
            return ['url' => '', 'attachment_id' => 0];
        }
        $saved = get_option('avbk_product_payment_' . $product, []);
        return ['url' => is_array($saved) ? (string) ($saved['url'] ?? '') : '',
            'attachment_id' => is_array($saved) ? (int) ($saved['attachment_id'] ?? 0) : 0];
    }

    /** Validate before changing settings; failed uploads preserve the old request. */
    public static function save(string $product, string $url, array $file, bool $remove): true|WP_Error {
        if (!self::can_manage() || !in_array($product, self::PRODUCTS, true)) {
            return new WP_Error('forbidden', 'Geen toegang.');
        }
        $url = trim($url);
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || wp_parse_url($url, PHP_URL_USER) !== null || wp_parse_url($url, PHP_URL_PASS) !== null)) {
            return new WP_Error('invalid_url', 'Vul een volledige https-betaallink in.');
        }
        $saved = self::get($product);
        $attachment_id = $remove ? 0 : $saved['attachment_id'];
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_NO_FILE) {
            if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
                return new WP_Error('upload_failed', 'De upload is mislukt. Probeer het bestand opnieuw te uploaden.');
            }
            if (filesize($file['tmp_name']) > 3 * 1024 * 1024) {
                return new WP_Error('upload_size', 'Het bestand mag maximaal 3 MB groot zijn.');
            }
            $type = wp_check_filetype_and_ext($file['tmp_name'], $file['name'] ?? '', self::MIMES);
            if (empty($type['ext']) || !in_array($type['type'] ?? '', self::MIMES, true)) {
                return new WP_Error('upload_type', 'Upload een PNG-, JPG-, WebP- of GIF-afbeelding, of een PDF.');
            }
            if ($type['type'] === 'application/pdf') {
                $handle = fopen($file['tmp_name'], 'rb');
                $signature = $handle ? fread($handle, 5) : '';
                if ($handle) { fclose($handle); }
                if ($signature !== '%PDF-') {
                    return new WP_Error('upload_type', 'Dit bestand is geen geldige PDF.');
                }
            } else {
                $image = @getimagesize($file['tmp_name']);
                if (!$image || ($image['mime'] ?? '') !== $type['type']) {
                    return new WP_Error('upload_type', 'Dit bestand is geen geldige QR-afbeelding.');
                }
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            // The financial-role gate above authorizes this specific upload,
            // including treasurers without general media-library permissions.
            add_filter('upload_dir', [self::class, 'public_upload_dir'], PHP_INT_MAX);
            try {
                $attachment_id = media_handle_upload('qr_upload', 0, [], ['test_form' => false, 'mimes' => self::MIMES]);
            } finally {
                remove_filter('upload_dir', [self::class, 'public_upload_dir'], PHP_INT_MAX);
            }
            if (is_wp_error($attachment_id)) {
                return new WP_Error('upload_failed', 'De upload kon niet worden opgeslagen. Het bestaande betaalverzoek is behouden.');
            }
        }
        update_option('avbk_product_payment_' . $product, ['url' => esc_url_raw($url), 'attachment_id' => (int) $attachment_id], false);
        return true;
    }

    /** Payment requests are public; scope this override to their upload only. */
    public static function public_upload_dir(array $dirs): array {
        // The site's public /wp-content/ URL maps to the wp-content-pvh
        // volume. Use this plugin's actual content directory for file writes.
        $dirs['basedir'] = dirname(rtrim(AVBK_PLUGIN_DIR, '/'), 2) . '/uploads';
        $subdir = preg_replace('#^/public/payment-requests(?=/|$)#', '', $dirs['subdir']);
        $subdir = preg_replace('#^/(?:private|public)(?=/|$)#', '', $subdir);
        $dirs['subdir'] = '/public/payment-requests' . $subdir;
        $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
        $dirs['url'] = $dirs['baseurl'] . $dirs['subdir'];
        // An earlier upload_dir filter may report a private-path error.
        $dirs['error'] = false;
        return $dirs;
    }

    /** Keep attachment management on the same volume after upload filters end. */
    public static function public_file_path($file, int $attachment_id) {
        $relative = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        if (str_starts_with($relative, 'public/payment-requests/') && !str_contains($relative, "\0")
            && !str_contains($relative, '\\') && !preg_match('#(^|/)\.\.?(/|$)#', $relative)) {
            return dirname(rtrim(AVBK_PLUGIN_DIR, '/'), 2) . '/uploads/' . $relative;
        }
        return $file;
    }

    public function handle_save(): void {
        if (!self::can_manage()) {
            wp_die('Geen toegang.', '', ['response' => 403]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('Gebruik het instellingenformulier.', '', ['response' => 405]);
        }
        $product = sanitize_key(wp_unslash($_POST['product'] ?? ''));
        check_admin_referer('avbk_save_product_payment_' . $product);
        $result = self::save($product, wp_unslash($_POST['payment_url'] ?? ''), $_FILES['qr_upload'] ?? [], !empty($_POST['remove_upload']));
        if (is_wp_error($result)) {
            set_transient('avbk_product_payment_error_' . get_current_user_id(), $result->get_error_message(), 10 * MINUTE_IN_SECONDS);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-orders', 'tab' => 'settings', 'product_payment_saved' => is_wp_error($result) ? '0' : '1'], admin_url('admin.php')) . '#payment-' . $product);
        exit;
    }

    public function shortcode($attributes): string {
        $attributes = shortcode_atts(['product' => 'tshirt'], $attributes);
        return self::render((string) $attributes['product']);
    }

    /** An uploaded image takes precedence; a link can also generate its own QR. */
    public static function render(string $product, ?float $amount = null, int $order_id = 0): string {
        $saved = self::get($product);
        $file_url = $saved['attachment_id'] ? wp_get_attachment_url($saved['attachment_id']) : '';
        $mime = $saved['attachment_id'] ? get_post_mime_type($saved['attachment_id']) : '';
        $image = $file_url && in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true);
        $pdf = $file_url && $mime === 'application/pdf';
        if ($saved['url'] === '' && !$image && !$pdf) {
            return '';
        }
        $qr = !$image && $saved['url'] !== '' && class_exists('AVBK_QR') ? AVBK_QR::svg($saved['url']) : null;
        ob_start(); ?>
        <div class="avbk-product-payment<?php echo $amount === null ? ' avbk-book-payment-box' : ''; ?>">
            <h3><?php echo $amount === null ? 'Bestaande bestelling betalen' : 'Betaal je bestelling'; ?></h3>
            <?php if ($amount !== null) : ?>
                <p>Vul in het betaalverzoek zelf <strong>&euro;&nbsp;<?php echo esc_html(number_format($amount, 2, ',', '.')); ?></strong> in.</p>
            <?php else : ?>
                <p>Heb je al besteld? Vul zelf het openstaande bedrag uit je bestelbevestiging in. Dit betaalverzoek plaatst geen nieuwe bestelling.</p>
            <?php endif; ?>
            <?php if ($order_id > 0) : ?><p>Je bestelnummer is <strong>#<?php echo (int) $order_id; ?></strong>.</p><?php endif; ?>
            <?php if ($saved['url'] !== '') : ?>
                <p><a class="button button-primary" href="<?php echo esc_url($saved['url']); ?>" target="_blank" rel="noopener noreferrer">Open het betaalverzoek</a></p>
            <?php endif; ?>
            <?php if ($image) : ?>
                <div class="avbk-book-qr-render" style="max-width:256px"><img src="<?php echo esc_url($file_url); ?>" alt="QR-code voor het betaalverzoek" style="width:256px;max-width:100%;height:auto" loading="lazy"></div>
                <p>Scan de QR-code om het betaalverzoek te openen.</p>
            <?php elseif ($qr) : ?>
                <div class="avbk-book-qr-render" style="max-width:256px"><?php echo $qr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- server-rendered QR SVG ?></div>
                <p>Scan de QR-code om het betaalverzoek te openen.</p>
            <?php endif; ?>
            <?php if ($pdf) : ?><p><a href="<?php echo esc_url($file_url); ?>" target="_blank" rel="noopener noreferrer">Bekijk het betaalverzoek (PDF)</a></p><?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }

    public static function render_settings(string $product, string $label): void {
        if (!self::can_manage() || !in_array($product, self::PRODUCTS, true)) { return; }
        $saved = self::get($product);
        ?>
        <h2 id="payment-<?php echo esc_attr($product); ?>">Betaalverzoek &mdash; <?php echo esc_html($label); ?></h2>
        <p>Stel één gedeeld betaalverzoek met de productomschrijving en zonder vast bedrag in. Bestellers vullen zelf hun openstaande bedrag in. De link en QR worden op de bevestigingspagina getoond. Zonder betaalverzoek blijft de bankoverschrijvingscode beschikbaar.</p>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('avbk_save_product_payment_' . $product); ?>
            <input type="hidden" name="action" value="avbk_save_product_payment">
            <input type="hidden" name="product" value="<?php echo esc_attr($product); ?>">
            <table class="form-table">
                <tr><th><label for="payment-url-<?php echo esc_attr($product); ?>">Betaallink</label></th><td><input type="url" id="payment-url-<?php echo esc_attr($product); ?>" name="payment_url" class="large-text" value="<?php echo esc_attr($saved['url']); ?>" placeholder="https://…"><p class="description">Bijvoorbeeld een ING-betaalverzoek of Tikkie. Laat het bedrag vrij. Zonder geüploade QR-afbeelding maken we een QR-code van deze link.</p></td></tr>
                <tr><th><label for="payment-upload-<?php echo esc_attr($product); ?>">QR-code of betaalverzoek uploaden</label></th><td><input type="file" id="payment-upload-<?php echo esc_attr($product); ?>" name="qr_upload" accept="image/png,image/jpeg,image/webp,image/gif,application/pdf"><p class="description">PNG, JPG, WebP, GIF of PDF, maximaal 3 MB. Het bestand is zichtbaar voor bestellers. Controleer dat link en upload hetzelfde betaalverzoek openen.</p>
                    <?php if ($saved['attachment_id']) : ?>
                        <p><a href="<?php echo esc_url(wp_get_attachment_url($saved['attachment_id']) ?: ''); ?>" target="_blank" rel="noopener noreferrer">Bekijk huidige upload</a></p>
                        <label><input type="checkbox" name="remove_upload" value="1"> Huidige upload van dit betaalverzoek verwijderen</label>
                    <?php endif; ?>
                </td></tr>
            </table>
            <?php submit_button('Betaalverzoek opslaan'); ?>
        </form>
        <?php
    }
}
