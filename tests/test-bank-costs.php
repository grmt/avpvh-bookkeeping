<?php
/**
 * Bank costs explain themselves: the bank's invoice for the club's own
 * account becomes Bankkosten; anything else stays to be explained.
 * Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
interface AVBK_Component { public function register(): void; }
function get_option($name, $default = false) { return ['avbk_club_iban' => 'NL00TEST0123456789', 'avbk_closed_through_year' => 2025][$name] ?? $default; }
function avbk_log_failure(string $where, string $reason, array $ids = []): void { $GLOBALS['logged'][] = "$where: $reason"; }
function sanitize_text_field($text) { return trim((string) $text); }
function sanitize_textarea_field($text) { return trim((string) $text); }
function wp_unslash($value) { return $value; }
final class AVBK_DB {
    public static array $open = [];
    public static array $inserted = [];
    public static int $min_year = -1;
    public static bool $insert_ok = true;
    public static function get_unexplained_outgoing(int $min_year = 0): array { self::$min_year = $min_year; return self::$open; }
    public static function insert_expense(array $data): int {
        self::$inserted[] = $data;
        return self::$insert_ok ? count(self::$inserted) : 0;
    }
}
require dirname(__DIR__) . '/includes/class-bank-costs.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

$tx = fn(int $id, array $changes = []) => (object) ($changes + ['id' => $id, 'direction' => 'out', 'status' => 'ignored', 'duplicate_of' => null, 'amount' => 21.22, 'counterparty_iban' => '', 'counterparty_name' => 'Kosten Zakelijk Betalingsverkeer', 'description' => 'Factuurnr. 123 Betreft IBAN: NL00 TEST 0123 4567 89 Periode: 01-02-2026']);
$club = 'NL00TEST0123456789';

check(AVBK_Bank_Costs::is_bank_costs($tx(1), $club), "The bank's invoice for our account");
check(!AVBK_Bank_Costs::is_bank_costs($tx(2, ['counterparty_iban' => 'NL11BANK0000000001']), $club), 'Not when it went to an account');
check(!AVBK_Bank_Costs::is_bank_costs($tx(3, ['counterparty_name' => 'Drukkerij']), $club), 'Not for another payee');
check(!AVBK_Bank_Costs::is_bank_costs($tx(4, ['description' => 'Betreft IBAN: NL22OTHR0000000002']), $club), "Not for another account's costs");
check(!AVBK_Bank_Costs::is_bank_costs($tx(5, ['direction' => 'in']), $club), 'Not incoming');
check(!AVBK_Bank_Costs::is_bank_costs($tx(1), ''), 'Not without our own IBAN set');

AVBK_DB::$open = [$tx(1), $tx(3, ['counterparty_name' => 'Drukkerij']), $tx(6)];
check(AVBK_Bank_Costs::explain_open() === 2, 'Two of three open payments are bank costs');
check(AVBK_DB::$min_year === 2026, 'Closed years are left alone');
check(AVBK_DB::$inserted[0] === ['transaction_id' => 1, 'kind' => 'bank_costs', 'category' => 'Bankkosten', 'note' => AVBK_Bank_Costs::NOTE], 'Explained as Bankkosten, saying it was automatic');
AVBK_DB::$insert_ok = false;
AVBK_DB::$open = [$tx(7)];
check(AVBK_Bank_Costs::explain_open() === 0 && ($GLOBALS['logged'] ?? []) === ['bank costs: not_saved'], 'A failed save is logged, not counted');

// A bank costs explanation by hand gets the category too.
$_POST = ['category' => '', 'supplier' => 'x'];
check(AVBK_Expenses::posted_input('bank_costs')['category'] === 'Bankkosten', 'By hand: the category is filled in');
$_POST = ['category' => 'Rente'];
check(AVBK_Expenses::posted_input('bank_costs')['category'] === 'Rente', 'unless one was given');

exit($failures ? 1 : 0);
