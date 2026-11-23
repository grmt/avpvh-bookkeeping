<?php
defined('ABSPATH') || exit;

/**
 * Tells avpvh-members the contribution status from these books, so its
 * members list and Contributie tab show what the bookkeeping knows instead
 * of its own (stale) avm_fees records. See AVPVH_Fees in avpvh-members for
 * the filters and the row shape.
 *
 * A member-year's status comes from their contribution fee items:
 * waived when every item is waived, paid when nothing is left, partial
 * when some money came in, otherwise pending. A member without an item
 * but with a fee-exempt flag counts as waived. An unpaid item in a closed
 * book year (avbk_closed_through_year) is left out: the books started
 * after it and don't know whether it was paid.
 *
 * Also refreshes a member's contribution item whenever avpvh-members says
 * the member changed (status, birth date, flags, ...).
 */
class AVBK_Member_Fee_Status implements AVBK_Component {

    public function register(): void {
        add_filter('avpvh_mbrs_fee_manager', [self::class, 'manager'], 10, 2);
        add_filter('avpvh_mbrs_fee_statuses', [self::class, 'statuses'], 10, 3);
        add_filter('avpvh_mbrs_member_fees', [self::class, 'member_fees'], 10, 2);
        add_action('avpvh_mbrs_member_changed', [self::class, 'schedule_refresh']);
    }

    /** @return array{label:string,url:string} */
    public static function manager(mixed $manager, int $member_id = 0): array {
        $url = $member_id
            ? add_query_arg(['page' => 'avbk-members', 'member_id' => $member_id], admin_url('admin.php'))
            : add_query_arg(['page' => 'avbk-rates'], admin_url('admin.php'));
        return ['label' => 'boekhouding', 'url' => $url];
    }

    /**
     * @param int[] $member_ids
     * @return array<int,array<string,mixed>>
     */
    public static function statuses(mixed $statuses, array $member_ids, int $year): array {
        $by_member = [];
        foreach (AVBK_DB::get_contribution_items_with_payments($member_ids, $year) as $item) {
            $by_member[(int) $item->member_id][] = $item;
        }
        $closed = (int) get_option('avbk_closed_through_year', 0);
        $result = [];
        foreach ($member_ids as $member_id) {
            $row = self::summarize($by_member[(int) $member_id] ?? [], $year, $closed)
                ?? self::exempt_row((int) $member_id, $year);
            if ($row) {
                $result[(int) $member_id] = $row;
            }
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> newest year first */
    public static function member_fees(mixed $rows, int $member_id): array {
        $by_year = [];
        foreach (AVBK_DB::get_contribution_items_with_payments([$member_id]) as $item) {
            $by_year[(int) $item->year][] = $item;
        }
        $closed = (int) get_option('avbk_closed_through_year', 0);
        $current_year = (int) current_time('Y');
        $result = [];
        $exempt = isset($by_year[$current_year]) ? null : self::exempt_row($member_id, $current_year);
        if ($exempt) {
            $result[] = $exempt;
        }
        foreach ($by_year as $year => $items) {
            $row = self::summarize($items, $year, $closed);
            if ($row) {
                $result[] = $row;
            }
        }
        usort($result, static fn(array $a, array $b): int => $b['year'] <=> $a['year']);
        return $result;
    }

    /**
     * One member-year's status row from its fee items (each with
     * amount_due, status, paid, paid_date); null when there's nothing to
     * report. Pure, for tests.
     */
    public static function summarize(array $items, int $year, int $closed_through_year): ?array {
        if (!$items) {
            return null;
        }
        $due = 0.0;
        $paid = 0.0;
        $paid_date = null;
        $all_waived = true;
        foreach ($items as $item) {
            $paid += (float) $item->paid;
            if ($item->paid_date && (!$paid_date || $item->paid_date > $paid_date)) {
                $paid_date = substr((string) $item->paid_date, 0, 10);
            }
            if ($item->status !== 'waived') {
                $all_waived = false;
                $due += (float) $item->amount_due;
            }
        }
        $status = self::status($all_waived, $due, $paid);
        if ($year <= $closed_through_year && in_array($status, ['pending', 'partial'], true) && $paid < 0.005) {
            return null;
        }
        return [
            'year'        => $year,
            'status'      => $status,
            'amount_due'  => round($due, 2),
            'amount_paid' => round($paid, 2),
            'paid_date'   => $paid_date,
        ];
    }

    private static function status(bool $all_waived, float $due, float $paid): string {
        if ($all_waived) {
            return 'waived';
        }
        if ($due - $paid <= 0.005) {
            return 'paid';
        }
        return $paid > 0.005 ? 'partial' : 'pending';
    }

    /** Waived for a fee-exempt member without an item that year (none is ever generated for them). */
    private static function exempt_row(int $member_id, int $year): ?array {
        if (!AVPVH_DB::member_is_fee_exempt($member_id)) {
            return null;
        }
        return ['year' => $year, 'status' => 'waived', 'amount_due' => 0.0, 'amount_paid' => 0.0, 'paid_date' => null];
    }

    /** Recompute once per member per request, after everything that request changes is saved. */
    public static function schedule_refresh(int $member_id): void {
        static $scheduled = [];
        if (!$member_id || isset($scheduled[$member_id])) {
            return;
        }
        $scheduled[$member_id] = true;
        add_action('shutdown', static function () use ($member_id): void {
            AVBK_Fee_Generation::generate_contribution_fee_for_member($member_id);
        });
    }
}
