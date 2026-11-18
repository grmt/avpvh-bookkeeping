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
            'not_outgoing'        => $tx?->direction !== 'out' || !empty($tx?->duplicate_of) || !in_array($tx?->status, ['ignored', 'matched'], true),
            'invalid_amount'      => $amount < 0.01,
            'exceeds_transaction' => $amount > round((float) ($tx?->amount ?? 0) - $tx_refunded, 2) + 0.005,
            'exceeds_paid'        => $amount > round($item_paid - $item_refunded, 2) + 0.005,
        ]);
        return array_key_first($failed);
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

    /** Unlinks one refund; the transaction goes back to ignored once it has none left. */
    public static function remove(int $refund_id): bool {
        return AVBK_DB::delete_refund($refund_id);
    }
}
