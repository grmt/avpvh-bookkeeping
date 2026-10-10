#!/usr/bin/env php
<?php
/**
 * CLI script om QR-flyers te genereren (SVG, HTML, PDF) en direct af te drukken.
 *
 * Gebruik:
 *   php bin/print-qr-flyer.php --preset=boek
 *   php bin/print-qr-flyer.php --preset=all --print
 *   php bin/print-qr-flyer.php --preset=tshirt --print --copies=2
 *   php bin/print-qr-flyer.php --url="https://avpvh.nl/voorbeeld" --title="Voorbeeld Titel" --print
 */

declare(strict_types=1);

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Fout: Composer autoload niet gevonden. Draai eerst 'composer install'.\n");
    exit(1);
}
require_once $autoloadPath;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\Common\EccLevel;

$presets = [
    'boek' => [
        'name'         => 'boek',
        'title'        => '50 Jaar Jubileumboek',
        'subtitle'     => 'Bestel het jubileumboek "DoorGraven" eenvoudig online via je smartphone',
        'url'          => 'https://avpvh.nl/boek',
        'display_url'  => 'avpvh.nl/boek',
        'instructions' => 'Scan de QR-code met je smartphone camera om direct naar de bestelpagina te gaan.',
        'badge'        => '1976 – 2026 • 50 Jaar Archeologie in Weert en omstreken',
    ],
    'tshirt' => [
        'name'         => 'tshirt',
        'title'        => '50 Jaar Jubileumkleding',
        'subtitle'     => 'Bestel T-shirts en hoodies in jouw favoriete design, kleur en maat online',
        'url'          => 'https://avpvh.nl/tshirt',
        'display_url'  => 'avpvh.nl/tshirt',
        'instructions' => 'Scan de QR-code met je smartphone camera om direct naar de bestelpagina te gaan.',
        'badge'        => '1976 – 2026 • 50 Jaar Archeologie in Weert en omstreken',
    ],
    'foto-delen' => [
        'name'         => 'foto-delen',
        'title'        => "Foto's & Herinneringen Delen",
        'subtitle'     => "Deel eenvoudig jouw foto's en herinneringen van 50 jaar opgravingen en activiteiten",
        'url'          => 'https://avpvh.nl/foto-delen',
        'display_url'  => 'avpvh.nl/foto-delen',
        'instructions' => "Scan de QR-code met je smartphone camera om direct foto's en herinneringen te uploaden.",
        'badge'        => '1976 – 2026 • 50 Jaar Archeologie in Weert en omstreken',
    ],
];
// Alias voor foto-delen
$presets['share'] = $presets['foto-delen'];

// Opties parsen
$options = getopt('h', [
    'help',
    'preset:',
    'url:',
    'display-url:',
    'title:',
    'subtitle:',
    'badge:',
    'instructions:',
    'output-dir:',
    'print',
    'printer:',
    'copies:',
]);

if (isset($options['h']) || isset($options['help'])) {
    showHelp();
    exit(0);
}

$outputDir = $options['output-dir'] ?? (__DIR__ . '/../output/flyers');
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$troffelSvgPath = __DIR__ . '/../assets/images/troffel.svg';
$troffelSvg = file_exists($troffelSvgPath) ? file_get_contents($troffelSvgPath) : '';

$printer = $options['printer'] ?? detectPrinter();
$doPrint = isset($options['print']);
$copies  = max(1, (int)($options['copies'] ?? 1));

// Bepaal welke items we genereren
$itemsToProcess = [];

