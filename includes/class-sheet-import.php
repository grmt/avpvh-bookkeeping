<?php
defined('ABSPATH') || exit;

/**
 * Generic "sign-ups come from a Google Form instead of this plugin's own
 * forms" importer — usable for any activity (Congres, Boek, T-shirt, Kamp,
 * ...), not just the congress that first needed it. The penningmeester and
 * whoever designs the Google Form agree on a column layout (see the config
 * shape below) once per activity; this then turns every attendee into an
 * ordinary activity participation + event fee item, exactly the same
 * generic building blocks a Boek/T-shirt/Kamp sign-up already uses
 * (AVPVH_DB::save_participation(), AVBK_DB::upsert_event_fee_item()) — one
 * fee item per person, not one combined item per form submission.
 *
 * The sign-up data itself can come from either a live Google Sheet link
 * (re-fetched on demand) or a one-off .xlsx upload (for a spreadsheet
 * someone hands the penningmeester directly, with no shareable live link) —
 * see import()'s $uploaded_file_path parameter.
 *
 * A person who doesn't match an existing member by e-mail or by an exact
 * unambiguous name is never auto-created — they come back as 'unmatched'
 * for the penningmeester to link to an existing member (incl. inactive/
 * oud-lid) or create by hand via AV-PvH Leden first, then re-run the import.
 *
 * Config array shape (see get/save_config()):
 *   'sheet_url'         => string, optional if sign-ups are imported via file upload instead
 *   'header_row'        => 1-based spreadsheet row containing the column headings
 *   'timestamp_column'  => spreadsheet column containing the form submission timestamp
 *   'match_activity_id' => optional related activity whose participants may
 *                          disambiguate a first-name-only source value
 *   'price_per_person'  => float, a flat amount charged to everyone matched (e.g. a congress ticket)
 *   'slots'             => list of up to N attendee column-groups, each:
 *       'name'   => spreadsheet column letter (e.g. "B") for the attendee's name — required for the slot to be used
 *       'email'  => column letter for their e-mail (optional)
 *       'diet'   => column letter for allergies/dietary notes (optional)
 *       'notes'  => column letter for any other free-text note (optional)
 *       'amount' => column letter for a per-row amount (optional) — when
 *                   set, THIS overrides 'price_per_person' for that
 *                   attendee, for a bill where every person owes a
 *                   different amount (e.g. a drankrekening: each camper's
 *                   own drink total) rather than one shared ticket price
 *   'header_cache'      => [letter => header text, ...] from the most recent
 *                          successful fetch/upload — lets the settings page
 *                          show real column headings even for a file-upload
 *                          source, which has no live link left to re-fetch
 *   'member_mappings'   => [source identity hash => member id, ...] for
 *                          attendees that the penningmeester linked by hand;
 *                          this makes that correction survive later refreshes
 *   'ignored_source_identities' => [source identity hash => true] for labels
 *                          such as "Totaal" and "controle" that are rows,
 *                          but not people
 * A simple one-person-per-row form (Boek, T-shirt) just uses slot 1; a
 * form that lets one submission register a whole group (like the congress
 * one did) fills in several slots, one per possible extra attendee.
 */
class AVBK_Sheet_Import {

    const MAX_SLOTS = 5;

    const DEFAULT_CONFIG = [
        'sheet_url'        => '',
        'header_row'       => 1,
        'last_data_row'    => 0,
        'timestamp_column' => '',
        'match_activity_id' => 0,
        'price_per_person' => 0.0,
        'slots'            => [],
        'header_cache'     => [],
        'member_mappings'  => [],
        'ignored_source_identities' => [],
    ];

    public static function get_config(int $activity_id): array {
        $config = get_option("avbk_sheet_import_{$activity_id}", []);
        return is_array($config) ? $config + self::DEFAULT_CONFIG : self::DEFAULT_CONFIG;
    }

    public static function save_config(int $activity_id, array $config): void {
        update_option("avbk_sheet_import_{$activity_id}", $config);
    }

    /** Per-user, per-activity review queue; survives individual manual links. */
    public static function result_transient_key(int $activity_id): string {
        return 'avbk_sheet_import_result_' . get_current_user_id() . '_' . max(0, $activity_id);
    }

