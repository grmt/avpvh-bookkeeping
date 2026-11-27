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
            <table id="avbk-dropzone-table" hidden>
                <thead><tr><th>Foto</th><th><button type="button" id="avbk-sort-date">Datum <span class="avbk-sort-indicator">↕</span></button></th></tr></thead>
                <tbody id="avbk-dropzone-list"></tbody>
            </table>
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
        expect(viewer.update).toHaveBeenCalledWith(expect.objectContaining({
            values: { date: '2026-08-01', store: 'Bakkerij Voorbeeld', amount: 11.8 },
            candidates: found.candidates,
            reading: false,
            nav: expect.objectContaining({ index: 1, total: 1, hasPrev: false, hasNext: false }),
        }));

        options.onClose();
        viewer.update.mockClear();
        cell(rows()[0], '.avbk-dropzone-item-retry').click();
        await settle();
        expect(viewer.update).not.toHaveBeenCalled();
    });

    it('navigates to previous and next viewable receipts with total line count', async () => {
        await load();
        addFiles(photo('een.jpg'));
        document.getElementById('avbk-add-manual-row').click();
        addFiles(photo('drie.jpg'));
        await settle();

        cell(rows()[0], '.avbk-dropzone-item-zoom').click();
        const firstNav = viewer.open.mock.calls[0][1].nav;
        expect([firstNav.index, firstNav.total, firstNav.hasPrev, firstNav.hasNext]).toEqual([1, 3, false, true]);

        firstNav.next();
        const thirdNav = viewer.open.mock.calls[1][1].nav;
        expect([thirdNav.index, thirdNav.total, thirdNav.hasPrev, thirdNav.hasNext]).toEqual([3, 3, true, false]);
        expect(rows()[2].classList.contains('avbk-dropzone-item-active')).toBe(true);

        thirdNav.prev();
        expect(viewer.open.mock.calls[2][1].nav.index).toBe(1);
    });

    it('sorts rows by date ascending and descending, keeping undated rows at the bottom', async () => {
        await load();
        replies.push(reply({ success: true, data: { ...found, date: '2026-08-08', store: 'Acht' } }));
        replies.push(reply({ success: true, data: { ...found, date: null, store: 'Leeg' } }));
        replies.push(reply({ success: true, data: { ...found, date: '2026-08-02', store: 'Twee' } }));
        replies.push(reply({ success: true, data: { ...found, date: '2026-08-05', store: 'Vijf' } }));
        addFiles(photo('a.jpg'), photo('b.jpg'), photo('c.jpg'), photo('d.jpg'));
        await settle();

        const sortBtn = document.getElementById('avbk-sort-date');
        sortBtn.click();
        expect(rows().map((r) => cell(r, '.avbk-dropzone-item-store').value)).toEqual(['Twee', 'Vijf', 'Acht', 'Leeg']);
        expect(sortBtn.querySelector('.avbk-sort-indicator').textContent).toBe('↑');
        expect(sortBtn.closest('th').getAttribute('aria-sort')).toBe('ascending');

        sortBtn.click();
        expect(rows().map((r) => cell(r, '.avbk-dropzone-item-store').value)).toEqual(['Acht', 'Vijf', 'Twee', 'Leeg']);
        expect(sortBtn.querySelector('.avbk-sort-indicator').textContent).toBe('↓');
        expect(sortBtn.closest('th').getAttribute('aria-sort')).toBe('descending');
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
                <table id="avbk-dropzone-table" hidden>
                <thead><tr><th>Foto</th><th><button type="button" id="avbk-sort-date">Datum <span class="avbk-sort-indicator">↕</span></button></th></tr></thead>
                <tbody id="avbk-dropzone-list"></tbody>
            </table>
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
            replies.push(reply({ success: true, data: { draft_id: 42 } }));
            addFiles(photo());
            await vi.advanceTimersByTimeAsync(10);

            expect(sent(0)).toEqual({ action: 'avbk_draft_ensure', nonce: 'n', iban: 'NL00TEST0000000001' });
            expect(sent(1)).toMatchObject({ action: 'avbk_draft_add_receipt', draft_id: '42' });
            expect(sent(2)).toMatchObject({ action: 'avbk_draft_save', draft_id: '42', amount: '11,80' });
            expect(document.getElementById('avbk-draft-id').value).toBe('42');
            expect(document.getElementById('avbk-draft-bar').hidden).toBe(false);
            expect(cell(rows()[0], 'input[name="receipt_id[]"]').value).toBe('101');
            expect(document.getElementById('avbk-receipt-input').files).toHaveLength(0);

            // Editing via viewer saves immediately.
            replies.push(reply({ success: true, data: { saved: true } }));
            cell(rows()[0], '.avbk-dropzone-item-zoom').click();
            viewer.open.mock.calls[0][1].apply('store', 'Supermarkt');
            await vi.advanceTimersByTimeAsync(10);
            expect(sent(3)).toMatchObject({ action: 'avbk_draft_save_receipt', draft_id: '42', receipt_id: '101', store: 'Supermarkt' });

            // Leaving a table cell or changing a header field flushes immediately without waiting for debounce.
            replies.push(reply({ success: true, data: { saved: true } }));
            replies.push(reply({ success: true, data: { draft_id: 42 } }));
            const descIn = cell(rows()[0], '.avbk-dropzone-item-description');
            type(descIn, 'brood');
            descIn.dispatchEvent(new Event('blur'));
            document.getElementById('avbk-activity').value = '9';
            document.getElementById('avbk-activity').dispatchEvent(new Event('change'));
            await vi.advanceTimersByTimeAsync(10);
            const actions = fetch.mock.calls.slice(4).map((_, idx) => sent(4 + idx).action);
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
        expect(rows()[2].cells[0].textContent).toBe('+ Foto');
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

    it('attaches a photo to a draft manual row and handles duplicate and error replies', async () => {
        await loadDraftForm({
            draft: {
                id: 88,
                receipts: [
                    { id: 401, hasReceipt: false, isImage: false, isPdf: false, photoUrl: '', date: '', store: '', description: '', amount: null },
                ],
            },
        });
        const attach = () => cell(rows()[0], '.avbk-draft-attach-input');
        const pickFile = (file) => {
            const input = attach();
            Object.defineProperty(input, 'files', { writable: true, value: [file] });
            input.dispatchEvent(new Event('change'));
        };

        // Duplicate reply restores "+ Foto" and shows error status.
        replies.push(reply({ success: true, data: { duplicate: true } }));
        pickFile(photo('dup.jpg'));
        await settle();
        expect(attach()).not.toBeNull();
        expect(document.getElementById('avbk-draft-status').classList.contains('avbk-draft-status-error')).toBe(true);

        // Network failure restores "+ Foto".
        replies.push(Promise.reject(new Error('offline')));
        pickFile(photo('fail.jpg'));
        await settle();
        expect(attach()).not.toBeNull();

        // Successful attach replaces "+ Foto" with clickable photo and applies OCR values.
        replies.push(reply({ success: true, data: { photo_url: '/photo/401', is_image: true, is_pdf: false, date: '2026-08-06', store: 'Bakkerij', amount: 12.5, duplicate: false } }));
        replies.push(reply({ success: true, data: { draft_id: 88 } }));
        pickFile(photo('ok.jpg'));
        await settle();
        expect(cell(rows()[0], '.avbk-dropzone-item-photo').getAttribute('src')).toBe('blob:receipt');
        expect(cell(rows()[0], '.avbk-dropzone-item-store').value).toBe('Bakkerij');
        expect(cell(rows()[0], '.avbk-dropzone-item-amount').value).toBe('12,50');
    });

    it('handles duplicate upload, read failure, and discard failure in draft mode', async () => {
        await loadDraftForm();
        replies.push(reply({ success: true, data: { draft_id: 91 } }));
        replies.push(reply({ success: true, data: { duplicate: true } }));
        replies.push(Promise.reject(new Error('offline')));
        addFiles(photo('dup.jpg'), photo('err.jpg'));
        await settle();
        expect(rows()[0].classList.contains('avbk-dropzone-item-duplicate')).toBe(true);
        expect(cell(rows()[1], '.avbk-dropzone-item-progress').textContent).toBe('niets gelezen – vul zelf in');

        vi.stubGlobal('confirm', vi.fn(() => true));
        replies.push(Promise.reject(new Error('offline')));
        document.getElementById('avbk-draft-discard').click();
        await settle();
        expect(document.getElementById('avbk-draft-status').classList.contains('avbk-draft-status-error')).toBe(true);

        // Successful discard revokes blob URLs created for freshly added files.
        replies.push(reply({ success: true, data: { discarded: true } }));
        document.getElementById('avbk-draft-discard').click();
        await settle();
        expect(URL.revokeObjectURL).toHaveBeenCalled();
    });

    it('covers draft header errors, debounced saves, IBAN selector, blur flushes, and remove/manual failures', async () => {
        vi.useFakeTimers();
        try {
            await loadDraftForm({
                draft: {
                    id: 95,
                    receipts: [
                        { id: 501, hasReceipt: true, isImage: true, isPdf: false, photoUrl: '/p/501', date: '2026-08-05', store: 'Bakker', description: '', amount: 5.0 },
                        { id: 502, hasReceipt: true, isImage: true, isPdf: false, photoUrl: '/p/502', date: '2026-08-05', store: 'Slager', description: '', amount: 7.0 },
                    ],
                },
            });
            // Inject an IBAN selector pointing at #avbk-iban and change it (exercises saveDraftHeader error too).
            const form = document.getElementById('avbk-reimbursement-form');
            const ibanSelect = document.createElement('select');
            ibanSelect.className = 'avbk-iban-select';
            ibanSelect.dataset.target = 'avbk-iban';
            ibanSelect.innerHTML = '<option value="NL00TEST0000000001">A</option><option value="NL00TEST0000000002">B</option>';
            form.appendChild(ibanSelect);
            // Re-run DOMContentLoaded listener to bind the injected IBAN select cleanly.
            start();

            replies.push(Promise.reject(new Error('offline')));
            ibanSelect.value = 'NL00TEST0000000002';
            ibanSelect.dispatchEvent(new Event('change'));
            await vi.advanceTimersByTimeAsync(10);
            expect(document.getElementById('avbk-draft-status').classList.contains('avbk-draft-status-error')).toBe(true);

            // Debounced header save via input on #avbk-member-note.
            replies.push(reply({ success: true, data: { draft_id: 95 } }));
            const note = document.getElementById('avbk-member-note');
            note.value = 'toelichting';
            note.dispatchEvent(new Event('input'));
            await vi.advanceTimersByTimeAsync(450);
            expect(fetch.mock.calls.length).toBeGreaterThanOrEqual(2);

            // Sort two rows with identical dates (da === db -> 0).
            document.getElementById('avbk-sort-date').click();

            // Blur on date and amount inputs flushes dirty state immediately.
            replies.push(reply({ success: true, data: { saved: true } }));
            const dateIn = cell(rows()[0], '.avbk-dropzone-item-date');
            type(dateIn, '06-08-2026');
            dateIn.dispatchEvent(new Event('blur'));
            await vi.advanceTimersByTimeAsync(10);

            replies.push(reply({ success: true, data: { saved: true } }));
            const amtIn = cell(rows()[0], '.avbk-dropzone-item-amount');
            type(amtIn, '9,50');
            amtIn.dispatchEvent(new Event('blur'));
            await vi.advanceTimersByTimeAsync(10);

            // Debounced receipt save via timer.
            replies.push(reply({ success: true, data: { saved: true } }));
            type(cell(rows()[0], '.avbk-dropzone-item-store'), 'Winkel X');
            await vi.advanceTimersByTimeAsync(450);

            // Remove receipt failure sets error status.
            replies.push(Promise.reject(new Error('offline')));
            cell(rows()[1], '.avbk-dropzone-item-remove').click();
            await vi.advanceTimersByTimeAsync(10);
            expect(document.getElementById('avbk-draft-status').classList.contains('avbk-draft-status-error')).toBe(true);

            // Manual row add failure + blur while receiptId is still missing.
            let failManual;
            replies.push(new Promise((_, reject) => { failManual = reject; }));
            document.getElementById('avbk-add-manual-row').click();
            await vi.advanceTimersByTimeAsync(10);
            const manualStore = cell(rows().at(-1), '.avbk-dropzone-item-store');
            type(manualStore, 'Nog geen id');
            manualStore.dispatchEvent(new Event('blur'));
            failManual(new Error('offline'));
            await vi.advanceTimersByTimeAsync(10);
            expect(document.getElementById('avbk-draft-status').classList.contains('avbk-draft-status-error')).toBe(true);
        } finally {
            vi.useRealTimers();
        }
    });
});

