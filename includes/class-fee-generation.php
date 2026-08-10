<?php
defined('ABSPATH') || exit;

/**
 * Fee items generate themselves — the treasurer's only routine task stays
 * "upload the bank export":
 *  - contribution fee items: a daily cron tick (cheap to run daily since
 *    upserting an already-open item is a harmless no-op / self-heals if
 *    the rate table gets corrected mid-year) creates/refreshes this
 *    year's item for every active member.
 *  - camp fee items: created/refreshed live whenever camp participation is
 *    saved (avpvh_camp_participation_saved, fired from avpvh-members'
 *    AVPVH_DB::save_participation()), so they track nights/attendance
 *    right up to camp time without any admin action.
 */
class AVBK_Fee_Generation {

    const CRON_HOOK = 'avbk_generate_contribution_fees';

    public function __construct() {
        add_action(self::CRON_HOOK, [$this, 'generate_contribution_fees']);
        add_action('avpvh_camp_participation_saved', [$this, 'on_camp_participation_saved'], 10, 3);
    }

    public static function schedule_cron(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(strtotime('tomorrow 03:00'), 'daily', self::CRON_HOOK);
        }
    }

    /** Creates/refreshes every active member's contribution fee item for $year (defaults to the current year). */
    public function generate_contribution_fees(?int $year = null): void {
        $year = $year ?? (int) current_time('Y');
        $rates = AVBK_DB::get_contribution_rates($year);
        if (!$rates) {
            return; // no rates configured yet for this year — nothing to generate
        }

        foreach (AVPVH_DB::get_members(['status' => 'active']) as $member) {
            if (empty($member->birth_date)) {
                continue; // can't place an age-based rate without a birth date
            }
            $age = self::age_on((string) $member->birth_date, "$year-01-01");
            $rate = AVBK_DB::get_rate_for_age($year, $age);
            if (!$rate) {
                continue; // no bracket covers this age — treasurer needs to widen the rate table
            }
            $label = $rate->label !== '' ? " ({$rate->label})" : '';
            AVBK_DB::upsert_contribution_fee_item(
                (int) $member->id, $year, (float) $rate->amount, "Contributie {$year}{$label}"
            );
        }
    }

    public function on_camp_participation_saved(int $member_id, int $camp_id, int $participation_id): void {
        $participation = AVPVH_DB::get_participation_by_id($participation_id);
        if (!$participation || !$participation->nights) {
            return; // nothing to charge until nights are known
        }
        $member = AVPVH_DB::get_member($member_id);
        if (!$member || empty($member->birth_date)) {
            return; // can't place an age-bracket rate without a birth date
        }
        $camp = AVPVH_DB::get_camp($camp_id);
        // Age at the camp's own start date, not "now" — a member's age
        // bracket for a past or future camp must reflect their age *then*.
        $reference_date = ($camp && $camp->start_date) ? $camp->start_date : current_time('Y-m-d');
        $age = self::age_on((string) $member->birth_date, $reference_date);
        $rate = AVBK_DB::get_camp_rate_for_age($camp_id, $age);
        if (!$rate) {
            return; // no bracket covers this age — surfaced as an admin notice instead of guessing
        }
        $amount = round((float) $rate->day_rate * (int) $participation->nights, 2);
        $label = $rate->label !== '' ? " ({$rate->label})" : '';
        $description = trim('Kamp ' . ($camp->name ?? '') . ' ' . ($camp->year ?? '')) . $label;
        AVBK_DB::upsert_camp_fee_item($member_id, $camp_id, $amount, $description);
    }

    /** Age in whole years as of $reference_date (not "now" — needed to generate correct rates for past/future years). */
    private static function age_on(string $birth_date, string $reference_date): int {
        $birth = new \DateTime($birth_date);
        $ref = new \DateTime($reference_date);
        return $birth->diff($ref)->y;
    }
}
