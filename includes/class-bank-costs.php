<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-expenses.php';

/**
 * Bank costs explain themselves: the bank's own invoice for the club's
 * account (no counter account, a payee "Kosten …", and "Betreft IBAN:"
 * with the club's own IBAN in the description) is explained as Bankkosten
 * right after an import. It still goes to Tweede controle. Another bank's
 * invoice looks different and simply stays at Te verantwoorden.
 */
class AVBK_Bank_Costs {

    public const NOTE = 'Automatisch herkend als bankkosten.';

    /** Whether an outgoing transaction is the bank's invoice for the account $club_iban. */
    public static function is_bank_costs(object $tx, string $club_iban): bool {
        $club_iban = strtoupper(str_replace(' ', '', $club_iban));
        $description = strtoupper(str_replace(' ', '', (string) $tx->description));
        return $tx->direction === 'out'
            && trim((string) $tx->counterparty_iban) === ''
            && stripos(trim((string) $tx->counterparty_name), 'Kosten ') === 0
            && $club_iban !== ''
            && str_contains($description, 'BETREFTIBAN:' . $club_iban);
    }

    /** Explains every open outgoing transaction that is bank costs; returns how many. */
    public static function explain_open(): int {
        $club_iban = (string) get_option('avbk_club_iban', '');
        $closed_through_year = (int) get_option('avbk_closed_through_year', 0);
        $count = 0;
        foreach (AVBK_DB::get_unexplained_outgoing($closed_through_year > 0 ? $closed_through_year + 1 : 0) as $tx) {
            if (!self::is_bank_costs($tx, $club_iban) || AVBK_Expenses::rejection('bank_costs', $tx, []) !== null) {
                continue;
            }
            $saved = AVBK_DB::insert_expense([
                'transaction_id' => (int) $tx->id,
                'kind'           => 'bank_costs',
                'category'       => AVBK_Expenses::BANK_COSTS_CATEGORY,
                'note'           => self::NOTE,
            ]);
            if (!$saved) {
                avbk_log_failure('bank costs', 'not_saved', ['transaction' => (int) $tx->id]);
            }
            $count += $saved ? 1 : 0;
        }
        return $count;
    }
}
