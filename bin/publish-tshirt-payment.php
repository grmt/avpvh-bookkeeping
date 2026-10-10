<?php
/**
 * Publiceer de betaalpagina en voeg deze toe aan het jubileummenu.
 * Gebruik: wp eval-file wp-content/plugins/avpvh-bookkeeping/bin/publish-tshirt-payment.php
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}

$payment = AVBK_Product_Payment::get('tshirt');
if ($payment['url'] === '' && !$payment['attachment_id']) {
    WP_CLI::error('Stel eerst het kledingbetaalverzoek in bij Boekhouding → Bestellingen → Instellingen.');
}

// Controleer het menu voordat er iets wordt gepubliceerd.
$matches = [];
foreach (get_posts(['post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => -1]) as $menu) {
    $blocks = parse_blocks($menu->post_content);
    foreach ($blocks as $index => $block) {
        if ($block['blockName'] === 'core/navigation-submenu'
            && strcasecmp($block['attrs']['label'] ?? '', '50 jaar archeo') === 0) {
            $matches[] = ['menu' => $menu, 'blocks' => $blocks, 'index' => $index];
        }
    }
}
if (count($matches) !== 1) {
    WP_CLI::error('Verwacht precies één menu met het submenu 50 jaar archeo.');
}

$existing_page = get_page_by_path('t-shirts-betalen');
if ($existing_page && $existing_page->post_title !== 'T-shirts betalen') {
    WP_CLI::error('Het adres t-shirts-betalen is al in gebruik door een andere pagina.');
}

$content = '<!-- wp:shortcode -->' . "\n" . '[avpvh_bk_product_payment product="tshirt"]' . "\n" . '<!-- /wp:shortcode -->';

if ($existing_page && trim($existing_page->post_content) !== '' && $existing_page->post_content !== $content) {
    WP_CLI::error('De bestaande betaalpagina bevat andere inhoud; deze wordt niet overschreven.');
}

$page_id = wp_insert_post([
    'ID' => $existing_page ? $existing_page->ID : 0,
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'T-shirts betalen',
    'post_name' => 't-shirts-betalen',
    'post_content' => wp_slash($content),
], true);
if (is_wp_error($page_id)) {
    WP_CLI::error($page_id->get_error_message());
}

$match = $matches[0];
$blocks = $match['blocks'];
$submenu = &$blocks[$match['index']];
$link = [
    'blockName' => 'core/navigation-link',
    'attrs' => [
        'label' => 'T-shirts betalen',
        'type' => 'page',
        'id' => $page_id,
        'url' => get_permalink($page_id),
        'kind' => 'post-type',
    ],
    'innerBlocks' => [],
    'innerHTML' => '',
    'innerContent' => [],
];
$children = [];
$inserted = false;
foreach ($submenu['innerBlocks'] as $child) {
    if (($child['attrs']['id'] ?? 0) === $page_id || ($child['attrs']['label'] ?? '') === 'T-shirts betalen') {
        continue;
    }
    $children[] = $child;
    if (($child['attrs']['label'] ?? '') === 'Kleding') {
        $children[] = $link;
        $inserted = true;
    }
}
if (!$inserted) {
    $children[] = $link;
}
$submenu['innerBlocks'] = $children;
$submenu['innerContent'] = [];
foreach ($children as $child) {
    $submenu['innerContent'][] = "\n";
    $submenu['innerContent'][] = null;
}
$submenu['innerContent'][] = "\n";
$updated_menu = serialize_blocks($blocks);
if ($updated_menu !== $match['menu']->post_content) {
    $result = wp_update_post([
        'ID' => $match['menu']->ID,
        'post_content' => wp_slash($updated_menu),
    ], true);
    if (is_wp_error($result)) {
        WP_CLI::error($result->get_error_message());
    }
}
WP_CLI::success('Betaalpagina en jubileummenu klaar: ' . get_permalink($page_id));
