#!/usr/bin/env python3
"""Verify read-only IMAP access and save credentials outside the application and Git."""
import argparse
import getpass
import imaplib
import json
import os
from pathlib import Path
import ssl
import sys
import tempfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--address', required=True, help='Company mailbox address to collect invoices for')
    parser.add_argument('--host', choices=['imap.kpnmail.nl', 'imap.xs4all.nl'], default='imap.kpnmail.nl')
    args = parser.parse_args()
    if not sys.stdin.isatty():
        raise SystemExit('Run this interactively in a terminal.')
    username = input('Mailbox login address [' + args.address + ']: ').strip() or args.address
    password = getpass.getpass('Mailbox email password (hidden): ')
    if not password or any('\n' in value or '\r' in value for value in [username, args.address]):
        raise SystemExit('Invalid or empty credentials; nothing saved.')
    try:
        with imaplib.IMAP4_SSL(args.host, 993, ssl_context=ssl.create_default_context(), timeout=20) as connection:
            connection.login(username, password)
            status, _ = connection.select('INBOX', readonly=True)
            if status != 'OK':
                raise RuntimeError()
    except (imaplib.IMAP4.error, OSError, RuntimeError):
        raise SystemExit('IMAP login or read-only mailbox access failed. Nothing saved; check the mailbox login and email password.')
    path = Path('/opt/docker/secrets/compose/company_imap.json')
    profile = {'address': args.address, 'host': args.host, 'port': 993, 'username': username, 'password': password, 'folder': 'INBOX'}
    temporary = None
    try:
        fd, temporary = tempfile.mkstemp(prefix='.company-imap-', dir=path.parent)
        with os.fdopen(fd, 'w', encoding='utf-8') as output:
            os.fchmod(output.fileno(), 0o600)
            json.dump(profile, output)
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, path)
        temporary = None
    finally:
        if temporary is not None:
            os.unlink(temporary)
    print('Read-only IMAP access verified. Credentials saved privately outside Git and WordPress.')
    print('No mail was sent, marked as read, moved, or deleted.')


if __name__ == '__main__':
    try:
        main()
    except (KeyboardInterrupt, EOFError):
        raise SystemExit('\nCancelled; no credentials changed.')
