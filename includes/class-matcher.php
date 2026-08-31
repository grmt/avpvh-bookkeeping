<?php
defined('ABSPATH') || exit;

/**
 * Turns one parsed bank-export row into a matching decision: which
 * member(s) it belongs to, and whether that's confident enough to apply
 * automatically or needs the treasurer's review.
 *
 * Real export data (ING "Alle transacties") showed why this needs more
 * than a keyword check: only ~38% of incoming rows mention "contributie"/
 * "kamp" at all, one transfer routinely pays for several members at once
 * ("Lidgeld Anna, Bram en Cas", "Kamp Anna Bram Cas" — first names only,
 * no member IDs), and connectors vary ("," / " - " / " en " /
 * "e/o" ["en/of"]). So beneficiary names are matched within the payer's
 * own household first (AVPVH_DB::get_manageable_members) before falling
 * back to a search across every active member.
 */
class AVBK_Matcher {

    const MIN_SCORE = 55;

    /** Personal one-off charges that are valid without a dated activity record. */
    public static function personal_one_off_types(): array {
        return ['Drank', 'Eten', 'Boek', 'T-shirt', 'Overig'];
    }

    public static function is_personal_one_off_type(string $type_name): bool {
        return in_array($type_name, self::personal_one_off_types(), true);
    }

    /**
     * Raw AVBK_Xlsx_Reader/AVBK_Csv_Reader row -> normalized transaction
     * fields. ING exports the same "Alle transacties" report with either
     * Dutch or English column headers depending on the account's own
     * language setting at export time — both are accepted here so an
     * import doesn't silently produce zero rows just because someone's
     * ING language preference happened to be English that day.
     */
    public static function parse_row(array $row, ?array $layout = null): ?array {
        $layout = AVBK_Bank_Import_Layout::resolve($layout);
        $date = AVBK_Bank_Import_Layout::parse_date(
            AVBK_Bank_Import_Layout::value($row, $layout, 'date'),
            $layout['date_format']
        );
        if (!$date) {
            return null;
        }
        $signed_amount = AVBK_Bank_Import_Layout::parse_amount(
            AVBK_Bank_Import_Layout::value($row, $layout, 'amount'),
            $layout['decimal_separator']
        );
        $direction = AVBK_Bank_Import_Layout::parse_direction(
            AVBK_Bank_Import_Layout::value($row, $layout, 'direction'),
            $signed_amount,
            $layout
        );

        return [
            'transaction_date'  => $date,
            'amount'            => abs($signed_amount),
            'direction'         => $direction,
            'counterparty_name' => AVBK_Bank_Import_Layout::value($row, $layout, 'name'),
            'counterparty_iban' => strtoupper(AVBK_Bank_Import_Layout::value($row, $layout, 'iban')),
            'description'       => AVBK_Bank_Import_Layout::value($row, $layout, 'description'),
            'source_row'        => isset($row['__row_number']) ? (int) $row['__row_number'] : null,
        ];
    }

    /**
     * The field labels this bank's flat "Naam: X Omschrijving: Y IBAN: Z
     * ..." Mededelingen/Notifications format always uses, in whatever
     * order they happen to appear — in either Dutch or English, since
     * (like the column headers themselves, see parse_row()) this follows
     * the account's own ING language setting at export time.
     */
    private const DESCRIPTION_LABELS = [
        'Naam:', 'Omschrijving:', 'IBAN:', 'Datum/Tijd:', 'Valutadatum:', 'Kenmerk:', 'Overige partij:', 'Mutatiesoort:',
        'Name:', 'Description:', 'Date/time:', 'Value date:', 'Reference:', 'Other party:', 'Transaction type:',
    ];
    /** Whichever language, this label's value is always the payer's own name — already shown separately, so strip_name_field() drops it. */
    private const NAME_LABELS = ['Naam:', 'Name:'];

