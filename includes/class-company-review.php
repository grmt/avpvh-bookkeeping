<?php
defined('ABSPATH') || exit;

/** Persist a VAT decision together with the exact workbook and its private audit history. */
class AVBK_Company_Review {
    public static function save(int $company_id, int $year, int $quarter, string $expected_hash, string $key, string $code, string $fixed_vat, string $state, string $note): array {
        $company = AVBK_Companies::company($company_id, 'import');
        $previous = AVBK_Company_Report::get($company_id, $year, $quarter);
        if (!$previous || empty($previous['workbook_data']) || !hash_equals($previous['workbook_hash'], $expected_hash)) throw new \RuntimeException('Het resultaat is gewijzigd of ontbreekt. Ververs de pagina voordat je controleert.');
        $bank = AVBK_Companies::transactions($company_id, $year, $quarter);
        $cards = AVBK_Company_Cards::transactions($company_id, $year, $quarter);
        if ($previous['transactions_hash'] !== AVBK_Company_Report::fingerprint($bank) || ($previous['cards_hash'] ?? '') !== AVBK_Company_Report::fingerprint($cards)) throw new \RuntimeException('Er zijn nieuwe bank- of kaartgegevens. Werk eerst de kwartaalwerkmap bij.');
        if (!in_array($code, ['', '0', '9', '21', 'x'], true) || !in_array($state, ['checked', 'pending'], true) || ($state === 'checked' && $code === '')) throw new \RuntimeException('Kies een BTW-tarief of vast BTW-bedrag voordat je de regel bevestigt.');
        if (mb_strlen($note) > 500) throw new \RuntimeException('Gebruik maximaal 500 tekens voor de factuurverwijzing of notitie.');
        $bytes = base64_decode($previous['workbook_data'], true);
        if ($bytes === false || strlen($bytes) > 3 * 1024 * 1024 || !hash_equals($expected_hash, hash('sha256', $bytes))) throw new \RuntimeException('De opgeslagen werkmap kon niet worden gecontroleerd.');
        $source = wp_tempnam('avbk-review-source.xlsx');
        $output = wp_tempnam('avbk-review-output.xlsx');
        try {
            if (!$source || !$output || file_put_contents($source, $bytes) !== strlen($bytes)) throw new \RuntimeException('Kan geen tijdelijke werkmap maken.');
            // Re-read row identities from the verified workbook, never accept a client row number or amount.
            $current = AVBK_Company_Report::build($source, $company, $bank, $year, $quarter, $previous['filename'], $cards);
            $matches = array_values(array_filter($current['rows'], fn($row) => $key !== '' && ($row['review_key'] ?? '') === $key));
            if (count($matches) !== 1) throw new \RuntimeException('Deze regel kan niet afzonderlijk worden gecontroleerd. Controleer de administratie, periode en eventuele detailregels.');
            $row = $matches[0]; $gross = $row['gross_cents'];
            $vat = null;
            if ($code === 'x') {
                if (!preg_match('/^\d{1,12}(?:[.,]\d{1,2})?$/D', $fixed_vat)) throw new \RuntimeException('Vul een positief BTW-bedrag in met maximaal twee decimalen.');
                $vat = (int) round((float) str_replace(',', '.', $fixed_vat) * 100);
                if ($vat > abs($gross)) throw new \RuntimeException('Het BTW-bedrag mag niet groter zijn dan het bedrag inclusief BTW.');
                $vat *= $gross < 0 ? -1 : 1;
            } elseif ($code !== '') {
                $vat = $gross - (int) round($gross * 100 / (100 + (int) $code));
            }
            $decision = ['code' => $code, 'vat_cents' => $vat, 'net_cents' => $vat === null ? null : $gross - $vat,
                'gross_cents' => $gross, 'direction' => $row['direction'], 'state' => $state, 'note' => $note,
                'by' => get_current_user_id(), 'at' => gmdate('Y-m-d H:i:s') . ' UTC'];
            $reviews = $previous['reviews'] ?? [];
            $before = $reviews[$key] ?? array_intersect_key($row, array_flip(['code', 'vat_cents', 'net_cents', 'status', 'note']));
            $reviews[$key] = $decision;
            AVBK_Company_Workbook::update($source, $output, $company, $bank, $year, $quarter, $cards, $reviews);
            $report = AVBK_Company_Report::build($output, $company, $bank, $year, $quarter, $previous['filename'], $cards);
            if (empty($report['workbook_data'])) throw new \RuntimeException('De werkmap is te groot om de controle op te slaan.');
            $report['reviews'] = $reviews;
            $report['review_log'] = $previous['review_log'] ?? [];
            $report['review_log'][] = ['key' => $key, 'date' => $row['date'], 'name' => $row['name'], 'before' => $before, 'after' => $decision];
            AVBK_Company_Report::save($report, $previous);
            return $report;
        } finally {
            if ($source) wp_delete_file($source);
            if ($output) wp_delete_file($output);
        }
    }
}
