<?php
defined('ABSPATH') || exit;

/**
 * Fixed-layout importer for the club's camp overview workbook.
 *
 * A camp sheet is not a configurable Google-Forms-style table: it contains
 * month/day headings, summary rows and formulas. Only activities whose type
 * is exactly "Kamp" may use this parser.
 */
class AVBK_Camp_Sheet_Import {

    private const SHEET_NAME = 'totaal inschrijvingen';
    private const MONTH_ROW = 3;
    private const DAY_ROW = 5;
    private const FIRST_PERSON_ROW = 17;
    private const LAST_PERSON_ROW = 250;
    private const NAME_COLUMN = 3;       // D, zero-based
    private const FIRST_DAY_COLUMN = 4;  // E
    private const LAST_DAY_COLUMN = 19;  // T
    private const NAWACHT_COLUMN = 22;   // W
    private const NOTES_COLUMNS = [23, 24]; // X, Y
    private const DIET_COLUMN = 25;      // Z

    private const MONTHS = [
        'januari' => 1,
        'februari' => 2,
        'maart' => 3,
        'april' => 4,
        'mei' => 5,
        'juni' => 6,
        'juli' => 7,
        'augustus' => 8,
        'september' => 9,
        'oktober' => 10,
        'november' => 11,
        'december' => 12,
    ];

    /** Return the standard AVBK_Sheet_Import result shape used by the page. */
    public static function import(int $activity_id, string $path): array {
        $activity = AVPVH_DB::get_activity($activity_id);
        if (!$activity || ($activity->type_name ?? '') !== 'Kamp') {
            return self::error('De speciale kampimport is alleen beschikbaar voor een activiteit van het type Kamp.');
        }

        try {
            $parsed = self::parse($path, (int) $activity->year);
        } catch (\Throwable $error) {
            return self::error($error->getMessage());
        }
        if (!$parsed['participants']) {
            return self::error('Geen deelnemers gevonden in het kampoverzicht.');
        }

        $matched = [];
        $unmatched = [];
        $to_save = [];
        $seen_members = [];
        foreach ($parsed['participants'] as $participant) {
            if (AVBK_Sheet_Import::is_source_identity_ignored($activity_id, $participant['name'])) {
                continue;
            }
            $member = AVBK_Sheet_Import::match_source_person($activity_id, $participant['name']);
            if (!$member) {
                $unmatched[] = self::unmatched_entry($participant, $activity_id);
                continue;
            }
            $member_id = (int) $member->id;
            if (isset($seen_members[$member_id])) {
                $duplicate = self::unmatched_entry($participant, $activity_id);
                $duplicate['notes'] = trim($duplicate['notes'] . "\nDubbele regel in het kampbestand.");
                $unmatched[] = $duplicate;
                continue;
            }
            $seen_members[$member_id] = true;
            $participant['member'] = $member;
            $to_save[] = $participant;
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $date_update = $wpdb->update(
                "{$wpdb->prefix}avm_activities",
                ['start_date' => $parsed['start_date'], 'end_date' => $parsed['end_date']],
                ['id' => $activity_id],
                ['%s', '%s'],
                ['%d']
            );
            if ($date_update === false) {
                throw new \RuntimeException('De kampdatums konden niet worden opgeslagen.');
            }

            foreach ($to_save as $participant) {
                $member = $participant['member'];
                $participation_id = AVPVH_DB::save_participation(
                    (int) $member->id,
                    $activity_id,
                    [
                        'nights' => $participant['nights'],
                        'nawacht' => $participant['nawacht'],
                        'diet' => $participant['diet'],
                        'notes' => $participant['notes'],
                    ]
                );
                if (!$participation_id) {
                    throw new \RuntimeException('Een kampdeelname kon niet worden opgeslagen.');
                }
                AVPVH_DB::save_participation_days($participation_id, $participant['days']);
                $matched[] = [
                    'name' => $participant['name'],
                    'email' => '',
                    'member_id' => (int) $member->id,
                    'member_name' => avpvh_format_name($member, 'list'),
                ];
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return self::error($error->getMessage());
        }

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
            'errors' => [],
            'camp' => [
                'start_date' => $parsed['start_date'],
                'end_date' => $parsed['end_date'],
            ],
        ];
    }

