<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-exceptions.php';

/** Private host scheduler settings, payment reminders and delivery receipts. */
class AVBK_Company_Payroll_Automation {
    public static function settings(int $company_id): array {
        AVBK_Companies::company($company_id);
        return get_option('avbk_payroll_automation_' . $company_id, []);
    }

    public static function configure(int $company_id, string $recipient, int $day = 27, int $hour = 9): void {
        AVBK_Companies::company($company_id, 'access');
        if (!is_email($recipient) || $day < 1 || $day > 28 || $hour < 0 || $hour > 23) {
            throw new AVBK_Company_Exception('Ongeldige maandelijkse herinnering.');
        }
        $settings = ['enabled' => true, 'recipient' => $recipient, 'day' => $day, 'hour' => $hour, 'timezone' => 'Europe/Amsterdam'];
        if (!update_option('avbk_payroll_automation_' . $company_id, $settings, false) && self::settings($company_id) !== $settings) {
            throw new AVBK_Company_Exception('Herinnering kon niet worden opgeslagen.');
        }
    }

    /** Strict references prevent assigning an unrelated same-amount transfer to a tax declaration. */
    public static function paid(int $company_id, array $payment): ?object {
        global $wpdb;
        AVBK_Companies::company($company_id);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, transaction_date, counterparty_iban, description FROM {$wpdb->prefix}avb_company_transactions WHERE company_id = %d AND direction = 'out' AND amount = %s ORDER BY transaction_date DESC", $company_id, number_format($payment['amount_cents'] / 100, 2, '.', '')));
        $reference = $payment['reference'];
        $pattern = $payment['kind'] === 'payroll_tax' ? '/(?<!\d)' . implode('[\s.-]*', str_split($reference)) . '(?!\d)/' : '/' . preg_quote($reference, '/') . '/iu';
        foreach ($rows as $row) {
            if (AVBK_Companies::normalize_iban($row->counterparty_iban) === $payment['iban'] && preg_match($pattern, $row->description)) {
                return $row;
            }
        }
        return null;
    }

    public static function reminder(int $company_id, string $date): array {
        $company = AVBK_Companies::company($company_id, 'access');
        if (!AVBK_Bank_Import_Layout::parse_date($date, 'Y-m-d')) {
            throw new AVBK_Company_Exception('Ongeldige herinneringsdatum.');
        }
        $settings = self::settings($company_id);
        if (empty($settings['enabled']) || !AVBK_Company_Payroll::enabled($company_id)) {
            throw new AVBK_Company_Exception('Automatische salarisverwerking is uitgeschakeld.');
        }
        $year = (int) substr($date, 0, 4); $end = (new \DateTimeImmutable($date))->modify('last day of this month')->format('Y-m-d');
        $all = AVBK_Company_Payroll::payments($company_id, $year);
        if ($year > 2000) {
            $all = AVBK_Company_Payroll::payments($company_id, $year - 1) + $all;
        }
        $items = []; $holds = [];
        foreach ($all as $period => $payments) {
            foreach ($payments as $payment) {
                self::sort_payment($company_id, (string) $period, $payment, [$date, $end], $items, $holds);
            }
        }
        return ['company' => $company->name, 'company_iban' => $company->iban, 'date' => $date, 'recipient' => $settings['recipient'], 'items' => $items, 'holds' => $holds];
    }

    /**
     * One payroll payment into the reminder: under review (held, up to this
     * month), due by the end of the month (an item, with its payment date
     * if paid), or later (left out).
     *
     * @param array{0: string, 1: string} $dates the reminder date and the last day of its month
     */
    private static function sort_payment(int $company_id, string $period, array $payment, array $dates, array &$items, array &$holds): void {
        [$date, $end] = $dates;
        if ($payment['state'] === 'review') {
            if ($period <= substr($date, 0, 7)) {
                $holds[] = ['period' => $period, 'payment' => $payment];
            }
            return;
        }
        $due = $payment['due_date'] ?? ($period . '-01');
        if ($due > $end) {
            return;
        }
        $match = self::paid($company_id, $payment);
        $items[] = ['period' => $period, 'payment' => $payment, 'paid_on' => $match->transaction_date ?? null];
    }

