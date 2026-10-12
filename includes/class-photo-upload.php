<?php
defined('ABSPATH') || exit;

/** Photo uploads go directly to the signed-in user's confirmed Drive share. */
class AVBK_Photo_Upload {
    public const MAX_BYTES = 25 * 1024 * 1024;
    public const TYPES = [
        'image/jpeg' => ['jpg', 'jpeg', 'jpe'], 'image/png' => ['png'],
        'image/gif' => ['gif'], 'image/webp' => ['webp'], 'image/avif' => ['avif'],
        'image/heic' => ['heic'], 'image/heif' => ['heif'],
        'image/tiff' => ['tif', 'tiff'], 'image/bmp' => ['bmp'],
    ];

    public function __construct() {
        add_action('wp_ajax_avbk_photo_upload', [$this, 'upload']);
        add_action('wp_ajax_nopriv_avbk_photo_upload', [$this, 'upload']);
    }

    public static function limit(): int {
        return min(self::MAX_BYTES, (int) wp_max_upload_size());
    }

    public static function render(): string {
        wp_enqueue_script('avbk-photo-upload', AVBK_PLUGIN_URL . 'assets/photo-share.js', [], avbk_asset_version('assets/photo-share.js'), true);
        $extensions = array_keys(self::TYPES);
        foreach (self::TYPES as $type) {
            foreach ($type as $extension) {
                $extensions[] = '.' . $extension;
            }
        }
        ob_start();
        ?>
        <div class="avbk-photo-upload" data-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('avbk_photo_upload')); ?>" data-limit="<?php echo esc_attr((string) self::limit()); ?>">
            <form class="avbk-photo-upload-form">
                <label>Foto’s van je toestel
                    <input type="file" name="photo" accept="<?php echo esc_attr(implode(',', $extensions)); ?>" multiple required>
                </label>
                <p class="avbk-photo-upload-help">JPG, PNG, GIF, WebP, HEIC, HEIF, AVIF, TIFF of BMP. Maximaal <?php echo esc_html(size_format(self::limit())); ?> per foto.</p>
                <button type="submit" class="avbk-photo-share-btn">Foto’s uploaden</button>
            </form>
            <?php if (AVBK_Google_Photos_Picker::configured()) : ?>
                <button type="button" class="avbk-photo-share-outline-btn avbk-google-photos-start">Kies uit Google Photos</button>
                <p class="avbk-photo-upload-help">Kies alleen foto’s. Google vraagt je toestemming. Na het kiezen keer je terug naar deze pagina; je foto’s worden naar je persoonlijke map gekopieerd.</p>
                <a class="avbk-google-photos-open" target="_blank" rel="noopener noreferrer" hidden>Open Google Photos</a>
                <button type="button" class="avbk-photo-share-outline-btn avbk-google-photos-resume" hidden>Controleer selectie</button>
                <button type="button" class="avbk-google-photos-cancel" hidden>Selectie annuleren</button>
            <?php endif; ?>
            <p class="avbk-photo-upload-status" role="status" aria-live="polite"></p>
            <ul class="avbk-photo-upload-results"></ul>
            <noscript>Schakel JavaScript in om hier foto’s te uploaden, of gebruik de link naar je persoonlijke uploadmap.</noscript>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Resolve ownership on the server, never from a submitted folder or token. */
    public static function own_share(): object {
        $user_id = get_current_user_id();
        $share = $user_id ? AVBK_DB::get_photo_share_by_user_id($user_id) : null;
        if (!$share || $share->status !== 'confirmed' || empty($share->drive_folder_id)
            || str_starts_with($share->drive_folder_id, 'share-')) {
            throw new RuntimeException('Log in met het account van je geactiveerde persoonlijke uploadmap.');
        }
        return $share;
    }