    /**
     * @param string|null $uploaded_file_path Path to a just-uploaded .xlsx
     *     (e.g. $_FILES[...]['tmp_name']) to import from instead of the
     *     configured Google Sheet link — for a source that hands the
     *     penningmeester a spreadsheet export by hand rather than a live,
     *     shareable sheet. Never persisted: read once and discarded, same as
     *     the bank-export upload.
     * @return array{matched: array, unmatched: array, errors: string[]}
     * 'matched' entries: {name, email, member_id, member_name}.
     * 'unmatched' entries: {name, email, allergies, notes}.
     */
    public static function import(int $activity_id, ?string $uploaded_file_path = null): array {
        $config = self::get_config($activity_id);
        $sheet_url = trim((string) $config['sheet_url']);
        $header_row = max(1, (int) $config['header_row']);
        $last_data_row = max(0, (int) ($config['last_data_row'] ?? 0));
        $price = (float) $config['price_per_person'];
        $matching_activity_ids = array_values(array_unique(array_filter([
            $activity_id,
            (int) ($config['match_activity_id'] ?? 0),
        ])));
        $slots = array_values(array_filter(
            (array) $config['slots'],
            fn($s) => !empty($s['name']) || !empty($s['email'])
        ));

        if (!$uploaded_file_path && $sheet_url === '') {
            return ['matched' => [], 'unmatched' => [], 'errors' => ['Geen sheet-link of Excel-bestand ingesteld voor deze activiteit.']];
        }
        if (!$slots) {
            return ['matched' => [], 'unmatched' => [], 'errors' => ['Nog geen kolommen ingesteld voor deze activiteit.']];
        }

        if ($uploaded_file_path) {
            try {
                $sheet = AVBK_Xlsx_Reader::read($uploaded_file_path, $header_row);
            } catch (\RuntimeException $e) {
                return ['matched' => [], 'unmatched' => [], 'errors' => [$e->getMessage()]];
            }
            $header_cells = $sheet['header_cells'];
            $data_rows = [];
            foreach ($sheet['rows'] as $sheet_row) {
                if ($last_data_row && (int) ($sheet_row['__row_number'] ?? 0) > $last_data_row) break;
                $cells = $sheet_row['__raw_cells'] ?? [];
                if (self::is_total_row($cells)) {
                    break;
                }
                $data_rows[] = $cells;
            }
            $raw_preview_rows = $sheet['preview_rows'] ?? [];
        } else {
            $fetched = self::fetch_csv_lines($sheet_url);
            if (isset($fetched['error'])) {
                return ['matched' => [], 'unmatched' => [], 'errors' => [$fetched['error']]];
            }
            $lines = $fetched['lines'];
            $header_index = $header_row - 1;
            if (!isset($lines[$header_index])) {
                return ['matched' => [], 'unmatched' => [], 'errors' => ["Kopregel {$header_row} is niet gevonden in de Google Sheet."]];
            }
            $header_cells = str_getcsv($lines[$header_index], ',');
            $data_lines = array_values(array_filter(array_slice($lines, $header_index + 1), fn($line) => trim($line) !== ''));
            $data_rows = [];
            foreach ($data_lines as $line_index => $line) {
                if ($last_data_row && ($header_row + 1 + $line_index) > $last_data_row) break;
                $cells = str_getcsv($line, ',');
                if (self::is_total_row($cells)) {
                    break;
                }
                $data_rows[] = $cells;
            }
            $raw_preview_rows = self::first_csv_rows($lines);
        }

        // Cache the header row (letter => text) on the config regardless of
        // source, so the settings page can offer a real-column-name dropdown
        // even for a file-upload source, where (unlike a Google Sheet link)
        // there's nothing left to re-fetch from between uploads.
        $header_map = [];
        foreach ($header_cells as $i => $text) {
            $header_map[self::index_to_letter($i)] = trim((string) $text);
        }
        if ($header_map) {
            $config['header_cache'] = $header_map;
            if (empty($config['timestamp_column'])) {
                foreach ($header_map as $letter => $heading) {
                    $normalized_heading = strtolower(remove_accents(trim($heading)));
                    if (in_array($normalized_heading, ['timestamp', 'tijdstempel'], true)) {
                        $config['timestamp_column'] = $letter;
                        break;
                    }
                }
            }
            self::save_config($activity_id, $config);
        }
        $preview = self::build_preview($header_cells, $data_rows);
        $preview['raw_rows'] = $raw_preview_rows;

        $matched = [];
        $unmatched = [];
        $description = self::fee_description($activity_id);

        foreach ($data_rows as $cells) {
            $source_timestamp = self::cell($cells, (string) $config['timestamp_column']);
            foreach (self::attendees_in_row($cells, $slots, $source_timestamp) as $attendee) {
                if (self::is_ignored_source_identity($config, $attendee['name'], $attendee['email'])) {
                    continue;
                }
                $member = self::find_match($attendee['name'], $attendee['email'], $matching_activity_ids);
                if (!$member) {
                    $member = self::find_saved_match($config, $attendee['name'], $attendee['email']);
                }
                if (!$member) {
                    $attendee['suggestions'] = self::fuzzy_member_suggestions($attendee['name'], $matching_activity_ids);
                    $unmatched[] = $attendee;
                    continue;
                }
                AVPVH_DB::save_participation((int) $member->id, $activity_id, [
                    'nights'  => null,
                    'nawacht' => false,
                    'diet'    => $attendee['allergies'],
                    'notes'   => $attendee['notes'],
                ]);
                AVBK_DB::save_sheet_participation_meta(
                    $activity_id,
                    (int) $member->id,
                    $attendee['registered_at'],
                    $attendee['source_timestamp']
                );
                $amount = $attendee['amount'] > 0 ? $attendee['amount'] : $price;
                if ($amount > 0) {
                    AVBK_DB::upsert_event_fee_item((int) $member->id, $description, $amount, $activity_id);
                }
                $matched[] = [
                    'name'        => $attendee['name'],
                    'email'       => $attendee['email'],
                    'member_id'   => (int) $member->id,
                    'member_name' => avpvh_format_name($member, 'list'),
                ];
            }
        }

        return ['matched' => $matched, 'unmatched' => $unmatched, 'errors' => [], 'preview' => $preview];
    }

