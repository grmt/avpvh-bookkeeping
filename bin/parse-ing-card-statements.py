#!/usr/bin/env python3
"""Extract ING Businesscard PDF purchases and verify each printed statement total."""
import argparse
import datetime as dt
from decimal import Decimal
import json
import hashlib
import os
from pathlib import Path
import re
import subprocess

MONTHS = {'JAN': 1, 'FEB': 2, 'MRT': 3, 'MAR': 3, 'APR': 4, 'MEI': 5, 'MAY': 5,
          'JUN': 6, 'JUL': 7, 'AUG': 8, 'SEP': 9, 'OKT': 10, 'OCT': 10, 'NOV': 11, 'DEC': 12}
MONEY = r'\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2}'


def money(value):
    return Decimal(value.replace('.', '').replace(',', '.'))


def parse(text):
    date_match = re.search(r'\b(\d{2}-\d{2}-\d{4})\s+\d+\s+van\s+\d+', text)
    account_match = re.search(r'\*{3}(\d{4})\s+(\d{6,34})', text)
    if not date_match or not account_match or 'Transactieoverzicht' not in text:
        raise ValueError('Unrecognized ING Businesscard statement layout')
    statement_date = dt.datetime.strptime(date_match[1], '%d-%m-%Y').date()
    reference = None; total = None
    lines = text.splitlines()
    for i, line in enumerate(lines):
        if 'Klantreferentie' in line:
            for following in lines[i + 1:i + 4]:
                match = re.search(r'^\s*(\d+)\s+[^\d-]*(?P<amount>' + MONEY + r')\s*$', following)
                if match:
                    reference, total = match[1], money(match['amount'])
                    break
            break
    if reference is None or total is None:
        raise ValueError('Missing statement customer reference or total')
    rows = []; current = None; table = False
    total_found = Decimal('0.00')
    date_pattern = r'^\s*(\d{2})\s+(' + '|'.join(MONTHS) + r')\s+(.+)$'
    for line in lines:
        if 'Transactieoverzicht' in line:
            table = True; current = None; continue
        if not table:
            continue
        if line.lstrip().startswith(('Op ing.nl/', 'Op ing.nl', 'ING CPS,')) or '\f' in line:
            table = False; current = None; continue
        match = re.match(date_pattern, line)
        if match:
            amount = re.search(r'(?P<negative>[-−]?)\s*[€\x80]\s*(?P<amount>' + MONEY + r')\s*$', match[3])
            if not amount:
                raise ValueError('A dated purchase line has no readable EUR amount')
            date = dt.date(statement_date.year, MONTHS[match[2]], int(match[1]))
            if date > statement_date:
                date = date.replace(year=date.year - 1)
            if (statement_date - date).days > 93:
                raise ValueError('Purchase date is outside the supported statement window')
            expense = money(amount['amount']) * (-1 if amount['negative'] else 1)
            total_found += expense
            description = match[3][:amount.start()].rstrip()
            foreign = re.search(r'\s{2,}([A-Z]{3})\s+([\d.,]+)\s+([\d.,]+)\s*$', description)
            currency = foreign[1] if foreign else 'EUR'
            original = money(foreign[2]) if foreign else abs(expense)
            rate = foreign[3] if foreign else ''
            if foreign:
                description = description[:foreign.start()].rstrip()
            if not description or expense == 0:
                raise ValueError('Empty or zero-value purchase line')
            current = {'Accountnummer': account_match[2], 'Kaartnummer': '**** ' + account_match[1],
                       'Transactiedatum': date.isoformat(), 'Boekingsdatum': '', 'Omschrijving': description.strip(),
                       'Valuta': currency, 'Bedrag': format(original * (-1 if expense > 0 else 1), '.2f'),
                       'Koers': rate, 'Bedrag in EUR': format(-expense, '.2f')}
            rows.append(current)
        elif current and line.strip() and not line.lstrip().startswith(('Datum Omschrijving', 'Valuta')):
            if re.search(r'[€\x80]\s*' + MONEY, line):
                raise ValueError('Unparsed monetary line in the purchase table')
            current['Omschrijving'] += ' ' + line.strip()
    if not rows or total_found != total:
        raise ValueError('Extracted purchases do not reconcile to the printed statement total')
    return {'statement_date': statement_date.isoformat(), 'customer_reference': reference,
            'card_account': account_match[2], 'card_last4': account_match[1], 'statement_total': format(total, '.2f'),
            'headers': list(rows[0]), 'rows': rows}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--input', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    os.umask(0o077)
    paths = sorted(args.input.glob('*.pdf')) if args.input.is_dir() else [args.input]
    statements = []
    for path in paths:
        result = subprocess.run(['pdftotext', '-layout', str(path), '-'], capture_output=True, text=True, check=True)
        statement = parse(result.stdout)
        statement['document_id'] = int(path.stem) if path.stem.isdigit() else None
        statement['source_hash'] = hashlib.sha256(path.read_bytes()).hexdigest()
        statements.append(statement)
    with args.output.open('w') as stream:
        os.fchmod(stream.fileno(), 0o600)
        json.dump(statements, stream, ensure_ascii=False)
    print('Statements parsed and reconciled:', len(statements))
    print('Purchase/refund rows:', sum(len(s['rows']) for s in statements))


if __name__ == '__main__':
    main()
