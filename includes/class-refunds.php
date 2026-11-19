<?php
defined('ABSPATH') || exit;

/**
 * Refunds: money paid towards a fee item that went back to the payer, linked
 * to the outgoing bank transaction that paid it back.
 *
 * A refund lives in its own table (avb_refunds), not as a negative
 * allocation. "Paid" and "remaining" keep meaning what was received and
 * what is still owed, so nothing reopens after a refund, and fee generation
 * (which rewrites amount_due on open camp and contribution items) can't
 * undo it. The participation, registration or order itself stays.
 *
 * Credit (a payment that was more than was owed) can be paid back the same
 * way: a refund row without fee item, pointing at the incoming payment whose
 * leftover went back (credit_transaction_id). That leftover is then no
 * longer credit, see AVBK_DB::get_credit_transactions_for_member().
 *
 * Linking a refund marks the outgoing transaction 'matched', which puts it
 * in Tweede controle like any other linked payment.
 */
class AVBK_Refunds {

    /**
     * Why a refund can't be linked, or null when it can.
     *
     * @param float $tx_refunded   Already linked as refund from this transaction.
     * @param float $item_paid     Received towards the fee item.
     * @param float $item_refunded Already refunded for the fee item.
     */
    public static function rejection(?object $tx, ?object $fee_item, float $amount, float $tx_refunded, float $item_paid, float $item_refunded): ?string {
        // In order: the first that applies is the reason. The nullsafe reads
        // are never reached for a missing transaction, which fails first.
        $failed = array_filter([
            'no_transaction'      => !$tx,
            'no_fee_item'         => !$fee_item,
            'not_outgoing'        => !self::is_refundable_outgoing($tx),
            'invalid_amount'      => $amount < 0.01,
            'exceeds_transaction' => $amount > round((float) ($tx?->amount ?? 0) - $tx_refunded, 2) + 0.005,
            'exceeds_paid'        => $amount > round($item_paid - $item_refunded, 2) + 0.005,
        ]);
        return array_key_first($failed);
    }

    /**
     * Why credit can't be paid back from this transaction, or null when it can.
     *
     * @param float $tx_refunded Already linked as refund from this transaction.
     * @param float $credit      The member's available credit.
     */
    public static function credit_rejection(?object $tx, float $amount, float $tx_refunded, float $credit): ?string {
        $failed = array_filter([
            'no_transaction'      => !$tx,
            'not_outgoing'        => !self::is_refundable_outgoing($tx),
            'invalid_amount'      => $amount < 0.01,
            'exceeds_transaction' => $amount > round((float) ($tx?->amount ?? 0) - $tx_refunded, 2) + 0.005,
            'exceeds_credit'      => $amount > $credit + 0.005,
        ]);
        return array_key_first($failed);
    }

    /** An outgoing bank row that isn't a duplicate and isn't explained otherwise. */
    private static function is_refundable_outgoing(?object $tx): bool {
        return $tx?->direction === 'out' && empty($tx->duplicate_of) && in_array($tx->status, ['ignored', 'matched'], true);
    }

    /**
     * How a credit refund is spread over the payments the credit came from:
     * oldest first, as credit is spent (AVBK_DB::apply_credit_fifo()).
     *
     * @param object[] $credit_rows Each with ->id and ->credit, oldest first.
     * @return array<int, float> Incoming transaction id => amount.
     */
    public static function spread_over_credit(array $credit_rows, float $amount): array {
        $parts = [];
        $left = round($amount, 2);
        foreach ($credit_rows as $row) {
            $take = round(min((float) $row->credit, $left), 2);
            if ($take >= 0.01) {
                $parts[(int) $row->id] = $take;
                $left = round($left - $take, 2);
            }
        }
        return $parts;
    }

