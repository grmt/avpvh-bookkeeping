<?php
/** AVBK_Mail_Compose: drafts of a mail checked before sending (Mail controleren). Fictional data only. */
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['transients'] = [];
$GLOBALS['user'] = 5;
function get_current_user_id() { return $GLOBALS['user']; }
function set_transient($name, $value, $ttl) { $GLOBALS['transients'][$name] = $value; return true; }
function get_transient($name) { return $GLOBALS['transients'][$name] ?? false; }
function delete_transient($name) { unset($GLOBALS['transients'][$name]); return true; }
function wp_generate_password($length, $special, $extra) { return substr(str_repeat('Abc123', 4), 0, $length); }
function add_query_arg(array $args, string $url) { return $url . '?' . http_build_query($args); }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
// Enough of wp_kses_post() for the test: scripts and event handlers go, the rest stays.
function wp_kses_post($html) {
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', (string) $html);
    return preg_replace('/\s+on\w+="[^"]*"/i', '', $html);
}

require_once dirname(__DIR__) . '/includes/interface-component.php';
require_once dirname(__DIR__) . '/includes/class-mail-compose.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

// split_html() / join_html()
$mail = '<!doctype html><html><body style="font-family:Arial,sans-serif"><p>Dag Anna,</p><p>Zou je dit willen betalen?</p></body></html>';
[$tag, $inner] = AVBK_Mail_Compose::split_html($mail);
check($tag === '<body style="font-family:Arial,sans-serif">', 'The body tag keeps its style');
check($inner === '<p>Dag Anna,</p><p>Zou je dit willen betalen?</p>', 'The inside of the body');
check(AVBK_Mail_Compose::join_html($tag, $inner) === $mail, 'Joining gives the same mail back');
check(AVBK_Mail_Compose::split_html('<p>Los</p>') === ['<body>', '<p>Los</p>'], 'A mail without a body tag gets a plain one');

// save_draft() / load_draft()
$key = AVBK_Mail_Compose::save_draft(['kind' => 'order_cancel', 'to' => 'a@example.test', 'subject' => 'Bestelling geannuleerd', 'html' => $mail, 'return_url' => 'https://example.test/', 'order_id' => 7]);
check((bool) preg_match('/^[a-z0-9]{20}$/', $key), 'The key is 20 lowercase letters and digits');
$draft = AVBK_Mail_Compose::load_draft($key);
check($draft !== null && $draft['order_id'] === 7 && $draft['body_tag'] === $tag && $draft['body'] === $inner && !isset($draft['html']), 'The draft is stored split, with what the send needs');
check(AVBK_Mail_Compose::url($key) === 'https://example.test/wp-admin/admin.php?page=avbk-compose-mail&draft=' . $key, 'The page link');
$GLOBALS['user'] = 6;
check(AVBK_Mail_Compose::load_draft($key) === null, "Another user can't load the draft");
$GLOBALS['user'] = 5;
check(AVBK_Mail_Compose::load_draft('../etc') === null && AVBK_Mail_Compose::load_draft('') === null, 'A malformed key loads nothing');
$GLOBALS['transients']['avbk_mail_draft_5_zzzzzzzzzzzzzzzzzzzz'] = ['kind' => 'something_else'];
check(AVBK_Mail_Compose::load_draft('zzzzzzzzzzzzzzzzzzzz') === null, 'A draft of an unknown kind loads nothing');
AVBK_Mail_Compose::delete_draft($key);
check(AVBK_Mail_Compose::load_draft($key) === null, 'A deleted (sent) draft is gone');

// posted_mail()
$draft = ['subject' => 'Openstaande betaling', 'body_tag' => $tag];
[$subject, $html] = AVBK_Mail_Compose::posted_mail($draft, ['mail_subject' => ' Herinnering ', 'mail_body' => '<p>Dag Anna,</p><p>Je hoeft niets te betalen.</p><script>alert(1)</script><p onclick="x()">Groet</p>']);
check($subject === 'Herinnering', 'The edited subject is used');
check($html === '<!doctype html><html>' . $tag . '<p>Dag Anna,</p><p>Je hoeft niets te betalen.</p><p>Groet</p></body></html>', 'The edited body is cleaned and wrapped in the stored body tag');
check(AVBK_Mail_Compose::posted_mail($draft, ['mail_subject' => '', 'mail_body' => '<p>Tekst</p>'])[0] === 'Openstaande betaling', 'An emptied subject falls back to the original');
check(AVBK_Mail_Compose::posted_mail($draft, ['mail_subject' => 'X', 'mail_body' => '<p> </p><br>']) === null, 'An empty body is refused');

exit($failures ? 1 : 0);
