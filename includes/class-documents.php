<?php
defined('ABSPATH') || exit;

/**
 * Documents (receipts, invoices) kept in the protected receipts directory:
 * taking one from an upload, storing, copying, deleting and showing it.
 * Uploads are validated like a declaratie's receipt
 * (AVBK_Reimbursements::validate_receipt_file()).
 */
class AVBK_Documents {

    /**
     * The document uploaded in form field $field.
     *
     * @return array{tmp?: string, ext?: string, name?: string, error?: string} Empty when nothing was uploaded.
     */
    public static function from_upload(string $field): array {
        $file = $_FILES[$field] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- validated below; every caller checked its nonce.
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        $result = [];
        if ($error !== UPLOAD_ERR_NO_FILE) {
            $result = ['error' => 'document_invalid'];
            try {
                $tmp = $error === UPLOAD_ERR_OK ? avbk_uploaded_file_path((string) $file['tmp_name']) : '';
                $name = sanitize_file_name((string) $file['name']);
                $ext = $tmp !== '' ? AVBK_Reimbursements::validate_receipt_file($tmp, $name) : null;
                if ($ext !== null) {
                    $result = ['tmp' => $tmp, 'ext' => $ext, 'name' => $name];
                }
            } catch (AVBK_Upload_Exception $e) {
                // Not this request's upload: stays document_invalid.
            }
        }
        return $result;
    }

    /** Moves a validated upload into the receipts directory; returns its stored name. */
    public static function store(string $tmp, string $ext, string $prefix): string {
        AVBK_Reimbursements::ensure_receipts_dir_protected();
        $name = $prefix . '-' . wp_generate_password(32, false, false) . '.' . $ext;
        // WP_Filesystem->move(), not move_uploaded_file() (Plugin Check);
        // from_upload() already checked this is this request's upload.
        self::filesystem()->move($tmp, self::path($name));
        return $name;
    }

    /** A copy of a stored document under a new name, or '' when it is gone. */
    public static function copy(string $stored, string $prefix): string {
        $source = self::path($stored);
        if ($stored === '' || !file_exists($source)) {
            return '';
        }
        $name = $prefix . '-' . wp_generate_password(32, false, false) . '.' . strtolower(pathinfo($source, PATHINFO_EXTENSION));
        return self::filesystem()->copy($source, self::path($name)) ? $name : '';
    }

    public static function delete(string $stored): void {
        if ($stored !== '') {
            self::filesystem()->delete(self::path($stored));
        }
    }

    /** Sends a stored document to the browser (an image inline, a pdf as download) and stops. */
    public static function serve(string $stored, string $download_name): void {
        $path = $stored !== '' ? self::path($stored) : '';
        if ($path === '' || !file_exists($path)) {
            wp_die('Bestand niet gevonden.', 'Fout', ['response' => 404]);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif', 'pdf' => 'application/pdf'];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: ' . ($ext === 'pdf' ? 'attachment' : 'inline') . '; filename="' . sanitize_file_name($download_name) . '.' . $ext . '"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'");
        echo self::filesystem()->get_contents($path); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw image/pdf bytes, not HTML.
        exit;
    }

    private static function path(string $stored): string {
        return AVBK_Reimbursements::receipts_dir() . '/' . basename($stored);
    }

    /** @return \WP_Filesystem_Base */
    private static function filesystem() {
        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        return $wp_filesystem;
    }
}
