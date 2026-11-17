<?php
/**
 * Every [$this, 'method'] callback (menu pages, admin-post handlers, AJAX
 * actions) must name a method that exists in the same file. A removed
 * render_iban_bank_codes() left its submenu entry behind, and the page died
 * with a fatal error that no other test noticed.
 */
$root = dirname(__DIR__);
$files = array_merge(glob("$root/includes/*.php"), glob("$root/admin/*.php"), glob("$root/*.php"));
$missing = [];
foreach ($files as $file) {
    $source = file_get_contents($file);
    preg_match_all('/\[\$this,\s*\'(\w+)\'\]/', $source, $matches);
    foreach (array_unique($matches[1]) as $method) {
        if (!preg_match('/function\s+' . $method . '\s*\(/', $source)) {
            $missing[] = basename($file) . ": $method";
        }
    }
}
if ($missing) {
    echo "FAIL: callbacks without a method:\n  " . implode("\n  ", $missing) . "\n";
    exit(1);
}
echo "All \$this callbacks resolve to a method.\n";
