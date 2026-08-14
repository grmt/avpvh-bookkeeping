<?php
defined('ABSPATH') || exit;

class AVBK_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menus'], 5);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_avbk_upload_import',        [$this, 'handle_upload_import']);
        add_action('admin_post_avbk_confirm_transaction',  [$this, 'handle_confirm_transaction']);
        add_action('admin_post_avbk_save_transaction_draft',  [$this, 'handle_save_transaction_draft']);
        add_action('admin_post_avbk_clear_transaction_draft', [$this, 'handle_clear_transaction_draft']);
        add_action('admin_post_avbk_ignore_transaction',   [$this, 'handle_ignore_transaction']);
        add_action('admin_post_avbk_save_activity_rate',   [$this, 'handle_save_activity_rate']);
        add_action('admin_post_avbk_delete_activity_rate', [$this, 'handle_delete_activity_rate']);
        add_action('admin_post_avbk_waive_fee_item',       [$this, 'handle_waive_fee_item']);
        add_action('admin_post_avbk_save_settings',        [$this, 'handle_save_settings']);
        add_action('admin_post_avbk_generate_contribution_fees_now', [$this, 'handle_generate_contribution_fees_now']);
        add_action('admin_post_avbk_generate_camp_fees_now',         [$this, 'handle_generate_camp_fees_now']);
        add_action('admin_post_avbk_recompute_suggestions',          [$this, 'handle_recompute_suggestions']);
        add_action('admin_post_avbk_resolve_dispute',                [$this, 'handle_resolve_dispute']);
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
        add_submenu_page('avbk-overview', 'Alle transacties', 'Alle transacties', 'read', 'avbk-transactions', [$this, 'render_transactions']);
        add_submenu_page('avbk-overview', 'Ledenoverzicht', 'Ledenoverzicht', 'read', 'avbk-members', [$this, 'render_members']);
        add_submenu_page('avbk-overview', 'Tarieven', 'Tarieven', 'read', 'avbk-rates', [$this, 'render_rates']);

        $open_disputes = AVBK_DB::count_open_disputes();
        $disputes_label = 'Bezwaren' . ($open_disputes ? " <span class=\"awaiting-mod count-{$open_disputes}\"><span class=\"pending-count\">{$open_disputes}</span></span>" : '');
        add_submenu_page('avbk-overview', 'Bezwaren', $disputes_label, 'read', 'avbk-disputes', [$this, 'render_disputes']);

        $congress_attention = AVBK_DB::count_congress_needs_attention();
        $congress_label = 'Congres/Reünie' . ($congress_attention ? " <span class=\"awaiting-mod count-{$congress_attention}\"><span class=\"pending-count\">{$congress_attention}</span></span>" : '');
        add_submenu_page('avbk-overview', 'Congres/Reünie', $congress_label, 'read', 'avbk-congress', [$this, 'render_congress']);
    }

    public function enqueue_assets(string $hook): void {
        if (!str_contains($hook, 'avbk-')) {
            return;
        }
        wp_enqueue_style('avbk-admin', AVBK_PLUGIN_URL . 'assets/admin.css', [], avbk_asset_version('assets/admin.css'));
        if (str_contains($hook, 'avbk-review')) {
            wp_enqueue_script('avbk-review-queue', AVBK_PLUGIN_URL . 'assets/review-queue.js', [], avbk_asset_version('assets/review-queue.js'), true);
        }
    }

    public function render_overview(): void { require AVBK_PLUGIN_DIR . 'admin/overview.php'; }
    public function render_disputes(): void { require AVBK_PLUGIN_DIR . 'admin/disputes.php'; }
    public function render_congress(): void { require AVBK_PLUGIN_DIR . 'admin/congress.php'; }
    public function render_import(): void { require AVBK_PLUGIN_DIR . 'admin/import.php'; }
    public function render_review(): void { require AVBK_PLUGIN_DIR . 'admin/review-queue.php'; }
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

        if ($transaction_id && $rows) {
            AVBK_Import::confirm_transaction($transaction_id, $rows);
        }

        wp_safe_redirect(add_query_arg(['page' => 'avbk-review', 'confirmed' => '1'], admin_url('admin.php')));
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
            AVBK_DB::update_transaction_status($transaction_id, 'ignored');
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-review', 'ignored' => '1'], admin_url('admin.php')));
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

    /**
     * Backs the review queue's live refresh: when the treasurer changes the
     * selected member on an already-rendered row (correcting a wrong
     * suggestion, or filling a blank slot), the amount/age/nights detail
     * needs to reflect the newly picked person instead of staying frozen on
     * whoever was originally suggested.
     */
    public function ajax_member_fee_detail(): void {
        check_ajax_referer('avbk_review_queue', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error('Geen toegang.', 403);
        }
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $types = array_values(array_intersect(
            array_map('sanitize_key', (array) ($_POST['types'] ?? [])),
            array_values(AVBK_DB::activity_fee_type_map())
        ));
        if (!$member_id) {
            wp_send_json_error('Ontbrekend lid.', 400);
        }
        wp_send_json_success(AVBK_DB::get_member_fee_detail($member_id, $types));
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
     * use elsewhere in this codebase.
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
        $candidates = array_values(array_filter(
            AVPVH_DB::get_extended_household($member_id),
            fn($m) => (int) $m->id !== $member_id
        ));
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

    public function handle_save_settings(): void {
        check_admin_referer('avbk_save_settings');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        update_option('avbk_club_iban', strtoupper(str_replace(' ', '', sanitize_text_field(wp_unslash($_POST['club_iban'] ?? '')))));
        update_option('avbk_club_name', sanitize_text_field(wp_unslash($_POST['club_name'] ?? '')));
        update_option('avbk_reference_prefix', sanitize_text_field(wp_unslash($_POST['reference_prefix'] ?? 'PVH')));
        update_option('avbk_penningmeester_email', sanitize_email(wp_unslash($_POST['penningmeester_email'] ?? '')) ?: 'info@avphilipsvanhorne.nl');
        wp_safe_redirect(add_query_arg(['page' => 'avbk-rates', 'settings_saved' => '1'], admin_url('admin.php')));
        exit;
    }
}
