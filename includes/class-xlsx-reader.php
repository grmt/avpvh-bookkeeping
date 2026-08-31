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
     * @return array{headers: string[], rows: array<int, array<string, string>>, header_cells: array<int, string>, preview_rows: array<int, array{row_number:int,cells:array}>}
     */
    public static function read(string $path, ?int $header_row_number = null): array {
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
                // Excel's own 1-based row number (from the <row r="..">
                // attribute, not a recount) — kept alongside each row so a
                // treasurer can find the exact line in the original
                // spreadsheet when double-checking an import (see
                // 'source_row' in AVBK_Matcher::parse_row()).
                $sheet_rows[] = ['row_number' => (int) $row['r'], 'cells' => $cells];
            }
        }

        if (!$sheet_rows) {
            return ['headers' => [], 'rows' => [], 'header_cells' => [], 'preview_rows' => []];
        }

        // Keep the literal first three non-empty worksheet rows available to
        // the configuration screen. This is captured before selecting the
        // configured heading row, so title/instruction rows remain visible.
        $preview_rows = self::preview_rows_with_total($sheet_rows);

        if ($header_row_number !== null) {
            $header_row_number = max(1, $header_row_number);
            $header_index = array_search($header_row_number, array_column($sheet_rows, 'row_number'), true);
            if ($header_index === false) {
                throw new \RuntimeException("Kopregel {$header_row_number} is niet gevonden in het xlsx-bestand.");
            }
            $header_entry = $sheet_rows[$header_index];
            // Title/instruction rows before the configured headings are not
            // registration data. Preserve Excel's real row numbers for all
            // rows after the selected header.
            $sheet_rows = array_slice($sheet_rows, $header_index + 1);
        } else {
            // Existing callers (notably configurable bank imports) retain
            // the old behaviour: first non-empty spreadsheet row is header.
            $header_entry = array_shift($sheet_rows);
        }
        $header_row = $header_entry['cells'];
        $max_col = max(array_keys($header_row));
        $headers = [];
        for ($i = 0; $i <= $max_col; $i++) {
            $headers[$i] = trim((string) ($header_row[$i] ?? ''));
        }

        $rows = [];
        foreach ($sheet_rows as $entry) {
            $cells = $entry['cells'];
            if (self::is_total_row($cells)) {
                break;
            }
            $row = [];
            foreach ($headers as $index => $name) {
                if ($name === '') {
                    continue;
                }
                $row[$name] = (string) ($cells[$index] ?? '');
            }
            // Skip fully blank rows (a trailing empty row is common at the end of an export).
            if (implode('', $row) !== '') {
                $row['__row_number'] = $entry['row_number'];
                // Raw, position-indexed cells (0=A, 1=B, ...) alongside the
                // by-header-name row above — used by AVBK_Sheet_Import, which
                // addresses columns by spreadsheet letter rather than by
                // header text (a Google Form response sheet repeats the same
                // header per attendee slot, so a name lookup is ambiguous).
                $row['__raw_cells'] = $cells;
                $rows[] = $row;
            }
        }

        return [
            'headers' => array_values(array_filter($headers, fn($h) => $h !== '')),
            'rows' => $rows,
            // Header row, position-indexed (0=A, 1=B, ...), blanks kept — the
            // counterpart to '__raw_cells' above.
            'header_cells' => $headers,
            'preview_rows' => $preview_rows,
        ];
    }

    /** Show the first rows plus the context around a Totaal/Total row. */
    private static function preview_rows_with_total(array $rows): array {
        $keep = array_slice($rows, 0, 3);
        foreach ($rows as $i => $entry) {
            if (!self::is_total_row($entry['cells'])) {
                continue;
            }
            foreach ([$i - 1, $i, $i + 1] as $context_index) {
                if (isset($rows[$context_index])) {
                    $keep[] = $rows[$context_index];
                }
            }
            break;
        }
        $unique = [];
        foreach ($keep as $entry) {
            $unique[(int) $entry['row_number']] = $entry;
        }
        ksort($unique, SORT_NUMERIC);
        return array_values($unique);
    }

    private static function is_total_row(array $cells): bool {
        foreach ($cells as $value) {
            $text = strtolower(remove_accents(trim((string) $value)));
            if ($text === 'totaal' || $text === 'total' || str_starts_with($text, 'totaal ') || str_starts_with($text, 'total ')) {
                return true;
            }
        }
        return false;
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
