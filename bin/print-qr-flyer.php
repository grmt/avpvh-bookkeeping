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

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('AVBK_PLUGIN_DIR')) {
    define('AVBK_PLUGIN_DIR', dirname(__DIR__) . '/');
}
require_once AVBK_PLUGIN_DIR . 'includes/class-flyer.php';

$presets = AVBK_Flyer::get_presets();
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

$troffelSvg = AVBK_Flyer::get_troffel_svg();

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
        fwrite(STDERR, "Onbekende preset: '{$presetKey}'. Beschikbaar: boek, tshirt, foto-delen, tshirts-betalen, all\n");
        exit(1);
    }
} elseif (isset($options['url'])) {
    $itemsToProcess[] = AVBK_Flyer::build_custom_item(
        (string) $options['url'],
        (string) ($options['title'] ?? ''),
        (string) ($options['subtitle'] ?? ''),
        (string) ($options['display-url'] ?? ''),
        (string) ($options['instructions'] ?? ''),
        (string) ($options['badge'] ?? '')
    );
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
    $qrMarkup = $qrSvg;
    if (isset($item['qr_image'])) {
        $qrImage = file_get_contents($item['qr_image']);
        if ($qrImage === false) {
            fwrite(STDERR, "  ✖ QR-afbeelding kon niet worden gelezen.\n");
            exit(1);
        }
        $qrMarkup = '<img src="data:image/png;base64,' . base64_encode($qrImage) . '" alt="QR-code om je bestelling te betalen">';
    }
    $htmlContent = renderFlyerHtml($item, $qrMarkup, $troffelSvg);
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
    return AVBK_Flyer::generate_qr_svg($url);
}

function renderFlyerHtml(array $item, string $qrSvg, string $troffelSvg): string {
    return AVBK_Flyer::render_flyer_html($item, $qrSvg, $troffelSvg);
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
  --preset=tshirts-betalen Genereer betaalflyer voor een bestaande T-shirtbestelling
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
