<?php
/** A company-only installation must activate and register its portal without association classes. */
$root = sys_get_temp_dir() . '/avbk-bootstrap-' . bin2hex(random_bytes(6));
mkdir($root . '/wp-admin/includes', 0700, true);
file_put_contents($root . '/wp-admin/includes/upgrade.php', '<?php');
define('ABSPATH', $root . '/');
$GLOBALS['hooks'] = []; $GLOBALS['shortcodes'] = []; $GLOBALS['schemas'] = []; $GLOBALS['options'] = [];
function plugin_dir_path(string $path): string { return dirname($path) . '/'; }
function plugin_dir_url(string $path): string { return 'https://example.test/wp-content/plugins/bookkeeping/'; }
function register_activation_hook(string $file, callable $handler): void { $GLOBALS['activate'] = $handler; }
function register_deactivation_hook(string $file, callable $handler): void { $GLOBALS['deactivate'] = $handler; }
function add_action(string $hook, callable $handler, ...$args): void { $GLOBALS['hooks'][$hook][] = $handler; }
function add_shortcode(string $tag, callable $handler): void { $GLOBALS['shortcodes'][$tag] = $handler; }
function dbDelta(string $sql): void { $GLOBALS['schemas'][] = $sql; }
function get_option(string $name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option(string $name, $value, $autoload = null): bool { $GLOBALS['options'][$name] = $value; return true; }
$wpdb = new class {
    public string $prefix = 'wp_';
    public bool $booking_nullable = false;
    public int $alter_count = 0;
    public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function get_row(string $sql): object { return (object) ['Null' => $this->booking_nullable ? 'YES' : 'NO']; }
    public function query(string $sql): int {
        if (str_contains($sql, 'MODIFY booking_date DATE NULL')) { $this->booking_nullable = true; $this->alter_count++; }
        return 1;
    }
};
try {
    require dirname(__DIR__) . '/avpvh-bookkeeping.php';
    if (!avbk_dependencies_missing()) throw new RuntimeException('Fixture unexpectedly has association dependencies.');
    ($GLOBALS['activate'])();
    if (count($GLOBALS['schemas']) !== 5) throw new RuntimeException('Company and credit-card tables not installed.');
    if (!$wpdb->booking_nullable || $wpdb->alter_count !== 1 || get_option('avbk_company_schema') !== '4') throw new RuntimeException('Existing required booking dates were not migrated explicitly.');
    foreach ($GLOBALS['hooks']['plugins_loaded'] as $handler) $handler();
    if (!isset($GLOBALS['shortcodes']['avbk_company_bookkeeping'], $GLOBALS['hooks']['admin_post_avbk_company'], $GLOBALS['hooks']['admin_post_avbk_company_document'])) throw new RuntimeException('Company portal handlers not registered.');
    if (class_exists('AVBK_DB') || isset($GLOBALS['hooks']['avbk_generate_contribution_fees'])) throw new RuntimeException('Association workflows should not start on the company-only site.');
    echo "PASS: company-only activation installs its tables and registers upload, document, and portal handlers\n";
} finally {
    unlink($root . '/wp-admin/includes/upgrade.php');
    rmdir($root . '/wp-admin/includes'); rmdir($root . '/wp-admin'); rmdir($root);
}
