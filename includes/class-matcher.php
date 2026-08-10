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

    public static function classify_type(string $description): ?string {
        $d = mb_strtolower($description);
        foreach (['contributie', 'lidmaatschap', 'lidgeld', 'inschrijving', 'inschrijfkosten'] as $kw) {
            if (str_contains($d, $kw)) {
                return 'contribution';
            }
        }
        if (str_contains($d, 'kamp')) {
            return 'camp';
        }
        return null;
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
        $all_members = AVPVH_DB::get_members(['status' => 'active']);

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
        // the self-payment case ("Hr S J M Kramer" / "contributie 2026").
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

    /** Splits on the connectors seen in real payer/beneficiary text: comma, " - ", " en ", "e/o" (en/of). */
    private static function split_names(string $text): array {
        $parts = preg_split('/\s*(?:,|;|\bes?\/o\b|\ben\b|-)\s*/iu', $text);
        return array_values(array_filter(array_map('trim', $parts), fn($p) => mb_strlen($p) >= 2));
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

        $best = null;
        $best_score = 0;
        foreach ($members as $member) {
            $full = self::normalize(avpvh_format_name($member));
            similar_text($needle, $full, $pct);
            // A bare first name ("Timo") against a full name ("Timo
            // Bergsma") scores low on similar_text purely from length —
            // give it credit when it's an exact match of the first token.
            $first_token_bonus = (strtok($full, ' ') === strtok($needle, ' ')) ? 25 : 0;
            $score = min(100, (int) round($pct + $first_token_bonus));
            if ($score > $best_score) {
                $best_score = $score;
                $best = $member;
            }
        }

        return ($best && $best_score >= self::MIN_SCORE) ? ['member' => $best, 'score' => $best_score] : null;
    }
}
