<?php
/** Document storage supports a private location and refreshes Apache rules. */
define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir());
define('AVBK_RECEIPTS_DIR', sys_get_temp_dir() . '/avbk-document-test-' . bin2hex(random_bytes(6)));
require dirname(__DIR__) . '/includes/interface-component.php';
require dirname(__DIR__) . '/includes/class-reimbursements.php';
function wp_mkdir_p($dir): bool { return is_dir($dir) || mkdir($dir, 0700, true); }
class Document_Test_Filesystem {
    public function put_contents($path, $contents): bool { return file_put_contents($path, $contents) !== false; }
}
$wp_filesystem = new Document_Test_Filesystem();
$dir = AVBK_Reimbursements::receipts_dir();
try {
    wp_mkdir_p($dir);
    file_put_contents($dir . '/.htaccess', 'old rules');
    AVBK_Reimbursements::ensure_receipts_dir_protected();
    $rules = file_get_contents($dir . '/.htaccess');
    if ($dir !== AVBK_RECEIPTS_DIR || !str_contains($rules, 'Require all denied') || !str_contains($rules, 'Deny from all') || str_contains($rules, 'FilesMatch')) {
        throw new RuntimeException('FAIL: Storage access rules were not refreshed');
    }
    echo "PASS: Configured private directory is used and older Apache rules are replaced\n";
} finally {
    foreach (glob($dir . '/*') as $file) unlink($file);
    if (file_exists($dir . '/.htaccess')) unlink($dir . '/.htaccess');
    if (is_dir($dir)) rmdir($dir);
}
