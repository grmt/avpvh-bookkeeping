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
 * ("Lidgeld Anna, Bram en Cas", "Kamp Timo Merel Sepp" — first
 * names only, no member IDs), and connectors vary ("," / " - " / " en " /
 * "e/o" ["en/of"]). So beneficiary names are matched within the payer's
 * own household first (AVPVH_DB::get_manageable_members) before falling
 * back to a search across every active member.
 */
class AVBK_Matcher {

    const MIN_SCORE = 55;

    /** Raw AVBK_Xlsx_Reader row -> normalized transaction fields. */
    public static function parse_row(array $row): ?array {
        $date_raw = trim($row['Datum'] ?? '');
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date_raw, $m)) {
            return null;
        }
        $amount = self::parse_amount($row['Bedrag (EUR)'] ?? '0');
        $direction = strtolower(trim($row['Af Bij'] ?? '')) === 'bij' ? 'in' : 'out';

        return [
            'transaction_date'  => "{$m[1]}-{$m[2]}-{$m[3]}",
            'amount'            => $amount,
            'direction'         => $direction,
            'counterparty_name' => trim($row['Naam / Omschrijving'] ?? ''),
            'counterparty_iban' => strtoupper(trim($row['Tegenrekening'] ?? '')),
            'description'       => trim($row['Mededelingen'] ?? ''),
        ];
    }

    /** "82,83" (European decimal comma) -> 82.83 */
    public static function parse_amount(string $raw): float {
        $raw = trim(str_replace(['€', ' '], '', $raw));
        $raw = str_replace('.', '', $raw);   // thousands separator, if present
        $raw = str_replace(',', '.', $raw);  // decimal comma -> dot
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
            if (str_contains($d, mb_strtolower($activity_type->name))) {
                $types[] = $activity_type->name;
            }
        }

        if (!in_array('Contributie', $types, true)) {
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

    /**
     * Candidate members for this transaction, best guess first. Each entry
     * is ['member' => object, 'score' => 0-100]. Never guesses beyond
     * MIN_SCORE — an empty array just means "nobody confident enough",
     * which is exactly what should land as fully unmatched.
     */
    public static function find_candidates(string $counterparty_name, string $description): array {
        $payer_names = self::split_names(self::strip_via_suffix($counterparty_name));
        $all_members = AVBK_DB::get_payable_members();

        $payer_matches = [];
        foreach ($payer_names as $name) {
            $match = self::best_match($name, $all_members);
            if ($match) {
                $payer_matches[$match['member']->id] = $match;
            }
        }

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

        // Strip the fee-type word itself ("Kamp Timo Merel Sepp" ->
        // "Timo Merel Sepp") — left in place, it drags down every name
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
        // them ("Kamp Timo Merel Sepp") can't be split by split_names.
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

        // Nothing named explicitly (or none of those names matched) —
        // fall back to whoever we matched as the payer(s) themselves,
        // the self-payment case ("Hr S J M Jansen" / "contributie 2026").
        if (!$found) {
            $found = $payer_matches;
        }

        usort($found, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_values($found);
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
     * " - ", " en ", "e/o" (en/of). The hyphen separator requires actual
     * surrounding whitespace ("Jansen W. - Bakker L.") — a bare hyphen
     * with no spaces is a compound surname, not a joiner (seen in practice:
     * "J F M Jansen-Bakker" is one person, not "Jansen" and "Bakker"; a
     * bare "-" split wrongly cut it into two names and one half then
     * happened to exact-match an unrelated member with that surname).
     */
    private static function split_names(string $text): array {
        $parts = preg_split('/\s*[,;]\s*|\bes?\/o\b|\ben\b|\s+-\s+/iu', $text);
        return array_values(array_filter(array_map('trim', $parts), fn($p) => mb_strlen($p) >= 2));
    }

    /** "S.J.M." -> "S J M" — one token per letter, so it lines up with how normalize() tokenizes bank text like "S J M Jansen" (each initial its own space-separated token). */
    private static function spaced_initials(string $initials): string {
        $letters = preg_replace('/[^A-Za-z]/', '', $initials);
        return implode(' ', str_split($letters));
    }

    /**
     * If $counterparty_name is shaped like "<initials> <surname>" (with or
     * without honorific/periods — "S J M Jansen", "Hr P M H Bakker",
     * "M.C. Hendriks") AND the surname matches $last_name, returns the
     * canonical "S.J.M." initials string. Returns null for anything else —
     * including a spelled-out first name ("Simon Jansen") — so this only
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
