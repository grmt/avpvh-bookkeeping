<?php
defined('ABSPATH') || exit;

/** Configurable translation from a bank export's columns into AVBK's canonical transaction fields. */
class AVBK_Bank_Import_Layout {

    const OPTION = 'avbk_bank_import_layout';

    const DEFAULT_CONFIG = [
        'preset'             => 'auto',
        'date_column'        => '',
        'name_column'        => '',
        'iban_column'        => '',
        'amount_column'      => '',
        'direction_column'   => '',
        'description_column' => '',
        'date_format'        => 'auto',
        'decimal_separator'  => 'auto',
        'credit_values'      => 'bij,credit',
        'debit_values'       => 'af,debit',
        'csv_delimiter'      => 'auto',
    ];

    const PRESETS = [
        'ing_nl' => [
            'date_column'        => 'Datum',
            'name_column'        => 'Naam / Omschrijving',
            'iban_column'        => 'Tegenrekening',
            'amount_column'      => 'Bedrag (EUR)',
            'direction_column'   => 'Af Bij',
            'description_column' => 'Mededelingen',
            'date_format'        => 'Ymd',
            'decimal_separator'  => 'comma',
            'credit_values'      => 'bij',
            'debit_values'       => 'af',
            'csv_delimiter'      => 'semicolon',
        ],
        'ing_en' => [
            'date_column'        => 'Date',
            'name_column'        => 'Name / Description',
            'iban_column'        => 'Counterparty',
            'amount_column'      => 'Amount (EUR)',
            'direction_column'   => 'Debit/credit',
            'description_column' => 'Notifications',
            'date_format'        => 'Ymd',
            'decimal_separator'  => 'dot',
            'credit_values'      => 'credit',
            'debit_values'       => 'debit',
            'csv_delimiter'      => 'semicolon',
        ],
    ];

    public static function get_config(): array {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored + self::DEFAULT_CONFIG : self::DEFAULT_CONFIG;
    }

    public static function save_config(array $config): void {
        update_option(self::OPTION, $config + self::DEFAULT_CONFIG);
    }

    /** Resolved values used for one import; auto retains both legacy ING languages. */
    public static function resolve(?array $config = null): array {
        $config = ($config ?? self::get_config()) + self::DEFAULT_CONFIG;
        $preset = $config['preset'];
        if ($preset === 'auto') {
            return self::DEFAULT_CONFIG;
        }
        if (isset(self::PRESETS[$preset])) {
            return ['preset' => $preset] + self::PRESETS[$preset] + self::DEFAULT_CONFIG;
        }
        return $config;
    }

    public static function sanitize(array $input): array {
        $preset = sanitize_key(wp_unslash($input['preset'] ?? 'auto'));
        if (!in_array($preset, ['auto', 'ing_nl', 'ing_en', 'custom'], true)) {
            $preset = 'auto';
        }
        $config = ['preset' => $preset];
        foreach (['date_column', 'name_column', 'iban_column', 'amount_column', 'direction_column', 'description_column', 'credit_values', 'debit_values'] as $key) {
            $config[$key] = sanitize_text_field(wp_unslash($input[$key] ?? ''));
        }
        $date_format = sanitize_text_field(wp_unslash($input['date_format'] ?? 'auto'));
        $config['date_format'] = in_array($date_format, ['auto', 'Ymd', 'Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y'], true) ? $date_format : 'auto';
        $decimal = sanitize_key(wp_unslash($input['decimal_separator'] ?? 'auto'));
        $config['decimal_separator'] = in_array($decimal, ['auto', 'comma', 'dot'], true) ? $decimal : 'auto';
        $delimiter = sanitize_key(wp_unslash($input['csv_delimiter'] ?? 'auto'));
        $config['csv_delimiter'] = in_array($delimiter, ['auto', 'semicolon', 'comma', 'tab'], true) ? $delimiter : 'auto';
        return $config + self::DEFAULT_CONFIG;
    }

    /** Exact configured header first; auto mode accepts both built-in ING variants. */
    public static function value(array $row, array $layout, string $field): string {
        $column_key = $field . '_column';
        $configured = trim((string) ($layout[$column_key] ?? ''));
        if ($configured !== '' && array_key_exists($configured, $row)) {
            return trim((string) $row[$configured]);
        }
        if (($layout['preset'] ?? 'auto') !== 'auto') {
            return '';
        }
        $fallbacks = [
            'date'        => ['Datum', 'Date'],
            'name'        => ['Naam / Omschrijving', 'Name / Description'],
            'iban'        => ['Tegenrekening', 'Counterparty'],
            'amount'      => ['Bedrag (EUR)', 'Amount (EUR)'],
            'direction'   => ['Af Bij', 'Debit/credit'],
            'description' => ['Mededelingen', 'Notifications'],
        ];
        foreach ($fallbacks[$field] ?? [] as $header) {
            if (array_key_exists($header, $row)) {
                return trim((string) $row[$header]);
            }
        }
        return '';
    }

    public static function parse_date(string $raw, string $format): ?string {
        $raw = trim($raw);
        if (is_numeric($raw)) {
            $raw = sprintf('%.0f', (float) $raw);
        }
        $formats = $format === 'auto'
            ? ['Ymd', 'Y-m-d', 'd-m-Y', 'd/m/Y']
            : [$format];
        foreach ($formats as $candidate) {
            $date = \DateTimeImmutable::createFromFormat('!' . $candidate, $raw);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }
        return null;
    }

    public static function parse_amount(string $raw, string $separator): float {
        $raw = trim(str_replace(['€', ' '], '', $raw));
        if ($separator === 'comma' || ($separator === 'auto' && str_contains($raw, ','))) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif ($separator === 'dot') {
            $raw = str_replace(',', '', $raw);
        }
        return (float) $raw;
    }

    public static function parse_direction(string $raw, float $signed_amount, array $layout): string {
        $value = mb_strtolower(trim($raw));
        $credit_values = self::list_values((string) ($layout['credit_values'] ?? ''));
        $debit_values = self::list_values((string) ($layout['debit_values'] ?? ''));
        if ($value !== '' && in_array($value, $credit_values, true)) {
            return 'in';
        }
        if ($value !== '' && in_array($value, $debit_values, true)) {
            return 'out';
        }
        // Custom exports often omit an Af/Bij column and encode direction
        // solely through the amount's sign.
        return $signed_amount >= 0 ? 'in' : 'out';
    }

    private static function list_values(string $raw): array {
        return array_values(array_filter(array_map(
            fn($value) => mb_strtolower(trim($value)),
            preg_split('/[,;|]+/', $raw)
        )));
    }
}
