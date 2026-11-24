<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-documents.php';

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

    /** The category of bank costs, filled in when none is given. */
    public const BANK_COSTS_CATEGORY = 'Bankkosten';

    /** Suggestions for the category field; earlier used categories are added. */
    public const DEFAULT_CATEGORIES = ['Accommodatie', 'Catering', 'Boodschappen', 'Vervoer', 'Brandstof', 'Materiaalhuur', 'Kampeerbenodigdheden', 'EHBO', 'Drukwerk', 'Materiaal', 'Porto', 'Abonnementen', 'Verzekering', 'Website en software', 'Excursie', 'Attenties', 'Overig'];

    /** Which kinds keep which optional field; the form shows the same (data-kinds). */
    public const FIELD_KINDS = [
        'supplier'    => ['invoice'],
        'category'    => ['invoice', 'reimbursement', 'other', 'bank_costs', 'transfer'],
        'activity_id' => ['invoice', 'reimbursement', 'other'],
    ];

    /** Statuses of an incoming payment that can be sent back: not allocated to fee items. */
    public const RETURNABLE_STATUSES = ['ignored', 'unmatched', 'suggested'];

    private const NO_ACCESS = 'Geen toegang.';

    /**
     * Why an explanation can't be saved, or null when it can. The caller
     * looks up what $facts describes:
     * - explained: the transaction already has an explanation;
     * - supplier, note: the posted text;
     * - document: a valid document was uploaded;
     * - reimbursement, related: the chosen declaratie and incoming payment;
     * - returned: that payment is already explained as sent back.
     *
     * @param array{explained?: bool, supplier?: string, note?: string, document?: bool, reimbursement?: ?object, related?: ?object, returned?: bool} $facts
     */
    public static function rejection(string $kind, ?object $tx, array $facts): ?string {
        if (!$tx) {
            return 'no_transaction';
        }
        if ($tx->direction !== 'out' || !empty($tx->duplicate_of) || $tx->status !== 'ignored' || !empty($facts['explained'])) {
            return 'not_outgoing';
        }
        $amount = (float) $tx->amount;
        $reimbursement = $facts['reimbursement'] ?? null;
        $related = $facts['related'] ?? null;
        return match ($kind) {
            'invoice'          => self::first_failed(['no_supplier' => trim($facts['supplier'] ?? '') === '', 'no_document' => empty($facts['document'])]),
            'reimbursement'    => self::reimbursement_rejection($reimbursement, !empty($facts['document']), $amount),
            'returned_payment' => self::first_failed([
                'no_related'        => !$related || $related->direction !== 'in' || !empty($related->duplicate_of),
                'related_allocated' => $related && !in_array($related->status, self::RETURNABLE_STATUSES, true),
                'related_returned'  => !empty($facts['returned']),
                'amount_mismatch'   => self::amount_differs($related, $amount),
            ]),
            'other'            => trim($facts['note'] ?? '') === '' ? 'no_note' : null,
            default            => isset(self::KINDS[$kind]) ? null : 'unknown_kind',
        };
    }

    /**
     * A declaratie submitted on the site must match the transfer. One paid
     * outside the site (before declaraties went through the site, or by
     * hand) has no declaratie to choose; then the receipt is required.
     */
    private static function reimbursement_rejection(?object $reimbursement, bool $document, float $amount): ?string {
        if ($reimbursement === null) {
            return $document ? null : 'no_reimbursement';
        }
        return self::first_failed([
            'no_reimbursement' => $reimbursement->status === 'rejected',
            'amount_mismatch'  => self::amount_differs($reimbursement, $amount),
        ]);
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
            wp_die(esc_html(self::NO_ACCESS), 403);
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
     * Shared with AVBK_Expense_Edits.
     *
     * @return array{supplier: string, category: string, note: string, activity_id: int, reimbursement_id: int, related_transaction_id: int}
     */
    public static function posted_input(string $kind): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- handle_explain() and AVBK_Expense_Edits::handle_edit() checked the nonce.
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
        if ($kind === 'bank_costs' && $input['category'] === '') {
            $input['category'] = self::BANK_COSTS_CATEGORY;
        }
        return $input;
    }

    /** The receipt the member uploaded on request for this payment, if any (see AVBK_Document_Requests). */
    private static function requested_document(int $transaction_id): ?object {
        $request = AVBK_DB::get_document_request_for_transaction($transaction_id);
        return $request && $request->document_path !== '' ? $request : null;
    }

    /**
     * Stores the uploaded document, or a copy of the member's receipt
     * (the request keeps its own); nothing when there is neither.
     *
     * @return array{0: string, 1: string} Stored name and original name.
     */
    private static function save_document(array $upload, ?object $requested): array {
        if (isset($upload['tmp'])) {
            return [AVBK_Documents::store($upload['tmp'], $upload['ext'], 'expense'), (string) $upload['name']];
        }
        if ($requested !== null) {
            return [AVBK_Documents::copy((string) $requested->document_path, 'expense'), (string) $requested->document_name];
        }
        return ['', ''];
    }

    /** Checks and stores an explanation; returns the reason it was refused, or null. */
    private function explain(int $transaction_id, string $kind, array $input): ?string {
        $upload = AVBK_Documents::from_upload('document');
        if (isset($upload['error'])) {
            return $upload['error'];
        }
        $requested_document = isset($upload['tmp']) ? null : self::requested_document($transaction_id);
        $related_id = $input['related_transaction_id'];
        $reimbursement_id = $input['reimbursement_id'];
        $rejection = self::rejection($kind, AVBK_DB::get_transaction($transaction_id), [
            'explained'     => AVBK_DB::get_expense_for_transaction($transaction_id) !== null,
            'supplier'      => $input['supplier'],
            'note'          => $input['note'],
            'document'      => isset($upload['tmp']) || $requested_document !== null,
            'reimbursement' => $reimbursement_id ? AVBK_DB::get_reimbursement($reimbursement_id) : null,
            'related'       => $related_id ? AVBK_DB::get_transaction($related_id) : null,
            'returned'      => $related_id && AVBK_DB::is_returned_payment($related_id),
        ]);
        if ($rejection !== null) {
            return $rejection;
        }
        [$document, $document_name] = self::save_document($upload, $requested_document);
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
            'document_name'          => $document_name,
        ]);
        if (!$saved) {
            AVBK_Documents::delete($document);
        }
        return $saved ? null : 'not_saved';
    }

    /** Removes an explanation; the transaction is ignored again and waits for a new one. */
    public function handle_remove(): void {
        check_admin_referer('avbk_remove_expense');
        if (!$this->can_manage()) {
            wp_die(esc_html(self::NO_ACCESS), 403);
        }
        $expense_id = (int) ($_POST['expense_id'] ?? 0);
        $document = $expense_id ? AVBK_DB::delete_expense($expense_id) : null;
        $notice = ['removed' => '1'];
        if ($document === null) {
            avbk_log_failure('expense remove', 'not_found', ['expense' => $expense_id]);
            $notice = ['explain_failed' => 'not_found'];
        } else {
            AVBK_Documents::delete($document);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-expenses', 'tab' => 'explained'] + $notice, admin_url('admin.php')));
        exit;
    }

    public function handle_view_document(): void {
        if (!$this->can_manage()) {
            wp_die(esc_html(self::NO_ACCESS), 'Fout', ['response' => 403]);
        }
        $expense = AVBK_DB::get_expense((int) ($_GET['id'] ?? 0));
        AVBK_Documents::serve($expense ? (string) $expense->document_path : '', 'uitgave-' . (int) ($expense->id ?? 0));
    }

    /** Link that shows an expense's document to the penningmeester. */
    public static function document_url(object $expense): string {
        return add_query_arg(['action' => 'avbk_view_expense_document', 'id' => (int) $expense->id], admin_url('admin-post.php'));
    }

    /** One-line description of an explanation, for lists and Tweede controle. */
    public static function summary(object $expense): string {
        $parts = [self::KINDS[$expense->kind] ?? $expense->kind];
        if (trim((string) $expense->category) !== '') {
            $parts[] = (string) $expense->category;
        }
        return implode(' — ', array_merge($parts, self::details($expense)));
    }

    /**
     * What an explanation says besides its kind and category: supplier,
     * the incoming payment or declaratie it is tied to, and the note.
     *
     * @return string[]
     */
    public static function details(object $expense): array {
        $parts = [];
        if (trim((string) $expense->supplier) !== '') {
            $parts[] = (string) $expense->supplier;
        }
        if (!empty($expense->related_transaction_id)) {
            $parts[] = 'van transactie #' . (int) $expense->related_transaction_id;
        }
        if (!empty($expense->reimbursement_id)) {
            $parts[] = 'declaratie #' . (int) $expense->reimbursement_id;
        } elseif ($expense->kind === 'reimbursement') {
            $parts[] = 'buiten de website ingediend';
        }
        if (trim((string) $expense->note) !== '') {
            $parts[] = (string) $expense->note;
        }
        return $parts;
    }
}
