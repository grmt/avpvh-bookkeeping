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
        if (!$tx) {
            return 'no_transaction';
        }
        if (!$fee_item) {
            return 'no_fee_item';
        }
        if ($tx->direction !== 'out' || !empty($tx->duplicate_of) || !in_array($tx->status, ['ignored', 'matched'], true)) {
            return 'not_outgoing';
        }
        if ($amount < 0.01) {
            return 'invalid_amount';
        }
        if ($amount > round((float) $tx->amount - $tx_refunded, 2) + 0.005) {
            return 'exceeds_transaction';
        }
        if ($amount > round($item_paid - $item_refunded, 2) + 0.005) {
            return 'exceeds_paid';
        }
        return null;
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
            $tx->match = $iban !== '' && in_array($iban, $payer, true) ? 2 : ($iban !== '' && in_array($iban, $member, true) ? 1 : 0);
            $candidates[] = $tx;
        }
        usort($candidates, static fn($a, $b) => [$b->match, abs($b->open - $refundable) < 0.005, $b->transaction_date, $b->id]
            <=> [$a->match, abs($a->open - $refundable) < 0.005, $a->transaction_date, $a->id]);
        return $candidates;
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
