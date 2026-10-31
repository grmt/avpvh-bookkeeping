<?php
defined('ABSPATH') || exit;

/** The same company portal can be used in wp-admin or on a private front-end page. */
class AVBK_Company_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'menus']);
        add_action('admin_post_avbk_company', [$this, 'handle']);
        add_action('admin_post_avbk_company_document', [$this, 'download_document']);
        add_shortcode('avbk_company_bookkeeping', [$this, 'shortcode']);
        add_action('admin_enqueue_scripts', function ($hook) {
            if (str_contains($hook, 'avbk-companies')) $this->assets();
        });
        add_action('wp_enqueue_scripts', function () {
            global $post;
            if ($post && has_shortcode($post->post_content, 'avbk_company_bookkeeping')) $this->assets();
        });
    }

    private function assets(): void {
        wp_enqueue_style('avbk-company', AVBK_PLUGIN_URL . 'assets/company.css', [], avbk_asset_version('assets/company.css'));
        wp_enqueue_script('avbk-import', AVBK_PLUGIN_URL . 'assets/import.js', [], avbk_asset_version('assets/import.js'), true);
        wp_enqueue_script('avbk-company', AVBK_PLUGIN_URL . 'assets/company.js', [], avbk_asset_version('assets/company.js'), true);
    }

    public function menus(): void {
        if (!current_user_can('manage_options') && !AVBK_Companies::accessible()) return;
        add_menu_page('Bedrijfsboekhouding', 'Bedrijfsboekhouding', 'read', 'avbk-companies', [$this, 'render'], 'dashicons-building', 32);
    }

    public function shortcode(): string {
        if (!is_user_logged_in()) return '<p><a href="' . esc_url(wp_login_url(get_permalink())) . '">Log in voor de bedrijfsboekhouding</a>.</p>';
        ob_start(); $this->render(); return ob_get_clean();
    }

    public function render(): void {
        if (!is_user_logged_in()) return;
        $companies = AVBK_Companies::accessible();
        $company_id = isset($_GET['company_id']) ? (int) $_GET['company_id'] : (int) ($companies[0]->id ?? 0);
        $company = null;
        foreach ($companies as $candidate) if ((int) $candidate->id === $company_id) $company = $candidate;
        if ($company_id && !$company) { echo '<p>Geen toegang tot deze administratie.</p>'; return; }
        $year = (int) ($_GET['book_year'] ?? wp_date('Y'));
        $quarter = (int) ($_GET['quarter'] ?? max(1, (int) ceil((int) wp_date('n') / 3) - 1));
        try { AVBK_Companies::period($year, $quarter); }
        catch (\RuntimeException $e) { echo '<p>Ongeldig boekjaar of kwartaal.</p>'; return; }
        $return_to = is_admin() ? admin_url('admin.php?page=avbk-companies') : get_permalink();
        $notice = get_transient('avbk_company_notice_' . get_current_user_id());
        delete_transient('avbk_company_notice_' . get_current_user_id());
        $transactions = $company ? AVBK_Companies::transactions($company_id, $year, $quarter) : [];
        $card_transactions = $company ? AVBK_Company_Cards::transactions($company_id, $year, $quarter) : [];
        $report = $company ? AVBK_Company_Report::get($company_id, $year, $quarter) : null;
        $report_stale = $report && ($report['transactions_hash'] !== AVBK_Company_Report::fingerprint($transactions)
            || ($report['cards_hash'] ?? '') !== AVBK_Company_Report::fingerprint($card_transactions));
        require AVBK_PLUGIN_DIR . 'admin/companies.php';
    }

    private function uploaded(string $field, array $extensions): array {
        $file = $_FILES[$field] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || ($file['size'] ?? 0) > 20 * 1024 * 1024) {
            throw new \RuntimeException('Kies een bestand van maximaal 20 MB.');
        }
        $name = sanitize_file_name(wp_unslash($file['name']));
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions, true)) throw new \RuntimeException('Ongeldig bestandstype.');
        return [$file['tmp_name'], $name];
    }

    public function download_document(): void {
        check_admin_referer('avbk_company_document');
        try {
            $doc = AVBK_Company_Documents::document((int) ($_GET['company_id'] ?? 0), (int) ($_GET['document_id'] ?? 0));
            $bytes = $doc->document_data ? base64_decode($doc->document_data, true) : false;
            if ($bytes === false) throw new \RuntimeException('Deze factuur is een verwijzing zonder geüpload bestand.');
        } catch (\RuntimeException $e) { wp_die(esc_html($e->getMessage()), '', ['response' => 403]); }
        nocache_headers();
        header('Content-Type: ' . $doc->mime_type);
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($doc->filename) . '"');
        header('X-Content-Type-Options: nosniff');
        echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- private binary download, after company access and nonce checks
        exit;
    }

    public function handle(): void {
        check_admin_referer('avbk_company');
        if (!is_user_logged_in()) wp_die('Geen toegang.', 403);
        $company_id = (int) ($_POST['company_id'] ?? 0);
        $year = (int) ($_POST['book_year'] ?? 0); $quarter = (int) ($_POST['quarter'] ?? 0);
        $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
        $output = null;
        try {
            if ($operation === 'create') {
                $company_id = AVBK_Companies::create(sanitize_text_field(wp_unslash($_POST['company_name'] ?? '')), sanitize_text_field(wp_unslash($_POST['iban'] ?? '')), sanitize_text_field(wp_unslash($_POST['sheet_prefix'] ?? '')));
                $message = 'Administratie toegevoegd. Wijs hieronder de gebruikers toe.';
            } elseif ($operation === 'access') {
                AVBK_Companies::company($company_id, 'access');
                $user = get_user_by('email', sanitize_email(wp_unslash($_POST['email'] ?? '')));
                if (!$user) throw new \RuntimeException('Geen WordPress-account met dit e-mailadres. Laat de sitebeheerder eerst een account aanmaken.');
                AVBK_Companies::set_access($company_id, (int) $user->ID, sanitize_key(wp_unslash($_POST['role'] ?? '')));
                $message = 'Bedrijfstoegang bijgewerkt.';
            } elseif ($operation === 'card_account') {
                AVBK_Company_Cards::link($company_id, sanitize_text_field(wp_unslash($_POST['card_account'] ?? '')));
                $message = 'Creditcardaccount gekoppeld aan deze administratie.';
            } elseif ($operation === 'card_import') {
                AVBK_Companies::company($company_id, 'import');
                [$path, $filename] = $this->uploaded('card_statement', ['pdf', 'csv', 'xlsx']);
                if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf') {
                    AVBK_Company_Documents::add($company_id, 0, 'Creditcardafschrift ' . $year . ' Q' . $quarter . ' — nog uit te werken; geen transacties geïmporteerd', $path, $filename);
                    $message = 'Creditcardafschrift privé opgeslagen bij Facturen en bonnetjes. De aankoopregels en BTW moeten nog worden uitgewerkt; er zijn nog geen transacties toegevoegd.';
                } else {
                    $result = AVBK_Company_Cards::import($company_id, $path, $filename, $year, $quarter);
                    $message = sprintf('%d creditcardtransacties toegevoegd; %d al aanwezig; %d buiten dit kwartaal en %d van andere accounts overgeslagen. Werk nu de BTW-werkmap bij.', $result['added'], $result['duplicates'], $result['outside_period'], $result['outside_account']);
                }
            } elseif ($operation === 'invoice') {
                AVBK_Companies::company($company_id, 'import');
                $path = ''; $filename = '';
                if (isset($_FILES['invoice']) && ($_FILES['invoice']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    [$path, $filename] = $this->uploaded('invoice', ['pdf', 'jpg', 'jpeg', 'png', 'webp']);
                }
                AVBK_Company_Documents::add($company_id, (int) ($_POST['transaction_id'] ?? 0), sanitize_text_field(wp_unslash($_POST['invoice_reference'] ?? '')), $path, $filename);
                $message = 'Factuur toegevoegd. De BTW-behandeling moet nog worden gecontroleerd.';
            } elseif ($operation === 'import') {
                AVBK_Companies::company($company_id, 'import');
                [$path, $filename] = $this->uploaded('bank_export', ['csv', 'xlsx']);
                $result = AVBK_Companies::import($company_id, $path, $filename, $year, $quarter);
                $message = sprintf('%d transacties toegevoegd; %d al aanwezig. ', $result['added'], $result['duplicates']);
                if ($result['outside_period']) $message .= sprintf('%d transacties buiten het gekozen kwartaal overgeslagen. ', $result['outside_period']);
                $message .= 'Werk nu het kwartaalwerkblad bij en controleer de BTW per factuur.';
            } elseif ($operation === 'export') {
                $company = AVBK_Companies::company($company_id, 'export');
                [$path] = $this->uploaded('workbook', ['xlsx']);
                $output = wp_tempnam('avbk-company-workbook.xlsx');
                if (!$output) throw new \RuntimeException('Kan geen tijdelijke werkmap maken.');
                $transactions = AVBK_Companies::transactions($company_id, $year, $quarter);
                $cards = AVBK_Company_Cards::transactions($company_id, $year, $quarter);
                AVBK_Company_Workbook::update($path, $output, $company, $transactions, $year, $quarter, $cards);
                AVBK_Company_Report::save(AVBK_Company_Report::build($output, $company, $transactions, $year, $quarter, $year . ' BTW-' . $company->sheet_prefix . '-Q' . $quarter . '.xlsx', $cards));
                nocache_headers();
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . sanitize_file_name($year . ' BTW-' . $company->sheet_prefix . '-Q' . $quarter . '.xlsx') . '"');
                header('X-Content-Type-Options: nosniff');
                readfile($output); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- stream a private generated download
                wp_delete_file($output); exit;
            } else throw new \RuntimeException('Onbekende actie.');
            set_transient('avbk_company_notice_' . get_current_user_id(), ['error' => false, 'message' => $message], 120);
        } catch (\Throwable $e) {
            if ($output) wp_delete_file($output);
            $message = $e instanceof \RuntimeException ? $e->getMessage() : 'De bewerking is mislukt. Controleer het bestand en probeer opnieuw.';
            if ($operation === 'export' && ($_SERVER['HTTP_X_AVBK_DOWNLOAD'] ?? '') === '1') wp_send_json_error(['message' => $message], 400);
            set_transient('avbk_company_notice_' . get_current_user_id(), ['error' => true, 'message' => $message], 120);
        }
        $return_to = wp_validate_redirect(esc_url_raw(wp_unslash($_POST['return_to'] ?? '')), admin_url('admin.php?page=avbk-companies'));
        $args = ['company_id' => $company_id];
        if ($year >= 2000 && $year <= 2100 && $quarter >= 1 && $quarter <= 4) $args += ['book_year' => $year, 'quarter' => $quarter];
        wp_safe_redirect(add_query_arg($args, $return_to)); exit;
    }
}
