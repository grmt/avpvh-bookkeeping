<?php
/**
 * Regression checks for AVBK_Import::find_candidates_for_row() — which
 * bank-transaction types get narrowed to a single best-guess payer vs.
 * offered as every known joint-account owner. No real names (see AGENTS.md).
 */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');

class AVPVH_Roles {
    public static function current_user_has_role($role): bool { return true; }
}
class AVPVH_DB {
    public static function get_member(int $id): ?object { return $GLOBALS['members'][$id] ?? null; }
    public static function get_manageable_members(int $member_id): array {
        return isset($GLOBALS['members'][$member_id]) ? [$GLOBALS['members'][$member_id]] : [];
    }
    public static function get_activity_types(): array {
        return array_map(fn($name) => (object) ['name' => $name], ['Kamp', 'Contributie', 'Drank', 'Boek', 'T-shirt']);
    }
}
class AVBK_DB {
    public static function get_payable_members(): array { return array_values($GLOBALS['members']); }
    public static function get_member_ids_by_iban(string $iban): array { return $GLOBALS['iban_owners'][$iban] ?? []; }
    public static function get_payment_household_candidates(int $member_id): array {
        return isset($GLOBALS['members'][$member_id]) ? [$GLOBALS['members'][$member_id]] : [];
    }
    public static function get_current_activity_for_type_name(string $name): ?object { return null; }
}
function avpvh_format_name(object $member, string $format = ''): string { return $member->first_name . ' ' . $member->last_name; }
function current_time(string $format): string { return $format === 'Y' ? '2026' : '2026-10-10'; }

require AVBK_PLUGIN_DIR . 'includes/class-matcher.php';
require AVBK_PLUGIN_DIR . 'includes/class-import.php';

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $message);
    echo "PASS $message\n";
}

function find_candidates_for_row(string $iban, string $name, string $description, array $type_hints): array {
    $method = new ReflectionMethod(AVBK_Import::class, 'find_candidates_for_row');
    $method->setAccessible(true);
    return $method->invoke(null, $iban, $name, $description, $type_hints);
}

$GLOBALS['members'] = [
    1 => (object) ['id' => 1, 'first_name' => 'Anna', 'last_name' => 'Hoef', 'initials' => 'A.'],
    2 => (object) ['id' => 2, 'first_name' => 'Lotte', 'last_name' => 'Erp', 'initials' => 'L.'],
];
$GLOBALS['iban_owners'] = ['NL00TEST0123456789' => [1, 2]];

// Production incident: a transfer from a joint account ("Hoef A. - Erp L.")
// covered two separate people's own kledingbestellingen, but got narrowed
// to a single guessed candidate — the second person's exact-amount order
// was never even offered as a suggestion.
$candidates = find_candidates_for_row('NL00TEST0123456789', 'Hoef A. - Erp L.', 'Trui 10 10 2026', ['T-shirt']);
$ids = array_map(fn($c) => $c['member']->id, $candidates);
check(count($candidates) === 2, 'T-shirt from a joint account suggests both known owners, not just one');
check(in_array(1, $ids, true) && in_array(2, $ids, true), 'both joint-account owners are offered as candidates for a T-shirt payment');

$candidates = find_candidates_for_row('NL00TEST0123456789', 'Hoef A. - Erp L.', 'Boek', ['Boek']);
check(count($candidates) === 2, 'Boek from a joint account also suggests both known owners');

// Drank/Eten/Overig really is one person's own running tab, not a per-item
// order several household members could each separately owe — that must
// still narrow to a single best-guess payer.
$candidates = find_candidates_for_row('NL00TEST0123456789', 'Hoef A. - Erp L.', 'Drankrekening', ['Drank']);
check(count($candidates) === 1, 'Drank from a joint account still narrows to a single best-guess payer');

echo "All candidate-matching checks passed.\n";
