#!/usr/bin/env python3
"""Collect company invoice candidates with read-only IMAP; never send or change mail."""
import argparse
import base64
import datetime as dt
from email import policy
from email.parser import BytesParser
from email.utils import getaddresses
import hashlib
import imaplib
import json
import os
from pathlib import Path
import re
import ssl
import subprocess
import sys

MAX_BYTES = 3 * 1024 * 1024
CUES = re.compile(r'invoice|factuur|receipt|rekening|afrekening|statement|billing|\bbill\b|\bbon\b|bestelling|\border\b|aankoop|purchase', re.I)
EXCLUDE = re.compile(r'voorwaarden|terms|privacy|manual|handleiding|logo|signature', re.I)
PUBLISH = r'''
global $wpdb;
$p = json_decode(file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
wp_set_current_user((int) $p['user_id']);
$company_id = (int) $p['company_id'];
AVBK_Companies::company($company_id, 'import');
$hash = hash('sha256', base64_decode($p['bytes'], true));
$existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}avb_company_documents WHERE company_id = %d AND content_hash = %s", $company_id, $hash));
$id = AVBK_Company_Documents::import_mail($company_id, $p['reference'], $p['filename'], $p['bytes']);
echo json_encode(['id' => $id, 'already_present' => (bool) $existing]);
'''


def private_write(path, data):
    try:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    except FileExistsError:
        if path.is_symlink() or path.read_bytes() != data:
            raise RuntimeError('Private staging file conflict')
        return
    with os.fdopen(fd, 'wb') as stream:
        stream.write(data)


def recipient_matches(message, address, domains=()):
    headers = [str(value) for key in ['To', 'Cc', 'Delivered-To', 'X-Original-To'] for value in message.get_all(key, [])]
    recipients = [value.lower() for _, value in getaddresses(headers)]
    return any(value == address.lower() or any(value.endswith('@' + domain.lower()) for domain in domains) for value in recipients)


