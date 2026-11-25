<?php
/** Reject entity declarations and oversized ZIP contents before parsing XLSX parts. */
define('ABSPATH', __DIR__ . '/');
function remove_accents(string $text): string { return $text; }
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
// read() parses the first worksheet and the shared strings through the same guard.
$ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
$sheet = '<worksheet ' . $ns . '><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
    . '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>12.5</v></c></row></sheetData></worksheet>';
$strings = '<sst ' . $ns . '><si><t>Naam</t></si><si><r><t>Bed</t></r><r><t>rag</t></r></si><si><t>Anna</t></si></sst>';
$path = workbook_file(['xl/worksheets/sheet1.xml' => $sheet, 'xl/sharedStrings.xml' => $strings]);
try {
    $read = AVBK_Xlsx_Reader::read($path);
    xlsx_check($read['headers'] === ['Naam', 'Bedrag'] && $read['rows'][0]['Naam'] === 'Anna' && $read['rows'][0]['Bedrag'] === '12.5', 'read() resolves shared strings, including rich text');
} finally { unlink($path); }
$doctype = '<!DOCTYPE x [<!ENTITY e "x">]>';
foreach (['xl/worksheets/sheet1.xml' => 'worksheet', 'xl/sharedStrings.xml' => 'shared strings'] as $part => $label) {
    $parts = ['xl/worksheets/sheet1.xml' => $sheet, 'xl/sharedStrings.xml' => $strings];
    $parts[$part] = $doctype . $parts[$part];
    $path = workbook_file($parts);
    try {
        $rejected = false;
        try { AVBK_Xlsx_Reader::read($path); } catch (AVBK_Import_Exception $e) { $rejected = true; }
        xlsx_check($rejected, "read() rejects a document type in the $label");
    } finally { unlink($path); }
}
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
