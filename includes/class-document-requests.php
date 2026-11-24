<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/interface-component.php';
require_once __DIR__ . '/class-documents.php';
require_once __DIR__ . '/functions-mail.php';
require_once __DIR__ . '/class-reminder-rules.php';

/**
 * A receipt asked from a member: the penningmeester, on Uitgaven, asks the
 * member a payment went to for its receipt (a declaratie paid outside the
 * site, say). The penningmeester picks the member; the ones the account
 * belongs to and whose name looks like the payee's come first. The member
 * gets a mail with a link to "Te doen" on the member page, and uploads a
 * photo or pdf there. Explaining the payment on Uitgaven then uses that
 * receipt when no other document is chosen (AVBK_Expenses).
 */
class AVBK_Document_Requests implements AVBK_Component {

    private const NO_ACCESS = 'Geen toegang.';

    /** Where "Te doen" is on the member page. */
    public const ANCHOR = '#te-doen';

    public function register(): void {
        add_action('admin_post_avbk_request_document', [$this, 'handle_request']);
        add_action('admin_post_avbk_withdraw_document_request', [$this, 'handle_withdraw']);
        add_action('admin_post_avbk_upload_requested_document', [$this, 'handle_upload']);
        add_action('admin_post_avbk_view_requested_document', [$this, 'handle_view']);
    }

    private static function can_manage(): bool {
        return current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('penningmeester');
    }

    private static function expenses_url(int $transaction_id, array $notice): string {
        return add_query_arg(['page' => 'avbk-expenses'] + $notice, admin_url('admin.php')) . '#tx-' . $transaction_id;
    }

    /** "Bon opvragen" on Uitgaven: asks the chosen member for the receipt of an outgoing payment, and mails them. */
    public function handle_request(): void {
        check_admin_referer('avbk_request_document');
        if (!self::can_manage()) {
            wp_die(esc_html(self::NO_ACCESS), 403);
        }
        $transaction_id = (int) ($_POST['transaction_id'] ?? 0);
        $tx = AVBK_DB::get_transaction($transaction_id);
        $member = AVPVH_DB::get_member((int) ($_POST['member_id'] ?? 0));
        $failure = match (true) {
            !$tx || $tx->direction !== 'out' => 'no_transaction',
            !$member                         => 'no_member',
            !AVBK_DB::create_document_request($transaction_id, (int) $member->id) => 'not_saved',
            default                          => '',
        };
        $notice = ['explain_failed' => 'request_failed', 'tx' => $transaction_id];
        if ($failure !== '') {
            avbk_log_failure('document request', $failure, ['transaction' => $transaction_id]);
        } else {
            $notice = ['document_requested' => self::mail_request($member, $tx)];
        }
        wp_safe_redirect(self::expenses_url($transaction_id, $notice));
        exit;
    }

