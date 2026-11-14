<?php
defined('ABSPATH') || exit;

/** Private invoice evidence, stored with its company rather than in public media uploads. */
class AVBK_Company_Documents {
    public const MAX_BYTES = 3 * 1024 * 1024;
    private const TYPES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public static function add(int $company_id, int $transaction_id, string $reference, string $uploaded_path = '', string $filename = '', bool $allow_existing = false, array $classification = [], array $financials = []): int {
        global $wpdb;
        AVBK_Companies::company($company_id, 'import');
        [$kind, $period] = self::classification($company_id, $classification);
        if ($kind !== 'invoice' && $uploaded_path === '') {
            throw new \RuntimeException('Upload een loonstrookje of belastingopgave.');
        }
        $reference = trim($reference);
        if (mb_strlen($reference) > 2000) {
            throw new \RuntimeException('De factuurverwijzing mag maximaal 2000 tekens bevatten.');
        }
        if ($transaction_id) {
            $found = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}avb_company_transactions WHERE company_id = %d AND id = %d", $company_id, $transaction_id));
            if (!$found) {
                throw new \RuntimeException('De banktransactie hoort niet bij deze administratie.');
            }
        }
        [$invoice_date, $due_date, $amount_total] = self::parse_financials($financials);
        $data = null; $mime = '';
        if ($uploaded_path !== '') {
            if (!is_uploaded_file($uploaded_path) || !filesize($uploaded_path) || filesize($uploaded_path) > self::MAX_BYTES) {
                throw new \RuntimeException('Kies een factuurbestand van maximaal 3 MB.');
            }
            $filename = sanitize_file_name($filename);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($uploaded_path);
            if (!isset(self::TYPES[$ext]) || self::TYPES[$ext] !== $mime) {
                throw new \RuntimeException('Kies een geldige PDF-, JPG-, PNG- of WebP-factuur.');
            }
            $bytes = file_get_contents($uploaded_path);
            if ($bytes === false) {
                throw new \RuntimeException('Kan het factuurbestand niet lezen.');
            }
            $hash = hash('sha256', $bytes); $data = base64_encode($bytes);
        } else {
            if ($reference === '') {
                throw new \RuntimeException('Upload een factuur of vul een vindplaats in.');
            }
            $filename = ''; $hash = hash('sha256', 'reference:' . $reference);
        }
        return self::persist($company_id, $transaction_id, $reference, $filename, $mime, $data, $hash, $allow_existing, $kind, $period, $invoice_date, $due_date, $amount_total);
    }

    /**
     * Optional "Factuurdatum" / "Vervaldatum" / "Totaalbedrag" fields, kept
     * separate from the required upload/reference pair above: an invoice
     * can be recorded as evidence before its payment status is known, and
     * these are only meaningful for document_kind 'invoice'.
     *
     * @return array{0: ?string, 1: ?string, 2: ?float}
     */
    private static function parse_financials(array $financials): array {
        $invoice_date = trim((string) ($financials['invoice_date'] ?? ''));
        $due_date     = trim((string) ($financials['due_date'] ?? ''));
        $amount_total = trim((string) ($financials['amount_total'] ?? ''));
        foreach (['invoice_date' => $invoice_date, 'due_date' => $due_date] as $label => $value) {
            if ($value === '') {
                continue;
            }
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new \RuntimeException($label === 'invoice_date' ? 'Vul een geldige factuurdatum in.' : 'Vul een geldige vervaldatum in.');
            }
        }
        if ($invoice_date !== '' && $due_date !== '' && $due_date < $invoice_date) {
            throw new \RuntimeException('De vervaldatum mag niet vóór de factuurdatum liggen.');
        }
        $amount = null;
        if ($amount_total !== '') {
            if (!preg_match('/^\d{1,12}(?:[.,]\d{1,2})?$/D', $amount_total)) {
                throw new \RuntimeException('Vul een positief factuurbedrag in met maximaal twee decimalen.');
            }
            $amount = round((float) str_replace(',', '.', $amount_total), 2);
        }
        return [$invoice_date !== '' ? $invoice_date : null, $due_date !== '' ? $due_date : null, $amount];
    }

    /** The host's read-only mailbox collector supplies attachment bytes through WP-CLI. */
    public static function import_mail(int $company_id, string $reference, string $filename, string $encoded, array $classification = []): int {
        if (!defined('WP_CLI') || !WP_CLI) {
            throw new \RuntimeException('E-mailbijlagen kunnen alleen via de mailboxcollector worden geïmporteerd.');
        }
        AVBK_Companies::company($company_id, 'import');
        [$kind, $period] = self::classification($company_id, $classification);
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 2000 || strlen($encoded) > 4 * self::MAX_BYTES / 3 + 8) {
            throw new \RuntimeException('Ongeldige e-mailbijlage of verwijzing.');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new \RuntimeException('Kies een e-mailbijlage van maximaal 3 MB.');
        }
        $filename = sanitize_file_name($filename);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!isset(self::TYPES[$ext]) || self::TYPES[$ext] !== $mime) {
            throw new \RuntimeException('De e-mailbijlage is geen geldige PDF of afbeelding.');
        }
        return self::persist($company_id, 0, $reference, $filename, $mime, base64_encode($bytes), hash('sha256', $bytes), true, $kind, $period);
    }

    private static function classification(int $company_id, array $classification): array {
        if (!$classification) {
            return ['invoice', null];
        }
        $kind = $classification['kind'] ?? '';
        if (!AVBK_Company_Payroll::enabled($company_id) || !isset(AVBK_Company_Payroll::KINDS[$kind])) {
            throw new \RuntimeException('Kies een geldig loondocument voor een administratie met loonadministratie.');
        }
        return [$kind, AVBK_Company_Payroll::period((string) ($classification['period'] ?? ''))];
    }

    private static function persist(int $company_id, int $transaction_id, string $reference, string $filename, string $mime, ?string $data, string $hash, bool $allow_existing, string $kind, ?string $period, ?string $invoice_date = null, ?string $due_date = null, ?float $amount_total = null): int {
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}avb_company_documents WHERE company_id = %d AND content_hash = %s", $company_id, $hash));
        if ($existing) {
            if ($allow_existing) {
                $document = self::document($company_id, (int) $existing);
                if (($document->document_kind ?? 'invoice') !== $kind || ($document->document_period ?? null) !== $period) {
                    throw new \RuntimeException('Dit bestand staat al bij een andere documentsoort of maand in deze administratie.');
                }
                return (int) $existing;
            }
            throw new \RuntimeException('Deze factuur of verwijzing staat al bij deze administratie.');
        }
        if (!$wpdb->insert($wpdb->prefix . 'avb_company_documents', [
            'company_id' => $company_id, 'transaction_id' => $transaction_id ?: null, 'source_reference' => $reference,
            'document_kind' => $kind, 'document_period' => $period,
            'filename' => $filename, 'mime_type' => $mime, 'document_data' => $data, 'content_hash' => $hash,
            'invoice_date' => $invoice_date, 'due_date' => $due_date, 'amount_total' => $amount_total,
            'added_by' => get_current_user_id(),
        ])) {
            throw new \RuntimeException('De factuur is niet opgeslagen.');
        }
        return (int) $wpdb->insert_id;
    }

    public static function listing(int $company_id): array {
        global $wpdb;
        AVBK_Companies::company($company_id);
        return $wpdb->get_results($wpdb->prepare("SELECT id, transaction_id, source_reference, filename, added_at, invoice_date, due_date, amount_total, paid_at FROM {$wpdb->prefix}avb_company_documents WHERE company_id = %d AND document_kind = 'invoice' ORDER BY id DESC LIMIT 200", $company_id));
    }

    /** Unpaid invoices that have a due date, oldest due date first; overdue-ness is left for the caller to judge against today's date. */
    public static function unpaid(int $company_id): array {
        global $wpdb;
        AVBK_Companies::company($company_id);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, source_reference, filename, invoice_date, due_date, amount_total FROM {$wpdb->prefix}avb_company_documents WHERE company_id = %d AND document_kind = 'invoice' AND due_date IS NOT NULL AND paid_at IS NULL", $company_id));
        usort($rows, fn($a, $b) => ($a->due_date ?? '') <=> ($b->due_date ?? ''));
        return $rows;
    }

    /** Linked invoices for every bank transaction of this company, keyed by transaction_id, for display next to the matching review row. */
    public static function linked_by_transaction(int $company_id): array {
        global $wpdb;
        AVBK_Companies::company($company_id);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, transaction_id, source_reference, filename FROM {$wpdb->prefix}avb_company_documents WHERE company_id = %d AND document_kind = 'invoice' AND transaction_id IS NOT NULL", $company_id));
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->transaction_id][] = $row;
        }
        return $grouped;
    }

    /** Invoices not yet linked to a bank transaction, offered when linking one to a review row. */
    public static function unlinked(int $company_id): array {
        global $wpdb;
        AVBK_Companies::company($company_id);
        return $wpdb->get_results($wpdb->prepare("SELECT id, source_reference, filename FROM {$wpdb->prefix}avb_company_documents WHERE company_id = %d AND document_kind = 'invoice' AND transaction_id IS NULL ORDER BY id DESC LIMIT 200", $company_id));
    }

    /** Link an existing, still-unlinked invoice to a bank transaction from the review row, instead of re-uploading it. */
    public static function link(int $company_id, int $document_id, int $transaction_id): void {
        global $wpdb;
        AVBK_Companies::company($company_id, 'import');
        $document = self::document($company_id, $document_id);
        if (($document->document_kind ?? 'invoice') !== 'invoice') {
            throw new \RuntimeException('Alleen facturen kunnen aan een banktransactie worden gekoppeld.');
        }
        if ($document->transaction_id !== null) {
            throw new \RuntimeException('Deze factuur is al aan een banktransactie gekoppeld.');
        }
        $found = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}avb_company_transactions WHERE company_id = %d AND id = %d", $company_id, $transaction_id));
        if (!$found) {
            throw new \RuntimeException('De banktransactie hoort niet bij deze administratie.');
        }
        if ($wpdb->update($wpdb->prefix . 'avb_company_documents', ['transaction_id' => $transaction_id], ['id' => $document_id, 'company_id' => $company_id]) === false) {
            throw new \RuntimeException('De koppeling is niet opgeslagen.');
        }
    }

    public static function mark_paid(int $company_id, int $document_id, string $paid_date = ''): void {
        global $wpdb;
        AVBK_Companies::company($company_id, 'import');
        $document = self::document($company_id, $document_id);
        if (($document->document_kind ?? 'invoice') !== 'invoice') {
            throw new \RuntimeException('Alleen facturen kunnen als betaald worden gemarkeerd.');
        }
        $paid_at = current_time('mysql');
        if ($paid_date !== '') {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $paid_date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new \RuntimeException('Vul een geldige betaaldatum in.');
            }
            $paid_at = $paid_date . ' 00:00:00';
        }
        if ($wpdb->update($wpdb->prefix . 'avb_company_documents', ['paid_at' => $paid_at], ['id' => $document_id, 'company_id' => $company_id]) === false) {
            throw new \RuntimeException('De betaalstatus is niet opgeslagen.');
        }
    }

    public static function mark_unpaid(int $company_id, int $document_id): void {
        global $wpdb;
        AVBK_Companies::company($company_id, 'import');
        $document = self::document($company_id, $document_id);
        if (($document->document_kind ?? 'invoice') !== 'invoice') {
            throw new \RuntimeException('Alleen facturen hebben een betaalstatus.');
        }
        if ($wpdb->update($wpdb->prefix . 'avb_company_documents', ['paid_at' => null], ['id' => $document_id, 'company_id' => $company_id]) === false) {
            throw new \RuntimeException('De betaalstatus is niet opgeslagen.');
        }
    }

    public static function document(int $company_id, int $document_id): object {
        global $wpdb;
        AVBK_Companies::company($company_id);
        $document = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}avb_company_documents WHERE company_id = %d AND id = %d", $company_id, $document_id));
        if (!$document) {
            throw new \RuntimeException('Factuur niet gevonden in deze administratie.');
        }
        return $document;
    }
}