describe('pending declaration receipt table', () => {
    function loadPendingTable() {
        document.body.innerHTML = `
            <form id="avbk-reimbursement-form">
                <div id="avbk-dropzone"><input type="file" id="avbk-receipt-input" multiple></div>
                <p id="avbk-ocr-status" hidden></p>
                <table id="avbk-dropzone-table" hidden><tbody id="avbk-dropzone-list"></tbody></table>
                <input id="avbk-amount">
            </form>
            <table><tbody><tr class="avbk-reimbursement-edit-row"><td>
                <div class="avbk-pending-receipts" data-reimbursement-id="7">
                    <div class="avbk-pending-toolbar">
                        <input type="search" class="avbk-pending-filter">
                        <span class="avbk-pending-status"></span>
                    </div>
                    <table class="avbk-pending-table">
                        <thead><tr>
                            <th>Foto</th>
                            <th><button type="button" class="avbk-dropzone-sort" data-sort="date">Datum <span class="avbk-sort-indicator">↕</span></button></th>
                            <th><button type="button" class="avbk-dropzone-sort" data-sort="store">Winkel <span class="avbk-sort-indicator">↕</span></button></th>
                            <th><button type="button" class="avbk-dropzone-sort" data-sort="description">Omschrijving <span class="avbk-sort-indicator">↕</span></button></th>
                            <th><button type="button" class="avbk-dropzone-sort" data-sort="amount">Bedrag <span class="avbk-sort-indicator">↕</span></button></th>
                            <th></th>
                        </tr></thead>
                        <tbody>
                            <tr class="avbk-pending-row" data-receipt-id="11" data-date="2026-08-08" data-photo-url="/photo/11">
                                <td><button type="button" class="avbk-pending-zoom"><img src="/photo/11"></button></td>
                                <td>
                                    <input class="avbk-pending-input" data-field="date" value="08-08-2026">
                                    <button type="button" class="avbk-pending-date-pick">📅</button>
                                    <input type="date" class="avbk-pending-date-picker" value="2026-08-08">
                                </td>
                                <td><input class="avbk-pending-input" data-field="store" value="Bakker"></td>
                                <td><input class="avbk-pending-input" data-field="description" value="brood"></td>
                                <td><input class="avbk-pending-input" data-field="amount" value="53,80"></td>
                                <td></td>
                            </tr>
                            <tr class="avbk-pending-row" data-receipt-id="12" data-date="" data-photo-url="">
                                <td><label class="avbk-attach-photo"><span>+ Foto</span><input type="file" class="avbk-pending-attach-input" hidden></label></td>
                                <td><input class="avbk-pending-input" data-field="date" value=""></td>
                                <td><input class="avbk-pending-input" data-field="store" value="Kantine"></td>
                                <td><input class="avbk-pending-input" data-field="description" value=""></td>
                                <td><input class="avbk-pending-input" data-field="amount" value="10,00"></td>
                                <td></td>
                            </tr>
                            <tr class="avbk-pending-row" data-receipt-id="13" data-date="2026-08-02" data-photo-url="/photo/13">
                                <td><button type="button" class="avbk-pending-zoom"><img src="/photo/13"></button></td>
                                <td><input class="avbk-pending-input" data-field="date" value="02-08-2026"></td>
                                <td><input class="avbk-pending-input" data-field="store" value="Aldi"></td>
                                <td><input class="avbk-pending-input" data-field="description" value="appels"></td>
                                <td><input class="avbk-pending-input" data-field="amount" value="20,20"></td>
                                <td></td>
                            </tr>
                        </tbody>
                        <tfoot><tr>
                            <td colspan="4"><strong class="avbk-pending-count"></strong></td>
                            <td><strong class="avbk-pending-total"></strong></td>
                            <td></td>
                        </tr></tfoot>
                    </table>
                </div>
                <form><input name="amount" value="84,00"></form>
            </td></tr></tbody></table>
            <script type="application/json" id="avbk-reimbursement-config">${JSON.stringify({ ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'n', engines: { google: 'Google' } })}</script>`;
        Object.defineProperty(document.getElementById('avbk-receipt-input'), 'files', { writable: true, value: [] });
        start();
    }

    it('sorts by date, store and amount, filters with live totals, and saves row edits', async () => {
        loadPendingTable();
        const pendingRows = () => [...document.querySelectorAll('.avbk-pending-row')];
        const stores = () => pendingRows().map((r) => r.querySelector('[data-field="store"]').value);

        expect(document.querySelector('.avbk-pending-count').textContent).toBe('Totaal (3 regels)');
        expect(document.querySelector('.avbk-pending-total').textContent).toBe('€ 84,00');

        // Sort by date asc -> undated row stays at bottom.
        document.querySelector('.avbk-dropzone-sort[data-sort="date"]').click();
        expect(stores()).toEqual(['Aldi', 'Bakker', 'Kantine']);
        // Sort by amount desc.
        const amtSort = document.querySelector('.avbk-dropzone-sort[data-sort="amount"]');
        amtSort.click();
        expect(stores()).toEqual(['Kantine', 'Aldi', 'Bakker']);
        amtSort.click();
        expect(stores()).toEqual(['Bakker', 'Aldi', 'Kantine']);
        // Sort by store asc.
        document.querySelector('.avbk-dropzone-sort[data-sort="store"]').click();
        expect(stores()).toEqual(['Aldi', 'Bakker', 'Kantine']);

        // Filter by text -> updates count and visible total.
        const filter = document.querySelector('.avbk-pending-filter');
        type(filter, 'bakker');
        expect(pendingRows().filter((r) => !r.hidden)).toHaveLength(1);
        expect(document.querySelector('.avbk-pending-count').textContent).toBe('Totaal (1 van 3 regels)');
        expect(document.querySelector('.avbk-pending-total').textContent).toBe('€ 53,80');
        type(filter, '');
        expect(document.querySelector('.avbk-pending-total').textContent).toBe('€ 84,00');

        // Edit amount -> live total + parent declaration amount update, and blur saves via AJAX.
        replies.push(reply({ success: true, data: { saved: true } }));
        const bakkerRow = pendingRows().find((r) => r.dataset.receiptId === '11');
        const amtIn = bakkerRow.querySelector('[data-field="amount"]');
        type(amtIn, '60,00');
        amtIn.dispatchEvent(new Event('blur'));
        await settle();
        expect(document.querySelector('.avbk-pending-total').textContent).toBe('€ 90,20');
        expect(document.querySelector('form input[name="amount"]').value).toBe('90,20');
        expect(sent(0)).toMatchObject({ action: 'avbk_member_update_receipt', reimbursement_id: '7', receipt_id: '11', amount: '60' });

        // Open photo in viewer -> navigate and apply an edit directly.
        replies.push(reply({ success: true, data: { saved: true } }));
        bakkerRow.querySelector('.avbk-pending-zoom').click();
        const [src, options] = viewer.open.mock.calls[0];
        expect(src).toBe('/photo/11');
        expect([options.nav.index, options.nav.total, options.nav.hasPrev, options.nav.hasNext]).toEqual([2, 3, true, false]);
        options.apply('store', 'Bakkerij');
        await settle();
        expect(bakkerRow.querySelector('[data-field="store"]').value).toBe('Bakkerij');
        expect(sent(1)).toMatchObject({ action: 'avbk_member_update_receipt', receipt_id: '11', store: 'Bakkerij' });
        options.nav.prev();
        expect(viewer.open.mock.calls[1][0]).toBe('/photo/13');
        options.onClose();

        // Attach a photo to the photo-less row -> replaces "+ Foto" with clickable thumbnail and fills empty date.
        const kantineRow = pendingRows().find((r) => r.dataset.receiptId === '12');
        const attachIn = kantineRow.querySelector('.avbk-pending-attach-input');
        Object.defineProperty(attachIn, 'files', { writable: true, value: [photo('kantine.jpg')] });
        replies.push(reply({
            success: true,
            data: { photo_url: '/photo/12', is_image: true, is_pdf: false, date: '2026-08-04', store: 'OCR Winkel', amount: 99, duplicate: false },
        }));
        attachIn.dispatchEvent(new Event('change'));
        await settle();
        expect(kantineRow.dataset.photoUrl).toBe('/photo/12');
        expect(kantineRow.querySelector('.avbk-pending-zoom img').getAttribute('src')).toBe('/photo/12');
        expect(kantineRow.querySelector('[data-field="date"]').value).toBe('04-08-2026');
        expect(kantineRow.querySelector('[data-field="store"]').value).toBe('Kantine');
        expect(kantineRow.querySelector('[data-field="amount"]').value).toBe('10,00');

        // Date editing, calendar picker (with fallback), viewer date/amount apply, and error status on save failure.
        const dateIn = bakkerRow.querySelector('[data-field="date"]');
        replies.push(reply({ success: true, data: { saved: true } }));
        type(dateIn, '4-8-26');
        dateIn.dispatchEvent(new Event('blur'));
        await settle();
        expect(dateIn.value).toBe('04-08-2026');
        type(dateIn, 'onzin');
        expect(dateIn.classList.contains('avbk-dropzone-item-invalid')).toBe(true);

        const picker = bakkerRow.querySelector('.avbk-pending-date-picker');
        const pickBtn = bakkerRow.querySelector('.avbk-pending-date-pick');
        picker.showPicker = vi.fn();
        pickBtn.click();
        expect(picker.showPicker).toHaveBeenCalled();
        picker.showPicker = () => { throw new Error('denied'); };
        const clickSpy = vi.spyOn(picker, 'click');
        pickBtn.click();
        expect(clickSpy).toHaveBeenCalled();
        replies.push(reply({ success: true, data: { saved: true } }));
        picker.value = '2026-08-07';
        picker.dispatchEvent(new Event('change'));
        await settle();
        expect(dateIn.value).toBe('07-08-2026');

        // Viewer apply date & amount + save failure indicator.
        replies.push(reply({ success: true, data: { saved: true } }));
        bakkerRow.querySelector('.avbk-pending-zoom').click();
        const latestViewer = viewer.open.mock.calls.at(-1)[1];
        latestViewer.apply('date', '2026-08-09');
        await settle();
        replies.push(Promise.reject(new Error('offline')));
        latestViewer.apply('amount', 45.5);
        await settle();
        expect(dateIn.value).toBe('09-08-2026');
        expect(bakkerRow.querySelector('[data-field="amount"]').value).toBe('45,50');
        expect(document.querySelector('.avbk-pending-status').classList.contains('avbk-draft-status-error')).toBe(true);
    });

    it('handles pending photo attach duplicate, PDF link, and failure', async () => {
        loadPendingTable();
        const kantineRow = [...document.querySelectorAll('.avbk-pending-row')].find((r) => r.dataset.receiptId === '12');
        kantineRow.querySelector('[data-field="store"]').value = '';
        kantineRow.querySelector('[data-field="amount"]').value = '';
        const attachIn = kantineRow.querySelector('.avbk-pending-attach-input');
        const sendFile = (file) => {
            Object.defineProperty(attachIn, 'files', { writable: true, value: [file] });
            attachIn.dispatchEvent(new Event('change'));
        };

        replies.push(reply({ success: true, data: { duplicate: true } }));
        sendFile(photo('dup.jpg'));
        await settle();
        expect(document.querySelector('.avbk-pending-status').classList.contains('avbk-draft-status-error')).toBe(true);

        replies.push(Promise.reject(new Error('offline')));
        sendFile(photo('err.jpg'));
        await settle();
        expect(document.querySelector('.avbk-pending-status').textContent).toContain('mislukt');

        replies.push(reply({ success: true, data: { photo_url: '/doc/12', is_image: false, is_pdf: true, date: '', store: 'PDF Winkel', amount: 15.0, duplicate: false } }));
        sendFile(new File(['%PDF'], 'bon.pdf', { type: 'application/pdf' }));
        await settle();
        expect(kantineRow.cells[0].textContent).toBe('pdf');
        expect(kantineRow.querySelector('[data-field="store"]').value).toBe('PDF Winkel');
        expect(kantineRow.querySelector('[data-field="amount"]').value).toBe('15,00');
    });

    it('covers pending store/description saves, nav.next, empty-pair sort, custom parent total, and clicking newly attached photo', async () => {
        vi.useFakeTimers();
        try {
            loadPendingTable();
            const pendingRows = () => [...document.querySelectorAll('.avbk-pending-row')];
            const row11 = pendingRows().find((r) => r.dataset.receiptId === '11');
            const row12 = pendingRows().find((r) => r.dataset.receiptId === '12');
            const row13 = pendingRows().find((r) => r.dataset.receiptId === '13');

            // Make two rows have empty descriptions and sort by description (hits aEmpty && bEmpty).
            row11.querySelector('[data-field="description"]').value = '';
            row12.querySelector('[data-field="description"]').value = '';
            document.querySelector('.avbk-dropzone-sort[data-sort="description"]').click();

            // Store input + blur saves immediately; description input saves after 400ms debounce.
            replies.push(reply({ success: true, data: { saved: true } }));
            const storeIn = row11.querySelector('[data-field="store"]');
            type(storeIn, 'Nieuwe Bakker');
            storeIn.dispatchEvent(new Event('blur'));
            await vi.advanceTimersByTimeAsync(10);

            replies.push(reply({ success: true, data: { saved: true } }));
            const descIn = row11.querySelector('[data-field="description"]');
            type(descIn, 'broodjes');
            await vi.advanceTimersByTimeAsync(450);

            // Manually edit parent amount away from the sum, then edit a row amount -> parent custom total is preserved.
            const parentAmt = document.querySelector('form input[name="amount"]');
            parentAmt.value = '150,00';
            type(row11.querySelector('[data-field="amount"]'), '55,00');
            expect(parentAmt.value).toBe('150,00');

            // Viewer nav.next() from row13 -> row11 (after sorting by description, row13 is first).
            row13.querySelector('.avbk-pending-zoom').click();
            const opts13 = viewer.open.mock.calls.at(-1)[1];
            expect(opts13.nav.hasNext).toBe(true);
            opts13.nav.next();
            expect(viewer.open.mock.calls.at(-1)[0]).toBe('/photo/11');

            // Attach image photo to row12, then click its newly created zoom button.
            const attachIn = row12.querySelector('.avbk-pending-attach-input');
            Object.defineProperty(attachIn, 'files', { writable: true, value: [photo('extra.jpg')] });
            replies.push(reply({ success: true, data: { photo_url: '/photo/12-new', is_image: true, is_pdf: false, date: '', store: '', amount: null, duplicate: false } }));
            attachIn.dispatchEvent(new Event('change'));
            await vi.advanceTimersByTimeAsync(10);
            row12.querySelector('.avbk-pending-zoom').click();
            expect(viewer.open.mock.calls.at(-1)[0]).toBe('/photo/12-new');
        } finally {
            vi.useRealTimers();
        }
    });
});
