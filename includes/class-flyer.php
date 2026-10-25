<?php
defined('ABSPATH') || exit;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\Common\EccLevel;

/**
 * Generates high-contrast QR vector SVGs and printable A4 HTML flyers for
 * club presets (book, clothing, photo sharing, payment) or custom URLs.
 * Shared by the admin UI (`avbk-flyers`) and `bin/print-qr-flyer.php`.
 */
class AVBK_Flyer {

    public const DEFAULT_BADGE = '1976 – 2026 • 50 Jaar Archeologie in Weert en omstreken';
    public const DEFAULT_TITLE = 'Informatie & Aanmelden';
    public const DEFAULT_SUBTITLE = 'Scan de QR-code met je smartphone om direct te openen';
    public const DEFAULT_INSTRUCTIONS = 'Scan de QR-code met je smartphone camera om direct naar de pagina te gaan.';

    /**
     * Built-in flyer presets.
     *
     * @return array<string, array<string, string>>
     */
    public static function get_presets(): array {
        $plugin_dir = defined('AVBK_PLUGIN_DIR') ? AVBK_PLUGIN_DIR : (dirname(__DIR__) . '/');
        return [
            'boek' => [
                'name'         => 'boek',
                'title'        => '50 Jaar Jubileumboek',
                'subtitle'     => 'Bestel het jubileumboek "DoorGraven" eenvoudig online via je smartphone',
                'url'          => 'https://avpvh.nl/boek',
                'display_url'  => 'avpvh.nl/boek',
                'instructions' => 'Scan de QR-code met je smartphone camera om direct naar de bestelpagina te gaan.',
                'badge'        => self::DEFAULT_BADGE,
            ],
            'tshirt' => [
                'name'         => 'tshirt',
                'title'        => '50 Jaar Jubileumkleding',
                'subtitle'     => 'Bestel T-shirts en hoodies in jouw favoriete design, kleur en maat online',
                'url'          => 'https://avpvh.nl/tshirt',
                'display_url'  => 'avpvh.nl/tshirt',
                'instructions' => 'Scan de QR-code met je smartphone camera om direct naar de bestelpagina te gaan.',
                'badge'        => self::DEFAULT_BADGE,
            ],
            'foto-delen' => [
                'name'         => 'foto-delen',
                'title'        => "Foto's & Herinneringen Delen",
                'subtitle'     => "Deel eenvoudig jouw foto's en herinneringen van 50 jaar opgravingen en activiteiten",
                'url'          => 'https://avpvh.nl/foto-delen',
                'display_url'  => 'avpvh.nl/foto-delen',
                'instructions' => "Scan de QR-code met je smartphone camera om direct foto's en herinneringen te uploaden.",
                'badge'        => self::DEFAULT_BADGE,
            ],
            'tshirts-betalen' => [
                'name'         => 'tshirts-betalen',
                'title'        => 'T-shirts betalen',
                'subtitle'     => 'Betaal je huidige bestelling van jubileumkleding eenvoudig via je smartphone',
                'url'          => 'https://www.ing.nl/payreq/m/aFbdfoIoWem2exURVabPmWck5AoW7WMT',
                'qr_image'     => $plugin_dir . 'assets/images/tshirts-betalen-qr.png',
                'display_url'  => 'avpvh.nl/t-shirts-betalen',
                'instructions' => 'Scan de QR-code met je smartphone camera om je bestaande bestelling via ING te betalen.',
                'badge'        => self::DEFAULT_BADGE,
            ],
        ];
    }

    /**
     * Resolves a preset key (supporting 'share' alias for 'foto-delen').
     */
    public static function get_preset(string $key): ?array {
        $key = strtolower(trim($key));
        if ($key === 'share') {
            $key = 'foto-delen';
        }
        $presets = self::get_presets();
        return $presets[$key] ?? null;
    }

    /**
     * Builds a normalized flyer item array from custom parameters.
     */
    public static function build_custom_item(
        string $url,
        string $title = '',
        string $subtitle = '',
        string $display_url = '',
        string $instructions = '',
        string $badge = ''
    ): array {
        $url = trim($url);
        $title = trim($title) !== '' ? trim($title) : self::DEFAULT_TITLE;
        $subtitle = trim($subtitle) !== '' ? trim($subtitle) : self::DEFAULT_SUBTITLE;
        $display_url = trim($display_url) !== ''
            ? trim($display_url)
            : (string) preg_replace('#^https?://(www\.)?#i', '', rtrim($url, '/'));
        $instructions = trim($instructions) !== '' ? trim($instructions) : self::DEFAULT_INSTRUCTIONS;
        $badge = trim($badge) !== '' ? trim($badge) : self::DEFAULT_BADGE;

        $name = (string) preg_replace('/[^a-z0-9_-]/i', '-', strtolower($title));
        $name = trim((string) preg_replace('/-+/', '-', $name), '-');

        return [
            'name'         => $name !== '' ? $name : 'flyer',
            'title'        => $title,
            'subtitle'     => $subtitle,
            'url'          => $url,
            'display_url'  => $display_url,
            'instructions' => $instructions,
            'badge'        => $badge,
        ];
    }

