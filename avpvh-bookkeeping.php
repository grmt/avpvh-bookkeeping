<?php
/**
 * Plugin Name: AV-PvH Boekhouding
 * Plugin URI:  https://github.com/grmt/avpvh-bookkeeping
 * Description: Contributie- en kampbijdrage-boekhouding voor AV Philips van Horne: bankexports inlezen, betalingen aan leden koppelen, saldo tonen via QR-popup en profielpagina.
 * Version:     1.23.0
 * Author:      grmt
 * Author URI:  https://github.com/grmt/avpvh-bookkeeping
 * Text Domain: avpvh-bookkeeping
 * Requires PHP: 8.2
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:  https://github.com/grmt/avpvh-bookkeeping
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
 * Association features use the members plugin. Company administrations
 * run independently, using WordPress accounts and company-scoped roles.
 */
function avbk_dependencies_missing(): bool {
    return !class_exists('AVPVH_DB') || !class_exists('AVPVH_Roles');
}

register_activation_hook(__FILE__, function () {
    require_once AVBK_PLUGIN_DIR . 'includes/class-companies.php';
    AVBK_Companies::install();
    if (avbk_dependencies_missing()) {
        return;
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
    require_once AVBK_PLUGIN_DIR . 'includes/class-xlsx-reader.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-csv-reader.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-bank-import-layout.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-matcher.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-companies.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-workbook.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-vat-patterns.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-documents.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-report.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-cards.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-admin.php';
    if (get_option('avbk_company_schema') !== '2') AVBK_Companies::install();
    new AVBK_Company_Admin();
    if (avbk_dependencies_missing()) {
        return;
    }

    if (file_exists(AVBK_PLUGIN_DIR . 'vendor/autoload.php')) {
        require_once AVBK_PLUGIN_DIR . 'vendor/autoload.php';
    }

    require_once AVBK_PLUGIN_DIR . 'includes/class-db.php';
    AVBK_DB::maybe_upgrade();

    // Lets avpvh-members' "Activiteit verwijderen" (PR #67) know what in
    // this plugin still references an activity, and clean up afterwards.
    add_filter('avpvh_activity_delete_blockers', ['AVBK_DB', 'activity_delete_blockers'], 10, 2);
    add_action('avpvh_activity_deleted', ['AVBK_DB', 'cleanup_deleted_activity']);

    require_once AVBK_PLUGIN_DIR . 'includes/class-xlsx-reader.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-csv-reader.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-bank-import-layout.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-matcher.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-import.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-fee-generation.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-qr.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-flyer.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-ocr.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-fee-popup.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-balance-shortcode.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-disputes.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-reimbursements.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-congress.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-product-payment.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-book-order.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-tshirt-order.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-sheet-import.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-camp-sheet-import.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-photo-share.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-photo-upload.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-google-photos-picker.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-frontend-admin-menu.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-admin.php';

    new AVBK_Fee_Generation();
    new AVBK_Fee_Popup();
    new AVBK_Balance_Shortcode();
    new AVBK_Reimbursements();
    new AVBK_Congress();
    new AVBK_Product_Payment();
    new AVBK_Book_Order();
    new AVBK_Tshirt_Order();
    new AVBK_Photo_Share();
    new AVBK_Photo_Upload();
    new AVBK_Google_Photos_Picker();
    new AVBK_Frontend_Admin_Menu();
    new AVBK_Admin();
});
