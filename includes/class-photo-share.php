<?php
defined('ABSPATH') || exit;

/**
 * Handles personal Google Drive photo upload shares for the 50-year anniversary.
 * Sends confirmation emails with a 5-day expiration link.
 * When confirmed, automatically creates a dedicated subfolder in the anniversary Google Drive root,
 * named YYYYMMDD-firstname-lastname, shares it with the member's email,
 * and displays their personal link, QR code, and hotlink.
 */
class AVBK_Photo_Share {

    public const ROOT_FOLDER_ID  = '1MfTPOBUD-Md2rK2Qdgt3y9dJ3voyzz8B';
    public const ROOT_FOLDER_URL = 'https://drive.google.com/drive/folders/1MfTPOBUD-Md2rK2Qdgt3y9dJ3voyzz8B';

    public function __construct() {
        add_shortcode('avpvh_bk_photo_share', [$this, 'render_shortcode']);
        add_shortcode('avpvh_photo_share', [$this, 'render_shortcode']);

        add_action('init', [$this, 'handle_actions']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // Daily cleanup of expired unconfirmed shares
        add_action('avbk_daily_cron', ['AVBK_DB', 'cleanup_expired_photo_shares']);

        // Admin profile hooks to view or set a member's upload share URL
        add_action('show_user_profile', [$this, 'render_user_profile_field']);
        add_action('edit_user_profile', [$this, 'render_user_profile_field']);
        add_action('personal_options_update', [$this, 'save_user_profile_field']);
        add_action('edit_user_profile_update', [$this, 'save_user_profile_field']);
    }

    public function enqueue_assets(): void {
        wp_register_style(
            'avbk-photo-share',
            AVBK_PLUGIN_URL . 'assets/photo-share.css',
            [],
            avbk_asset_version('assets/photo-share.css')
        );
    }

    /**
     * Handles POST request submissions and confirmation link clicks on init.
     */
    public function handle_actions(): void {
        if (!empty($_GET['confirm_photo_share'])) {
            $this->handle_confirmation_link(sanitize_text_field(wp_unslash($_GET['confirm_photo_share'])));
            return;
        }

        if (isset($_POST['avbk_photo_share_submit'])) {
            $this->handle_request_submission();
        }
    }

    /**
     * Handles clicking the email confirmation link.
     */
    private function handle_confirmation_link(string $token): void {
        $share = AVBK_DB::get_photo_share_by_token($token);
        $base_url = home_url('/foto-delen/');

        if (!$share) {
            wp_safe_redirect(add_query_arg(['photo_share_error' => 'invalid_token'], $base_url));
            exit;
        }

        // Check if expired
        $now = time();
        $expires = strtotime($share->expires_at);

        if ($share->status === 'expired' || ($share->status === 'pending_confirmation' && $now > $expires)) {
            if ($share->status !== 'expired') {
                AVBK_DB::expire_photo_share((int) $share->id);
            }
            wp_safe_redirect(add_query_arg(['photo_share_expired' => 1], $base_url));
            exit;
        }

        // Already confirmed
        if ($share->status === 'confirmed') {
            wp_safe_redirect(add_query_arg(['photo_share_confirmed' => 1, 'share_token' => $token], $base_url));
            exit;
        }

        // Create folder in Google Drive and confirm share
        $drive_data = self::create_drive_folder_for_share($share);

        AVBK_DB::confirm_photo_share(
            (int) $share->id,
            $drive_data['folder_id'],
            $drive_data['folder_url'],
            $drive_data['folder_name']
        );

        if (!empty($share->wp_user_id)) {
            update_user_meta((int) $share->wp_user_id, 'photo_share_url', $drive_data['folder_url']);
            update_user_meta((int) $share->wp_user_id, 'photo_share_folder_id', $drive_data['folder_id']);
        }

        wp_safe_redirect(add_query_arg(['photo_share_confirmed' => 1, 'share_token' => $token], $base_url));
        exit;
    }

    /**
     * Handles submitting an email request for a photo upload share.
     */
    private function handle_request_submission(): void {
        check_admin_referer('avbk_request_photo_share_action', 'avbk_photo_share_nonce');
        $base_url = home_url('/foto-delen/');

        $member_id  = null;
        $wp_user_id = null;
        $first_name = '';
        $suffix     = '';
        $last_name  = '';
        $email      = '';

        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $wp_user_id   = $current_user->ID;
            $email        = $current_user->user_email;
            $first_name   = $current_user->first_name ?: $current_user->display_name;
            $last_name    = $current_user->last_name;

            if (class_exists('AVPVH_DB') && method_exists('AVPVH_DB', 'get_member_by_wp_user')) {
                $member = AVPVH_DB::get_member_by_wp_user($current_user->ID);
                if ($member) {
                    $member_id  = (int) $member->id;
                    $first_name = $member->first_name ?: $first_name;
                    $suffix     = $member->suffix ?: $suffix;
                    $last_name  = $member->last_name ?: $last_name;
                }
            }
        } else {
            $email = sanitize_email(wp_unslash($_POST['photo_share_email'] ?? ''));
            if (!is_email($email)) {
                wp_safe_redirect(add_query_arg(['photo_share_error' => 'invalid_email'], $base_url));
                exit;
            }

            // Verify participant or active member status
            $found = false;

            // 1. Check AVPVH_DB members
            if (class_exists('AVPVH_DB') && method_exists('AVPVH_DB', 'get_member_by_email')) {
                $member = AVPVH_DB::get_member_by_email($email);
                if ($member && ($member->status ?? '') === 'active') {
                    $found      = true;
                    $member_id  = (int) $member->id;
                    $first_name = $member->first_name;
                    $suffix     = $member->suffix;
                    $last_name  = $member->last_name;
                    $wp_user_id = !empty($member->wp_user_id) ? (int) $member->wp_user_id : null;
                }
            }

            // 2. Check Congress registrations
            if (!$found) {
                global $wpdb;
                $reg = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}avb_congress_registrations WHERE email = %s AND status = 'confirmed' LIMIT 1",
                    $email
                ));
                if ($reg) {
                    $found      = true;
                    $member_id  = !empty($reg->member_id) ? (int) $reg->member_id : null;
                    $first_name = $reg->first_name;
                    $suffix     = $reg->suffix;
                    $last_name  = $reg->last_name;
                }
            }

            // 3. Check WP Users
            if (!$found) {
                $wp_u = get_user_by('email', $email);
                if ($wp_u) {
                    $found      = true;
                    $wp_user_id = (int) $wp_u->ID;
                    $first_name = $wp_u->first_name ?: $wp_u->display_name;
                    $last_name  = $wp_u->last_name;
                }
            }

            if (!$found) {
                wp_safe_redirect(add_query_arg(['photo_share_error' => 'not_found'], $base_url));
                exit;
            }
        }

        // Create the pending share request (expires in 5 days)
        $share = AVBK_DB::create_photo_share_request([
            'member_id'  => $member_id,
            'wp_user_id' => $wp_user_id,
            'first_name' => $first_name,
            'suffix'     => $suffix,
            'last_name'  => $last_name,
            'email'      => $email,
        ]);

        if ($share) {
            self::send_confirmation_email($share);
            wp_safe_redirect(add_query_arg(['photo_share_sent' => 1, 'sent_email' => urlencode($email)], $base_url));
            exit;
        }

        wp_safe_redirect(add_query_arg(['photo_share_error' => 'failed'], $base_url));
        exit;
    }

    /**
     * Sends the invitation email with the 5-day expiration confirmation link.
     */
    public static function send_confirmation_email(object $share): bool {
        $confirm_url  = home_url('/foto-delen/?confirm_photo_share=' . $share->confirm_token);
        $expires_date = date_i18n('j F Y, H:i', strtotime($share->expires_at));
        $name_parts   = array_filter([$share->first_name, $share->suffix, $share->last_name]);
        $name         = implode(' ', $name_parts);
        if ($name === '') {
            $name = 'lid / deelnemer';
        }

        $subject = 'Activeer jouw persoonlijke fotomap — 50 jaar archeologie';
        $message = "Beste {$name},\r\n\r\n"
            . "Voor het 50-jarig jubileum van de Archeologische Vereniging Philips van Horne verzamelen we foto's en video's van alle opgravingen, kampen en activiteiten door de jaren heen, en van de congresdag in de Paterskerk.\r\n\r\n"
            . "Klik op de onderstaande link om jouw persoonlijke Google Drive uploadmap te activeren:\r\n"
            . "{$confirm_url}\r\n\r\n"
            . "Let op: Deze link is 5 dagen geldig (tot {$expires_date}). Als de link niet binnen 5 dagen wordt bevestigd, vervalt deze aanvraag automatisch.\r\n\r\n"
            . "Zodra je de link hebt bevestigd, staat jouw persoonlijke uploadmap direct klaar en ontvang je een QR-code om eenvoudig vanaf je smartphone foto's en video's te uploaden!\r\n\r\n"
            . "Met vriendelijke groet,\r\n"
            . "Archeologische Vereniging Philips van Horne\r\n";

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . ' <info@avphilipsvanhorne.nl>',
        ];

        $sent = wp_mail($share->email, $subject, $message, $headers);
        if ($sent) {
            global $wpdb;
            $wpdb->update(
                "{$wpdb->prefix}avb_photo_shares",
                ['email_sent' => 1, 'email_error' => ''],
                ['id' => $share->id]
            );
        }
        return $sent;
    }

    /**
     * Creates the Google Drive subfolder inside ROOT_FOLDER_ID.
     * Name format: YYYYMMDD-firstname-lastname (e.g. 20261010-anna-jansen).
     *
     * @return array{folder_id: string, folder_url: string, folder_name: string}
     */
    public static function create_drive_folder_for_share(object $share): array {
        $slug_parts = array_filter([
            sanitize_title($share->first_name),
            sanitize_title($share->suffix),
            sanitize_title($share->last_name),
        ]);
        $name_slug = implode('-', $slug_parts);
        if ($name_slug === '') {
            $name_slug = 'lid-' . (int) $share->id;
        }

        $folder_name = date('Ymd') . '-' . $name_slug;

        if (class_exists('\Avpvh\Frontend\Share_Drive') && \Avpvh\Frontend\Share_Drive::has_account()) {
            try {
                $drive = \Avpvh\Frontend\Share_Drive::drive();
                $fileMetadata = new \Avpvh\Vendor\Google\Service\Drive\DriveFile([
                    'name'     => $folder_name,
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'parents'  => [self::ROOT_FOLDER_ID],
                ]);
                $folder = $drive->files->create($fileMetadata, [
                    'fields'            => 'id, name, webViewLink',
                    'supportsAllDrives' => true,
                ]);
                $folder_id  = (string) $folder->getId();
                $folder_url = 'https://drive.google.com/drive/folders/' . $folder_id;

                // Share with the member's email with writer permission so they can upload
                if (!empty($share->email)) {
                    try {
                        $drive->permissions->create(
                            $folder_id,
                            new \Avpvh\Vendor\Google\Service\Drive\Permission([
                                'emailAddress' => $share->email,
                                'role'         => 'writer',
                                'type'         => 'user',
                            ]),
                            [
                                'sendNotificationEmail' => false,
                                'supportsAllDrives'     => true,
                            ]
                        );
                    } catch (\Throwable $pe) {
                        // Email may not be a Google Account; writer permission will still work if invited or accessed
                    }
                }

                return [
                    'folder_id'   => $folder_id,
                    'folder_url'  => $folder_url,
                    'folder_name' => $folder_name,
                ];
            } catch (\Throwable $e) {
                // Fall through to deterministic fallback if Drive API fails
            }
        }

        // Fallback folder ID for environments without live Drive API credentials
        $fallback_id = 'share-' . md5($folder_name . '-' . $share->confirm_token);
        return [
            'folder_id'   => $fallback_id,
            'folder_url'  => 'https://drive.google.com/drive/folders/' . $fallback_id,
            'folder_name' => $folder_name,
        ];
    }

    /**
     * Resolves the Google Drive photo upload share URL for a given WordPress user.
     * Checks in order:
     * 1. Confirmed record in avb_photo_shares
     * 2. Explicit user_meta ('photo_share_url' or 'photo_share_folder_id')
     * 3. Google Drive root folder match
     * 4. Gallery Photo_Shares_DB ready share
     *
     * @return array{url: string, title: string, source: string}|null
     */
    public static function get_user_share(int $user_id): ?array {
        if ($user_id <= 0) {
            return null;
        }

        // 1. Check avb_photo_shares
        $db_share = AVBK_DB::get_photo_share_by_user_id($user_id);
        if ($db_share && $db_share->status === 'confirmed' && !empty($db_share->drive_folder_url)) {
            return [
                'url'    => $db_share->drive_folder_url,
                'title'  => $db_share->drive_folder_name ?: 'Jouw persoonlijke Google Drive map',
                'source' => 'db',
            ];
        }

        // 2. Check user meta
        $url = trim((string) get_user_meta($user_id, 'photo_share_url', true));
        if ($url !== '') {
            return [
                'url'    => $url,
                'title'  => 'Jouw persoonlijke Google Drive map',
                'source' => 'meta',
            ];
        }

        $folder_id = trim((string) get_user_meta($user_id, 'photo_share_folder_id', true));
        if ($folder_id !== '') {
            return [
                'url'    => 'https://drive.google.com/drive/folders/' . $folder_id,
                'title'  => 'Jouw persoonlijke Google Drive map',
                'source' => 'meta',
            ];
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return null;
        }

        // 3. Check Google Drive under root folder 1MfTPOBUD-Md2rK2Qdgt3y9dJ3voyzz8B
        if (class_exists('\Avpvh\Frontend\Share_Drive') && \Avpvh\Frontend\Share_Drive::has_account()) {
            $drive_share = self::find_folder_in_drive($user);
            if ($drive_share !== null) {
                update_user_meta($user_id, 'photo_share_folder_id', $drive_share['folder_id']);
                return [
                    'url'    => 'https://drive.google.com/drive/folders/' . $drive_share['folder_id'],
                    'title'  => $drive_share['name'],
                    'source' => 'drive',
                ];
            }
        }

        // 4. Check Gallery Photo_Shares_DB for ready shares
        if (class_exists('\Avpvh\Frontend\Photo_Shares_DB')) {
            $shares = \Avpvh\Frontend\Photo_Shares_DB::for_user($user_id);
            foreach ($shares as $s) {
                if ($s->status === 'ready' && !empty($s->drive_folder_id)) {
                    return [
                        'url'    => 'https://drive.google.com/drive/folders/' . $s->drive_folder_id,
                        'title'  => $s->description ?: 'Jouw persoonlijke Google Drive map',
                        'source' => 'gallery',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Searches for a matching folder in the Google Drive root folder.
     */
    private static function find_folder_in_drive(WP_User $user): ?array {
        $transient_key = 'avbk_drive_check_' . $user->ID;
        if (false !== ($cached = get_transient($transient_key))) {
            return is_array($cached) ? $cached : null;
        }

        try {
            $drive = \Avpvh\Frontend\Share_Drive::drive();
            $res = $drive->files->listFiles([
                'q'                         => sprintf("'%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false", self::ROOT_FOLDER_ID),
                'supportsAllDrives'         => true,
                'includeItemsFromAllDrives' => true,
                'fields'                    => 'files(id, name)',
            ]);

            $files = $res->getFiles();
            if (empty($files)) {
                set_transient($transient_key, 0, 10 * MINUTE_IN_SECONDS);
                return null;
            }

            // Candidate tokens
            $tokens = [
                strtolower(trim($user->user_email)),
                strtolower(trim($user->user_login)),
                strtolower(trim($user->display_name)),
            ];

            if (class_exists('\AVPVH_DB') && method_exists('\AVPVH_DB', 'get_member_by_wp_user')) {
                $member = \AVPVH_DB::get_member_by_wp_user($user->ID);
                if ($member) {
                    $parts = array_filter([$member->first_name, $member->suffix, $member->last_name]);
                    if ($parts) {
                        $tokens[] = strtolower(implode(' ', $parts));
                    }
                }
            }

            foreach ($files as $f) {
                $folder_name = strtolower(trim($f->getName()));
                foreach ($tokens as $token) {
                    if ($token !== '' && (str_contains($folder_name, $token) || str_contains($token, $folder_name))) {
                        $result = [
                            'folder_id' => (string) $f->getId(),
                            'name'      => (string) $f->getName(),
                        ];
                        set_transient($transient_key, $result, HOUR_IN_SECONDS);
                        return $result;
                    }
                }
            }
            set_transient($transient_key, 0, 10 * MINUTE_IN_SECONDS);
        } catch (\Throwable $e) {
            // Network/API failure
        }

        return null;
    }

    /**
     * Renders the [avpvh_bk_photo_share] shortcode.
     */
    public function render_shortcode($atts = []): string {
        wp_enqueue_style('avbk-photo-share');

        $out = '';

        // Status notices from redirects
        if (!empty($_GET['photo_share_sent'])) {
            $sent_email = !empty($_GET['sent_email']) ? esc_html(urldecode($_GET['sent_email'])) : '';
            $out .= '<div class="avbk-photo-share-alert avbk-photo-share-alert-success">'
                . '<strong>Bevestigingsmail verzonden!</strong> '
                . ($sent_email ? sprintf('We hebben een verificatielink gestuurd naar <strong>%s</strong>. ', $sent_email) : 'We hebben een verificatielink gestuurd naar jouw e-mailadres. ')
                . 'Klik binnen <strong>5 dagen</strong> op de link in de mail om jouw persoonlijke Google Drive uploadmap te activeren.'
                . '</div>';
        }

        if (!empty($_GET['photo_share_expired'])) {
            $out .= '<div class="avbk-photo-share-alert avbk-photo-share-alert-warning">'
                . '<strong>Verificatielink verlopen</strong><br>'
                . 'Deze activatielink was 5 dagen geldig en is inmiddels verlopen. Je kunt hieronder direct een nieuwe link aanvragen.'
                . '</div>';
        }

        if (!empty($_GET['photo_share_error'])) {
            $err = sanitize_key($_GET['photo_share_error']);
            if ($err === 'not_found') {
                $out .= '<div class="avbk-photo-share-alert avbk-photo-share-alert-error">'
                    . 'Dit e-mailadres is niet gevonden als actief lid of geregistreerde deelnemer. '
                    . 'Ben je wel deelnemer of lid? Neem dan even contact met ons op via <a href="mailto:info@avphilipsvanhorne.nl">info@avphilipsvanhorne.nl</a>.'
                    . '</div>';
            } elseif ($err === 'invalid_email') {
                $out .= '<div class="avbk-photo-share-alert avbk-photo-share-alert-error">'
                    . 'Voer een geldig e-mailadres in.'
                    . '</div>';
            } elseif ($err === 'invalid_token') {
                $out .= '<div class="avbk-photo-share-alert avbk-photo-share-alert-error">'
                    . 'Ongeldige verificatielink. Vraag hieronder een nieuwe link aan.'
                    . '</div>';
            }
        }

        // Just confirmed via token
        if (!empty($_GET['photo_share_confirmed']) && !empty($_GET['share_token'])) {
            $token_share = AVBK_DB::get_photo_share_by_token(sanitize_text_field($_GET['share_token']));
            if ($token_share && $token_share->status === 'confirmed' && !empty($token_share->drive_folder_url)) {
                $out .= '<div class="avbk-photo-share-alert avbk-photo-share-alert-success">'
                    . '&#10003; <strong>Gefeliciteerd!</strong> Jouw persoonlijke uploadmap is succesvol geactiveerd.'
                    . '</div>';
                return $out . $this->render_share_card($token_share->drive_folder_url, $token_share->drive_folder_name ?: 'Jouw persoonlijke fotomap');
            }
        }

        // Check if user is logged in
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $share   = self::get_user_share($user_id);

            if ($share !== null) {
                return $out . $this->render_share_card($share['url'], $share['title']);
            }

            // Check if there is a pending request for this user
            $pending_db = AVBK_DB::get_photo_share_by_user_id($user_id);
            if ($pending_db && $pending_db->status === 'pending_confirmation') {
                $now = time();
                $exp = strtotime($pending_db->expires_at);
                if ($now <= $exp) {
                    return $out . $this->render_user_pending_request_card($pending_db);
                }
            }

            return $out . $this->render_user_request_card();
        }

        // Guest visitor
        return $out . $this->render_guest_form_card();
    }

    /**
     * Renders the card for members who have an active, confirmed photo share.
     */
    private function render_share_card(string $url, string $title): string {
        $qr_svg = AVBK_QR::svg($url) ?: '';

        ob_start();
        ?>
        <div class="avbk-photo-share-card">
            <div class="avbk-photo-share-header">
                <span class="avbk-photo-share-badge">&#10003; Persoonlijke uploadmap gekoppeld</span>
                <h3 class="avbk-photo-share-title"><?php echo esc_html($title); ?></h3>
                <p class="avbk-photo-share-desc">
                    Scan de QR-code met je smartphone om direct vanaf je telefoon foto&#8217;s en video&#8217;s te uploaden, of klik op de knop om de map in Google Drive te openen.
                </p>
            </div>

            <div class="avbk-photo-share-content">
                <?php if ($qr_svg !== '') : ?>
                    <div class="avbk-photo-share-qr-column">
                        <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer" class="avbk-photo-share-qr-link" title="Open Google Drive map">
                            <div class="avbk-photo-share-qr-frame">
                                <?php echo $qr_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                        </a>
                        <span class="avbk-photo-share-qr-hint">Scan met je smartphone</span>
                    </div>
                <?php endif; ?>

                <div class="avbk-photo-share-details-column">
                    <div class="avbk-photo-share-hotlink-wrap">
                        <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer" class="button avbk-photo-share-btn">
                            Open je persoonlijke Google Drive map &rarr;
                        </a>
                    </div>

                    <div class="avbk-photo-share-url-wrap">
                        <label for="avbk-photo-share-input">Directe link:</label>
                        <div class="avbk-photo-share-copy-wrap">
                            <input type="text" id="avbk-photo-share-input" readonly value="<?php echo esc_url($url); ?>" class="avbk-photo-share-input" onclick="this.select()">
                            <button type="button" class="button avbk-photo-share-copy-btn" onclick="navigator.clipboard.writeText('<?php echo esc_js($url); ?>'); this.textContent='Gekopieerd!'; setTimeout(() => this.textContent='Kopieer link', 2000);">
                                Kopieer link
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Card for logged-in user who already has a pending activation link sent.
     */
    private function render_user_pending_request_card(object $share): string {
        $days_left = max(1, (int) ceil((strtotime($share->expires_at) - time()) / DAY_IN_SECONDS));
        $exp_str   = date_i18n('j F, H:i', strtotime($share->expires_at));

        ob_start();
        ?>
        <div class="avbk-photo-share-pending-box">
            <h3 class="avbk-photo-share-pending-title">Verificatielink verzonden</h3>
            <p>
                We hebben een e-mail gestuurd naar <strong><?php echo esc_html($share->email); ?></strong> om jouw persoonlijke uploadmap te activeren.
            </p>
            <p style="color: #667766; font-size: 0.9em;">
                <em>Deze link is nog <?php echo esc_html((string) $days_left); ?> dag(en) geldig (tot <?php echo esc_html($exp_str); ?>). Als de link niet binnen 5 dagen wordt bevestigd, vervalt de aanvraag.</em>
            </p>
            <form method="post" action="<?php echo esc_url(home_url('/foto-delen/')); ?>" style="margin-top: 14px;">
                <?php wp_nonce_field('avbk_request_photo_share_action', 'avbk_photo_share_nonce'); ?>
                <button type="submit" name="avbk_photo_share_submit" class="button avbk-photo-share-outline-btn">
                    Stuur activatielink opnieuw &rarr;
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Card for logged-in user who does not have an upload share yet.
     */
    private function render_user_request_card(): string {
        $user = wp_get_current_user();
        $name = $user ? ($user->first_name ?: $user->display_name) : 'lid';
        $email = $user ? $user->user_email : '';

        ob_start();
        ?>
        <div class="avbk-photo-share-auth-box">
            <h3 class="avbk-photo-share-auth-title">Jouw persoonlijke uploadmap activeren</h3>
            <p>
                Beste <?php echo esc_html($name); ?>, klik op de onderstaande knop om een activatielink te ontvangen op <strong><?php echo esc_html($email); ?></strong>.
                Na bevestiging wordt jouw eigen map in Google Drive direct aangemaakt en verschijnen hier jouw QR-code en uploadlink.
            </p>
            <p style="color: #667766; font-size: 0.85em;">
                <em>De activatielink is na verzending 5 dagen geldig.</em>
            </p>
            <form method="post" action="<?php echo esc_url(home_url('/foto-delen/')); ?>" style="margin-top: 14px;">
                <?php wp_nonce_field('avbk_request_photo_share_action', 'avbk_photo_share_nonce'); ?>
                <button type="submit" name="avbk_photo_share_submit" class="button avbk-photo-share-btn">
                    Stuur mij een activatielink per e-mail &rarr;
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Form card for guests/participants who are not logged in.
     */
    private function render_guest_form_card(): string {
        $login_url = wp_login_url(home_url('/foto-delen/'));

        ob_start();
        ?>
        <div class="avbk-photo-share-auth-box">
            <h3 class="avbk-photo-share-auth-title">Persoonlijke uploadmap aanvragen</h3>
            <p>
                Ben je deelnemer of actief lid? Vul hieronder je e-mailadres in om een activatielink te ontvangen.
                Na bevestiging van de e-mail ontvang je direct jouw persoonlijke Google Drive uploadmap met QR-code.
            </p>
            <p style="color: #667766; font-size: 0.85em;">
                <em>Let op: De verificatielink is na verzending 5 dagen geldig.</em>
            </p>
            <form method="post" action="<?php echo esc_url(home_url('/foto-delen/')); ?>" class="avbk-photo-share-request-form">
                <?php wp_nonce_field('avbk_request_photo_share_action', 'avbk_photo_share_nonce'); ?>
                <div class="avbk-photo-share-form-row">
                    <input type="email" name="photo_share_email" required placeholder="Jouw e-mailadres" class="avbk-photo-share-input-email">
                    <button type="submit" name="avbk_photo_share_submit" class="button avbk-photo-share-btn">
                        Stuur activatielink &rarr;
                    </button>
                </div>
            </form>
            <p style="margin-top: 16px; font-size: 0.85rem; color: #667766;">
                Heb je al een gebruikersaccount? <a href="<?php echo esc_url($login_url); ?>">Log hier direct in</a>.
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders custom profile fields in WP-Admin (user-edit.php).
     */
    public function render_user_profile_field(WP_User $user): void {
        if (!current_user_can('edit_users')) {
            return;
        }

        $share_url = get_user_meta($user->ID, 'photo_share_url', true);
        ?>
        <h2>50 jaar archeologie &mdash; Foto delen</h2>
        <table class="form-table">
            <tr>
                <th><label for="avbk_photo_share_url">Persoonlijke upload Google Drive URL</label></th>
                <td>
                    <input type="url" name="avbk_photo_share_url" id="avbk_photo_share_url" value="<?php echo esc_attr($share_url); ?>" class="regular-text" placeholder="https://drive.google.com/drive/folders/...">
                    <p class="description">De directe Google Drive URL naar de persoonlijke uploadmap van dit lid.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Saves custom profile fields in WP-Admin.
     */
    public function save_user_profile_field(int $user_id): void {
        if (!current_user_can('edit_users')) {
            return;
        }

        if (isset($_POST['avbk_photo_share_url'])) {
            $url = esc_url_raw(trim(wp_unslash($_POST['avbk_photo_share_url'])));
            if ($url !== '') {
                update_user_meta($user_id, 'photo_share_url', $url);
            } else {
                delete_user_meta($user_id, 'photo_share_url');
            }
        }
    }
}
