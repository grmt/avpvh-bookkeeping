"""Synthetic ING PDF extraction and read-only mailbox isolation regressions."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
from email.message import EmailMessage


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).parents[1] / 'bin' / filename)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


pdf = module('statement', 'parse-ing-card-statements.py')
mail = module('mailbox', 'collect-company-invoices.py')
SAMPLE = '''Overzicht Businesscard
09-10-2026                 1 van 1             1
Kaartnummer Accountnummer Bestedingslimiet
***5678       12345678901       € 3.500,00
Klantreferentie Totale uitgaven
417164300                  € 114,80
Transactieoverzicht
 Datum Omschrijving            Valuta Oorspronkelijk bedrag Koers Bedrag
 09 SEP Voorbeeld winkel                                      € 121,00
        Voorbeeldplaats NLD
 14 SEP Voorbeeld dienst       USD          20,00   1,1111111   € 18,00
        Voorbeeldstad USA
 25 SEP Retour winkel                                      -  € 24,20
        Voorbeeldplaats NLD
Op ing.nl/businesscardspecificatie vindt u uitleg.
'''


class Statements(unittest.TestCase):
    def test_purchases_foreign_currency_and_refunds_reconcile(self):
        result = pdf.parse(SAMPLE)
        self.assertEqual(result['statement_total'], '114.80')
        self.assertEqual(len(result['rows']), 3)
        self.assertEqual(result['rows'][0]['Bedrag in EUR'], '-121.00')
        self.assertEqual(result['rows'][1]['Valuta'], 'USD')
        self.assertEqual(result['rows'][1]['Bedrag'], '-20.00')
        self.assertEqual(result['rows'][2]['Bedrag in EUR'], '24.20')
        self.assertEqual(result['rows'][0]['Boekingsdatum'], '')

    def test_wrong_total_rejected(self):
        with self.assertRaises(ValueError):
            pdf.parse(SAMPLE.replace('114,80', '115,80'))

    def test_unparsed_amount_rejected(self):
        with self.assertRaises(ValueError):
            pdf.parse(SAMPLE.replace('Op ing.nl', '        onbekende regel € 9,99\nOp ing.nl'))

    def test_year_boundary(self):
        result = pdf.parse(SAMPLE.replace('09-10-2026', '09-01-2027').replace(' SEP ', ' DEC '))
        self.assertEqual(result['rows'][0]['Transactiedatum'], '2026-12-09')

    def test_recipient_scope_requires_explicit_alias_domain(self):
        msg = EmailMessage(); msg['To'] = 'postmaster@business.example'
        self.assertFalse(mail.recipient_matches(msg, 'business@example.org'))
        self.assertTrue(mail.recipient_matches(msg, 'business@example.org', ['business.example']))
        msg.replace_header('To', 'postmaster@business.example.attacker.test')
        self.assertFalse(mail.recipient_matches(msg, 'business@example.org', ['business.example']))

    def test_private_attachment_cannot_be_overwritten(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'invoice.pdf'
            mail.private_write(path, b'original')
            mail.private_write(path, b'original')
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            with self.assertRaises(RuntimeError):
                mail.private_write(path, b'replacement')


if __name__ == '__main__':
    unittest.main()
