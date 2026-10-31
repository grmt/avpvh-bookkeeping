# Company bookkeeping

The plugin supports an association installation and a separate company installation
from the same code. The company installation does not require the members plugin.
Company records, permissions, bank transactions, and invoices have their own tables;
the association's existing administration is unchanged.

## Portal setup

1. Install the plugin on the company's WordPress site with HTTPS. PHP needs ZIP,
   DOM, SimpleXML, mbstring, and fileinfo support.
2. In **Bedrijfsboekhouding**, add each company with its bank IBAN and the prefix
   used by its Excel worksheet names. Prefix `Alpha` selects `Alpha Q1`, etc.
   Different companies must have different IBANs and worksheet prefixes.
3. Create WordPress accounts for the people who need access. Subscriber accounts
   are sufficient; they do not need WordPress administration privileges.
4. Assign each account a company role. Roles are independent per company:

| Role | Read records and invoices | Download workbook | Import bank data and invoices | Manage company access |
| --- | --- | --- | --- | --- |
| Directeur / CEO | Yes | Yes | Yes | Yes |
| Boekhouder | Yes | Yes | Yes | No |
| Accountant | Yes | Yes | No | No |

Site administrators can configure every company. Company roles do not grant access
to association records or WordPress settings. An account assigned to both companies
can switch between them.

For a front-end portal, put `[avbk_company_bookkeeping]` on a WordPress page. The
same interface is available in wp-admin. Configure the hostname and WordPress
hosting separately; this feature does not change DNS or deploy a new site.

## Bank upload and workbook update

Select a company, year, and quarter; upload an ING CSV or Excel transaction export.
Every row must have that company's own IBAN and valid transaction data. Exports
covering multiple quarters or years are accepted: only rows in the selected year
and quarter are imported, and the result reports the number outside that period.
The whole file, including excluded rows, is checked before anything is inserted.
An export with no transactions in the selected period is rejected. Both incoming and outgoing
transactions are stored, with the importing user and original row data. A unique
company/hash index prevents overlapping imports from adding the same records again.

Upload the latest VAT workbook to download a complete updated copy. Only the named
quarterly worksheet and workbook calculation metadata change. Other worksheets,
styles, shared strings, and embedded parts remain intact. Existing bank entries,
VAT annotations, and undated detail rows in the selected year's sheet are preserved;
new bank entries are merged in date order. A previous-year template is refreshed.
The original uploaded file is never overwritten.

The portal retains a private result snapshot for each company, year, and quarter.
It shows the exported worksheet rows, VAT amounts, amounts excluding VAT, and their
totals. Suggested amounts remain provisional; blank amounts remain unknown. The
unmarked totals follow the worksheet's exclusion of `BTW-` review markers. Expenses
are positive and receipts negative, as in the worksheet; this balance is not a VAT
return calculation. Footer caches are ignored and totals are summed from row values.
Later bank imports mark the result stale. Changes made in Excel appear after the
edited workbook is uploaded and updated again. With JavaScript, the export downloads
and then returns to the saved result; without JavaScript, refresh after downloading.

Excel shared formulas are expanded and relative row references follow their rows.
The totals are rebuilt, the obsolete calculation chain is removed, and Excel is
asked to recalculate on opening. Worksheets with tables, merged cells, validation,
conditional formatting, hyperlinks, or drawings are rejected rather than being
rewritten with stale cell references. The supported layout is the existing A:P bank
and VAT layout, with VAT classification in I, VAT in J, and net amounts in K.

## VAT patterns and invoice evidence

Earlier entries in the same company's workbook provide suggestions for recurring
counterparties. Matches use the counterparty IBAN when present, otherwise its name,
and distinguish incoming from outgoing payments. The suggestion cites its source
worksheet and cell. Conflicting classifications remain unresolved. Fixed VAT amounts
are suggested only when the earlier values agree. Credit-card settlements are
excluded from supplier VAT inference.

New rows are marked in column P with a `BTW-` review marker. Their provisional VAT
and net amounts are excluded from the VAT/net totals using `SUMIF`. Check the invoice,
correct the amounts and treatment, and remove that marker only once reviewed.
Changing a suggested numeric rate updates its VAT/net formulas when Excel recalculates.
The history is an aid to classification, not proof that an invoice was checked.

Company invoices can be uploaded as PDF, JPEG, PNG, or WebP, or recorded as a source
reference (a URL or local file path). References are stored without automatically
fetching their content. An invoice may be linked to an imported payment from the same
company. Uploads are limited to 3 MB, validated against their actual MIME type, and
stored privately in the database. They are not WordPress media attachments and have
no public file URL. Downloads require company access and a nonce. Keep database
backups because they now include invoice evidence.

## Remaining work

- Map the actual ING credit-card download format from a representative export.
  Keep individual purchases distinct from the bank settlement and reconcile them.
- Add invoice-based bookkeeping entries, including unpaid invoices, split and mixed
  VAT treatments, invoice dates, credit notes, reverse charge, and VAT return boxes.
- Add a general ledger, opening balances, journal entries, period closing, balance
  sheet, profit-and-loss reports, and accountant adjustments with an audit history.
- Add invoice review in the portal. Currently VAT review happens in the workbook.
- Add direct OneDrive save-back with version/conflict handling. Currently users
  download the merged workbook and save it to OneDrive themselves.
- Add mailbox invoice retrieval when a mailbox connection is available.

This increment is a company bank-import and workbook-review workflow. It is not yet
a complete company accounting system or an automated VAT-return preparation tool.

## Verification

`php tests/test-companies.php` checks tenant isolation, role boundaries, private
invoice references, bank account/period validation, duplicate imports, rollback,
worksheet selection, preservation of other sheets, manual rows, formula relocation,
VAT suggestions/conflicts, and exclusion of pending suggestions from VAT totals.
