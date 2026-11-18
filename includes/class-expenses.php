<?php
defined('ABSPATH') || exit;

/**
 * Uitgaven verantwoorden: every outgoing bank transaction gets an
 * explanation — an invoice with its document, a reimbursement, a payment
 * sent back, bank costs, an internal transfer — instead of being ignored at
 * import. A refund of a paid fee item stays AVBK_Refunds (member page).
 *
 * An explained transaction becomes 'matched' and goes to Tweede controle
 * like an incoming payment; removing the explanation sets it back to
 * ignored. Documents live in the protected receipts folder of
 * AVBK_Reimbursements, under a random name, and are only served through
 * handle_view_document()'s capability check.
 */
class AVBK_Expenses implements AVBK_Component {

    public const KINDS = [
        'invoice'          => 'Factuur / bestelling',
        'reimbursement'    => 'Declaratie',
        'returned_payment' => 'Teruggestorte betaling',
        'bank_costs'       => 'Bankkosten',
        'transfer'         => 'Interne overboeking',
        'other'            => 'Overig',
    ];

    /** Suggestions for the category field; earlier used categories are added. */
    public const DEFAULT_CATEGORIES = ['Zaalhuur', 'Catering', 'Drukwerk', 'Materiaal', 'Porto', 'Abonnementen', 'Verzekering', 'Website en software', 'Excursie', 'Overig'];

    /** Which kinds keep which optional field; the form shows the same (data-kinds). */
    public const FIELD_KINDS = [
        'supplier'    => ['invoice'],
        'category'    => ['invoice', 'other', 'bank_costs', 'transfer'],
        'activity_id' => ['invoice', 'reimbursement', 'other'],
    ];

    /** Statuses of an incoming payment that can be sent back: not allocated to fee items. */
    public const RETURNABLE_STATUSES = ['ignored', 'unmatched', 'suggested'];

    /**
     * Why an explanation can't be saved, or null when it can. $input holds
     * supplier and note; the related objects are looked up by the caller.
     *
     * @param array{supplier?: string, note?: string} $input
     */
    public static function rejection(string $kind, ?object $tx, bool $already_explained, array $input, bool $has_document, ?object $reimbursement, ?object $related, bool $related_already_returned): ?string {
        if (!$tx) {
            return 'no_transaction';
        }
        if ($tx->direction !== 'out' || !empty($tx->duplicate_of) || $tx->status !== 'ignored' || $already_explained) {
            return 'not_outgoing';
        }
        $amount = (float) $tx->amount;
        return match ($kind) {
            'invoice'          => self::first_failed(['no_supplier' => trim($input['supplier'] ?? '') === '', 'no_document' => !$has_document]),
            'reimbursement'    => self::first_failed([
                'no_reimbursement' => !$reimbursement || $reimbursement->status === 'rejected',
                'amount_mismatch'  => self::amount_differs($reimbursement, $amount),
            ]),
            'returned_payment' => self::first_failed([
                'no_related'        => !$related || $related->direction !== 'in' || !empty($related->duplicate_of),
                'related_allocated' => $related && !in_array($related->status, self::RETURNABLE_STATUSES, true),
                'related_returned'  => $related_already_returned,
                'amount_mismatch'   => self::amount_differs($related, $amount),
            ]),
            'other'            => trim($input['note'] ?? '') === '' ? 'no_note' : null,
            default            => isset(self::KINDS[$kind]) ? null : 'unknown_kind',
        };
    }

    /** @param array<string, bool> $checks In order; the first true one is the reason. */
    private static function first_failed(array $checks): ?string {
        return array_key_first(array_filter($checks));
    }

    private static function amount_differs(?object $other, float $amount): bool {
        return $other !== null && abs((float) $other->amount - $amount) > 0.005;
    }

    /**
     * Incoming payments this outgoing one may send back: same amount first,
     * then the most recent.
     *
     * @param object[] $payments Each with ->id, ->amount, ->transaction_date.
     * @return object[]
     */
    public static function rank_returned_candidates(array $payments, float $amount): array {
        usort($payments, static fn($a, $b) => [abs((float) $b->amount - $amount) < 0.005, $b->transaction_date, $b->id]
            <=> [abs((float) $a->amount - $amount) < 0.005, $a->transaction_date, $a->id]);
        return $payments;
    }

