<?php
defined('ABSPATH') || exit;

/**
 * Who the Herinneringen page lists and ticks, and the text and subject of a
 * reminder. Apart from candidates() and the request/option readers, these
 * are pure functions of their arguments; AVBK_Reminders does the sending.
 */
class AVBK_Reminder_Rules {

    public const DEFAULT_INTRO = "Volgens onze administratie staat er nog een betaling open. Zou je die willen betalen?\n\nHeb je al betaald, of klopt er iets niet? Laat het ons weten via je ledenpagina.";

    /** Default filters: everyone with something open who was not reminded in the last two weeks. */
    public const DEFAULT_FILTERS = ['min_amount' => 0.01, 'older_than_days' => 0, 'not_reminded_days' => 14];

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

    public static function subject(array $items): string {
        return count($items) === 1 ? 'Herinnering: openstaande betaling' : 'Herinnering: openstaande betalingen';
    }
}
