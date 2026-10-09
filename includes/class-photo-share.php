<?php
defined('ABSPATH') || exit;

/**
 * Handles personal Google Drive photo upload shares for the 50-year anniversary.
 * Displays the member's personal share link, a high-resolution QR code (for smartphone scanning),
 * and a direct hot link to their personal Google Drive upload folder.
 */
class AVBK_Photo_Share {

    public const ROOT_FOLDER_ID = '1MfTPOBUD-Md2rK2Qdgt3y9dJ3voyzz8B';
    public const ROOT_FOLDER_URL = 'https://drive.google.com/drive/folders/1MfTPOBUD-Md2rK2Qdgt3y9dJ3voyzz8B';

    public function __construct() {
        add_shortcode('avpvh_bk_photo_share', [$this, 'render_shortcode']);
        add_shortcode('avpvh_photo_share', [$this, 'render_shortcode']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

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
     * Resolves the Google Drive photo upload share URL for a given WordPress user.
     * Checks in order:
     * 1. Explicit user_meta ('photo_share_url' or 'photo_share_folder_id')
     * 2. Search in Google Drive root folder (1MfTPOBUD-Md2rK2Qdgt3y9dJ3voyzz8B)
     * 3. Ready share from gallery Photo_Shares_DB
     *
     * @return array{url: string, title: string, source: string}|null
     */
    public static function get_user_share(int $user_id): ?array {
        if ($user_id <= 0) {
            return null;
        }

        // 1. Check user meta
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

        // 2. Check Google Drive under root folder 1MfTPOBUD-Md2rK2Qdgt3y9dJ3voyzz8B
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

        // 3. Check Gallery Photo_Shares_DB for ready shares
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
     * Matches against user email, login, display name, or member full name.
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

            // Gather candidate search tokens
            $tokens = [
                strtolower(trim($user->user_email)),
                strtolower(trim($user->user_login)),
                strtolower(trim($user->display_name)),
            ];

            if (class_exists('\AVPVH_DB')) {
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
            // Silently fall through on network or API errors
        }

        return null;
    }

    /**
     * Renders the [avpvh_bk_photo_share] shortcode.
     */
    public function render_shortcode($atts = []): string {
        wp_enqueue_style('avbk-photo-share');

        if (!is_user_logged_in()) {
            return $this->render_guest_card();
        }

        $user_id = get_current_user_id();
        $share   = self::get_user_share($user_id);

        if ($share !== null) {
            return $this->render_share_card($share['url'], $share['title']);
        }

        return $this->render_pending_card();
    }

    /**
     * Renders the card for logged-in members who have an active photo share.
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
     * Renders a friendly card when a member is logged in, but no share has been assigned yet.
     */
    private function render_pending_card(): string {
        $user = wp_get_current_user();
        $name = $user ? $user->display_name : '';

        ob_start();
        ?>
        <div class="avbk-photo-share-pending-box">
            <h3 class="avbk-photo-share-pending-title">Persoonlijke uploadmap</h3>
            <p>
                Je bent ingelogd als <strong><?php echo esc_html($name); ?></strong>, maar er is voor jouw account op dit moment nog geen persoonlijke Google Drive uploadmap klaargezet.
            </p>
            <p>
                Als deelnemer of actief lid ontvang je een e-mail met jouw persoonlijke uploadshare. Zodra deze gereed is, verschijnen hier direct automatisch jouw uploadlink en QR-code.
            </p>
            <div class="avbk-photo-share-central-wrap">
                <a href="<?php echo esc_url(self::ROOT_FOLDER_URL); ?>" target="_blank" rel="noopener noreferrer" class="button avbk-photo-share-outline-btn">
                    Naar de centrale Google Drive fotomap &rarr;
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders a notice card for guests encouraging them to log in to access their personal share.
     */
    private function render_guest_card(): string {
        $login_url = wp_login_url(home_url('/foto-delen/'));

        ob_start();
        ?>
        <div class="avbk-photo-share-auth-box">
            <h3 class="avbk-photo-share-auth-title">Jouw persoonlijke fotomap bekijken?</h3>
            <p>
                Ben je deelnemer of actief lid? Log in met je account om direct jouw persoonlijke uploadmap en QR-code te zien.
            </p>
            <div class="avbk-photo-share-auth-actions">
                <a href="<?php echo esc_url($login_url); ?>" class="button avbk-photo-share-btn">
                    Inloggen &rarr;
                </a>
            </div>
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