    /**
     * Reimbursements this transfer may pay: paid to the same account first,
     * then the same amount, then pending before already paid, oldest first.
     *
     * @param object[] $reimbursements Each with ->id, ->amount, ->iban, ->status.
     * @return object[]
     */
    public static function rank_reimbursements(array $reimbursements, float $amount, string $iban): array {
        $iban = strtoupper(str_replace(' ', '', $iban));
        $key = static fn($r) => [
            $iban !== '' && strtoupper(str_replace(' ', '', (string) $r->iban)) === $iban,
            abs((float) $r->amount - $amount) < 0.005,
            $r->status === 'pending',
            -(int) $r->id,
        ];
        usort($reimbursements, static fn($a, $b) => $key($b) <=> $key($a));
        return $reimbursements;
    }

    public function register(): void {
        add_action('admin_post_avbk_explain_expense', [$this, 'handle_explain']);
        add_action('admin_post_avbk_remove_expense', [$this, 'handle_remove']);
        add_action('admin_post_avbk_view_expense_document', [$this, 'handle_view_document']);
    }

    private function can_manage(): bool {
        return current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('penningmeester');
    }

    /** Saves the explanation of one outgoing transaction. */
    public function handle_explain(): void {
        check_admin_referer('avbk_explain_expense');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $transaction_id = (int) ($_POST['transaction_id'] ?? 0);
        $kind = sanitize_key(wp_unslash($_POST['kind'] ?? ''));
        $rejection = $this->explain($transaction_id, $kind, self::posted_input($kind));
        $notice = ['explained' => '1'];
        if ($rejection !== null) {
            avbk_log_failure('expense explain', $rejection, ['transaction' => $transaction_id, 'kind' => $kind]);
            $notice = ['explain_failed' => $rejection, 'tx' => $transaction_id];
        }
        $anchor = $rejection !== null ? '#tx-' . $transaction_id : '';
        wp_safe_redirect(add_query_arg(['page' => 'avbk-expenses'] + $notice, admin_url('admin.php')) . $anchor);
        exit;
    }

    /**
     * The posted fields, without those that don't belong to the kind.
     *
     * @return array{supplier: string, category: string, note: string, activity_id: int, reimbursement_id: int, related_transaction_id: int}
     */
    private static function posted_input(string $kind): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- handle_explain() checked the nonce.
        $input = [
            'supplier'               => sanitize_text_field(wp_unslash($_POST['supplier'] ?? '')),
            'category'               => sanitize_text_field(wp_unslash($_POST['category'] ?? '')),
            'note'                   => sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')),
            'activity_id'            => (int) ($_POST['activity_id'] ?? 0),
            'reimbursement_id'       => $kind === 'reimbursement' ? (int) ($_POST['reimbursement_id'] ?? 0) : 0,
            'related_transaction_id' => $kind === 'returned_payment' ? (int) ($_POST['related_transaction_id'] ?? 0) : 0,
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        foreach (self::FIELD_KINDS as $name => $kinds) {
            if (!in_array($kind, $kinds, true)) {
                $input[$name] = $name === 'activity_id' ? 0 : '';
            }
        }
        return $input;
    }

    /** Checks and stores an explanation; returns the reason it was refused, or null. */
    private function explain(int $transaction_id, string $kind, array $input): ?string {
        $upload = $this->uploaded_document();
        if (isset($upload['error'])) {
            return $upload['error'];
        }
        $related_id = $input['related_transaction_id'];
        $reimbursement_id = $input['reimbursement_id'];
        $rejection = self::rejection(
            $kind,
            AVBK_DB::get_transaction($transaction_id),
            AVBK_DB::get_expense_for_transaction($transaction_id) !== null,
            $input,
            isset($upload['tmp']),
            $reimbursement_id ? AVBK_DB::get_reimbursement($reimbursement_id) : null,
            $related_id ? AVBK_DB::get_transaction($related_id) : null,
            $related_id && AVBK_DB::is_returned_payment($related_id)
        );
        if ($rejection !== null) {
            return $rejection;
        }
        $document = isset($upload['tmp']) ? $this->store_document($upload['tmp'], $upload['ext']) : '';
        $saved = AVBK_DB::insert_expense([
            'transaction_id'         => $transaction_id,
            'kind'                   => $kind,
            'category'               => $input['category'],
            'supplier'               => $input['supplier'],
            'activity_id'            => $input['activity_id'] ?: null,
            'note'                   => $input['note'],
            'related_transaction_id' => $related_id ?: null,
            'reimbursement_id'       => $reimbursement_id ?: null,
            'document_path'          => $document,
            'document_name'          => $upload['name'] ?? '',
        ]);
        if (!$saved && $document !== '') {
            self::filesystem()->delete(AVBK_Reimbursements::receipts_dir() . '/' . $document);
        }
        return $saved ? null : 'not_saved';
    }