    public static function html(array $reminder): string {
        $html = '<h2>Salaris en loonheffingen · ' . esc_html($reminder['company']) . '</h2><p>Herinnering ' . esc_html($reminder['date']) . '. Betaal vanaf ' . esc_html($reminder['company_iban']) . '. De datum op de opgave is de deadline; de QR plant geen uitvoerdatum in.</p>';
        $open = 0;
        foreach ($reminder['items'] as $i => $item) {
            $p = $item['payment'];
            $html .= '<h3>' . esc_html(AVBK_Company_Payroll::KINDS[$p['kind']] . ' · loontijdvak ' . $item['period']) . '</h3>';
            if ($item['paid_on']) { $html .= '<p>Betaling gevonden op ' . esc_html($item['paid_on']) . '; geen QR toegevoegd.</p>'; continue; }
            $open++;
            $html .= '<p>Bedrag: EUR ' . esc_html(number_format($p['amount_cents'] / 100, 2, ',', '.')) . '<br>Ontvanger: ' . esc_html($p['name']) . '<br>IBAN: ' . esc_html($p['iban']) . '<br>Kenmerk / omschrijving: ' . esc_html($p['reference']) . '<br>Uiterste betaaldatum: ' . esc_html($p['due_date'] ?? 'volgens salarisafspraak') . '</p><p>' . esc_html($p['note'] ?? '') . '</p><img width="280" height="280" alt="Betaal-QR" src="cid:avbk-payroll-' . $i . '">';
        }
        if (!$open) {
            $html .= '<p>Geen betaal-QR nodig op basis van de beschikbare betaalgegevens.</p>';
        }
        foreach ($reminder['holds'] as $hold) {
            $html .= '<h3>Eerst afstemmen · ' . esc_html($hold['period']) . '</h3><p>' . esc_html($hold['payment']['note'] ?? '') . '</p>';
        }
        $html .= '<p>Controleer in je bank of een betaling al is uitgevoerd of ingepland. Scannen en bevestigen gebeurt in je bank; deze herinnering voert geen betalingen uit.</p><p><a href="' . esc_url(home_url('/salaris/')) . '">Salarisadministratie openen</a></p>';
        return $html;
    }

    /**
     * A QR image file per unpaid item, keyed by its content id in the mail.
     * Every file created is added to $files, also when a later one fails,
     * so the caller can always remove them.
     */
    private static function qr_images(array $items, array &$files): array {
        $images = [];
        foreach ($items as $i => $item) {
            if ($item['paid_on']) {
                continue;
            }
            $png = AVBK_QR::png(AVBK_Company_Payroll::payload($item['payment']));
            $file = wp_tempnam('avbk-payroll-qr.png');
            if (!$png || !$file) {
                throw new AVBK_Company_Exception('Kan geen QR voor e-mail maken.');
            }
            $files[] = $file;
            if (file_put_contents($file, $png) !== strlen($png)) {
                throw new AVBK_Company_Exception('Kan de QR-bijlage niet opslaan.');
            }
            $images['avbk-payroll-' . $i] = $file;
        }
        return $images;
    }

    public static function send(int $company_id): string {
        if (!defined('WP_CLI') || !WP_CLI) {
            throw new AVBK_Company_Exception('Herinneringen worden door de hostscheduler verstuurd.');
        }
        AVBK_Companies::company($company_id, 'access');
        $settings = self::settings($company_id); $now = new \DateTimeImmutable('now', new \DateTimeZone($settings['timezone'] ?? 'Europe/Amsterdam'));
        if ((int) $now->format('j') < ($settings['day'] ?? 27) || (int) $now->format('G') < ($settings['hour'] ?? 9)) {
            return 'not_due';
        }
        $reminder = self::reminder($company_id, $now->format('Y-m-d'));
        $key = 'avbk_payroll_reminder_' . $company_id . '_' . $now->format('Y_m');
        if (!add_option($key, ['state' => 'dispatching', 'at' => $now->format(DATE_ATOM)], '', false)) {
            return (get_option($key)['state'] ?? '') === 'sent' ? 'already_sent' : 'delivery_needs_review';
        }
        $files = []; $images = [];
        $embed = function ($mailer) use (&$images) {
            foreach ($images as $cid => $file) {
                $mailer->addEmbeddedImage($file, $cid, $cid . '.png', 'base64', 'image/png');
            }
        };
        try {
            $images = self::qr_images($reminder['items'], $files);
            add_action('phpmailer_init', $embed);
            if (!wp_mail($reminder['recipient'], 'Salaris en loonheffingen · ' . $reminder['company'] . ' · ' . $now->format('Y-m'), self::html($reminder), ['Content-Type: text/html; charset=UTF-8'])) {
                throw new AVBK_Company_Exception('De betaalherinnering kon niet worden verzonden.');
            }
            update_option($key, ['state' => 'sent', 'at' => $now->format(DATE_ATOM), 'recipient' => $reminder['recipient'], 'qr_count' => count($images)], false);
            return 'sent';
        } catch (\Throwable $e) {
            update_option($key, ['state' => 'needs_review', 'at' => $now->format(DATE_ATOM)], false);
            throw $e;
        } finally {
            remove_action('phpmailer_init', $embed);
            foreach ($files as $file) {
                wp_delete_file($file);
            }
        }
    }
}