    /**
     * Drops the "Naam: ..." segment from the bank's flat Mededelingen
     * string — it only ever repeats the payer's own name, which is already
     * shown separately as counterparty_name, so it's pure noise here.
     * Every other labelled segment (Omschrijving, IBAN, Kenmerk, ...) is
     * kept as-is. Display-only: never touches the stored description.
     */
    public static function strip_name_field(string $description): string {
        if ($description === '') {
            return $description;
        }
        $pattern = '/(' . implode('|', array_map(fn($l) => preg_quote($l, '/'), self::DESCRIPTION_LABELS)) . ')/';
        $parts = preg_split($pattern, $description, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!$parts || count($parts) < 3) {
            // Doesn't actually follow the labelled format — nothing to strip.
            return $description;
        }
        $result = trim($parts[0]);
        for ($i = 1; $i < count($parts); $i += 2) {
            $label = $parts[$i];
            $value = trim($parts[$i + 1] ?? '');
            if (in_array($label, self::NAME_LABELS, true)) {
                continue;
            }
            $result .= ($result !== '' ? ' ' : '') . $label . ' ' . $value;
        }
        return trim($result) !== '' ? trim($result) : $description;
    }

    /**
     * Bolds the field labels in the bank's flat Mededelingen string so it
     * reads as a mini key/value list instead of a wall of text. Escapes
     * first, then bolds the (already-safe, no HTML-special-character)
     * label text — never the other way round.
     */
    public static function format_description_html(string $description): string {
        $escaped = esc_html($description);
        foreach (self::DESCRIPTION_LABELS as $label) {
            $escaped = preg_replace('/(?<=^|\s)' . preg_quote($label, '/') . '/', '<strong>' . $label . '</strong>', $escaped);
        }
        return $escaped;
    }

    /** "82,83" (European decimal comma) -> 82.83 */
    /**
     * "82,83" (European, dot-as-thousands) -> 82.83, but also "75.0" or
     * "1116.56" (English-locale ING export, dot-as-decimal, no thousands
     * separator at all) -> left as-is. A comma present anywhere is the
     * signal it's the European format; otherwise a lone dot is already a
     * plain decimal point PHP's own float cast handles correctly.
     */
    public static function parse_amount(string $raw): float {
        $raw = trim(str_replace(['€', ' '], '', $raw));
        if (str_contains($raw, ',')) {
            $raw = str_replace('.', '', $raw);   // thousands separator
            $raw = str_replace(',', '.', $raw);  // decimal comma -> dot
        }
        return (float) $raw;
    }

    /**
     * A description can name more than one activity at once ("KAMP EN
     * CONTRIBUTIE 2026", "kampbijdrage + drankafrekening") — a single type
     * string would silently drop one. Returns every activity *name*
     * mentioned (e.g. ['Kamp', 'Drank'], matching the confirm form's
     * per-row Activiteit-dropdown), scanned dynamically against
     * AVPVH_DB::get_activity_types() — the same admin-editable list, so a
     * newly added activity type (e.g. "Excursie") is recognized here too
     * without a code change, not a hardcoded keyword list. Contributie
     * additionally matches a few common real-world phrasings that don't
     * literally say "contributie".
     */
    public static function classify_types(string $description): array {
        $d = mb_strtolower($description);
        $types = [];

        foreach (AVPVH_DB::get_activity_types() as $activity_type) {
            // A type by itself is only taxonomy. Suggest it for a bank
            // payment only when at least one concrete activity of that
            // type is actually registered; otherwise an unused type such
            // as "Feest" becomes a misleading one-off payment category.
            if (
                str_contains($d, mb_strtolower($activity_type->name))
                && (
                    AVBK_DB::get_current_activity_for_type_name($activity_type->name)
                    || self::is_personal_one_off_type($activity_type->name)
                )
            ) {
                $types[] = $activity_type->name;
            }
        }

        // "Drankafrekening archeoweekend" says what is being paid for
        // (Drank) and only names the weekend as context. Treating the
        // embedded word "weekend" as a second charge produced two already-
        // paid Weekend rows at €0 and hid the actual personal drink bill.
        if (preg_match('/\bdrank\s*afrekening\b/u', $d) && in_array('Drank', $types, true)) {
            return ['Drank'];
        }

        if (
            !in_array('Contributie', $types, true)
            && AVBK_DB::get_current_activity_for_type_name('Contributie')
        ) {
            foreach (['contributie', 'lidmaatschap', 'lidgeld', 'inschrijving', 'inschrijfkosten'] as $kw) {
                if (str_contains($d, $kw)) {
                    $types[] = 'Contributie';
                    break;
                }
            }
        }

        return $types;
    }

