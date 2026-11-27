// The declaration form: reading receipts, the rows, dates and amounts.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { browserStandIns, settle } from './setup.js';

const found = {
    amount: 11.8, store: 'Bakkerij Voorbeeld', date: '2026-08-01', duplicate: false,
    candidates: { amounts: [11.8, 3.5], dates: ['2026-08-01', '2026-08-02'], stores: ['Bakkerij Voorbeeld', 'Dorpsstraat 1'] },
};
let replies;
let start;
let viewer;

function reply(data) {
    return Promise.resolve({ json: () => Promise.resolve(data) });
}

/** The form as the shortcode renders it, then the script's DOMContentLoaded handler. */
async function load(engines = { google: 'Google' }) {
    document.body.innerHTML = `
        <form id="avbk-reimbursement-form">
            <select class="avbk-iban-select" data-target="iban"><option value="">anders</option><option value="NL00TEST0000000001">opgeslagen</option></select>
            <input id="iban" hidden>
            <a href="#" class="avbk-reimbursement-edit-toggle" data-target="edit-row">wijzig</a>
            <div id="edit-row" hidden></div>
            <div id="avbk-dropzone"><input type="file" id="avbk-receipt-input" multiple></div>
            <button type="button" id="avbk-add-manual-row">+ Regel</button>
            <p id="avbk-ocr-status" hidden></p>
            <table id="avbk-dropzone-table" hidden><tbody id="avbk-dropzone-list"></tbody></table>
            <input id="avbk-amount">
            <button type="submit">Verstuur</button>
        </form>
        <script type="application/json" id="avbk-reimbursement-config">${JSON.stringify({ ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'n', engines })}</script>`;
    Object.defineProperty(document.getElementById('avbk-receipt-input'), 'files', { writable: true, value: [] });
    start();
}

function addFiles(...files) {
    const input = document.getElementById('avbk-receipt-input');
    input.files = files;
    input.dispatchEvent(new Event('change'));
}

const photo = (name = 'bon.jpg') => new File(['jpg'], name, { type: 'image/jpeg' });
const rows = () => [...document.querySelectorAll('#avbk-dropzone-list tr')];
const status = () => document.getElementById('avbk-ocr-status');
const cell = (row, selector) => row.querySelector(selector);
const sent = (call) => Object.fromEntries([...fetch.mock.calls[call][1].body.entries()].filter(([, v]) => typeof v === 'string'));

function type(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input'));
}

beforeEach(async () => {
    browserStandIns();
    replies = [];
    vi.stubGlobal('fetch', vi.fn(() => replies.shift() ?? reply({ success: true, data: found })));
    viewer = { open: vi.fn(), update: vi.fn() };
    window.AVBKReceiptText = { date: (value) => (value === '4-8-26' ? '2026-08-04' : null) };
    window.AVBKReceiptViewer = viewer;
    const listen = vi.spyOn(document, 'addEventListener');
    vi.resetModules();
    await import('../../assets/reimbursement.js');
    start = listen.mock.calls.find(([type]) => type === 'DOMContentLoaded')[1];
    listen.mockRestore();
});

afterEach(() => {
    vi.unstubAllGlobals();
    delete window.AVBKReceiptViewer;
});

