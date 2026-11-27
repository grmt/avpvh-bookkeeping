<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/functions-mail.php';

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
 * random filename, served through handle_view_receipt()'s ownership/capability
 * check. The web server must deny direct access to the storage directory.
 */
class AVBK_Reimbursements implements AVBK_Component {

    public const MAX_BYTES = 25 * 1024 * 1024;
    private const INVALID_FILE_ERROR = 'Ongeldig bestand';
    public const ALLOWED_MIMES = [
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'image/webp'      => ['webp'],
        'image/heic'      => ['heic'],
        'image/heif'      => ['heif'],
        'application/pdf' => ['pdf'],
    ];

    public static function receipts_dir(): string {
        return defined('AVBK_RECEIPTS_DIR') ? rtrim(AVBK_RECEIPTS_DIR, '/\\') : WP_CONTENT_DIR . '/avbk-receipts';
    }

    public static function ensure_receipts_dir_protected(): void {
        $dir = self::receipts_dir();
        wp_mkdir_p($dir);
        $htaccess = $dir . '/.htaccess';
        // Refresh access rules when the directory already exists.
        self::filesystem()->put_contents(
            $htaccess,
            "# Financial documents are served only through authenticated PHP handlers.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder Deny,Allow\nDeny from all\n</IfModule>\nOptions -Indexes\n"
        );
        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            self::filesystem()->put_contents($index, '');
        }
    }

    /**
     * Validates an uploaded receipt file's size, extension, and real bytes.
     * SVG, HTML, and script extensions are strictly excluded.
     *
     * @return string|null Normalized extension on success, null on failure.
     */
    public static function validate_receipt_file(string $tmp_path, string $original_name): ?string {
        return is_uploaded_file($tmp_path) ? self::validate_receipt_content($tmp_path, $original_name) : null;
    }

    /** validate_receipt_file() without the upload check: size, extension and real bytes of a file on disk. */
    public static function validate_receipt_content(string $tmp_path, string $original_name): ?string {
        $size = filesize($tmp_path);
        $ext = strtolower(pathinfo(sanitize_file_name($original_name), PATHINFO_EXTENSION));
        if (!$size || $size > self::MAX_BYTES || !in_array($ext, array_merge(...array_values(self::ALLOWED_MIMES)), true)) {
            return null;
        }
        $valid = $ext === 'pdf' ? self::is_pdf($tmp_path) : self::is_image_of_type($tmp_path, $ext);
        return $valid ? $ext : null;
    }

    /** The file starts with the PDF signature. */
    private static function is_pdf(string $path): bool {
        return file_get_contents($path, false, null, 0, 5) === '%PDF-';
    }

    /** The file's real image type allows this extension. */
    private static function is_image_of_type(string $path, string $ext): bool {
        $mime = (string) (function_exists('wp_get_image_mime') ? wp_get_image_mime($path) : mime_content_type($path));
        if ($mime === 'image/x-ms-bmp') {
            $mime = 'image/bmp';
        }
        return in_array($ext, self::ALLOWED_MIMES[$mime] ?? [], true);
    }

    public function register(): void {
        add_shortcode('avpvh_bk_reimbursement', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_avbk_ocr_receipt', [$this, 'ajax_ocr_receipt']);
        add_action('wp_ajax_avbk_ocr_region', [$this, 'ajax_ocr_region']);
        add_action('wp_ajax_avbk_draft_ensure', [$this, 'ajax_draft_ensure']);
        add_action('wp_ajax_avbk_draft_add_receipt', [$this, 'ajax_draft_add_receipt']);
        add_action('wp_ajax_avbk_draft_add_manual', [$this, 'ajax_draft_add_manual']);
        add_action('wp_ajax_avbk_draft_retry_receipt', [$this, 'ajax_draft_retry_receipt']);
        add_action('wp_ajax_avbk_draft_save_receipt', [$this, 'ajax_draft_save_receipt']);
        add_action('wp_ajax_avbk_draft_save', [$this, 'ajax_draft_save']);
        add_action('wp_ajax_avbk_draft_remove_receipt', [$this, 'ajax_draft_remove_receipt']);
        add_action('wp_ajax_avbk_draft_discard', [$this, 'ajax_draft_discard']);
        add_action('wp_ajax_avbk_member_update_receipt', [$this, 'ajax_member_update_receipt']);
        add_action('wp_ajax_avbk_member_attach_receipt_photo', [$this, 'ajax_member_attach_receipt_photo']);
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
        add_action('admin_post_avbk_reimbursement_seen', [$this, 'handle_seen']);
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
        wp_enqueue_script('avbk-receipt-viewer', AVBK_PLUGIN_URL . 'assets/receipt-viewer.js', [], avbk_asset_version('assets/receipt-viewer.js'), true);
        wp_enqueue_script('avbk-reimbursement', AVBK_PLUGIN_URL . 'assets/reimbursement.js', ['avbk-receipt-viewer'], avbk_asset_version('assets/reimbursement.js'), true);

        $config = wp_json_encode([
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('avbk_ocr_receipt'),
            'engines' => AVBK_OCR::engines(),
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
        $draft = AVBK_DB::get_draft_reimbursement((int) $member->id);
        $draft_receipts = $draft ? AVBK_DB::get_reimbursement_receipts((int) $draft->id) : [];
        $draft_iban = $draft && trim((string) $draft->iban) !== '' ? (string) $draft->iban : (string) ($known_ibans[0]->iban ?? '');
        $draft_iban_known = false;
        foreach ($known_ibans as $known) {
            if ($known->iban === $draft_iban) {
                $draft_iban_known = true;
                break;
            }
        }
        // Seeing the list here is seeing the decisions: no notice on the member page about them any more.
        AVBK_DB::mark_reimbursement_decisions_seen((int) $member->id);
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
                <input type="hidden" name="draft_id" id="avbk-draft-id" value="<?php echo $draft ? (int) $draft->id : ''; ?>">

                <div class="avbk-draft-bar" id="avbk-draft-bar"<?php echo $draft ? '' : ' hidden'; ?>>
                    <span id="avbk-draft-status" aria-live="polite"><?php echo $draft ? 'Concept hersteld — wijzigingen worden automatisch opgeslagen' : ''; ?></span>
                    <button type="button" class="avbk-draft-discard" id="avbk-draft-discard">Concept wissen</button>
                </div>

                <p>
                    <label for="avbk-activity">Activiteit</label><br>
                    <select name="activity_id" id="avbk-activity">
                        <option value="">&mdash; geen (algemene onkosten) &mdash;</option>
                        <?php foreach ($activities as $activity) : ?>
                            <option value="<?php echo esc_attr($activity->id); ?>" <?php selected((int) ($draft->activity_id ?? 0), (int) $activity->id); ?>>
                                <?php echo esc_html($activity->name . ' (' . $activity->year . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p>
                    <label for="avbk-receipt-input">Bonnetjes</label><br>
                    <div class="avbk-dropzone" id="avbk-dropzone">
                        <span class="avbk-dropzone-text">Sleep een of meer foto's hierheen, of klik om te kiezen</span>
                        <input type="file" name="receipt[]" id="avbk-receipt-input" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf" capture="environment" multiple>
                    </div>
                    <button type="button" class="button" id="avbk-add-manual-row">+ Regel toevoegen zonder bonnetje</button>
                    <table class="avbk-dropzone-table" id="avbk-dropzone-table" hidden>
                        <thead><tr><th>Foto</th><th><button type="button" class="avbk-dropzone-sort" id="avbk-sort-date" title="Sorteer op datum">Datum <span class="avbk-sort-indicator" aria-hidden="true">↕</span></button></th><th>Winkel</th><th>Omschrijving</th><th class="avbk-dropzone-amount-head">Bedrag</th><th></th></tr></thead>
                        <tbody id="avbk-dropzone-list"></tbody>
                    </table>
                </p>

                <p id="avbk-ocr-status" class="avbk-reimbursement-ocr-status" hidden></p>

                <p>
                    <label for="avbk-amount">Totaal bedrag</label><br>
                    &euro; <input type="text" name="amount" id="avbk-amount" placeholder="0,00" value="<?php echo $draft && (float) $draft->amount > 0 ? esc_attr(number_format((float) $draft->amount, 2, ',', '')) : ''; ?>" required>
                </p>

                <p>
                    <?php if (count($known_ibans) > 1) : ?>
                        <label for="avbk-iban-select">Rekeningnummer (waarop je het bedrag terug wilt)</label><br>
                        <select id="avbk-iban-select" class="avbk-iban-select" data-target="avbk-iban">
                            <?php foreach ($known_ibans as $known) : ?>
                                <option value="<?php echo esc_attr($known->iban); ?>" <?php selected($draft_iban, $known->iban); ?>>
                                    <?php echo esc_html(($known->account_name ?: $known->iban) . ' (' . $known->iban . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="" <?php selected($draft_iban_known, false); ?>>Ander rekeningnummer&hellip;</option>
                        </select>
                        <label class="screen-reader-text" for="avbk-iban">Ander rekeningnummer</label>
                        <input type="text" name="iban" id="avbk-iban" class="regular-text" value="<?php echo esc_attr($draft_iban); ?>" placeholder="NL00BANK0123456789"<?php echo $draft_iban_known ? ' hidden' : ' required'; ?>>
                    <?php else : ?>
                        <label for="avbk-iban">Rekeningnummer (waarop je het bedrag terug wilt)</label><br>
                        <input type="text" name="iban" id="avbk-iban" class="regular-text" value="<?php echo esc_attr($draft_iban); ?>" placeholder="NL00BANK0123456789" required>
                    <?php endif; ?>
                </p>

                <p>
                    <label for="avbk-member-note">Toelichting voor de penningmeester (optioneel)</label><br>
                    <textarea name="member_note" id="avbk-member-note" rows="3" class="large-text" maxlength="2000" placeholder="Bijv.: voorgeschoten voor de hele groep, of: graag op de rekening van mijn partner"><?php echo esc_textarea((string) ($draft->member_note ?? '')); ?></textarea>
                </p>

                <p><button type="submit" class="button button-primary">Declareren</button></p>
            </form>
            <?php if ($draft) :
                $draft_json = wp_json_encode([
                    'id'       => (int) $draft->id,
                    'receipts' => array_map(fn($rec) => self::receipt_to_client((int) $draft->id, $rec), $draft_receipts),
                ]);
                add_action('wp_footer', static function () use ($draft_json) {
                    echo '<script type="application/json" id="avbk-reimbursement-draft">' . $draft_json . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON payload inside application/json script tag, emitted in wp_footer so wpautop/wptexturize never alters it.
                });
            endif; ?>

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
                        <?php if ($r->status === 'rejected' && trim((string) ($r->rejection_reason ?? '')) !== '') : ?>
                            <tr class="avbk-reimbursement-reason"><td colspan="5"><strong>Reden:</strong> <?php echo nl2br(esc_html((string) $r->rejection_reason)); ?> <a href="<?php echo esc_url(AVBK_Disputes::member_page_url((int) $r->member_id)); ?>">Reageren in Berichten</a></td></tr>
                        <?php endif; ?>
                        <?php if ($is_pending) :
                            self::render_pending_edit_row($r, $activities, $known_ibans);
                        endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Renders the expandable edit panel (editable receipt table + header form + withdraw) for one pending declaration. */
    private static function render_pending_edit_row(object $r, array $activities, array $known_ibans): void {
        $iban_matches_known = false;
        foreach ($known_ibans as $known) {
            if ($known->iban === $r->iban) {
                $iban_matches_known = true;
                break;
            }
        }
        $own_receipts = AVBK_DB::get_reimbursement_receipts((int) $r->id);
        $own_total = 0.0;
        foreach ($own_receipts as $rec_item) {
            if ($rec_item->amount !== null) {
                $own_total += (float) $rec_item->amount;
            }
        }
        $can_remove = count($own_receipts) > 1;
        ?>
        <tr class="avbk-reimbursement-edit-row" id="avbk-edit-row-<?php echo (int) $r->id; ?>" hidden>
            <td colspan="5">
                <div class="avbk-pending-receipts" data-reimbursement-id="<?php echo (int) $r->id; ?>">
                    <div class="avbk-pending-toolbar">
                        <input type="search" class="avbk-pending-filter" placeholder="Filter op datum, winkel, omschrijving of bedrag…" aria-label="Filter bonnetjes">
                        <span class="avbk-pending-status" aria-live="polite"></span>
                    </div>
                    <table class="avbk-dropzone-table avbk-pending-table">
                        <thead>
                            <tr>
                                <th>Foto</th>
                                <th><button type="button" class="avbk-dropzone-sort" data-sort="date" title="Sorteer op datum">Datum <span class="avbk-sort-indicator" aria-hidden="true">↕</span></button></th>
                                <th><button type="button" class="avbk-dropzone-sort" data-sort="store" title="Sorteer op winkel">Winkel <span class="avbk-sort-indicator" aria-hidden="true">↕</span></button></th>
                                <th><button type="button" class="avbk-dropzone-sort" data-sort="description" title="Sorteer op omschrijving">Omschrijving <span class="avbk-sort-indicator" aria-hidden="true">↕</span></button></th>
                                <th class="avbk-dropzone-amount-head"><button type="button" class="avbk-dropzone-sort" data-sort="amount" title="Sorteer op bedrag">Bedrag <span class="avbk-sort-indicator" aria-hidden="true">↕</span></button></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($own_receipts as $rec) :
                            self::render_pending_receipt_row($r, $rec, $can_remove);
                        endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="avbk-pending-total-row">
                                <td colspan="4"><strong class="avbk-pending-count"><?php echo esc_html(sprintf('Totaal (%d %s)', count($own_receipts), count($own_receipts) === 1 ? 'regel' : 'regels')); ?></strong></td>
                                <td class="avbk-dropzone-amount-head"><strong class="avbk-pending-total">&euro; <?php echo esc_html(number_format($own_total, 2, ',', '.')); ?></strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="avbk-reimbursement-add-receipt-form">
                    <?php wp_nonce_field('avbk_member_add_receipt'); ?>
                    <input type="hidden" name="action" value="avbk_member_add_receipt">
                    <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                    <label for="avbk-add-receipt-<?php echo (int) $r->id; ?>">Extra bonnetje toevoegen</label>
                    <input type="file" id="avbk-add-receipt-<?php echo (int) $r->id; ?>" name="receipt" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf">
                    <button type="submit" class="button">Toevoegen</button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-reimbursement-edit-details" id="avbk-edit-form-<?php echo (int) $r->id; ?>">
                    <?php wp_nonce_field('avbk_member_update_reimbursement'); ?>
                    <input type="hidden" name="action" value="avbk_member_update_reimbursement">
                    <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                    <div class="avbk-reimbursement-edit-grid">
                        <div class="avbk-reimbursement-edit-field">
                            <label for="avbk-edit-activity-<?php echo (int) $r->id; ?>">Activiteit</label>
                            <select name="activity_id" id="avbk-edit-activity-<?php echo (int) $r->id; ?>">
                                <option value="">&mdash; geen (algemene onkosten) &mdash;</option>
                                <?php foreach ($activities as $activity) : ?>
                                    <option value="<?php echo esc_attr($activity->id); ?>" <?php selected((int) $r->activity_id, (int) $activity->id); ?>>
                                        <?php echo esc_html($activity->name . ' (' . $activity->year . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="avbk-reimbursement-edit-field">
                            <label for="avbk-edit-amount-<?php echo (int) $r->id; ?>">Totaal bedrag</label>
                            <span class="avbk-amount-prefix">&euro; <input type="text" name="amount" id="avbk-edit-amount-<?php echo (int) $r->id; ?>" value="<?php echo esc_attr(number_format((float) $r->amount, 2, ',', '')); ?>"></span>
                        </div>
                        <?php self::render_pending_iban_field($r, $known_ibans, $iban_matches_known); ?>
                        <div class="avbk-reimbursement-edit-field avbk-reimbursement-edit-field-full">
                            <label for="avbk-edit-note-<?php echo (int) $r->id; ?>">Toelichting voor de penningmeester</label>
                            <textarea name="member_note" id="avbk-edit-note-<?php echo (int) $r->id; ?>" rows="4" maxlength="2000" placeholder="Bijv.: voorgeschoten voor de hele groep, of extra uitleg bij de bonnen"><?php echo esc_textarea((string) ($r->member_note ?? '')); ?></textarea>
                        </div>
                    </div>
                </form>
                <div class="avbk-reimbursement-edit-actions">
                    <button type="submit" form="avbk-edit-form-<?php echo (int) $r->id; ?>" class="button button-primary">Wijziging opslaan</button>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-reimbursement-withdraw-form">
                        <?php wp_nonce_field('avbk_withdraw_reimbursement'); ?>
                        <input type="hidden" name="action" value="avbk_withdraw_reimbursement">
                        <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                        <button type="submit" class="button avbk-button-danger" onclick="return confirm('Declaratie intrekken?');">Declaratie intrekken</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php
    }

    /** Renders the IBAN selector / text field inside a pending declaration's edit panel. */
    private static function render_pending_iban_field(object $r, array $known_ibans, bool $iban_matches_known): void {
        $has_multiple_ibans = count($known_ibans) > 1;
        $iban_label_for = $has_multiple_ibans ? 'avbk-edit-iban-select-' . (int) $r->id : 'avbk-edit-iban-' . (int) $r->id;
        ?>
        <div class="avbk-reimbursement-edit-field">
            <label for="<?php echo esc_attr($iban_label_for); ?>">Rekeningnummer</label>
            <?php if ($has_multiple_ibans) : ?>
                <select id="avbk-edit-iban-select-<?php echo (int) $r->id; ?>" class="avbk-iban-select" data-target="avbk-edit-iban-<?php echo (int) $r->id; ?>">
                    <?php foreach ($known_ibans as $known) : ?>
                        <option value="<?php echo esc_attr($known->iban); ?>" <?php selected($r->iban, $known->iban); ?>>
                            <?php echo esc_html(($known->account_name ?: $known->iban) . ' (' . $known->iban . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="" <?php selected($iban_matches_known, false); ?>>Ander rekeningnummer&hellip;</option>
                </select>
                <label class="screen-reader-text" for="avbk-edit-iban-<?php echo (int) $r->id; ?>">Ander rekeningnummer</label>
            <?php endif; ?>
            <input type="text" name="iban" id="avbk-edit-iban-<?php echo (int) $r->id; ?>" class="regular-text" value="<?php echo esc_attr($r->iban); ?>" <?php echo ($has_multiple_ibans && $iban_matches_known) ? 'hidden' : ''; ?>>
        </div>
        <?php
    }

    /** Renders one editable receipt row inside a pending declaration's table. */
    private static function render_pending_receipt_row(object $r, object $rec, bool $can_remove): void {
        $rec_path = trim((string) ($rec->receipt_path ?? ''));
        $rec_has_photo = $rec_path !== '';
        $rec_is_image = $rec_has_photo && self::receipt_is_image($rec_path);
        $rec_is_pdf = $rec_has_photo && strtolower(pathinfo($rec_path, PATHINFO_EXTENSION)) === 'pdf';
        $rec_view_url = $rec_has_photo ? self::receipt_view_url((int) $r->id, (int) $rec->id) : '';
        $rec_date_iso = (string) ($rec->date ?? '');
        $rec_date_display = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rec_date_iso, $dm) ? $dm[3] . '-' . $dm[2] . '-' . $dm[1] : '';
        $rec_amount_display = $rec->amount !== null ? number_format((float) $rec->amount, 2, ',', '') : '';
        $can_edit_rec = (int) $rec->id > 0;
        $disabled_attr = $can_edit_rec ? '' : ' disabled';
        $picker_id = 'avbk-pending-picker-' . (int) $r->id . '-' . (int) $rec->id;
        ?>
        <tr class="avbk-pending-row" data-receipt-id="<?php echo (int) $rec->id; ?>" data-date="<?php echo esc_attr($rec_date_iso); ?>" data-photo-url="<?php echo esc_attr($rec_is_image ? $rec_view_url : ''); ?>">
            <td><?php self::render_pending_photo_cell($rec_is_image, $rec_has_photo, $rec_is_pdf, $can_edit_rec, $rec_view_url); ?></td>
            <td>
                <span class="avbk-dropzone-item-date-wrap">
                    <input type="text" inputmode="numeric" class="avbk-dropzone-item-date avbk-pending-input" data-field="date" value="<?php echo esc_attr($rec_date_display); ?>" placeholder="dd-mm-jjjj" aria-label="Datum (dd-mm-jjjj)"<?php echo $disabled_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal. ?>>
                    <?php if ($can_edit_rec) : ?>
                        <button type="button" class="avbk-dropzone-item-date-pick avbk-pending-date-pick" aria-label="Kies een datum" title="Kies een datum">📅</button>
                        <label class="screen-reader-text" for="<?php echo esc_attr($picker_id); ?>">Kies datum</label>
                        <input type="date" id="<?php echo esc_attr($picker_id); ?>" class="avbk-dropzone-item-date-picker avbk-pending-date-picker" tabindex="-1" aria-hidden="true" value="<?php echo esc_attr($rec_date_iso); ?>">
                    <?php endif; ?>
                </span>
            </td>
            <td><input type="text" class="avbk-dropzone-item-store avbk-pending-input" data-field="store" value="<?php echo esc_attr((string) ($rec->store ?? '')); ?>" placeholder="Winkel" aria-label="Winkel"<?php echo $disabled_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal. ?>></td>
            <td><input type="text" class="avbk-dropzone-item-description avbk-pending-input" data-field="description" value="<?php echo esc_attr((string) ($rec->description ?? '')); ?>" placeholder="Omschrijving (optioneel)" aria-label="Omschrijving"></td>
            <td><input type="text" inputmode="decimal" class="avbk-dropzone-item-amount avbk-pending-input" data-field="amount" value="<?php echo esc_attr($rec_amount_display); ?>" placeholder="0,00" aria-label="Bedrag"<?php echo $disabled_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal. ?>></td>
            <td>
                <?php if ($can_remove && $can_edit_rec) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <?php wp_nonce_field('avbk_member_remove_receipt'); ?>
                        <input type="hidden" name="action" value="avbk_member_remove_receipt">
                        <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                        <input type="hidden" name="receipt_id" value="<?php echo esc_attr($rec->id); ?>">
                        <button type="submit" class="avbk-dropzone-item-remove" title="Verwijderen" aria-label="Verwijderen" onclick="return confirm('Dit bonnetje verwijderen?');">&times;</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /** Renders the thumbnail / file link / "+ Foto" button cell for one pending receipt row. */
    private static function render_pending_photo_cell(bool $rec_is_image, bool $rec_has_photo, bool $rec_is_pdf, bool $can_edit_rec, string $rec_view_url): void {
        if ($rec_is_image) : ?>
            <button type="button" class="avbk-dropzone-item-zoom avbk-pending-zoom" aria-label="Bonnetje groot bekijken" title="Bekijk groot om te controleren of een stukje op de foto opnieuw uit te lezen">
                <img src="<?php echo esc_url($rec_view_url); ?>" alt="" class="avbk-dropzone-item-photo">
            </button>
        <?php elseif ($rec_has_photo) : ?>
            <a href="<?php echo esc_url($rec_view_url); ?>" target="_blank" class="avbk-dropzone-item-no-photo"><?php echo $rec_is_pdf ? 'pdf' : 'bestand'; ?></a>
        <?php elseif ($can_edit_rec) : ?>
            <label class="avbk-attach-photo" title="Foto toevoegen aan deze regel">
                <span>+ Foto</span>
                <input type="file" class="avbk-pending-attach-input" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf" capture="environment" hidden>
            </label>
        <?php else : ?>
            <span class="avbk-dropzone-item-no-photo">geen foto</span>
        <?php endif;
    }

    /** AJAX: OCR a just-added photo before the real submit, purely to pre-fill the amount field (and warn early on a duplicate) — the temp upload is discarded either way, the authoritative duplicate check happens again in handle_submit(). One file per call — the dropzone calls this once per photo added. */
    public function ajax_ocr_receipt(): void {
        check_ajax_referer('avbk_ocr_receipt', 'nonce');
        $tmp_path = $this->uploaded_path('receipt');
        if (!is_user_logged_in() || $tmp_path === '') {
            avbk_send_json_error();
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        $read = self::read_receipt(
            $tmp_path,
            (string) ($_FILES['receipt']['name'] ?? ''),
            $member ? (int) $member->id : 0,
            self::requested_engine(wp_unslash($_POST['engine'] ?? ''))
        );
        if ($read === null) {
            avbk_send_json_error(self::INVALID_FILE_ERROR);
        }
        wp_send_json_success($read);
    }

    /** The temporary path of the file uploaded in this request as $field, or '' when there is none. */
    protected function uploaded_path(string $field): string {
        $tmp_path = (string) ($_FILES[$field]['tmp_name'] ?? '');
        return $tmp_path !== '' && is_uploaded_file($tmp_path) ? $tmp_path : '';
    }

    /**
     * What the OCR finds on a receipt, for pre-filling its row: best guesses,
     * everything to pick from, and whether the member declared it before.
     * Null for a file that is no valid receipt.
     *
     * @return array{amount: ?float, date: ?string, store: ?string, duplicate: bool, candidates: array<string, array<mixed>>}|null
     */
    public static function read_receipt(string $path, string $original_name, int $member_id, ?string $engine): ?array {
        if (self::validate_receipt_content($path, $original_name) === null) {
            return null;
        }
        return self::extract_receipt_data($path, $member_id, $engine);
    }

    /**
     * Runs OCR and duplicate detection on an already-validated receipt file.
     *
     * @return array{amount: ?float, date: ?string, store: ?string, duplicate: bool, candidates: array<string, array<mixed>>}
     */
    private static function extract_receipt_data(string $path, int $member_id, ?string $engine): array {
        $text = (string) AVBK_OCR::extract_text($path, 30, $engine);
        $date = $text !== '' ? AVBK_OCR::guess_date($text) : null;
        $store = $text !== '' ? AVBK_OCR::guess_store($text) : null;
        return [
            'amount'     => $text !== '' ? AVBK_OCR::guess_total($text) : null,
            'date'       => $date,
            'store'      => $store,
            'duplicate'  => $member_id > 0 && AVBK_DB::find_duplicate_receipt($member_id, (string) hash_file('sha256', $path), $date, $store),
            'candidates' => AVBK_OCR::candidates($text),
        ];
    }

    /**
     * AJAX: read the part of a receipt photo the member selected in the
     * viewer (a PNG the browser cropped), so a date, shop or amount can be
     * taken over. Only the text comes back; nothing is stored.
     */
    public function ajax_ocr_region(): void {
        check_ajax_referer('avbk_ocr_receipt', 'nonce');
        $tmp_path = $this->uploaded_path('region');
        if (!is_user_logged_in() || $tmp_path === '') {
            avbk_send_json_error('Ongeldig fragment');
        }
        $read = self::read_region($tmp_path, self::requested_engine(wp_unslash($_POST['engine'] ?? '')));
        if ($read['error'] !== '') {
            avbk_send_json_error($read['error']);
        }
        wp_send_json_success(['text' => $read['text']]);
    }

    /**
     * Reads a cropped part of a receipt (a PNG): its text, or why not.
     *
     * @return array{text: string, error: string}
     */
    public static function read_region(string $path, ?string $engine): array {
        if (self::validate_receipt_content($path, 'region.png') !== 'png') {
            return ['text' => '', 'error' => 'Ongeldig fragment'];
        }
        $text = self::region_text((string) AVBK_OCR::extract_text($path, 20, $engine));
        return $text === ''
            ? ['text' => '', 'error' => 'In dit stuk is geen tekst gevonden. Selecteer ruimer, of typ het over.']
            : ['text' => $text, 'error' => ''];
    }

    /** The engine the member chose (a posted value), or null for the default (also for an unknown one). */
    public static function requested_engine($posted): ?string {
        $engine = sanitize_key(is_string($posted) ? $posted : '');
        return isset(AVBK_OCR::engines()[$engine]) ? $engine : null;
    }

    /** The OCR text of a selected part, on one line and at most 200 characters. */
    public static function region_text(string $text): string {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 200);
    }

    /** Raw URL (not HTML-escaped — used in JSON/JS img.src, unlike wp_nonce_url() which calls esc_html()). */
    private static function receipt_view_url(int $reimbursement_id, int $receipt_id): string {
        return add_query_arg([
            'action'   => 'avbk_view_receipt',
            'id'       => $reimbursement_id,
            'receipt'  => $receipt_id,
            '_wpnonce' => wp_create_nonce('avbk_view_receipt'),
        ], admin_url('admin-post.php'));
    }

    private static function receipt_is_image(string $path): bool {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    /** Serializes one stored draft receipt row for the browser form. */
    public static function receipt_to_client(int $draft_id, object $rec): array {
        $path = trim((string) ($rec->receipt_path ?? ''));
        $has_receipt = $path !== '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return [
            'id'          => (int) $rec->id,
            'hasReceipt'  => $has_receipt,
            'isImage'     => $has_receipt && self::receipt_is_image($path),
            'isPdf'       => $has_receipt && $ext === 'pdf',
            'photoUrl'    => $has_receipt ? self::receipt_view_url($draft_id, (int) $rec->id) : '',
            'date'        => (string) ($rec->date ?? ''),
            'store'       => (string) ($rec->store ?? ''),
            'description' => (string) ($rec->description ?? ''),
            'amount'      => $rec->amount !== null ? (float) $rec->amount : null,
        ];
    }

    private static function parse_posted_amount($raw): ?float {
        $value = trim(str_replace([' ', '€'], '', (string) $raw));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $value)) {
            $value = str_replace('.', '', $value);
        }
        $normalized = str_replace(',', '.', $value);
        return is_numeric($normalized) ? round((float) $normalized, 2) : null;
    }

    private static function parse_posted_date($raw): ?string {
        $date = sanitize_text_field(wp_unslash((string) $raw));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    /** Verifies AJAX nonce + logged-in member for draft endpoints. */
    private function require_draft_member(): object {
        check_ajax_referer('avbk_ocr_receipt', 'nonce');
        if (!is_user_logged_in()) {
            avbk_send_json_error('Niet ingelogd');
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        if (!$member) {
            avbk_send_json_error('Niet ingelogd');
        }
        return $member;
    }

    /** Returns the posted draft ID when it is an open draft owned by $member_id, else finds or creates one. */
    private function resolve_member_draft_id(int $member_id, string $default_iban = ''): int {
        $posted_id = (int) ($_POST['draft_id'] ?? 0);
        if ($posted_id > 0) {
            $draft = AVBK_DB::get_reimbursement($posted_id);
            if ($draft && (int) $draft->member_id === $member_id && $draft->status === 'draft') {
                return $posted_id;
            }
        }
        return AVBK_DB::get_or_create_draft_reimbursement($member_id, $default_iban);
    }

    /** AJAX: ensures the member has an open draft before parallel file uploads start. */
    public function ajax_draft_ensure(): void {
        $member = $this->require_draft_member();
        $iban = strtoupper(str_replace(' ', '', sanitize_text_field(wp_unslash($_POST['iban'] ?? ''))));
        $draft_id = $this->resolve_member_draft_id((int) $member->id, $iban);
        wp_send_json_success(['draft_id' => $draft_id]);
    }

    /** AJAX: validates, OCRs, duplicate-checks, and immediately persists one uploaded receipt into the member's draft. */
    public function ajax_draft_add_receipt(): void {
        $member = $this->require_draft_member();
        $tmp_path = $this->uploaded_path('receipt');
        if ($tmp_path === '') {
            avbk_send_json_error(self::INVALID_FILE_ERROR);
        }
        $name = (string) ($_FILES['receipt']['name'] ?? '');
        $ext = self::validate_receipt_content($tmp_path, $name);
        if ($ext === null) {
            avbk_send_json_error(self::INVALID_FILE_ERROR);
        }
        $engine = self::requested_engine(wp_unslash($_POST['engine'] ?? ''));
        $read = self::extract_receipt_data($tmp_path, (int) $member->id, $engine);
        if ($read['duplicate']) {
            wp_send_json_success(['duplicate' => true]);
        }

        $iban = strtoupper(str_replace(' ', '', sanitize_text_field(wp_unslash($_POST['iban'] ?? ''))));
        $draft_id = $this->resolve_member_draft_id((int) $member->id, $iban);
        $hash = (string) hash_file('sha256', $tmp_path);
        $stored = $this->store_receipt_file($tmp_path, $ext);
        $receipt_id = AVBK_DB::add_reimbursement_receipt($draft_id, [
            'receipt_path' => $stored,
            'receipt_hash' => $hash,
            'description'  => '',
            'date'         => $read['date'],
            'store'        => (string) ($read['store'] ?? ''),
            'amount'       => $read['amount'],
            'ocr_amount'   => $read['amount'],
            'ocr_date'     => $read['date'],
            'ocr_store'    => (string) ($read['store'] ?? ''),
        ]);

        wp_send_json_success($read + [
            'draft_id'   => $draft_id,
            'receipt_id' => $receipt_id,
            'photo_url'  => self::receipt_view_url($draft_id, $receipt_id),
            'is_image'   => self::receipt_is_image($stored),
            'is_pdf'     => strtolower($ext) === 'pdf',
        ]);
    }

    /** AJAX: adds a manual (no-photo) row to the member's draft. */
    public function ajax_draft_add_manual(): void {
        $member = $this->require_draft_member();
        $iban = strtoupper(str_replace(' ', '', sanitize_text_field(wp_unslash($_POST['iban'] ?? ''))));
        $draft_id = $this->resolve_member_draft_id((int) $member->id, $iban);
        $receipt_id = AVBK_DB::add_reimbursement_receipt($draft_id, [
            'receipt_path' => '',
            'receipt_hash' => '',
            'description'  => '',
            'date'         => null,
            'store'        => '',
            'amount'       => null,
            'ocr_amount'   => null,
            'ocr_date'     => null,
            'ocr_store'    => '',
        ]);
        wp_send_json_success(['draft_id' => $draft_id, 'receipt_id' => $receipt_id]);
    }

    /** AJAX: re-reads an already-stored draft receipt file with the requested OCR engine. */
    public function ajax_draft_retry_receipt(): void {
        $member = $this->require_draft_member();
        $draft_id = (int) ($_POST['draft_id'] ?? 0);
        $receipt_id = (int) ($_POST['receipt_id'] ?? 0);
        $receipt = AVBK_DB::get_draft_receipt($draft_id, $receipt_id, (int) $member->id);
        if (!$receipt || trim((string) $receipt->receipt_path) === '') {
            avbk_send_json_error('Bonnetje niet gevonden');
        }
        $path = self::receipts_dir() . '/' . basename((string) $receipt->receipt_path);
        if (!file_exists($path)) {
            avbk_send_json_error('Bestand niet gevonden');
        }
        $engine = self::requested_engine(wp_unslash($_POST['engine'] ?? ''));
        $text = (string) AVBK_OCR::extract_text($path, 30, $engine);
        $date = $text !== '' ? AVBK_OCR::guess_date($text) : null;
        $store = $text !== '' ? AVBK_OCR::guess_store($text) : null;
        $amount = $text !== '' ? AVBK_OCR::guess_total($text) : null;
        $duplicate = (bool) AVBK_DB::find_duplicate_receipt((int) $member->id, (string) $receipt->receipt_hash, $date, $store, $receipt_id);
        AVBK_DB::update_draft_receipt($draft_id, $receipt_id, (int) $member->id, [
            'ocr_date'   => $date,
            'ocr_store'  => (string) ($store ?? ''),
            'ocr_amount' => $amount,
        ]);
        wp_send_json_success([
            'amount'     => $amount,
            'date'       => $date,
            'store'      => $store,
            'duplicate'  => $duplicate,
            'candidates' => AVBK_OCR::candidates($text),
        ]);
    }

    /** AJAX: saves edited date/store/description/amount of one receipt inside the member's draft. */
    public function ajax_draft_save_receipt(): void {
        $member = $this->require_draft_member();
        $draft_id = (int) ($_POST['draft_id'] ?? 0);
        $receipt_id = (int) ($_POST['receipt_id'] ?? 0);
        $ok = AVBK_DB::update_draft_receipt($draft_id, $receipt_id, (int) $member->id, [
            'date'        => self::parse_posted_date($_POST['date'] ?? ''),
            'store'       => sanitize_text_field(wp_unslash($_POST['store'] ?? '')),
            'description' => sanitize_text_field(wp_unslash($_POST['description'] ?? '')),
            'amount'      => self::parse_posted_amount($_POST['amount'] ?? ''),
        ]);
        if (!$ok) {
            avbk_send_json_error('Concept niet gevonden');
        }
        wp_send_json_success(['saved' => true]);
    }

    /** AJAX: saves header fields (activity, total amount, IBAN, note) of the member's draft. */
    public function ajax_draft_save(): void {
        $member = $this->require_draft_member();
        $iban = strtoupper(str_replace(' ', '', sanitize_text_field(wp_unslash($_POST['iban'] ?? ''))));
        $draft_id = $this->resolve_member_draft_id((int) $member->id, $iban);
        $amount = self::parse_posted_amount($_POST['amount'] ?? '') ?? 0.0;
        AVBK_DB::update_draft_reimbursement($draft_id, (int) $member->id, [
            'activity_id' => (int) ($_POST['activity_id'] ?? 0) ?: null,
            'member_note' => self::posted_note(),
            'amount'      => $amount,
            'iban'        => $iban,
        ]);
        wp_send_json_success(['draft_id' => $draft_id]);
    }

    /** AJAX: removes one receipt from the member's draft and deletes its file from disk. */
    public function ajax_draft_remove_receipt(): void {
        $member = $this->require_draft_member();
        $draft_id = (int) ($_POST['draft_id'] ?? 0);
        $receipt_id = (int) ($_POST['receipt_id'] ?? 0);
        $deleted = AVBK_DB::delete_draft_receipt($draft_id, $receipt_id, (int) $member->id);
        if (!$deleted) {
            avbk_send_json_error('Bonnetje niet gevonden');
        }
        $this->delete_receipt_file((string) $deleted->receipt_path);
        wp_send_json_success(['removed' => true]);
    }

    /** AJAX: discards the member's entire draft declaration and deletes all stored receipt files. */
    public function ajax_draft_discard(): void {
        $member = $this->require_draft_member();
        $draft_id = (int) ($_POST['draft_id'] ?? 0);
        $paths = AVBK_DB::delete_draft_reimbursement($draft_id, (int) $member->id);
        foreach ($paths as $path) {
            $this->delete_receipt_file((string) $path);
        }
        wp_send_json_success(['discarded' => true]);
    }

    /** AJAX: updates one receipt row of a member's own still-pending declaration. */
    public function ajax_member_update_receipt(): void {
        $member = $this->require_draft_member();
        $reimbursement_id = (int) ($_POST['reimbursement_id'] ?? 0);
        $receipt_id = (int) ($_POST['receipt_id'] ?? 0);
        $ok = AVBK_DB::update_member_receipt($reimbursement_id, $receipt_id, (int) $member->id, [
            'date'        => self::parse_posted_date($_POST['date'] ?? ''),
            'store'       => sanitize_text_field(wp_unslash($_POST['store'] ?? '')),
            'description' => sanitize_text_field(wp_unslash($_POST['description'] ?? '')),
            'amount'      => self::parse_posted_amount($_POST['amount'] ?? ''),
        ], 'pending');
        if (!$ok) {
            avbk_send_json_error('Declaratie niet gevonden');
        }
        wp_send_json_success(['saved' => true]);
    }

    /** AJAX: attaches a photo/PDF to an existing photo-less row of a member's draft or pending declaration. */
    public function ajax_member_attach_receipt_photo(): void {
        $member = $this->require_draft_member();
        $reimbursement_id = (int) ($_POST['reimbursement_id'] ?? 0);
        $receipt_id = (int) ($_POST['receipt_id'] ?? 0);
        $existing = AVBK_DB::get_member_receipt($reimbursement_id, $receipt_id, (int) $member->id, 'draft')
            ?? AVBK_DB::get_member_receipt($reimbursement_id, $receipt_id, (int) $member->id, 'pending');
        if (!$existing || trim((string) ($existing->receipt_path ?? '')) !== '') {
            avbk_send_json_error('Regel niet gevonden');
        }
        $tmp_path = $this->uploaded_path('receipt');
        $name = (string) ($_FILES['receipt']['name'] ?? '');
        $ext = $tmp_path !== '' ? self::validate_receipt_content($tmp_path, $name) : null;
        if ($ext === null) {
            avbk_send_json_error(self::INVALID_FILE_ERROR);
        }
        $read = self::extract_receipt_data($tmp_path, (int) $member->id, null);
        if ($read['duplicate']) {
            wp_send_json_success(['duplicate' => true]);
        }
        $stored = $this->store_receipt_file($tmp_path, $ext);
        AVBK_DB::attach_member_receipt_photo($reimbursement_id, $receipt_id, (int) $member->id, [
            'receipt_path' => $stored,
            'receipt_hash' => (string) hash_file('sha256', $tmp_path),
            'ocr_amount'   => $read['amount'],
            'ocr_date'     => $read['date'],
            'ocr_store'    => (string) ($read['store'] ?? ''),
        ]);
        wp_send_json_success($read + [
            'photo_url' => self::receipt_view_url($reimbursement_id, $receipt_id),
            'is_image'  => self::receipt_is_image($stored),
            'is_pdf'    => strtolower($ext) === 'pdf',
        ]);
    }

    /** Safely removes one stored receipt file from the private receipts directory. */
    protected function delete_receipt_file(string $filename): void {
        $base = basename(trim($filename));
        if ($base === '') {
            return;
        }
        $path = self::receipts_dir() . '/' . $base;
        if (file_exists($path)) {
            self::filesystem()->delete($path);
        }
    }

    /**
     * Validates, hashes, OCRs and duplicate-checks one already-uploaded temp file
     * without moving it yet — handle_submit() needs every file in a batch
     * to pass this before any of them are actually stored, so a duplicate
     * discovered on file 3 doesn't leave files 1-2 orphaned on disk.
     * $seen_hashes is shared across a batch to also catch the same photo
     * added twice in one submission (before either has a DB row to match
     * against). Returns null for an invalid or duplicate file.
     */
    private function analyze_uploaded_receipt(string $tmp_path, string $original_name, array &$seen_hashes, int $member_id): ?array {
        $ext = self::validate_receipt_file($tmp_path, $original_name);
        if ($ext === null) {
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
            'ext'        => $ext,
            'hash'       => $hash,
            'ocr_amount' => $ocr_amount,
            'ocr_date'   => $ocr_date,
            'ocr_store'  => $ocr_store,
        ];
    }

    /** Moves an already-validated temp upload into permanent storage under a random filename. */
    protected function store_receipt_file(string $tmp_path, string $validated_ext): string {
        self::ensure_receipts_dir_protected();
        $allowed_exts = [];
        foreach (self::ALLOWED_MIMES as $exts) {
            $allowed_exts = array_merge($allowed_exts, $exts);
        }
        $ext = in_array(strtolower($validated_ext), $allowed_exts, true) ? strtolower($validated_ext) : 'jpg';
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

        $amount = self::parse_posted_amount($_POST['amount'] ?? '') ?? 0.0;
        $iban = strtoupper(str_replace(' ', '', sanitize_text_field(wp_unslash($_POST['iban'] ?? ''))));
        $draft_id = (int) ($_POST['draft_id'] ?? 0);
        if ($draft_id > 0) {
            $notice = $this->handle_submit_draft($draft_id, $member, $amount, $iban);
            wp_safe_redirect(add_query_arg($notice, '1', $redirect_url) . '#declareren');
            return;
        }

        $files = $_FILES['receipt'] ?? ['tmp_name' => [], 'name' => []];
        // has_receipt[] carries one flag per declaration LINE, in the same
        // order as date[]/store[]/description[]/amount[] — a line with no
        // photo (manual entry: cash expense, lost receipt) has no matching
        // slot in $files at all, since a native file input only ever
        // submits the files actually selected. This pointer is how a
        // '1' flag knows which $files entry is "next", without assuming
        // line index and file index are the same number.
        $has_receipt_flags = (array) ($_POST['has_receipt'] ?? []);

        if (!$member || !$has_receipt_flags || $amount <= 0 || $iban === '') {
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
        $file_index = 0;
        $row_amounts = is_array($_POST['row_amount'] ?? null) ? $_POST['row_amount'] : [];
        foreach ($has_receipt_flags as $i => $flag) {
            $description = sanitize_text_field(wp_unslash($_POST['description'][$i] ?? ''));
            $store = sanitize_text_field(wp_unslash($_POST['store'][$i] ?? ''));
            $date = self::parse_posted_date($_POST['date'][$i] ?? '');
            $row_amount = self::parse_posted_amount($row_amounts[$i] ?? '');

            if (!empty($flag) && !empty($files['tmp_name'][$file_index])) {
                $tmp_path = $files['tmp_name'][$file_index];
                $name = (string) ($files['name'][$file_index] ?? '');
                $file_index++;
                $info = $this->analyze_uploaded_receipt($tmp_path, $name, $seen_hashes, (int) $member->id);
                if ($info === null) {
                    wp_safe_redirect(add_query_arg('reimbursement_duplicate', '1', $redirect_url) . '#declareren');
                    exit;
                }
                $analyzed[] = $info + [
                    'tmp_path'    => $tmp_path,
                    'name'        => $name,
                    'description' => $description,
                    'date'        => $date ?: $info['ocr_date'],
                    'store'       => $store !== '' ? $store : $info['ocr_store'],
                    'amount'      => $row_amount ?? $info['ocr_amount'],
                ];
            } elseif ($row_amount !== null && $row_amount > 0) {
                // Manual line — no photo, nothing to OCR or duplicate-check.
                $analyzed[] = [
                    'ext'         => '',
                    'hash'        => '',
                    'ocr_amount'  => null,
                    'ocr_date'    => null,
                    'ocr_store'   => '',
                    'tmp_path'    => null,
                    'name'        => '',
                    'description' => $description,
                    'date'        => $date,
                    'store'       => $store,
                    'amount'      => $row_amount,
                ];
            }
        }
        if (!$analyzed) {
            wp_safe_redirect(add_query_arg('reimbursement_error', '1', $redirect_url) . '#declareren');
            exit;
        }

        $ocr_amounts = array_filter(array_column($analyzed, 'ocr_amount'), fn($v) => $v !== null);
        $member_note = self::posted_note();

        // One activity per declaration (it can only ever be booked against
        // one open fee item), but each bundled purchase gets its own
        // description — the parent's own description column is just a
        // joined summary for list views, refreshed once the receipts exist.
        $id = AVBK_DB::create_reimbursement([
            'member_id'   => (int) $member->id,
            'activity_id' => (int) ($_POST['activity_id'] ?? 0) ?: null,
            'description' => '',
            'member_note' => $member_note,
            'amount'      => $amount,
            'ocr_amount'  => $ocr_amounts ? array_sum($ocr_amounts) : null,
            'iban'        => $iban,
        ]);
        foreach ($analyzed as $a) {
            // A manual line has no tmp_path — nothing to move, receipt_path/
            // receipt_hash just stay at their column defaults (empty string).
            $random_name = $a['tmp_path'] !== null ? $this->store_receipt_file($a['tmp_path'], $a['ext']) : '';
            AVBK_DB::add_reimbursement_receipt($id, [
                'receipt_path' => $random_name,
                'receipt_hash' => $a['hash'],
                'description'  => $a['description'],
                'date'         => $a['date'],
                'store'        => $a['store'],
                'amount'       => $a['amount'],
                'ocr_amount'   => $a['ocr_amount'],
                'ocr_date'     => $a['ocr_date'],
                'ocr_store'    => $a['ocr_store'],
            ]);
        }
        AVBK_DB::refresh_reimbursement_description_summary($id);
        AVBK_DB::remember_iban((int) $member->id, $iban, avpvh_format_name($member));
        $this->notify_penningmeester($member, $amount, implode(', ', array_filter(array_column($analyzed, 'description'))), $member_note);

        wp_safe_redirect(add_query_arg('reimbursement_submitted', '1', $redirect_url) . '#declareren');
    }

    /** Submits an already-saved draft declaration after syncing the final posted row values. */
    private function handle_submit_draft(int $draft_id, ?object $member, float $amount, string $iban): string {
        $draft = $member ? AVBK_DB::get_reimbursement($draft_id) : null;
        if (!$draft || (int) $draft->member_id !== (int) $member->id || $draft->status !== 'draft' || $amount <= 0 || $iban === '') {
            return 'reimbursement_error';
        }
        $member_id = (int) $member->id;
        self::sync_posted_draft_rows($draft_id, $member_id);
        $valid = self::collect_valid_draft_receipts($draft_id, $member_id);
        if ($valid === null) {
            return 'reimbursement_duplicate';
        }
        if (!$valid) {
            return 'reimbursement_error';
        }
        $ocr_amounts = array_filter(array_map(fn($r) => $r->ocr_amount !== null ? (float) $r->ocr_amount : null, $valid), fn($v) => $v !== null);
        $member_note = self::posted_note();
        AVBK_DB::submit_draft_reimbursement($draft_id, $member_id, [
            'activity_id' => (int) ($_POST['activity_id'] ?? 0) ?: null,
            'member_note' => $member_note,
            'amount'      => $amount,
            'ocr_amount'  => $ocr_amounts ? array_sum($ocr_amounts) : null,
            'iban'        => $iban,
        ]);
        AVBK_DB::refresh_reimbursement_description_summary($draft_id);
        AVBK_DB::remember_iban($member_id, $iban, avpvh_format_name($member));
        $descriptions = implode(', ', array_filter(array_map(fn($r) => (string) $r->description, $valid)));
        $this->notify_penningmeester($member, $amount, $descriptions, $member_note);

        return 'reimbursement_submitted';
    }

    private static function sync_posted_draft_rows(int $draft_id, int $member_id): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_submit() checked its nonce.
        $posted_ids = (array) ($_POST['receipt_id'] ?? []);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_submit() checked its nonce.
        $row_amounts = is_array($_POST['row_amount'] ?? null) ? $_POST['row_amount'] : null;
        foreach ($posted_ids as $i => $raw_id) {
            $rid = (int) $raw_id;
            if ($rid <= 0) {
                continue;
            }
            $row_update = [
                'description' => sanitize_text_field(wp_unslash($_POST['description'][$i] ?? '')), // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'store'       => sanitize_text_field(wp_unslash($_POST['store'][$i] ?? '')), // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'date'        => self::parse_posted_date($_POST['date'][$i] ?? ''), // phpcs:ignore WordPress.Security.NonceVerification.Missing
            ];
            if ($row_amounts !== null) {
                $row_update['amount'] = self::parse_posted_amount($row_amounts[$i] ?? '');
            }
            AVBK_DB::update_draft_receipt($draft_id, $rid, $member_id, $row_update);
        }
    }

    /** Returns valid draft receipts (pruning empty manual rows), or null if any photo is a duplicate. */
    private static function collect_valid_draft_receipts(int $draft_id, int $member_id): ?array {
        $valid = [];
        $seen_hashes = [];
        foreach (AVBK_DB::get_reimbursement_receipts($draft_id) as $rec) {
            $has_photo = trim((string) $rec->receipt_path) !== '';
            if (!$has_photo && ($rec->amount === null || (float) $rec->amount <= 0)) {
                AVBK_DB::delete_draft_receipt($draft_id, (int) $rec->id, $member_id);
                continue;
            }
            if ($has_photo && !self::check_draft_receipt_unique($member_id, $rec, $seen_hashes)) {
                return null;
            }
            $valid[] = $rec;
        }
        return $valid;
    }

    private static function check_draft_receipt_unique(int $member_id, object $rec, array &$seen_hashes): bool {
        $hash = (string) $rec->receipt_hash;
        if (($hash !== '' && in_array($hash, $seen_hashes, true))
            || AVBK_DB::find_duplicate_receipt($member_id, $hash, $rec->ocr_date ?: null, $rec->ocr_store ?: null, 0, true)) {
            return false;
        }
        if ($hash !== '') {
            $seen_hashes[] = $hash;
        }
        return true;
    }

    private function notify_penningmeester(object $member, float $amount, string $descriptions, string $member_note): void {
        $to = get_option('avbk_penningmeester_email', 'info@avphilipsvanhorne.nl');
        $subject = sprintf('[AV-PvH] Nieuwe declaratie van %s', avpvh_format_name($member));
        $body = sprintf(
            "%s heeft een bonnetje gedeclareerd van € %s.\n\nOmschrijving: %s%s\n\nBeoordelen: %s",
            avpvh_format_name($member),
            number_format($amount, 2, ',', '.'),
            $descriptions,
            $member_note !== '' ? "\n\nToelichting:\n" . $member_note : '',
            admin_url('admin.php?page=avbk-reimbursements')
        );
        avbk_mail('treasurer_notice', (int) $member->id, $to, $subject, $body);
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
        $store = sanitize_text_field(wp_unslash($_POST['store'] ?? ''));
        $date_raw = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_raw) ? $date_raw : null;
        $amount_raw = (string) ($_POST['amount'] ?? '');
        $amount = $amount_raw !== '' ? (float) str_replace(',', '.', $amount_raw) : null;
        if ($id) {
            AVBK_DB::update_reimbursement_receipt($id, $receipt_id, [
                'description' => $description,
                'date'        => $date,
                'store'       => $store,
                'amount'      => $amount,
            ]);
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
                'member_note' => self::posted_note(),
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
        $tmp_path = $this->uploaded_path('receipt');
        if (!$owns_pending || $tmp_path === '') {
            wp_safe_redirect($redirect_url . '#declareren');
            return;
        }

        $name = (string) ($_FILES['receipt']['name'] ?? '');
        $ext = self::validate_receipt_content($tmp_path, $name);
        $read = $ext !== null ? self::extract_receipt_data($tmp_path, (int) $member->id, null) : null;
        if ($read === null || $read['duplicate']) {
            wp_safe_redirect(add_query_arg('reimbursement_duplicate', '1', $redirect_url) . '#declareren');
            return;
        }
        $random_name = $this->store_receipt_file($tmp_path, $ext);
        AVBK_DB::add_reimbursement_receipt($id, [
            'receipt_path' => $random_name,
            'receipt_hash' => (string) hash_file('sha256', $tmp_path),
            'description'  => sanitize_text_field(wp_unslash($_POST['description'] ?? '')),
            'date'         => $read['date'],
            'store'        => (string) ($read['store'] ?? ''),
            'amount'       => $read['amount'],
            'ocr_amount'   => $read['amount'],
            'ocr_date'     => $read['date'],
            'ocr_store'    => (string) ($read['store'] ?? ''),
        ]);
        AVBK_DB::refresh_reimbursement_description_summary($id);
        wp_safe_redirect($redirect_url . '#declareren');
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
        $reason = trim(sanitize_textarea_field(wp_unslash($_POST['reason'] ?? '')));
        $reimbursement = $id ? AVBK_DB::get_reimbursement($id) : null;
        $notice = ['rejected' => '1'];
        if ($reason === '' || !$reimbursement || !AVBK_DB::reject_reimbursement($id, $reason)) {
            avbk_log_failure('reimbursement reject', $reason === '' ? 'no_reason' : 'not_saved', ['reimbursement' => $id]);
            $notice = ['reject_failed' => $reason === '' ? 'no_reason' : 'not_saved'];
        } else {
            $notice += self::start_rejection_conversation($reimbursement, $reason);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avbk-reimbursements'] + $notice, admin_url('admin.php')));
        exit;
    }

    /**
     * A rejection starts a conversation in Berichten, so the member can
     * answer; the mail of that message is the member's notice.
     *
     * @return array<string, string> An extra notice when the conversation failed.
     */
    private static function start_rejection_conversation(object $reimbursement, string $reason): array {
        $what = trim((string) $reimbursement->description);
        $thread = AVBK_Disputes::start(
            (int) $reimbursement->member_id,
            sprintf('Declaratie van € %s afgewezen', number_format((float) $reimbursement->amount, 2, ',', '.')),
            null,
            "Je declaratie van € " . number_format((float) $reimbursement->amount, 2, ',', '.') . ($what !== '' ? ' (' . $what . ')' : '') . " is afgewezen.\n\nReden: " . $reason,
            wp_generate_uuid4()
        );
        if (is_wp_error($thread)) {
            avbk_log_failure('reimbursement reject message', $thread->get_error_code(), ['reimbursement' => (int) $reimbursement->id]);
            return ['message_failed' => $thread->get_error_code()];
        }
        return [];
    }

    /**
     * On the member page: the member's declaraties paid or rejected since
     * they last looked, with "Gezien". No mail; a rejection also comes as
     * a message in Berichten.
     */
    public static function render_member_notices(object $own_member, object $target_member, string $page_url): void {
        if ((int) $own_member->id !== (int) $target_member->id) {
            return;
        }
        $decisions = AVBK_DB::get_unseen_reimbursement_decisions((int) $target_member->id);
        if (!$decisions) {
            return;
        }
        ?>
        <div class="avbk-balance-notice avbk-reimbursement-decisions">
            <?php foreach ($decisions as $r) :
                $amount = '€ ' . number_format((float) $r->amount, 2, ',', '.');
                $what = trim((string) $r->description) !== '' ? ' (' . $r->description . ')' : '';
                ?>
                <p><?php echo esc_html($r->status === 'paid'
                    ? sprintf('Je declaratie van %s%s is uitbetaald.', $amount, $what)
                    : sprintf('Je declaratie van %s%s is afgewezen. De reden staat bij je declaraties en in Berichten.', $amount, $what)); ?></p>
            <?php endforeach; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avbk_reimbursement_seen'); ?>
                <input type="hidden" name="action" value="avbk_reimbursement_seen">
                <input type="hidden" name="redirect_url" value="<?php echo esc_url($page_url); ?>">
                <a href="<?php echo esc_url(home_url('/leden/beheer/declareren/')); ?>">Bekijk je declaraties</a>
                <button type="submit" class="button button-small">Gezien</button>
            </form>
        </div>
        <?php
    }

    /**
     * On the member page ("Financiële zaken"): a prominent Declaraties section
     * with a clear button to declare (or resume a draft) plus any unfinished
     * declarations (draft for the member themselves, and pending ones).
     */
    public static function render_member_summary(object $own_member, object $target_member): void {
        $target_id = (int) $target_member->id;
        $is_own = (int) $own_member->id === $target_id;
        $draft = $is_own ? AVBK_DB::get_draft_reimbursement($target_id) : null;
        $pending = array_values(array_filter(
            AVBK_DB::get_reimbursements_for_member($target_id),
            static fn($r) => $r->status === 'pending'
        ));
        if (!$is_own && !$pending) {
            return;
        }
        $declare_url = home_url('/leden/beheer/declareren/');
        $button_label = $draft ? 'Verder met concept →' : 'Bonnetje declareren →';
        ?>
        <section class="avbk-balance-reimbursements">
            <div class="avbk-balance-reimbursements-head">
                <h3>Declaraties</h3>
                <?php if ($is_own) : ?>
                    <a class="avbk-balance-declare-btn" href="<?php echo esc_url($declare_url); ?>"><?php echo esc_html($button_label); ?></a>
                <?php endif; ?>
            </div>
            <?php if ($draft || $pending) : ?>
                <table class="avbk-balance-reimbursements-table">
                    <thead><tr><th>Datum</th><th>Omschrijving</th><th class="avbk-cell-nowrap">Bedrag</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php
                    if ($draft) {
                        self::render_summary_row($draft, 'Nog geen bonnetjes', 'avbk-status-draft', 'Concept', $declare_url, 'verdergaan →');
                    }
                    foreach ($pending as $r) {
                        self::render_summary_row($r, '—', 'avbk-status-pending', 'In behandeling', $is_own ? $declare_url . '#declareren' : '', 'bekijken →');
                    }
                    ?>
                    </tbody>
                </table>
            <?php elseif ($is_own) : ?>
                <p class="avbk-balance-reimbursements-empty">Je hebt op dit moment geen openstaande declaraties.</p>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function render_summary_row(object $row, string $empty_label, string $badge_class, string $badge_label, string $link_url, string $link_text): void {
        $count = count(AVBK_DB::get_reimbursement_receipts((int) $row->id));
        $noun = $count === 1 ? 'bonnetje' : 'bonnetjes';
        $fallback = $count > 0 ? sprintf('%d %s', $count, $noun) : $empty_label;
        $description = trim((string) ($row->description ?? '')) !== '' ? (string) $row->description : $fallback;
        ?>
        <tr>
            <td><?php echo esc_html(wp_date('d-m-Y', strtotime($row->created_at))); ?></td>
            <td><?php echo esc_html($description); ?></td>
            <td class="avbk-cell-nowrap">&euro; <?php echo esc_html(number_format((float) $row->amount, 2, ',', '.')); ?></td>
            <td><span class="avbk-status-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_label); ?></span></td>
            <td><?php if ($link_url !== '') : ?><a href="<?php echo esc_url($link_url); ?>"><?php echo esc_html($link_text); ?></a><?php endif; ?></td>
        </tr>
        <?php
    }

    /** "Gezien" on the member page's declaratie notice. */
    public function handle_seen(): void {
        check_admin_referer('avbk_reimbursement_seen');
        $member = is_user_logged_in() ? avpvh_get_member_by_wp_user(get_current_user_id()) : null;
        if ($member) {
            AVBK_DB::mark_reimbursement_decisions_seen((int) $member->id);
        }
        wp_safe_redirect(esc_url_raw(wp_unslash($_POST['redirect_url'] ?? '')) ?: home_url('/'));
        exit;
    }

    /** The posted toelichting of a declaratie. */
    private static function posted_note(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller checked its nonce.
        return mb_substr(trim(sanitize_textarea_field(wp_unslash($_POST['member_note'] ?? ''))), 0, 2000);
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

        $ext = strtolower(pathinfo($receipt->receipt_path, PATHINFO_EXTENSION));
        $mime_map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'pdf'  => 'application/pdf',
        ];
        $mime = $mime_map[$ext] ?? 'application/octet-stream';
        $disposition = ($ext === 'pdf') ? 'attachment' : 'inline';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="bonnetje.' . $ext . '"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'");
        echo self::filesystem()->get_contents($path); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw binary image/pdf content, not HTML; there's nothing to escape.
        exit;
    }
}