    /** Reference code (e.g. "PVH-42") in free text -> member_id, or null. */
    public static function match_reference_code(string $description): ?int {
        $prefix = preg_quote((string) get_option('avbk_reference_prefix', 'PVH'), '/');
        if (preg_match('/\b' . $prefix . '-(\d+)\b/i', $description, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /** Exact fee ids embedded by current AVBK_QR codes (legacy PVH-<member> references simply return []). */
    public static function match_fee_item_reference(string $description): array {
        $prefix = preg_quote((string) get_option('avbk_reference_prefix', 'PVH'), '/');
        if (!preg_match('/\b' . $prefix . '-\d+-F(\d+(?:\.\d+)*)\b/i', $description, $m)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', explode('.', $m[1])))));
    }

    /**
     * Candidate members for this transaction, best guess first. Each entry
     * is ['member' => object, 'score' => 0-100]. Never guesses beyond
     * MIN_SCORE — an empty array just means "nobody confident enough",
     * which is exactly what should land as fully unmatched.
     */
    public static function find_candidates(string $counterparty_name, string $description): array {
        $all_members = AVBK_DB::get_payable_members();
        $payer_matches = self::find_payer_candidates($counterparty_name, $all_members);

        // Household pool: self + household of every matched payer — the
        // pool beneficiary names ("Anna, Bram en Cas") get
        // checked against first, since that's who the description is
        // actually naming.
        $household_pool = [];
        foreach ($payer_matches as $match) {
            foreach (AVPVH_DB::get_manageable_members((int) $match['member']->id) as $hm) {
                $household_pool[$hm->id] = $hm;
            }
        }

        // Strip the fee-type word itself ("Kamp Anna Bram Cas" ->
        // "Anna Bram Cas") — left in place, it drags down every name
        // comparison and can make a real match silently fall below
        // MIN_SCORE.
        $beneficiary_text = self::strip_leading_keyword(self::extract_beneficiary_text($description));
        $beneficiary_names = $beneficiary_text ? self::split_names($beneficiary_text) : [];

        $found = [];
        foreach ($beneficiary_names as $name) {
            $pool = $household_pool ?: $all_members;
            $match = self::best_match($name, $pool);
            if ($match) {
                $found[$match['member']->id] = $match;
            }
        }

        // Plain space-separated first names with no comma/"en"/"-" between
        // them ("Kamp Anna Bram Cas") can't be split by split_names.
        // Within a known household (small, so a wrong per-word guess is
        // low-risk) also try every individual word on its own.
        if ($household_pool && $beneficiary_text !== '') {
            foreach (preg_split('/\s+/', $beneficiary_text) as $word) {
                $match = self::best_match($word, $household_pool);
                if ($match) {
                    $found[$match['member']->id] = $match;
                }
            }
        }

        $matched_beneficiary = (bool) $found;

        // Nothing named explicitly (or none of those names matched) —
        // fall back to whoever we matched as the payer(s) themselves,
        // the self-payment case ("Hr S J M Jansen" / "contributie 2026").
        if (!$found) {
            $found = $payer_matches;
        }

        foreach ($found as &$match) {
            $match['source'] = $matched_beneficiary ? 'beneficiary' : 'payer';
        }
        unset($match);

        usort($found, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_values($found);
    }

    /**
     * Best payer-name matches inside an explicit member pool. Personal
     * one-off charges use this with the known owners of an IBAN, preventing
     * a weak fuzzy surname match outside that pool from beating the actual
     * account holder merely because a first name shares a few characters
     * with the bank's initials.
     */
    public static function find_payer_candidates(string $counterparty_name, array $members): array {
        $matches = [];
        foreach (self::split_names(self::strip_via_suffix($counterparty_name)) as $name) {
            $match = self::best_match($name, $members);
            if ($match) {
                $match['source'] = 'payer';
                $matches[(int) $match['member']->id] = $match;
            }
        }
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_values($matches);
    }

    /** True only when the available birth data affirmatively says <18. */
    public static function member_is_minor(object $member, ?string $reference_date = null): bool {
        $reference_date = $reference_date ?: current_time('Y-m-d');
        if (!empty($member->birth_date)) {
            $birth = new \DateTimeImmutable((string) $member->birth_date);
            $reference = new \DateTimeImmutable($reference_date);
            return $birth->diff($reference)->y < 18;
        }
        if (!empty($member->birth_year)) {
            return ((int) substr($reference_date, 0, 4) - (int) $member->birth_year) < 18;
        }
        return false;
    }

    private static function extract_beneficiary_text(string $description): string {
        if (preg_match('/Omschrijving:\s*(.*?)\s*(?:IBAN:|Datum\/Tijd:|Valutadatum:|Kenmerk:|$)/u', $description, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private static function strip_leading_keyword(string $text): string {
        return trim(preg_replace(
            '/^(contributie|lidmaatschap|lidgeld|inschrijving|inschrijfkosten|kampbijdrage|kamp|drankafrekening|declaratie|betaling(\s+van)?)\s*/iu',
            '',
            $text
        ));
    }

    private static function strip_via_suffix(string $name): string {
        return trim(preg_replace('/\s+via\s+.*/iu', '', $name));
    }

    /**
     * Splits on the connectors seen in real payer/beneficiary text: comma,
     * " - ", " en ", "+", "e/o" (en/of) — and its slash-less bank-export
     * spelling "eo" ("N. Jansen eo C.J.M. Bakker"), which used to slip
     * through unsplit since the pattern required a literal "/". The hyphen
     * separator requires actual surrounding whitespace ("Jansen W. -
     * Bakker L.") — a bare hyphen with no spaces is a compound surname,
     * not a joiner (seen in practice: "J F M Jansen-Bakker" is one person,
     * not "Jansen" and "Bakker"; a bare "-" split wrongly cut it into two
     * names and one half then happened to exact-match an unrelated member
     * with that surname).
     */
    private static function split_names(string $text): array {
        $parts = preg_split('/\s*[,;]\s*|\bes?\/?o\b|\ben\b|\s*\+\s*|\s+-\s+/iu', $text);
        return array_values(array_filter(array_map('trim', $parts), fn($p) => mb_strlen($p) >= 2));
    }

    /** "S.J.M." -> "S J M" — one token per letter, so it lines up with how normalize() tokenizes bank text like "S J M Jansen" (each initial its own space-separated token). */
    private static function spaced_initials(string $initials): string {
        $letters = preg_replace('/[^A-Za-z]/', '', $initials);
        return implode(' ', str_split($letters));
    }

    /**
     * If $counterparty_name is shaped like "<initials> <surname>" (with or
     * without honorific/periods — "S J M Jansen", "Hr P M H de Boer",
     * "M.C. de Wit") AND the surname matches $last_name, returns the
     * canonical "S.J.M." initials string. Returns null for anything else —
     * including a spelled-out first name ("Piet Jansen") — so this only
     * ever captures genuine initials, never misfiles a full given name.
     */
    public static function extract_initials(string $counterparty_name, string $last_name): ?string {
        $name = self::strip_via_suffix($counterparty_name);
        $name = preg_replace('/\b(hr|dhr|mw|mevr|dr|drs|ing|mr)\b\.?/iu', '', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        $parts = explode(' ', $name);
        if (count($parts) < 2) {
            return null;
        }
        $surname = array_pop($parts);
        if (mb_strtolower($surname) !== mb_strtolower(trim($last_name))) {
            return null;
        }

        $letters = '';
        foreach ($parts as $part) {
            $had_period = str_contains($part, '.');
            $part = str_replace('.', '', $part);
            // A period is strong evidence of a glued multi-initial token
            // ("R.P.M.E.M." for five given names) — allow it more length
            // than a bare word, which is more likely a spelled-out name.
            $max_len = $had_period ? 6 : 4;
            if ($part === '' || mb_strlen($part) > $max_len || !preg_match('/^[A-Za-z]+$/u', $part)) {
                return null;
            }
            // A period-less, already-lowercase word ("van", "den", "de")
            // reads as a tussenvoegsel/connector, not initials — bank
            // exports write real initials capitalized (a lone single
            // letter is unambiguous either way, so it's still accepted).
            if (!$had_period && mb_strlen($part) > 1 && $part === mb_strtolower($part)) {
                return null;
            }
            $letters .= mb_strtoupper($part);
        }
        return $letters !== '' ? implode('.', str_split($letters)) . '.' : null;
    }

    private static function normalize(string $s): string {
        $s = mb_strtolower($s);
        $s = preg_replace('/\b(hr|dhr|mw|mevr|dr|drs|ing|mr)\b\.?/u', '', $s);
        $s = str_replace('.', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /** Best-matching member for one candidate name string, or null if nothing clears MIN_SCORE. */
    private static function best_match(string $candidate, array $members): ?array {
        $needle = self::normalize($candidate);
        if ($needle === '') {
            return null;
        }

        $needle_tokens = array_values(array_filter(explode(' ', $needle)));

        $best = null;
        $best_score = 0;
        foreach ($members as $member) {
            $full = self::normalize(avpvh_format_name($member));
            $score = self::name_score($needle, $needle_tokens, $full);

            // Bank account holders are routinely printed as initials +
            // surname ("S J M Jansen", "P M H Bakker") rather than a full
            // first name — score against that form too, taking whichever
            // is higher, since a member's own `first_name` alone would
            // never token-match multi-letter bank initials.
            if (!empty($member->initials)) {
                $initials_full = self::normalize(self::spaced_initials($member->initials) . ' ' . $member->last_name);
                $score = max($score, self::name_score($needle, $needle_tokens, $initials_full));
            }

            if ($score > $best_score) {
                $best_score = $score;
                $best = $member;
            }
        }

        return ($best && $best_score >= self::MIN_SCORE) ? ['member' => $best, 'score' => $best_score] : null;
    }

    /**
     * Order-insensitive: bank payer fields are routinely "LASTNAME
     * FIRSTNAME" (e.g. "JANSEN PIET"), which a plain character-similarity
     * comparison against "Piet Jansen" scores badly on purely because the
     * words are swapped — similar_text finds the longest common
     * *substring*, and a swap can shorten that a lot even though every word
     * matches. Score by how many needle tokens appear as a whole word in
     * the full name, regardless of order, and only fall back to raw
     * character similarity as a floor for close-but-not-token-exact cases.
     *
     * Deliberately exact-match only, no prefix matching: a prefix rule
     * ("Jansen" is a text-prefix of "Jansen-Bakker") wrongly treated two
     * different members' surnames as a match just because one is a
     * hyphenated compound sharing a root with the other's plain surname —
     * exact-token-or-character-similarity-floor is a safer combination.
     */
    private static function name_score(string $needle, array $needle_tokens, string $full): int {
        if (!$needle_tokens) {
            return 0;
        }
        $full_tokens = array_values(array_filter(explode(' ', $full)));
        $matched = 0;
        foreach ($needle_tokens as $nt) {
            if (in_array($nt, $full_tokens, true)) {
                $matched++;
            }
        }
        $token_score = ($matched / count($needle_tokens)) * 100;

        similar_text($needle, $full, $char_pct);

        return (int) round(max($token_score, $char_pct));
    }
}