describe('reading receipts', () => {
    it('shows each receipt being read, then done, and fills in what it found', async () => {
        await load();
        addFiles(photo(), photo('tweede.jpg'));
        expect(cell(rows()[0], '.avbk-dropzone-item-progress').textContent).toBe('lezen…');
        expect(rows()[0].classList.contains('avbk-dropzone-item-reading')).toBe(true);
        expect(status().textContent).toBe('Bonnetjes worden gelezen: 0 van 2 klaar…');
        expect(status().classList.contains('avbk-reimbursement-ocr-status-busy')).toBe(true);
        expect(sent(0)).toEqual({ action: 'avbk_ocr_receipt', nonce: 'n' });

        await settle();
        const row = rows()[0];
        expect(cell(row, '.avbk-dropzone-item-progress').textContent).toBe('✓ gelezen');
        expect(cell(row, '.avbk-dropzone-item-date').value).toBe('01-08-2026');
        expect(cell(row, 'input[name="date[]"]').value).toBe('2026-08-01');
        expect(cell(row, '.avbk-dropzone-item-store').value).toBe('Bakkerij Voorbeeld');
        expect(cell(row, '.avbk-dropzone-item-amount').value).toBe('11,80');
        expect(document.getElementById('avbk-amount').value).toBe('23,60');
        expect(status().textContent).toMatch(/^Klaar: 2 bonnetjes gelezen\. /);
        expect(status().classList.contains('avbk-reimbursement-ocr-status-busy')).toBe(false);
        expect([...row.querySelectorAll('datalist')].map((l) => [...l.options].map((o) => o.value))).toEqual([
            ['01-08-2026', '02-08-2026'], ['Bakkerij Voorbeeld', 'Dorpsstraat 1'], ['11,80', '3,50'],
        ]);
    });

    it('says when nothing was found, also when reading fails', async () => {
        await load();
        replies.push(reply({ success: true, data: { amount: null, store: null, date: null, duplicate: false } }));
        replies.push(Promise.reject(new Error('offline')));
        addFiles(photo(), photo('tweede.jpg'));
        await settle();
        expect(rows().map((r) => cell(r, '.avbk-dropzone-item-progress').textContent)).toEqual(['niets gelezen – vul zelf in', 'niets gelezen – vul zelf in']);
        expect(status().textContent).toMatch(/^Klaar: 2 bonnetjes gelezen, bij 2 niets gevonden\. /);
    });

    it('warns about a receipt declared before and blocks sending', async () => {
        await load();
        replies.push(reply({ success: true, data: { duplicate: true } }));
        addFiles(photo());
        await settle();
        expect(cell(rows()[0], '.avbk-dropzone-item-warning').textContent).toBe('al eerder gedeclareerd');
        expect(rows()[0].classList.contains('avbk-dropzone-item-duplicate')).toBe(true);
        expect(status().classList.contains('avbk-reimbursement-ocr-status-error')).toBe(true);
        expect(document.querySelector('button[type="submit"]').disabled).toBe(true);
    });

    it('reads again with the other engine, keeping what the member typed', async () => {
        await load({ docling: 'Docling (eigen server)', google: 'Google' });
        addFiles(photo());
        await settle();
        type(cell(rows()[0], '.avbk-dropzone-item-store'), 'Eigen naam');
        const retry = cell(rows()[0], '.avbk-dropzone-item-retry');
        expect(retry.textContent).toBe('↻ opnieuw lezen met Google');
        retry.click();
        expect(sent(1).engine).toBe('google');
        await settle();
        expect(cell(rows()[0], '.avbk-dropzone-item-store').value).toBe('Eigen naam');
        expect(cell(rows()[0], '.avbk-dropzone-item-retry').textContent).toBe('↻ opnieuw lezen met Docling (eigen server)');
    });

    it('offers a plain retry with one engine', async () => {
        await load();
        addFiles(photo());
        await settle();
        cell(rows()[0], '.avbk-dropzone-item-retry').click();
        expect(sent(1).engine).toBeUndefined();
    });
});

