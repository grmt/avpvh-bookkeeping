<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/functions-mail.php';

/**
 * Herinneringen: the penningmeester reminds members with open items to pay,
 * many at once. The page lists everyone with something open (outside closed
 * years), how often they were reminded and when they were last asked; the
 * penningmeester picks who gets a mail and adjusts the opening text.
 *
 * A round is stored first (one pending reminder per member, protected
 * against a double click by its request key) and then sent in small batches
 * from the browser, which shows the progress. Each reminder is claimed
 * before it is sent, so nobody gets the same reminder twice. The mail is the
 * member page's payment request (AVBK_Admin::send_balance_request()), logged
 * as kind 'reminder' in Verzonden mails, which is also where the count comes
 * from.
 */
class AVBK_Reminders implements AVBK_Component {

    public const DEFAULT_INTRO = "Volgens onze administratie staat er nog een betaling open. Zou je die willen betalen?\n\nHeb je al betaald, of klopt er iets niet? Laat het ons weten via je ledenpagina.";

    /** Default filters: everyone with something open who was not reminded in the last two weeks. */
    public const DEFAULT_FILTERS = ['min_amount' => 0.01, 'older_than_days' => 0, 'not_reminded_days' => 14];

    public const BATCH_SIZE = 5;

    private const NO_ACCESS = 'Geen toegang.';

    /**
     * One row of the overview: what a member owes and how they were asked.
     *
     * @param object[] $items Open items with ->id, ->remaining, ->created_at, ->is_estimated.
     * @param array{reminders?: int, last_reminder?: ?string, last_request?: ?string} $history
     * @return array{member_id: int, member: ?object, fee_item_ids: int[], amount: float, count: int, oldest: string, reminders: int, last_reminder: ?string, last_request: ?string, warnings: string[]}
     */
    public static function candidate(int $member_id, ?object $member, array $items, array $history, bool $disputed): array {
        $warnings = [];
        if (!self::has_mail_address($member)) {
            $warnings[] = 'geen geldig e-mailadres';
        }
        if ($disputed) {
            $warnings[] = 'open bezwaar';
        }
        if (array_filter($items, static fn($item) => !empty($item->is_estimated))) {
            $warnings[] = 'geschat bedrag';
        }
        $dates = array_filter(array_map(static fn($item) => substr((string) ($item->created_at ?? ''), 0, 10), $items));
        return [
            'member_id'     => $member_id,
            'member'        => $member,
            'fee_item_ids'  => array_map(static fn($item) => (int) $item->id, $items),
            'amount'        => round(array_sum(array_map(static fn($item) => (float) $item->remaining, $items)), 2),
            'count'         => count($items),
            'oldest'        => $dates ? min($dates) : '',
            'reminders'     => (int) ($history['reminders'] ?? 0),
            'last_reminder' => $history['last_reminder'] ?? null,
            'last_request'  => $history['last_request'] ?? null,
            'warnings'      => $warnings,
        ];
    }

    /** Whether a member can be mailed: a valid address that isn't a placeholder. */
    public static function has_mail_address(?object $member): bool {
        $email = strtolower(trim((string) ($member->email ?? '')));
        return $email !== '' && is_email($email) && !str_ends_with($email, '@avpvh.local');
    }

    /**
     * Whether a row is shown with these filters.
     *
     * @param array{min_amount: float, older_than_days: int, not_reminded_days: int} $filters
     */
    public static function matches(array $row, array $filters, string $today): bool {
        if ($row['amount'] < $filters['min_amount'] - 0.005) {
            return false;
        }
        return $filters['older_than_days'] <= 0
            || ($row['oldest'] !== '' && self::days_between($row['oldest'], $today) >= $filters['older_than_days']);
    }

    /**
     * Whether a shown row is ticked to begin with: mailable, no warnings, and
     * not asked for payment within the last not_reminded_days days.
     *
     * @param array{min_amount: float, older_than_days: int, not_reminded_days: int} $filters
     */
    public static function selected_by_default(array $row, array $filters, string $today): bool {
        if ($row['warnings']) {
            return false;
        }
        return $row['last_request'] === null
            || self::days_between(substr($row['last_request'], 0, 10), $today) >= $filters['not_reminded_days'];
    }

    private static function days_between(string $from, string $to): int {
        return (int) floor((strtotime($to) - strtotime($from)) / DAY_IN_SECONDS);
    }

