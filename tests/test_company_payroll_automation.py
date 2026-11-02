"""Synthetic payroll parsing and recurring-schedule regressions."""
import datetime as dt
import importlib.util
from pathlib import Path
import unittest
from zoneinfo import ZoneInfo
spec=importlib.util.spec_from_file_location('payroll',Path(__file__).parents[1]/'bin/process-company-payroll.py')
payroll=importlib.util.module_from_spec(spec);spec.loader.exec_module(payroll)
CONFIG={'employer_name':'Voorbeeld BV','employer_tax_id':'123456789L01','employee_id':1,'approved_tax_ibans':['NL91ABNA0417164300']}
TAX='''Voorbeeld BV
Loonheffingsnummer : 123456789L01
Begindatum tijdvak : 01-10-2026
Betalingskenmerk : 0123 4567 8901 2345
IBAN Belastingdienst : NL91ABNA0417164300
Het bedrag voor oktober bedraagt: EUR 43,00.
Het bedrag dient uiterlijk op 30-11-2026 bijgeschreven te zijn
'''
SLIP='''Werkgevernaam: Voorbeeld BV
Werknemer 1 Periodenummer 10,0
Begindatum 01-10-2026 Einddatum 31-10-2026
Netto loon 856,35
'''
class Payroll(unittest.TestCase):
 def test_tax_period_is_separate_from_deadline(self):
  result=payroll.parse_document(TAX,CONFIG)
  self.assertEqual((result['period'],result['due_date'],result['reference'],result['amount_cents']),('2026-10','2026-11-30','0123456789012345',4300))
 def test_payslip_amount(self):
  self.assertEqual(payroll.parse_document(SLIP,CONFIG)['amount_cents'],85635)
 def test_company_employee_and_bank_scope(self):
  for text in [TAX.replace('Voorbeeld BV','Ander BV'),TAX.replace('123456789L01','987654321L01'),TAX.replace('NL91ABNA0417164300','NL02ABNA0123456789'),SLIP.replace('Werknemer 1','Werknemer 2')]:
   with self.assertRaises(ValueError):payroll.parse_document(text,CONFIG)
 def test_27th_at_9_amsterdam_and_no_duplicate_daily_run(self):
  settings={'day':27,'hour':9};zone=ZoneInfo('Europe/Amsterdam')
  self.assertFalse(payroll.due(dt.datetime(2026,11,26,10,tzinfo=zone),settings,{}))
  now=dt.datetime(2026,11,27,9,tzinfo=zone)
  self.assertTrue(payroll.due(now,settings,{}))
  self.assertFalse(payroll.due(now,settings,{'last_attempt_date':'2026-11-27'}))
  self.assertFalse(payroll.due(now,settings,{'completed_month':'2026-11'}))
 def test_old_tax_account_cannot_be_used_for_new_period(self):
  config={**CONFIG,'legacy_tax_ibans':['NL02ABNA0123456789'],'tax_account_change_date':'2026-05-01'}
  with self.assertRaises(ValueError):payroll.parse_document(TAX.replace('NL91ABNA0417164300','NL02ABNA0123456789'),config)
if __name__=='__main__':unittest.main()