    /**
     * Members to offer first for a payment: those the account belongs to,
     * then those whose name looks like the payee's, each once.
     *
     * @param int[]    $iban_member_ids
     * @param object[] $name_matches From AVBK_Matcher::find_payer_candidates().
     * @return int[]
     */
    public static function suggested_member_ids(array $iban_member_ids, array $name_matches): array {
        $ids = array_merge(array_map('intval', $iban_member_ids), array_map(static fn($match) => (int) $match['member']->id, $name_matches));
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * The suggestions for one payment, as member objects.
     *
     * @param object[] $members Every member that can be asked, for the name match.
     * @return object[]
     */
    public static function suggestions(object $tx, array $members): array {
        $iban = (string) $tx->counterparty_iban;
        $ids = self::suggested_member_ids(
            $iban !== '' ? AVBK_DB::get_member_ids_by_iban($iban) : [],
            trim((string) $tx->counterparty_name) !== '' ? AVBK_Matcher::find_payer_candidates((string) $tx->counterparty_name, $members) : []
        );
        return array_values(array_filter(array_map(static fn($id) => AVPVH_DB::get_member($id), $ids)));
    }

    /** Where the member uploads the receipt. */
    public static function member_page_url(int $member_id): string {
        return add_query_arg(['member_id' => $member_id], home_url('/leden/beheer/member-profile/')) . self::ANCHOR;
    }

    /**
     * The mail asking for the receipt.
     *
     * @return array{0: string, 1: string} Subject and plain-text body.
     */
    public static function request_mail(object $member, object $tx, string $page_url, string $signature): array {
        $first_name = trim((string) ($member->first_name ?? ''));
        $description = trim(AVBK_Matcher::strip_name_field((string) $tx->description));
        $paid = sprintf(
            '€ %s op %s%s',
            number_format((float) $tx->amount, 2, ',', '.'),
            wp_date('d-m-Y', strtotime((string) $tx->transaction_date)),
            $description !== '' ? ' (' . mb_strimwidth($description, 0, 80, '…') . ')' : ''
        );
        $body = ($first_name !== '' ? "Dag {$first_name},\n\n" : '')
            . "De vereniging heeft je {$paid} betaald. Voor de boekhouding heb ik de bon of factuur daarvan nodig.\n\n"
            . "Wil je er een foto of pdf van uploaden op je ledenpagina, onder Te doen?\n{$page_url}\n\n"
            . "Groet,\n{$signature}";
        return ['[AV-PvH] Bon gevraagd bij een betaling', $body];
    }

    /** Mails the request; 'mailed', 'not_mailed', or 'no_address' when the member has no usable address. */
    private static function mail_request(object $member, object $tx): string {
        if (!AVBK_Reminder_Rules::has_mail_address($member)) {
            avbk_log_failure('document request mail', 'no_address', ['transaction' => (int) $tx->id]);
            return 'no_address';
        }
        $from = sanitize_email(get_option('avbk_penningmeester_email', 'penningmeester@avphilipsvanhorne.nl'));
        $signature = sanitize_text_field(get_option('avbk_penningmeester_name', 'de penningmeester'));
        [$subject, $body] = self::request_mail($member, $tx, self::member_page_url((int) $member->id), $signature);
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        if (is_email($from)) {
            $headers[] = 'Reply-To: ' . $from;
        }
        $sent = avbk_mail('document_request', (int) $member->id, (string) $member->email, $subject, $body, $headers);
        if (!$sent) {
            avbk_log_failure('document request mail', 'not_sent', ['transaction' => (int) $tx->id]);
        }
        return $sent ? 'mailed' : 'not_mailed';
    }

    /** Withdraws a request, with the receipt the member uploaded for it, if any. */
    public function handle_withdraw(): void {
        check_admin_referer('avbk_withdraw_document_request');
        if (!self::can_manage()) {
            wp_die(esc_html(self::NO_ACCESS), 403);
        }
        $request = AVBK_DB::get_document_request((int) ($_POST['document_request_id'] ?? 0));
        $document = $request ? AVBK_DB::delete_document_request((int) $request->id) : null;
        $notice = ['document_request_withdrawn' => '1'];
        if ($document === null) {
            avbk_log_failure('document request withdraw', 'not_found', ['request' => (int) ($_POST['document_request_id'] ?? 0)]);
            $notice = ['explain_failed' => 'not_found'];
        }
        AVBK_Documents::delete((string) $document);
        wp_safe_redirect(self::expenses_url($request ? (int) $request->transaction_id : 0, $notice));
        exit;
    }

    /** The member uploads the asked receipt on the member page. */
    public function handle_upload(): void {
        check_admin_referer('avbk_upload_requested_document');
        $own_member = is_user_logged_in() ? avpvh_get_member_by_wp_user(get_current_user_id()) : null;
        $request = AVBK_DB::get_document_request((int) ($_POST['document_request_id'] ?? 0));
        if (!$own_member || !$request || !AVBK_Messages::can_view($own_member, (int) $request->member_id)) {
            wp_die(esc_html(self::NO_ACCESS), 'Fout', ['response' => 403]);
        }
        $redirect = strtok(esc_url_raw(wp_unslash($_POST['redirect_url'] ?? '')) ?: home_url('/'), '#');
        $upload = AVBK_Documents::from_upload('document');
        $stored = isset($upload['tmp']) ? AVBK_Documents::store($upload['tmp'], $upload['ext'], 'requested') : '';
        $saved = $stored !== '' && AVBK_DB::attach_requested_document((int) $request->id, $stored, (string) $upload['name'], (int) $own_member->id);
        if (!$saved) {
            AVBK_Documents::delete($stored);
            avbk_log_failure('requested document upload', $upload['error'] ?? 'not_saved', ['request' => (int) $request->id]);
        }
        wp_safe_redirect(add_query_arg($saved ? 'document_uploaded' : 'document_upload_failed', '1', $redirect) . self::ANCHOR);
        exit;
    }

    /** The uploaded receipt, for the penningmeester. */
    public function handle_view(): void {
        if (!self::can_manage()) {
            wp_die(esc_html(self::NO_ACCESS), 'Fout', ['response' => 403]);
        }
        $request = AVBK_DB::get_document_request((int) ($_GET['id'] ?? 0));
        AVBK_Documents::serve($request ? (string) $request->document_path : '', 'bon-transactie-' . (int) ($request->transaction_id ?? 0));
    }

    public static function view_url(object $request): string {
        return add_query_arg(['action' => 'avbk_view_requested_document', 'id' => (int) $request->id], admin_url('admin-post.php'));
    }

    /**
     * "Te doen" on the member page: the receipts the penningmeester asks
     * this member for, each with an upload form. Nothing when there are none.
     */
    public static function render_member_todos(object $target_member, string $page_url): void {
        $requests = AVBK_DB::get_open_document_requests([(int) $target_member->id]);
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- notices only.
        $uploaded = !empty($_GET['document_uploaded']);
        $failed = !empty($_GET['document_upload_failed']);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if (!$requests && !$uploaded && !$failed) {
            return;
        }
        ?>
        <section class="avbk-todos" id="te-doen">
            <h3>Te doen</h3>
            <?php if ($uploaded) : ?><p class="avbk-balance-notice">Dank je, de bon is naar de penningmeester gestuurd.</p><?php endif; ?>
            <?php if ($failed) : ?><p class="avbk-balance-notice avbk-balance-notice--error">De bon is niet opgeslagen. Kies een pdf of foto (jpg, png, webp, heic) van maximaal 25 MB.</p><?php endif; ?>
            <?php foreach ($requests as $request) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="avbk-todo">
                    <?php wp_nonce_field('avbk_upload_requested_document'); ?>
                    <input type="hidden" name="action" value="avbk_upload_requested_document">
                    <input type="hidden" name="document_request_id" value="<?php echo esc_attr($request->id); ?>">
                    <input type="hidden" name="redirect_url" value="<?php echo esc_url($page_url); ?>">
                    <p>De penningmeester vraagt de bon bij de betaling van <strong>&euro; <?php echo esc_html(number_format((float) $request->amount, 2, ',', '.')); ?></strong> aan jou op <?php echo esc_html(wp_date('d-m-Y', strtotime((string) $request->transaction_date))); ?><?php echo trim((string) $request->description) !== '' ? ' (' . esc_html(mb_strimwidth(AVBK_Matcher::strip_name_field((string) $request->description), 0, 80, '…')) . ')' : ''; ?>.</p>
                    <label for="avbk-todo-document-<?php echo esc_attr($request->id); ?>">Bon of factuur (pdf of foto)</label>
                    <input type="file" name="document" id="avbk-todo-document-<?php echo esc_attr($request->id); ?>" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif" required>
                    <button type="submit" class="button">Uploaden</button>
                </form>
            <?php endforeach; ?>
        </section>
        <?php
    }
}
