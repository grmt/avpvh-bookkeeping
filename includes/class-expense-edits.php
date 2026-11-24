<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-expenses.php';

/**
 * Changing an explanation on Uitgaven → Verantwoord: its kind, supplier,
 * category, activity, note, and a document that replaces the current one.
 * The same rules as explaining apply (AVBK_Expenses::rejection()), and the
 * change takes back the Tweede controle (AVBK_DB::update_expense()).
 */
class AVBK_Expense_Edits implements AVBK_Component {

    public function register(): void {
        add_action('admin_post_avbk_edit_expense', [$this, 'handle_edit']);
    }

    /**
     * Why a change to an explanation can't be saved, or null when it can.
     * The kind can change, except to or from one tied to a declaratie or
     * an incoming payment: those links are made when explaining, so that
     * takes removing the explanation and explaining it again.
     *
     * @param array{supplier?: string, note?: string, document?: bool} $facts document: the expense keeps or gets a document.
     */
    public static function rejection(object $expense, string $kind, array $facts): ?string {
        if (!isset(AVBK_Expenses::KINDS[$kind])) {
            return 'unknown_kind';
        }
        $checks = match ($kind) {
            'invoice'       => ['no_supplier' => trim($facts['supplier'] ?? '') === '', 'no_document' => empty($facts['document'])],
            'reimbursement' => ['no_reimbursement' => empty($expense->reimbursement_id) && empty($facts['document'])],
            'other'         => ['no_note' => trim($facts['note'] ?? '') === ''],
            default         => [],
        };
        return array_key_first(array_filter(['kind_fixed' => !isset(self::editable_kinds($expense)[$kind])] + $checks));
    }

    /**
     * The kinds an explanation can change to (see rejection()).
     *
     * @return array<string, string>
     */
    public static function editable_kinds(object $expense): array {
        if (!empty($expense->reimbursement_id) || !empty($expense->related_transaction_id) || $expense->kind === 'returned_payment') {
            return [$expense->kind => AVBK_Expenses::KINDS[$expense->kind] ?? $expense->kind];
        }
        return array_diff_key(AVBK_Expenses::KINDS, ['returned_payment' => true]);
    }

    /** Saves a change to an explanation. */
    public function handle_edit(): void {
        check_admin_referer('avbk_edit_expense');
        if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
            wp_die(esc_html('Geen toegang.'), 403);
        }
        $expense_id = (int) ($_POST['expense_id'] ?? 0);
        $kind = sanitize_key(wp_unslash($_POST['kind'] ?? ''));
        $rejection = self::edit($expense_id, $kind, AVBK_Expenses::posted_input($kind));
        $notice = ['updated' => '1'];
        if ($rejection !== null) {
            avbk_log_failure('expense edit', $rejection, ['expense' => $expense_id, 'kind' => $kind]);
            $notice = ['explain_failed' => $rejection];
        }
        $year = (int) ($_POST['year'] ?? 0);
        $args = ['page' => 'avbk-expenses', 'tab' => 'explained'] + ($year ? ['year' => $year] : []) + $notice;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#expense-' . $expense_id);
        exit;
    }

    /** Checks and stores a change; returns the reason it was refused, or null. A new document replaces the old one. */
    private static function edit(int $expense_id, string $kind, array $input): ?string {
        $expense = AVBK_DB::get_expense($expense_id);
        $upload = AVBK_Documents::from_upload('document');
        $rejection = match (true) {
            !$expense               => 'not_found',
            isset($upload['error']) => $upload['error'],
            default                 => self::rejection($expense, $kind, [
                'supplier' => $input['supplier'],
                'note'     => $input['note'],
                'document' => isset($upload['tmp']) || (string) $expense->document_path !== '',
            ]),
        };
        if ($rejection !== null) {
            return $rejection;
        }
        $data = [
            'kind'        => $kind,
            'category'    => $input['category'],
            'supplier'    => $input['supplier'],
            'activity_id' => $input['activity_id'] ?: null,
            'note'        => $input['note'],
        ];
        $document = isset($upload['tmp']) ? AVBK_Documents::store($upload['tmp'], $upload['ext'], 'expense') : '';
        if ($document !== '') {
            $data += ['document_path' => $document, 'document_name' => (string) $upload['name']];
        }
        $saved = AVBK_DB::update_expense($expense_id, $data);
        // Saved: the replaced document goes; not saved: the new one does.
        AVBK_Documents::delete($saved ? ($document !== '' ? (string) $expense->document_path : '') : $document);
        return $saved ? null : 'not_saved';
    }
}