if (isset($options['preset'])) {
    $presetKey = strtolower(trim((string)$options['preset']));
    if ($presetKey === 'all') {
        $itemsToProcess = [
            $presets['boek'],
            $presets['tshirt'],
            $presets['foto-delen'],
        ];
    } elseif (isset($presets[$presetKey])) {
        $itemsToProcess = [$presets[$presetKey]];
    } else {
        fwrite(STDERR, "Onbekende preset: '{$presetKey}'. Beschikbaar: boek, tshirt, foto-delen, all\n");
        exit(1);
    }
} elseif (isset($options['url'])) {
    $url = trim((string)$options['url']);
    $displayUrl = $options['display-url'] ?? preg_replace('#^https?://(www\.)?#i', '', rtrim($url, '/'));
    $title = $options['title'] ?? 'Informatie & Aanmelden';
    $subtitle = $options['subtitle'] ?? 'Scan de QR-code met je smartphone om direct te openen';
    $name = preg_replace('/[^a-z0-9_-]/i', '-', strtolower($title));
    $name = trim(preg_replace('/-+/', '-', $name), '-');

    $itemsToProcess[] = [
        'name'         => $name ?: 'flyer',
        'title'        => $title,
        'subtitle'     => $subtitle,
        'url'          => $url,
        'display_url'  => $displayUrl,
        'instructions' => $options['instructions'] ?? 'Scan de QR-code met je smartphone camera om direct naar de pagina te gaan.',
        'badge'        => $options['badge'] ?? '1976 – 2026 • 50 Jaar Archeologie in Weert en omstreken',
    ];
} else {
    fwrite(STDERR, "Fout: Geef een --preset=<naam> of een --url=<url> op.\n");
    fwrite(STDERR, "Draai met --help voor alle opties.\n");
    exit(1);
}

echo "\n========================================\n";
echo "  AVPvH QR Flyer Generator & Print Tool \n";
echo "========================================\n\n";

foreach ($itemsToProcess as $item) {
    echo "▶ Verwerken: {$item['title']} ({$item['display_url']})\n";

    // 1. QR SVG genereren
    $qrSvg = generateQrSvg($item['url']);
    $svgFile = $outputDir . '/' . $item['name'] . '-qr.svg';
    file_put_contents($svgFile, $qrSvg);
    echo "  ✔ QR Vector SVG: {$svgFile}\n";

    // 2. HTML Flyer samenstellen
    $htmlContent = renderFlyerHtml($item, $qrSvg, $troffelSvg);
    $htmlFile = $outputDir . '/' . $item['name'] . '-flyer.html';
    file_put_contents($htmlFile, $htmlContent);
    echo "  ✔ HTML Flyer:    {$htmlFile}\n";

    // 3. Vector PDF genereren via headless Chrome
    $pdfFile = $outputDir . '/' . $item['name'] . '-flyer.pdf';
    $chromeCmd = sprintf(
        'google-chrome --headless --disable-gpu --no-pdf-header-footer --print-to-pdf=%s %s 2>&1',
        escapeshellarg($pdfFile),
        escapeshellarg($htmlFile)
    );
    exec($chromeCmd, $chromeOutput, $chromeExit);
    if ($chromeExit !== 0 || !file_exists($pdfFile)) {
        fwrite(STDERR, "  ✖ Fout bij genereren PDF met Chrome: " . implode("\n", $chromeOutput) . "\n");
        continue;
    }
    echo "  ✔ Print-PDF:     {$pdfFile} (" . number_format(filesize($pdfFile) / 1024, 1) . " KB)\n";

    // 4. Afdrukken indien gevraagd
    if ($doPrint) {
        if (!$printer) {
            fwrite(STDERR, "  ✖ Geen geschikte printer gevonden om af te drukken.\n");
            continue;
        }
        $printCmd = sprintf(
            'lp -d %s -o media=A4 -o orientation-requested=3 -o portrait -o fit-to-page -n %d %s 2>&1',
            escapeshellarg($printer),
            $copies,
            escapeshellarg($pdfFile)
        );
        exec($printCmd, $printOutput, $printExit);
        if ($printExit === 0) {
            echo "  🖨 Afgedrukt naar '{$printer}' ({$copies}x): " . implode(' ', $printOutput) . "\n";
        } else {
            fwrite(STDERR, "  ✖ Fout bij afdrukken: " . implode(' ', $printOutput) . "\n");
        }
    }

    echo "\n";
}

echo "Klaar! Bestanden opgeslagen in: {$outputDir}\n\n";

// -------------------------------------------------------------
// Helper functies
// -------------------------------------------------------------

