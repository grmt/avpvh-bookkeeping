<?php
defined('ABSPATH') || exit;

/** Private invoice evidence, stored with its company rather than in public media uploads. */
class AVBK_Company_Documents {
    public const MAX_BYTES = 3 * 1024 * 1024;
    private const TYPES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public static function add(int $company_id, int $transaction_id, string $reference, string $uploaded_path = '', string $filename = ''): int {
        global $wpdb;
        AVBK_Companies::company($company_id, 'import');
        $reference = trim($reference);
        if (mb_strlen($reference) > 2000) throw new \RuntimeException('De factuurverwijzing mag maximaal 2000 tekens bevatten.');
        if ($transaction_id) {
            $found = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}avb_company_transactions WHERE company_id = %d AND id = %d", $company_id, $transaction_id));
            if (!$found) throw new \RuntimeException('De banktransactie hoort niet bij deze administratie.');
        }
        $data = null; $mime = '';
        if ($uploaded_path !== '') {
            if (!is_uploaded_file($uploaded_path) || !filesize($uploaded_path) || filesize($uploaded_path) > self::MAX_BYTES) throw new \RuntimeException('Kies een factuurbestand van maximaal 3 MB.');
            $filename = sanitize_file_name($filename);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($uploaded_path);
            if (!isset(self::TYPES[$ext]) || self::TYPES[$ext] !== $mime) throw new \RuntimeException('Kies een geldige PDF-, JPG-, PNG- of WebP-factuur.');
            $bytes = file_get_contents($uploaded_path);
            if ($bytes === false) throw new \RuntimeException('Kan het factuurbestand niet lezen.');
            $hash = hash('sha256', $bytes); $data = base64_encode($bytes);
        } else {
            if ($reference === '') throw new \RuntimeException('Upload een factuur of vul een vindplaats in.');
            $filename = ''; $hash = hash('sha256', 'reference:' . $reference);
        }
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}avb_company_documents WHERE company_id = %d AND content_hash = %s", $company_id, $hash))) {
            throw new \RuntimeException('Deze factuur of verwijzing staat al bij deze administratie.');
        }
        if (!$wpdb->insert($wpdb->prefix . 'avb_company_documents', [
            'company_id' => $company_id, 'transaction_id' => $transaction_id ?: null, 'source_reference' => $reference,
            'filename' => $filename, 'mime_type' => $mime, 'document_data' => $data, 'content_hash' => $hash, 'added_by' => get_current_user_id(),
        ])) throw new \RuntimeException('De factuur is niet opgeslagen.');
        return (int) $wpdb->insert_id;
    }

    public static function listing(int $company_id): array {
        global $wpdb;
        AVBK_Companies::company($company_id);
        return $wpdb->get_results($wpdb->prepare("SELECT id, transaction_id, source_reference, filename, added_at FROM {$wpdb->prefix}avb_company_documents WHERE company_id = %d ORDER BY id DESC LIMIT 200", $company_id));
    }

    public static function document(int $company_id, int $document_id): object {
        global $wpdb;
        AVBK_Companies::company($company_id);
        $document = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}avb_company_documents WHERE company_id = %d AND id = %d", $company_id, $document_id));
        if (!$document) throw new \RuntimeException('Factuur niet gevonden in deze administratie.');
        return $document;
    }
}
