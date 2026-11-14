<?php
defined('ABSPATH') || exit;

/** Presentation only: retain every worksheet row and its financial values. */
class AVBK_Company_Card_Groups {
    public static function build(array $report, object $company, array $banks, array $cards): array {
        AVBK_Companies::company((int) $company->id);
        $statements = array_filter(get_option('avbk_company_card_statements_' . $company->id, []),
            fn($statement) => ($statement['card_account'] ?? '') === ($company->card_account ?? ''));
        $card_groups = [];
        foreach ($cards as $card) {
            if ((int) $card->company_id !== (int) $company->id) {
                continue;
            }
            $raw = json_decode($card->raw_data, true) ?: [];
            $id = (int) ($raw['Afschrift document_id'] ?? 0);
            $statement = $statements[$id] ?? null;
            $verified = $statement && ($raw['Afschrift sha256'] ?? '') === $statement['source_hash']
                && ($raw['Afschriftdatum'] ?? '') === $statement['date'];
            $matches = [];
            foreach ($statements as $candidate_id => $candidate) {
                if (in_array($card->dedupe_hash, $candidate['transaction_hashes'] ?? [], true)) {
                    $matches[] = $candidate_id;
                }
            }
            // A validated PDF can identify purchases already imported from a CSV.
            $card_groups['card:' . $card->dedupe_hash] = count($matches) === 1 ? 'statement-' . $matches[0]
                : (!$matches && $verified && !isset($statement['transaction_hashes']) ? 'statement-' . $id : 'cards-unlinked');
        }
        // A payment must be unique in both directions. Equal amounts alone are insufficient.
        $candidates = []; $reverse = []; $bank_keys = [];
        foreach ($banks as $bank) {
            if ((int) $bank->company_id !== (int) $company->id || $bank->direction !== 'out'
                || !AVBK_Vat_Patterns::is_settlement($bank->counterparty_name, $bank->description)) {
                continue;
            }
            $key = 'bank:' . AVBK_Company_Report::bank_key($bank->transaction_date, $bank->amount, $bank->direction,
                $bank->counterparty_iban, $bank->counterparty_name, $bank->description);
            $bank_keys[$key][] = $bank;
            foreach ($statements as $id => $statement) {
                $last = (new \DateTimeImmutable($statement['date']))->modify('+45 days')->format('Y-m-d');
                if ((int) round((float) $bank->amount * 100) !== $statement['total_cents']
                    || $bank->transaction_date < $statement['date'] || $bank->transaction_date > $last) {
                    continue;
                }
                $candidates[$id][] = $key;
                $reverse[$key][] = $id;
            }
        }
        $linked = [];
        foreach ($candidates as $id => $keys) {
            if (count($keys) === 1 && count($reverse[$keys[0]]) === 1) {
                $linked[$keys[0]] = 'statement-' . $id;
            }
        }
        $groups = []; $active = 'bank';
        foreach ($report['rows'] as $row) {
            $source = $row['source'] ?? 'Bank';
            if ($source === 'Creditcard') {
                $active = $card_groups[$row['transaction_key'] ?? $row['review_key'] ?? ''] ?? 'cards-unlinked';
            } elseif ($source === 'Bank') {
                $active = 'bank';
                if ($row['status'] === 'settlement') {
                    $active = 'settlements-unlinked';
                    $key = $row['transaction_key'] ?? '';
                    if ($key === '') {
                        // Older snapshots omitted the identity on non-reviewable settlement rows.
                        $matches = [];
                        foreach ($bank_keys as $candidate => $transactions) {
                            foreach ($transactions as $bank) {
                                if ($row['date'] === $bank->transaction_date && $row['name'] === $bank->counterparty_name
                                    && $row['direction'] === $bank->direction && $row['gross_cents'] === (int) round((float) $bank->amount * 100)) {
                                    $matches[] = $candidate;
                                }
                            }
                        }
                        if (count($matches) === 1) {
                            $key = $matches[0];
                        }
                    }
                    $active = $linked[$key] ?? $active;
                }
            }
            if (!isset($groups[$active])) {
                $statement = str_starts_with($active, 'statement-') ? ($statements[(int) substr($active, 10)] ?? null) : null;
                $groups[$active] = ['id' => $active, 'statement' => $statement, 'rows' => [], 'card_cents' => 0, 'card_count' => 0, 'paid' => false, 'ambiguous' => false];
                if ($statement) {
                    $id = (int) substr($active, 10);
                    $groups[$active]['ambiguous'] = !empty($candidates[$id]) && !in_array($active, $linked, true);
                }
            }
            $groups[$active]['rows'][] = $row;
            if ($source === 'Creditcard') {
                $groups[$active]['card_cents'] += $row['gross_cents'] ?? 0;
                $groups[$active]['card_count']++;
            }
            if ($row['status'] === 'settlement') {
                $groups[$active]['paid'] = true;
            }
        }
        uasort($groups, function ($a, $b) {
            if ($a['id'] === 'bank' || $b['id'] === 'bank') {
                return $a['id'] === $b['id'] ? 0 : ($a['id'] === 'bank' ? -1 : 1);
            }
            return strcmp($b['statement']['date'] ?? '', $a['statement']['date'] ?? '') ?: strcmp($a['id'], $b['id']);
        });
        foreach ($groups as &$group) {
            // Put the settlement first, leaving each purchase and its detail rows together.
            $blocks = [];
            foreach ($group['rows'] as $row) {
                if (($row['source'] ?? 'Bank') === 'Detail' && $blocks) {
                    $blocks[count($blocks) - 1][] = $row;
                } else {
                    $blocks[] = [$row];
                }
            }
            usort($blocks, fn($a, $b) => ($b[0]['status'] === 'settlement') <=> ($a[0]['status'] === 'settlement'));
            $group['rows'] = array_merge(...$blocks);
        }
        unset($group);
        return array_values($groups);
    }
}
