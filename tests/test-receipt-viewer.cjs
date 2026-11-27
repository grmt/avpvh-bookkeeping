// Reading a date, amount and shop from the text of a selected part of a receipt.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const context = { window: {} };
vm.runInNewContext(fs.readFileSync('assets/receipt-viewer.js', 'utf8'), context);
const { date, amount, line } = context.window.AVBKReceiptText;

assert.equal(date('01-08-2026'), '2026-08-01');
assert.equal(date('Datum: 4/8/26 14:02'), '2026-08-04');
assert.equal(date('02.08.2026'), '2026-08-02');
assert.equal(date('2026-08-03'), '2026-08-03');
assert.equal(date('31-02-2026 then 01-03-2026'), '2026-03-01', 'an impossible date is skipped');
assert.equal(date('Bon 1234567'), null);

assert.equal(amount('TOTAAL 11,80'), 11.8);
assert.equal(amount('Te betalen € 1.234,56'), 1234.56);
assert.equal(amount('Subtotaal 10,00 Totaal 12,50'), 12.5, 'the last amount with cents');
assert.equal(amount('59.05'), 59.05);
assert.equal(amount('43'), 43, 'a lone whole number');
assert.equal(amount('€ 43,-'), 43);
assert.equal(amount('Kassa 12 bon 345'), null, 'numbers in text are no amount');
assert.equal(amount('11 ,80'), 11.8, 'OCR space before the comma');

assert.equal(line('  Boulangerie\n  Pâtisserie  '), 'Boulangerie Pâtisserie');
assert.equal(line('x'.repeat(150)).length, 100);
console.log('Receipt viewer reads dates, amounts and shop names from selected text.');
