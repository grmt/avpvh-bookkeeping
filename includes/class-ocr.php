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
    const LINE_BREAK = '/\r\n|\r|\n/';

    /** Whether a docling service or a Google service account is configured, so OCR can be tried at all. */
    public static function available(): bool {
        return self::engines() !== [];
    }

    /**
     * The configured OCR engines, the default first: code => name shown to
     * the member when they choose another one for a receipt.
     *
     * @return array<string, string>
     */
    public static function engines(): array {
        $engines = [];
        if (self::docling_url() !== null) {
            $engines['docling'] = 'Docling (eigen server)';
        }
        $key = json_decode((string) get_option('avbk_gcp_service_account_json', ''), true);
        if (is_array($key) && !empty($key['private_key']) && !empty($key['client_email'])) {
            $engines['google'] = 'Google';
        }
        return $engines;
    }

    /**
     * Base URL of a self-hosted docling-serve, from the avbk_docling_url
     * option (e.g. http://hetzner-ocr-docling-serve-1:5001), or null. When
     * set, documents stay on the own server instead of going to Google.
     */
    public static function docling_url(): ?string {
        $url = trim((string) get_option('avbk_docling_url', ''));
        return preg_match('#^https?://[^\s/]+#i', $url) ? rtrim($url, '/') : null;
    }

    /** Text of a PDF or image via docling-serve's /v1/convert/file, or null on any failure. */
    private static function docling_text(string $base_url, string $file_path, int $timeout): ?string {
        $boundary = 'avbk' . bin2hex(random_bytes(12));
        $fields = [['to_formats', 'text'], ['ocr_lang', 'nl'], ['ocr_lang', 'en']];
        $body = '';
        foreach ($fields as [$name, $value]) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        $mime = mime_content_type($file_path) ?: 'application/octet-stream';
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"files\"; filename=\"" . basename($file_path) . "\"\r\nContent-Type: $mime\r\n\r\n"
            . file_get_contents($file_path) . "\r\n--$boundary--\r\n";
        $response = wp_remote_post($base_url . '/v1/convert/file', [
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            'body'    => $body,
            'timeout' => $timeout,
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $text = $data['document']['text_content'] ?? null;
        return is_string($text) ? $text : null;
    }

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

    /**
     * Raw OCR text for an image or PDF on disk, or null on any failure
     * (network, auth, quota — always non-fatal, caller falls back to
     * manual entry). Docling when configured, else Google; $engine
     * ('docling' or 'google', see engines()) picks one. $timeout is
     * for docling, which runs on CPU and can take a while on a scan.
     */
    public static function extract_text(string $file_path, int $timeout = 30, ?string $engine = null): ?string {
        if (!file_exists($file_path) || ($engine !== null && !isset(self::engines()[$engine]))) {
            return null;
        }
        $docling = self::docling_url();
        return $docling !== null && $engine !== 'google'
            ? self::docling_text($docling, $file_path, $timeout)
            : self::google_text($file_path);
    }

    /** Text of an image or PDF via Google Document AI, or null on any failure. */
    private static function google_text(string $file_path): ?string {
        $token = self::get_access_token();
        if (!$token) {
            return null;
        }
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
                    'mimeType' => mime_content_type($file_path) ?: 'image/jpeg',
                ],
            ]),
            'timeout' => 30,
        ]);
        $body = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
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
        $lines = preg_split(self::LINE_BREAK, $text) ?: [];

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
        foreach ([
            '/(?<!\d)(\d{1,2})\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(\d{4}|\d{2})(?!\d)/',
            '/(?<!\d)(\d{1,2})[\s.\-\/]+(\d{1,2})[\s.\-\/]+((?:19|20)\d{2})(?!\d)/',
        ] as $pattern) {
            $found = self::first_dmy_date($text, $pattern);
            if ($found !== null) {
                return $found;
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

    private static function first_dmy_date(string $text, string $pattern): ?string {
        if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return null;
        }
        foreach ($matches as $m) {
            $year = (int) $m[3];
            if ($year < 100) {
                $year += $year < 70 ? 2000 : 1900;
            }
            if (checkdate((int) $m[2], (int) $m[1], $year)) {
                return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
            }
        }
        return null;
    }

    /**
     * Everything that could be the amount, date or shop, for the member
     * to pick from when the best guess is wrong: amounts largest first,
     * dates and shop lines in the order they appear.
     *
     * @return array{amounts: float[], dates: string[], stores: string[]}
     */
    public static function candidates(string $text): array {
        $amounts = [];
        if (preg_match_all('/(?<!\d)(\d{1,4}[.,]\d{2})(?!\d)/', $text, $matches)) {
            $amounts = array_values(array_unique(array_map([self::class, 'to_float'], $matches[1]), SORT_REGULAR));
            rsort($amounts);
        }
        $dates = [];
        $stores = [];
        foreach (preg_split(self::LINE_BREAK, $text) ?: [] as $line) {
            $date = self::guess_date($line);
            if ($date !== null && !in_array($date, $dates, true)) {
                $dates[] = $date;
            }
            $line = trim($line);
            if (count($stores) < 5 && preg_match('/[a-zA-Z]{3,}/', $line) && !in_array($line, $stores, true)) {
                $stores[] = mb_substr($line, 0, 100);
            }
        }
        return ['amounts' => array_slice($amounts, 0, 8), 'dates' => array_slice($dates, 0, 5), 'stores' => $stores];
    }

    /**
     * Best-guess store/vendor name — the first line containing real text,
     * which on virtually every receipt layout is the business name printed
     * above the address/phone/date block. A rough heuristic (see
     * guess_date()'s docblock for why it's good enough here).
     */
    public static function guess_store(string $text): ?string {
        $lines = preg_split(self::LINE_BREAK, $text) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('/[a-zA-Z]{3,}/', $line)) {
                return mb_substr($line, 0, 100);
            }
        }
        return null;
    }
}
