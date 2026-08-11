<?php
defined('ABSPATH') || exit;

class AVBK_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menus'], 5);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_avbk_upload_import',        [$this, 'handle_upload_import']);
        add_action('admin_post_avbk_confirm_transaction',  [$this, 'handle_confirm_transaction']);
        add_action('admin_post_avbk_ignore_transaction',   [$this, 'handle_ignore_transaction']);
        add_action('admin_post_avbk_save_contribution_rate',   [$this, 'handle_save_contribution_rate']);
        add_action('admin_post_avbk_delete_contribution_rate', [$this, 'handle_delete_contribution_rate']);
        add_action('admin_post_avbk_save_camp_rate',       [$this, 'handle_save_camp_rate']);
        add_action('admin_post_avbk_delete_camp_rate',     [$this, 'handle_delete_camp_rate']);
        add_action('admin_post_avbk_waive_fee_item',       [$this, 'handle_waive_fee_item']);
        add_action('admin_post_avbk_save_settings',        [$this, 'handle_save_settings']);
        add_action('admin_post_avbk_generate_contribution_fees_now', [$this, 'handle_generate_contribution_fees_now']);
        add_action('admin_post_avbk_generate_camp_fees_now',         [$this, 'handle_generate_camp_fees_now']);
        add_action('admin_post_avbk_recompute_suggestions',          [$this, 'handle_recompute_suggestions']);
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
    }

    public function enqueue_assets(string $hook): void {
        if (!str_contains($hook, 'avbk-')) {
            return;
        }
        wp_enqueue_style('avbk-admin', AVBK_PLUGIN_URL . 'assets/admin.css', [], avbk_asset_version('assets/admin.css'));
    }

    public function render_overview(): void { require AVBK_PLUGIN_DIR . 'admin/overview.php'; }
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

    public function handle_confirm_transaction(): void {
        check_admin_referer('avbk_confirm_transaction');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }

        $transaction_id = (int) ($_POST['transaction_id'] ?? 0);
        $type_hints = array_values(array_intersect(
            array_map('sanitize_key', (array) ($_POST['type'] ?? [])),
            ['contribution', 'camp']
        ));
        $type_hints = $type_hints ?: null;
        $member_ids = array_map('intval', (array) ($_POST['member_id'] ?? []));
        $amounts = array_map(fn($a) => (float) str_replace(',', '.', (string) $a), (array) ($_POST['amount'] ?? []));

        $member_amounts = [];
        foreach ($member_ids as $i => $member_id) {
            if ($member_id > 0 && ($amounts[$i] ?? 0) > 0) {
                $member_amounts[$member_id] = ($member_amounts[$member_id] ?? 0) + $amounts[$i];
            }
        }

        if ($transaction_id && $member_amounts) {
            AVBK_Import::confirm_transaction($transaction_id, $member_amounts, $type_hints);
        }

        wp_safe_redirect(add_query_arg(['page' => 'avbk-review', 'confirmed' => '1'], admin_url('admin.php')));
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

    public function handle_save_contribution_rate(): void {
        check_admin_referer('avbk_save_contribution_rate');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }

        $id = (int) ($_POST['id'] ?? 0);
        $year = (int) ($_POST['year'] ?? 0);
        $min_age = $_POST['min_age'] !== '' ? (int) $_POST['min_age'] : null;
        $max_age = $_POST['max_age'] !== '' ? (int) $_POST['max_age'] : null;
        $for_students = !empty($_POST['for_students']);
        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));

        if ($year && $amount > 0) {
            AVBK_DB::save_contribution_rate($id, $year, $min_age, $max_age, $label, $amount, $for_students);
        }

        wp_safe_redirect(add_query_arg(['page' => 'avbk-rates', 'rate_saved' => '1', 'year' => $year], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_contribution_rate(): void {
        check_admin_referer('avbk_delete_contribution_rate');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $year = (int) ($_POST['year'] ?? 0);
        if ($id) {
            AVBK_DB::delete_contribution_rate($id);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-rates', 'rate_deleted' => '1', 'year' => $year], admin_url('admin.php')));
        exit;
    }

    public function handle_save_camp_rate(): void {
        check_admin_referer('avbk_save_camp_rate');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $camp_id = (int) ($_POST['camp_id'] ?? 0);
        $min_age = $_POST['min_age'] !== '' ? (int) $_POST['min_age'] : null;
        $max_age = $_POST['max_age'] !== '' ? (int) $_POST['max_age'] : null;
        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        // 0 is a legitimate rate (e.g. kids 0-3 free), so only camp_id gates this — not day_rate > 0.
        $day_rate = (float) str_replace(',', '.', (string) ($_POST['day_rate'] ?? ''));

        if ($camp_id) {
            AVBK_DB::save_camp_rate($id, $camp_id, $min_age, $max_age, $label, $day_rate);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-rates', 'camp_id' => $camp_id, 'camp_rate_saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_camp_rate(): void {
        check_admin_referer('avbk_delete_camp_rate');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $camp_id = (int) ($_POST['camp_id'] ?? 0);
        if ($id) {
            AVBK_DB::delete_camp_rate($id);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-rates', 'camp_id' => $camp_id, 'camp_rate_deleted' => '1'], admin_url('admin.php')));
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
     * this camp — needed because the live save hook only fires on a *new*
     * save, so participation entered before a rate existed never generated
     * one on its own.
     */
    public function handle_generate_camp_fees_now(): void {
        check_admin_referer('avbk_generate_camp_fees_now');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 403);
        }
        $camp_id = (int) ($_POST['camp_id'] ?? 0);
        $count = $camp_id ? AVBK_Fee_Generation::generate_camp_fees($camp_id) : 0;
        wp_safe_redirect(add_query_arg([
            'page' => 'avbk-rates', 'camp_id' => $camp_id, 'camp_fees_generated' => $count,
        ], admin_url('admin.php')));
        exit;
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
        wp_safe_redirect(add_query_arg(['page' => 'avbk-rates', 'settings_saved' => '1'], admin_url('admin.php')));
        exit;
    }
}
