<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-exceptions.php';

/**
 * Invoices uploaded in bulk, waiting to be linked to a bank transaction.
 *
 * Each uploaded invoice is stored as an unlinked company document with
 * ocr_status 'pending'. A background job reads it with Google Document AI
 * (AVBK_OCR), fills the invoice date, total and supplier where they are
 * still empty, and looks for the outgoing bank transaction that paid it.
 * A transaction is only suggested when exactly one matches; linking is
 * always confirmed by a person (AVBK_Company_Documents::link()).
 */
class AVBK_Company_Invoice_Inbox {
    public const MAX_FILES = 50;
    public const MAX_TOTAL_BYTES = 100 * 1024 * 1024;
    public const CRON_HOOK = 'avbk_company_invoice_ocr';
    private const PER_RUN = 10;
    /** A payment can come shortly before the invoice date (prepaid) up to months after it. */
    private const DAYS_BEFORE = 14;
    private const DAYS_AFTER = 120;

    /**
     * The limits that really apply: ours, but never more than the server
     * accepts (max_file_uploads, post_max_size), since PHP silently drops
     * files over max_file_uploads.
     *
     * @return array{files: int, bytes: int}
     */
    public static function limits(): array {
        $files = (int) ini_get('max_file_uploads');
        $post = wp_convert_hr_to_bytes((string) ini_get('post_max_size'));
        return [
            'files' => $files > 0 ? min(self::MAX_FILES, $files) : self::MAX_FILES,
            'bytes' => $post > 0 ? min(self::MAX_TOTAL_BYTES, $post - 1024 * 1024) : self::MAX_TOTAL_BYTES,
        ];
    }