function generateQrSvg(string $url): string {
    $qrOptions = new QROptions([
        'outputType'   => QROutputInterface::MARKUP_SVG,
        'eccLevel'     => EccLevel::H,
        'outputBase64' => false,
        'addQuietzone' => true,
    ]);

    $svg = (new QRCode($qrOptions))->render($url);
    // Zorg voor responsive/vaste viewbox dimensies
    return preg_replace('/<svg /', '<svg width="100%" height="100%" ', $svg, 1);
}

function renderFlyerHtml(array $item, string $qrSvg, string $troffelSvg): string {
    $escapedTitle        = htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8');
    $escapedSubtitle     = htmlspecialchars($item['subtitle'], ENT_QUOTES, 'UTF-8');
    $escapedDisplayUrl   = htmlspecialchars($item['display_url'], ENT_QUOTES, 'UTF-8');
    $escapedInstructions = htmlspecialchars($item['instructions'], ENT_QUOTES, 'UTF-8');
    $escapedBadge        = htmlspecialchars($item['badge'], ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<title>{$escapedTitle}</title>
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
  .qr-box svg {
    width: 320px;
    height: 320px;
    display: block;
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
      {$troffelSvg}
    </div>
    <div class="org-line-1">Archeologische Vereniging</div>
    <div class="org-line-2">Philips van Horne</div>
  </div>

  <h1>{$escapedTitle}</h1>
  <p class="subtitle">{$escapedSubtitle}</p>

  <div class="qr-box">
    {$qrSvg}
  </div>

  <div class="url-label">{$escapedDisplayUrl}</div>
  <div class="instructions">{$escapedInstructions}</div>

  <div class="badge">{$escapedBadge}</div>
</body>
</html>
HTML;
}

function detectPrinter(): ?string {
    exec('lpstat -p 2>&1', $lines, $exit);
    if ($exit !== 0 || empty($lines)) {
        return null;
    }
    // Voorkeur voor Epson printer indien beschikbaar
    foreach ($lines as $line) {
        if (preg_match('/^printer\s+([^\s]+)/', $line, $m)) {
            if (stripos($m[1], 'EPSON') !== false) {
                return $m[1];
            }
        }
    }
    // Anders de eerste beschikbare printer
    foreach ($lines as $line) {
        if (preg_match('/^printer\s+([^\s]+)/', $line, $m)) {
            return $m[1];
        }
    }
    return null;
}

function showHelp(): void {
    echo <<<HELP
AVPvH QR Flyer Generator & Print Tool
======================================
Genereert vector QR-codes, responsive HTML-flyers en drukklare A4 PDF's,
met optionele directe aansturing van de printer.

Gebruik:
  php bin/print-qr-flyer.php [opties]

Opties voor presets:
  --preset=boek        Genereer flyer voor het Jubileumboek (avpvh.nl/boek)
  --preset=tshirt      Genereer flyer voor Jubileumkleding (avpvh.nl/tshirt)
  --preset=foto-delen  Genereer flyer voor Foto's Delen (avpvh.nl/foto-delen)
  --preset=all         Genereer alle drie de flyers in één keer

Opties voor custom flyers:
  --url=<url>          Doel-URL voor de QR-code (bijv. https://avpvh.nl/congres)
  --display-url=<tekst>Zichtbare URL onder de QR-code (bijv. avpvh.nl/congres)
  --title=<titel>      Hoofdtitel van de flyer
  --subtitle=<tekst>   Ondertitel / toelichting
  --badge=<tekst>      Tekst in de onderste badge

Afdruk-opties:
  --print              Stuur de gegenereerde PDF direct naar de printer via CUPS (lp)
  --printer=<naam>     Specifieke printer (standaard: automatisch gedetecteerd, bijv. EPSON_ET_2860_Series)
  --copies=<aantal>    Aantal exemplaren om af te drukken (standaard: 1)
  --output-dir=<map>   Map voor gegenereerde bestanden (standaard: output/flyers)

Voorbeelden:
  # Genereer alle 3 de flyers zonder af te drukken:
  php bin/print-qr-flyer.php --preset=all

  # Genereer en print de T-shirt flyer (2 exemplaren):
  php bin/print-qr-flyer.php --preset=tshirt --print --copies=2

  # Custom flyer genereren:
  php bin/print-qr-flyer.php --url="https://avpvh.nl/congres" --title="Congresdag Paterkerk" --print

HELP;
}
