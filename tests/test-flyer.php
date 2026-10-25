<?php
/**
 * Standalone test suite for AVBK_Flyer and the admin QR-flyers page.
 * No real names in tests or test data (see AGENTS.md).
 */
define('ABSPATH', __DIR__ . '/');
define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
define('AVBK_PLUGIN_URL', 'https://example.test/wp-content/plugins/avpvh-bookkeeping/');

require_once AVBK_PLUGIN_DIR . 'vendor/autoload.php';
require_once AVBK_PLUGIN_DIR . 'includes/class-flyer.php';

function check(bool $condition, string $msg): void {
    if (!$condition) {
        throw new RuntimeException("FAILED: $msg");
    }
    echo "PASS $msg\n";
}

// 1. Presets lookup
$presets = AVBK_Flyer::get_presets();
check(isset($presets['boek'], $presets['tshirt'], $presets['foto-delen'], $presets['tshirts-betalen']), 'All 4 presets are defined');

$boek = AVBK_Flyer::get_preset('boek');
check($boek !== null && $boek['url'] === 'https://avpvh.nl/boek', 'Preset boek resolves with correct URL');

$share = AVBK_Flyer::get_preset('share');
check($share !== null && $share['name'] === 'foto-delen', 'Alias share resolves to foto-delen');

check(AVBK_Flyer::get_preset('onbekend') === null, 'Unknown preset returns null');

// 2. Custom item builder
$custom = AVBK_Flyer::build_custom_item(
    'https://avpvh.nl/congres/',
    'Congresdag 2026!'
);
check($custom['name'] === 'congresdag-2026', 'Custom item slugified name generated from title');
check($custom['display_url'] === 'avpvh.nl/congres', 'Display URL stripped of scheme and trailing slash');
check($custom['subtitle'] === AVBK_Flyer::DEFAULT_SUBTITLE, 'Default subtitle filled when empty');
check($custom['instructions'] === AVBK_Flyer::DEFAULT_INSTRUCTIONS, 'Default instructions filled when empty');
check($custom['badge'] === AVBK_Flyer::DEFAULT_BADGE, 'Default badge filled when empty');

// 3. QR SVG generation
$svg = AVBK_Flyer::generate_qr_svg('https://avpvh.nl/boek');
check(str_contains($svg, '<svg width="100%" height="100%"'), 'Generated QR SVG has responsive 100% width and height');
check(str_contains($svg, 'viewBox='), 'Generated QR SVG contains viewBox attribute');

// 4. HTML Flyer rendering (vector QR preset)
$html_boek = AVBK_Flyer::render_flyer_html($boek);
check(str_contains($html_boek, '<!DOCTYPE html>'), 'Flyer HTML is a complete HTML document');
check(str_contains($html_boek, '50 Jaar Jubileumboek'), 'Flyer HTML contains preset title');
check(str_contains($html_boek, 'avpvh.nl/boek'), 'Flyer HTML contains display URL');
check(str_contains($html_boek, '<div class="qr-box">'), 'Flyer HTML contains QR container');
check(str_contains($html_boek, '<svg width="100%" height="100%"'), 'Boek flyer embeds vector SVG QR');

// 5. HTML Flyer rendering (PNG QR preset: tshirts-betalen)
$betalen = AVBK_Flyer::get_preset('tshirts-betalen');
$html_betalen = AVBK_Flyer::render_flyer_html($betalen);
check(str_contains($html_betalen, 'data:image/png;base64,'), 'T-shirts betalen flyer embeds base64 PNG QR image');

// 6. XSS escaping in custom flyer HTML
$xss_item = AVBK_Flyer::build_custom_item(
    'https://avpvh.nl/test',
    '<script>alert(1)</script>',
    'Sub <b>bold</b>',
    'avpvh.nl/"onload="alert(1)',
    'Instr <img src=x>',
    'Badge & co'
);
$html_xss = AVBK_Flyer::render_flyer_html($xss_item);
check(!str_contains($html_xss, '<script>alert(1)</script>'), 'Title HTML tags are escaped');
check(str_contains($html_xss, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Escaped title is present');
check(str_contains($html_xss, 'Badge &amp; co'), 'Ampersand in badge is escaped');

echo "\nAll flyer tests PASSED!\n";
