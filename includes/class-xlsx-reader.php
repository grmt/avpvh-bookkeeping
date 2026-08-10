<?php
defined('ABSPATH') || exit;

/**
 * Minimal, dependency-free .xlsx reader — mirrors the "no need for
 * PhpSpreadsheet" philosophy of avpvh-members' AVPVH_Xlsx_Writer, but for
 * reading a known, fixed format (a bank's transaction export) rather than
 * writing an arbitrary spreadsheet. Reads the first worksheet via
 * PHP's built-in ZipArchive + SimpleXML: shared strings + sparse cell
 * references (banks omit empty trailing cells, so cells are addressed by
 * their own "r" attribute, e.g. "K5", not by position in the row).
 */
class AVBK_Xlsx_Reader {

    // OOXML spreadsheet documents declare this as the *default* (unprefixed)
    // namespace. SimpleXML property access (->foo) resolves that
    // transparently, but xpath() does not — an unprefixed node test in
    // XPath always means "no namespace", so `xpath('.//t')` silently
    // matches nothing here. children($ns) is what actually works.
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @return array{headers: string[], rows: array<int, array<string, string>>}
     */
    public static function read(string $path): array {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZipArchive-extensie is niet beschikbaar.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Kan het xlsx-bestand niet openen.');
        }

        $shared = self::read_shared_strings($zip);
        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet_xml === false) {
            // Fall back to whichever worksheet part actually exists.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== null && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    $sheet_xml = $zip->getFromName($name);
                    break;
                }
            }
        }
        $zip->close();

        if ($sheet_xml === false || $sheet_xml === null) {
            throw new \RuntimeException('Geen werkblad gevonden in het xlsx-bestand.');
        }

        $sheet = simplexml_load_string($sheet_xml);
        if ($sheet === false) {
            throw new \RuntimeException('Kan het werkblad niet lezen (ongeldige XML).');
        }

        $sheet_rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col_index = self::column_index($ref);
                $cells[$col_index] = self::cell_value($c, $shared);
            }
            if ($cells) {
                $sheet_rows[] = $cells;
            }
        }

        if (!$sheet_rows) {
            return ['headers' => [], 'rows' => []];
        }

        $header_row = array_shift($sheet_rows);
        $max_col = max(array_keys($header_row));
        $headers = [];
        for ($i = 0; $i <= $max_col; $i++) {
            $headers[$i] = trim((string) ($header_row[$i] ?? ''));
        }

        $rows = [];
        foreach ($sheet_rows as $cells) {
            $row = [];
            foreach ($headers as $index => $name) {
                if ($name === '') {
                    continue;
                }
                $row[$name] = (string) ($cells[$index] ?? '');
            }
            // Skip fully blank rows (a trailing empty row is common at the end of an export).
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }

        return ['headers' => array_values(array_filter($headers, fn($h) => $h !== '')), 'rows' => $rows];
    }

    private static function read_shared_strings(\ZipArchive $zip): array {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $sst = simplexml_load_string($xml);
        if ($sst === false) {
            return [];
        }
        $strings = [];
        foreach ($sst->si as $si) {
            $strings[] = self::rich_text($si);
        }
        return $strings;
    }

    /** A shared/inline string element is either a single <t> or several <r><t> runs (rich text). */
    private static function rich_text(\SimpleXMLElement $node): string {
        $children = $node->children(self::NS);
        if (isset($children->t)) {
            return (string) $children->t;
        }
        $text = '';
        foreach ($children->r ?? [] as $run) {
            $run_children = $run->children(self::NS);
            $text .= (string) ($run_children->t ?? '');
        }
        return $text;
    }

    private static function cell_value(\SimpleXMLElement $c, array $shared): string {
        $type = (string) $c['t'];
        $children = $c->children(self::NS);
        if ($type === 's') {
            $index = (int) $children->v;
            return $shared[$index] ?? '';
        }
        if ($type === 'inlineStr') {
            return isset($children->is) ? self::rich_text($children->is) : '';
        }
        return (string) $children->v;
    }

    /** "K5" -> 10 (zero-based column index; A=0, Z=25, AA=26, ...). */
    private static function column_index(string $ref): int {
        preg_match('/^([A-Z]+)/', $ref, $m);
        $letters = $m[1] ?? 'A';
        $index = 0;
        foreach (str_split($letters) as $char) {
            $index = $index * 26 + (ord($char) - ord('A') + 1);
        }
        return $index - 1;
    }
}
