<?php
/**
 * AVBK_Mail_History_Import: which FluentSMTP mails are the plugin's own,
 * and how a FluentSMTP row becomes a Verzonden mails row. Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/includes/class-mail-history-import.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

$kind = [AVBK_Mail_History_Import::class, 'kind_for_subject'];
check($kind('Openstaande betaling — Drankrekening Kamp') === 'payment_request', 'Payment request');
check($kind('Openstaande betalingen') === 'payment_request', 'Payment request, plural');
check($kind('[AV-PvH] Tegoed verrekend') === 'credit', 'Credit settled');
check($kind('[AV-PvH] Tegoed geschonken') === 'credit', 'Credit donated');
check($kind('[AV-PvH] Antwoord op je bericht (#12)') === 'dispute_reply', 'Dispute reply');
check($kind('[AV-PvH] Nieuwe declaratie van Piet Jansen') === 'treasurer_notice', 'Notice to the penningmeester');
check($kind('[AV-PvH] Anna heeft tegoed verrekend') === 'treasurer_notice', 'Credit notice to the penningmeester, not a credit mail');
check($kind('Bevestig je bestelling &mdash; Jubileumboek') === 'order', 'Order, with an entity in the subject');
check($kind('Bevestiging bestelling — Lustrumkleding') === 'order', 'Order confirmed');
check($kind('Bestelling geannuleerd — Jubileumkleding') === 'order', 'Order cancelled');
check($kind('Activeer jouw persoonlijke fotomap — 50 jaar archeologie') === 'photo_share', 'Photo share');
check($kind('AV Philips van Horne — Wachtwoord aanmaken') === 'order_lookup', 'Order lookup');
check($kind('AV Philips van Horne — inloggegevens gewijzigd') === null, 'Login mail left out');
check($kind('[Archeologische Vereniging Philips van Horne] gebroken links') === null, 'WordPress mail left out');
check($kind('Fluent SMTP: test e-mail') === null, 'FluentSMTP test left out');

$to = serialize([['email' => 'anna@example.test'], ['email' => 'bram@example.test']]);
check(AVBK_Mail_History_Import::recipients($to) === ['anna@example.test', 'bram@example.test'], 'Serialized recipients');
check(AVBK_Mail_History_Import::recipients('cas@example.test') === ['cas@example.test'], 'Plain address');
check(AVBK_Mail_History_Import::recipients('a:1:{broken') === [], 'Malformed value gives no recipients');
check(AVBK_Mail_History_Import::recipients('O:8:"stdClass":0:{}') === [], 'No objects unserialized');

$headers = serialize(['content-type' => 'text/html']);
$log = (object) ['to' => serialize([['email' => 'Anna@example.test']]), 'subject' => '[AV-PvH] Tegoed verrekend', 'body' => '<p>Dag Anna,</p>', 'headers' => $headers, 'status' => 'sent', 'created_at' => '2026-11-01 10:00:00'];
$asked = [];
$row = AVBK_Mail_History_Import::row_for($log, function (string $email) use (&$asked): ?int { $asked[] = $email; return 7; });
check($row !== null && $row['kind'] === 'credit' && $row['member_id'] === 7 && $row['recipient'] === 'Anna@example.test', 'Row: kind, member and recipient');
check($row['sent_at'] === '2026-11-01 10:00:00' && $row['is_html'] === 1 && $row['status'] === 'sent' && $row['source'] === 'fluentsmtp' && $row['sent_by'] === null, 'Row: date, html, status, source');
check($asked === ['Anna@example.test'], 'Member looked up by the recipient');
$failed = AVBK_Mail_History_Import::row_for((object) (['status' => 'failed'] + (array) $log), fn() => null);
check($failed['status'] === 'failed' && $failed['error'] !== '' && $failed['member_id'] === null, 'Failed mail, no member');
check(AVBK_Mail_History_Import::row_for((object) (['subject' => 'Wachtwoord vergeten'] + (array) $log), fn() => 1) === null, 'Other mail gives no row');

exit($failures ? 1 : 0);
