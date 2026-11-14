<?php
/**
 * Plugin Name: AV-PvH Bookkeeping
 * Plugin URI:  https://github.com/grmt/avpvh-bookkeeping
 * Description: Membership fee and camp contribution bookkeeping for AV Philips van Horne: imports bank exports, matches payments to members, and shows balances via a QR popup and the profile page.
 * Version:     1.34.3
 * Author:      grmt
 * Author URI:  https://github.com/grmt/avpvh-bookkeeping
 * Text Domain: avpvh-bookkeeping
 * Domain Path: /languages
 * Requires at least: 7.1
 * Requires PHP: 8.4
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:  https://github.com/grmt/avpvh-bookkeeping
 */

defined('ABSPATH') || exit;

define('AVBK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AVBK_PLUGIN_URL', plugin_dir_url(__FILE__));

// Loaded up front: every path that loads a component class (activation,
// plugins_loaded) needs it first.
require_once AVBK_PLUGIN_DIR . 'includes/interface-component.php';

// Every error a user sees also goes to the server log (see the file's docblock).
require_once AVBK_PLUGIN_DIR . 'includes/functions-errors.php';
require_once AVBK_PLUGIN_DIR . 'includes/functions-uploads.php';
avbk_register_error_logging();

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
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-features.php';
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
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-features.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-workbook.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-vat-patterns.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-documents.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-payroll.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-payroll-automation.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-report.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-review.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-cards.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-card-groups.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-ledger.php';
    require_once AVBK_PLUGIN_DIR . 'includes/class-company-admin.php';
    if (get_option('avbk_company_schema') !== '7') {
        AVBK_Companies::install();
    }
    // The company portal also runs without avpvh-members.
    (new AVBK_Company_Admin())->register();
    if (file_exists(AVBK_PLUGIN_DIR . 'vendor/autoload.php')) {
        require_once AVBK_PLUGIN_DIR . 'vendor/autoload.php';
    }
    require_once AVBK_PLUGIN_DIR . 'includes/class-qr.php';
    if (avbk_dependencies_missing()) {
        return;
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

    // The association parts, which need avpvh-members.
    $components = [
        AVBK_Fee_Generation::class,
        AVBK_Fee_Popup::class,
        AVBK_Balance_Shortcode::class,
        AVBK_Reimbursements::class,
        AVBK_Congress::class,
        AVBK_Product_Payment::class,
        AVBK_Book_Order::class,
        AVBK_Tshirt_Order::class,
        AVBK_Photo_Share::class,
        AVBK_Photo_Upload::class,
        AVBK_Google_Photos_Picker::class,
        AVBK_Frontend_Admin_Menu::class,
        AVBK_Admin::class,
    ];
    foreach ($components as $component) {
        (new $component())->register();
    }
});
