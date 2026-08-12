<?php
defined('ABSPATH') || exit;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\Common\EccLevel;

/**
 * Builds a SEPA Credit Transfer QR code (the "EPC QR" / "BCD" payload,
 * EPC069-12) that any banking app can scan to prefill a manual bank
 * transfer — no payment-provider account needed, matching how the club
 * actually gets paid (bank transfers reconciled from a bank export), not
 * a live payment gateway.
 */
class AVBK_QR {

    /** Deterministic short reference so future payments start auto-matching (see AVBK_Matcher). */
    public static function reference_code(int $member_id): string {
        $prefix = get_option('avbk_reference_prefix', 'PVH');
        return sprintf('%s-%d', $prefix, $member_id);
    }

    /**
     * EPC069-12 "BCD" payload, one field per line. BIC (field 5) is left
     * blank — allowed for IBANs from SEPA countries, which covers every
     * transaction seen in the sample export (NL/BE).
     */
    public static function epc_payload(float $amount, string $remittance): ?string {
        $iban = trim((string) get_option('avbk_club_iban', ''));
        $name = trim((string) get_option('avbk_club_name', ''));
        if ($iban === '' || $name === '' || $amount <= 0) {
            return null;
        }

        $lines = [
            'BCD',
            '002',
            '1',                                        // character set: UTF-8
            'SCT',
            '',                                          // BIC (optional)
            mb_substr($name, 0, 70),
            str_replace(' ', '', strtoupper($iban)),
            'EUR' . number_format($amount, 2, '.', ''),
            '',                                          // purpose
            '',                                          // structured remittance (unused)
            mb_substr($remittance, 0, 140),
            '',                                          // beneficiary-to-originator info
        ];
        return implode("\n", $lines);
    }

    /** Inline <svg>...</svg> markup for a payload, or null if the QR library isn't installed / payload is empty. */
    public static function svg(string $payload): ?string {
        if (!class_exists(QRCode::class)) {
            return null;
        }
        $options = new QROptions([
            'outputType'   => QROutputInterface::MARKUP_SVG,
            'eccLevel'     => EccLevel::M,
            'outputBase64' => false,
            'addQuietzone' => true,
            'cssClass'     => 'avbk-qr',
        ]);
        return (new QRCode($options))->render($payload);
    }

    /**
     * The EPC remittance text: a human summary of what's actually being
     * paid for (open items only — "Contributie 2026, Kamp Goeblange 2026
     * (6 nachten)") followed by the auto-match reference code, so a
     * banking app's payment screen and the resulting statement line both
     * say more than a bare "PVH-91". Truncates the summary half, never the
     * reference half — that's what AVBK_Matcher::match_reference_code()
     * depends on for future auto-matching, and EPC caps this field at 140
     * characters total.
     */
    public static function remittance_for_balance(array $items, int $member_id): string {
        $reference = self::reference_code($member_id);
        $fragments = [];
        foreach ($items as $item) {
            if ($item->status === 'waived' || $item->remaining <= 0.005) {
                continue;
            }
            $parts = AVBK_DB::split_fee_description((string) $item->description);
            $qty = AVBK_DB::fee_item_quantity_label($item);
            $fragments[] = $qty ? "{$parts['base']} ({$qty})" : $parts['base'];
        }
        $summary = implode(', ', $fragments);
        if ($summary === '') {
            return $reference;
        }

        $suffix = ' - ' . $reference;
        $max_summary_len = 140 - mb_strlen($suffix);
        if (mb_strlen($summary) > $max_summary_len) {
            $summary = mb_substr($summary, 0, max(0, $max_summary_len - 1)) . '…';
        }
        return $summary . $suffix;
    }

    /** Convenience: the member's balance QR, or null if there's nothing to pay or settings are incomplete. $items (from AVBK_DB::get_member_balance()) makes the payment message describe what it's for instead of just the reference code — optional so existing callers that only have the total keep working. */
    public static function for_member_balance(int $member_id, float $balance, array $items = []): ?string {
        if ($balance <= 0) {
            return null;
        }
        $remittance = $items ? self::remittance_for_balance($items, $member_id) : self::reference_code($member_id);
        $payload = self::epc_payload($balance, $remittance);
        return $payload ? self::svg($payload) : null;
    }
}