describe('the rows', () => {
    it('types a date as dd-mm-jjjj and sends yyyy-mm-dd', async () => {
        await load();
        document.getElementById('avbk-add-manual-row').click();
        const row = rows()[0];
        const date = cell(row, '.avbk-dropzone-item-date');
        type(date, '4-8-26');
        expect(cell(row, 'input[name="date[]"]').value).toBe('2026-08-04');
        date.dispatchEvent(new Event('blur'));
        expect(date.value).toBe('04-08-2026');
        type(date, 'gisteren');
        expect(date.classList.contains('avbk-dropzone-item-invalid')).toBe(true);
        expect(cell(row, 'input[name="date[]"]').value).toBe('');
        date.dispatchEvent(new Event('blur'));
        expect(date.value).toBe('gisteren');
    });

    it('opens the browser date picker, also where showPicker is missing', async () => {
        await load();
        document.getElementById('avbk-add-manual-row').click();
        const row = rows()[0];
        const picker = cell(row, '.avbk-dropzone-item-date-picker');
        picker.showPicker = vi.fn();
        cell(row, '.avbk-dropzone-item-date-pick').click();
        expect(picker.showPicker).toHaveBeenCalled();
        picker.showPicker = () => { throw new Error('not allowed'); };
        const click = vi.spyOn(picker, 'click');
        cell(row, '.avbk-dropzone-item-date-pick').click();
        expect(click).toHaveBeenCalled();
        picker.value = '2026-08-05';
        picker.dispatchEvent(new Event('change'));
        expect(cell(row, '.avbk-dropzone-item-date').value).toBe('05-08-2026');
        expect(cell(row, 'input[name="date[]"]').value).toBe('2026-08-05');
    });

    it('shows amounts with two decimals and adds them up', async () => {
        await load();
        document.getElementById('avbk-add-manual-row').click();
        document.getElementById('avbk-add-manual-row').click();
        const [first, second] = rows().map((r) => cell(r, '.avbk-dropzone-item-amount'));
        type(first, '1.234,5');
        first.dispatchEvent(new Event('blur'));
        expect(first.value).toBe('1234,50');
        type(second, '€ 0.5');
        expect(document.getElementById('avbk-amount').value).toBe('1235,00');
        type(second, 'abc');
        second.dispatchEvent(new Event('blur'));
        expect(second.value).toBe('abc');
        type(cell(rows()[0], '.avbk-dropzone-item-description'), 'koffie');
    });

    it('keeps a total the member typed', async () => {
        await load();
        document.getElementById('avbk-amount').value = '50,00';
        addFiles(photo());
        await settle();
        expect(document.getElementById('avbk-amount').value).toBe('50,00');
    });

    it('marks a row without photo, and a PDF without the viewer', async () => {
        await load();
        document.getElementById('avbk-add-manual-row').click();
        addFiles(new File(['%PDF'], 'factuur.pdf', { type: 'application/pdf' }), new File(['x'], 'bon.heic', { type: '' }));
        await settle();
        expect(rows().map((r) => r.cells[0].textContent)).toEqual(['geen foto', 'pdf', 'bestand']);
        expect(cell(rows()[0], 'input[name="has_receipt[]"]').value).toBe('0');
        expect(cell(rows()[0], '.avbk-dropzone-item-progress')).toBeNull();
    });

    it('opens a photo in the viewer and takes over what is selected there', async () => {
        await load();
        addFiles(photo());
        await settle();
        cell(rows()[0], '.avbk-dropzone-item-zoom').click();
        const [src, options] = viewer.open.mock.calls[0];
        expect(src).toBe('blob:receipt');
        expect(options.values).toEqual({ date: '2026-08-01', store: 'Bakkerij Voorbeeld', amount: 11.8 });
        expect(options.candidates).toEqual(found.candidates);
        expect(options.reading).toBe(false);
        options.apply('amount', 7.5);
        expect(cell(rows()[0], '.avbk-dropzone-item-amount').value).toBe('7,50');
        options.apply('store', 'Markt');
        expect(cell(rows()[0], '.avbk-dropzone-item-store').value).toBe('Markt');

        fetch.mockImplementationOnce(() => reply({ success: true, data: { text: 'TOTAAL 7,50' } }));
        await expect(options.read(new Blob(['png']), 'google')).resolves.toBe('TOTAAL 7,50');
        expect(sent(1)).toEqual({ engine: 'google', action: 'avbk_ocr_region', nonce: 'n' });
        fetch.mockImplementationOnce(() => reply({ success: false, data: 'Ongeldig fragment' }));
        await expect(options.read(new Blob(['png']), '')).rejects.toThrow('Ongeldig fragment');
        fetch.mockImplementationOnce(() => reply(null));
        await expect(options.read(new Blob(['png']), '')).rejects.toThrow('Typ het over');
        const scrollSpy = vi.fn();
        rows()[0].scrollIntoView = scrollSpy;
        options.onClose();
        expect(rows()[0].classList.contains('avbk-dropzone-item-active')).toBe(true);
        expect(document.activeElement).toBe(cell(rows()[0], '.avbk-dropzone-item-zoom'));
        expect(scrollSpy).toHaveBeenCalledWith({ block: 'center' });
    });

    it('updates an open viewer when reading finishes, and stops once closed', async () => {
        await load();
        let finishFirst;
        replies.push(new Promise((resolve) => { finishFirst = resolve; }));
        addFiles(photo());
        cell(rows()[0], '.avbk-dropzone-item-zoom').click();
        const [, options] = viewer.open.mock.calls[0];
        expect(options.reading).toBe(true);
        expect(options.values).toEqual({ date: '', store: '', amount: null });

        finishFirst({ json: () => Promise.resolve({ success: true, data: found }) });
        await settle();
        expect(viewer.update).toHaveBeenCalledWith({
            values: { date: '2026-08-01', store: 'Bakkerij Voorbeeld', amount: 11.8 },
            candidates: found.candidates,
            reading: false,
        });

        options.onClose();
        viewer.update.mockClear();
        cell(rows()[0], '.avbk-dropzone-item-retry').click();
        await settle();
        expect(viewer.update).not.toHaveBeenCalled();
    });

    it('removes a row and frees its photo', async () => {
        await load();
        addFiles(photo());
        await settle();
        cell(rows()[0], '.avbk-dropzone-item-zoom').click();
        cell(rows()[0], '.avbk-dropzone-item-remove').click();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:receipt');
        expect(rows()).toHaveLength(0);
        expect(status().hidden).toBe(true);
        expect(document.getElementById('avbk-dropzone-table').hidden).toBe(true);
    });
});

