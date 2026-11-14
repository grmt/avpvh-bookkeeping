<?php
defined('ABSPATH') || exit;

/** A manual, double-entry general ledger per company: a chart of accounts and balanced journal entries. */
class AVBK_Company_Ledger {
    public const ACCOUNT_TYPES = ['asset' => 'Activa', 'liability' => 'Passiva', 'equity' => 'Eigen vermogen', 'income' => 'Opbrengsten', 'expense' => 'Kosten'];

    /** A starter Dutch RGS-lite chart; companies add their own accounts beyond this. */
    private const DEFAULT_ACCOUNTS = [
        ['1000', 'Kas', 'asset'],
        ['1010', 'Bank', 'asset'],
        ['1300', 'Debiteuren', 'asset'],
        ['1710', 'Te vorderen BTW', 'asset'],
        ['1600', 'Crediteuren', 'liability'],
        ['1700', 'Te betalen BTW', 'liability'],
        ['2000', 'Eigen vermogen', 'equity'],
        ['4000', 'Omzet', 'income'],
        ['4500', 'Inkoopkosten', 'expense'],
        ['4600', 'Algemene kosten', 'expense'],
    ];

    public static function accounts(int $company_id): array {
        global $wpdb;
        AVBK_Companies::company($company_id);
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}avb_company_accounts WHERE company_id = %d ORDER BY code", $company_id));
    }

    public static function account(int $company_id, int $account_id): object {
        global $wpdb;
        AVBK_Companies::company($company_id);
        $account = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}avb_company_accounts WHERE company_id = %d AND id = %d", $company_id, $account_id));
        if (!$account) {
            throw new \RuntimeException('Grootboekrekening niet gevonden bij deze administratie.');
        }
        return $account;
    }

    /** Seed the starter chart once; refuses once any account already exists so it never silently duplicates a company's own numbering. */
    public static function seed_default_accounts(int $company_id): int {
        AVBK_Companies::company($company_id, 'import');
        if (self::accounts($company_id)) {
            throw new \RuntimeException('Dit bedrijf heeft al een rekeningschema.');
        }
        foreach (self::DEFAULT_ACCOUNTS as [$code, $name, $type]) {
            self::add_account($company_id, $code, $name, $type);
        }
        return count(self::DEFAULT_ACCOUNTS);
    }

    public static function add_account(int $company_id, string $code, string $name, string $type): int {
        global $wpdb;
        AVBK_Companies::company($company_id, 'import');
        $code = trim($code); $name = trim($name);
        if ($code === '' || !preg_match('/^[A-Za-z0-9.\-]{1,20}$/D', $code)) {
            throw new \RuntimeException('Vul een geldige rekeningcode in (letters, cijfers, punt of streepje, maximaal 20 tekens).');
        }
        if ($name === '' || mb_strlen($name) > 190) {
            throw new \RuntimeException('Vul een naam voor de grootboekrekening in.');
        }
        if (!isset(self::ACCOUNT_TYPES[$type])) {
            throw new \RuntimeException('Kies een geldig rekeningtype.');
        }
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}avb_company_accounts WHERE company_id = %d AND code = %s", $company_id, $code))) {
            throw new \RuntimeException('Deze rekeningcode bestaat al bij deze administratie.');
        }
        if (!$wpdb->insert($wpdb->prefix . 'avb_company_accounts', [
            'company_id' => $company_id, 'code' => $code, 'name' => $name, 'account_type' => $type,
            'is_active' => 1, 'created_by' => get_current_user_id(),
        ])) {
            throw new \RuntimeException('De grootboekrekening is niet opgeslagen.');
        }
        return (int) $wpdb->insert_id;
    }

    /** Deactivating only blocks new postings; past journal lines on this account keep their history. */
    public static function deactivate_account(int $company_id, int $account_id): void {
        global $wpdb;
        AVBK_Companies::company($company_id, 'import');
        self::account($company_id, $account_id);
        if ($wpdb->update($wpdb->prefix . 'avb_company_accounts', ['is_active' => 0], ['id' => $account_id, 'company_id' => $company_id]) === false) {
            throw new \RuntimeException('De grootboekrekening kon niet worden gedeactiveerd.');
        }
    }

    private static function parse_amount(string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (!preg_match('/^\d{1,12}(?:[.,]\d{1,2})?$/D', $value)) {
            throw new \RuntimeException('Vul per boekingsregel een positief bedrag in met maximaal twee decimalen.');
        }
        return (int) round((float) str_replace(',', '.', $value) * 100);
    }

    /** @param array<int, array{account_id:int, debit:string, credit:string, note?:string}> $lines */
    public static function add_entry(int $company_id, string $entry_date, string $description, string $reference, array $lines): int {
        global $wpdb;
        AVBK_Companies::company($company_id, 'import');
        $entry_date = trim($entry_date); $description = trim($description); $reference = trim($reference);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $entry_date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \RuntimeException('Vul een geldige boekingsdatum in.');
        }
        if ($description === '' || mb_strlen($description) > 500) {
            throw new \RuntimeException('Vul een omschrijving van maximaal 500 tekens in.');
        }
        if (mb_strlen($reference) > 255) {
            throw new \RuntimeException('De referentie mag maximaal 255 tekens bevatten.');
        }
        if (count($lines) < 2) {
            throw new \RuntimeException('Een boeking heeft minstens twee regels.');
        }
        $accounts = array_column(self::accounts($company_id), null, 'id');
        $prepared = []; $total_debit = 0; $total_credit = 0;
        foreach ($lines as $line) {
            $account_id = (int) ($line['account_id'] ?? 0);
            if (!isset($accounts[$account_id])) {
                throw new \RuntimeException('Kies een geldige grootboekrekening voor elke regel.');
            }
            if (!$accounts[$account_id]->is_active) { throw new \RuntimeException('Rekening "' . $accounts[$account_id]->name . '" is niet actief en kan niet worden gebruikt.'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text exception with the account's own name; escaped with esc_html() by admin/company-ledger.php when displayed
            }            $debit = self::parse_amount((string) ($line['debit'] ?? ''));
            $credit = self::parse_amount((string) ($line['credit'] ?? ''));
            if (($debit > 0) === ($credit > 0)) {
                throw new \RuntimeException('Vul per regel óf een debetbedrag óf een creditbedrag in, niet beide en niet geen van beide.');
            }
            $note = trim((string) ($line['note'] ?? ''));
            if (mb_strlen($note) > 255) {
                throw new \RuntimeException('De toelichting per regel mag maximaal 255 tekens bevatten.');
            }
            $total_debit += $debit; $total_credit += $credit;
            $prepared[] = ['account_id' => $account_id, 'debit_cents' => $debit, 'credit_cents' => $credit, 'line_note' => $note];
        }
        if ($total_debit !== $total_credit) {
            throw new \RuntimeException('Debet en credit moeten gelijk zijn; het verschil is € ' . number_format(abs($total_debit - $total_credit) / 100, 2, ',', '.') . '.');
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Kan de boeking niet starten.');
        }
        try {
            $next_number = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(entry_number), 0) + 1 FROM {$wpdb->prefix}avb_company_journal_entries WHERE company_id = %d", $company_id));
            if (!$wpdb->insert($wpdb->prefix . 'avb_company_journal_entries', [
                'company_id' => $company_id, 'entry_number' => $next_number, 'entry_date' => $entry_date,
                'description' => $description, 'reference' => $reference, 'created_by' => get_current_user_id(),
            ])) {
                throw new \RuntimeException('De boeking is niet opgeslagen.');
            }
            $entry_id = (int) $wpdb->insert_id;
            foreach ($prepared as $line) {
                if (!$wpdb->insert($wpdb->prefix . 'avb_company_journal_lines', $line + ['entry_id' => $entry_id])) {
                    throw new \RuntimeException('De boekingsregels zijn niet opgeslagen.');
                }
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('De boeking is niet opgeslagen.');
            }
            return $entry_id;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public static function entries(int $company_id): array {
        global $wpdb;
        AVBK_Companies::company($company_id);
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}avb_company_journal_entries WHERE company_id = %d ORDER BY entry_number", $company_id));
    }

    public static function entry(int $company_id, int $entry_id): object {
        global $wpdb;
        AVBK_Companies::company($company_id);
        $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}avb_company_journal_entries WHERE company_id = %d AND id = %d", $company_id, $entry_id));
        if (!$entry) {
            throw new \RuntimeException('Boeking niet gevonden bij deze administratie.');
        }
        return $entry;
    }

    public static function lines(int $company_id, int $entry_id): array {
        global $wpdb;
        self::entry($company_id, $entry_id);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}avb_company_journal_lines WHERE entry_id = %d ORDER BY id", $entry_id));
        $accounts = array_column(self::accounts($company_id), null, 'id');
        foreach ($rows as $row) {
            $account = $accounts[(int) $row->account_id] ?? null;
            $row->account_code = $account->code ?? '?'; $row->account_name = $account->name ?? 'Onbekende rekening';
        }
        return $rows;
    }

    /** Entries are never edited or deleted once posted; voiding records who and why, keeping the full history visible. */
    public static function void_entry(int $company_id, int $entry_id, string $reason): void {
        global $wpdb;
        AVBK_Companies::company($company_id, 'import');
        $entry = self::entry($company_id, $entry_id);
        if ($entry->voided_at !== null) {
            throw new \RuntimeException('Deze boeking is al teruggedraaid.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \RuntimeException('Vul een reden voor het terugdraaien in (maximaal 500 tekens).');
        }
        if ($wpdb->update($wpdb->prefix . 'avb_company_journal_entries', [
            'voided_by' => get_current_user_id(), 'voided_at' => current_time('mysql'), 'void_reason' => $reason,
        ], ['id' => $entry_id, 'company_id' => $company_id]) === false) {
            throw new \RuntimeException('De boeking kon niet worden teruggedraaid.');
        }
    }
}