    /** @return array{min_amount: float, older_than_days: int, not_reminded_days: int} */
    public static function filters_from_request(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters of a list.
        $filters = self::DEFAULT_FILTERS;
        if (isset($_GET['min_amount'])) {
            $filters['min_amount'] = max(0.01, (float) str_replace(',', '.', sanitize_text_field(wp_unslash($_GET['min_amount']))));
        }
        foreach (['older_than_days', 'not_reminded_days'] as $key) {
            if (isset($_GET[$key])) {
                $filters[$key] = max(0, (int) $_GET[$key]);
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return $filters;
    }

    /**
     * Every member with something open, as overview rows, most owed first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function candidates(): array {
        $history = AVBK_DB::get_request_history();
        $disputed = array_flip(AVBK_DB::get_open_dispute_member_ids());
        $rows = [];
        foreach (AVBK_DB::get_open_items_by_member() as $member_id => $items) {
            $rows[] = self::candidate($member_id, AVPVH_DB::get_member($member_id), $items, $history[$member_id] ?? [], isset($disputed[$member_id]));
        }
        usort($rows, static fn($a, $b) => $b['amount'] <=> $a['amount']);
        return $rows;
    }

    public static function intro(): string {
        $saved = trim((string) get_option('avbk_reminder_intro', ''));
        return $saved !== '' ? $saved : self::DEFAULT_INTRO;
    }

    public function register(): void {
        add_action('admin_post_avbk_reminders_start', [$this, 'handle_start']);
        add_action('admin_post_avbk_reminders_preview', [$this, 'handle_preview']);
        add_action('admin_post_avbk_reminders_test', [$this, 'handle_test']);
        add_action('admin_post_avbk_reminders_batch', [$this, 'handle_batch']);
        add_action('wp_ajax_avbk_reminders_batch', [$this, 'ajax_batch']);
    }

    private function can_manage(): bool {
        return current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('penningmeester');
    }

    private static function page_url(array $args = []): string {
        return add_query_arg(['page' => 'avbk-reminders'] + $args, admin_url('admin.php'));
    }

    /**
     * The posted selection and text, shared by start, preview and test.
     *
     * @return array{member_ids: int[], intro: string}
     */
    private static function posted_selection(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- every caller checked its nonce first.
        $member_ids = array_values(array_unique(array_filter(array_map('intval', (array) wp_unslash($_POST['member_ids'] ?? [])))));
        $intro = trim(sanitize_textarea_field(wp_unslash($_POST['intro'] ?? '')));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return ['member_ids' => $member_ids, 'intro' => $intro !== '' ? $intro : self::DEFAULT_INTRO];
    }

    /** Stores the round and goes to its progress page, where the browser sends it. */
    public function handle_start(): void {
        check_admin_referer('avbk_reminders_send');
        if (!$this->can_manage()) {
            wp_die(esc_html(self::NO_ACCESS), 403);
        }
        ['member_ids' => $member_ids, 'intro' => $intro] = self::posted_selection();
        $request_key = sanitize_text_field(wp_unslash($_POST['request_key'] ?? ''));
        if (!empty($_POST['save_intro'])) {
            update_option('avbk_reminder_intro', $intro, false);
        }
        // Re-derive what each member owes now instead of trusting the page.
        $open = AVBK_DB::get_open_items_by_member();
        $reminders = [];
        foreach ($member_ids as $member_id) {
            $items = $open[$member_id] ?? [];
            if ($items && self::has_mail_address(AVPVH_DB::get_member($member_id))) {
                $reminders[] = [
                    'member_id'    => $member_id,
                    'fee_item_ids' => array_map(static fn($item) => (int) $item->id, $items),
                    'amount'       => array_sum(array_map(static fn($item) => (float) $item->remaining, $items)),
                ];
            }
        }
        $round_id = 0;
        if ($reminders && preg_match('/^[0-9a-f-]{36}$/i', $request_key)) {
            $round_id = AVBK_DB::create_reminder_round($request_key, $intro, $reminders);
        }
        if (!$round_id) {
            avbk_log_failure('reminders start', $reminders ? 'not_saved' : 'no_recipients', ['selected' => count($member_ids)]);
            wp_safe_redirect(self::page_url(['start_failed' => $reminders ? 'not_saved' : 'no_recipients']));
            exit;
        }
        wp_safe_redirect(self::page_url(['round' => $round_id]));
        exit;
    }

    /** The mail for the first selected member, as it would be sent; in a new tab. */
    public function handle_preview(): void {
        check_admin_referer('avbk_reminders_send');
        if (!$this->can_manage()) {
            wp_die(esc_html(self::NO_ACCESS), 403);
        }
        ['member_ids' => $member_ids, 'intro' => $intro] = self::posted_selection();
        [$member, $items] = self::first_mailable($member_ids);
        if (!$member) {
            wp_die('Kies minstens één lid met openstaande posten.', 'Voorbeeld', ['response' => 400]);
        }
        header('Content-Type: text/html; charset=UTF-8');
        // The mail's own markup, shown in its own sandboxed document.
        header('Content-Security-Policy: sandbox; default-src \'none\'; style-src \'unsafe-inline\'; img-src data:');
        echo AVBK_Admin::balance_request_preview($member, $items, AVBK_Admin::balance_request_url((int) $member->id, $items), $intro); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the mail body, built from escaped parts.
        exit;
    }

    /** Sends the first selected member's reminder to the penningmeester's own address. */
    public function handle_test(): void {
        check_admin_referer('avbk_reminders_send');
        if (!$this->can_manage()) {
            wp_die(esc_html(self::NO_ACCESS), 403);
        }
        ['member_ids' => $member_ids, 'intro' => $intro] = self::posted_selection();
        [$member, $items] = self::first_mailable($member_ids);
        $own_address = (string) wp_get_current_user()->user_email;
        $notice = ['test_sent' => '1'];
        if (!$member || !is_email($own_address)) {
            $notice = ['test_failed' => '1'];
        } else {
            $test_member = clone $member;
            $test_member->email = $own_address;
            $result = AVBK_Admin::send_balance_request($test_member, $items, ['applied' => 0.0, 'sources' => [], 'left' => 0.0], AVBK_Admin::balance_request_url((int) $member->id, $items), '[TEST] ' . self::subject($items), 'reminder_test', $intro);
            $notice = $result['sent'] ? $notice : ['test_failed' => '1'];
        }
        if (isset($notice['test_failed'])) {
            avbk_log_failure('reminders test', 'not_sent', ['selected' => count($member_ids)]);
        }
        wp_safe_redirect(self::page_url($notice));
        exit;
    }

    /**
     * The first selected member that can be mailed, with their open items.
     *
     * @param int[] $member_ids
     * @return array{0: ?object, 1: object[]}
     */
    private static function first_mailable(array $member_ids): array {
        $open = AVBK_DB::get_open_items_by_member();
        foreach ($member_ids as $member_id) {
            $member = AVPVH_DB::get_member($member_id);
            $items = AVBK_Admin::open_items_for_reminder($member_id, array_map(static fn($item) => (int) $item->id, $open[$member_id] ?? []));
            if ($items && self::has_mail_address($member)) {
                return [$member, $items];
            }
        }
        return [null, []];
    }

    public static function subject(array $items): string {
        return count($items) === 1 ? 'Herinnering: openstaande betaling' : 'Herinnering: openstaande betalingen';
    }

    /**
     * Sends the next batch of a round.
     *
     * @return array{pending: int, sending: int, sent: int, failed: int, skipped: int}
     */
    public static function send_batch(int $round_id): array {
        $round = AVBK_DB::get_reminder_round($round_id);
        if (!$round) {
            return ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        }
        foreach (AVBK_DB::claim_pending_reminders($round_id, self::BATCH_SIZE) as $reminder) {
            [$status, $error] = self::send_one($reminder, (string) $round->intro);
            AVBK_DB::finish_reminder((int) $reminder->id, $status, $error);
            if ($status === 'failed') {
                avbk_log_failure('reminder', $error !== '' ? 'mail_failed' : 'not_sent', ['round' => $round_id, 'member' => (int) $reminder->member_id]);
            }
        }
        return AVBK_DB::reminder_counts($round_id);
    }

    /**
     * One reminder, with what is still open now: an item paid since the
     * round started is left out, and a member with nothing left is skipped.
     *
     * @return array{0: string, 1: string} Status and error.
     */
    private static function send_one(object $reminder, string $intro): array {
        $member = AVPVH_DB::get_member((int) $reminder->member_id);
        if (!self::has_mail_address($member)) {
            return ['failed', 'geen geldig e-mailadres'];
        }
        $items = AVBK_Admin::open_items_for_reminder((int) $reminder->member_id, array_map('intval', explode(',', (string) $reminder->fee_item_ids)));
        if (!$items) {
            return ['skipped', 'niets meer open'];
        }
        $result = AVBK_Admin::send_balance_request($member, $items, ['applied' => 0.0, 'sources' => [], 'left' => 0.0], AVBK_Admin::balance_request_url((int) $member->id, $items), self::subject($items), 'reminder', $intro);
        return $result['sent'] ? ['sent', ''] : ['failed', $result['error'] !== '' ? $result['error'] : 'niet verstuurd'];
    }

    /** The browser's batch call while the progress page is open. */
    public function ajax_batch(): void {
        check_ajax_referer('avbk_reminders_batch');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => self::NO_ACCESS], 403);
        }
        wp_send_json_success(self::send_batch((int) ($_POST['round'] ?? 0)));
    }

    /** Without JavaScript: one batch per click. */
    public function handle_batch(): void {
        check_admin_referer('avbk_reminders_batch');
        if (!$this->can_manage()) {
            wp_die(esc_html(self::NO_ACCESS), 403);
        }
        $round_id = (int) ($_POST['round'] ?? 0);
        self::send_batch($round_id);
        wp_safe_redirect(self::page_url(['round' => $round_id]));
        exit;
    }
}