describe('the rest of the form', () => {
    it('takes dropped files, and ignores a drop without files', async () => {
        await load();
        const zone = document.getElementById('avbk-dropzone');
        const drag = (type, files) => {
            const event = new Event(type, { cancelable: true });
            event.dataTransfer = files ? { files } : undefined;
            zone.dispatchEvent(event);
        };
        drag('dragover');
        expect(zone.classList.contains('avbk-dropzone-dragover')).toBe(true);
        drag('dragleave');
        expect(zone.classList.contains('avbk-dropzone-dragover')).toBe(false);
        drag('drop', []);
        expect(rows()).toHaveLength(0);
        drag('drop', [photo()]);
        expect(rows()).toHaveLength(1);
        const input = document.getElementById('avbk-receipt-input');
        input.files = [];
        input.dispatchEvent(new Event('change'));
        expect(rows()).toHaveLength(1);
    });

    it('switches between a saved IBAN and typing one, and toggles an edit row', async () => {
        await load();
        const select = document.querySelector('.avbk-iban-select');
        const iban = document.getElementById('iban');
        select.value = 'NL00TEST0000000001';
        select.dispatchEvent(new Event('change'));
        expect([iban.value, iban.hidden, iban.required]).toEqual(['NL00TEST0000000001', true, false]);
        select.value = '';
        select.dispatchEvent(new Event('change'));
        expect([iban.value, iban.hidden, iban.required]).toEqual(['', false, true]);
        document.querySelector('.avbk-reimbursement-edit-toggle').click();
        expect(document.getElementById('edit-row').hidden).toBe(false);
    });

    it('does nothing on a page without the form', () => {
        document.body.innerHTML = '<p>andere pagina</p>';
        expect(() => start()).not.toThrow();
    });
});

