<?php
/**
 * avbk_iban_page_selection(): which country the IBAN-bankcodes page shows.
 */
define('ABSPATH', __DIR__ . '/');

function sanitize_text_field($value) { return trim((string) $value); }
function wp_unslash($value) { return $value; }

class AVBK_DB {
    public static function get_iban_bank_codes(string $country): array {
        return [(object) ['country' => $country]];
    }
}

require dirname(__DIR__) . '/admin/iban-bank-codes.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

$nl = (object) ['country_code' => 'NL', 'bank_code_length' => 4];
$be = (object) ['country_code' => 'BE', 'bank_code_length' => 3];

$_GET = ['country' => 'be'];
[$country, $format, $codes] = avbk_iban_page_selection([$nl, $be]);
check($country === 'BE' && $format === $be, 'Requested known country, case-insensitive');
check($codes[0]->country === 'BE', 'Bank codes of the selected country');

$_GET = ['country' => 'XX'];
[$country, $format] = avbk_iban_page_selection([$nl, $be]);
check($country === 'NL' && $format === $nl, 'Unknown country falls back to the first format');

$_GET = [];
[$country, $format] = avbk_iban_page_selection([$nl, $be]);
check($country === 'NL', 'No country requested falls back to the first format');

[$country, $format, $codes] = avbk_iban_page_selection([]);
check($country === '' && $format === null && $codes === [], 'No formats: nothing selected');

exit($failures ? 1 : 0);
