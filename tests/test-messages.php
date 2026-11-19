<?php
/**
 * Berichten: the order and visibility of a conversation, and the mail to
 * the member (whole text, link to reply on the site). Fictional data only.
 */
define('ABSPATH', __DIR__ . '/');
function get_option($name, $default = false) { return $default; }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function home_url($path = ''): string { return 'https://example.test' . $path; }
function add_query_arg($args, $url): string { return $url . '?' . http_build_query($args); }
require_once dirname(__DIR__) . '/includes/interface-component.php';
require dirname(__DIR__) . '/includes/class-disputes.php';
require dirname(__DIR__) . '/includes/class-messages.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}
$event = fn(string $type, string $message, ?int $author = null) => (object) ['event_type' => $type, 'message' => $message, 'author_member_id' => $author, 'created_at' => '2026-11-02 10:00:00'];

// A member's question, answered, a note, the member again, closed.
$thread = (object) ['id' => 5, 'member_id' => 10, 'submitted_by_member_id' => 11, 'started_by' => 'member', 'message' => 'Is dit betaald?', 'created_at' => '2026-11-01 09:00:00', 'subject' => 'Kamp', 'status' => 'open', 'awaiting' => 'treasurer'];
$events = [$event('reply', 'Ja, op 3 november.'), $event('note', 'Bank gecontroleerd'), $event('member_message', 'Dank je!', 11), $event('resolved', '')];
$member_view = AVBK_Messages::conversation($thread, $events, false);
check(array_column($member_view, 'author') === ['member', 'treasurer', 'member', 'system'], 'Member sees question, answer, own reply and the closing');
check($member_view[0]['member_id'] === 11 && $member_view[2]['member_id'] === 11, 'Messages are from the member who wrote them');
check(!in_array('Bank gecontroleerd', array_column($member_view, 'text'), true), 'Internal note hidden from the member');
$admin_view = AVBK_Messages::conversation($thread, $events, true);
check(array_column($admin_view, 'kind') === ['message', 'message', 'note', 'message', 'status'], 'Penningmeester sees the note too, in order');

// Started by the penningmeester: no opening text of its own, the first answer is the message.
$started = (object) ['id' => 6, 'member_id' => 10, 'submitted_by_member_id' => null, 'started_by' => 'treasurer', 'message' => '', 'created_at' => '2026-11-01 09:00:00', 'subject' => '', 'status' => 'open', 'awaiting' => 'member'];
$view = AVBK_Messages::conversation($started, [$event('reply', 'Wil je je IBAN controleren?')], false);
check(count($view) === 1 && $view[0]['author'] === 'treasurer', 'A conversation the penningmeester started opens with their message');
check(AVBK_Messages::admin_state($started) === 'Wacht op het lid' && AVBK_Messages::admin_state($thread) === 'Wacht op jou', 'Whose turn it is');
check(AVBK_Messages::admin_state((object) ['status' => 'resolved']) === 'Afgehandeld', 'Closed');

// The mail: the whole text and the link to the conversation.
[$subject, $body] = AVBK_Disputes::reply_mail($thread, (object) ['id' => 11, 'first_name' => 'Anna'], "Ja, op 3 november.\nGroet");
check($subject === '[AV-PvH] Kamp (#5)', 'Subject is the conversation subject');
check(str_starts_with($body, "Dag Anna,\n\nJa, op 3 november.\nGroet"), 'Whole text in the mail');
check(str_contains($body, 'https://example.test/leden/beheer/member-profile/?member_id=10#berichten'), 'Link to the conversation on the member page');
[$subject] = AVBK_Disputes::reply_mail($started, (object) ['id' => 10], 'Tekst');
check($subject === '[AV-PvH] Bericht van de penningmeester (#6)', 'Subject without a topic');

exit($failures ? 1 : 0);