    /**
     * The document of this request, validated like a receipt.
     *
     * @return array{tmp?: string, ext?: string, name?: string, error?: string} Empty when nothing was uploaded.
     */
    private function uploaded_document(): array {
        $file = $_FILES['document'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below: uploaded-file check, size, extension and real bytes.
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'document_invalid'];
        }
        try {
            $tmp = avbk_uploaded_file_path((string) $file['tmp_name']);
        } catch (AVBK_Upload_Exception $e) {
            return ['error' => 'document_invalid'];
        }
        $name = sanitize_file_name((string) $file['name']);
        $ext = AVBK_Reimbursements::validate_receipt_file($tmp, $name);
        return $ext === null ? ['error' => 'document_invalid'] : ['tmp' => $tmp, 'ext' => $ext, 'name' => $name];
    }

    private function store_document(string $tmp, string $ext): string {
        AVBK_Reimbursements::ensure_receipts_dir_protected();
        $name = 'expense-' . wp_generate_password(32, false, false) . '.' . $ext;
        // WP_Filesystem->move(), not move_uploaded_file() (Plugin Check);
        // uploaded_document() already checked this is this request's upload.
        self::filesystem()->move($tmp, AVBK_Reimbursements::receipts_dir() . '/' . $name);
        return $name;
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

    /** Removes an explanation; the transaction is ignored again and waits for a new one. */
    public function handle_remove(): void {
        check_admin_referer('avbk_remove_expense');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $expense_id = (int) ($_POST['expense_id'] ?? 0);
        $document = $expense_id ? AVBK_DB::delete_expense($expense_id) : null;
        $notice = ['removed' => '1'];
        if ($document === null) {
            avbk_log_failure('expense remove', 'not_found', ['expense' => $expense_id]);
            $notice = ['explain_failed' => 'not_found'];
        } elseif ($document !== '') {
            self::filesystem()->delete(AVBK_Reimbursements::receipts_dir() . '/' . basename($document));
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-expenses', 'tab' => 'explained'] + $notice, admin_url('admin.php')));
        exit;
    }

    public function handle_view_document(): void {
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }
        $expense = AVBK_DB::get_expense((int) ($_GET['id'] ?? 0));
        $path = $expense && $expense->document_path !== '' ? AVBK_Reimbursements::receipts_dir() . '/' . basename($expense->document_path) : '';
        if ($path === '' || !file_exists($path)) {
            wp_die('Bestand niet gevonden.', 'Fout', ['response' => 404]);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif', 'pdf' => 'application/pdf'];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: ' . ($ext === 'pdf' ? 'attachment' : 'inline') . '; filename="uitgave-' . (int) $expense->id . '.' . $ext . '"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'");
        echo self::filesystem()->get_contents($path); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw image/pdf bytes, not HTML.
        exit;
    }

    /** Link that shows an expense's document to the penningmeester. */
    public static function document_url(object $expense): string {
        return add_query_arg(['action' => 'avbk_view_expense_document', 'id' => (int) $expense->id], admin_url('admin-post.php'));
    }

    /** One-line description of an explanation, for lists and Tweede controle. */
    public static function summary(object $expense): string {
        $parts = [self::KINDS[$expense->kind] ?? $expense->kind];
        foreach (['supplier', 'category'] as $field) {
            if (trim((string) $expense->$field) !== '') {
                $parts[] = (string) $expense->$field;
            }
        }
        if (!empty($expense->related_transaction_id)) {
            $parts[] = 'van transactie #' . (int) $expense->related_transaction_id;
        }
        if (!empty($expense->reimbursement_id)) {
            $parts[] = 'declaratie #' . (int) $expense->reimbursement_id;
        }
        if (trim((string) $expense->note) !== '') {
            $parts[] = (string) $expense->note;
        }
        return implode(' — ', $parts);
    }
}
