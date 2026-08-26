<?php
defined('ABSPATH') || exit;

/**
 * Ties AVBK_Xlsx_Reader + AVBK_Matcher + AVBK_DB together: reads an
 * uploaded export, dedupes against previous imports, and applies every
 * confident match immediately (IBAN or reference code) — only rows nobody
 * can be confident about land in the review queue.
 */
class AVBK_Import {

    /** @return array{batch_id:int, row_count:int, matched_count:int} */
    public static function process_file(string $path, string $filename, int $uploaded_by): array {
        $parsed = AVBK_Xlsx_Reader::read($path);
        $batch_id = AVBK_DB::create_import_batch($filename, $uploaded_by);

        $row_count = 0;
        $matched_count = 0;

        foreach ($parsed['rows'] as $raw_row) {
            $tx = AVBK_Matcher::parse_row($raw_row);
            if (!$tx) {
                continue;
            }

            $hash = AVBK_DB::dedupe_hash($tx['transaction_date'], $tx['amount'], $tx['counterparty_iban'], $tx['description']);
            if (AVBK_DB::transaction_exists($hash)) {
                continue; // already imported in a previous upload — safe no-op
            }
            $row_count++;
            $tx['import_batch_id'] = $batch_id;
            $tx['dedupe_hash'] = $hash;

            if ($tx['direction'] !== 'in') {
                // Outgoing (expenses etc.) — kept for the record, never matched.
                $tx['status'] = 'ignored';
                AVBK_DB::insert_transaction($tx);
                continue;
            }

            $type_hints = AVBK_Matcher::classify_types($tx['description']);

            $ref_member_id = AVBK_Matcher::match_reference_code($tx['description']);
            $iban_member_id = AVBK_DB::find_member_id_by_iban($tx['counterparty_iban']);
            $confident_member_id = $ref_member_id ?: $iban_member_id;

            if ($confident_member_id && AVPVH_DB::get_member($confident_member_id)) {
                $tx['status'] = 'matched';
                $tx_id = AVBK_DB::insert_transaction($tx);
                self::apply_payment($tx_id, [$confident_member_id], $tx['amount'], $tx['counterparty_iban'], $type_hints, $tx['counterparty_name']);
                $matched_count++;
                continue;
            }

            $candidates = self::find_candidates_for_row($tx['counterparty_iban'], $tx['counterparty_name'], $tx['description']);
            $tx['status'] = $candidates ? 'suggested' : 'unmatched';
            $tx['suggested_member_ids'] = implode(',', array_map(fn($c) => $c['member']->id, $candidates));
            $tx['suggested_type'] = implode(',', $type_hints);
            AVBK_DB::insert_transaction($tx);
        }

        AVBK_DB::update_import_batch_counts($batch_id, $row_count, $matched_count);
        return ['batch_id' => $batch_id, 'row_count' => $row_count, 'matched_count' => $matched_count];
    }

