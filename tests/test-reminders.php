<?php
/**
 * AVBK_Reminders: which members are shown and ticked for a reminder.
 * Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
const DAY_IN_SECONDS = 86400;
interface AVBK_Component { public function register(): void; }
function is_email($email) { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
function add_action(...$args): void {}
function remove_action(...$args): void {}
require dirname(__DIR__) . '/includes/class-reminders.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

$item = fn(int $id, float $remaining, string $created, int $estimated = 0) => (object) ['id' => $id, 'remaining' => $remaining, 'created_at' => "$created 10:00:00", 'is_estimated' => $estimated];
$anna = (object) ['id' => 1, 'email' => 'anna@example.test'];
$today = '2026-11-19';
$filters = AVBK_Reminders::DEFAULT_FILTERS;

// candidate()
$row = AVBK_Reminders::candidate(1, $anna, [$item(10, 50.0, '2026-01-05'), $item(11, 32.0, '2026-09-01')], ['reminders' => 2, 'last_reminder' => '2026-10-01 09:00:00', 'last_request' => '2026-10-01 09:00:00'], false);
check($row['amount'] === 82.0 && $row['count'] === 2 && $row['fee_item_ids'] === [10, 11], 'Amount, count and items');
check($row['oldest'] === '2026-01-05', 'Oldest item');
check($row['reminders'] === 2 && $row['warnings'] === [], 'History, no warnings');

$warned = AVBK_Reminders::candidate(2, (object) ['email' => 'lid@avpvh.local'], [$item(12, 10.0, '2026-02-01', 1)], [], true);
check($warned['warnings'] === ['geen geldig e-mailadres', 'open bezwaar', 'geschat bedrag'], 'Placeholder address, open dispute and estimate are warned');
check(!AVBK_Reminders::has_mail_address(null) && !AVBK_Reminders::has_mail_address((object) ['email' => 'geen-adres']), 'No member or no valid address');

// matches()
check(AVBK_Reminders::matches($row, $filters, $today), 'Shown with default filters');
check(!AVBK_Reminders::matches($row, ['min_amount' => 100.0] + $filters, $today), 'Below the minimum amount');
check(AVBK_Reminders::matches($row, ['older_than_days' => 300] + $filters, $today), 'Oldest item 318 days old passes 300');
check(!AVBK_Reminders::matches($row, ['older_than_days' => 400] + $filters, $today), 'but not 400');

// selected_by_default()
check(AVBK_Reminders::selected_by_default($row, $filters, $today), 'Last asked 49 days ago: ticked');
check(!AVBK_Reminders::selected_by_default($row, ['not_reminded_days' => 60] + $filters, $today), 'Not ticked when asked within 60 days');
$recent = ['last_request' => '2026-11-12 08:00:00'] + $row;
check(!AVBK_Reminders::selected_by_default($recent, $filters, $today), 'Asked a week ago: not ticked');
$never = ['last_request' => null] + $row;
check(AVBK_Reminders::selected_by_default($never, $filters, $today), 'Never asked: ticked');
check(!AVBK_Reminders::selected_by_default($warned, $filters, $today), 'A warning keeps it unticked');

// subject()
check(AVBK_Reminders::subject([1]) === 'Herinnering: openstaande betaling' && AVBK_Reminders::subject([1, 2]) === 'Herinnering: openstaande betalingen', 'Subject singular and plural');

exit($failures ? 1 : 0);