    /**
     * Remembers a manual correction for this exact source identity. The map
     * belongs to the activity because two unrelated Forms may legitimately
     * contain the same incomplete name or shared e-mail address.
     */
    public static function remember_match(int $activity_id, string $name, string $email, int $member_id): void {
        if ($activity_id <= 0 || $member_id <= 0 || !AVPVH_DB::get_member($member_id)) {
            return;
        }
        $config = self::get_config($activity_id);
        $config['member_mappings'][self::source_identity_key($name, $email)] = $member_id;
        self::save_config($activity_id, $config);
    }

    /** Permanently suppress a non-person source label for this activity. */
    public static function ignore_source_identity(int $activity_id, string $name, string $email): void {
        if ($activity_id <= 0 || (trim($name) === '' && trim($email) === '')) {
            return;
        }
        $config = self::get_config($activity_id);
        $config['ignored_source_identities'][self::source_identity_key($name, $email)] = true;
        self::save_config($activity_id, $config);
    }

    private static function is_ignored_source_identity(array $config, string $name, string $email): bool {
        return !empty(($config['ignored_source_identities'] ?? [])[self::source_identity_key($name, $email)]);
    }

    /**
     * The sheet's header row as [column_letter => header_text, ...] — lets
     * the config UI offer a dropdown of the sheet's actual column headings
     * instead of asking someone to type a spreadsheet column letter blind.
     * Empty array (no error) if the URL isn't set yet or the sheet is empty.
     */
    public static function fetch_headers(string $sheet_url, int $header_row = 1): array {
        $preview = self::fetch_preview($sheet_url, $header_row);
        return ['headers' => $preview['headers'], 'error' => $preview['error']];
    }

