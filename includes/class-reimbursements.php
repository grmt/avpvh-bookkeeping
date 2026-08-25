<?php
defined('ABSPATH') || exit;

/**
 * "Bonnetje declareren" — a member uploads a photo of a receipt, the
 * amount is OCR-pre-filled (AVBK_OCR, always just a suggestion — the
 * member confirms/edits it) and the penningmeester pays it back. The
 * reverse money direction from every other flow in this plugin: the club
 * owes the member, so AVBK_QR::for_reimbursement() targets the member's
 * own IBAN, not the club's.
 *
 * Receipt photos are personal financial documents (same caution as bank
 * exports, see AVBK_Import) — stored outside wp-content/uploads under a
 * random filename, never a public URL, only ever served through
 * handle_view_receipt()'s ownership/capability check.
 */
class AVBK_Reimbursements {

    public static function receipts_dir(): string {
        return WP_CONTENT_DIR . '/avbk-receipts';
    }

    public function __construct() {
        add_shortcode('avpvh_bk_reimbursement', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_avbk_ocr_receipt', [$this, 'ajax_ocr_receipt']);
        add_action('admin_post_avbk_submit_reimbursement', [$this, 'handle_submit']);
        add_action('admin_post_avbk_mark_reimbursement_paid', [$this, 'handle_mark_paid']);
        add_action('admin_post_avbk_reject_reimbursement', [$this, 'handle_reject']);
        add_action('admin_post_avbk_view_receipt', [$this, 'handle_view_receipt']);
    }

    private function can_manage(): bool {
        return current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('penningmeester');
    }

    public function enqueue_assets(): void {
        if (!is_user_logged_in()) {
            return;
        }
        global $post;
        if (!$post || !has_shortcode((string) $post->post_content, 'avpvh_bk_reimbursement')) {
            return;
        }
        wp_enqueue_style('avbk-reimbursement', AVBK_PLUGIN_URL . 'assets/reimbursement.css', [], avbk_asset_version('assets/reimbursement.css'));
        wp_enqueue_script('avbk-reimbursement', AVBK_PLUGIN_URL . 'assets/reimbursement.js', [], avbk_asset_version('assets/reimbursement.js'), true);

        $config = wp_json_encode([
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('avbk_ocr_receipt'),
        ]);
        add_action('wp_footer', function () use ($config) {
            echo '<script type="application/json" id="avbk-reimbursement-config">' . $config . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output containing only an admin-ajax URL and a nonce, same CSP-safe pattern as class-fee-popup.php.
        });
    }

    public function render(): string {
        if (!is_user_logged_in()) {
            return '';
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        if (!$member) {
            return '';
        }

        $activities = AVPVH_DB::get_activities();
        $known_ibans = AVBK_DB::get_known_ibans_for_member((int) $member->id);
        $requests = AVBK_DB::get_reimbursements_for_member((int) $member->id);
        $status_labels = ['pending' => 'In behandeling', 'paid' => 'Uitbetaald', 'rejected' => 'Afgewezen'];

        ob_start();
        ?>
        <div class="avbk-reimbursement" id="declareren">
            <h2>Bonnetje declareren</h2>

            <?php if (!empty($_GET['reimbursement_submitted'])) : ?>
                <p class="avbk-reimbursement-notice">Je declaratie is verstuurd naar de penningmeester.</p>
            <?php endif; ?>
            <?php if (!empty($_GET['reimbursement_error'])) : ?>
                <p class="avbk-reimbursement-notice avbk-reimbursement-notice-error">Upload een foto van het bonnetje en vul een bedrag en rekeningnummer in.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" id="avbk-reimbursement-form">
                <?php wp_nonce_field('avbk_submit_reimbursement'); ?>
                <input type="hidden" name="action" value="avbk_submit_reimbursement">
                <input type="hidden" name="ocr_amount" id="avbk-ocr-amount" value="">

                <p>
                    <label for="avbk-receipt-input">Foto van het bonnetje</label><br>
                    <input type="file" name="receipt" id="avbk-receipt-input" accept="image/*" capture="environment" required>
                </p>

                <p id="avbk-ocr-status" class="avbk-reimbursement-ocr-status" hidden></p>

                <p>
                    <label for="avbk-activity">Activiteit</label><br>
                    <select name="activity_id" id="avbk-activity">
                        <option value="">&mdash; geen (algemene onkosten) &mdash;</option>
                        <?php foreach ($activities as $activity) : ?>
                            <option value="<?php echo esc_attr($activity->id); ?>">
                                <?php echo esc_html($activity->name . ' (' . $activity->year . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p>
                    <label for="avbk-description">Omschrijving</label><br>
                    <input type="text" name="description" id="avbk-description" class="regular-text" placeholder="Bijv. boodschappen voor het weekend">
                </p>

                <p>
                    <label for="avbk-amount">Bedrag</label><br>
                    &euro; <input type="text" name="amount" id="avbk-amount" placeholder="0,00" required>
                </p>

                <p>
                    <label for="avbk-iban">Rekeningnummer (waarop je het bedrag terug wilt)</label><br>
                    <input type="text" name="iban" id="avbk-iban" class="regular-text" value="<?php echo esc_attr($known_ibans[0] ?? ''); ?>" placeholder="NL00BANK0123456789" required>
                    <?php if (count($known_ibans) > 1) : ?>
                        <br><small>Eerder bij ons bekend: <?php echo esc_html(implode(', ', $known_ibans)); ?></small>
                    <?php endif; ?>
                </p>

                <p><button type="submit" class="button button-primary">Declareren</button></p>
            </form>

            <?php if ($requests) : ?>
                <h3>Eerdere declaraties</h3>
                <table class="avbk-reimbursement-table">
                    <thead><tr><th>Datum</th><th>Omschrijving</th><th>Bedrag</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($requests as $r) : ?>
                        <tr>
                            <td><?php echo esc_html(wp_date('d-m-Y', strtotime($r->created_at))); ?></td>
                            <td><?php echo esc_html($r->description ?: '—'); ?></td>
                            <td>&euro; <?php echo esc_html(number_format((float) $r->amount, 2, ',', '.')); ?></td>
                            <td><span class="avbk-status-badge avbk-status-<?php echo esc_attr($r->status); ?>"><?php echo esc_html($status_labels[$r->status] ?? $r->status); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** AJAX: OCR a just-selected photo before the real submit, purely to pre-fill the amount field — the temp upload is discarded either way. */
    public function ajax_ocr_receipt(): void {
        check_ajax_referer('avbk_ocr_receipt', 'nonce');
        if (!is_user_logged_in() || empty($_FILES['receipt']['tmp_name']) || !is_uploaded_file($_FILES['receipt']['tmp_name'])) {
            wp_send_json_error();
        }

        $text = AVBK_OCR::extract_text($_FILES['receipt']['tmp_name']);
        $amount = $text ? AVBK_OCR::guess_total($text) : null;
        wp_send_json_success(['amount' => $amount]);
    }

    public function handle_submit(): void {
        check_admin_referer('avbk_submit_reimbursement');
        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        $redirect_url = wp_get_referer() ?: home_url('/');

        $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? ''));
        $iban = strtoupper(str_replace(' ', '', sanitize_text_field(wp_unslash($_POST['iban'] ?? ''))));
        $has_receipt = !empty($_FILES['receipt']['tmp_name']) && is_uploaded_file($_FILES['receipt']['tmp_name']);

        if (!$member || !$has_receipt || $amount <= 0 || $iban === '') {
            wp_safe_redirect(add_query_arg('reimbursement_error', '1', $redirect_url) . '#declareren');
            exit;
        }

        wp_mkdir_p(self::receipts_dir());
        $ext = strtolower(pathinfo((string) $_FILES['receipt']['name'], PATHINFO_EXTENSION));
        $ext = preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'jpg';
        $random_name = wp_generate_password(32, false, false) . '.' . $ext;
        move_uploaded_file($_FILES['receipt']['tmp_name'], self::receipts_dir() . '/' . $random_name);

        $ocr_amount = $_POST['ocr_amount'] !== '' ? (float) str_replace(',', '.', (string) $_POST['ocr_amount']) : null;

        $id = AVBK_DB::create_reimbursement([
            'member_id'    => (int) $member->id,
            'activity_id'  => (int) ($_POST['activity_id'] ?? 0) ?: null,
            'description'  => sanitize_text_field(wp_unslash($_POST['description'] ?? '')),
            'amount'       => $amount,
            'ocr_amount'   => $ocr_amount,
            'receipt_path' => $random_name,
            'iban'         => $iban,
        ]);
        AVBK_DB::remember_iban((int) $member->id, $iban);

        $to = get_option('avbk_penningmeester_email', 'info@avphilipsvanhorne.nl');
        $subject = sprintf('[AV-PvH] Nieuwe declaratie van %s', avpvh_format_name($member));
        $body = sprintf(
            "%s heeft een bonnetje gedeclareerd van € %s.\n\nOmschrijving: %s\n\nBeoordelen: %s",
            avpvh_format_name($member),
            number_format($amount, 2, ',', '.'),
            $_POST['description'] ?? '',
            admin_url('admin.php?page=avbk-reimbursements')
        );
        wp_mail($to, $subject, $body);

        wp_safe_redirect(add_query_arg('reimbursement_submitted', '1', $redirect_url) . '#declareren');
        exit;
    }

    public function handle_mark_paid(): void {
        check_admin_referer('avbk_mark_reimbursement_paid');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            AVBK_DB::mark_reimbursement_paid($id, get_current_user_id());
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-reimbursements', 'paid' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_reject(): void {
        check_admin_referer('avbk_reject_reimbursement');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            AVBK_DB::reject_reimbursement($id);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-reimbursements', 'rejected' => '1'], admin_url('admin.php')));
        exit;
    }

    /** Streams a receipt photo — never a public URL, gated on being the submitter or bestuur/penningmeester. */
    public function handle_view_receipt(): void {
        $id = (int) ($_GET['id'] ?? 0);
        $reimbursement = $id ? AVBK_DB::get_reimbursement($id) : null;
        if (!$reimbursement) {
            wp_die('Niet gevonden.', 'Fout', ['response' => 404]);
        }

        $member = is_user_logged_in() ? avpvh_get_member_by_wp_user(get_current_user_id()) : null;
        $is_owner = $member && (int) $member->id === (int) $reimbursement->member_id;
        if (!$is_owner && !$this->can_manage()) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }

        $path = self::receipts_dir() . '/' . $reimbursement->receipt_path;
        if (!$reimbursement->receipt_path || !file_exists($path)) {
            wp_die('Bestand niet gevonden.', 'Fout', ['response' => 404]);
        }

        header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="bonnetje"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