describe('automatic server-side drafts', () => {
    async function loadDraftForm({ draft = null, engines = { docling: 'Docling', google: 'Google' } } = {}) {
        document.body.innerHTML = `
            <form id="avbk-reimbursement-form">
                <input type="hidden" name="draft_id" id="avbk-draft-id" value="${draft ? draft.id : ''}">
                <div class="avbk-draft-bar" id="avbk-draft-bar" ${draft ? '' : 'hidden'}>
                    <span id="avbk-draft-status"></span>
                    <button type="button" id="avbk-draft-discard">Concept wissen</button>
                </div>
                <select id="avbk-activity"><option value="">geen</option><option value="9">Kamp</option></select>
                <select class="avbk-iban-select" data-target="avbk-iban"><option value="">anders</option><option value="NL00TEST0000000001">opgeslagen</option></select>
                <input id="avbk-iban" value="NL00TEST0000000001" hidden>
                <div id="avbk-dropzone"><input type="file" id="avbk-receipt-input" multiple></div>
                <button type="button" id="avbk-add-manual-row">+ Regel</button>
                <p id="avbk-ocr-status" hidden></p>
                <table id="avbk-dropzone-table" hidden><tbody id="avbk-dropzone-list"></tbody></table>
                <input id="avbk-amount" value="${draft?.amount ?? ''}">
                <textarea id="avbk-member-note"></textarea>
                <button type="submit">Declareren</button>
            </form>
            ${draft ? `<script type="application/json" id="avbk-reimbursement-draft">${JSON.stringify(draft)}</script>` : ''}
            <script type="application/json" id="avbk-reimbursement-config">${JSON.stringify({ ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'n', engines })}</script>`;
        Object.defineProperty(document.getElementById('avbk-receipt-input'), 'files', { writable: true, value: [] });
        start();
    }

    it('creates a draft on upload, saves edits and retries, and removes rows on the server', async () => {
        vi.useFakeTimers();
        try {
            await loadDraftForm();
            replies.push(reply({ success: true, data: { draft_id: 42 } }));
            replies.push(reply({
                success: true,
                data: {
                    ...found,
                    draft_id: 42,
                    receipt_id: 101,
                    photo_url: '/wp-admin/admin-post.php?action=avbk_view_receipt&id=42&receipt=101',
                    is_image: true,
                    is_pdf: false,
                },
            }));
            addFiles(photo());
            await vi.advanceTimersByTimeAsync(10);

            expect(sent(0)).toEqual({ action: 'avbk_draft_ensure', nonce: 'n', iban: 'NL00TEST0000000001' });
            expect(sent(1)).toMatchObject({ action: 'avbk_draft_add_receipt', draft_id: '42' });
            expect(document.getElementById('avbk-draft-id').value).toBe('42');
            expect(document.getElementById('avbk-draft-bar').hidden).toBe(false);
            expect(cell(rows()[0], 'input[name="receipt_id[]"]').value).toBe('101');
            expect(document.getElementById('avbk-receipt-input').files).toHaveLength(0);

            // Editing via viewer saves immediately.
            replies.push(reply({ success: true, data: { saved: true } }));
            cell(rows()[0], '.avbk-dropzone-item-zoom').click();
            viewer.open.mock.calls[0][1].apply('store', 'Supermarkt');
            await vi.advanceTimersByTimeAsync(10);
            expect(sent(2)).toMatchObject({ action: 'avbk_draft_save_receipt', draft_id: '42', receipt_id: '101', store: 'Supermarkt' });

            // Typing in table row + header saves after debounce.
            replies.push(reply({ success: true, data: { saved: true } }));
            replies.push(reply({ success: true, data: { draft_id: 42 } }));
            type(cell(rows()[0], '.avbk-dropzone-item-description'), 'brood');
            document.getElementById('avbk-activity').value = '9';
            document.getElementById('avbk-activity').dispatchEvent(new Event('change'));
            await vi.advanceTimersByTimeAsync(450);
            const actions = fetch.mock.calls.slice(3).map((_, idx) => sent(3 + idx).action);
            expect(actions).toContain('avbk_draft_save_receipt');
            expect(actions).toContain('avbk_draft_save');

            // Retry uses avbk_draft_retry_receipt against the stored receipt_id.
            const beforeRetry = fetch.mock.calls.length;
            replies.push(reply({ success: true, data: { ...found, amount: 15.0 } }));
            replies.push(reply({ success: true, data: { saved: true } }));
            cell(rows()[0], '.avbk-dropzone-item-retry').click();
            await vi.advanceTimersByTimeAsync(10);
            expect(sent(beforeRetry)).toMatchObject({ action: 'avbk_draft_retry_receipt', draft_id: '42', receipt_id: '101', engine: 'google' });

            // Removing the row calls avbk_draft_remove_receipt.
            const beforeRemove = fetch.mock.calls.length;
            replies.push(reply({ success: true, data: { removed: true } }));
            cell(rows()[0], '.avbk-dropzone-item-remove').click();
            await vi.advanceTimersByTimeAsync(10);
            expect(sent(beforeRemove)).toEqual({ action: 'avbk_draft_remove_receipt', nonce: 'n', draft_id: '42', receipt_id: '101' });
        } finally {
            vi.useRealTimers();
        }
    });

    it('hydrates a saved draft on page load and lets the member discard it', async () => {
        await loadDraftForm({
            draft: {
                id: 55,
                receipts: [
                    { id: 201, hasReceipt: true, isImage: true, isPdf: false, photoUrl: '/receipt/201', date: '2026-08-03', store: 'Bakker', description: 'brood', amount: 31.7 },
                    { id: 202, hasReceipt: true, isImage: false, isPdf: true, photoUrl: '/receipt/202', date: '', store: '', description: '', amount: null },
                    { id: 203, hasReceipt: false, isImage: false, isPdf: false, photoUrl: '', date: '', store: 'Koffie', description: '', amount: 4.3 },
                ],
            },
        });
        expect(rows()).toHaveLength(3);
        expect(document.getElementById('avbk-amount').value).toBe('36,00');
        expect(cell(rows()[0], '.avbk-dropzone-item-photo').getAttribute('src')).toBe('/receipt/201');
        expect(rows()[1].cells[0].textContent).toBe('pdf');
        expect(rows()[2].cells[0].textContent).toBe('geen foto');
        expect(cell(rows()[0], '.avbk-dropzone-item-progress').textContent).toBe('✓ gelezen');
        expect(cell(rows()[1], '.avbk-dropzone-item-progress').textContent).toBe('niets gelezen – vul zelf in');

        // Opening the restored image uses its server URL.
        cell(rows()[0], '.avbk-dropzone-item-zoom').click();
        expect(viewer.open.mock.calls[0][0]).toBe('/receipt/201');

        // Discarding can be cancelled or confirmed.
        vi.stubGlobal('confirm', vi.fn(() => false));
        document.getElementById('avbk-draft-discard').click();
        expect(rows()).toHaveLength(3);

        confirm.mockReturnValue(true);
        replies.push(reply({ success: true, data: { discarded: true } }));
        document.getElementById('avbk-draft-discard').click();
        await settle();
        expect(sent(0)).toEqual({ action: 'avbk_draft_discard', nonce: 'n', draft_id: '55' });
        expect(rows()).toHaveLength(0);
        expect(document.getElementById('avbk-draft-id').value).toBe('');
        expect(document.getElementById('avbk-draft-bar').hidden).toBe(true);
    });

    it('adds manual rows to the draft, flushes early edits, and shows save errors', async () => {
        await loadDraftForm();
        let finishManual;
        replies.push(reply({ success: true, data: { draft_id: 77 } }));
        replies.push(new Promise((resolve) => { finishManual = resolve; }));
        document.getElementById('avbk-add-manual-row').click();
        await settle();

        // Edit before receipt_id arrives -> marked dirty and flushed once manual row is created.
        type(cell(rows()[0], '.avbk-dropzone-item-store'), 'Kantine');
        replies.push(reply({ success: false, data: 'Fout' }));
        finishManual({ json: () => Promise.resolve({ success: true, data: { draft_id: 77, receipt_id: 301 } }) });
        await settle();

        expect(sent(1)).toMatchObject({ action: 'avbk_draft_add_manual', draft_id: '77' });
        expect(sent(2)).toMatchObject({ action: 'avbk_draft_save_receipt', draft_id: '77', receipt_id: '301', store: 'Kantine' });
        expect(document.getElementById('avbk-draft-status').classList.contains('avbk-draft-status-error')).toBe(true);
    });
});
