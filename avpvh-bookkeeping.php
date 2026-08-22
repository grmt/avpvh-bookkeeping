<?php
/**
 * Plugin Name: AV-PvH Boekhouding
 * Plugin URI:  https://github.com/grmt/avpvh-bookkeeping
 * Description: Contributie- en kampbijdrage-boekhouding voor AV Philips van Horne: bankexports inlezen, betalingen aan leden koppelen, saldo tonen via QR-popup en profielpagina.
 * Version:     1.0.0
 * Author:      grmt
 * Author URI:  https://github.com/grmt/avpvh-bookkeeping
 * Text Domain: avpvh-bookkeeping
 * Requires PHP: 8.2
 */

defined('ABSPATH') || exit;

define('AVBK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AVBK_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Cache-busting version for an asset: the file's own mtime — mirrors
 * avpvh_asset_version() in avpvh-members so deploys invalidate browser
 * caches automatically instead of a hand-maintained version string.
 */
function avbk_asset_version(string $relative_path): string {
    $path = AVBK_PLUGIN_DIR . ltrim($relative_path, '/');
    $mtime = file_exists($path) ? filemtime($path) : false;
    return $mtime ? (string) $mtime : '0.1';
}

/**
 * Every lookup in this plugin goes through avpvh-members' AVPVH_DB (member
 * data, camps, LLDAP identity) and AVPVH_Roles (penningmeester access
 * control) — refuse to run without it rather than fail confusingly deep
 * inside a query.
 */
function avbk_dependencies_missing(): bool {
    return !class_exists('AVPVH_DB') || !class_exists('AVPVH_Roles');
}

add_action('admin_notices', function () {
    if (avbk_dependencies_missing()) {
        echo '<div class="notice notice-error"><p>AV-PvH Boekhouding vereist een actieve, actuele versie van de AV-PvH Leden plugin (AVPVH_DB en AVPVH_Roles).</p></div>';
    }
});

register_activation_hook(__FILE__, function () {
    if (avbk_dependencies_missing()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('AV-PvH Boekhouding vereist dat de AV-PvH Leden plugin actief is (en actueel genoeg is voor AVPVH_Roles). Activeer die eerst.');
    }
    require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';
    AVBK_DB::install();
    require_once AVBK_PLUGIN_DIR . 'includes/class-fee-generation.php';
    AVBK_Fee_Generation::schedule_cron();
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('avbk_generate_contribution_fees');
});

add_action('plugins_loaded', function () {
    if (avbk_dependencies_missing()) {
        return;
    }

    if (file_exists(AVBK_PLUGIN_DIR . 'vendor/autoload.php')) {
        require_once AVBK_PLUGIN_DIR . 'vendor/autoload.php';
    }

    require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';
    AVBK_DB::maybe_upgrade();

    require_once AVBK_PLUGIN_DIR . 'includes/class-xlsx-reader.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-matcher.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-import.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-fee-generation.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-qr.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-fee-popup.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-balance-shortcode.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-congress.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-admin.php';

    new AVBK_Fee_Generation();
    new AVBK_Fee_Popup();
    new AVBK_Balance_Shortcode();
    new AVBK_Congress();
    new AVBK_Admin();
});