    /**
     * Re-runs matching against every still-open review-queue row (status
     * 'suggested' or 'unmatched') using the *current* AVBK_Matcher —
     * without re-reading the export file, whose rows are already in the DB
     * and would just be skipped by the dedupe check on re-upload. Needed
     * because a matcher fix/improvement doesn't retroactively touch
     * suggestions computed by the old code at import time. Returns how
     * many rows changed (newly auto-applied, or just got a different
     * suggestion).
     */
    public static function recompute_suggestions(): int {
        $changed = 0;
        foreach (AVBK_DB::get_review_queue() as $tx) {
            $type_hints = AVBK_Matcher::classify_types($tx->description);

            $ref_member_id = AVBK_Matcher::match_reference_code($tx->description);
            $iban_member_id = AVBK_DB::find_member_id_by_iban($tx->counterparty_iban);
            $confident_member_id = $ref_member_id ?: $iban_member_id;

            if ($confident_member_id && AVPVH_DB::get_member($confident_member_id)) {
                self::apply_payment((int) $tx->id, [$confident_member_id], (float) $tx->amount, $tx->counterparty_iban, $type_hints, $tx->counterparty_name);
                $changed++;
                continue;
            }

            $candidates = self::find_candidates_for_row($tx->counterparty_iban, $tx->counterparty_name, $tx->description);
            $new_status = $candidates ? 'suggested' : 'unmatched';
            $new_ids = implode(',', array_map(fn($c) => $c['member']->id, $candidates));
            $new_type = implode(',', $type_hints);

            if ($new_status !== $tx->status || $new_ids !== $tx->suggested_member_ids || $new_type !== $tx->suggested_type) {
                AVBK_DB::update_transaction_suggestion((int) $tx->id, $new_status, $new_ids, $new_type);
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * A known IBAN with 2+ remembered owners (joint account) is a stronger
     * signal than a fuzzy name guess — surface all of them as high-
     * confidence candidates before falling back to name matching. A single-
     * owner IBAN is handled earlier as an auto-apply, not here.
     */
    private static function find_candidates_for_row(string $counterparty_iban, string $counterparty_name, string $description): array {
        if ($counterparty_iban !== '') {
            $joint_owner_ids = AVBK_DB::get_member_ids_by_iban($counterparty_iban);
            if (count($joint_owner_ids) >= 2) {
                $candidates = [];
                foreach ($joint_owner_ids as $member_id) {
                    $member = AVPVH_DB::get_member($member_id);
                    if ($member) {
                        $candidates[] = ['member' => $member, 'score' => 90];
                    }
                }
                if ($candidates) {
                    return $candidates;
                }
            }
        }
        return AVBK_Matcher::find_candidates($counterparty_name, $description);
    }

    /**
     * Splits $amount evenly across $member_ids (remainder cent-rounding on
     * the last member), allocates each share to that member's open fee
     * items oldest-first, and — only when this transaction pays for
     * exactly one member — remembers the IBAN and backfills initials for
     * next time.
     */
    public static function apply_payment(int $transaction_id, array $member_ids, float $amount, string $iban, ?array $type_hints = null, string $counterparty_name = ''): void {
        $member_ids = array_values(array_unique(array_map('intval', $member_ids)));
        if (!$member_ids) {
            return;
        }
        // $type_hints comes from AVBK_Matcher::classify_types() as activity
        // *names* ('Kamp', 'Drank', ...) — avb_fee_items.type is the older,
        // narrower ENUM ('contribution'/'camp'/'event'/'other'), so map
        // through AVBK_DB::activity_fee_type_map() for the priority-sort
        // comparison inside allocate_to_open_items(). A hint with no fee-
        // item-type equivalent (Drank, Overig, ...) just drops out here —
        // this auto-apply path only ever pays off *existing* open items,
        // never creates a new one-off item the way the confirm form can.
        $fee_type_map = AVBK_DB::activity_fee_type_map();
        $enum_type_hints = $type_hints
            ? array_values(array_filter(array_map(fn($name) => $fee_type_map[$name] ?? null, $type_hints)))
            : null;

        $n = count($member_ids);
        $share = round($amount / $n, 2);
        $remaining_total = $amount;

        foreach ($member_ids as $i => $member_id) {
            $this_share = ($i === $n - 1) ? round($remaining_total, 2) : $share;
            $remaining_total -= $this_share;
            self::allocate_to_open_items($transaction_id, $member_id, $this_share, $enum_type_hints ?: null);
        }
        if ($n === 1) {
            if ($iban !== '') {
                AVBK_DB::remember_iban($member_ids[0], $iban, $counterparty_name);
            }
            self::maybe_backfill_initials($member_ids[0], $counterparty_name);
        }
        AVBK_DB::update_transaction_status($transaction_id, 'matched');
    }

    /**
     * Confirms a review-queue row with an explicit treasurer-chosen split —
     * one row per (member, activity, amount). Unlike the old member-wide
     * $type_hints priority-sort, each row's activity is a deliberate,
     * explicit statement of what that money is for, so contribution and
     * camp (or drank, or anything else) for the same person in the same
     * payment can be split across separate rows instead of blended into
     * one combined amount per member.
     *
     * $rows: array of ['member_id' => int, 'activity' => string,
     * 'description' => string, 'amount' => float]. 'activity' is either
     * "a<id>" — a specific avm_activities row the treasurer picked from
     * the review queue's own dropdown, allocated against that member's
     * matching open fee item (unambiguous even when the member has two
     * open items of the same type across different years) — or any other
     * activity-type name (Drank/Eten/Overig/... — a brand new, already-paid
     * fee item is created for it on the spot, same as the old "overige
     * regel"; 'description' is optional free text folded into that item's
     * own description).
     */
    public static function confirm_transaction(int $transaction_id, array $rows): void {
        $tx = AVBK_DB::get_transaction($transaction_id);
        if (!$tx) {
            return;
        }
        $paid_member_ids = [];
        foreach ($rows as $row) {
            $member_id = (int) ($row['member_id'] ?? 0);
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $activity = trim((string) ($row['activity'] ?? ''));
            if ($member_id <= 0 || $amount <= 0 || $activity === '') {
                continue;
            }
            if (preg_match('/^a(\d+)$/', $activity, $m)) {
                self::allocate_to_open_items_of_activity($transaction_id, $member_id, $amount, (int) $m[1]);
            } else {
                $description = (string) ($row['description'] ?? '');
                $fee_item_id = AVBK_DB::create_other_fee_item($member_id, $activity, $description, $amount);
                AVBK_DB::allocate($transaction_id, $fee_item_id, $member_id, $amount);
            }
            $paid_member_ids[] = $member_id;
        }
        $paid_member_ids = array_values(array_unique($paid_member_ids));

        // The IBAN is safe to remember for every payer on a split payment —
        // avb_known_ibans is many-to-many, and a joint account with 2+
        // remembered owners is only ever surfaced as candidates to confirm
        // (see find_candidates_for_row()), never auto-applied — so this
        // never causes a silent misallocation, only a better default
        // suggestion the next time this account pays (e.g. a whole family
        // sharing one account).
        if ($tx->counterparty_iban !== '') {
            foreach ($paid_member_ids as $member_id) {
                AVBK_DB::remember_iban($member_id, $tx->counterparty_iban, $tx->counterparty_name);
            }
        }
        // Initials backfill stays single-payer-only: it parses one name
        // string ("S J M Jansen") into initials for one specific member,
        // and a split transaction gives no way to know which part of that
        // name belongs to which of several payers.
        if (count($paid_member_ids) === 1) {
            self::maybe_backfill_initials($paid_member_ids[0], $tx->counterparty_name);
        }
        AVBK_DB::update_transaction_status($transaction_id, 'matched');
        AVBK_DB::clear_transaction_draft($transaction_id);
    }

    /**
     * Recognizes "<initials> <surname>" in a bank transaction's own payer
     * name (e.g. "S J M Jansen") and, only when the member doesn't already
     * have initials on file, saves it — never overwrites a value someone
     * already entered, since that may have been sourced from an actual
     * passport rather than guessed from one bank transaction.
     */
    private static function maybe_backfill_initials(int $member_id, string $counterparty_name): void {
        if ($counterparty_name === '') {
            return;
        }
        $member = AVPVH_DB::get_member($member_id);
        if (!$member || !empty($member->initials)) {
            return;
        }
        $initials = AVBK_Matcher::extract_initials($counterparty_name, $member->last_name);
        if ($initials) {
            AVPVH_DB::update_member_initials($member_id, $initials);
        }
    }

    /**
     * Allocates $amount to a member's open fee items, oldest first
     * (matching any of $type_hints first when given — a "kamp en
     * contributie" payment can rightly pay off both fee types from one
     * transaction), leaving any un-allocatable remainder simply
     * unallocated — an overpayment or a payment with nothing open to
     * apply it to isn't modeled as a credit balance in this version; the
     * treasurer sees it as a partly-applied transaction in "Alle
     * transacties".
     */
    private static function allocate_to_open_items(int $transaction_id, int $member_id, float $amount, ?array $type_hints): void {
        $open_items = AVBK_DB::get_open_fee_items_for_member($member_id);
        if ($type_hints) {
            usort($open_items, function ($a, $b) use ($type_hints) {
                return (int) in_array($b->type, $type_hints, true) <=> (int) in_array($a->type, $type_hints, true);
            });
        }

        $remaining = round($amount, 2);
        foreach ($open_items as $item) {
            if ($remaining <= 0) {
                break;
            }
            $due_left = round((float) $item->amount_due - AVBK_DB::get_fee_item_paid((int) $item->id), 2);
            if ($due_left <= 0) {
                continue;
            }
            $alloc = min($remaining, $due_left);
            AVBK_DB::allocate($transaction_id, (int) $item->id, $member_id, $alloc);
            $remaining = round($remaining - $alloc, 2);
        }
    }

    /**
     * Allocates $amount to a member's open fee item for exactly one
     * specific activity — the confirm-form counterpart to
     * allocate_to_open_items() above (used by the fully-automatic
     * apply_payment() path, which only has a guessed list of possible
     * types to blend). Here the treasurer picked one specific activity for
     * this one specific row, so there's no blending or fallback to other
     * activities/types: money explicitly assigned to "Kamp Zonneveld
     * (2026)" never quietly pays off a different year's camp fee, or a
     * contribution item, instead. Any remainder that doesn't fit this
     * activity's own open item is simply left unallocated, same as
     * allocate_to_open_items().
     */
    private static function allocate_to_open_items_of_activity(int $transaction_id, int $member_id, float $amount, int $activity_id): void {
        $open_items = array_values(array_filter(
            AVBK_DB::get_open_fee_items_for_member($member_id),
            fn($item) => (int) $item->activity_id === $activity_id
        ));

        $remaining = round($amount, 2);
        foreach ($open_items as $item) {
            if ($remaining <= 0) {
                break;
            }
            $due_left = round((float) $item->amount_due - AVBK_DB::get_fee_item_paid((int) $item->id), 2);
            if ($due_left <= 0) {
                continue;
            }
            $alloc = min($remaining, $due_left);
            AVBK_DB::allocate($transaction_id, (int) $item->id, $member_id, $alloc);
            $remaining = round($remaining - $alloc, 2);
        }
    }
}