    /** Selected heading row plus the first three following data rows. */
    public static function fetch_preview(string $sheet_url, int $header_row = 1): array {
        $sheet_url = trim($sheet_url);
        if ($sheet_url === '') {
            return ['headers' => [], 'rows' => [], 'raw_rows' => [], 'error' => null];
        }
        $fetched = self::fetch_csv_lines($sheet_url);
        if (isset($fetched['error'])) {
            return ['headers' => [], 'rows' => [], 'raw_rows' => [], 'error' => $fetched['error']];
        }
        $header_row = max(1, $header_row);
        $header_index = $header_row - 1;
        if (!isset($fetched['lines'][$header_index])) {
            return [
                'headers' => [],
                'rows' => [],
                'raw_rows' => self::first_csv_rows($fetched['lines']),
                'error' => "Kopregel {$header_row} is niet gevonden in de Google Sheet.",
            ];
        }
        $cells = str_getcsv($fetched['lines'][$header_index], ',');
        $data_lines = array_values(array_filter(
            array_slice($fetched['lines'], $header_index + 1),
            fn($line) => trim($line) !== ''
        ));
        $data_rows = array_map(fn($line) => str_getcsv($line, ','), array_slice($data_lines, 0, 3));
        return self::build_preview($cells, $data_rows) + [
            'raw_rows' => self::first_csv_rows($fetched['lines']),
            'error' => null,
        ];
    }

