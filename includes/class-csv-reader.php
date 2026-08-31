<?php
defined('ABSPATH') || exit;

/**
 * ING's own CSV export ("Alle transacties" as .csv instead of .xlsx) —
 * semicolon-separated, with the exact same column headers as the xlsx
 * export ("Datum", "Naam / Omschrijving", "Tegenrekening", "Bedrag (EUR)",
 * "Af Bij", "Mededelingen", ...). Returns the same
 * array{headers, rows} shape as AVBK_Xlsx_Reader::read() — including a
 * '__row_number' key per row (here: the line number in the file) — so
 * AVBK_Matcher::parse_row() and AVBK_Import::process_file() need no
 * changes to accept either format.
 */
class AVBK_Csv_Reader {

    public static function read(string $path, string $delimiter_setting = 'auto'): array {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Kan het csv-bestand niet openen.');
        }

        // ING's CSV export is typically Windows-1252, not UTF-8 — without
        // this, accented names (é, ë, ...) turn into mojibake.
        if (!mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents); // strip a UTF-8 BOM, if present

        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $contents),
            fn($l) => trim($l) !== ''
        ));
        if (!$lines) {
            return ['headers' => [], 'rows' => []];
        }

        $header_line = array_shift($lines);
        $delimiter_map = ['semicolon' => ';', 'comma' => ',', 'tab' => "\t"];
        $delimiter = $delimiter_map[$delimiter_setting] ?? self::detect_delimiter($header_line);
        $headers = array_map(fn($h) => trim($h, " \t\"'"), str_getcsv($header_line, $delimiter));

        $rows = [];
        foreach ($lines as $i => $line) {
            $cells = str_getcsv($line, $delimiter);
            $row = [];
            foreach ($headers as $index => $name) {
                if ($name === '') {
                    continue;
                }
                $row[$name] = trim((string) ($cells[$index] ?? ''), " \t\"'");
            }
            // Skip fully blank rows (a trailing empty line is common at the end of an export).
            if (implode('', $row) !== '') {
                $row['__row_number'] = $i + 2; // +1 for the header line, +1 to make it 1-based
                $rows[] = $row;
            }
        }

        return ['headers' => array_values(array_filter($headers, fn($h) => $h !== '')), 'rows' => $rows];
    }

    private static function detect_delimiter(string $header_line): string {
        $scores = [];
        foreach ([';' => ';', ',' => ',', "\t" => "\t"] as $key => $delimiter) {
            $scores[$key] = count(str_getcsv($header_line, $delimiter));
        }
        arsort($scores);
        $best = (string) array_key_first($scores);
        return $best === "\t" ? "\t" : $best;
    }
}