def publish(args, root, metadata, filename, data):
    reference = ('E-mailbijlage · ' + metadata['date'] + ' · ' + metadata['sender'] + ' · ' + metadata['subject'] + ' · bericht ' + metadata['message_id']
                 + ' · kandidaatfactuur; administratie, aankoop en BTW nog controleren')[:1900]
    payload = {'company_id': args.company_id, 'user_id': args.user_id, 'reference': reference,
               'filename': filename, 'bytes': base64.b64encode(data).decode('ascii')}
    result = subprocess.run(['docker', 'compose', '-f', str(root / 'scripts/docker-compose.yml'), 'exec', '-T', 'wpcli-company', 'wp', 'eval', PUBLISH],
                            cwd=root / 'scripts', input=json.dumps(payload), text=True, capture_output=True)
    if result.returncode:
        return {'status': 'not_imported', 'reason': 'Application rejected the attachment; diagnostic output withheld'}
    response = json.loads(result.stdout)
    return {'status': 'already_present' if response['already_present'] else 'imported', 'document_id': response['id']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--since', type=dt.date.fromisoformat, required=True)
    parser.add_argument('--before', type=dt.date.fromisoformat, required=True, help='Exclusive end date')
    parser.add_argument('--profile', type=Path)
    parser.add_argument('--company-id', type=int, required=True)
    parser.add_argument('--user-id', type=int, required=True)
    parser.add_argument('--publish', action='store_true')
    parser.add_argument('--recipient-domain', action='append', default=[], help='Additional explicitly authorized recipient domain')
    args = parser.parse_args()
    if args.since >= args.before or args.company_id < 1 or args.user_id < 1:
        raise SystemExit('Invalid collection period or application account')
    if any(not re.fullmatch(r'[A-Za-z0-9]+(?:[.-][A-Za-z0-9]+)*\.[A-Za-z]{2,}', domain) for domain in args.recipient_domain):
        raise SystemExit('Invalid recipient domain')
    os.umask(0o077)
    root = Path(os.environ.get('DOCKER_ROOT', '/opt/docker'))
    profile_path = args.profile or root / 'secrets/compose/company_imap.json'
    p = json.loads(profile_path.read_text())
    if profile_path.stat().st_mode & 0o077:
        raise SystemExit('Mailbox credential file must be private to its owner')
    stage = Path.home() / '.local/share/company-invoices' / str(args.company_id)
    stage.mkdir(parents=True, exist_ok=True, mode=0o700)
    counts = {'messages_checked': 0, 'company_messages': 0, 'invoice_messages': 0, 'attachments': 0,
              'imported': 0, 'already_present': 0, 'not_imported': 0, 'oversized': 0, 'without_attachment': 0}
    entries = []; follow_up = []
    with imaplib.IMAP4_SSL(p['host'], p.get('port', 993), ssl_context=ssl.create_default_context(), timeout=25) as c:
        c.login(p['username'], p['password'])
        folders = [p.get('folder', 'INBOX')]
        status, listing = c.list()
        if status == 'OK':
            for item in listing:
                if not item:
                    continue
                match = re.match(rb'\(([^)]*)\)\s+"[^"]*"\s+(.+)', item)
                if not match or re.search(rb'\\(?:Sent|Trash|Drafts|Junk|NoSelect)', match[1], re.I):
                    continue
                folder = match[2].decode('ascii').strip('"').replace('\\"', '"')
                if re.search(r'(?:^|[/.])(?:sent|trash|spam|junk|drafts|deleted|verzonden|prullenbak|ongewenst|concepten)(?:$|[/.])', folder, re.I):
                    continue
                if folder not in folders:
                    folders.append(folder)
        for index, folder in enumerate(folders, 1):
            status, _ = c.select('"' + folder.replace('\\', '\\\\').replace('"', '\\"') + '"', readonly=True)
            if status != 'OK':
                continue
            status, data = c.uid('search', None, 'SINCE', args.since.strftime('%d-%b-%Y'), 'BEFORE', args.before.strftime('%d-%b-%Y'))
            if status != 'OK':
                raise RuntimeError('Mailbox search failed')
            ids = data[0].split()
            print('Folder', index, ':', len(ids), 'messages in collection period', flush=True)
            for uid in ids:
                status, parts = c.uid('fetch', uid, '(RFC822.SIZE BODYSTRUCTURE BODY.PEEK[HEADER.FIELDS (SUBJECT FROM TO CC DELIVERED-TO X-ORIGINAL-TO DATE MESSAGE-ID)])')
                if status != 'OK':
                    continue
                counts['messages_checked'] += 1
                header = b''.join(part[1] for part in parts if isinstance(part, tuple))
                structure = b' '.join(part[0] if isinstance(part, tuple) else part for part in parts if part)
                message = BytesParser(policy=policy.default).parsebytes(header)
                if not recipient_matches(message, p['address'], args.recipient_domain):
                    continue
                counts['company_messages'] += 1
                subject = str(message.get('Subject', ''))
                context = bool(CUES.search(subject))
                if not context and b'.pdf' not in structure.lower() and b'"pdf"' not in structure.lower():
                    continue
                size = re.search(rb'RFC822.SIZE\s+(\d+)', structure)
                if size and int(size[1]) > 15 * 1024 * 1024:
                    counts['oversized'] += 1
                    continue
                status, body = c.uid('fetch', uid, '(BODY.PEEK[])')
                if status != 'OK':
                    continue
                raw = b''.join(part[1] for part in body if isinstance(part, tuple))
                mail = BytesParser(policy=policy.default).parsebytes(raw)
                metadata = {'folder': folder, 'uid': uid.decode(), 'date': str(mail.get('Date', '')),
                            'message_id': str(mail.get('Message-ID', ''))[:400], 'subject': subject[:500], 'sender': str(mail.get('From', ''))[:500]}
                found = 0
                for part in mail.walk():
                    filename = part.get_filename()
                    if not filename:
                        continue
                    filename = Path(filename.replace('\\', '/')).name
                    ext = Path(filename).suffix.lower()
                    if ext not in {'.pdf', '.jpg', '.jpeg', '.png', '.webp'} or EXCLUDE.search(filename):
                        continue
                    if not (context or CUES.search(filename)):
                        continue
                    if ext != '.pdf' and part.get_content_disposition() != 'attachment' and not CUES.search(filename):
                        continue
                    attachment = part.get_payload(decode=True)
                    if not attachment:
                        continue
                    if len(attachment) > MAX_BYTES:
                        counts['oversized'] += 1
                        continue
                    digest = hashlib.sha256(attachment).hexdigest()
                    path = stage / (digest + ext)
                    private_write(path, attachment)
                    record = dict(metadata, filename=filename, staged_file=str(path), sha256=digest)
                    result = publish(args, root, metadata, filename, attachment) if args.publish else {'status': 'staged'}
                    record.update(result); entries.append(record)
                    counts['attachments'] += 1; found += 1
                    if result['status'] in counts:
                        counts[result['status']] += 1
                if context or found:
                    counts['invoice_messages'] += 1
                if context and not found:
                    counts['without_attachment'] += 1
                    text = '\n'.join(str(part.get_content()) for part in mail.walk() if part.get_content_type() == 'text/plain' and part.get_content_disposition() != 'attachment')
                    follow_up.append(dict(metadata, text=text[:16000]))
                if counts['messages_checked'] % 25 == 0:
                    print('Progress:', counts['messages_checked'], 'messages checked;', counts['attachments'], 'attachments collected', flush=True)
    manifest = stage / ('collection-' + dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '.json')
    private_write(manifest, json.dumps({'since': str(args.since), 'before': str(args.before), 'company_id': args.company_id,
                                      'counts': counts, 'attachments': entries, 'follow_up': follow_up}, ensure_ascii=False, indent=2).encode())
    print(json.dumps(counts), flush=True)
    print('Private manifest:', manifest, flush=True)
    print('No mail sent, marked read, moved, or deleted.', flush=True)


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, imaplib.IMAP4.error):
        raise SystemExit('Mailbox collection failed; credential and provider diagnostics withheld.')