    /** Literal first three non-empty CSV rows, including their sheet row number. */
    private static function first_csv_rows(array $lines): array {
        $rows = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (count($rows) < 3) {
                $rows[] = ['row_number' => $index + 1, 'cells' => str_getcsv($line, ',')];
            }
        }
        foreach ($lines as $index => $line) {
            if (!self::is_total_row(str_getcsv($line, ','))) continue;
            foreach ([$index - 1, $index, $index + 1] as $context_index) {
                if (isset($lines[$context_index]) && trim($lines[$context_index]) !== '') {
                    $rows[] = ['row_number' => $context_index + 1, 'cells' => str_getcsv($lines[$context_index], ',')];
                }
            }
            break;
        }
        $unique = [];
        foreach ($rows as $row) $unique[(int) $row['row_number']] = $row;
        ksort($unique, SORT_NUMERIC);
        return array_values($unique);
    }

    private static function is_total_row(array $cells): bool {
        foreach ($cells as $value) {
            $text = strtolower(remove_accents(trim((string) $value)));
            if ($text === 'totaal' || $text === 'total' || str_starts_with($text, 'totaal ') || str_starts_with($text, 'total ')) return true;
        }
        return false;
    }

    private static function build_preview(array $header_cells, array $data_rows): array {
        $headers = [];
        foreach ($header_cells as $i => $text) {
            $headers[self::index_to_letter($i)] = trim((string) $text);
        }
        return [
            'headers' => $headers,
            'rows'    => array_map(
                fn($row) => array_map(fn($cell) => trim((string) $cell), array_values($row)),
                array_slice($data_rows, 0, 3)
            ),
        ];
    }

    /** @return array{lines: string[]}|array{error: string} */
    private static function fetch_csv_lines(string $sheet_url): array {
        $csv_url = self::to_csv_export_url($sheet_url);
        $response = wp_remote_get($csv_url, ['timeout' => 20]);
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['error' => "Kon de sheet niet ophalen (HTTP {$code}) — staat 'ie op \"Anyone with the link can view\"?"];
        }
        // Keep empty rows in the middle: header_row refers to the visible,
        // 1-based spreadsheet row, not "the Nth non-empty line". Only trim
        // trailing empty export lines.
        $lines = preg_split('/\r\n|\r|\n/', wp_remote_retrieve_body($response));
        while ($lines && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        $lines = array_values($lines);
        return ['lines' => $lines];
    }

    private static function fee_description(int $activity_id): string {
        $activity = AVPVH_DB::get_activity($activity_id);
        return $activity ? $activity->name : 'Activiteit';
    }

    /** One row can register more than one person; either name or e-mail identifies a populated slot. */
    private static function attendees_in_row(array $cells, array $slots, string $source_timestamp = ''): array {
        $attendees = [];
        $registered_at = self::parse_sheet_timestamp($source_timestamp);
        foreach ($slots as $slot) {
            $name = self::cell($cells, $slot['name'] ?? '');
            $email = self::cell($cells, $slot['email'] ?? '');
            if ($name === '' && $email === '') {
                continue;
            }
            $attendees[] = [
                'name'      => $name,
                'email'     => $email,
                'allergies' => self::cell($cells, $slot['diet'] ?? ''),
                'notes'     => self::cell($cells, $slot['notes'] ?? ''),
                'amount'    => AVBK_Matcher::parse_amount(self::cell($cells, $slot['amount'] ?? '')),
                'registered_at'   => $registered_at,
                'source_timestamp' => $source_timestamp,
            ];
        }
        return $attendees;
    }

    /**
     * Conservative review aid for a source row without an authoritative
     * e-mail match. Returns ranked active-member candidates but never makes
     * the match itself; the treasurer must confirm one in the dropdown.
     */
    private static function fuzzy_member_suggestions(string $name, array $activity_ids = [], int $limit = 8): array {
        $needle = self::normalize_match_text($name);
        if ($needle === '') {
            return [];
        }
        $needle_tokens = array_values(array_filter(explode(' ', $needle)));
        $suggestions = [];
        $participant_ids = self::participant_ids_for_activities($activity_ids);
        foreach (AVPVH_DB::get_members(['status' => 'active']) as $member) {
            $candidate = self::normalize_match_text(avpvh_format_name($member));
            $candidate_tokens = array_values(array_filter(explode(' ', $candidate)));
            $matched = 0;
            foreach ($needle_tokens as $token) {
                if (in_array($token, $candidate_tokens, true)) {
                    $matched++;
                }
            }
            $token_score = $needle_tokens ? ($matched / count($needle_tokens)) * 100 : 0;
            similar_text($needle, $candidate, $character_score);
            $score = (int) round(max($token_score, $character_score));
            // An existing registration is strong supporting evidence, but
            // never enough to turn an unrelated name into a suggestion.
            if ($score >= 60 && isset($participant_ids[(int) $member->id])) {
                $score = min(100, $score + 10);
            }
            if ($score >= 60) {
                $suggestions[] = [
                    'member' => $member,
                    'score' => $score,
                    'registered' => isset($participant_ids[(int) $member->id]),
                ];
            }
        }
        usort($suggestions, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($suggestions, 0, $limit);
    }

    private static function normalize_match_text(string $value): string {
        $value = strtolower(remove_accents($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private static function cell(array $cells, string $column_letter): string {
        if ($column_letter === '') {
            return '';
        }
        $index = self::letter_to_index($column_letter);
        return trim((string) ($cells[$index] ?? ''));
    }

    /** Normalize common Google Forms/Excel timestamp formats to MySQL local time. */
    private static function parse_sheet_timestamp(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $timezone = wp_timezone();
        $formats = [
            'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i',
            'm/d/Y H:i:s', 'm/d/Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i',
        ];
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $raw, $timezone);
            if ($date instanceof \DateTimeImmutable) {
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                    return $date->format('Y-m-d H:i:s');
                }
            }
        }
        try {
            return (new \DateTimeImmutable($raw, $timezone))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    /** "B" -> 1, "AA" -> 26 (0-based, same convention as AVBK_Xlsx_Reader::column_index()). */
    public static function letter_to_index(string $letters): int {
        $letters = strtoupper(trim($letters));
        $index = 0;
        foreach (str_split($letters) as $char) {
            if ($char < 'A' || $char > 'Z') {
                continue;
            }
            $index = $index * 26 + (ord($char) - ord('A') + 1);
        }
        return $index - 1;
    }

    /** 0 -> "A", 26 -> "AA" — inverse of letter_to_index(). */
    public static function index_to_letter(int $index): string {
        $letters = '';
        $index++;
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(ord('A') + $remainder) . $letters;
            $index = intdiv($index - 1, 26);
        }
        return $letters;
    }

    /**
     * An exact e-mail match is authoritative, even when the free-text name
     * contains a typo. If no member has that e-mail, fall back to exact,
     * unambiguous first+last-name matching and finally manual review.
     */
    private static function find_match(string $name, string $email, array $activity_ids = []): ?object {
        [$first_name, $last_name] = self::split_name($name);

        if ($email !== '') {
            $by_email = AVPVH_DB::get_member_by_email($email);
            if (!$by_email && method_exists('AVPVH_DB', 'get_identity_by_email')) {
                $identity = AVPVH_DB::get_identity_by_email($email);
                $by_email = $identity ? AVPVH_DB::get_member((int) $identity->member_id) : null;
            }
            if ($by_email) {
                return $by_email;
            }
        }
        $matches = AVPVH_DB::find_members_by_name($first_name, $last_name);
        if (count($matches) === 1) {
            return AVPVH_DB::get_member((int) $matches[0]->id);
        }

        // Forms often ask only for a roepnaam. An exact first name may be
        // linked automatically when it identifies one active member. Old,
        // inactive duplicate records do not make that ambiguous. If there
        // are several active namesakes, one existing participation for this
        // activity is sufficient additional evidence; otherwise review is
        // still required.
        $normalized_name = self::normalize_match_text($name);
        if ($normalized_name !== '' && strpos($normalized_name, ' ') === false) {
            $first_name_matches = array_values(array_filter(
                AVPVH_DB::get_members(['status' => 'active']),
                fn($member) => self::normalize_match_text((string) $member->first_name) === $normalized_name
            ));
            if (count($first_name_matches) === 1) {
                return $first_name_matches[0];
            }
            if ($activity_ids && count($first_name_matches) > 1) {
                $participant_ids = self::participant_ids_for_activities($activity_ids);
                $registered_matches = array_values(array_filter(
                    $first_name_matches,
                    fn($member) => isset($participant_ids[(int) $member->id])
                ));
                if (count($registered_matches) === 1) {
                    return $registered_matches[0];
                }
            }
        }
        return null;
    }

    /** Participants in the current and any explicitly related matching activity. */
    private static function participant_ids_for_activities(array $activity_ids): array {
        $participant_ids = [];
        foreach (array_unique(array_filter(array_map('intval', $activity_ids))) as $activity_id) {
            foreach (AVPVH_DB::get_participation_for_activity($activity_id) as $participation) {
                $participant_ids[(int) $participation->member_id] = true;
            }
        }
        return $participant_ids;
    }

    /** Returns a still-existing member from an earlier manual correction. */
    private static function find_saved_match(array $config, string $name, string $email): ?object {
        $member_id = (int) (($config['member_mappings'] ?? [])[self::source_identity_key($name, $email)] ?? 0);
        return $member_id > 0 ? AVPVH_DB::get_member($member_id) : null;
    }

    /** Stable across harmless case and whitespace changes in the Sheet. */
    private static function source_identity_key(string $name, string $email): string {
        $normalize = static function (string $value): string {
            $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
            return strtolower(remove_accents($value));
        };
        return hash('sha256', $normalize($name) . "\0" . $normalize($email));
    }

    /**
     * A free-text "Naam" field — best-effort split. A Dutch surname prefix
     * ("tussenvoegsel": van, de, van der, ...) is stripped off the
     * first-name remainder rather than left attached, since avm_members
     * keeps those in a separate `suffix` column with a bare last_name —
     * find_members_by_name() only compares first_name/last_name, so
     * "Anna van Berg" must split into ("Anna", "Berg"), not
     * ("Anna van", "Berg"), to ever match the real member.
     */
    private static function split_name(string $full_name): array {
        $full_name = trim($full_name);
        $prefixes = ['van der', 'van den', 'van de', 'de', 'den', 'der', 'van', 'ter', 'ten', 'te'];
        $prefix_pattern = implode('|', array_map(fn($p) => preg_quote($p, '/'), $prefixes));
        if (preg_match('/^(.+?)\s+(?:' . $prefix_pattern . ')\s+(\S+)$/iu', $full_name, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        $parts = preg_split('/\s+/', $full_name);
        if (count($parts) < 2) {
            return [$full_name, ''];
        }
        $last = array_pop($parts);
        return [implode(' ', $parts), $last];
    }

    private static function to_csv_export_url(string $url): string {
        if (!preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            return $url;
        }
        $gid = '';
        if (preg_match('/[?#&]gid=(\d+)/', $url, $gm)) {
            $gid = $gm[1];
        }
        return "https://docs.google.com/spreadsheets/d/{$m[1]}/export?format=csv" . ($gid !== '' ? "&gid={$gid}" : '');
    }
}
