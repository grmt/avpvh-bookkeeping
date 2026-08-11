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
        add_action(self::CRON_HOOK, [self::class, 'generate_contribution_fees']);
        add_action('avpvh_camp_participation_saved', [$this, 'on_camp_participation_saved'], 10, 3);
    }

    public static function schedule_cron(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(strtotime('tomorrow 03:00'), 'daily', self::CRON_HOOK);
        }
    }

    /** Creates/refreshes every active member's contribution fee item for $year (defaults to the current year). */
    public static function generate_contribution_fees(?int $year = null): void {
        $year = $year ?? (int) current_time('Y');
        $rates = AVBK_DB::get_contribution_rates($year);
        if (!$rates) {
            return; // no rates configured yet for this year — nothing to generate
        }

        foreach (AVPVH_DB::get_members(['status' => 'active']) as $member) {
            $is_estimated = false;
            $reason = '';
            if (!empty($member->birth_date)) {
                $age = self::age_on((string) $member->birth_date, "$year-01-01");
                $rate = AVBK_DB::get_rate_for_age($year, $age);
            } else {
                // No birth date on file — assume adult rather than
                // silently skipping the member entirely; flag it so the
                // treasurer can verify/correct instead of the fee item
                // just never existing.
                $rate = AVBK_DB::get_adult_contribution_rate($year);
                $is_estimated = true;
                $reason = 'Geen geboortedatum bekend — volwassen tarief aangenomen.';
            }
            if (!$rate) {
                continue; // no bracket covers this age, or no rates configured at all yet
            }
            $label = $rate->label !== '' ? " ({$rate->label})" : '';
            AVBK_DB::upsert_contribution_fee_item(
                (int) $member->id, $year, (float) $rate->amount, "Contributie {$year}{$label}", $is_estimated, $reason
            );
        }
    }

    public function on_camp_participation_saved(int $member_id, int $camp_id, int $participation_id): void {
        $participation = AVPVH_DB::get_participation_by_id($participation_id);
        if (!$participation || !$participation->nights) {
            return; // nothing to charge until nights are known
        }
        self::generate_camp_fee_item($member_id, $camp_id, (int) $participation->nights);
    }

    /**
     * Backfill/refresh every existing participation record for one camp —
     * needed because the live hook above only fires on a *new* save.
     * Participation entered before this plugin existed (or before a rate
     * was configured) never generated a fee item on its own; this is the
     * one-click catch-up for that. Returns how many fee items were
     * created/updated.
     */
    public static function generate_camp_fees(int $camp_id): int {
        $count = 0;
        foreach (AVPVH_DB::get_participation_for_camp($camp_id) as $participation) {
            if (!$participation->nights) {
                continue;
            }
            if (self::generate_camp_fee_item((int) $participation->member_id, $camp_id, (int) $participation->nights)) {
                $count++;
            }
        }
        return $count;
    }

    private static function generate_camp_fee_item(int $member_id, int $camp_id, int $nights): bool {
        $member = AVPVH_DB::get_member($member_id);
        if (!$member) {
            return false;
        }
        $camp = AVPVH_DB::get_camp($camp_id);
        $is_estimated = false;
        $reason = '';
        if (!empty($member->birth_date)) {
            // Age at the camp's own start date, not "now" — a member's age
            // bracket for a past or future camp must reflect their age
            // *then*.
            $reference_date = ($camp && $camp->start_date) ? $camp->start_date : current_time('Y-m-d');
            $age = self::age_on((string) $member->birth_date, $reference_date);
            $rate = AVBK_DB::get_camp_rate_for_age($camp_id, $age);
        } else {
            // No birth date on file — assume adult rather than silently
            // skipping the member entirely; flag it so the treasurer can
            // verify/correct instead of the fee item just never existing.
            $rate = AVBK_DB::get_adult_camp_rate($camp_id);
            $is_estimated = true;
            $reason = 'Geen geboortedatum bekend — volwassen tarief aangenomen.';
        }
        if (!$rate) {
            return false; // no bracket covers this age, or no rates configured at all yet
        }
        $amount = round((float) $rate->day_rate * $nights, 2);
        $label = $rate->label !== '' ? " ({$rate->label})" : '';
        $description = trim('Kamp ' . ($camp->name ?? '') . ' ' . ($camp->year ?? '')) . $label;
        AVBK_DB::upsert_camp_fee_item($member_id, $camp_id, $amount, $description, $is_estimated, $reason);
        return true;
    }

    /**
     * Age in whole years as of $reference_date (not "now" — needed to
     * generate correct rates for past/future years). Public so admin
     * screens can show "why this rate" too (e.g. the review queue).
     */
    public static function age_on(string $birth_date, string $reference_date): int {
        $birth = new \DateTime($birth_date);
        $ref = new \DateTime($reference_date);
        return $birth->diff($ref)->y;
    }
}
