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

            $type_hint = AVBK_Matcher::classify_type($tx['description']);

            $ref_member_id = AVBK_Matcher::match_reference_code($tx['description']);
            $iban_member_id = AVBK_DB::find_member_id_by_iban($tx['counterparty_iban']);
            $confident_member_id = $ref_member_id ?: $iban_member_id;

            if ($confident_member_id && AVPVH_DB::get_member($confident_member_id)) {
                $tx['status'] = 'matched';
                $tx_id = AVBK_DB::insert_transaction($tx);
                self::apply_payment($tx_id, [$confident_member_id], $tx['amount'], $tx['counterparty_iban'], $type_hint);
                $matched_count++;
                continue;
            }

            $candidates = AVBK_Matcher::find_candidates($tx['counterparty_name'], $tx['description']);
            $tx['status'] = $candidates ? 'suggested' : 'unmatched';
            $tx['suggested_member_ids'] = implode(',', array_map(fn($c) => $c['member']->id, $candidates));
            $tx['suggested_type'] = $type_hint ?? '';
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
            $type_hint = AVBK_Matcher::classify_type($tx->description);

            $ref_member_id = AVBK_Matcher::match_reference_code($tx->description);
            $iban_member_id = AVBK_DB::find_member_id_by_iban($tx->counterparty_iban);
            $confident_member_id = $ref_member_id ?: $iban_member_id;

            if ($confident_member_id && AVPVH_DB::get_member($confident_member_id)) {
                self::apply_payment((int) $tx->id, [$confident_member_id], (float) $tx->amount, $tx->counterparty_iban, $type_hint);
                $changed++;
                continue;
            }

            $candidates = AVBK_Matcher::find_candidates($tx->counterparty_name, $tx->description);
            $new_status = $candidates ? 'suggested' : 'unmatched';
            $new_ids = implode(',', array_map(fn($c) => $c['member']->id, $candidates));
            $new_type = $type_hint ?? '';

            if ($new_status !== $tx->status || $new_ids !== $tx->suggested_member_ids || $new_type !== $tx->suggested_type) {
                AVBK_DB::update_transaction_suggestion((int) $tx->id, $new_status, $new_ids, $new_type);
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * Splits $amount evenly across $member_ids (remainder cent-rounding on
     * the last member), allocates each share to that member's open fee
     * items oldest-first, and remembers the IBAN for next time.
     */
    public static function apply_payment(int $transaction_id, array $member_ids, float $amount, string $iban, ?string $type_hint = null): void {
        $member_ids = array_values(array_unique(array_map('intval', $member_ids)));
        if (!$member_ids) {
            return;
        }
        $n = count($member_ids);
        $share = round($amount / $n, 2);
        $remaining_total = $amount;

        foreach ($member_ids as $i => $member_id) {
            $this_share = ($i === $n - 1) ? round($remaining_total, 2) : $share;
            $remaining_total -= $this_share;
            self::allocate_to_open_items($transaction_id, $member_id, $this_share, $type_hint);
            if ($iban !== '') {
                AVBK_DB::remember_iban($member_id, $iban);
            }
        }
        AVBK_DB::update_transaction_status($transaction_id, 'matched');
    }

    /**
     * Confirms a review-queue row with an explicit treasurer-chosen split
     * (member_id => amount), rather than an even split.
     */
    public static function confirm_transaction(int $transaction_id, array $member_amounts, ?string $type_hint = null): void {
        $tx = AVBK_DB::get_transaction($transaction_id);
        if (!$tx) {
            return;
        }
        foreach ($member_amounts as $member_id => $amount) {
            $member_id = (int) $member_id;
            $amount = (float) $amount;
            if ($member_id <= 0 || $amount <= 0) {
                continue;
            }
            self::allocate_to_open_items($transaction_id, $member_id, $amount, $type_hint);
            if ($tx->counterparty_iban !== '') {
                AVBK_DB::remember_iban($member_id, $tx->counterparty_iban);
            }
        }
        AVBK_DB::update_transaction_status($transaction_id, 'matched');
    }

    /**
     * Allocates $amount to a member's open fee items, oldest first
     * (matching $type_hint's items first when given), leaving any
     * un-allocatable remainder simply unallocated — an overpayment or a
     * payment with nothing open to apply it to isn't modeled as a credit
     * balance in this version; the treasurer sees it as a partly-applied
     * transaction in "Alle transacties".
     */
    private static function allocate_to_open_items(int $transaction_id, int $member_id, float $amount, ?string $type_hint): void {
        $open_items = AVBK_DB::get_open_fee_items_for_member($member_id);
        if ($type_hint) {
            usort($open_items, function ($a, $b) use ($type_hint) {
                return ($b->type === $type_hint) <=> ($a->type === $type_hint);
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
}
