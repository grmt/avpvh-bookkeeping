<?php
defined('ABSPATH') || exit;

/**
 * Fee items generate themselves — the treasurer's only routine task stays
 * "upload the bank export":
 *  - contribution fee items: a daily cron tick (cheap to run daily since
 *    upserting an already-open item is a harmless no-op / self-heals if
 *    the rate table gets corrected mid-year) creates/refreshes this
 *    year's item for every active member.
 *  - camp fee items: created/refreshed live whenever activity participation
 *    is saved (avpvh_activity_participation_saved, fired from avpvh-members'
 *    AVPVH_DB::save_participation()), so they track nights/attendance
 *    right up to camp time without any admin action.
 */
class AVBK_Fee_Generation {

    const CRON_HOOK = 'avbk_generate_contribution_fees';

    public function __construct() {
        add_action(self::CRON_HOOK, [self::class, 'generate_contribution_fees']);
        add_action('avpvh_activity_participation_saved', [$this, 'on_activity_participation_saved'], 10, 3);
    }

    public static function schedule_cron(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(strtotime('tomorrow 03:00'), 'daily', self::CRON_HOOK);
        }
    }

    /** Creates/refreshes every active member's contribution fee item for $year (defaults to the current year). */
    public static function generate_contribution_fees(?int $year = null): void {
        $year = $year ?? (int) current_time('Y');
        $activity = self::get_contribution_activity($year);
        if (!$activity || !AVBK_DB::get_activity_rates((int) $activity->id)) {
            return; // no "Contributie {year}" activity or no rates configured yet — nothing to generate
        }

        foreach (AVPVH_DB::get_members(['status' => 'active']) as $member) {
            $computed = self::compute_activity_rate($member, $activity, 1, "$year-01-01");
            if (!$computed) {
                continue; // no bracket covers this age, or no rates configured at all yet
            }
            $label = $computed['rate']->label !== '' ? " ({$computed['rate']->label})" : '';
            AVBK_DB::upsert_contribution_fee_item(
                (int) $member->id, $year, $computed['amount'], "Contributie {$year}{$label}", $computed['is_estimated'], $computed['reason']
            );
        }
    }

    /** The "Contributie" activity for $year (an avm_camps row, name "Contributie" + year=$year — same convention as a camp), or null if it doesn't exist yet. Created by the 1.8 migration for years with pre-existing rates; otherwise the treasurer creates it via Activiteiten same as a camp. */
    private static function get_contribution_activity(int $year): ?object {
        return AVPVH_DB::get_activity_by_name_year('Contributie', $year);
    }

    /**
     * Pure calculation, no writes — what $member owes for $activity (a
     * camp, "Contributie {year}", or any other activity) based on today's
     * inputs (status, birth date/year, rate table) and $quantity (nights
     * for a camp, 1 for anything else). Shared by generate_contribution_fees()/
     * generate_camp_fee_item() (which write the result), find_stale_fee_items()
     * (which only compares it against what's already stored), and
     * AVBK_Congress::handle_register() (a congress signup is just another
     * activity), so none of them can ever drift apart. Public for that
     * last reason. Null when no rate
     * bracket/table covers this member at all. $reference_date is what the
     * member's age is evaluated at (a camp's own start date, or 1 January
     * of a contribution year) — not "now", so past/future years and camps
     * still compute correctly.
     */
    public static function compute_activity_rate(object $member, object $activity, int $quantity, string $reference_date): ?array {
        $is_estimated = false;
        $reason = '';
        $rate = null;
        $activity_id = (int) $activity->id;

        // Student is a status flag, not an age bracket (a 22-year-old
        // can be either) — it wins over age when set and a student
        // rate is actually configured.
        if (!empty($member->is_student)) {
            $rate = AVBK_DB::get_student_activity_rate($activity_id);
        }
        if (!$rate) {
            if (!empty($member->birth_date)) {
                $age = self::age_on((string) $member->birth_date, $reference_date);
                $rate = AVBK_DB::get_rate_for_age($activity_id, $age);
            } elseif (!empty($member->birth_year)) {
                // Only the birth *year* is known — a real, if
                // imprecise, age beats the no-date-at-all fallback
                // below (a known 11-year-old shouldn't get bumped to
                // the adult rate just because the exact day is lost).
                $age = self::age_from_year((int) $member->birth_year, $reference_date);
                $rate = AVBK_DB::get_rate_for_age($activity_id, $age);
                if ($rate) {
                    $is_estimated = true;
                    $reason = "Alleen geboortejaar {$member->birth_year} bekend — leeftijd bij benadering ({$age} jaar).";
                }
            } else {
                // No birth date on file at all — assume adult rather
                // than silently skipping the member entirely; flag it
                // so the treasurer can verify/correct instead of the
                // fee item just never existing.
                $rate = AVBK_DB::get_adult_activity_rate($activity_id);
                $is_estimated = true;
                $reason = 'Leeftijd niet bekend — volwassen tarief aangenomen.';
            }
        }
        if (!$rate) {
            return null;
        }
        return [
            'amount'       => round((float) $rate->rate * $quantity, 2),
            'rate'         => $rate,
            'is_estimated' => $is_estimated,
            'reason'       => $reason,
        ];
    }

    public function on_activity_participation_saved(int $member_id, int $activity_id, int $participation_id): void {
        $participation = AVPVH_DB::get_participation_by_id($participation_id);
        if (!$participation || !$participation->nights) {
            return; // nothing to charge until nights are known
        }
        self::generate_camp_fee_item($member_id, $activity_id, (int) $participation->nights);
    }

    /**
     * Backfill/refresh every existing participation record for one activity
     * — needed because the live hook above only fires on a *new* save.
     * Participation entered before this plugin existed (or before a rate
     * was configured) never generated a fee item on its own; this is the
     * one-click catch-up for that. Returns how many fee items were
     * created/updated.
     */
    public static function generate_camp_fees(int $activity_id): int {
        $count = 0;
        foreach (AVPVH_DB::get_participation_for_activity($activity_id) as $participation) {
            if (!$participation->nights) {
                continue;
            }
            if (self::generate_camp_fee_item((int) $participation->member_id, $activity_id, (int) $participation->nights)) {
                $count++;
            }
        }
        return $count;
    }

    private static function generate_camp_fee_item(int $member_id, int $activity_id, int $nights): bool {
        $member = AVPVH_DB::get_member($member_id);
        $activity = AVPVH_DB::get_activity($activity_id);
        if (!$member || !$activity) {
            return false;
        }
        // Age at the activity's own start date, not "now" — a member's age
        // bracket for a past or future camp must reflect their age *then*.
        $reference_date = $activity->start_date ?: current_time('Y-m-d');
        $computed = self::compute_activity_rate($member, $activity, $nights, $reference_date);
        if (!$computed) {
            return false; // no bracket covers this age, or no rates configured at all yet
        }
        $label = $computed['rate']->label !== '' ? " ({$computed['rate']->label})" : '';
        $description = trim('Kamp ' . $activity->name . ' ' . $activity->year) . $label;
        AVBK_DB::upsert_camp_fee_item(
            $member_id, $activity_id, $computed['amount'], $description, $computed['is_estimated'], $computed['reason']
        );
        return true;
    }

    /**
     * Open contribution/camp fee items whose stored amount no longer
     * matches what today's inputs (current nights on file, current birth
     * date/year, current rate table) would produce — the general form of
     * the bug fixed by hand for Cas (birth date added after her fee
     * item was generated) and Finn (nights corrected after generation):
     * a fee item only ever refreshes on the next participation save or a
     * manual "genereren/bijwerken" click, so any other edit leaves it
     * silently stale until someone happens to notice. Surfaced on the
     * Overzicht page so that stops being "until someone happens to
     * notice."
     */
    public static function find_stale_fee_items(): array {
        $stale = [];
        $members = [];
        $camps = [];

        foreach (AVBK_DB::get_open_contribution_and_camp_fee_items() as $item) {
            $member_id = (int) $item->member_id;
            if (!array_key_exists($member_id, $members)) {
                $members[$member_id] = AVPVH_DB::get_member($member_id);
            }
            $member = $members[$member_id];
            if (!$member) {
                continue; // member since removed entirely — out of scope here
            }

            if ($item->type === 'contribution') {
                $year = (int) $item->year;
                $activity = self::get_contribution_activity($year);
                $computed = $activity ? self::compute_activity_rate($member, $activity, 1, "$year-01-01") : null;
            } else {
                $activity_id = (int) $item->activity_id;
                if (!array_key_exists($activity_id, $camps)) {
                    $camps[$activity_id] = AVPVH_DB::get_activity($activity_id);
                }
                $activity = $camps[$activity_id];
                $participation = AVPVH_DB::get_participation($member_id, $activity_id);
                $nights = $participation ? (int) $participation->nights : 0;
                $computed = ($nights && $activity) ? self::compute_activity_rate($member, $activity, $nights, $activity->start_date ?: current_time('Y-m-d')) : null;
            }

            if ($computed && abs($computed['amount'] - (float) $item->amount_due) > 0.005) {
                $stale[] = (object) [
                    'item'            => $item,
                    'member'          => $member,
                    'current_amount'  => $computed['amount'],
                ];
            }
        }
        return $stale;
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

    /**
     * Approximate age from a birth *year* alone (day/month unknown) —
     * reference year minus birth year, i.e. assuming a 1 January birthday.
     * Can be off by up to a year in either direction depending on the real
     * birthday, which is exactly why every caller flags the result as
     * estimated; still real information, unlike the "no birth date at all"
     * fallback. Public for the same reason age_on() is — admin screens
     * showing "why this rate" need it too.
     */
    public static function age_from_year(int $birth_year, string $reference_date): int {
        return (int) (new \DateTime($reference_date))->format('Y') - $birth_year;
    }
}
