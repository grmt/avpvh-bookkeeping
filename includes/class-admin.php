<?php
defined('ABSPATH') || exit;

class AVBK_Admin {

    public const DEFAULT_PAYMENT_EMAIL_LOGIN_TEXT = "Inloggen: gebruik als gebruikersnaam je e-mailadres. Als je nog niet eerder bent ingelogd of je je wachtwoord niet weet, klik dan [wachtwoord-link].\n\nAls je met je browser al bent ingelogd bij Google (Gmail) of Microsoft (Outlook/Hotmail), kun je ook de knop Inloggen met Google respectievelijk Inloggen met Microsoft proberen. Dan heb je geen (nieuw) wachtwoord nodig. Het e-mailadres moet wel overeenkomen met het adres dat bij de vereniging bekend is.";
    public const DEFAULT_QR_CAPTION_TEXT = 'Scan deze QR-code met je bankieren-app:';
    public const DEFAULT_GENERIC_PAYMENT_LINK_TEXT = 'Je kan betalen via deze betaallink: [link]';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menus'], 5);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_avbk_upload_import',        [$this, 'handle_upload_import']);
        add_action('admin_post_avbk_save_bank_import_layout', [$this, 'handle_save_bank_import_layout']);
        add_action('admin_post_avbk_confirm_transaction',  [$this, 'handle_confirm_transaction']);
        add_action('admin_post_avbk_save_transaction_draft',  [$this, 'handle_save_transaction_draft']);
        add_action('admin_post_avbk_clear_transaction_draft', [$this, 'handle_clear_transaction_draft']);
        add_action('admin_post_avbk_ignore_transaction',   [$this, 'handle_ignore_transaction']);
        add_action('admin_post_avbk_mark_transaction_duplicate', [$this, 'handle_mark_transaction_duplicate']);
        add_action('admin_post_avbk_restore_ignored_transaction', [$this, 'handle_restore_ignored_transaction']);
        add_action('admin_post_avbk_set_transaction_activity', [$this, 'handle_set_transaction_activity']);
        add_action('admin_post_avbk_save_activity_rate',   [$this, 'handle_save_activity_rate']);
        add_action('admin_post_avbk_delete_activity_rate', [$this, 'handle_delete_activity_rate']);
        add_action('admin_post_avbk_copy_activity_rates',  [$this, 'handle_copy_activity_rates']);
        add_action('admin_post_avbk_waive_fee_item',       [$this, 'handle_waive_fee_item']);
        add_action('admin_post_avbk_update_fee_item_amount', [$this, 'handle_update_fee_item_amount']);
        add_action('admin_post_avbk_save_student_year',    [$this, 'handle_save_student_year']);
        add_action('admin_post_avbk_delete_student_year',  [$this, 'handle_delete_student_year']);
        add_action('admin_post_avbk_save_settings',        [$this, 'handle_save_settings']);
        add_action('admin_post_avbk_save_iban_country_format', [$this, 'handle_save_iban_country_format']);
        add_action('admin_post_avbk_delete_iban_country_format', [$this, 'handle_delete_iban_country_format']);
        add_action('admin_post_avbk_save_iban_bank_code',   [$this, 'handle_save_iban_bank_code']);
        add_action('admin_post_avbk_delete_iban_bank_code', [$this, 'handle_delete_iban_bank_code']);
        add_action('admin_post_avbk_generate_contribution_fees_now', [$this, 'handle_generate_contribution_fees_now']);
        add_action('admin_post_avbk_generate_camp_fees_now',         [$this, 'handle_generate_camp_fees_now']);
        add_action('admin_post_avbk_save_sheet_url',                 [$this, 'handle_save_sheet_url']);
        add_action('admin_post_avbk_save_sheet_import_config',       [$this, 'handle_save_sheet_import_config']);
        add_action('admin_post_avbk_sheet_import',                   [$this, 'handle_sheet_import']);
        add_action('admin_post_avbk_sheet_import_upload',            [$this, 'handle_sheet_import_upload']);
        add_action('admin_post_avbk_camp_sheet_import_from_url',     [$this, 'handle_camp_sheet_import_from_url']);
        add_action('admin_post_avbk_sheet_import_link_attendee',     [$this, 'handle_sheet_import_link_attendee']);
        add_action('admin_post_avbk_sheet_import_ignore_attendee',   [$this, 'handle_sheet_import_ignore_attendee']);
        add_action('admin_post_avbk_sheet_import_apply_sheet_value', [$this, 'handle_sheet_import_apply_sheet_value']);
        add_action('admin_post_avbk_request_payment',                [$this, 'handle_request_payment']);
        add_action('admin_post_avbk_preview_request_payment_email',  [$this, 'handle_preview_request_payment_email']);
        add_action('admin_post_avbk_preview_payment_request',        [$this, 'handle_preview_payment_request']);
        add_action('admin_post_avbk_request_balance_payment',        [$this, 'handle_request_balance_payment']);
        add_action('admin_post_avbk_recompute_suggestions',          [$this, 'handle_recompute_suggestions']);
        add_action('admin_post_avbk_save_review_order',              [$this, 'handle_save_review_order']);
        add_action('admin_post_avbk_resolve_dispute',                [$this, 'handle_resolve_dispute']);
        add_action('admin_post_avbk_second_approve_transaction',     [$this, 'handle_second_approve_transaction']);
        add_action('admin_post_avbk_revert_transaction_to_review',   [$this, 'handle_revert_transaction_to_review']);
        add_action('admin_post_avbk_revert_year_payments_to_review', [$this, 'handle_revert_year_payments_to_review']);
        add_action('admin_post_avbk_set_closed_through_year',        [$this, 'handle_set_closed_through_year']);
        add_action('admin_post_avbk_save_activity_payment_url',      [$this, 'handle_save_activity_payment_url']);
        add_action('admin_post_avbk_save_activity_payment_qr',       [$this, 'handle_save_activity_payment_qr']);
        add_action('admin_post_avbk_delete_activity_payment_url',    [$this, 'handle_delete_activity_payment_url']);
        add_action('admin_post_avbk_delete_activity_payment_qr',     [$this, 'handle_delete_activity_payment_qr']);
        add_action('wp_ajax_avbk_member_fee_detail', [$this, 'ajax_member_fee_detail']);
        add_action('wp_ajax_avbk_household_candidates', [$this, 'ajax_household_candidates']);
        add_action('wp_ajax_avbk_activity_participants', [$this, 'ajax_activity_participants']);
    }

    /** Real WP admins, or whoever currently holds/is delegated penningmeester (AVPVH_Roles folds officer roles into bestuur, but this screen is specifically financial — keep it to penningmeester, not all of bestuur). */
    private function can_manage(): bool {
        return current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('penningmeester');
    }

    private function is_camp_activity(int $activity_id): bool {
        $activity = $activity_id > 0 ? AVPVH_DB::get_activity($activity_id) : null;
        return $activity && ($activity->type_name ?? '') === 'Kamp';
    }

    public function register_menus(): void {
        if (!$this->can_manage()) {
            return; // not registered at all for anyone else — 'read' (used below) is every logged-in user's capability
        }

        add_menu_page(
            'AV-PvH Boekhouding', 'AV-PvH Boekhouding', 'read',
            'avbk-overview', [$this, 'render_overview'],
            'dashicons-money-alt', 31
        );
        add_submenu_page('avbk-overview', 'Overzicht', 'Overzicht', 'read', 'avbk-overview', [$this, 'render_overview']);
        add_submenu_page('avbk-overview', 'Bankexport uploaden', 'Bankexport uploaden', 'read', 'avbk-import', [$this, 'render_import']);
        add_submenu_page('avbk-overview', 'Te controleren', 'Te controleren', 'read', 'avbk-review', [$this, 'render_review']);

        $pending_second_approval = AVBK_DB::count_pending_second_approval();
        $second_approval_label = 'Tweede controle' . ($pending_second_approval ? " <span class=\"awaiting-mod count-{$pending_second_approval}\"><span class=\"pending-count\">{$pending_second_approval}</span></span>" : '');
        add_submenu_page('avbk-overview', 'Tweede controle', $second_approval_label, 'read', 'avbk-second-approval', [$this, 'render_second_approval']);
        add_submenu_page('avbk-overview', 'Alle transacties', 'Alle transacties', 'read', 'avbk-transactions', [$this, 'render_transactions']);
        add_submenu_page('avbk-overview', 'Ledenoverzicht', 'Ledenoverzicht', 'read', 'avbk-members', [$this, 'render_members']);
        add_submenu_page('avbk-overview', 'Tarieven', 'Tarieven', 'read', 'avbk-rates', [$this, 'render_rates']);
        add_submenu_page('avbk-overview', 'IBAN-bankcodes', 'IBAN-bankcodes', 'read', 'avbk-iban-bank-codes', [$this, 'render_iban_bank_codes']);

        $open_disputes = AVBK_DB::count_open_disputes();
        $disputes_label = 'Bezwaren' . ($open_disputes ? " <span class=\"awaiting-mod count-{$open_disputes}\"><span class=\"pending-count\">{$open_disputes}</span></span>" : '');
        add_submenu_page('avbk-overview', 'Bezwaren', $disputes_label, 'read', 'avbk-disputes', [$this, 'render_disputes']);

        $pending_reimbursements = AVBK_DB::count_pending_reimbursements();
        $reimbursements_label = 'Declaraties' . ($pending_reimbursements ? " <span class=\"awaiting-mod count-{$pending_reimbursements}\"><span class=\"pending-count\">{$pending_reimbursements}</span></span>" : '');
        add_submenu_page('avbk-overview', 'Declaraties', $reimbursements_label, 'read', 'avbk-reimbursements', [$this, 'render_reimbursements']);

        add_submenu_page('avbk-overview', 'Deelname en betalingen', 'Deelname en betalingen', 'read', 'avbk-activity-payments', [$this, 'render_activity_payments']);
    }

    public function enqueue_assets(string $hook): void {
        if (
            sanitize_key(wp_unslash($_GET['page'] ?? '')) === 'avpvh-activity-participation-detail'
            && !empty($_GET['member_id'])
        ) {
            // A validation hotlink can open a not-yet-existing camp
            // participation. The members form itself has no member_id URL
            // prefill, so select the requested member once its form exists.
            wp_enqueue_script(
                'avbk-participation-prefill',
                AVBK_PLUGIN_URL . 'assets/participation-prefill.js',
                [],
                avbk_asset_version('assets/participation-prefill.js'),
                true
            );
        }
        if (!str_contains($hook, 'avbk-')) {
            return;
        }
        wp_enqueue_style('avbk-admin', AVBK_PLUGIN_URL . 'assets/admin.css', [], avbk_asset_version('assets/admin.css'));
        if (str_contains($hook, 'avbk-members') || str_contains($hook, 'avbk-transactions') || str_contains($hook, 'avbk-activity-payments')) {
            wp_enqueue_style('avbk-balance-admin', AVBK_PLUGIN_URL . 'assets/balance.css', [], avbk_asset_version('assets/balance.css'));
            wp_enqueue_script('avbk-balance-admin', AVBK_PLUGIN_URL . 'assets/balance.js', [], avbk_asset_version('assets/balance.js'), true);
        }
        if (str_contains($hook, 'avbk-review')) {
            wp_enqueue_script('avbk-review-queue', AVBK_PLUGIN_URL . 'assets/review-queue.js', [], avbk_asset_version('assets/review-queue.js'), true);
        }
        if (str_contains($hook, 'avbk-reimbursements')) {
            wp_enqueue_script('avbk-reimbursement-admin', AVBK_PLUGIN_URL . 'assets/reimbursement-admin.js', [], avbk_asset_version('assets/reimbursement-admin.js'), true);
        }
        if (str_contains($hook, 'avbk-import') || str_contains($hook, 'avbk-activity-payments')) {
            wp_enqueue_script('avbk-import', AVBK_PLUGIN_URL . 'assets/import.js', [], avbk_asset_version('assets/import.js'), true);
        }
    }

    public function render_overview(): void { require AVBK_PLUGIN_DIR . 'admin/overview.php'; }
    public function render_disputes(): void { require AVBK_PLUGIN_DIR . 'admin/disputes.php'; }
    public function render_reimbursements(): void { require AVBK_PLUGIN_DIR . 'admin/reimbursements.php'; }
    public function render_activity_payments(): void { require AVBK_PLUGIN_DIR . 'admin/activity-payments.php'; }
    public function render_import(): void { require AVBK_PLUGIN_DIR . 'admin/import.php'; }
    public function render_review(): void { require AVBK_PLUGIN_DIR . 'admin/review-queue.php'; }
    public function render_second_approval(): void { require AVBK_PLUGIN_DIR . 'admin/second-approval.php'; }
    public function render_transactions(): void { require AVBK_PLUGIN_DIR . 'admin/transactions.php'; }
    public function render_members(): void { require AVBK_PLUGIN_DIR . 'admin/members-balance.php'; }
    public function render_rates(): void { require AVBK_PLUGIN_DIR . 'admin/rates.php'; }
    public function render_iban_bank_codes(): void { require AVBK_PLUGIN_DIR . 'admin/iban-bank-codes.php'; }

    public function handle_upload_import(): void {
        check_admin_referer('avbk_upload_import');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }

        if (empty($_FILES['bank_export']['tmp_name']) || !is_uploaded_file($_FILES['bank_export']['tmp_name'])) {
            wp_safe_redirect(add_query_arg(['page' => 'avbk-import', 'import_error' => '1'], admin_url('admin.php')));
            exit;
        }

        try {
            $filename = sanitize_file_name(wp_unslash($_FILES['bank_export']['name']));
            // Deliberately never moved into wp-content/uploads — a bank
            // export contains IBANs and personal transaction data, and
            // AVBK_Import::process_file() only needs to read it once from
            // PHP's own private upload tmp path.
            $result = AVBK_Import::process_file($_FILES['bank_export']['tmp_name'], $filename, get_current_user_id());
        } catch (\Throwable $e) {
            wp_safe_redirect(add_query_arg([
                'page' => 'avbk-import', 'import_error' => '1', 'import_error_message' => rawurlencode($e->getMessage()),
            ], admin_url('admin.php')));
            exit;
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-review', 'imported' => '1',
            'row_count' => $result['row_count'], 'matched_count' => $result['matched_count'],
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_save_bank_import_layout(): void {
        check_admin_referer('avbk_save_bank_import_layout');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        AVBK_Bank_Import_Layout::save_config(AVBK_Bank_Import_Layout::sanitize($_POST));
        wp_safe_redirect(add_query_arg(['page' => 'avbk-import', 'layout_saved' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * The confirm form's unified rows — parallel arrays name[]/activity[]/
     * description[]/amount[], one entry per row, in whatever order they
     * were posted. No filtering here: handle_save_transaction_draft() wants
     * the raw, possibly-incomplete state (that's the point of a draft),
     * while handle_confirm_transaction() filters separately since only it
     * needs every row to be actually usable.
     */
    private function parse_raw_transaction_rows(): array {
        $member_ids = array_map('intval', (array) ($_POST['member_id'] ?? []));
        $activities = array_map('sanitize_text_field', wp_unslash((array) ($_POST['activity'] ?? [])));
        $descriptions = array_map('sanitize_text_field', wp_unslash((array) ($_POST['description'] ?? [])));
        $amounts_raw = array_map('sanitize_text_field', wp_unslash((array) ($_POST['amount'] ?? [])));
        $donation_emails = (array) ($_POST['donation_email'] ?? []);

        $rows = [];
        foreach ($member_ids as $i => $member_id) {
            $rows[] = [
                'member_id'      => $member_id,
                'activity'       => $activities[$i] ?? '',
                'description'    => $descriptions[$i] ?? '',
                'amount'         => (float) str_replace(',', '.', (string) ($amounts_raw[$i] ?? '')),
                // "Markeer rest als schenking" (review-queue.js) checks a
                // box next to the row it auto-fills — set when the
                // treasurer wants the overpayer notified by e-mail once
                // this row is actually confirmed (see
                // maybe_send_donation_emails()).
                'donation_email' => !empty($donation_emails[$i]),
            ];
        }
        return $rows;
    }

    /**
     * Fires only for rows the treasurer explicitly opted into via
     * "Markeer rest als schenking"'s own checkbox — a courtesy heads-up
     * that an overpayment is being kept as a donation, never sent
     * silently just because a row happens to be named "Schenking".
     * $context_label is every other confirmed row's activiteit on this
     * same transaction (what the member actually overpaid for);
     * "jouw betaling" is the fallback when that's empty/ambiguous.
     */
    private function maybe_send_donation_emails(array $rows, string $transaction_date, string $context_label): void {
        $context_label = $context_label !== '' ? $context_label : 'jouw betaling';
        $penningmeester_name = get_option('avbk_penningmeester_name', 'de penningmeester');
        $penningmeester_email = get_option('avbk_penningmeester_email', 'info@avphilipsvanhorne.nl');

        foreach ($rows as $row) {
            if (empty($row['donation_email']) || (int) $row['member_id'] <= 0 || (float) $row['amount'] <= 0) {
                continue;
            }
            $member = AVPVH_DB::get_member((int) $row['member_id']);
            if (!$member || $member->email === '' || str_ends_with(strtolower($member->email), '@avpvh.local')) {
                continue; // no real adres on file to mail this to
            }
            $body = sprintf(
                "Beste %s,\n\nJe hebt op %s € %s teveel betaald voor %s. Ik heb dit bedrag nu als schenking aan de vereniging beschouwd.\n\nMail gerust terug als je dit liever anders zou zien.\n\nMet vriendelijke groet,\n%s",
                $member->first_name,
                wp_date('d-m-Y', strtotime($transaction_date)),
                number_format((float) $row['amount'], 2, ',', '.'),
                $context_label,
                $penningmeester_name
            );
            $sent = wp_mail(
                $member->email,
                'Overbetaling als schenking verwerkt',
                $body,
                ['Reply-To: ' . $penningmeester_name . ' <' . $penningmeester_email . '>']
            );
            if ($sent) {
                $fee_item = AVBK_DB::find_recent_donation_fee_item((int) $row['member_id']);
                if ($fee_item) {
                    AVBK_DB::mark_donation_email_sent((int) $fee_item->id);
                }
            }
        }
    }

    public function handle_confirm_transaction(): void {
        check_admin_referer('avbk_transaction_row');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }

        $transaction_id = (int) ($_POST['transaction_id'] ?? 0);
        $rows = array_values(array_filter(
            $this->parse_raw_transaction_rows(),
            fn($r) => $r['member_id'] > 0 && $r['amount'] > 0 && $r['activity'] !== ''
        ));

        if ($transaction_id && !$rows) {
            AVBK_DB::save_transaction_draft($transaction_id, $this->parse_raw_transaction_rows());
            set_transient('avbk_confirm_errors_' . get_current_user_id(), [
                'Er is niets verwerkt: kies minimaal één lid en activiteit met een bedrag groter dan € 0,00.',
            ], 60);
            wp_safe_redirect(add_query_arg(['page' => 'avbk-review', 'confirm_failed' => '1', 'confirm_failed_tx' => $transaction_id], admin_url('admin.php')) . '#tx-' . $transaction_id);
            exit;
        }

        $result = ['underpaid' => 0.0, 'requested_total' => 0.0, 'remaining_open' => 0.0, 'unassigned' => 0.0];
        if ($transaction_id && $rows) {
            $result = AVBK_Import::confirm_transaction($transaction_id, $rows);
            if (!$result['ok']) {
                // Keep the treasurer's edits as a draft (nothing was
                // written) and send them back to this same row instead of
                // losing the input or, worse, silently confirming with
                // money unaccounted for.
                AVBK_DB::save_transaction_draft($transaction_id, $rows);
                set_transient('avbk_confirm_errors_' . get_current_user_id(), $result['errors'], 60);
                wp_safe_redirect(add_query_arg(['page' => 'avbk-review', 'confirm_failed' => '1', 'confirm_failed_tx' => $transaction_id], admin_url('admin.php')) . '#tx-' . $transaction_id);
                exit;
            }

            $context_labels = [];
            foreach ($rows as $row) {
                if (!empty($row['donation_email'])) {
                    continue; // the schenking row itself isn't "what was overpaid for"
                }
                if (preg_match('/^a(\d+)$/', (string) $row['activity'], $m)) {
                    $activity = AVPVH_DB::get_activity((int) $m[1]);
                    if ($activity) {
                        $context_labels[] = $activity->name;
                    }
                } elseif ($row['activity'] !== '') {
                    $context_labels[] = $row['activity'];
                }
            }
            $tx = AVBK_DB::get_transaction($transaction_id);
            $this->maybe_send_donation_emails(
                $rows,
                $tx ? $tx->transaction_date : current_time('mysql'),
                implode(' en ', array_unique($context_labels))
            );
        }

        $redirect_args = ['page' => 'avbk-review', 'confirmed' => '1'];
        if (!empty($result['underpaid'])) {
            $redirect_args['underpaid'] = $result['underpaid'];
            $redirect_args['requested_total'] = $result['requested_total'];
        } else {
            if (!empty($result['remaining_open'])) {
                $redirect_args['remaining_open'] = $result['remaining_open'];
            }
            if (!empty($result['unassigned'])) {
                $redirect_args['unassigned'] = $result['unassigned'];
            }
        }
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    /** Saves the treasurer's in-progress row edits without applying them — see AVBK_DB::save_transaction_draft(). */
    public function handle_save_transaction_draft(): void {
        check_admin_referer('avbk_transaction_row');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $transaction_id = (int) ($_POST['transaction_id'] ?? 0);
        if ($transaction_id) {
            AVBK_DB::save_transaction_draft($transaction_id, $this->parse_raw_transaction_rows());
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-review', 'draft_saved' => '1'], admin_url('admin.php')));
        exit;
    }

    /** Discards a saved draft — back to the automatic suggestion on next render. */
    public function handle_clear_transaction_draft(): void {
        check_admin_referer('avbk_transaction_row');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $transaction_id = (int) ($_POST['transaction_id'] ?? 0);
        if ($transaction_id) {
            AVBK_DB::clear_transaction_draft($transaction_id);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-review', 'draft_cleared' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_ignore_transaction(): void {
        check_admin_referer('avbk_ignore_transaction');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $transaction_id = (int) ($_POST['transaction_id'] ?? 0);
        if ($transaction_id) {
            AVBK_DB::ignore_transaction($transaction_id, 'manual_review');
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-review', 'ignored' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_mark_transaction_duplicate(): void {
        check_admin_referer('avbk_mark_transaction_duplicate');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $transaction_id = (int) ($_POST['transaction_id'] ?? 0);
        $duplicate_of = (int) ($_POST['duplicate_of'] ?? 0);
        $marked = AVBK_DB::mark_transaction_duplicate($transaction_id, $duplicate_of);
        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-review',
            'duplicate_marked' => $marked ? '1' : '0',
        ], admin_url('admin.php')) . ($marked ? '' : '#tx-' . $transaction_id));
        exit;
    }

    /** Reopens an explicitly ignored incoming bank row for a fresh review. */
    public function handle_restore_ignored_transaction(): void {
        check_admin_referer('avbk_restore_ignored_transaction');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $transaction_id = (int) ($_POST['transaction_id'] ?? 0);
        $tx = $transaction_id ? AVBK_DB::get_transaction($transaction_id) : null;
        if ($tx && $tx->direction === 'in' && $tx->status === 'ignored' && empty($tx->duplicate_of)) {
            AVBK_DB::revert_transaction_to_review($transaction_id);
        }
        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-review',
            'restored' => '1',
        ], admin_url('admin.php')) . ($transaction_id ? '#tx-' . $transaction_id : ''));
        exit;
    }

    public function handle_set_transaction_activity(): void {
        check_admin_referer('avbk_set_transaction_activity');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $transaction_id = (int) ($_POST['transaction_id'] ?? 0);
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        if ($activity_id && !AVPVH_DB::get_activity($activity_id)) {
            $activity_id = 0;
        }
        $saved = $transaction_id && AVBK_DB::set_transaction_activity($transaction_id, $activity_id);
        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-transactions',
            'activity_tagged' => $saved ? '1' : '0',
            'show_all_years' => !empty($_POST['show_all_years']) ? '1' : null,
        ], admin_url('admin.php')) . ($transaction_id ? '#tx-' . $transaction_id : ''));
        exit;
    }

    /** The second, independent sign-off on an already-matched transaction — see AVBK_DB::second_approve_transaction() for the four-eyes guard against self-approval. */
    public function handle_second_approve_transaction(): void {
        check_admin_referer('avbk_second_approve_transaction');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $transaction_id = (int) ($_POST['transaction_id'] ?? 0);
        $ok = $transaction_id && AVBK_DB::second_approve_transaction($transaction_id, get_current_user_id());
        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-second-approval',
            $ok ? 'approved' : 'approve_error' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    /** A second reviewer spotting a wrong allocation — undoes it and sends the transaction back to the review queue instead of just blindly approving or having no way to fix it. */
    public function handle_revert_transaction_to_review(): void {
        check_admin_referer('avbk_revert_transaction_to_review');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $transaction_id = (int) ($_POST['transaction_id'] ?? 0);
        $show_all_years = !empty($_POST['show_all_years']);
        $closed_through_year = (int) get_option('avbk_closed_through_year', 0);
        $min_year = $show_all_years || !$closed_through_year ? 0 : $closed_through_year + 1;

        // Correcting several bad matches in a row is common (e.g. after
        // finding a systemic mismatch) — jump straight to whichever
        // transaction takes this one's place once it drops off the
        // "wachten op tweede akkoord" list (the next one, or the new last
        // one if this was the last) instead of snapping back to the top.
        $redirect_anchor = '';
        if ($transaction_id) {
            $pending_ids = array_map('intval', wp_list_pluck(AVBK_DB::get_transactions_pending_second_approval($min_year), 'id'));
            $pos = array_search($transaction_id, $pending_ids, true);

            AVBK_DB::revert_transaction_to_review($transaction_id);

            if ($pos !== false) {
                $remaining = array_values(array_diff($pending_ids, [$transaction_id]));
                if ($remaining) {
                    $redirect_anchor = '#tx-' . $remaining[min($pos, count($remaining) - 1)];
                }
            }
        }
        $args = ['page' => 'avbk-second-approval', 'reverted' => '1'];
        if ($show_all_years) {
            $args['show_all_years'] = '1';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . $redirect_anchor);
        exit;
    }

    /** Bulk variant of the single-payment correction action, guarded by an exact typed year confirmation. */
    public function handle_revert_year_payments_to_review(): void {
        check_admin_referer('avbk_revert_year_payments_to_review');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }

        $year = (int) ($_POST['payment_year'] ?? 0);
        $confirmed_year = (int) ($_POST['confirm_payment_year'] ?? 0);
        if ($year < 2000 || $year > 2100 || $confirmed_year !== $year) {
            wp_safe_redirect(add_query_arg([
                'page'        => 'avbk-overview',
                'reset_error' => '1',
            ], admin_url('admin.php')));
            exit;
        }

        $count = AVBK_DB::revert_assigned_payments_for_year($year);
        wp_safe_redirect(add_query_arg([
            'page'        => 'avbk-review',
            'reset_year'  => $year,
            'reset_count' => $count,
        ], admin_url('admin.php')));
        exit;
    }

    /** Only hides prior years from the default view (see the min_year filter in AVBK_DB::get_transactions() and the balance shortcode) — never locks or deletes anything. */
    public function handle_set_closed_through_year(): void {
        check_admin_referer('avbk_set_closed_through_year');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        update_option('avbk_closed_through_year', (int) ($_POST['closed_through_year'] ?? 0));
        wp_safe_redirect(add_query_arg(['page' => 'avbk-overview', 'year_closed' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_activity_rate(): void {
        check_admin_referer('avbk_save_activity_rate');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }

        $id = (int) ($_POST['id'] ?? 0);
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        $min_age = $_POST['min_age'] !== '' ? (int) $_POST['min_age'] : null;
        $max_age = $_POST['max_age'] !== '' ? (int) $_POST['max_age'] : null;
        $for_students = !empty($_POST['for_students']);
        $flag_id = (int) ($_POST['flag_id'] ?? 0) ?: null;
        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        // 0 is a legitimate rate (e.g. kids 0-3 free), so only activity_id gates this — not rate > 0.
        $rate = (float) str_replace(',', '.', (string) ($_POST['rate'] ?? ''));

        if ($activity_id) {
            AVBK_DB::save_activity_rate($id, $activity_id, $min_age, $max_age, $label, $rate, $for_students, $flag_id);
        }

        wp_safe_redirect(add_query_arg(['page' => 'avbk-rates', 'activity_id' => $activity_id, 'rate_saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_activity_rate(): void {
        check_admin_referer('avbk_delete_activity_rate');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        if ($id) {
            AVBK_DB::delete_activity_rate($id);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-rates', 'activity_id' => $activity_id, 'rate_deleted' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_copy_activity_rates(): void {
        check_admin_referer('avbk_copy_activity_rates');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        $source_activity_id = (int) ($_POST['source_activity_id'] ?? 0);
        $result = 'invalid';
        if ($activity_id && $source_activity_id && $activity_id !== $source_activity_id
            && AVPVH_DB::get_activity($activity_id) && AVPVH_DB::get_activity($source_activity_id)) {
            $existing = AVBK_DB::get_activity_rates($activity_id);
            $source_rates = AVBK_DB::get_activity_rates($source_activity_id);
            if ($existing) {
                $result = 'target_has_rates';
            } elseif (!$source_rates) {
                $result = 'source_empty';
            } else {
                foreach ($source_rates as $source_rate) {
                    AVBK_DB::save_activity_rate(
                        0,
                        $activity_id,
                        $source_rate->min_age === null ? null : (int) $source_rate->min_age,
                        $source_rate->max_age === null ? null : (int) $source_rate->max_age,
                        (string) $source_rate->label,
                        (float) $source_rate->rate,
                        !empty($source_rate->for_students),
                        $source_rate->flag_id === null ? null : (int) $source_rate->flag_id
                    );
                }
                $result = 'copied';
            }
        }
        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-rates',
            'activity_id' => $activity_id,
            'rates_copy' => $result,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Manual trigger for the daily cron job — lets the treasurer apply a
     * just-entered rate table immediately instead of waiting for the next
     * 03:00 run.
     */
    public function handle_generate_contribution_fees_now(): void {
        check_admin_referer('avbk_generate_contribution_fees_now');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $year = (int) ($_POST['year'] ?? current_time('Y'));
        AVBK_Fee_Generation::generate_contribution_fees($year);
        wp_safe_redirect(add_query_arg(['page' => 'avbk-rates', 'year' => $year, 'contribution_fees_generated' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * Backfills camp fee items for every existing participation record of
     * this activity — needed because the live save hook only fires on a
     * *new* save, so participation entered before a rate existed never
     * generated one on its own.
     */
    public function handle_generate_camp_fees_now(): void {
        check_admin_referer('avbk_generate_camp_fees_now');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        $count = $activity_id ? AVBK_Fee_Generation::generate_camp_fees($activity_id) : 0;
        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-rates', 'activity_id' => $activity_id, 'camp_fees_generated' => $count,
        ], admin_url('admin.php')));
        exit;
    }

    /** Saves the penningmeester's/form designer's agreed column layout for one activity's Google Form sheet — see AVBK_Sheet_Import's own docblock for the config shape. */
    /** Saved separately from price/slots below so the link field, "Ververs" button and Excel-upload can all sit together at the top of the page as one "bron" group. */
    public function handle_save_sheet_url(): void {
        check_admin_referer('avbk_save_sheet_url');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        if ($this->is_camp_activity($activity_id)) {
            wp_die('Voor een kamp wordt de speciale kampimport gebruikt; een generieke sheet-link is niet van toepassing.', 400);
        }
        if ($activity_id) {
            $config = AVBK_Sheet_Import::get_config($activity_id);
            $old_header_row = max(1, (int) $config['header_row']);
            $config['sheet_url'] = esc_url_raw(wp_unslash($_POST['sheet_url'] ?? ''));
            $config['header_row'] = max(1, (int) ($_POST['header_row'] ?? 1));
            $config['last_data_row'] = max(0, (int) ($_POST['last_data_row'] ?? ($config['last_data_row'] ?? 0)));
            $posted_match_activity_id = (int) ($_POST['match_activity_id'] ?? ($config['match_activity_id'] ?? 0));
            $config['match_activity_id'] = $posted_match_activity_id > 0 && AVPVH_DB::get_activity($posted_match_activity_id)
                ? $posted_match_activity_id
                : 0;
            if ($config['header_row'] !== $old_header_row) {
                $config['header_cache'] = [];
            }
            $posted_candidates = json_decode(wp_unslash($_POST['preview_header_candidates'] ?? ''), true);
            $preview_headers = [];
            if (is_array($posted_candidates) && isset($posted_candidates[$config['header_row']]) && is_array($posted_candidates[$config['header_row']])) {
                foreach ($posted_candidates[$config['header_row']] as $letter => $heading) {
                    $letter = strtoupper(sanitize_text_field((string) $letter));
                    if (preg_match('/^[A-Z]+$/', $letter)) {
                        $preview_headers[$letter] = sanitize_text_field((string) $heading);
                    }
                }
            }
            if ($config['sheet_url'] !== '') {
                $headers_result = AVBK_Sheet_Import::fetch_headers($config['sheet_url'], $config['header_row']);
                if (!$headers_result['error']) {
                    $config['header_cache'] = $headers_result['headers'];
                    if (empty($config['timestamp_column'])) {
                        foreach ($headers_result['headers'] as $letter => $heading) {
                            if (in_array(strtolower(remove_accents(trim($heading))), ['timestamp', 'tijdstempel'], true)) {
                                $config['timestamp_column'] = $letter;
                                break;
                            }
                        }
                    }
                }
            } elseif ($preview_headers) {
                // A one-off Excel upload no longer exists after the request.
                // Rebuild its headings from the three preview rows posted by
                // the protected admin form instead of retaining the old row.
                $config['header_cache'] = $preview_headers;
                if (empty($config['timestamp_column'])) {
                    foreach ($preview_headers as $letter => $heading) {
                        if (in_array(strtolower(remove_accents(trim($heading))), ['timestamp', 'tijdstempel'], true)) {
                            $config['timestamp_column'] = $letter;
                            break;
                        }
                    }
                }
            }
            AVBK_Sheet_Import::save_config($activity_id, $config);
            if (!empty($_POST['refresh_after_save'])) {
                $result = AVBK_Sheet_Import::import($activity_id);
                set_transient(AVBK_Sheet_Import::result_transient_key($activity_id), $result, 12 * HOUR_IN_SECONDS);
                wp_safe_redirect(add_query_arg([
                    'page' => 'avbk-activity-payments',
                    'activity_id' => $activity_id,
                    'config_saved' => '1',
                    'imported' => '1',
                ], admin_url('admin.php')));
                exit;
            }
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-activity-payments', 'activity_id' => $activity_id, 'config_saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_sheet_import_config(): void {
        check_admin_referer('avbk_save_sheet_import_config');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        if (!$activity_id) {
            wp_safe_redirect(add_query_arg(['page' => 'avbk-activity-payments'], admin_url('admin.php')));
            exit;
        }
        if ($this->is_camp_activity($activity_id)) {
            wp_die('Voor een kamp is geen generieke kolomindeling nodig.', 400);
        }
        $config = AVBK_Sheet_Import::get_config($activity_id);
        $config['timestamp_format'] = ($_POST['timestamp_format'] ?? '') === 'dmy' ? 'dmy' : 'mdy';
        $posted_header_row = max(1, (int) ($_POST['header_row'] ?? $config['header_row']));
        $config['last_data_row'] = max(0, (int) ($_POST['last_data_row'] ?? ($config['last_data_row'] ?? 0)));
        $posted_candidates = json_decode(wp_unslash($_POST['preview_header_candidates'] ?? ''), true);
        if ($posted_header_row !== (int) $config['header_row']) {
            $config['header_row'] = $posted_header_row;
            if (is_array($posted_candidates) && isset($posted_candidates[$posted_header_row]) && is_array($posted_candidates[$posted_header_row])) {
                $config['header_cache'] = [];
                foreach ($posted_candidates[$posted_header_row] as $letter => $heading) {
                    $letter = strtoupper(sanitize_text_field((string) $letter));
                    if (preg_match('/^[A-Z]+$/', $letter)) {
                        $config['header_cache'][$letter] = sanitize_text_field((string) $heading);
                    }
                }
            }
        }
        $slots = [];
        for ($i = 0; $i < AVBK_Sheet_Import::MAX_SLOTS; $i++) {
            $slots[] = [
                'name'       => sanitize_text_field(wp_unslash($_POST['slot_name'][$i] ?? '')),
                'email'      => sanitize_text_field(wp_unslash($_POST['slot_email'][$i] ?? '')),
                'diet'       => sanitize_text_field(wp_unslash($_POST['slot_diet'][$i] ?? '')),
                'notes'      => sanitize_text_field(wp_unslash($_POST['slot_notes'][$i] ?? '')),
                'amount'     => sanitize_text_field(wp_unslash($_POST['slot_amount'][$i] ?? '')),
                'newsletter' => sanitize_text_field(wp_unslash($_POST['slot_newsletter'][$i] ?? '')),
            ];
        }
        // Loaded (not overwritten from scratch) so sheet_url/header_cache — saved/updated elsewhere — survive a price/slots save.
        $config['price_per_person'] = (float) str_replace(',', '.', (string) ($_POST['price_per_person'] ?? '0'));
        $config['timestamp_column'] = sanitize_text_field(wp_unslash($_POST['timestamp_column'] ?? ''));
        $match_activity_id = (int) ($_POST['match_activity_id'] ?? 0);
        $config['match_activity_id'] = $match_activity_id > 0 && AVPVH_DB::get_activity($match_activity_id)
            ? $match_activity_id
            : 0;
        $config['slots'] = $slots;
        AVBK_Sheet_Import::save_config($activity_id, $config);
        $redirect_args = ['page' => 'avbk-activity-payments', 'activity_id' => $activity_id, 'config_saved' => '1'];
        if (trim((string) $config['sheet_url']) !== '') {
            $result = AVBK_Sheet_Import::import($activity_id);
            set_transient(AVBK_Sheet_Import::result_transient_key($activity_id), $result, 12 * HOUR_IN_SECONDS);
            $redirect_args['imported'] = '1';
        }
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')) . (isset($redirect_args['imported']) ? '#avbk-unmatched' : ''));
        exit;
    }

    /** Re-fetches the configured Google Form response sheet for one activity and turns every recognizable attendee into a participation + event fee item — see AVBK_Sheet_Import for why unmatched people are never auto-created. */
    public function handle_sheet_import(): void {
        check_admin_referer('avbk_sheet_import');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        if (!$activity_id) {
            $result = ['matched' => [], 'unmatched' => [], 'errors' => ['Geen activiteit gekozen.']];
        } elseif ($this->is_camp_activity($activity_id)) {
            $result = ['matched' => [], 'unmatched' => [], 'errors' => ['Upload voor een kamp het speciale .xlsx-kampbestand.']];
        } else {
            $result = AVBK_Sheet_Import::import($activity_id);
        }
        set_transient(AVBK_Sheet_Import::result_transient_key($activity_id), $result, 12 * HOUR_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page' => 'avbk-activity-payments', 'activity_id' => $activity_id, 'imported' => '1'], admin_url('admin.php')));
        exit;
    }

    /** Upload route: fixed camp parser for Kamp, configurable parser for every other activity type. */
    public function handle_sheet_import_upload(): void {
        check_admin_referer('avbk_sheet_import_upload');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        $file = $_FILES['sheet_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as a PHP upload; cell values are sanitized by the selected importer
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $result = ['matched' => [], 'unmatched' => [], 'errors' => ['Geen bestand geüpload.']];
        } elseif (strtolower(pathinfo(sanitize_file_name((string) ($file['name'] ?? '')), PATHINFO_EXTENSION)) !== 'xlsx') {
            $result = ['matched' => [], 'unmatched' => [], 'errors' => ['Gebruik een .xlsx-bestand. Sla een oud .xls-bestand eerst op als .xlsx.']];
        } elseif ((int) ($file['size'] ?? 0) <= 0 || (int) ($file['size'] ?? 0) > 20 * MB_IN_BYTES) {
            $result = ['matched' => [], 'unmatched' => [], 'errors' => ['Het xlsx-bestand is leeg of groter dan 20 MB.']];
        } else {
            // Never moved into wp-content/uploads — read once from PHP's own
            // private upload tmp path, same as the bank-export upload.
            if (!$activity_id) {
                $result = ['matched' => [], 'unmatched' => [], 'errors' => ['Geen activiteit gekozen.']];
            } elseif ($this->is_camp_activity($activity_id)) {
                $result = AVBK_Camp_Sheet_Import::import($activity_id, (string) $file['tmp_name']);
            } else {
                $result = AVBK_Sheet_Import::import($activity_id, (string) $file['tmp_name']);
            }
        }
        set_transient(AVBK_Sheet_Import::result_transient_key($activity_id), $result, 12 * HOUR_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page' => 'avbk-activity-payments', 'activity_id' => $activity_id, 'imported' => '1'], admin_url('admin.php')));
        exit;
    }

    /** Camp-sheet counterpart of handle_sheet_import_upload() for a Google Sheets link instead of an uploaded file — see AVBK_Camp_Sheet_Import::import_from_url(). */
    public function handle_camp_sheet_import_from_url(): void {
        check_admin_referer('avbk_camp_sheet_import_from_url');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        $sheet_url = esc_url_raw(wp_unslash($_POST['camp_sheet_url'] ?? ''));
        if (!$activity_id) {
            $result = ['matched' => [], 'unmatched' => [], 'errors' => ['Geen activiteit gekozen.']];
        } elseif ($sheet_url === '') {
            $result = ['matched' => [], 'unmatched' => [], 'errors' => ['Geen link ingevuld.']];
        } elseif (!$this->is_camp_activity($activity_id)) {
            $result = ['matched' => [], 'unmatched' => [], 'errors' => ['Deze link-import is alleen beschikbaar voor een activiteit van het type Kamp.']];
        } else {
            $result = AVBK_Camp_Sheet_Import::import_from_url($activity_id, $sheet_url);
        }
        set_transient(AVBK_Sheet_Import::result_transient_key($activity_id), $result, 12 * HOUR_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page' => 'avbk-activity-payments', 'activity_id' => $activity_id, 'imported' => '1'], admin_url('admin.php')));
        exit;
    }

    /** The penningmeester manually linking one sheet attendee that didn't auto-match to an existing (incl. inactive/oud-lid) member, after creating that member via AV-PvH Leden first if needed. */
    public function handle_sheet_import_link_attendee(): void {
        check_admin_referer('avbk_sheet_import_link_attendee');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        $email_added = null;
        $linked = false;
        if ($member_id && $activity_id && AVPVH_DB::get_member($member_id)) {
            $is_camp = $this->is_camp_activity($activity_id);
            $source_name = sanitize_text_field(wp_unslash($_POST['source_name'] ?? ''));
            $source_email = sanitize_text_field(wp_unslash($_POST['source_email'] ?? ''));
            AVBK_Sheet_Import::remember_match($activity_id, $source_name, $source_email, $member_id);
            if (!empty($_POST['add_source_email'])) {
                $email = sanitize_email($source_email);
                $email_added = $email !== '' && AVPVH_DB::ensure_identity($member_id, 'email', $email);
            }
            $camp_days = [];
            if ($is_camp) {
                $posted_days = json_decode(wp_unslash($_POST['camp_days'] ?? ''), true);
                foreach (is_array($posted_days) ? $posted_days : [] as $date => $status) {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                        $camp_days[(string) $date] = substr(sanitize_text_field((string) $status), 0, 10);
                    }
                }
            }
            $participation_id = AVPVH_DB::save_participation($member_id, $activity_id, [
                'nights'  => $is_camp ? count(array_filter($camp_days, fn($status) => strtolower($status) === 'n')) : null,
                'nawacht' => $is_camp && !empty($_POST['camp_nawacht']),
                'diet'    => sanitize_textarea_field(wp_unslash($_POST['allergies'] ?? '')),
                'notes'   => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
            ]);
            if ($is_camp) {
                AVPVH_DB::save_participation_days($participation_id, $camp_days);
            } else {
                AVBK_DB::save_sheet_participation_meta(
                    $activity_id,
                    $member_id,
                    sanitize_text_field(wp_unslash($_POST['registered_at'] ?? '')) ?: null,
                    sanitize_text_field(wp_unslash($_POST['source_timestamp'] ?? ''))
                );
                $config = AVBK_Sheet_Import::get_config($activity_id);
                $row_amount = (float) ($_POST['amount'] ?? 0);
                $amount = $row_amount > 0
                    ? $row_amount
                    : AVBK_Fee_Generation::event_price_for_member($member_id, $activity_id, (float) $config['price_per_person']);
                if ($amount > 0) {
                    AVBK_DB::upsert_event_fee_item($member_id, AVPVH_DB::get_activity($activity_id)->name ?? 'Activiteit', $amount, $activity_id);
                }
            }
            if (isset($_POST['newsletter'])) {
                AVPVH_DB::set_member_flag_by_slug($member_id, 'nieuwsbrief', $_POST['newsletter'] === '1');
            }
            $linked = true;
            $this->remove_sheet_review_entry($activity_id, $source_name, $source_email, sanitize_text_field(wp_unslash($_POST['source_timestamp'] ?? '')));
        }
        $redirect_args = ['page' => 'avbk-activity-payments', 'activity_id' => $activity_id, 'linked' => '1'];
        if ($email_added !== null) {
            $redirect_args[$email_added ? 'email_added' : 'email_add_failed'] = '1';
        }
        $redirect_url = add_query_arg($redirect_args, admin_url('admin.php'));
        wp_safe_redirect($redirect_url . ($linked ? '#avbk-unmatched' : ''));
        exit;
    }

    /** Marks a source value such as "Totaal" as not being a participant. */
    public function handle_sheet_import_ignore_attendee(): void {
        check_admin_referer('avbk_sheet_import_ignore_attendee');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        $source_name = sanitize_text_field(wp_unslash($_POST['source_name'] ?? ''));
        $source_email = sanitize_text_field(wp_unslash($_POST['source_email'] ?? ''));
        $source_timestamp = sanitize_text_field(wp_unslash($_POST['source_timestamp'] ?? ''));
        if ($activity_id > 0 && ($source_name !== '' || $source_email !== '')) {
            AVBK_Sheet_Import::ignore_source_identity($activity_id, $source_name, $source_email);
            $this->remove_sheet_review_entry($activity_id, $source_name, $source_email, $source_timestamp);
        }
        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-activity-payments',
            'activity_id' => $activity_id,
            'source_ignored' => '1',
        ], admin_url('admin.php')) . '#avbk-unmatched');
        exit;
    }

    /**
     * Explicit override for a diet/notes conflict AVBK_Sheet_Import::import()
     * held back (see resolve_participation_fields()) — applies the sheet's
     * value the treasurer chose to accept and advances the snapshot for
     * just that one field, so it stops being flagged on later imports.
     */
    public function handle_sheet_import_apply_sheet_value(): void {
        check_admin_referer('avbk_sheet_import_apply_sheet_value');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $field = sanitize_key(wp_unslash($_POST['field'] ?? ''));
        $value = sanitize_textarea_field(wp_unslash($_POST['value'] ?? ''));
        if ($activity_id > 0 && $member_id > 0 && in_array($field, ['diet', 'notes'], true)) {
            $current = AVPVH_DB::get_participation($member_id, $activity_id);
            if ($current) {
                AVPVH_DB::save_participation($member_id, $activity_id, [
                    'nights'  => $current->nights,
                    'nawacht' => $current->nawacht,
                    'diet'    => $field === 'diet' ? $value : $current->diet,
                    'notes'   => $field === 'notes' ? $value : $current->notes,
                ]);
                AVBK_DB::update_sheet_participation_snapshot(
                    $activity_id,
                    $member_id,
                    $field === 'diet' ? $value : null,
                    $field === 'notes' ? $value : null
                );
                $this->remove_sheet_import_conflict($activity_id, $member_id, $field);
            }
        }
        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-activity-payments',
            'activity_id' => $activity_id,
        ], admin_url('admin.php')) . '#avbk-conflicts');
        exit;
    }

    /** Removes exactly one resolved conflict entry from the stored import result, retaining the rest. */
    private function remove_sheet_import_conflict(int $activity_id, int $member_id, string $field): void {
        $result_key = AVBK_Sheet_Import::result_transient_key($activity_id);
        $result = get_transient($result_key);
        if (!is_array($result) || empty($result['conflicts']) || !is_array($result['conflicts'])) {
            return;
        }
        foreach ($result['conflicts'] as $index => $conflict) {
            if ((int) ($conflict['member_id'] ?? 0) === $member_id && ($conflict['field'] ?? '') === $field) {
                unset($result['conflicts'][$index]);
            }
        }
        $result['conflicts'] = array_values($result['conflicts']);
        set_transient($result_key, $result, 12 * HOUR_IN_SECONDS);
    }

    /** Removes exactly one reviewed source person while retaining the rest. */
    private function remove_sheet_review_entry(int $activity_id, string $source_name, string $source_email, string $source_timestamp): void {
        $result_key = AVBK_Sheet_Import::result_transient_key($activity_id);
        $result = get_transient($result_key);
        if (!is_array($result) || empty($result['unmatched']) || !is_array($result['unmatched'])) {
            return;
        }
        foreach ($result['unmatched'] as $index => $unmatched) {
            if (
                (string) ($unmatched['name'] ?? '') === $source_name
                && (string) ($unmatched['email'] ?? '') === $source_email
                && (string) ($unmatched['source_timestamp'] ?? '') === $source_timestamp
            ) {
                unset($result['unmatched'][$index]);
                break;
            }
        }
        $result['unmatched'] = array_values($result['unmatched']);
        if ($result['unmatched']) {
            set_transient($result_key, $result, 12 * HOUR_IN_SECONDS);
        } else {
            delete_transient($result_key);
        }
    }

    /**
     * "Vraag om betaling" — one participant, one activity: e-mails that
     * member a short personal request (namens de penningmeester) naming the
     * specific amount + activity, with the exact fee-item QR embedded in the
     * HTML mail and a fallback link to the live balance page.
     */
    /** Marks where handle_request_payment()/handle_preview_request_payment_email() splice in the optional free-text note. */
    private const EXTRA_MESSAGE_PLACEHOLDER = '{{AVBK_EXTRA_MESSAGE}}';
    /** Marks where the betaalgegevens (IBAN/naam/omschrijving) block goes — replaceable so the penningmeester can swap in different text. */
    private const DETAILS_PLACEHOLDER = '{{AVBK_DETAILS_BLOCK}}';
    /** Marks where the QR caption+image goes as one unit — replaceable so a QR copied from elsewhere (e.g. an ING Betaalverzoek, or a generic scan-your-own-amount QR) can be pasted in instead. */
    private const QR_PLACEHOLDER = '{{AVBK_QR_BLOCK}}';

    /**
     * Subject + body template shared by the real send (handle_request_
     * payment()) and its preview (handle_preview_request_payment_email(),
     * which live-updates this in JS as the treasurer types/edits) — one
     * source of truth so what gets previewed can't drift from what
     * actually gets sent. The extra-message, betaalgegevens and QR blocks
     * are all left as placeholder tokens rather than filled in here: the
     * preview needs to keep swapping them live without a server round
     * trip, and the two default blocks are returned separately so both
     * callers can fall back to them when nothing was overridden.
     * $qr_image_html is passed in rather than built here because the two
     * callers embed the QR differently (send: PNG via cid:, preview:
     * inline SVG — nothing to download in a browser tab), null meaning
     * generation failed.
     */
    private function payment_request_email_template(object $member, object $activity, object $fee_item, float $remaining, ?string $qr_image_html): array {
        $penningmeester_name = get_option('avbk_penningmeester_name', 'de penningmeester');
        $balance_url = home_url('/leden/beheer/member-profile/');
        $login_url = wp_login_url($balance_url);
        $login_help = '';
        if (get_option('avbk_payment_email_login_help', 1)) {
            $login_text = (string) get_option('avbk_payment_email_login_text', '') ?: self::DEFAULT_PAYMENT_EMAIL_LOGIN_TEXT;
            $login_help = wpautop(str_replace(
                '[wachtwoord-link]',
                '<a href="' . esc_url($login_url) . '">hier</a>',
                esc_html($login_text)
            ));
        }
        $subject = sprintf('Openstaande betaling — %s', $activity->name);
        $qr_caption = (string) get_option('avbk_qr_caption_text', '') ?: self::DEFAULT_QR_CAPTION_TEXT;
        // Same remittance text embedded in the QR itself — see
        // AVBK_QR::remittance_for_fee_item() — so a scanned QR and a
        // manually-typed omschrijving read identically; it's not a
        // separate "kenmerk" field banks have, just the ordinary vrije
        // omschrijving.
        $reference_code = AVBK_QR::remittance_for_fee_item((int) $member->id, $fee_item);
        $club_iban = trim((string) get_option('avbk_club_iban', ''));
        $club_iban_display = $club_iban ? trim(chunk_split($club_iban, 4, ' ')) : '';
        $club_name = trim((string) get_option('avbk_club_name', 'Archeologische Vereniging Philips van Horne'));
        $default_details_html = '<p>Doe je de betaling zelf? Gebruik dan de volgende gegevens:<br>'
            . ($club_iban ? 'IBAN: <code style="font-size:1.3em">' . esc_html($club_iban_display) . '</code><br>' : '')
            . 'Ten name van: ' . esc_html($club_name) . '<br>'
            . 'Omschrijving: <code style="font-size:1.3em">' . esc_html($reference_code) . '</code>'
            . '</p>';
        $default_qr_html = $qr_image_html !== null
            ? '<p>' . nl2br(esc_html($qr_caption)) . '</p>' . $qr_image_html
            : '<p>De QR-code kon niet worden gegenereerd; gebruik de link hieronder.</p>';
        $body_template = sprintf(
            '<!doctype html><html><body><p>Dag %s,</p><p>Zou je de volgende rekening willen betalen?</p><p><strong>%s: € %s</strong></p>%s%s%s%s<p>Groet,<br>%s</p></body></html>',
            esc_html($member->first_name),
            esc_html($activity->name),
            esc_html(number_format($remaining, 2, ',', '.')),
            self::EXTRA_MESSAGE_PLACEHOLDER,
            self::DETAILS_PLACEHOLDER,
            self::QR_PLACEHOLDER,
            $login_help,
            esc_html($penningmeester_name)
        );
        return [$subject, $body_template, $default_details_html, $default_qr_html];
    }

    public function handle_request_payment(): void {
        check_admin_referer('avbk_request_payment');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        $extra_message = trim(sanitize_textarea_field(wp_unslash($_POST['extra_message'] ?? '')));
        // Override from the "Vraag om betaling en voeg nog iets toe"
        // preview page: the penningmeester can clear the QR block there and
        // type/paste a replacement (e.g. an ING Betaalverzoek QR) — see
        // handle_preview_request_payment_email().
        $custom_qr_active = !empty($_POST['custom_qr_active']);
        $custom_qr_html = $custom_qr_active ? wp_kses_post(wp_unslash($_POST['custom_qr_html'] ?? '')) : '';
        $custom_qr_image_data = $custom_qr_active ? (string) ($_POST['custom_qr_image_data'] ?? '') : '';
        $custom_qr_image_mime = $custom_qr_active ? (string) ($_POST['custom_qr_image_mime'] ?? '') : '';
        $redirect = fn(string $flag) => wp_safe_redirect(add_query_arg(['page' => 'avbk-activity-payments', 'activity_id' => $activity_id, $flag => '1'], admin_url('admin.php')));

        $member = $member_id ? AVPVH_DB::get_member($member_id) : null;
        $activity = $activity_id ? AVPVH_DB::get_activity($activity_id) : null;
        $fee_item = ($member && $activity) ? AVBK_DB::get_fee_item_for_member_activity($member_id, $activity_id) : null;
        if (!$member || !$activity || !$fee_item) {
            $redirect('payment_request_failed');
            exit;
        }
        $remaining = AVBK_DB::get_fee_item_remaining($fee_item);
        if ($remaining <= 0.005 || !is_email($member->email)) {
            $redirect('payment_request_failed');
            exit;
        }

        // Skip generating the default QR entirely when a pasted one will
        // replace it anyway — payment_request_email_template() still needs
        // a value for its (unused, in that case) default QR block.
        $qr_png = $custom_qr_active ? null : AVBK_QR::png_for_fee_item($member_id, $fee_item);
        $qr_cid = 'avbk-payment-qr-' . $fee_item->id . '-' . wp_generate_password(8, false, false) . '@avpvh.nl';
        // Alt text (what shows if the embedded image doesn't render) names
        // the specific activity, not a generic "QR-code voor betaling" —
        // the reader should know what payment this is for even then.
        $qr_alt = sprintf('QR-code voor betaling %s', $activity->name);
        $qr_image_html = $qr_png
            ? '<div style="background:#fff;padding:12px;display:inline-block"><img src="cid:' . esc_attr($qr_cid) . '" width="360" height="360" alt="' . esc_attr($qr_alt) . '"></div>'
            : null;
        [$subject, $body_template, $default_details_html, $default_qr_html] = $this->payment_request_email_template($member, $activity, $fee_item, $remaining, $qr_image_html);

        // A pasted replacement QR (e.g. an ING Betaalverzoek, or a generic
        // scan-your-own-amount QR) gets embedded as its own cid: image,
        // same mechanism as the generated one — a data: URI would just get
        // stripped by most mail clients' HTML sanitizers.
        $custom_qr_cid = null;
        $decoded_qr_image = null;
        if ($custom_qr_active && $custom_qr_image_data !== '' && in_array($custom_qr_image_mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            $decoded_qr_image = base64_decode($custom_qr_image_data, true);
            if ($decoded_qr_image !== false && strlen($decoded_qr_image) > 0 && strlen($decoded_qr_image) < 3 * 1024 * 1024) {
                $custom_qr_cid = 'avbk-custom-qr-' . wp_generate_password(8, false, false) . '@avpvh.nl';
            } else {
                $decoded_qr_image = null;
            }
        }
        $custom_qr_image_tag = $custom_qr_cid
            ? '<div style="background:#fff;padding:12px;display:inline-block"><img src="cid:' . esc_attr($custom_qr_cid) . '" style="max-width:360px;width:100%;height:auto" alt="QR-code"></div>'
            : '';

        // Free-text note from the "... en voeg nog iets toe" form, placed
        // right after the amount and before the payment details/QR — the
        // treasurer's own words belong with the personal part of the mail,
        // not buried below the boilerplate.
        $extra_message_block = $extra_message !== '' ? '<p>' . nl2br(esc_html($extra_message)) . '</p>' : '';
        $qr_block = $custom_qr_active ? ($custom_qr_image_tag . $custom_qr_html) : $default_qr_html;
        $body = strtr($body_template, [
            self::EXTRA_MESSAGE_PLACEHOLDER => $extra_message_block,
            self::DETAILS_PLACEHOLDER => $default_details_html,
            self::QR_PLACEHOLDER => $qr_block,
        ]);
        $from_email = sanitize_email(get_option('avbk_penningmeester_email', 'penningmeester@avphilipsvanhorne.nl'));
        if (!is_email($from_email)) {
            $from_email = 'penningmeester@avphilipsvanhorne.nl';
        }
        $embed_qr = static function ($phpmailer) use ($qr_png, $qr_cid, $decoded_qr_image, $custom_qr_cid, $custom_qr_image_mime): void {
            if ($qr_png && is_object($phpmailer) && method_exists($phpmailer, 'addStringEmbeddedImage')) {
                $phpmailer->addStringEmbeddedImage($qr_png, $qr_cid, 'betaling-qr.png', 'base64', 'image/png');
            }
            if ($decoded_qr_image && $custom_qr_cid && is_object($phpmailer) && method_exists($phpmailer, 'addStringEmbeddedImage')) {
                $phpmailer->addStringEmbeddedImage($decoded_qr_image, $custom_qr_cid, 'qr', 'base64', $custom_qr_image_mime);
            }
        };
        // Some SMTP plugins replace the From header during phpmailer_init.
        // Run last so the visible sender remains the configured treasurer,
        // while the envelope can still be handled by the site's SMTP relay.
        $force_sender = static function ($phpmailer) use ($from_email): void {
            if (!is_object($phpmailer) || !method_exists($phpmailer, 'setFrom')) {
                return;
            }
            try {
                $phpmailer->setFrom($from_email, 'AV-PvH Penningmeester', false);
            } catch (\Throwable $e) {
                // Keep the mail send alive if a relay rejects the address.
            }
        };
        if ($qr_png || $decoded_qr_image) {
            add_action('phpmailer_init', $embed_qr);
        }
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: AV-PvH Penningmeester <' . $from_email . '>',
            'Reply-To: ' . $from_email,
        ];
        $force_from = static fn($current) => $from_email;
        $force_from_name = static fn($current) => 'AV-PvH Penningmeester';
        add_filter('wp_mail_from', $force_from, PHP_INT_MAX);
        add_filter('wp_mail_from_name', $force_from_name, PHP_INT_MAX);
        add_action('phpmailer_init', $force_sender, PHP_INT_MAX);
        $sent = wp_mail($member->email, $subject, $body, $headers);
        remove_action('phpmailer_init', $force_sender, PHP_INT_MAX);
        remove_filter('wp_mail_from', $force_from, PHP_INT_MAX);
        remove_filter('wp_mail_from_name', $force_from_name, PHP_INT_MAX);
        if ($qr_png || $decoded_qr_image) {
            remove_action('phpmailer_init', $embed_qr);
        }

        if ($sent) {
            AVBK_DB::log_payment_request((int) $fee_item->id, $member_id, $activity_id, $member->email);
        }

        $redirect($sent ? 'payment_requested' : 'payment_request_failed');
        exit;
    }

    /**
     * Standalone preview of the "Vraag om betaling" e-mail — full subject +
     * body in an iframe, with a textarea that live-updates it in the
     * browser (no server round trip) as the treasurer adds a note, and a
     * "Verstuur" button at the bottom that posts straight to
     * handle_request_payment() with that same text.
     */
    public function handle_preview_request_payment_email(): void {
        check_admin_referer('avbk_preview_request_payment_email');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $member_id = (int) ($_GET['member_id'] ?? 0);
        $activity_id = (int) ($_GET['activity_id'] ?? 0);
        $member = $member_id ? AVPVH_DB::get_member($member_id) : null;
        $activity = $activity_id ? AVPVH_DB::get_activity($activity_id) : null;
        $fee_item = ($member && $activity) ? AVBK_DB::get_fee_item_for_member_activity($member_id, $activity_id) : null;
        if (!$member || !$activity || !$fee_item) {
            wp_die('Niet gevonden.', 404);
        }
        $remaining = AVBK_DB::get_fee_item_remaining($fee_item);
        if ($remaining <= 0.005) {
            wp_die('Deze rekening is al betaald.', 200);
        }

        // The activity's own generic betaalverzoek (link/QR, see
        // AVBK_DB::get_activity_payment_link()) — set up once, shared by
        // everyone attending, and only pulled into a specific mail here on
        // request, never automatically.
        $payment_link = AVBK_DB::get_activity_payment_link($activity_id);
        $generic_payment_url = $payment_link->payment_url ?? '';
        $generic_qr_data_url = !empty($payment_link->qr_image)
            ? 'data:' . $payment_link->qr_image_mime . ';base64,' . base64_encode($payment_link->qr_image)
            : '';

        $qr_svg = AVBK_QR::for_fee_item($member_id, $fee_item);
        // The <style> here is not redundant with the outer page's own
        // stylesheet: this markup ends up inside the iframe's srcdoc, a
        // separate HTML document with no <style> of its own, so without
        // this the raw <svg> renders at whatever tiny/default intrinsic
        // size it carries instead of filling its wrapper.
        $qr_image_html = $qr_svg
            ? '<div style="background:#fff;padding:12px;display:inline-block;max-width:360px"><style>svg{width:100%;height:auto;display:block}</style>' . $qr_svg . '</div>'
            : null;
        [$subject, $body_template, $default_details_html, $default_qr_html] = $this->payment_request_email_template($member, $activity, $fee_item, $remaining, $qr_image_html);
        // Filled once on load: {{...}} tokens become fixed <div id="..."> wrappers
        // holding the default content, so every later edit (typing the extra
        // note, "verwijderen" + paste for the QR) mutates that already-
        // loaded iframe document directly instead of reassigning srcdoc —
        // reassigning would reload the iframe from scratch and wipe out
        // whatever the treasurer had just pasted. The betaalgegevens block
        // has no editable wrapper — it's not editable here, just shown.
        $initial_html = strtr($body_template, [
            self::EXTRA_MESSAGE_PLACEHOLDER => '<div id="avbk-mail-extra"></div>',
            self::DETAILS_PLACEHOLDER => $default_details_html,
            self::QR_PLACEHOLDER => '<div id="avbk-mail-qr">' . $default_qr_html . '</div>',
        ]);

        header('Content-Type: text/html; charset=UTF-8');
        ?>
        <!doctype html>
        <html lang="nl">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Voorbeeld &mdash; Betaalverzoek <?php echo esc_html($activity->name); ?></title>
        <style>
            body { font-family: -apple-system, sans-serif; max-width: 720px; margin: 1.5rem auto; padding: 0 1rem 2rem; }
            .avbk-preview-subject { color: #555; margin-bottom: .5rem; }
            #avbk-preview-frame { width: 100%; height: 560px; border: 1px solid #ccc; background: #fff; }
            textarea { width: 100%; box-sizing: border-box; font-family: inherit; }
            .avbk-preview-edit-buttons { margin: .5rem 0 1.25rem; }
        </style>
        </head>
        <body>
            <p class="avbk-preview-subject">Onderwerp: <strong><?php echo esc_html($subject); ?></strong></p>
            <iframe id="avbk-preview-frame" title="Voorbeeld e-mail"></iframe>
            <p class="avbk-preview-edit-buttons">
                <button type="button" id="avbk-clear-qr" class="button button-small">QR-code verwijderen</button>
                <?php if ($generic_qr_data_url !== '') : ?>
                    <button type="button" id="avbk-use-generic-qr" class="button button-small">Generieke QR-code gebruiken</button>
                <?php endif; ?>
                <?php if ($generic_payment_url !== '') : ?>
                    <button type="button" id="avbk-add-generic-link" class="button button-small">Betaalverzoeklink toevoegen</button>
                <?php endif; ?>
            </p>
            <p class="description">Na "verwijderen" kun je in de mail zelf klikken en typen, of een QR-code/afbeelding van elders plakken (Ctrl+V) om die te vervangen — bijvoorbeeld een ING Betaalverzoek of een generieke QR-code waarmee de betaler zelf het bedrag kiest.<?php if ($generic_qr_data_url !== '' || $generic_payment_url !== '') : ?> "Generieke QR-code gebruiken"/"Betaalverzoeklink toevoegen" halen wat bij deze activiteit is opgeslagen (zie de activiteit-betalingenpagina) direct in deze mail.<?php endif; ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="avbk-send-form">
                <?php wp_nonce_field('avbk_request_payment'); ?>
                <input type="hidden" name="action" value="avbk_request_payment">
                <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                <input type="hidden" name="custom_qr_active" id="avbk-custom-qr-active" value="">
                <input type="hidden" name="custom_qr_html" id="avbk-custom-qr-html" value="">
                <input type="hidden" name="custom_qr_image_data" id="avbk-custom-qr-image-data" value="">
                <input type="hidden" name="custom_qr_image_mime" id="avbk-custom-qr-image-mime" value="">
                <p>
                    <label for="avbk-extra-message">Extra tekst (optioneel, verschijnt boven de betaalgegevens):</label><br>
                    <textarea name="extra_message" id="avbk-extra-message" rows="4"></textarea>
                </p>
                <?php submit_button('Verstuur', 'primary', 'submit', false); ?>
            </form>
            <script>
            (function () {
                var initialHtml = <?php echo wp_json_encode($initial_html); ?>;
                var defaultQrHtml = <?php echo wp_json_encode($default_qr_html); ?>;
                var genericQrDataUrl = <?php echo wp_json_encode($generic_qr_data_url); ?>;
                var genericPaymentUrl = <?php echo wp_json_encode($generic_payment_url); ?>;
                var genericPaymentLinkText = <?php echo wp_json_encode((string) get_option('avbk_generic_payment_link_text', '') ?: self::DEFAULT_GENERIC_PAYMENT_LINK_TEXT); ?>;
                var textarea = document.getElementById('avbk-extra-message');
                var frame = document.getElementById('avbk-preview-frame');
                var qrActive = false;
                var qrPlaceholder = 'Plak hier een QR-code (Ctrl+V), bijv. een ING Betaalverzoek';
                // Whatever was typed/pasted survives toggling back to the
                // default and into edit mode again — "terugzetten" only
                // swaps what's shown, it doesn't throw the draft away.
                var qrDraft = null;
                function escapeHtml(s) {
                    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                }
                function frameDoc() {
                    return frame.contentDocument;
                }
                function updateExtra() {
                    var value = textarea.value.trim();
                    var block = value ? '<p>' + escapeHtml(value).replace(/\n/g, '<br>') + '</p>' : '';
                    var el = frameDoc().getElementById('avbk-mail-extra');
                    if (el) {
                        el.innerHTML = block;
                    }
                }
                function applyEditableStyle(el) {
                    el.setAttribute('contenteditable', 'true');
                    el.style.minHeight = '3em';
                    el.style.border = '1px dashed #999';
                    el.style.padding = '.5em';
                }
                function makeEditable(el, placeholderText) {
                    el.innerHTML = '';
                    el.textContent = placeholderText;
                    applyEditableStyle(el);
                    el.style.color = '#888';
                    el.addEventListener('focus', function once() {
                        if (el.textContent === placeholderText) {
                            el.textContent = '';
                            el.style.color = '';
                        }
                        el.removeEventListener('focus', once);
                    });
                    el.focus();
                }
                // Re-enters edit mode with a previously typed/pasted draft
                // instead of a blank placeholder — used when toggling back
                // after "terugzetten" so nothing already entered is lost.
                function restoreDraft(el, draftHtml) {
                    el.innerHTML = draftHtml;
                    applyEditableStyle(el);
                    el.style.color = '';
                    el.focus();
                }
                // Back to plain, non-editable, with the original generated
                // content — same look as before "verwijderen" was ever
                // clicked. Whatever was in `el` is the caller's job to save
                // as a draft first if it should survive this.
                function restoreDefault(el, defaultHtml) {
                    el.innerHTML = defaultHtml;
                    el.removeAttribute('contenteditable');
                    el.style.minHeight = '';
                    el.style.border = '';
                    el.style.padding = '';
                    el.style.color = '';
                    el.style.display = '';
                    el.style.alignItems = '';
                    el.style.justifyContent = '';
                    el.style.textAlign = '';
                }
                // Returns el's current innerHTML as a draft to remember, or
                // null if there's nothing meaningful there yet (still the
                // untouched placeholder, or truly empty) — callers keep
                // whatever draft they already had in that case rather than
                // erasing it. Checked via children too, not just text,
                // since a pasted QR image has no textContent of its own.
                function captureDraft(el, placeholderText) {
                    var text = el.textContent.trim();
                    if (el.children.length === 0 && (text === '' || text === placeholderText)) {
                        return null;
                    }
                    return el.innerHTML;
                }
                frame.addEventListener('load', function () {
                    updateExtra();
                    var qrBtn = document.getElementById('avbk-clear-qr');
                    var qrEl = frameDoc().getElementById('avbk-mail-qr');
                    // Attached once, up front, rather than re-added on every
                    // "verwijderen" click — it no-ops via the qrActive check
                    // whenever the QR block isn't the paste target.
                    qrEl.addEventListener('paste', function (e) {
                        if (!qrActive) {
                            return;
                        }
                        var items = (e.clipboardData || window.clipboardData).items || [];
                        for (var i = 0; i < items.length; i++) {
                            if (items[i].type.indexOf('image') === -1) {
                                continue;
                            }
                            var file = items[i].getAsFile();
                            var reader = new FileReader();
                            reader.onload = function (ev) {
                                if (qrEl.textContent === qrPlaceholder) {
                                    qrEl.textContent = '';
                                    qrEl.style.color = '';
                                }
                                var img = document.createElement('img');
                                img.src = ev.target.result;
                                img.style.maxWidth = '100%';
                                qrEl.appendChild(img);
                            };
                            reader.readAsDataURL(file);
                            e.preventDefault();
                            return;
                        }
                    });
                    qrBtn.addEventListener('click', function () {
                        qrActive = !qrActive;
                        if (qrActive) {
                            if (qrDraft !== null) {
                                restoreDraft(qrEl, qrDraft);
                            } else {
                                makeEditable(qrEl, qrPlaceholder);
                            }
                            qrEl.style.display = 'flex';
                            qrEl.style.alignItems = 'center';
                            qrEl.style.justifyContent = 'center';
                            qrEl.style.textAlign = 'center';
                            qrBtn.textContent = 'QR-code terugzetten';
                        } else {
                            var captured = captureDraft(qrEl, qrPlaceholder);
                            if (captured !== null) {
                                qrDraft = captured;
                            }
                            restoreDefault(qrEl, defaultQrHtml);
                            qrBtn.textContent = 'QR-code verwijderen';
                        }
                    });
                    var genericQrBtn = document.getElementById('avbk-use-generic-qr');
                    if (genericQrBtn) {
                        genericQrBtn.addEventListener('click', function () {
                            qrActive = true;
                            qrEl.innerHTML = '';
                            applyEditableStyle(qrEl);
                            qrEl.style.color = '';
                            qrEl.style.display = 'flex';
                            qrEl.style.alignItems = 'center';
                            qrEl.style.justifyContent = 'center';
                            qrEl.style.textAlign = 'center';
                            var img = document.createElement('img');
                            img.src = genericQrDataUrl;
                            img.style.maxWidth = '100%';
                            qrEl.appendChild(img);
                            qrBtn.textContent = 'QR-code terugzetten';
                        });
                    }
                    var genericLinkBtn = document.getElementById('avbk-add-generic-link');
                    if (genericLinkBtn) {
                        genericLinkBtn.addEventListener('click', function () {
                            var addition = genericPaymentLinkText.replace('[link]', genericPaymentUrl);
                            textarea.value = textarea.value.trim() ? textarea.value.trim() + '\n\n' + addition : addition;
                            updateExtra();
                        });
                    }
                });
                textarea.addEventListener('input', updateExtra);
                frame.srcdoc = initialHtml;

                document.getElementById('avbk-send-form').addEventListener('submit', function () {
                    document.getElementById('avbk-custom-qr-active').value = qrActive ? '1' : '';
                    if (qrActive) {
                        var qrEl = frameDoc().getElementById('avbk-mail-qr');
                        var img = qrEl.querySelector('img');
                        if (img && img.src.indexOf('data:') === 0) {
                            var match = img.src.match(/^data:([^;]+);base64,(.*)$/);
                            img.remove();
                            if (match) {
                                document.getElementById('avbk-custom-qr-image-mime').value = match[1];
                                document.getElementById('avbk-custom-qr-image-data').value = match[2];
                            }
                        }
                        var qrHtml = qrEl.textContent.trim() === qrPlaceholder ? '' : qrEl.innerHTML;
                        document.getElementById('avbk-custom-qr-html').value = qrHtml;
                    }
                });
            })();
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * On-screen version of the "Vraag om betaling" e-mail: same QR and
     * reference, but as a standalone page instead of a mail, so the
     * penningmeester can hold up a phone/laptop screen and let the payer
     * scan it directly (e.g. at a desk or during an activity) instead of
     * needing to send and wait for an e-mail. No admin chrome, large QR —
     * meant to be shown, not read as a document.
     */
    public function handle_preview_payment_request(): void {
        check_admin_referer('avbk_preview_payment_request');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $member_id = (int) ($_GET['member_id'] ?? 0);
        $activity_id = (int) ($_GET['activity_id'] ?? 0);
        $member = $member_id ? AVPVH_DB::get_member($member_id) : null;
        $activity = $activity_id ? AVPVH_DB::get_activity($activity_id) : null;
        $fee_item = ($member && $activity) ? AVBK_DB::get_fee_item_for_member_activity($member_id, $activity_id) : null;
        if (!$member || !$activity || !$fee_item) {
            wp_die('Niet gevonden.', 404);
        }
        $remaining = AVBK_DB::get_fee_item_remaining($fee_item);
        if ($remaining <= 0.005) {
            wp_die('Deze rekening is al betaald.', 200);
        }

        // The penningmeester can override both the amount and the
        // betaalverzoektekst (the QR's SEPA remittance/omschrijving) right
        // here — e.g. a payer who wants to pay a rounder amount, or add a
        // note — and the QR is regenerated to match instead of always
        // encoding the fee item's own remaining amount/reference verbatim.
        // Purely a display override: nothing here touches the fee item or
        // the ledger, matching/reconciliation still happens later from the
        // actual bank transaction, same as any other QR payment.
        $default_text = AVBK_QR::remittance_for_fee_item($member_id, $fee_item);
        $edit_amount = $remaining;
        if (isset($_GET['betaal_bedrag'])) {
            $posted_amount = (float) str_replace(',', '.', sanitize_text_field(wp_unslash($_GET['betaal_bedrag'])));
            if ($posted_amount > 0) {
                $edit_amount = $posted_amount;
            }
        }
        $edit_text = isset($_GET['betaal_tekst'])
            ? mb_substr(trim(sanitize_text_field(wp_unslash($_GET['betaal_tekst']))), 0, 140)
            : $default_text;
        if ($edit_text === '') {
            $edit_text = $default_text;
        }
        $qr_payload = AVBK_QR::epc_payload($edit_amount, $edit_text);
        $qr_svg = $qr_payload ? AVBK_QR::svg($qr_payload) : null;
        $reference_code = $edit_text;
        $club_iban = trim((string) get_option('avbk_club_iban', ''));
        $beneficiary_iban_display = $club_iban ? trim(chunk_split($club_iban, 4, ' ')) : '';
        $beneficiary_name = trim((string) get_option('avbk_club_name', 'Archeologische Vereniging Philips van Horne'));
        $remaining = $edit_amount;

        header('Content-Type: text/html; charset=UTF-8');
        ?>
        <!doctype html>
        <html lang="nl">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Betaalverzoek &mdash; <?php echo esc_html($activity->name); ?></title>
        <style>
            body { font-family: -apple-system, sans-serif; margin: 0; padding: 1.5rem 1rem 3rem; }
            /* Side by side on anything roomier than a phone, so the whole
               thing fits on one screen without scrolling when it's held up
               for someone else to read + scan at the same time; wraps to
               the original stacked layout on narrow viewports. */
            .avbk-preview-wrap { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 1.5rem; max-width: 720px; margin: 0 auto; }
            .avbk-preview-info { flex: 1 1 260px; min-width: 220px; text-align: center; }
            h1 { font-size: 1.1rem; font-weight: normal; margin-bottom: .25rem; }
            .avbk-preview-amount { font-size: 1.6rem; font-weight: 600; margin: 0 0 1.25rem; }
            .avbk-preview-qr { flex: 0 0 auto; display: inline-block; background: #fff; padding: 16px; max-width: 320px; width: 60vw; }
            .avbk-preview-qr svg { width: 100%; height: auto; }
            .avbk-preview-details { margin-top: 1.25rem; font-size: .95rem; color: #333; text-align: left; }
            .avbk-preview-details code { font-size: 1.15em; }
            .avbk-preview-edit { max-width: 720px; margin: 2rem auto 0; padding-top: 1.25rem; border-top: 1px solid #ddd; font-size: .9rem; }
            .avbk-preview-edit label { display: block; margin-bottom: .75rem; }
            .avbk-preview-edit input[type="text"],
            .avbk-preview-edit input[type="number"] { width: 100%; box-sizing: border-box; margin-top: .25rem; padding: .4rem .5rem; font-size: 1rem; }
        </style>
        </head>
        <body>
            <div class="avbk-preview-wrap">
                <div class="avbk-preview-info">
                    <h1><?php echo esc_html(avpvh_format_name($member)); ?> &mdash; <?php echo esc_html($activity->name); ?></h1>
                    <p class="avbk-preview-amount">&euro; <?php echo esc_html(number_format($remaining, 2, ',', '.')); ?></p>
                    <p class="avbk-preview-details" id="avbk-details">
                        <?php if ($beneficiary_iban_display) : ?>IBAN: <code><?php echo esc_html($beneficiary_iban_display); ?></code><br><?php endif; ?>
                        Ten name van: <?php echo esc_html($beneficiary_name); ?><br>
                        Omschrijving: <code><?php echo esc_html($reference_code); ?></code>
                    </p>
                    <button type="button" id="avbk-clear-text" class="button button-small">Tekst verwijderen</button>
                </div>
                <div id="avbk-qr-wrap">
                    <?php if ($qr_svg) : ?>
                        <div class="avbk-preview-qr" id="avbk-qr"><?php echo $qr_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- server-rendered SVG from chillerlan/php-qrcode (AVBK_QR::svg), not user input; esc_html() would break the markup. ?></div>
                    <?php else : ?>
                        <p id="avbk-qr">De QR-code kon niet worden gegenereerd.</p>
                    <?php endif; ?>
                    <button type="button" id="avbk-clear-qr" class="button button-small">QR-code verwijderen</button>
                </div>
            </div>
            <form class="avbk-preview-edit" method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avbk_preview_payment_request'); ?>
                <input type="hidden" name="action" value="avbk_preview_payment_request">
                <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                <label>Bedrag
                    <input type="number" step="0.01" min="0.01" name="betaal_bedrag" value="<?php echo esc_attr(number_format($edit_amount, 2, '.', '')); ?>">
                </label>
                <label>Betaalverzoektekst (omschrijving in de QR-code)
                    <input type="text" name="betaal_tekst" maxlength="140" value="<?php echo esc_attr($edit_text); ?>">
                </label>
                <?php submit_button('QR-code bijwerken', 'secondary', 'submit', false); ?>
            </form>
            <script>
            (function () {
                // "Tekst verwijderen": clears the IBAN/naam/omschrijving
                // block and turns it into a plain editable area, so the
                // penningmeester can paste in different text (e.g. from
                // another system) instead of the generated details.
                document.getElementById('avbk-clear-text').addEventListener('click', function () {
                    var el = document.getElementById('avbk-details');
                    el.textContent = '';
                    el.setAttribute('contenteditable', 'true');
                    el.style.minHeight = '3em';
                    el.style.border = '1px dashed #999';
                    el.style.padding = '.5em';
                    el.focus();
                });
                // "QR-code verwijderen": same idea, but for an image —
                // becomes a paste target so a QR copied from elsewhere
                // (another payment system, an e-mail, ...) can replace the
                // generated one. Ctrl+V/long-press paste of an image lands
                // here via the browser's clipboard API; the generated QR
                // is simply discarded, nothing server-side to undo.
                document.getElementById('avbk-clear-qr').addEventListener('click', function () {
                    var el = document.getElementById('avbk-qr');
                    el.textContent = 'Plak hier een QR-code (Ctrl+V)';
                    el.setAttribute('contenteditable', 'true');
                    el.style.minHeight = '200px';
                    el.style.display = 'flex';
                    el.style.alignItems = 'center';
                    el.style.justifyContent = 'center';
                    el.style.textAlign = 'center';
                    el.style.color = '#888';
                    el.style.border = '2px dashed #999';
                    el.addEventListener('paste', function (e) {
                        var items = (e.clipboardData || window.clipboardData).items || [];
                        for (var i = 0; i < items.length; i++) {
                            if (items[i].type.indexOf('image') === -1) {
                                continue;
                            }
                            var file = items[i].getAsFile();
                            var reader = new FileReader();
                            reader.onload = function (ev) {
                                el.textContent = '';
                                el.style.color = '';
                                var img = document.createElement('img');
                                img.src = ev.target.result;
                                img.style.maxWidth = '100%';
                                el.appendChild(img);
                            };
                            reader.readAsDataURL(file);
                            e.preventDefault();
                            return;
                        }
                    });
                    el.focus();
                });
            })();
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Saves the activity's generic betaalverzoeklink (e.g. an ING
     * Betaalverzoek/Tikkie link the penningmeester sets up once and shares
     * with everyone attending) — see AVBK_DB::save_activity_payment_url()
     * for why this is per-activity, not per-member, and never auto-used in
     * the "Vraag om betaling" e-mail.
     */
    public function handle_save_activity_payment_url(): void {
        check_admin_referer('avbk_save_activity_payment_url');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        if ($activity_id) {
            AVBK_DB::save_activity_payment_url($activity_id, sanitize_text_field(wp_unslash($_POST['payment_url'] ?? '')));
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-activity-payments', 'activity_id' => $activity_id], admin_url('admin.php')));
        exit;
    }

    /** Saves the activity's generic betaalverzoek-QR (dragged-and-dropped image, see admin/activity-payments.php) — same table/row as the link, see AVBK_DB::save_activity_payment_qr(). */
    public function handle_save_activity_payment_qr(): void {
        check_admin_referer('avbk_save_activity_payment_qr');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        $mime = (string) ($_POST['qr_image_mime'] ?? '');
        $data = (string) ($_POST['qr_image_data'] ?? '');
        if ($activity_id && $data !== '' && in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            $decoded = base64_decode($data, true);
            if ($decoded !== false && strlen($decoded) > 0 && strlen($decoded) < 3 * 1024 * 1024) {
                AVBK_DB::save_activity_payment_qr($activity_id, $decoded, $mime);
            }
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-activity-payments', 'activity_id' => $activity_id], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_activity_payment_url(): void {
        check_admin_referer('avbk_delete_activity_payment_url');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        if ($activity_id) {
            AVBK_DB::delete_activity_payment_url($activity_id);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-activity-payments', 'activity_id' => $activity_id], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_activity_payment_qr(): void {
        check_admin_referer('avbk_delete_activity_payment_qr');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        if ($activity_id) {
            AVBK_DB::delete_activity_payment_qr($activity_id);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-activity-payments', 'activity_id' => $activity_id], admin_url('admin.php')));
        exit;
    }

    /**
     * Opens or mails the existing member balance/QR page for an explicit
     * selection of fee items. This deliberately reuses the shortcode and
     * AVBK_QR reference format instead of creating a second payment flow in
     * wp-admin.
     */
    public function handle_request_balance_payment(): void {
        check_admin_referer('avbk_request_balance_payment');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }

        $member_id = (int) ($_POST['member_id'] ?? 0);
        $mode = sanitize_key(wp_unslash($_POST['request_mode'] ?? 'show'));
        $requested_ids = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) wp_unslash($_POST['fee_item_ids'] ?? [])
        ))));
        $member = $member_id ? AVPVH_DB::get_member($member_id) : null;

        $items = [];
        $total = 0.0;
        foreach ($requested_ids as $fee_item_id) {
            $item = AVBK_DB::get_fee_item($fee_item_id);
            if (!$item || (int) $item->member_id !== $member_id || $item->status === 'waived') {
                continue;
            }
            $remaining = AVBK_DB::get_fee_item_remaining($item);
            if ($remaining <= 0.005) {
                continue;
            }
            $item->remaining = $remaining;
            $items[] = $item;
            $total += $remaining;
        }

        $return_url = add_query_arg(['page' => 'avbk-members', 'member_id' => $member_id], admin_url('admin.php'));
        if (!$member || !$items) {
            wp_safe_redirect(add_query_arg('payment_request_failed', '1', $return_url));
            exit;
        }

        $balance_url = add_query_arg(
            ['member_id' => $member_id, 'pay' => wp_list_pluck($items, 'id')],
            home_url('/leden/beheer/member-profile/')
        ) . '#bijdrage';
        if ($mode === 'show') {
            wp_safe_redirect($balance_url);
            exit;
        }

        if ($mode !== 'mail' || !is_email($member->email)) {
            wp_safe_redirect(add_query_arg('payment_request_failed', '1', $return_url));
            exit;
        }

        $descriptions = array_map(
            fn($item) => sprintf('%s: € %s', $item->description, number_format((float) $item->remaining, 2, ',', '.')),
            $items
        );
        $penningmeester_name = get_option('avbk_penningmeester_name', 'de penningmeester');
        $subject = count($items) === 1 ? 'Openstaande betaling' : 'Openstaande betalingen';
        $login_help_text = '';
        if (get_option('avbk_payment_email_login_help', 1)) {
            $login_help_text = "\n\n" . str_replace(
                '[wachtwoord-link]',
                wp_login_url($balance_url),
                (string) get_option('avbk_payment_email_login_text', '') ?: self::DEFAULT_PAYMENT_EMAIL_LOGIN_TEXT
            );
        }
        $body = sprintf(
            "Dag %s,\n\nZou je de volgende rekening willen betalen?\n\n%s\n\nTotaal: € %s\n\nJe vindt het overzicht en de QR-code voor deze selectie hier (inloggen met je AV-PvH-account):\n%s\n\nOp die pagina kun je zo nodig ook betalingen voor huisgenoten toevoegen.%s\n\nGroet,\n%s",
            $member->first_name,
            implode("\n", $descriptions),
            number_format($total, 2, ',', '.'),
            $balance_url,
            $login_help_text,
            $penningmeester_name
        );
        $from_email = sanitize_email(get_option('avbk_penningmeester_email', 'penningmeester@avphilipsvanhorne.nl'));
        if (!is_email($from_email)) {
            $from_email = 'penningmeester@avphilipsvanhorne.nl';
        }
        $force_sender = static function ($phpmailer) use ($from_email): void {
            if (is_object($phpmailer) && method_exists($phpmailer, 'setFrom')) {
                try {
                    $phpmailer->setFrom($from_email, 'AV-PvH Penningmeester', false);
                } catch (\Throwable $e) {
                    // Keep the mail send alive if a relay rejects the address.
                }
            }
        };
        $force_from = static fn($current) => $from_email;
        $force_from_name = static fn($current) => 'AV-PvH Penningmeester';
        add_filter('wp_mail_from', $force_from, PHP_INT_MAX);
        add_filter('wp_mail_from_name', $force_from_name, PHP_INT_MAX);
        add_action('phpmailer_init', $force_sender, PHP_INT_MAX);
        $sent = wp_mail($member->email, $subject, $body, [
            'From: AV-PvH Penningmeester <' . $from_email . '>',
            'Reply-To: ' . $from_email,
        ]);
        remove_action('phpmailer_init', $force_sender, PHP_INT_MAX);
        remove_filter('wp_mail_from', $force_from, PHP_INT_MAX);
        remove_filter('wp_mail_from_name', $force_from_name, PHP_INT_MAX);

        wp_safe_redirect(add_query_arg($sent ? 'payment_requested' : 'payment_request_failed', '1', $return_url));
        exit;
    }

    /**
     * Backs the review queue's live refresh: when the treasurer changes the
     * selected member or activity on an already-rendered row (correcting a
     * wrong suggestion, or filling a blank slot), the amount/age/nights
     * detail needs to reflect the newly picked person/activity instead of
     * staying frozen on whoever/whatever was originally suggested.
     */
    public function ajax_member_fee_detail(): void {
        check_ajax_referer('avbk_review_queue', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error('Geen toegang.', 403);
        }
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        if (!$member_id) {
            wp_send_json_error('Ontbrekende gegevens.', 400);
        }
        // activity_id 0 means the treasurer picked a one-off category
        // (Weekend, Drank, Overig, ...) with no tarief to look up — still
        // worth telling them the member is a scholier/student, see
        // AVBK_DB::get_member_status_detail().
        wp_send_json_success($activity_id
            ? AVBK_DB::get_member_fee_detail_for_activity($member_id, $activity_id)
            : AVBK_DB::get_member_status_detail($member_id));
    }

    /**
     * Backs the review queue's "fill the other blank rows" convenience: once
     * the treasurer picks the first payer on a row, their household/family
     * members are the overwhelmingly likely candidates for the rest of that
     * payment (parents paying for kids, partners paying for each other —
     * see the class docblock on AVBK_Matcher for the real examples this is
     * modeled on) — far more useful to suggest than scrolling the full
     * ~200-member list. Reuses AVPVH_DB::get_manageable_members(), the same
     * self-or-household rule the profile form and balance shortcode already
     * use elsewhere in this codebase. The payer themselves is included too —
     * a single person paying for two different activities in one transfer
     * (e.g. weekend-inschrijving + drank) needs their own name available
     * when adding a second row for the same payment, not just relatives.
     */
    public function ajax_household_candidates(): void {
        check_ajax_referer('avbk_review_queue', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error('Geen toegang.', 403);
        }
        $member_id = (int) ($_POST['member_id'] ?? 0);
        if (!$member_id) {
            wp_send_json_error('Ontbrekend lid.', 400);
        }
        $candidates = AVBK_DB::get_payment_household_candidates($member_id);
        // Adults/account holders first, then children; keep the existing
        // household/name order within each group.
        usort($candidates, fn($a, $b) =>
            (int) AVBK_Matcher::member_is_minor($a) <=> (int) AVBK_Matcher::member_is_minor($b)
        );
        wp_send_json_success(array_map(fn($m) => [
            'id'    => (int) $m->id,
            'label' => avpvh_format_name($m, 'list'),
        ], $candidates));
    }

    /**
     * Everyone already registered as a participant of one specific
     * activiteit (e.g. "Congres/Reünie 50 jaar AVPvH") — surfaced as a
     * suggestions optgroup in the review-queue's lid-dropdown, same idea as
     * ajax_household_candidates() but scoped to the row's own matched
     * activiteit instead of the payer's household. Much faster to pick the
     * right person from ~100 known attendees than from every payable lid
     * (which, unlike this list, also excludes ex-leden by default — see
     * AVBK_DB::get_payable_members() — exactly who tends to show up for a
     * jubileum/reünie).
     */
    public function ajax_activity_participants(): void {
        check_ajax_referer('avbk_review_queue', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        if (!$activity_id) {
            wp_send_json_error('Ontbrekende activiteit.', 400);
        }
        $rows = AVPVH_DB::get_participation_for_activity($activity_id);
        wp_send_json_success(array_map(function ($p) use ($activity_id) {
            $detail = AVBK_DB::get_member_fee_detail_for_activity((int) $p->member_id, $activity_id);
            return [
                'id'    => (int) $p->member_id,
                'label' => avpvh_format_name($p, 'list'),
                'paid'  => $detail['found'] && $detail['share'] <= 0.005,
            ];
        }, $rows));
    }

    public function handle_recompute_suggestions(): void {
        check_admin_referer('avbk_recompute_suggestions');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $count = AVBK_Import::recompute_suggestions();
        wp_safe_redirect(add_query_arg(['page' => 'avbk-review', 'recomputed' => $count], admin_url('admin.php')));
        exit;
    }

    /** Saves the treasurer's preferred review direction; oldest-first is the safe default because earlier payments must consume earlier open charges before later payments are assessed. */
    public function handle_save_review_order(): void {
        check_admin_referer('avbk_save_review_order');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $order = sanitize_key(wp_unslash($_POST['review_order'] ?? 'asc'));
        update_user_meta(get_current_user_id(), 'avbk_review_order', $order === 'desc' ? 'desc' : 'asc');
        wp_safe_redirect(add_query_arg(['page' => 'avbk-review'], admin_url('admin.php')));
        exit;
    }

    public function handle_resolve_dispute(): void {
        check_admin_referer('avbk_resolve_dispute');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            AVBK_DB::resolve_dispute($id, get_current_user_id());
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-disputes', 'resolved' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_waive_fee_item(): void {
        check_admin_referer('avbk_waive_fee_item');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $member_id = (int) ($_POST['member_id'] ?? 0);
        if ($id) {
            AVBK_DB::waive_fee_item($id);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-members', 'member_id' => $member_id, 'waived' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_update_fee_item_amount(): void {
        check_admin_referer('avbk_update_fee_item_amount');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $amount = (float) str_replace(',', '.', (string) ($_POST['amount_due'] ?? ''));
        if ($id) {
            AVBK_DB::update_fee_item_amount($id, $amount);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-members', 'member_id' => $member_id, 'amount_updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_student_year(): void {
        check_admin_referer('avbk_save_student_year');
        if (!$this->can_manage()) wp_die('Geen toegang.', 403);
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? 0);
        if ($member_id && $year >= 1900 && $year <= 2200) {
            AVBK_DB::set_member_student_year($member_id, $year, !empty($_POST['is_student']));
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-members', 'member_id' => $member_id, 'student_year_saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_student_year(): void {
        check_admin_referer('avbk_delete_student_year');
        if (!$this->can_manage()) wp_die('Geen toegang.', 403);
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? 0);
        if ($member_id && $year) AVBK_DB::delete_member_student_year($member_id, $year);
        wp_safe_redirect(add_query_arg(['page' => 'avbk-members', 'member_id' => $member_id, 'student_year_deleted' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_settings(): void {
        check_admin_referer('avbk_save_settings');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        update_option('avbk_club_iban', strtoupper(str_replace(' ', '', sanitize_text_field(wp_unslash($_POST['club_iban'] ?? '')))));
        update_option('avbk_club_name', sanitize_text_field(wp_unslash($_POST['club_name'] ?? '')));
        update_option('avbk_reference_prefix', sanitize_text_field(wp_unslash($_POST['reference_prefix'] ?? 'PVH')));
        update_option('avbk_penningmeester_email', sanitize_email(wp_unslash($_POST['penningmeester_email'] ?? '')) ?: 'info@avphilipsvanhorne.nl');
        update_option('avbk_penningmeester_name', sanitize_text_field(wp_unslash($_POST['penningmeester_name'] ?? '')) ?: 'de penningmeester');
        update_option('avbk_payment_email_login_help', !empty($_POST['payment_email_login_help']) ? 1 : 0);
        update_option('avbk_payment_email_login_text', sanitize_textarea_field(wp_unslash($_POST['payment_email_login_text'] ?? '')) ?: self::DEFAULT_PAYMENT_EMAIL_LOGIN_TEXT);
        update_option('avbk_qr_caption_text', sanitize_textarea_field(wp_unslash($_POST['qr_caption_text'] ?? '')) ?: self::DEFAULT_QR_CAPTION_TEXT);
        update_option('avbk_generic_payment_link_text', sanitize_textarea_field(wp_unslash($_POST['generic_payment_link_text'] ?? '')) ?: self::DEFAULT_GENERIC_PAYMENT_LINK_TEXT);
        wp_safe_redirect(add_query_arg(['page' => 'avbk-rates', 'settings_saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_iban_country_format(): void {
        check_admin_referer('avbk_save_iban_country_format');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $country_code = strtoupper(sanitize_text_field(wp_unslash($_POST['country_code'] ?? '')));
        $saved = AVBK_DB::save_iban_country_format(
            $country_code,
            sanitize_text_field(wp_unslash($_POST['country_name'] ?? '')),
            (int) ($_POST['bank_code_position'] ?? 0),
            (int) ($_POST['bank_code_length'] ?? 0)
        );
        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-iban-bank-codes',
            $saved ? 'country_saved' : 'iban_error' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_iban_country_format(): void {
        check_admin_referer('avbk_delete_iban_country_format');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        AVBK_DB::delete_iban_country_format(sanitize_text_field(wp_unslash($_POST['country_code'] ?? '')));
        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-iban-bank-codes',
            'country_deleted' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_save_iban_bank_code(): void {
        check_admin_referer('avbk_save_iban_bank_code');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $country_code = strtoupper(sanitize_text_field(wp_unslash($_POST['country_code'] ?? '')));
        $saved = AVBK_DB::save_iban_bank_code(
            (int) ($_POST['id'] ?? 0),
            $country_code,
            sanitize_text_field(wp_unslash($_POST['code_start'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['code_end'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['bank_name'] ?? ''))
        );
        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-iban-bank-codes',
            'country' => $country_code,
            $saved ? 'bank_saved' : 'iban_error' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_iban_bank_code(): void {
        check_admin_referer('avbk_delete_iban_bank_code');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $country_code = strtoupper(sanitize_text_field(wp_unslash($_POST['country_code'] ?? '')));
        AVBK_DB::delete_iban_bank_code((int) ($_POST['id'] ?? 0));
        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-iban-bank-codes',
            'country' => $country_code,
            'bank_deleted' => '1',
        ], admin_url('admin.php')));
        exit;
    }
}
