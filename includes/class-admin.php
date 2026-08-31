<?php
defined('ABSPATH') || exit;

class AVBK_Admin {

    public const DEFAULT_PAYMENT_EMAIL_LOGIN_TEXT = "Inloggen: gebruik als gebruikersnaam je e-mailadres. Als je nog niet eerder bent ingelogd of je je wachtwoord niet weet, klik dan [wachtwoord-link].\n\nAls je met je browser al bent ingelogd bij Google (Gmail) of Microsoft (Outlook/Hotmail), kun je ook de knop Inloggen met Google respectievelijk Inloggen met Microsoft proberen. Dan heb je geen (nieuw) wachtwoord nodig. Het e-mailadres moet wel overeenkomen met het adres dat bij de vereniging bekend is.";

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
        add_action('admin_post_avbk_save_student_year',    [$this, 'handle_save_student_year']);
        add_action('admin_post_avbk_delete_student_year',  [$this, 'handle_delete_student_year']);
        add_action('admin_post_avbk_save_settings',        [$this, 'handle_save_settings']);
        add_action('admin_post_avbk_generate_contribution_fees_now', [$this, 'handle_generate_contribution_fees_now']);
        add_action('admin_post_avbk_generate_camp_fees_now',         [$this, 'handle_generate_camp_fees_now']);
        add_action('admin_post_avbk_save_sheet_url',                 [$this, 'handle_save_sheet_url']);
        add_action('admin_post_avbk_save_sheet_import_config',       [$this, 'handle_save_sheet_import_config']);
        add_action('admin_post_avbk_sheet_import',                   [$this, 'handle_sheet_import']);
        add_action('admin_post_avbk_sheet_import_upload',            [$this, 'handle_sheet_import_upload']);
        add_action('admin_post_avbk_sheet_import_link_attendee',     [$this, 'handle_sheet_import_link_attendee']);
        add_action('admin_post_avbk_sheet_import_ignore_attendee',   [$this, 'handle_sheet_import_ignore_attendee']);
        add_action('admin_post_avbk_request_payment',                [$this, 'handle_request_payment']);
        add_action('admin_post_avbk_request_balance_payment',        [$this, 'handle_request_balance_payment']);
        add_action('admin_post_avbk_recompute_suggestions',          [$this, 'handle_recompute_suggestions']);
        add_action('admin_post_avbk_save_review_order',              [$this, 'handle_save_review_order']);
        add_action('admin_post_avbk_resolve_dispute',                [$this, 'handle_resolve_dispute']);
        add_action('admin_post_avbk_second_approve_transaction',     [$this, 'handle_second_approve_transaction']);
        add_action('admin_post_avbk_revert_transaction_to_review',   [$this, 'handle_revert_transaction_to_review']);
        add_action('admin_post_avbk_revert_year_payments_to_review', [$this, 'handle_revert_year_payments_to_review']);
        add_action('admin_post_avbk_set_closed_through_year',        [$this, 'handle_set_closed_through_year']);
        add_action('wp_ajax_avbk_member_fee_detail', [$this, 'ajax_member_fee_detail']);
        add_action('wp_ajax_avbk_household_candidates', [$this, 'ajax_household_candidates']);
    }

    /** Real WP admins, or whoever currently holds/is delegated penningmeester (AVPVH_Roles folds officer roles into bestuur, but this screen is specifically financial — keep it to penningmeester, not all of bestuur). */
    private function can_manage(): bool {
        return current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('penningmeester');
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

        $open_disputes = AVBK_DB::count_open_disputes();
        $disputes_label = 'Bezwaren' . ($open_disputes ? " <span class=\"awaiting-mod count-{$open_disputes}\"><span class=\"pending-count\">{$open_disputes}</span></span>" : '');
        add_submenu_page('avbk-overview', 'Bezwaren', $disputes_label, 'read', 'avbk-disputes', [$this, 'render_disputes']);

        $pending_reimbursements = AVBK_DB::count_pending_reimbursements();
        $reimbursements_label = 'Declaraties' . ($pending_reimbursements ? " <span class=\"awaiting-mod count-{$pending_reimbursements}\"><span class=\"pending-count\">{$pending_reimbursements}</span></span>" : '');
        add_submenu_page('avbk-overview', 'Declaraties', $reimbursements_label, 'read', 'avbk-reimbursements', [$this, 'render_reimbursements']);

        add_submenu_page('avbk-overview', 'Activiteit betalingen', 'Activiteit betalingen', 'read', 'avbk-activity-payments', [$this, 'render_activity_payments']);
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

        $rows = [];
        foreach ($member_ids as $i => $member_id) {
            $rows[] = [
                'member_id'   => $member_id,
                'activity'    => $activities[$i] ?? '',
                'description' => $descriptions[$i] ?? '',
                'amount'      => (float) str_replace(',', '.', (string) ($amounts_raw[$i] ?? '')),
            ];
        }
        return $rows;
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
            wp_safe_redirect(add_query_arg(['page' => 'avbk-review', 'confirm_failed' => '1'], admin_url('admin.php')) . '#tx-' . $transaction_id);
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
                wp_safe_redirect(add_query_arg(['page' => 'avbk-review', 'confirm_failed' => '1'], admin_url('admin.php')) . '#tx-' . $transaction_id);
                exit;
            }
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
        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        // 0 is a legitimate rate (e.g. kids 0-3 free), so only activity_id gates this — not rate > 0.
        $rate = (float) str_replace(',', '.', (string) ($_POST['rate'] ?? ''));

        if ($activity_id) {
            AVBK_DB::save_activity_rate($id, $activity_id, $min_age, $max_age, $label, $rate, $for_students);
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
                        !empty($source_rate->for_students)
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
        $config = AVBK_Sheet_Import::get_config($activity_id);
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
                'name'   => sanitize_text_field(wp_unslash($_POST['slot_name'][$i] ?? '')),
                'email'  => sanitize_text_field(wp_unslash($_POST['slot_email'][$i] ?? '')),
                'diet'   => sanitize_text_field(wp_unslash($_POST['slot_diet'][$i] ?? '')),
                'notes'  => sanitize_text_field(wp_unslash($_POST['slot_notes'][$i] ?? '')),
                'amount' => sanitize_text_field(wp_unslash($_POST['slot_amount'][$i] ?? '')),
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
        $result = $activity_id
            ? AVBK_Sheet_Import::import($activity_id)
            : ['matched' => [], 'unmatched' => [], 'errors' => ['Geen activiteit gekozen.']];
        set_transient(AVBK_Sheet_Import::result_transient_key($activity_id), $result, 12 * HOUR_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page' => 'avbk-activity-payments', 'activity_id' => $activity_id, 'imported' => '1'], admin_url('admin.php')));
        exit;
    }

    /** Same as handle_sheet_import(), but from a one-off .xlsx upload instead of the configured Google Sheet link — for a sign-up source with no shareable live link. */
    public function handle_sheet_import_upload(): void {
        check_admin_referer('avbk_sheet_import_upload');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        if (empty($_FILES['sheet_file']['tmp_name']) || !is_uploaded_file($_FILES['sheet_file']['tmp_name'])) {
            $result = ['matched' => [], 'unmatched' => [], 'errors' => ['Geen bestand geüpload.']];
        } else {
            // Never moved into wp-content/uploads — read once from PHP's own
            // private upload tmp path, same as the bank-export upload.
            $result = $activity_id
                ? AVBK_Sheet_Import::import($activity_id, $_FILES['sheet_file']['tmp_name'])
                : ['matched' => [], 'unmatched' => [], 'errors' => ['Geen activiteit gekozen.']];
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
            $source_name = sanitize_text_field(wp_unslash($_POST['source_name'] ?? ''));
            $source_email = sanitize_text_field(wp_unslash($_POST['source_email'] ?? ''));
            AVBK_Sheet_Import::remember_match($activity_id, $source_name, $source_email, $member_id);
            if (!empty($_POST['add_source_email'])) {
                $email = sanitize_email($source_email);
                $email_added = $email !== '' && AVPVH_DB::ensure_identity($member_id, 'email', $email);
            }
            AVPVH_DB::save_participation($member_id, $activity_id, [
                'nights'  => null,
                'nawacht' => false,
                'diet'    => sanitize_text_field(wp_unslash($_POST['allergies'] ?? '')),
                'notes'   => sanitize_text_field(wp_unslash($_POST['notes'] ?? '')),
            ]);
            AVBK_DB::save_sheet_participation_meta(
                $activity_id,
                $member_id,
                sanitize_text_field(wp_unslash($_POST['registered_at'] ?? '')) ?: null,
                sanitize_text_field(wp_unslash($_POST['source_timestamp'] ?? ''))
            );
            $config = AVBK_Sheet_Import::get_config($activity_id);
            $row_amount = (float) ($_POST['amount'] ?? 0);
            $amount = $row_amount > 0 ? $row_amount : (float) $config['price_per_person'];
            if ($amount > 0) {
                AVBK_DB::upsert_event_fee_item($member_id, AVPVH_DB::get_activity($activity_id)->name ?? 'Activiteit', $amount, $activity_id);
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
    public function handle_request_payment(): void {
        check_admin_referer('avbk_request_payment');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        $redirect = fn(string $flag) => wp_safe_redirect(add_query_arg(['page' => 'avbk-activity-payments', 'activity_id' => $activity_id, $flag => '1'], admin_url('admin.php')));

        $member = $member_id ? AVPVH_DB::get_member($member_id) : null;
        $activity = $activity_id ? AVPVH_DB::get_activity($activity_id) : null;
        $fee_item = ($member && $activity) ? AVBK_DB::get_fee_item_for_member_activity($member_id, $activity_id) : null;
        if (!$member || !$activity || !$fee_item) {
            $redirect('payment_request_failed');
            exit;
        }
        $remaining = round((float) $fee_item->amount_due - AVBK_DB::get_fee_item_paid((int) $fee_item->id), 2);
        if ($remaining <= 0.005 || !is_email($member->email)) {
            $redirect('payment_request_failed');
            exit;
        }

        $penningmeester_name = get_option('avbk_penningmeester_name', 'de penningmeester');
        $balance_url = home_url('/leden/beheer/member-profile/');
        $login_url = wp_login_url($balance_url);
        $login_help = '';
        if (get_option('avbk_payment_email_login_help', 1)) {
            $login_text = (string) get_option('avbk_payment_email_login_text', self::DEFAULT_PAYMENT_EMAIL_LOGIN_TEXT);
            $login_help = wpautop(str_replace(
                '[wachtwoord-link]',
                '<a href="' . esc_url($login_url) . '">hier</a>',
                esc_html($login_text)
            ));
        }
        $subject = sprintf('Openstaande betaling — %s', $activity->name);
        $qr_png = AVBK_QR::png_for_fee_item($member_id, $fee_item);
        $qr_cid = 'avbk-payment-qr-' . $fee_item->id . '-' . wp_generate_password(8, false, false) . '@avpvh.nl';
        $qr_block = $qr_png
            ? '<p><strong>Scan deze QR-code met je bankieren-app:</strong></p><div style="background:#fff;padding:12px;display:inline-block"><img src="cid:' . esc_attr($qr_cid) . '" width="360" height="360" alt="QR-code voor betaling"></div>'
            : '<p>De QR-code kon niet worden gegenereerd; gebruik de link hieronder.</p>';
        $body = sprintf(
            '<!doctype html><html><body><p>Dag %s,</p><p>Zou je de volgende rekening willen betalen?</p><p><strong>%s: € %s</strong></p>%s<p>Je vindt deze betaling én al je gegevens, inclusief alle andere betalingen die je misschien nog moet doen en al hebt gedaan, op je profielpagina van de website:<br><a href="%s">%s</a></p>%s<p>Groet,<br>%s</p></body></html>',
            esc_html($member->first_name),
            esc_html($activity->name),
            esc_html(number_format($remaining, 2, ',', '.')),
            $qr_block,
            esc_url($balance_url),
            esc_html($balance_url),
            $login_help,
            esc_html($penningmeester_name)
        );
        $from_email = sanitize_email(get_option('avbk_penningmeester_email', 'penningmeester@avphilipsvanhorne.nl'));
        if (!is_email($from_email)) {
            $from_email = 'penningmeester@avphilipsvanhorne.nl';
        }
        $embed_qr = static function ($phpmailer) use ($qr_png, $qr_cid): void {
            if ($qr_png && is_object($phpmailer) && method_exists($phpmailer, 'addStringEmbeddedImage')) {
                $phpmailer->addStringEmbeddedImage($qr_png, $qr_cid, 'betaling-qr.png', 'base64', 'image/png');
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
        if ($qr_png) {
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
        if ($qr_png) {
            remove_action('phpmailer_init', $embed_qr);
        }

        if ($sent) {
            AVBK_DB::log_payment_request((int) $fee_item->id, $member_id, $activity_id, $member->email);
        }

        $redirect($sent ? 'payment_requested' : 'payment_request_failed');
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
            $remaining = round((float) $item->amount_due - AVBK_DB::get_fee_item_paid((int) $item->id), 2);
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
                (string) get_option('avbk_payment_email_login_text', self::DEFAULT_PAYMENT_EMAIL_LOGIN_TEXT)
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
        update_option('avbk_payment_email_login_text', sanitize_textarea_field(wp_unslash($_POST['payment_email_login_text'] ?? self::DEFAULT_PAYMENT_EMAIL_LOGIN_TEXT)));
        wp_safe_redirect(add_query_arg(['page' => 'avbk-rates', 'settings_saved' => '1'], admin_url('admin.php')));
        exit;
    }
}