    /** Parse the special grid without writing data; public for fixture tests. */
    public static function parse(string $path, int $campaign_year): array {
        $rows = AVBK_Xlsx_Reader::read_named_rows($path, self::SHEET_NAME);
        $date_columns = self::date_columns($rows, $campaign_year);
        $participants = [];

        for ($row = self::FIRST_PERSON_ROW; $row <= self::LAST_PERSON_ROW; $row++) {
            $name = sanitize_text_field(self::cell($rows, $row, self::NAME_COLUMN));
            if ($name === '') {
                continue;
            }
            $days = [];
            foreach ($date_columns as $column => $date) {
                $days[$date] = substr(sanitize_text_field(self::cell($rows, $row, $column)), 0, 10);
            }
            $nawacht_text = sanitize_text_field(self::cell($rows, $row, self::NAWACHT_COLUMN));
            $notes = [];
            foreach (self::NOTES_COLUMNS as $column) {
                $note = sanitize_textarea_field(self::cell($rows, $row, $column));
                if ($note !== '') {
                    $notes[] = $note;
                }
            }
            $diet = sanitize_textarea_field(self::cell($rows, $row, self::DIET_COLUMN));

            // Summary and age-category rows also have text in D, but no
            // registration data in the camp input cells.
            if (!array_filter($days, fn($status) => $status !== '')
                && $nawacht_text === '' && !$notes && $diet === '') {
                continue;
            }
            $participants[] = [
                'name' => $name,
                'days' => $days,
                'nights' => count(array_filter($days, fn($status) => strtolower($status) === 'n')),
                'nawacht' => !in_array(strtolower($nawacht_text), ['', '0', 'nee', 'no', 'false', 'n.v.t.'], true),
                'notes' => implode("\n", $notes),
                'diet' => $diet,
            ];
        }

        $dates = array_values($date_columns);
        sort($dates, SORT_STRING);
        return [
            'participants' => $participants,
            'start_date' => reset($dates),
            'end_date' => end($dates),
        ];
    }

    private static function unmatched_entry(array $participant, int $activity_id): array {
        return [
            'name' => $participant['name'],
            'email' => '',
            'allergies' => $participant['diet'],
            'notes' => $participant['notes'],
            'amount' => 0.0,
            'registered_at' => null,
            'source_timestamp' => '',
            'suggestions' => AVBK_Sheet_Import::source_person_suggestions($activity_id, $participant['name']),
            'camp_days' => $participant['days'],
            'camp_nights' => $participant['nights'],
            'camp_nawacht' => $participant['nawacht'],
        ];
    }

    private static function date_columns(array $rows, int $campaign_year): array {
        $columns = [];
        $month = null;
        $year = $campaign_year;
        $previous_month = null;
        for ($column = self::FIRST_DAY_COLUMN; $column <= self::LAST_DAY_COLUMN; $column++) {
            $month_label = strtolower(self::cell($rows, self::MONTH_ROW, $column));
            if ($month_label !== '') {
                $month = self::MONTHS[$month_label] ?? null;
                if ($month === null) {
                    throw new \RuntimeException('Onbekende maand in het kampoverzicht: ' . $month_label . '.');
                }
                if ($previous_month !== null && $month < $previous_month) {
                    $year++;
                }
                $previous_month = $month;
            }
            $day_text = self::cell($rows, self::DAY_ROW, $column);
            if ($month === null || $day_text === '') {
                continue;
            }
            $day = filter_var($day_text, FILTER_VALIDATE_INT);
            if ($day === false || !checkdate($month, $day, $year)) {
                throw new \RuntimeException('Ongeldige dag in rij 5 van het kampoverzicht.');
            }
            $columns[$column] = sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        if (!$columns) {
            throw new \RuntimeException('Geen kampdatums gevonden in rijen 3 en 5, kolommen E-T.');
        }
        return $columns;
    }

    private static function cell(array $rows, int $row, int $column): string {
        $value = trim((string) ($rows[$row][$column] ?? ''));
        if (is_numeric($value) && (float) $value === floor((float) $value)) {
            return (string) (int) $value;
        }
        return $value;
    }

    private static function error(string $message): array {
        return ['matched' => [], 'unmatched' => [], 'errors' => [$message]];
    }
}