    /**
     * Generates a vector SVG QR code with EccLevel::H and 100% width/height attributes.
     */
    public static function generate_qr_svg(string $url): string {
        $qr_options = new QROptions([
            'outputType'   => QROutputInterface::MARKUP_SVG,
            'eccLevel'     => EccLevel::H,
            'outputBase64' => false,
            'addQuietzone' => true,
        ]);

        $svg = (new QRCode($qr_options))->render($url);
        return (string) preg_replace('/<svg /', '<svg width="100%" height="100%" ', $svg, 1);
    }

    /**
     * Loads the club's trowel SVG icon for the flyer header.
     */
    public static function get_troffel_svg(): string {
        $plugin_dir = defined('AVBK_PLUGIN_DIR') ? AVBK_PLUGIN_DIR : (dirname(__DIR__) . '/');
        $path = $plugin_dir . 'assets/images/troffel.svg';
        return file_exists($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Returns the QR markup for an item: either a base64 `<img>` when the
     * preset specifies a custom PNG (`qr_image`) or the generated vector `<svg>`.
     */
    public static function get_qr_markup(array $item, ?string $qr_svg = null): string {
        if (!empty($item['qr_image']) && file_exists($item['qr_image'])) {
            $qr_image = file_get_contents($item['qr_image']);
            if ($qr_image !== false) {
                return '<img src="data:image/png;base64,' . base64_encode($qr_image) . '" alt="QR-code om je bestelling te betalen">';
            }
        }
        return $qr_svg ?? self::generate_qr_svg((string) ($item['url'] ?? ''));
    }

    /**
     * Renders the standalone printable A4 HTML flyer for a given item.
     */
    public static function render_flyer_html(array $item, ?string $qr_markup = null, ?string $troffel_svg = null): string {
        $qr_markup   = $qr_markup ?? self::get_qr_markup($item);
        $troffel_svg = $troffel_svg ?? self::get_troffel_svg();

        $escaped_title        = htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $escaped_subtitle     = htmlspecialchars((string) ($item['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8');
        $escaped_display_url  = htmlspecialchars((string) ($item['display_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $escaped_instructions = htmlspecialchars((string) ($item['instructions'] ?? ''), ENT_QUOTES, 'UTF-8');
        $escaped_badge        = htmlspecialchars((string) ($item['badge'] ?? ''), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<title>{$escaped_title}</title>
<style>
  @page {
    size: A4 portrait;
    margin: 18mm 20mm;
  }
  * {
    box-sizing: border-box;
  }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: #1e293b;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 88vh;
    text-align: center;
    -webkit-font-smoothing: antialiased;
  }
  .header-brand {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: 20px;
  }
  .troffel-wrap {
    width: 64px;
    height: 64px;
    margin-bottom: 12px;
  }
  .troffel-wrap svg {
    width: 100%;
    height: 100%;
    display: block;
  }
  .org-line-1 {
    font-size: 16pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: #475569;
    line-height: 1.2;
  }
  .org-line-2 {
    font-size: 22pt;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 2.5px;
    color: #0f4c81;
    margin-top: 4px;
    line-height: 1.2;
  }
  h1 {
    font-size: 32pt;
    font-weight: 800;
    margin: 10px 0 10px 0;
    color: #0f172a;
    line-height: 1.2;
  }
  p.subtitle {
    font-size: 16pt;
    color: #475569;
    max-width: 580px;
    margin: 0 auto 26px auto;
    line-height: 1.4;
  }
  .qr-box {
    background: #ffffff;
    padding: 24px;
    border-radius: 16px;
    border: 3px solid #0f4c81;
    display: inline-block;
    box-shadow: 0 10px 25px rgba(0,0,0,0.06);
    margin-bottom: 22px;
    width: 368px;
    height: 368px;
  }
  .qr-box svg,
  .qr-box img {
    width: 320px;
    height: 320px;
    display: block;
  }
  .qr-box img {
    image-rendering: pixelated;
  }
  .url-label {
    font-size: 22pt;
    font-weight: 700;
    color: #0f4c81;
    margin-bottom: 8px;
    font-family: monospace;
    letter-spacing: 1px;
  }
  .instructions {
    font-size: 14pt;
    color: #64748b;
    margin-top: 8px;
    max-width: 500px;
  }
  .badge {
    display: inline-block;
    background: #f1f5f9;
    color: #334155;
    padding: 8px 18px;
    border-radius: 20px;
    font-size: 11pt;
    font-weight: 600;
    margin-top: 25px;
  }
</style>
</head>
<body>
  <div class="header-brand">
    <div class="troffel-wrap">
      {$troffel_svg}
    </div>
    <div class="org-line-1">Archeologische Vereniging</div>
    <div class="org-line-2">Philips van Horne</div>
  </div>

  <h1>{$escaped_title}</h1>
  <p class="subtitle">{$escaped_subtitle}</p>

  <div class="qr-box">
    {$qr_markup}
  </div>

  <div class="url-label">{$escaped_display_url}</div>
  <div class="instructions">{$escaped_instructions}</div>

  <div class="badge">{$escaped_badge}</div>
</body>
</html>
HTML;
    }
}
