<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-xlsx-archive.php';

/** Merge a bank quarter into a copy of an existing VAT workbook. */
class AVBK_Company_Workbook {
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private static function xml(string $text): \DOMDocument {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (stripos($text, '<!DOCTYPE') !== false || !$doc->loadXML($text, LIBXML_NONET)) {
                throw new \RuntimeException('Ongeldige werkmap-XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $doc;
    }

    public static function sheet_name(object $company, int $year, int $quarter): string {
        AVBK_Companies::period($year, $quarter);
        return $company->sheet_prefix . ' Q' . $quarter;
    }

    private static function value_key(array $values): string {
        return hash('sha256', wp_json_encode([
            $values[0] ?? '', number_format((float) ($values[6] ?? 0), 2, '.', ''),
            in_array(mb_strtolower($values[5] ?? ''), ['debit', 'af'], true) ? 'out' : 'in',
            AVBK_Companies::normalize_iban($values[3] ?? ''), $values[1] ?? '', $values[13] ?? '',
        ]));
    }

    /** Relocate relative row references, leaving string literals and absolute rows intact. */
    private static function formula(string $formula, int $delta, int $column_delta = 0): string {
        $parts = preg_split('/("(?:[^"]|"")*")/', $formula, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($i % 2) continue;
            $parts[$i] = preg_replace_callback('/(?<![A-Za-z0-9_])((?:\x27[^\x27]+\x27|[A-Za-z_][A-Za-z0-9_.]*)!)?(\$?)([A-Z]{1,3})(\$?)([1-9][0-9]*)(?![A-Za-z0-9_(])/', function ($m) use ($delta, $column_delta) {
                $column = 0;
                foreach (str_split($m[3]) as $letter) $column = $column * 26 + ord($letter) - 64;
                $column = $m[2] === '$' ? $column : $column + $column_delta;
                $letters = '';
                while ($column > 0) { $column--; $letters = chr(65 + $column % 26) . $letters; $column = intdiv($column, 26); }
                return ($m[1] ?? '') . $m[2] . $letters . $m[4] . ($m[4] === '$' ? $m[5] : max(1, (int) $m[5] + $delta));
            }, $part);
        }
        return implode('', $parts);
    }

    /** Excel also shares total formulas across neighbouring columns. */
    private static function expand_shared(\DOMDocument $doc): void {
        $anchors = [];
        foreach ($doc->getElementsByTagNameNS(self::NS, 'c') as $cell) {
            $formula = $cell->getElementsByTagNameNS(self::NS, 'f')->item(0);
            if ($formula && $formula->getAttribute('t') === 'shared' && $formula->textContent !== '') {
                $anchors[$formula->getAttribute('si')] = [$cell->getAttribute('r'), $formula->textContent];
            }
        }
        foreach ($doc->getElementsByTagNameNS(self::NS, 'c') as $cell) {
            $formula = $cell->getElementsByTagNameNS(self::NS, 'f')->item(0);
            if (!$formula || $formula->getAttribute('t') !== 'shared') continue;
            [$anchor, $text] = $anchors[$formula->getAttribute('si')] ?? ['', ''];
            preg_match('/^([A-Z]+)(\d+)$/', $anchor, $a);
            preg_match('/^([A-Z]+)(\d+)$/', $cell->getAttribute('r'), $b);
            if (!$a || !$b) throw new \RuntimeException('Ongeldige gedeelde formule.');
            $col = function (string $letters): int { $value = 0; foreach (str_split($letters) as $letter) $value = $value * 26 + ord($letter) - 64; return $value; };
            $formula->textContent = self::formula($text, (int) $b[2] - (int) $a[2], $col($b[1]) - $col($a[1]));
            foreach (['t', 'si', 'ref'] as $attr) $formula->removeAttribute($attr);
        }
    }

    private static function relocate(\DOMElement $row, int $number): void {
        $delta = $number - (int) $row->getAttribute('r');
        $row->setAttribute('r', (string) $number);
        foreach ($row->getElementsByTagNameNS(self::NS, 'c') as $cell) {
            $cell->setAttribute('r', preg_replace('/[0-9]+$/', (string) $number, $cell->getAttribute('r')));
            $formula = $cell->getElementsByTagNameNS(self::NS, 'f')->item(0);
            if ($formula) $formula->textContent = self::formula($formula->textContent, $delta);
        }
    }

    private static function cell(\DOMDocument $doc, string $ref, $value, bool $numeric = false, ?string $formula = null): \DOMElement {
        $cell = $doc->createElementNS(self::NS, 'c');
        $cell->setAttribute('r', $ref);
        if ($formula !== null) $cell->appendChild($doc->createElementNS(self::NS, 'f', $formula));
        if ($numeric) {
            if ($value !== null) $cell->appendChild($doc->createElementNS(self::NS, 'v', (string) $value));
        } else {
            $cell->setAttribute('t', 'inlineStr');
            $inline = $doc->createElementNS(self::NS, 'is');
            $text = $doc->createElementNS(self::NS, 't');
            $text->appendChild($doc->createTextNode((string) $value));
            $inline->appendChild($text); $cell->appendChild($inline);
        }
        return $cell;
    }

    /** Source and destination must differ. Only the selected sheet and calculation metadata change. */
    public static function update(string $source, string $output, object $company, array $transactions, int $year, int $quarter, array $card_transactions = []): array {
        if ($source === $output || (realpath($output) && realpath($source) === realpath($output))) throw new \RuntimeException('Gebruik een aparte uitvoerwerkmap.');
        if (!$transactions) throw new \RuntimeException('Importeer eerst banktransacties voor dit kwartaal.');
        [$start, $end] = AVBK_Companies::period($year, $quarter);
        $name = self::sheet_name($company, $year, $quarter);
        $bank_keys = [];
        foreach ($transactions as $tx) $bank_keys[self::value_key([0 => str_replace('-', '', $tx->transaction_date), 1 => $tx->counterparty_name,
            3 => $tx->counterparty_iban, 5 => $tx->direction === 'out' ? 'Debit' : 'Credit', 6 => $tx->amount, 13 => $tx->description])] = true;
        $history = AVBK_Vat_Patterns::history($source, $company, $year, $quarter);
        $zip = new AVBK_Xlsx_Archive($source);
        try {
            $wb = self::xml((string) $zip->getFromName('xl/workbook.xml'));
            $rels = self::xml((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
            $rid = '';
            foreach ($wb->getElementsByTagNameNS(self::NS, 'sheet') as $sheet) {
                if (mb_strtolower(trim($sheet->getAttribute('name'))) === mb_strtolower($name)) $rid = $sheet->getAttributeNS(self::REL_NS, 'id');
            }
            $path = '';
            foreach ($rels->documentElement->childNodes as $rel) {
                if ($rel instanceof \DOMElement && $rel->getAttribute('Id') === $rid && !$rel->hasAttribute('TargetMode')) {
                    $target = $rel->getAttribute('Target');
                    $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                }
            }
            if ($rid === '' || !preg_match('#^xl/worksheets/[^/]+\.xml$#D', $path)) throw new \RuntimeException('Het juiste kwartaalwerkblad ontbreekt in de werkmap.');
            $doc = self::xml((string) $zip->getFromName($path));
            $values = AVBK_Xlsx_Reader::read_named_rows($source, $name);
            if (!in_array($values[1][0] ?? '', ['Date', 'Datum'], true) || !in_array($values[1][8] ?? '', ['BTW %', 'Btw %'], true)) {
                throw new \RuntimeException('Gebruik de bestaande BTW-werkmap met de bankkolommen A:P en BTW % in kolom I.');
            }
            // These carry independent cell references; reject instead of silently corrupting them.
            foreach (['tableParts', 'mergeCells', 'conditionalFormatting', 'dataValidations', 'hyperlinks', 'drawing', 'legacyDrawing'] as $tag) {
                if ($doc->getElementsByTagNameNS(self::NS, $tag)->length) throw new \RuntimeException('Dit werkblad bevat tabellen, samengevoegde cellen of celregels die eerst verwerkt moeten worden.');
            }
            self::expand_shared($doc);
            $data = $doc->getElementsByTagNameNS(self::NS, 'sheetData')->item(0);
            if (!$data) throw new \RuntimeException('Werkbladgegevens ontbreken.');
            $header = null; $styles = []; $groups = []; $key = null; $existing_years = []; $manual_details = false;
            foreach ($data->childNodes as $row) {
                if (!$row instanceof \DOMElement || $row->localName !== 'row') continue;
                $r = (int) $row->getAttribute('r');
                if ($r === 1) { $header = $row->cloneNode(true); continue; }
                $v = $values[$r] ?? [];
                if (!$styles) foreach ($row->getElementsByTagNameNS(self::NS, 'c') as $c) {
                    if ($c->hasAttribute('s')) $styles[preg_replace('/[0-9]+$/', '', $c->getAttribute('r'))] = $c->getAttribute('s');
                }
                if (preg_match('/\bCC:(\d{4}-\d{2}-\d{2}):([a-f0-9]{64})\b/', (string) ($v[15] ?? ''), $card_match)) {
                    $date = AVBK_Bank_Import_Layout::parse_date($card_match[1], 'Y-m-d');
                    if (!$date || $date < $start || $date >= $end) { $key = null; continue; }
                    if (empty($company->card_account) || AVBK_Company_Cards::account($v[2] ?? '') !== $company->card_account) throw new \RuntimeException('Het werkblad bevat creditcardregels van een ander account.');
                    if ((float) ($v[7] ?? 0) != 0) throw new \RuntimeException('Een creditcarddetailregel telt mee in het banktotaal. Maak kolom H van die detailregel leeg om dubbeltelling te voorkomen.');
                    $key = 'card:' . $card_match[2];
                    if (isset($groups[$key])) throw new \RuntimeException('Het werkblad bevat dubbele creditcardregels.');
                    $groups[$key] = ['date' => $date, 'rows' => [$row->cloneNode(true)], 'pending' => str_starts_with($v[15] ?? '', 'BTW-')];
                } elseif (preg_match('/^\d{8}$/D', (string) ($v[0] ?? ''))) {
                    $date = AVBK_Bank_Import_Layout::parse_date($v[0], 'Ymd');
                    if (!$date) throw new \RuntimeException('Ongeldige datum in het bestaande kwartaalwerkblad.');
                    $existing_years[substr($date, 0, 4)] = true;
                    if (substr($date, 0, 4) === (string) $year && ($date < $start || $date >= $end)) throw new \RuntimeException('Het bestaande werkblad bevat transacties uit een ander kwartaal.');
                    if ($date < $start || $date >= $end) { $key = null; continue; }
                    if (AVBK_Companies::normalize_iban($v[2] ?? '') !== $company->iban) throw new \RuntimeException('Het bestaande werkblad bevat een andere bankrekening.');
                    if ($card_transactions && AVBK_Vat_Patterns::is_settlement($v[1] ?? '', $v[13] ?? '') && ((float) ($v[9] ?? 0) != 0 || (float) ($v[10] ?? 0) != 0)) {
                        throw new \RuntimeException('Het werkblad kent al BTW of kosten toe aan een creditcardafrekening. Controleer die regel voordat je losse kaarttransacties toevoegt.');
                    }
                    $key = self::value_key($v);
                    if ($card_transactions && !isset($bank_keys[$key])) throw new \RuntimeException('Het werkblad bevat extra handmatige transacties. Controleer deze voordat je creditcardaankopen toevoegt, om dubbeltelling te voorkomen.');
                    if (isset($groups[$key])) throw new \RuntimeException('Het werkblad bevat identieke bankregels; controleer deze eerst handmatig.');
                    $groups[$key] = ['date' => $date, 'rows' => [$row->cloneNode(true)], 'pending' => str_starts_with($v[15] ?? '', 'BTW-')];
                } elseif ($key !== null && array_filter($v, fn($value) => (string) $value !== '')) {
                    $is_total = false;
                    foreach ($row->getElementsByTagNameNS(self::NS, 'f') as $f) {
                        if (preg_match('/^SUM\([HJK]2:[HJK][0-9]+\)$/iD', $f->textContent)) $is_total = true;
                    }
                    if ($is_total) { $key = null; continue; }
                    if (!str_starts_with($key, 'card:') && (is_numeric($v[6] ?? null) || is_numeric($v[9] ?? null) || is_numeric($v[10] ?? null))) $manual_details = true;
                    $groups[$key]['rows'][] = $row->cloneNode(true);
                }
            }
            if (isset($existing_years[(string) $year]) && count($existing_years) > 1) throw new \RuntimeException('Het kwartaalwerkblad bevat meerdere jaren. Controleer dit eerst.');
            if ($card_transactions && $manual_details) throw new \RuntimeException('Het werkblad bevat handmatig ingevoerde aankoopdetails. Controleer die eerst om dubbele creditcardkosten te voorkomen.');
            $preserved = count($groups); $added = 0; $suggested = 0;
            foreach ($transactions as $tx) {
                if ((int) $tx->company_id !== (int) $company->id || $tx->transaction_date < $start || $tx->transaction_date >= $end) throw new \RuntimeException('Transactie hoort niet bij dit bedrijf en kwartaal.');
                $raw = json_decode($tx->raw_data, true) ?: [];
                $v = [str_replace('-', '', $tx->transaction_date), $tx->counterparty_name, $company->iban, $tx->counterparty_iban,
                    $raw['Code'] ?? '', $tx->direction === 'out' ? 'Debit' : 'Credit', $tx->amount,
                    '', '', '', '', '', $raw['Transaction type'] ?? $raw['Mutatiesoort'] ?? '', $tx->description,
                    $raw['Resulting balance'] ?? $raw['Saldo na mutatie'] ?? '', $raw['Tag'] ?? ''];
                $key = self::value_key($v);
                if (isset($groups[$key])) continue;
                $suggestion = AVBK_Vat_Patterns::suggest($history, $tx);
                $v[15] = 'BTW-controleren: ' . $suggestion['source'];
                $net = null; $vat = null;
                $signed = ($tx->direction === 'out' ? 1 : -1) * (float) $tx->amount;
                if ($suggestion['code'] !== null) {
                    $v[8] = $suggestion['code'];
                    $v[15] = 'BTW-suggestie: ' . $suggestion['source'] . '; controleer factuur';
                    $vat = $suggestion['code'] === 'x' ? ($tx->direction === 'out' ? 1 : -1) * $suggestion['fixed_vat'] : round($signed - $signed / (1 + (float) $suggestion['code'] / 100), 2);
                    $net = round($signed - $vat, 2); $suggested++;
                }
                $row = $doc->createElementNS(self::NS, 'row'); $row->setAttribute('r', '2');
                foreach ($v as $i => $value) {
                    $col = chr(65 + $i);
                    if ($i === 7) $cell = self::cell($doc, 'H2', ($tx->direction === 'out' ? 1 : -1) * (float) $tx->amount, true, 'IF(OR(F2="Debit",F2="Af"),G2,-G2)');
                    elseif ($i === 8) $cell = self::cell($doc, 'I2', $value, is_numeric($value));
                    elseif ($i === 9) {
                        $fixed = isset($suggestion['fixed_vat']) ? number_format(($tx->direction === 'out' ? 1 : -1) * $suggestion['fixed_vat'], 2, '.', '') : '""';
                        $cell = self::cell($doc, 'J2', $vat, true, 'IF(I2="","",IF(I2="x",' . $fixed . ',H2-ROUND(H2/(1+I2/100),2)))');
                    }
                    elseif ($i === 10) $cell = self::cell($doc, 'K2', $net, true, 'IF(I2="","",IF(J2="","",H2-J2))');
                    elseif ($i === 11) $cell = self::cell($doc, 'L2', '');
                    else $cell = self::cell($doc, $col . '2', $value, $i === 6);
                    if (isset($styles[$col])) $cell->setAttribute('s', $styles[$col]);
                    $row->appendChild($cell);
                }
                $groups[$key] = ['date' => $tx->transaction_date, 'rows' => [$row], 'pending' => true]; $added++;
            }
            $cards_added = 0;
            foreach ($card_transactions as $card) {
                if ((int) $card->company_id !== (int) $company->id || $card->transaction_date < $start || $card->transaction_date >= $end
                    || empty($company->card_account) || $card->card_account !== $company->card_account) throw new \RuntimeException('Creditcardtransactie hoort niet bij dit bedrijf en kwartaal.');
                if ($card->entry_type === 'settlement') continue;
                $key = 'card:' . $card->dedupe_hash;
                if (isset($groups[$key])) continue;
                $tx = (object) ['counterparty_name' => $card->description, 'counterparty_iban' => '', 'direction' => $card->direction, 'description' => 'Creditcardaankoop'];
                $suggestion = AVBK_Vat_Patterns::suggest($history, $tx);
                $signed = ($card->direction === 'out' ? 1 : -1) * (float) $card->amount;
                $vat = null; $net = null;
                if ($suggestion['code'] !== null) {
                    $vat = $suggestion['code'] === 'x' ? ($card->direction === 'out' ? 1 : -1) * $suggestion['fixed_vat'] : round($signed - $signed / (1 + (float) $suggestion['code'] / 100), 2);
                    $net = round($signed - $vat, 2); $suggested++;
                }
                $tag = 'CC:' . $card->transaction_date . ':' . $card->dedupe_hash;
                $note = 'BTW-' . ($suggestion['code'] === null ? 'controleren: ' : 'suggestie: ') . $suggestion['source'] . '; ' . $tag;
                $v = ['', $card->description, $card->card_account, '', '', $card->direction === 'out' ? 'Debit' : 'Credit', $card->amount,
                    '', $suggestion['code'] ?? '', '', '', '', 'Creditcard ' . $card->card_last4,
                    $card->transaction_date . '; ' . $card->currency . ' ' . $card->original_amount, '', $note];
                $row = $doc->createElementNS(self::NS, 'row'); $row->setAttribute('r', '2');
                $gross_formula = 'IF(OR(F2="Debit",F2="Af"),G2,-G2)';
                foreach ($v as $i => $value) {
                    $ref = chr(65 + $i) . '2';
                    if ($i === 9) {
                        $fixed = $suggestion['code'] === 'x' ? number_format($vat, 2, '.', '') : '""';
                        $cell = self::cell($doc, $ref, $vat, true, 'IF(I2="","",IF(I2="x",' . $fixed . ',' . $gross_formula . '-ROUND(' . $gross_formula . '/(1+I2/100),2)))');
                    } elseif ($i === 10) $cell = self::cell($doc, $ref, $net, true, 'IF(J2="","",' . $gross_formula . '-J2)');
                    else $cell = self::cell($doc, $ref, $value, $i === 6 || ($i === 8 && is_numeric($value)));
                    $col = chr(65 + $i);
                    if (isset($styles[$col])) $cell->setAttribute('s', $styles[$col]);
                    $row->appendChild($cell);
                }
                $groups[$key] = ['date' => $card->transaction_date, 'rows' => [$row], 'pending' => true]; $cards_added++;
            }
            uasort($groups, fn($a, $b) => strcmp($b['date'], $a['date']));
            while ($data->firstChild) $data->removeChild($data->firstChild);
            $data->appendChild($header); $number = 2;
            foreach ($groups as $group) foreach ($group['rows'] as $row) {
                self::relocate($row, $number++); $data->appendChild($row);
            }
            $total = $doc->createElementNS(self::NS, 'row'); $total->setAttribute('r', (string) $number);
            $pending = count(array_filter($groups, fn($group) => $group['pending']));
            $total->appendChild(self::cell($doc, 'B' . $number, $pending ? 'VOORLOPIG: ' . $pending . ' BTW-regels te controleren (kolom P)' : 'Totaal'));
            foreach (['H', 'J', 'K'] as $col) {
                $formula = $col === 'H' ? 'SUM(H2:H' . ($number - 1) . ')' : 'SUMIF(P2:P' . ($number - 1) . ',"<>BTW-*",' . $col . '2:' . $col . ($number - 1) . ')';
                $total->appendChild(self::cell($doc, $col . $number, null, true, $formula));
            }
            $data->appendChild($total);
            $dimension = $doc->getElementsByTagNameNS(self::NS, 'dimension')->item(0);
            if ($dimension) $dimension->setAttribute('ref', 'A1:P' . $number);
            foreach ($doc->getElementsByTagNameNS(self::NS, 'autoFilter') as $filter) $filter->setAttribute('ref', 'A1:P' . ($number - 1));
            $calc = $wb->getElementsByTagNameNS(self::NS, 'calcPr')->item(0);
            if (!$calc) { $calc = $wb->createElementNS(self::NS, 'calcPr'); $wb->documentElement->appendChild($calc); }
            $calc->setAttribute('fullCalcOnLoad', '1'); $calc->setAttribute('forceFullCalc', '1'); $calc->setAttribute('calcMode', 'auto');
            foreach (iterator_to_array($rels->documentElement->childNodes) as $rel) {
                if ($rel instanceof \DOMElement && str_ends_with($rel->getAttribute('Type'), '/calcChain')) $rels->documentElement->removeChild($rel);
            }
            $types = self::xml((string) $zip->getFromName('[Content_Types].xml'));
            foreach (iterator_to_array($types->documentElement->childNodes) as $type) {
                if ($type instanceof \DOMElement && $type->getAttribute('PartName') === '/xl/calcChain.xml') $types->documentElement->removeChild($type);
            }
        } finally { $zip->close(); }
        if (!copy($source, $output)) throw new \RuntimeException('Kan geen werkmapkopie maken.');
        $out = new AVBK_Xlsx_Archive($output);
        try {
            $out->put($path, $doc->saveXML());
            $out->put('xl/workbook.xml', $wb->saveXML());
            $out->put('xl/_rels/workbook.xml.rels', $rels->saveXML());
            $out->put('[Content_Types].xml', $types->saveXML());
            $out->remove('xl/calcChain.xml');
        } finally { $out->close(); }
        return ['sheet' => $name, 'added' => $added, 'preserved' => $preserved, 'suggested' => $suggested, 'cards_added' => $cards_added];
    }
}
