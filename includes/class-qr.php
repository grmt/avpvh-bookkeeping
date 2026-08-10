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

    /** Convenience: the member's balance QR, or null if there's nothing to pay or settings are incomplete. */
    public static function for_member_balance(int $member_id, float $balance): ?string {
        if ($balance <= 0) {
            return null;
        }
        $payload = self::epc_payload($balance, self::reference_code($member_id));
        return $payload ? self::svg($payload) : null;
    }
}
