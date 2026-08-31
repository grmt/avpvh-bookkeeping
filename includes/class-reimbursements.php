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
        add_action('admin_post_avbk_update_reimbursement', [$this, 'handle_update']);
        add_action('admin_post_avbk_update_receipt', [$this, 'handle_update_receipt']);
        add_action('admin_post_avbk_member_update_reimbursement', [$this, 'handle_member_update']);
        add_action('admin_post_avbk_withdraw_reimbursement', [$this, 'handle_withdraw']);
        add_action('admin_post_avbk_member_add_receipt', [$this, 'handle_member_add_receipt']);
        add_action('admin_post_avbk_member_remove_receipt', [$this, 'handle_member_remove_receipt']);
        add_action('admin_post_avbk_mark_reimbursement_paid', [$this, 'handle_mark_paid']);
        add_action('admin_post_avbk_reject_reimbursement', [$this, 'handle_reject']);
        add_action('admin_post_avbk_view_receipt', [$this, 'handle_view_receipt']);
    }

    private function can_manage(): bool {
        return current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('penningmeester');
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
        $status_labels = ['pending' => 'In behandeling', 'paid' => 'Uitbetaald', 'rejected' => 'Afgewezen', 'withdrawn' => 'Ingetrokken'];

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
            <?php if (!empty($_GET['reimbursement_duplicate'])) : ?>
                <p class="avbk-reimbursement-notice avbk-reimbursement-notice-error">Dit bonnetje lijkt al eerder gedeclareerd te zijn (zelfde foto, of hetzelfde bedrag/winkel/datum). Neem contact op met de penningmeester als dit niet klopt.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" id="avbk-reimbursement-form">
                <?php wp_nonce_field('avbk_submit_reimbursement'); ?>
                <input type="hidden" name="action" value="avbk_submit_reimbursement">

                <p>
                    <label for="avbk-receipt-input">Foto's van het bonnetje (meerdere mogelijk)</label><br>
                    <div class="avbk-dropzone" id="avbk-dropzone" tabindex="0">
                        <span class="avbk-dropzone-text">Sleep een of meer foto's hierheen, of klik om te kiezen</span>
                        <input type="file" name="receipt[]" id="avbk-receipt-input" accept="image/*" capture="environment" multiple required>
                    </div>
                    <ul class="avbk-dropzone-list" id="avbk-dropzone-list"></ul>
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
                    <label for="avbk-iban-select">Rekeningnummer (waarop je het bedrag terug wilt)</label><br>
                    <?php if (count($known_ibans) > 1) : ?>
                        <select id="avbk-iban-select" class="avbk-iban-select" data-target="avbk-iban">
                            <?php foreach ($known_ibans as $known) : ?>
                                <option value="<?php echo esc_attr($known->iban); ?>">
                                    <?php echo esc_html(($known->account_name ?: $known->iban) . ' (' . $known->iban . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="">Ander rekeningnummer&hellip;</option>
                        </select>
                        <input type="text" name="iban" id="avbk-iban" class="regular-text" value="<?php echo esc_attr($known_ibans[0]->iban); ?>" placeholder="NL00BANK0123456789" hidden>
                    <?php else : ?>
                        <input type="text" name="iban" id="avbk-iban" class="regular-text" value="<?php echo esc_attr($known_ibans[0]->iban ?? ''); ?>" placeholder="NL00BANK0123456789" required>
                    <?php endif; ?>
                </p>

                <p><button type="submit" class="button button-primary">Declareren</button></p>
            </form>

            <?php if ($requests) : ?>
                <h3>Eerdere declaraties</h3>
                <table class="avbk-reimbursement-table">
                    <thead><tr><th>Datum</th><th>Omschrijving</th><th>Bedrag</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($requests as $r) :
                        $is_pending = $r->status === 'pending';
                        ?>
                        <tr>
                            <td><?php echo esc_html(wp_date('d-m-Y', strtotime($r->created_at))); ?></td>
                            <td><?php echo esc_html($r->description ?: '—'); ?></td>
                            <td>&euro; <?php echo esc_html(number_format((float) $r->amount, 2, ',', '.')); ?></td>
                            <td><span class="avbk-status-badge avbk-status-<?php echo esc_attr($r->status); ?>"><?php echo esc_html($status_labels[$r->status] ?? $r->status); ?></span></td>
                            <td><?php if ($is_pending) : ?><a href="#" class="avbk-reimbursement-edit-toggle" data-target="avbk-edit-row-<?php echo (int) $r->id; ?>">wijzigen</a><?php endif; ?></td>
                        </tr>
                        <?php if ($is_pending) :
                            $iban_matches_known = false;
                            foreach ($known_ibans as $known) {
                                if ($known->iban === $r->iban) {
                                    $iban_matches_known = true;
                                    break;
                                }
                            }
                            ?>
                            <tr class="avbk-reimbursement-edit-row" id="avbk-edit-row-<?php echo (int) $r->id; ?>" hidden>
                                <td colspan="5">
                                    <p class="avbk-reimbursement-receipts">
                                        <?php $own_receipts = AVBK_DB::get_reimbursement_receipts((int) $r->id); ?>
                                        <?php foreach ($own_receipts as $rec) :
                                            $rec_view_url = wp_nonce_url(add_query_arg(['action' => 'avbk_view_receipt', 'id' => $r->id, 'receipt' => $rec->id], admin_url('admin-post.php')), 'avbk_view_receipt');
                                            ?>
                                            <span class="avbk-reimbursement-receipt-thumb-wrap">
                                                <a href="<?php echo esc_url($rec_view_url); ?>" target="_blank"><img src="<?php echo esc_url($rec_view_url); ?>" alt="Bonnetje" class="avbk-reimbursement-receipt-thumb"></a>
                                                <?php if (count($own_receipts) > 1) : ?>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                                        <?php wp_nonce_field('avbk_member_remove_receipt'); ?>
                                                        <input type="hidden" name="action" value="avbk_member_remove_receipt">
                                                        <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                                                        <input type="hidden" name="receipt_id" value="<?php echo esc_attr($rec->id); ?>">
                                                        <button type="submit" class="avbk-reimbursement-receipt-remove" title="Verwijderen" onclick="return confirm('Dit bonnetje verwijderen?');">&times;</button>
                                                    </form>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </p>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="avbk-reimbursement-edit-form">
                                        <?php wp_nonce_field('avbk_member_add_receipt'); ?>
                                        <input type="hidden" name="action" value="avbk_member_add_receipt">
                                        <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                                        <label>Extra bonnetje toevoegen<br>
                                            <input type="file" name="receipt" accept="image/*">
                                        </label>
                                        <button type="submit" class="button">Toevoegen</button>
                                    </form>

                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-reimbursement-edit-form">
                                        <?php wp_nonce_field('avbk_member_update_reimbursement'); ?>
                                        <input type="hidden" name="action" value="avbk_member_update_reimbursement">
                                        <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                                        <p>
                                            <label>Activiteit<br>
                                                <select name="activity_id">
                                                    <option value="">&mdash; geen (algemene onkosten) &mdash;</option>
                                                    <?php foreach ($activities as $activity) : ?>
                                                        <option value="<?php echo esc_attr($activity->id); ?>" <?php selected((int) $r->activity_id, (int) $activity->id); ?>>
                                                            <?php echo esc_html($activity->name . ' (' . $activity->year . ')'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                        </p>
                                        <p>
                                            <label>Omschrijving<br>
                                                <input type="text" name="description" class="regular-text" value="<?php echo esc_attr($r->description); ?>">
                                            </label>
                                        </p>
                                        <p>
                                            <label>Bedrag<br>
                                                &euro; <input type="text" name="amount" value="<?php echo esc_attr(number_format((float) $r->amount, 2, ',', '')); ?>">
                                            </label>
                                        </p>
                                        <p>
                                            <label>Rekeningnummer<br>
                                                <?php if (count($known_ibans) > 1) : ?>
                                                    <select class="avbk-iban-select" data-target="avbk-edit-iban-<?php echo (int) $r->id; ?>">
                                                        <?php foreach ($known_ibans as $known) : ?>
                                                            <option value="<?php echo esc_attr($known->iban); ?>" <?php selected($r->iban, $known->iban); ?>>
                                                                <?php echo esc_html(($known->account_name ?: $known->iban) . ' (' . $known->iban . ')'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                        <option value="" <?php selected($iban_matches_known, false); ?>>Ander rekeningnummer&hellip;</option>
                                                    </select>
                                                <?php endif; ?>
                                                <input type="text" name="iban" id="avbk-edit-iban-<?php echo (int) $r->id; ?>" class="regular-text" value="<?php echo esc_attr($r->iban); ?>" <?php echo (count($known_ibans) > 1 && $iban_matches_known) ? 'hidden' : ''; ?>>
                                            </label>
                                        </p>
                                        <p>
                                            <button type="submit" class="button button-primary">Wijziging opslaan</button>
                                        </p>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('avbk_withdraw_reimbursement'); ?>
                                        <input type="hidden" name="action" value="avbk_withdraw_reimbursement">
                                        <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                                        <button type="submit" class="button" onclick="return confirm('Declaratie intrekken?');">Declaratie intrekken</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** AJAX: OCR a just-added photo before the real submit, purely to pre-fill the amount field (and warn early on a duplicate) — the temp upload is discarded either way, the authoritative duplicate check happens again in handle_submit(). One file per call — the dropzone calls this once per photo added. */
    public function ajax_ocr_receipt(): void {
        check_ajax_referer('avbk_ocr_receipt', 'nonce');
        if (!is_user_logged_in() || empty($_FILES['receipt']['tmp_name']) || !is_uploaded_file($_FILES['receipt']['tmp_name'])) {
            wp_send_json_error();
        }

        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        $tmp_path = $_FILES['receipt']['tmp_name'];
        $hash = hash_file('sha256', $tmp_path);
        $text = AVBK_OCR::extract_text($tmp_path);
        $amount = $text ? AVBK_OCR::guess_total($text) : null;
        $date = $text ? AVBK_OCR::guess_date($text) : null;
        $store = $text ? AVBK_OCR::guess_store($text) : null;
        $duplicate = $member && AVBK_DB::find_duplicate_receipt((int) $member->id, $hash, $date, $store);
        wp_send_json_success(['amount' => $amount, 'date' => $date, 'store' => $store, 'duplicate' => (bool) $duplicate]);
    }

    /**
     * Hashes, OCRs and duplicate-checks one already-uploaded temp file
     * without moving it yet — handle_submit() needs every file in a batch
     * to pass this before any of them are actually stored, so a duplicate
     * discovered on file 3 doesn't leave files 1-2 orphaned on disk.
     * $seen_hashes is shared across a batch to also catch the same photo
     * added twice in one submission (before either has a DB row to match
     * against). Returns null for an invalid or duplicate file.
     */
    private function analyze_uploaded_receipt(string $tmp_path, array &$seen_hashes, int $member_id): ?array {
        if (!is_uploaded_file($tmp_path)) {
            return null;
        }
        $hash = hash_file('sha256', $tmp_path);
        $text = AVBK_OCR::extract_text($tmp_path);
        $ocr_amount = $text ? AVBK_OCR::guess_total($text) : null;
        $ocr_date = $text ? AVBK_OCR::guess_date($text) : null;
        $ocr_store = $text ? AVBK_OCR::guess_store($text) : null;

        if (in_array($hash, $seen_hashes, true) || AVBK_DB::find_duplicate_receipt($member_id, $hash, $ocr_date, $ocr_store)) {
            return null;
        }
        $seen_hashes[] = $hash;

        return [
            'hash'      => $hash,
            'ocr_amount' => $ocr_amount,
            'ocr_date'   => $ocr_date,
            'ocr_store'  => $ocr_store,
        ];
    }

    /** Moves an already-validated temp upload into permanent storage under a random filename. */
    private function store_receipt_file(string $tmp_path, string $original_name): string {
        wp_mkdir_p(self::receipts_dir());
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $ext = preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'jpg';
        $random_name = wp_generate_password(32, false, false) . '.' . $ext;
        // WP_Filesystem->move() (not move_uploaded_file(), which Plugin
        // Check forbids) does the actual move — the caller already
        // verified is_uploaded_file() before we got here.
        self::filesystem()->move($tmp_path, self::receipts_dir() . '/' . $random_name);
        return $random_name;
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
        $files = $_FILES['receipt'] ?? null;
        $has_receipt = $files && !empty(array_filter((array) $files['tmp_name']));

        if (!$member || !$has_receipt || $amount <= 0 || $iban === '') {
            wp_safe_redirect(add_query_arg('reimbursement_error', '1', $redirect_url) . '#declareren');
            exit;
        }

        // Recomputed here rather than trusted from the earlier AJAX pre-fill
        // calls — this is the authoritative duplicate gate, not just a UX
        // hint, so it has to hold even with JS disabled or a tampered form.
        // Every file in the batch is validated before any of them are
        // actually stored (see analyze_uploaded_receipt()'s docblock).
        $seen_hashes = [];
        $analyzed = [];
        foreach ($files['tmp_name'] as $i => $tmp_path) {
            if (!$tmp_path) {
                continue;
            }
            $description = sanitize_text_field(wp_unslash($_POST['description'][$i] ?? ''));
            $info = $this->analyze_uploaded_receipt($tmp_path, $seen_hashes, (int) $member->id);
            if ($info === null) {
                wp_safe_redirect(add_query_arg('reimbursement_duplicate', '1', $redirect_url) . '#declareren');
                exit;
            }
            $analyzed[] = $info + ['tmp_path' => $tmp_path, 'name' => (string) $files['name'][$i], 'description' => $description];
        }
        if (!$analyzed) {
            wp_safe_redirect(add_query_arg('reimbursement_error', '1', $redirect_url) . '#declareren');
            exit;
        }

        $ocr_amounts = array_filter(array_column($analyzed, 'ocr_amount'), fn($v) => $v !== null);

        // One activity per declaration (it can only ever be booked against
        // one open fee item), but each bundled purchase gets its own
        // description — the parent's own description column is just a
        // joined summary for list views, refreshed once the receipts exist.
        $id = AVBK_DB::create_reimbursement([
            'member_id'   => (int) $member->id,
            'activity_id' => (int) ($_POST['activity_id'] ?? 0) ?: null,
            'description' => '',
            'amount'      => $amount,
            'ocr_amount'  => $ocr_amounts ? array_sum($ocr_amounts) : null,
            'iban'        => $iban,
        ]);
        foreach ($analyzed as $a) {
            $random_name = $this->store_receipt_file($a['tmp_path'], $a['name']);
            AVBK_DB::add_reimbursement_receipt($id, [
                'receipt_path' => $random_name,
                'receipt_hash' => $a['hash'],
                'description'  => $a['description'],
                'ocr_amount'   => $a['ocr_amount'],
                'ocr_date'     => $a['ocr_date'],
                'ocr_store'    => $a['ocr_store'],
            ]);
        }
        AVBK_DB::refresh_reimbursement_description_summary($id);
        AVBK_DB::remember_iban((int) $member->id, $iban, avpvh_format_name($member));

        $descriptions = implode(', ', array_filter(array_column($analyzed, 'description')));
        $to = get_option('avbk_penningmeester_email', 'info@avphilipsvanhorne.nl');
        $subject = sprintf('[AV-PvH] Nieuwe declaratie van %s', avpvh_format_name($member));
        $body = sprintf(
            "%s heeft een bonnetje gedeclareerd van € %s.\n\nOmschrijving: %s\n\nBeoordelen: %s",
            avpvh_format_name($member),
            number_format($amount, 2, ',', '.'),
            $descriptions,
            admin_url('admin.php?page=avbk-reimbursements')
        );
        wp_mail($to, $subject, $body);

        wp_safe_redirect(add_query_arg('reimbursement_submitted', '1', $redirect_url) . '#declareren');
        exit;
    }

    /** Penningmeester correcting a pending declaration before paying it — e.g. swapping in a different known IBAN for the same member. */
    public function handle_update(): void {
        check_admin_referer('avbk_update_reimbursement');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? ''));
        $iban = strtoupper(str_replace(' ', '', sanitize_text_field(wp_unslash($_POST['iban'] ?? ''))));
        if ($id && $amount > 0 && $iban !== '') {
            AVBK_DB::update_reimbursement($id, [
                'activity_id' => (int) ($_POST['activity_id'] ?? 0),
                'amount'      => $amount,
                'iban'        => $iban,
            ]);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-reimbursements', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    /** Penningmeester correcting one receipt's own description — e.g. adding detail, or fixing OCR's store+date pre-fill. */
    public function handle_update_receipt(): void {
        check_admin_referer('avbk_update_receipt');
        if (!$this->can_manage()) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $receipt_id = (int) ($_POST['receipt_id'] ?? 0);
        $description = sanitize_text_field(wp_unslash($_POST['description'] ?? ''));
        if ($id) {
            AVBK_DB::update_reimbursement_receipt_description($id, $receipt_id, $description);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-reimbursements', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    /** The declarant correcting their own still-pending declaration — see AVBK_DB::member_update_reimbursement() for the ownership+status guard. */
    public function handle_member_update(): void {
        check_admin_referer('avbk_member_update_reimbursement');
        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        $redirect_url = wp_get_referer() ?: home_url('/');
        $id = (int) ($_POST['id'] ?? 0);
        $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? ''));
        $iban = strtoupper(str_replace(' ', '', sanitize_text_field(wp_unslash($_POST['iban'] ?? ''))));

        if ($member && $id && $amount > 0 && $iban !== '') {
            AVBK_DB::member_update_reimbursement($id, (int) $member->id, [
                'activity_id' => (int) ($_POST['activity_id'] ?? 0),
                'amount'      => $amount,
                'iban'        => $iban,
            ]);
            AVBK_DB::remember_iban((int) $member->id, $iban, avpvh_format_name($member));
        }
        wp_safe_redirect($redirect_url . '#declareren');
        exit;
    }

    /** The declarant withdrawing their own still-pending declaration — e.g. an accidental duplicate submission. */
    public function handle_withdraw(): void {
        check_admin_referer('avbk_withdraw_reimbursement');
        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        $redirect_url = wp_get_referer() ?: home_url('/');
        $id = (int) ($_POST['id'] ?? 0);
        if ($member && $id) {
            AVBK_DB::withdraw_reimbursement($id, (int) $member->id);
        }
        wp_safe_redirect($redirect_url . '#declareren');
        exit;
    }

    /** The declarant attaching one more receipt photo to their own still-pending declaration. */
    public function handle_member_add_receipt(): void {
        check_admin_referer('avbk_member_add_receipt');
        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        $redirect_url = wp_get_referer() ?: home_url('/');
        $id = (int) ($_POST['id'] ?? 0);
        $reimbursement = $id ? AVBK_DB::get_reimbursement($id) : null;
        $owns_pending = $member && $reimbursement
            && (int) $reimbursement->member_id === (int) $member->id
            && $reimbursement->status === 'pending';
        $tmp_path = $_FILES['receipt']['tmp_name'] ?? '';

        if (!$owns_pending || !$tmp_path || !is_uploaded_file($tmp_path)) {
            wp_safe_redirect($redirect_url . '#declareren');
            exit;
        }

        $seen_hashes = [];
        $info = $this->analyze_uploaded_receipt($tmp_path, $seen_hashes, (int) $member->id);
        if ($info === null) {
            wp_safe_redirect(add_query_arg('reimbursement_duplicate', '1', $redirect_url) . '#declareren');
            exit;
        }
        $random_name = $this->store_receipt_file($tmp_path, (string) ($_FILES['receipt']['name'] ?? ''));
        AVBK_DB::add_reimbursement_receipt($id, [
            'receipt_path' => $random_name,
            'receipt_hash' => $info['hash'],
            'description'  => sanitize_text_field(wp_unslash($_POST['description'] ?? '')),
            'ocr_amount'   => $info['ocr_amount'],
            'ocr_date'     => $info['ocr_date'],
            'ocr_store'    => $info['ocr_store'],
        ]);
        AVBK_DB::refresh_reimbursement_description_summary($id);
        wp_safe_redirect($redirect_url . '#declareren');
        exit;
    }

    /** The declarant removing one wrongly-attached receipt from their own still-pending declaration — refuses to remove the last one (withdraw the whole declaration instead, see handle_withdraw()). */
    public function handle_member_remove_receipt(): void {
        check_admin_referer('avbk_member_remove_receipt');
        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        $redirect_url = wp_get_referer() ?: home_url('/');
        $id = (int) ($_POST['id'] ?? 0);
        $receipt_id = (int) ($_POST['receipt_id'] ?? 0);
        $reimbursement = $id ? AVBK_DB::get_reimbursement($id) : null;
        $owns_pending = $member && $reimbursement
            && (int) $reimbursement->member_id === (int) $member->id
            && $reimbursement->status === 'pending';

        if ($owns_pending && $receipt_id) {
            $receipts = AVBK_DB::get_reimbursement_receipts($id);
            if (count($receipts) > 1) {
                foreach ($receipts as $rec) {
                    if ((int) $rec->id === $receipt_id) {
                        $path = self::receipts_dir() . '/' . $rec->receipt_path;
                        if (file_exists($path)) {
                            self::filesystem()->delete($path);
                        }
                        AVBK_DB::delete_reimbursement_receipt($receipt_id);
                        AVBK_DB::refresh_reimbursement_description_summary($id);
                        break;
                    }
                }
            }
        }
        wp_safe_redirect($redirect_url . '#declareren');
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

        $receipt_id = (int) ($_GET['receipt'] ?? 0);
        $receipt = null;
        foreach (AVBK_DB::get_reimbursement_receipts($id) as $rec) {
            if ((int) $rec->id === $receipt_id) {
                $receipt = $rec;
                break;
            }
        }
        if (!$receipt) {
            wp_die('Niet gevonden.', 'Fout', ['response' => 404]);
        }

        $path = self::receipts_dir() . '/' . $receipt->receipt_path;
        if (!$receipt->receipt_path || !file_exists($path)) {
            wp_die('Bestand niet gevonden.', 'Fout', ['response' => 404]);
        }

        header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="bonnetje"');
        header('X-Content-Type-Options: nosniff');
        echo self::filesystem()->get_contents($path); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw binary image content, not HTML; there's nothing to escape.
        exit;
    }
}
