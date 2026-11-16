<?php
/** Tests for reading invoices in the company invoice inbox (AVBK_Company_Invoice_Inbox). Fictional invoices only. */
define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-company-invoice-inbox.php';

$failures = 0;
function check(bool $ok, string $label): void { global $failures; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) $failures++; }

$dutch = <<<TXT
Voorbeeld Kantoorartikelen B.V.
Industrieweg 12
1234 AB Ergens
FACTUUR
Factuurnummer: 2025-0412
Factuurdatum: 14-03-2025
Vervaldatum: 13-04-2025
Omschrijving        Aantal   Bedrag
Papier A4           10       45,00
Inktpatronen        2        1.020,00
Totaal excl. BTW             1.065,00
BTW 21%                        223,65
Totaal incl. BTW             1.288,65
TXT;
$read = AVBK_Company_Invoice_Inbox::read_invoice($dutch);
check($read['date'] === '2025-03-14', 'Dutch invoice date from the Factuurdatum line, not the due date');
check($read['amount'] === 1288.65, 'total incl. VAT with a thousands separator is preferred over excl. VAT');
check($read['supplier'] === 'Voorbeeld Kantoorartikelen B.V.', 'supplier is the first text line');

$english = <<<TXT
INVOICE
Example Hosting Ltd
Invoice date: 2 October 2025
Due date: 1 November 2025
Subtotal 1,000.00
VAT 210.00
Amount due EUR 1,210.00
TXT;
$read = AVBK_Company_Invoice_Inbox::read_invoice($english);
check($read['date'] === '2025-10-02', 'English month names are read');
check($read['amount'] === 1210.0, 'amount due with comma thousands and a dot decimal');
check($read['supplier'] === 'Example Hosting Ltd', 'a heading like INVOICE is not taken as the supplier');

$next_line = "Bakkerij Voorbeeld\nDatum\n05.01.2026\nTe betalen\n€ 18,40";
$read = AVBK_Company_Invoice_Inbox::read_invoice($next_line);
check($read['date'] === '2026-01-05' && $read['amount'] === 18.40, 'date and amount on the line after their label');

$no_total = "Iets Zonder Totaal\n12-02-2025\nRegel 1 10,00\nRegel 2 20,00";
$read = AVBK_Company_Invoice_Inbox::read_invoice($no_total);
check($read['amount'] === null && $read['date'] === '2025-02-12', 'no total line means no amount guess, but the date is still found');

$docling = "Voorbeeld Leverancier B.V. Factuurdatum: 14-03-2025 Vervaldatum: 13-04-2025 Totaal excl. BTW 100,00 BTW 21% 21,00 Totaal incl. BTW 121,00";
$read = AVBK_Company_Invoice_Inbox::read_invoice($docling);
check($read['supplier'] === 'Voorbeeld Leverancier B.V.' && $read['date'] === '2025-03-14' && $read['amount'] === 121.0, 'docling text with the lines of a block joined into one');

check(AVBK_Company_Invoice_Inbox::first_date('31-02-2025 en 2025-06-30') === '2025-06-30', 'an impossible date is skipped');
check(AVBK_Company_Invoice_Inbox::first_date('versie 1.2.3') === null, 'version numbers are not dates');
check(AVBK_Company_Invoice_Inbox::amounts('Totaal 1.234,56 en 12,00 en 99.95') === [1234.56, 12.0, 99.95], 'European and dot-decimal amounts on one line');
check(AVBK_Company_Invoice_Inbox::amounts('IBAN NL00TEST0123456789 kenmerk 2025') === [], 'numbers without cents are not amounts');
check(AVBK_Company_Invoice_Inbox::quarter_label('2025-08-31') === '2025 Q3' && AVBK_Company_Invoice_Inbox::quarter_label('2026-01-01') === '2026 Q1' && AVBK_Company_Invoice_Inbox::quarter_label(null) === '', 'quarter from the invoice date');

exit($failures ? 1 : 0);
