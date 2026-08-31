<?php
defined('ABSPATH') || exit;

/**
 * Receipt OCR via Google Cloud Document AI — reuses the same GCP project
 * and service account already used for the association's book-scanning
 * pipeline (see the separate scan/ tooling), rather than standing up a
 * new OCR service. The service account key lives only in the
 * avbk_gcp_service_account_json option (set once via wp-cli, never
 * through a form, never in git) — never exposed to the browser.
 *
 * This is a plain "OCR the whole image to text" processor, not a
 * specialised expense/invoice parser, so guess_total() below just
 * regexes the resulting text for a euro amount — always a pre-fill
 * suggestion the member/penningmeester can override, never authoritative.
 */
class AVBK_OCR {

    const PROJECT_ID   = 'dynamic-beacon-457609-k1';
    const LOCATION     = 'eu';
    const PROCESSOR_ID = '86330dffb1e6f725';
    const TOKEN_TRANSIENT = 'avbk_gcp_access_token';

    /** Bearer token for the Google API, cached in a transient (tokens are valid ~1h). */
    private static function get_access_token(): ?string {
        $cached = get_transient(self::TOKEN_TRANSIENT);
        if ($cached) {
            return $cached;
        }

        $json = get_option('avbk_gcp_service_account_json', '');
        $key = json_decode($json, true);
        if (!$key || empty($key['private_key']) || empty($key['client_email'])) {
            return null;
        }

        $now = time();
        $header = self::base64url(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = self::base64url(wp_json_encode([
            'iss'   => $key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ]));
        $signing_input = "$header.$claim";

        $signature = '';
        $ok = openssl_sign($signing_input, $signature, $key['private_key'], 'sha256WithRSAEncryption');
        if (!$ok) {
            return null;
        }
        $jwt = $signing_input . '.' . self::base64url($signature);

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            return null;
        }

        set_transient(self::TOKEN_TRANSIENT, $body['access_token'], (int) ($body['expires_in'] ?? 3000) - 60);
        return $body['access_token'];
    }

    private static function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Raw OCR text for an image file on disk, or null on any failure (network, auth, quota — always non-fatal, caller falls back to manual entry). */
    public static function extract_text(string $file_path): ?string {
        $token = self::get_access_token();
        if (!$token || !file_exists($file_path)) {
            return null;
        }

        $mime = mime_content_type($file_path) ?: 'image/jpeg';
        $endpoint = sprintf(
            'https://%s-documentai.googleapis.com/v1/projects/%s/locations/%s/processors/%s:process',
            self::LOCATION, self::PROJECT_ID, self::LOCATION, self::PROCESSOR_ID
        );

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode([
                'rawDocument' => [
                    'content'  => base64_encode(file_get_contents($file_path)),
                    'mimeType' => $mime,
                ],
            ]),
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['document']['text'] ?? null;
    }

    /**
     * Best-guess total from raw receipt OCR text — prefers an amount on
     * (or near) a line containing "totaal"/"total"/"te betalen", falls
     * back to the largest euro-looking amount found anywhere (receipts
     * usually have the total as their largest single line amount).
     * Always just a pre-fill suggestion.
     */
    public static function guess_total(string $text): ?float {
        $amount_pattern = '/(?<!\d)(\d{1,4}[.,]\d{2})(?!\d)/';
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/totaal|total|te betalen|verschuldigd/i', $line) && preg_match($amount_pattern, $line, $m)) {
                return self::to_float($m[1]);
            }
        }

        $all_amounts = [];
        foreach ($lines as $line) {
            if (preg_match_all($amount_pattern, $line, $matches)) {
                foreach ($matches[1] as $match) {
                    $all_amounts[] = self::to_float($match);
                }
            }
        }
        return $all_amounts ? max($all_amounts) : null;
    }

    private static function to_float(string $amount): float {
        return (float) str_replace(',', '.', $amount);
    }

    /**
     * Best-guess purchase date from raw receipt OCR text — the first
     * plausible dd-mm-yyyy/dd.mm.yyyy/dd/mm/yyyy (2- or 4-digit year) or
     * yyyy-mm-dd date found anywhere. Used both as a pre-fill hint and,
     * together with guess_store(), as a fuzzy duplicate-receipt signal
     * (see AVBK_DB::find_duplicate_receipt()) for photos of the same
     * paper receipt that don't hash-match byte-for-byte.
     */
    public static function guess_date(string $text): ?string {
        if (preg_match('/(?<!\d)(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4}|\d{2})(?!\d)/', $text, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            if ($year < 100) {
                $year += $year < 70 ? 2000 : 1900;
            }
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        if (preg_match('/(?<!\d)(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/', $text, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        return null;
    }

    /**
     * Best-guess store/vendor name — the first line containing real text,
     * which on virtually every receipt layout is the business name printed
     * above the address/phone/date block. A rough heuristic (see
     * guess_date()'s docblock for why it's good enough here).
     */
    public static function guess_store(string $text): ?string {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('/[a-zA-Z]{3,}/', $line)) {
                return mb_substr($line, 0, 100);
            }
        }
        return null;
    }
}