    /** Marks a stored invoice for OCR and makes sure the background job runs. */
    public static function queue(int $company_id, int $document_id): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'avb_company_documents',
            ['ocr_status' => 'pending'],
            ['id' => $document_id, 'company_id' => $company_id, 'transaction_id' => null]
        );
        self::schedule();
    }

    public static function schedule(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time(), self::CRON_HOOK);
        }
    }

    /** Background job: OCR a few pending invoices, then reschedule while more are waiting. */
    public static function process(): void {
        global $wpdb;
        $documents = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avb_company_documents WHERE ocr_status = 'pending' ORDER BY id LIMIT %d",
            self::PER_RUN
        )) ?: [];
        foreach ($documents as $document) {
            self::process_document($document);
        }
        if (count($documents) === self::PER_RUN) {
            wp_schedule_single_event(time() + 30, self::CRON_HOOK);
        }
    }

    /** OCR one invoice; empty fields are filled, never overwritten. */
    public static function process_document(object $document): void {
        global $wpdb;
        $table = $wpdb->prefix . 'avb_company_documents';
        $text = self::document_text($document);
        if ($text === null) {
            $wpdb->update($table, ['ocr_status' => class_exists('AVBK_OCR') && AVBK_OCR::available() ? 'failed' : 'unavailable'], ['id' => (int) $document->id]);
            return;
        }
        $found = self::read_invoice($text);
        $update = ['ocr_status' => 'done'];
        if (empty($document->invoice_date) && $found['date'] !== null) {
            $update['invoice_date'] = $found['date'];
        }
        if ($document->amount_total === null && $found['amount'] !== null) {
            $update['amount_total'] = $found['amount'];
        }
        if ((string) $document->supplier === '' && $found['supplier'] !== null) {
            $update['supplier'] = $found['supplier'];
        }
        $wpdb->update($table, $update, ['id' => (int) $document->id]);
        self::suggest((int) $document->company_id, (int) $document->id);
    }

    /** The OCR text of a stored document, or null when there is no file or OCR is not possible. */
    private static function document_text(object $document): ?string {
        $bytes = class_exists('AVBK_OCR') && !empty($document->document_data) ? base64_decode((string) $document->document_data, true) : false;
        $extension = strtolower(pathinfo((string) $document->filename, PATHINFO_EXTENSION)) ?: 'pdf';
        $file = $bytes === false ? false : wp_tempnam('avbk-invoice.' . $extension);
        if (!$file) {
            return null;
        }
        $text = null;
        try {
            if (file_put_contents($file, $bytes) === strlen($bytes)) {
                $text = AVBK_OCR::extract_text($file);
            }
        } finally {
            wp_delete_file($file);
        }
        return is_string($text) && trim($text) !== '' ? $text : null;
    }

    /**
     * Invoice date, total (incl. VAT) and supplier from OCR text. Every
     * value is a suggestion and may be null.
     *
     * @return array{date: ?string, amount: ?float, supplier: ?string}
     */
    public static function read_invoice(string $text): array {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []), static fn($line) => $line !== ''));
        return [
            'date'     => self::invoice_date($lines),
            'amount'   => self::invoice_total($lines),
            'supplier' => self::supplier($lines),
        ];
    }

    /** The date on a "factuurdatum"/"datum"/"invoice date" line (or the line after it), else the first date anywhere. */
    private static function invoice_date(array $lines): ?string {
        foreach ($lines as $i => $line) {
            if (preg_match('/factuurdatum|invoice\s*date|datum|date/i', $line) && !preg_match('/verval|due|lever|deliver|periode|period/i', $line)) {
                $date = self::first_date($line . ' ' . ($lines[$i + 1] ?? ''));
                if ($date !== null) {
                    return $date;
                }
            }
        }
        return self::first_date(implode("\n", $lines));
    }

    private const MONTHS = [
        'jan' => 1, 'januari' => 1, 'january' => 1, 'feb' => 2, 'februari' => 2, 'february' => 2, 'mrt' => 3, 'maart' => 3, 'mar' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'juni' => 6, 'june' => 6, 'jul' => 7, 'juli' => 7, 'july' => 7,
        'aug' => 8, 'augustus' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9, 'okt' => 10, 'oct' => 10, 'oktober' => 10, 'october' => 10,
        'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12,
    ];

    /** The first real date in dd-mm-yyyy, yyyy-mm-dd or "12 maart 2025" form. */
    public static function first_date(string $text): ?string {
        $candidates = [];
        if (preg_match_all('/(?<!\d)(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})(?!\d)/', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $match) {
                $candidates[$match[0][1]] = [(int) $match[3][0], (int) $match[2][0], (int) $match[1][0]];
            }
        }
        if (preg_match_all('/(?<!\d)(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $match) {
                $candidates[$match[0][1]] = [(int) $match[1][0], (int) $match[2][0], (int) $match[3][0]];
            }
        }
        if (preg_match_all('/(?<!\d)(\d{1,2})\s+([a-z]{3,9})\.?\s+(\d{4})(?!\d)/i', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $match) {
                $month = self::MONTHS[strtolower($match[2][0])] ?? 0;
                $candidates[$match[0][1]] = [(int) $match[3][0], $month, (int) $match[1][0]];
            }
        }
        ksort($candidates);
        foreach ($candidates as [$year, $month, $day]) {
            if ($year >= 2000 && $year <= 2100 && checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        return null;
    }

    /**
     * The amount to pay: on a "te betalen"/"incl"/"amount due" line, else
     * the largest amount on a "totaal"/"total" line. Null without such a
     * line: guessing from all numbers on an invoice is too unreliable.
     */
    private static function invoice_total(array $lines): ?float {
        $preferred = [];
        $totals = [];
        foreach ($lines as $i => $line) {
            if (!preg_match('/totaal|total|te betalen|amount due|to pay|verschuldigd/i', $line)) {
                continue;
            }
            $amounts = self::amounts($line);
            if (!$amounts) {
                $amounts = self::amounts($lines[$i + 1] ?? '');
            }
            $totals = array_merge($totals, $amounts);
            if (preg_match('/te betalen|incl|amount due|to pay|verschuldigd/i', $line)) {
                $preferred = array_merge($preferred, $amounts);
            }
        }
        if ($preferred) {
            return max($preferred);
        }
        return $totals ? max($totals) : null;
    }

    /** Euro amounts in a line: "1.234,56", "1234,56", "1,234.56" or "1234.56". */
    public static function amounts(string $line): array {
        $found = [];
        if (preg_match_all('/(?<![\d.,])(\d{1,3}(?:[.\s]\d{3})+|\d+),(\d{2})(?![\d])/', $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $found[] = (float) (preg_replace('/[.\s]/', '', $match[1]) . '.' . $match[2]);
            }
        }
        if (preg_match_all('/(?<![\d.,])(\d{1,3}(?:,\d{3})+|\d+)\.(\d{2})(?![\d])/', $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $found[] = (float) (str_replace(',', '', $match[1]) . '.' . $match[2]);
            }
        }
        return array_values(array_filter($found, static fn($amount) => $amount > 0 && $amount < 1000000000));
    }

    /** The first line with real text, usually the supplier's name at the top of the invoice. */
    private static function supplier(array $lines): ?string {
        foreach (array_slice($lines, 0, 8) as $line) {
            if (preg_match('/\p{L}{3,}/u', $line) && !preg_match('/^(factuur|invoice|rekening|nota)\b/i', $line)) {
                return mb_substr($line, 0, 190);
            }
        }
        return null;
    }

    /**
     * Outgoing bank transactions that could have paid this invoice: same
     * amount, paid from DAYS_BEFORE before to DAYS_AFTER after the invoice
     * date, and not linked to another invoice yet. Closest date first.
     */
    public static function candidates(int $company_id, object $document, int $limit = 5): array {
        global $wpdb;
        if (empty($document->invoice_date) || $document->amount_total === null) {
            return [];
        }
        $date = (string) $document->invoice_date;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.transaction_date, t.amount, t.counterparty_name, t.description
             FROM {$wpdb->prefix}avb_company_transactions t
             WHERE t.company_id = %d AND t.direction = 'out' AND ABS(t.amount - %f) < 0.005
               AND t.transaction_date BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY)
               AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}avb_company_documents d WHERE d.company_id = t.company_id AND d.transaction_id = t.id)
             ORDER BY ABS(DATEDIFF(t.transaction_date, %s)), t.id
             LIMIT %d",
            $company_id, (float) $document->amount_total, $date, self::DAYS_BEFORE, $date, self::DAYS_AFTER, $date, $limit
        )) ?: [];
    }

    /** Stores a suggestion only when exactly one transaction matches. */
    public static function suggest(int $company_id, int $document_id): ?int {
        global $wpdb;
        $document = AVBK_Company_Documents::document($company_id, $document_id);
        $candidates = self::candidates($company_id, $document, 2);
        $suggested = count($candidates) === 1 ? (int) $candidates[0]->id : null;
        $wpdb->update($wpdb->prefix . 'avb_company_documents', ['suggested_transaction_id' => $suggested], ['id' => $document_id, 'company_id' => $company_id]);
        return $suggested;
    }

    /** Unlinked invoices, oldest invoice date first, with their suggestion and other candidates. */
    public static function inbox(int $company_id): array {
        global $wpdb;
        AVBK_Companies::company($company_id, 'import');
        $documents = $wpdb->get_results($wpdb->prepare(
            "SELECT id, company_id, filename, source_reference, supplier, invoice_date, due_date, amount_total, ocr_status, suggested_transaction_id, added_at
             FROM {$wpdb->prefix}avb_company_documents
             WHERE company_id = %d AND document_kind = 'invoice' AND transaction_id IS NULL
             ORDER BY invoice_date IS NULL, invoice_date, id LIMIT 500",
            $company_id
        )) ?: [];
        foreach ($documents as $document) {
            $document->candidates = self::candidates($company_id, $document);
        }
        return $documents;
    }

    /** "2025 Q3" for an invoice date, '' without one. */
    public static function quarter_label(?string $date): string {
        if (!$date || !preg_match('/^(\d{4})-(\d{2})/', $date, $m)) {
            return '';
        }
        return $m[1] . ' Q' . (int) ceil((int) $m[2] / 3);
    }

    /** Corrected date, amount and supplier from the inbox; then look for a match again. */
    public static function update_details(int $company_id, int $document_id, string $invoice_date, string $amount, string $supplier): void {
        global $wpdb;
        AVBK_Companies::company($company_id, 'import');
        $document = AVBK_Company_Documents::document($company_id, $document_id);
        if ($document->transaction_id !== null) {
            throw new AVBK_Company_Exception('Deze factuur is al gekoppeld.');
        }
        $invoice_date = trim($invoice_date);
        if ($invoice_date !== '' && self::first_date($invoice_date) !== $invoice_date) {
            throw new AVBK_Company_Exception('Vul een geldige factuurdatum in.');
        }
        $amount = trim($amount);
        if ($amount !== '' && !preg_match('/^\d{1,12}(?:[.,]\d{1,2})?$/D', $amount)) {
            throw new AVBK_Company_Exception('Vul een positief bedrag in met maximaal twee decimalen.');
        }
        $wpdb->update($wpdb->prefix . 'avb_company_documents', [
            'invoice_date' => $invoice_date !== '' ? $invoice_date : null,
            'amount_total' => $amount !== '' ? round((float) str_replace(',', '.', $amount), 2) : null,
            'supplier'     => mb_substr(trim($supplier), 0, 190),
        ], ['id' => $document_id, 'company_id' => $company_id]);
        self::suggest($company_id, $document_id);
    }

    /** Link the invoice to a transaction the person picked (the suggestion or another candidate). */
    public static function link(int $company_id, int $document_id, int $transaction_id): void {
        global $wpdb;
        AVBK_Company_Documents::link($company_id, $document_id, $transaction_id);
        $wpdb->update($wpdb->prefix . 'avb_company_documents', ['suggested_transaction_id' => null], ['id' => $document_id, 'company_id' => $company_id]);
    }

    /** Run OCR again for one invoice, e.g. after OCR was unavailable. */
    public static function retry(int $company_id, int $document_id): void {
        AVBK_Companies::company($company_id, 'import');
        $document = AVBK_Company_Documents::document($company_id, $document_id);
        if ($document->transaction_id !== null) {
            throw new AVBK_Company_Exception('Deze factuur is al gekoppeld.');
        }
        self::queue($company_id, $document_id);
    }
}
