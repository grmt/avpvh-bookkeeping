<?php
/** Reject entity declarations and oversized ZIP contents before parsing XLSX parts. */
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/includes/class-xlsx-reader.php';
function xlsx_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . "\n";
}
$parse = new ReflectionMethod(AVBK_Xlsx_Reader::class, 'parse_xml');
foreach (['<!DOCTYPE workbook [<!ENTITY x SYSTEM "file:///etc/passwd">]><workbook>&x;</workbook>',
    '<!DOCTYPE workbook SYSTEM "https://example.test/external.dtd"><workbook/>',
    '<!ENTITY x "expanded"><workbook/>',
    "\xff\xfe" . iconv('UTF-8', 'UTF-16LE', '<!DOCTYPE workbook><workbook/>')] as $xml) {
    $rejected = false;
    try { $parse->invoke(null, $xml); } catch (AVBK_Import_Exception $e) { $rejected = true; }
    xlsx_check($rejected, 'Rejects XML entity/document-type declarations, including UTF-16');
}
xlsx_check($parse->invoke(null, '<workbook><sheets/></workbook>') instanceof SimpleXMLElement, 'Ordinary XML remains readable');
if (!class_exists('ZipArchive')) {
    echo "SKIP: Archive tests require ZipArchive.\n";
    exit;
}
function workbook_file(array $parts): string {
    $path = tempnam(sys_get_temp_dir(), 'avbk-xlsx-security-');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($parts as $name => $content) $zip->addFromString($name, $content);
    $zip->close();
    return $path;
}
$path = workbook_file(['xl/workbook.xml' => '<workbook><sheets><sheet name="Test"/></sheets></workbook>']);
try { xlsx_check(AVBK_Xlsx_Reader::sheet_names($path) === ['Test'], 'Safe workbook can be read'); }
finally { unlink($path); }
foreach (['member', 'total', 'count'] as $case) {
    // Build each case separately to stay within PHP's default memory limit.
    $chunk = str_repeat('x', ($case === 'member' ? 33 : 23) * 1024 * 1024);
    $parts = match ($case) {
        'member' => ['a' => $chunk],
        'total' => ['a' => $chunk, 'b' => $chunk, 'c' => $chunk],
        'count' => array_fill_keys(array_map(fn($i) => "part-$i", range(1, 2049)), 'x'),
    };
    $path = workbook_file($parts);
    try {
        $rejected = false;
        try { $archive = new AVBK_Xlsx_Archive($path); $archive->close(); }
        catch (AVBK_Import_Exception $e) { $rejected = true; }
        xlsx_check($rejected, 'Oversized member, aggregate expansion, or entry count rejected at archive open');
    } finally { unlink($path); unset($parts, $chunk); }
}