    public static function authorize(): object {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Log opnieuw in om foto’s te uploaden.'], 401);
        }
        if (!check_ajax_referer('avbk_photo_upload', 'nonce', false)) {
            wp_send_json_error(['message' => 'Ververs de pagina en probeer opnieuw.'], 403);
        }
        try {
            return self::own_share();
        } catch (RuntimeException $e) {
            wp_send_json_error(['message' => $e->getMessage()], 403);
        }
    }

    /** Check real bytes as well as the filename; SVG, documents and videos are excluded. */
    public static function validate(string $path, string $name, int $limit = self::MAX_BYTES): array {
        $size = is_file($path) ? filesize($path) : 0;
        if (!$size || $size > $limit) {
            throw new RuntimeException('Deze foto is leeg of groter dan de toegestane bestandsgrootte.');
        }
        $mime = wp_get_image_mime($path);
        if ($mime === 'image/x-ms-bmp') {
            $mime = 'image/bmp';
        }
        $name = sanitize_file_name($name);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$mime]) || !in_array($extension, self::TYPES[$mime], true)) {
            throw new RuntimeException('Kies een foto in JPG, PNG, GIF, WebP, HEIC, HEIF, AVIF, TIFF of BMP.');
        }
        return ['name' => $name, 'mime' => $mime];
    }

    public static function store(string $path, string $name, int $limit = self::MAX_BYTES, string $source_key = ''): string {
        $share = self::own_share();
        $photo = self::validate($path, $name, $limit);
        if (!class_exists('\\Avpvh\\Frontend\\Share_Drive') || !\Avpvh\Frontend\Share_Drive::has_account()) {
            throw new RuntimeException('De uploadmap is tijdelijk niet bereikbaar. Probeer later opnieuw.');
        }
        try {
            $drive = \Avpvh\Frontend\Share_Drive::drive();
            $folder = $drive->files->get($share->drive_folder_id, ['fields' => 'mimeType,parents,trashed', 'supportsAllDrives' => true]);
            if ($folder->getTrashed() || $folder->getMimeType() !== 'application/vnd.google-apps.folder'
                || !in_array(AVBK_Photo_Share::ROOT_FOLDER_ID, (array) $folder->getParents(), true)) {
                throw new RuntimeException('Invalid upload folder');
            }
            // Repeated Picker imports after a lost connection reuse the already copied file.
            if ($source_key !== '') {
                $existing = $drive->files->listFiles([
                    'q' => "'" . str_replace("'", "\\'", $share->drive_folder_id) . "' in parents and trashed = false and appProperties has { key='avbk_photos_source' and value='" . hash('sha256', $source_key) . "' }",
                    'fields' => 'files(id)', 'pageSize' => 1, 'supportsAllDrives' => true, 'includeItemsFromAllDrives' => true,
                ])->getFiles();
                if ($existing) {
                    return (string) $existing[0]->getId();
                }
            }
            $metadata = ['name' => $photo['name'], 'parents' => [$share->drive_folder_id]];
            if ($source_key !== '') {
                $metadata['appProperties'] = ['avbk_photos_source' => hash('sha256', $source_key)];
            }
            $file = $drive->files->create(new \Avpvh\Vendor\Google\Service\Drive\DriveFile($metadata), [
                'data' => file_get_contents($path), 'mimeType' => $photo['mime'],
                'uploadType' => 'multipart', 'fields' => 'id', 'supportsAllDrives' => true,
            ]);
            return (string) $file->getId();
        } catch (Throwable $e) {
            throw new RuntimeException('Het opslaan in je uploadmap is niet gelukt. Probeer opnieuw.');
        }
    }

    public function upload(): void {
        self::authorize();
        $photo = $_FILES['photo'] ?? [];
        if (!is_array($photo) || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($photo['tmp_name'] ?? null) || !is_uploaded_file($photo['tmp_name']) || !is_string($photo['name'] ?? null)) {
            wp_send_json_error(['message' => 'Kies een foto binnen de aangegeven bestandsgrootte.'], 400);
        }
        try {
            self::store($photo['tmp_name'], $photo['name'], self::limit());
            wp_send_json_success(['message' => 'Foto opgeslagen in je persoonlijke uploadmap.']);
        } catch (RuntimeException $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }
}