    /**
     * Outgoing transactions that could be this refund, best first: paid back
     * to an account that paid the fee item, then to one of the member's
     * known accounts, then the rest; within each, the exact amount first,
     * then the most recent.
     *
     * @param object[] $transactions Each with ->id, ->amount, ->refunded, ->counterparty_iban, ->transaction_date.
     * @param string[] $payer_ibans  Accounts that paid this fee item.
     * @param string[] $member_ibans The member's known accounts.
     * @return object[] Each gets ->open (amount not yet linked) and ->match (2 payer, 1 member, 0 other).
     */
    public static function rank_candidates(array $transactions, array $payer_ibans, array $member_ibans, float $refundable): array {
        $normalise = static fn(string $iban): string => strtoupper(str_replace(' ', '', $iban));
        $payer = array_map($normalise, $payer_ibans);
        $member = array_map($normalise, $member_ibans);
        $candidates = [];
        foreach ($transactions as $tx) {
            $open = round((float) $tx->amount - (float) ($tx->refunded ?? 0), 2);
            if ($open < 0.01) {
                continue;
            }
            $iban = $normalise((string) $tx->counterparty_iban);
            $tx->open = $open;
            $tx->match = self::match_level($iban, $payer, $member);
            $candidates[] = $tx;
        }
        usort($candidates, static fn($a, $b) => [$b->match, abs($b->open - $refundable) < 0.005, $b->transaction_date, $b->id]
            <=> [$a->match, abs($a->open - $refundable) < 0.005, $a->transaction_date, $a->id]);
        return $candidates;
    }

    /** 2 when paid back to an account that paid, 1 to another account of the member, 0 otherwise. */
    private static function match_level(string $iban, array $payer_ibans, array $member_ibans): int {
        if ($iban === '') {
            return 0;
        }
        if (in_array($iban, $payer_ibans, true)) {
            return 2;
        }
        return in_array($iban, $member_ibans, true) ? 1 : 0;
    }

    /** Links a refund; returns the refund id, or an error code (see rejection()). */
    public static function add(int $transaction_id, int $fee_item_id, float $amount): int|string {
        $amount = round($amount, 2);
        $tx = AVBK_DB::get_transaction($transaction_id);
        $fee_item = AVBK_DB::get_fee_item($fee_item_id);
        $rejection = self::rejection(
            $tx,
            $fee_item,
            $amount,
            AVBK_DB::get_refunded_for_transaction($transaction_id),
            $fee_item ? AVBK_DB::get_fee_item_paid($fee_item_id) : 0.0,
            AVBK_DB::get_refunded_for_fee_item($fee_item_id)
        );
        if ($rejection !== null) {
            return $rejection;
        }
        $refund_id = AVBK_DB::insert_refund($transaction_id, $fee_item_id, (int) $fee_item->member_id, $amount);
        return $refund_id ?: 'not_saved';
    }

    /**
     * Links an outgoing transaction as paying back (part of) a member's
     * credit; returns the number of refund rows, or an error code (see
     * credit_rejection()).
     */
    public static function add_credit(int $transaction_id, int $member_id, float $amount): int|string {
        $amount = round($amount, 2);
        $tx = AVBK_DB::get_transaction($transaction_id);
        $credit_rows = AVBK_DB::get_credit_transactions_for_member($member_id);
        $credit = round(array_sum(array_map(static fn($row) => (float) $row->credit, $credit_rows)), 2);
        $rejection = self::credit_rejection($tx, $amount, AVBK_DB::get_refunded_for_transaction($transaction_id), $credit);
        if ($rejection !== null) {
            return $rejection;
        }
        $linked = 0;
        foreach (self::spread_over_credit($credit_rows, $amount) as $credit_transaction_id => $part) {
            $linked += AVBK_DB::insert_refund($transaction_id, null, $member_id, $part, $credit_transaction_id) ? 1 : 0;
        }
        return $linked ?: 'not_saved';
    }

    /** Unlinks one refund; the transaction goes back to ignored once it has none left. */
    public static function remove(int $refund_id): bool {
        $refund = AVBK_DB::get_refund($refund_id);
        if (!$refund || empty($refund->credit_transaction_id)) {
            return AVBK_DB::delete_refund($refund_id);
        }
        // One credit refund can be spread over several payments: unlink it whole.
        $removed = true;
        foreach (AVBK_DB::get_refunds_for_transaction((int) $refund->transaction_id) as $part) {
            if (!empty($part->credit_transaction_id) && (int) $part->member_id === (int) $refund->member_id) {
                $removed = AVBK_DB::delete_refund((int) $part->id) && $removed;
            }
        }
        return $removed;
    }
}
