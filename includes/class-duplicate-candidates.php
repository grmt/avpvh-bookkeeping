<?php
defined('ABSPATH') || exit;

/**
 * "Dubbele betaling van" in Te controleren: which earlier payments to offer
 * first as the original. A double payment usually comes from the same
 * family (two partners, a parent for a child, both paying the same
 * contribution), so payments involving the member's extended household
 * come first: from their accounts, or allocated to their items.
 */
class AVBK_Duplicate_Candidates {

    /**
     * The family of a payment: the members it is about (suggested, or
     * owning the account) and everyone in their extended households.
     *
     * @param int[] $direct_ids
     * @param callable(int): int[] $household_of Member ids in a member's extended household.
     * @return int[]
     */
    public static function relatives(array $direct_ids, callable $household_of): array {
        $family = [];
        foreach (array_unique(array_map('intval', $direct_ids)) as $member_id) {
            $family[$member_id] = true;
            foreach ($household_of($member_id) as $relative_id) {
                $family[(int) $relative_id] = true;
            }
        }
        return array_keys($family);
    }

    /**
     * The candidates involving the family, the payment itself left out:
     * the same amount first, then the most recent.
     *
     * @param object[] $candidates Each with ->id, ->amount, ->transaction_date.
     * @param array<int, int[]> $involved Candidate id => members it involves.
     * @param int[] $family
     * @return int[] Candidate ids.
     */
    public static function preferred(array $candidates, array $involved, array $family, int $exclude_id, float $amount): array {
        $family = array_flip($family);
        $matches = array_values(array_filter($candidates, static fn($c) => (int) $c->id !== $exclude_id
            && array_intersect_key(array_flip($involved[(int) $c->id] ?? []), $family)));
        usort($matches, static fn($a, $b) => [abs((float) $b->amount - $amount) < 0.005, $b->transaction_date, (int) $b->id]
            <=> [abs((float) $a->amount - $amount) < 0.005, $a->transaction_date, (int) $a->id]);
        return array_map(static fn($c) => (int) $c->id, $matches);
    }
}
