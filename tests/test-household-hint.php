<?php
/** Tests for AVBK_Admin::household_payment_hint(): the payment request mail names who can be added. */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
class AVPVH_DB {
    public static array $household = [];
    public static array $relationships = [];
    public static function get_extended_household(int $id): array { return self::$household[$id] ?? []; }
    public static function get_relationships(int $id): array { return self::$relationships[$id] ?? []; }
}
require_once AVBK_PLUGIN_DIR . 'includes/interface-component.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-admin.php';

$failures = 0;
function check(bool $ok, string $label): void { global $failures; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) $failures++; }
function m(int $id, string $name): object { return (object) ['id' => $id, 'first_name' => $name]; }
function rel(int $other, string $label, string $category): object { return (object) ['other_id' => $other, 'label' => $label, 'category' => $category]; }

// Alone: no sentence at all.
AVPVH_DB::$household[1] = [m(1, 'Anna')];
check(AVBK_Admin::household_payment_hint(1) === '', 'no sentence without partner or children');

// Partner and two children.
AVPVH_DB::$household[2] = [m(2, 'Piet'), m(3, 'Anna'), m(4, 'Bram'), m(5, 'Cas')];
AVPVH_DB::$relationships[2] = [rel(3, 'partner van', 'partner'), rel(4, 'kind van', 'ouder_kind'), rel(5, 'kind van', 'ouder_kind')];
check(AVBK_Admin::household_payment_hint(2) === "\n\nOp die pagina kun je ook betalingen voor Anna, Bram en Cas toevoegen.", 'partner and children are named');

// Only one child (a ward counts too). Labels describe the other member: 'kind van' = the other is my child.
AVPVH_DB::$household[6] = [m(6, 'Dirk'), m(7, 'Eva')];
AVPVH_DB::$relationships[6] = [rel(7, 'pupil van', 'voogd')];
check(AVBK_Admin::household_payment_hint(6) === "\n\nOp die pagina kun je ook betalingen voor Eva toevoegen.", 'a single child (via voogd) is named');

// A child's own view: a parent is neither partner nor child, so no sentence.
AVPVH_DB::$household[4] = [m(4, 'Bram'), m(2, 'Piet')];
AVPVH_DB::$relationships[4] = [rel(2, 'ouder van', 'ouder_kind')];
check(AVBK_Admin::household_payment_hint(4) === '', 'no sentence for a parent');

// Partner plus someone else (the partner's child): only the partner is named.
AVPVH_DB::$household[8] = [m(8, 'Fred'), m(9, 'Gea'), m(10, 'Hans')];
AVPVH_DB::$relationships[8] = [rel(9, 'vriend van', 'partner')];
check(AVBK_Admin::household_payment_hint(8) === "\n\nOp die pagina kun je ook betalingen voor Gea toevoegen.", 'only partner and children are named');

// Children and a child's partner: parents can pay for those too.
AVPVH_DB::$household[11] = [m(11, 'Ina'), m(12, 'Jan'), m(13, 'Kim'), m(14, 'Lot')];
AVPVH_DB::$relationships[11] = [rel(12, 'kind van', 'ouder_kind'), rel(13, 'kind van', 'ouder_kind')];
AVPVH_DB::$relationships[12] = [rel(14, 'vriendin van', 'partner'), rel(11, 'ouder van', 'ouder_kind')];
check(AVBK_Admin::household_payment_hint(11) === "\n\nOp die pagina kun je ook betalingen voor Jan, Lot en Kim toevoegen.", "a child's partner is named");

exit($failures ? 1 : 0);
