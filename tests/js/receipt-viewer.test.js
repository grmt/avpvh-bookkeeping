// The receipt viewer: reading text from a selected part, and the dialog itself.
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { browserStandIns, pointer, settle } from './setup.js';

let text;
let viewer;

beforeAll(async () => {
    browserStandIns();
    await import('../../assets/receipt-viewer.js');
    text = window.AVBKReceiptText;
    viewer = window.AVBKReceiptViewer;
});

describe('reading the selected text', () => {
    it('finds a date', () => {
        expect(text.date('01-08-2026')).toBe('2026-08-01');
        expect(text.date('Datum: 4/8/26 14:02')).toBe('2026-08-04');
        expect(text.date('02.08.2026')).toBe('2026-08-02');
        expect(text.date('2026-08-03')).toBe('2026-08-03');
        expect(text.date('31-02-2026 then 01-03-2026')).toBe('2026-03-01');
        expect(text.date('05-06-85')).toBe('1985-06-05');
        expect(text.date('Bon 1234567')).toBeNull();
        expect(text.date('2026-13-01')).toBeNull();
    });

    it('finds an amount', () => {
        expect(text.amount('TOTAAL 11,80')).toBe(11.8);
        expect(text.amount('Te betalen € 1.234,56')).toBe(1234.56);
        expect(text.amount('Subtotaal 10,00 Totaal 12,50')).toBe(12.5);
        expect(text.amount('59.05')).toBe(59.05);
        expect(text.amount('1,234.50')).toBe(1234.5);
        expect(text.amount('43')).toBe(43);
        expect(text.amount('€ 43,-')).toBe(43);
        expect(text.amount('11 ,80')).toBe(11.8);
        expect(text.amount('Kassa 12 bon 345')).toBeNull();
        expect(text.amount('')).toBeNull();
    });

    it('puts a shop name on one line', () => {
        expect(text.line('  Boulangerie\n  Pâtisserie  ')).toBe('Boulangerie Pâtisserie');
        expect(text.line('x'.repeat(150))).toHaveLength(100);
        expect(text.line(null)).toBe('');
    });
});

describe('the viewer', () => {
    let read;
    let apply;
    const dialog = () => document.querySelector('.avbk-receipt-viewer');
    const button = (label) => [...dialog().querySelectorAll('button')].find((b) => b.textContent === label);
    const image = () => dialog().querySelector('.avbk-receipt-viewer-image');
    const canvas = () => dialog().querySelector('.avbk-receipt-viewer-canvas');
    const hint = () => dialog().querySelector('.avbk-receipt-viewer-hint');
    const field = () => dialog().querySelector('.avbk-receipt-viewer-text');

    function open(engines = { google: 'Google' }) {
        read = vi.fn(() => Promise.resolve('Datum 01-08-2026 Totaal 11,80'));
        apply = vi.fn();
        viewer.open('blob:receipt', { read, apply, engines });
    }

    async function select(x1, y1, x2, y2) {
        pointer(canvas(), 'pointerdown', x1, y1, { pointerId: 1 });
        pointer(canvas(), 'pointermove', x2, y2);
        pointer(canvas(), 'pointerup', x2, y2);
        await settle();
    }

    beforeEach(() => {
        dialog()?.close();
    });

    it('opens the photo large, fitted, in select mode', () => {
        open();
        expect(dialog().open).toBe(true);
        expect(image().getAttribute('src')).toBe('blob:receipt');
        expect(button('Selecteren').getAttribute('aria-pressed')).toBe('true');
        expect(dialog().querySelector('.avbk-receipt-viewer-engine').hidden).toBe(true);
        expect(button('→ Datum').disabled).toBe(true);
    });

    it('offers an engine choice only with two engines', () => {
        open({ docling: 'Docling (eigen server)', google: 'Google' });
        const choice = dialog().querySelector('.avbk-receipt-viewer-engine');
        expect(choice.hidden).toBe(false);
        expect([...choice.querySelectorAll('option')].map((o) => o.value)).toEqual(['docling', 'google']);
    });

    it('zooms in, out and back to fit, also with Ctrl+scroll', () => {
        open();
        button('Passend').click();
        const fitted = Number.parseFloat(image().style.width);
        button('+').click();
        expect(Number.parseFloat(image().style.width)).toBeGreaterThan(fitted);
        button('−').click();
        button('−').click();
        expect(Number.parseFloat(image().style.width)).toBeLessThan(fitted * 1.25);
        const before = Number.parseFloat(image().style.width);
        const stage = dialog().querySelector('.avbk-receipt-viewer-stage');
        stage.dispatchEvent(new WheelEvent('wheel', { deltaY: -1, ctrlKey: true, cancelable: true }));
        expect(Number.parseFloat(image().style.width)).toBeGreaterThan(before);
        stage.dispatchEvent(new WheelEvent('wheel', { deltaY: -1, cancelable: true }));
    });

    it('reads a selected part and takes it over as date, amount or shop', async () => {
        open({ docling: 'Docling (eigen server)', google: 'Google' });
        dialog().querySelector('.avbk-receipt-viewer-engine select').value = 'google';
        await select(10, 10, 200, 60);
        expect(read).toHaveBeenCalledWith(expect.any(Blob), 'google');
        expect(field().value).toBe('Datum 01-08-2026 Totaal 11,80');
        expect(hint().textContent).toContain('Gelezen');

        button('→ Datum').click();
        expect(apply).toHaveBeenLastCalledWith('date', '2026-08-01');
        button('→ Bedrag').click();
        expect(apply).toHaveBeenLastCalledWith('amount', 11.8);
        field().value = 'Bakkerij  Voorbeeld';
        field().dispatchEvent(new Event('input'));
        expect(button('→ Datum').disabled).toBe(true);
        button('→ Winkel').click();
        expect(apply).toHaveBeenLastCalledWith('store', 'Bakkerij Voorbeeld');
        expect(hint().textContent).toContain('winkel');
    });

    it('ignores a click without a real selection', async () => {
        open();
        await select(10, 10, 12, 12);
        expect(read).not.toHaveBeenCalled();
        expect(dialog().querySelector('.avbk-receipt-viewer-selection').hidden).toBe(true);
    });

    it('says so when a part cannot be read', async () => {
        open();
        read.mockImplementation(() => Promise.reject(new Error('In dit stuk is geen tekst gevonden.')));
        await select(10, 10, 200, 60);
        expect(hint().textContent).toBe('In dit stuk is geen tekst gevonden.');
        expect(hint().classList.contains('avbk-receipt-viewer-hint-error')).toBe(true);
        read.mockImplementation(() => Promise.reject(new Error('')));
        await select(10, 10, 200, 60);
        expect(hint().textContent).toContain('Typ het over');
    });

    it('drops a late answer after the viewer closed', async () => {
        open();
        let answer;
        read.mockImplementation(() => new Promise((resolve) => { answer = resolve; }));
        await select(10, 10, 200, 60);
        dialog().close();
        answer('te laat');
        await settle();
        expect(field().value).toBe('');
    });

    it('moves the photo in move mode instead of selecting', async () => {
        open();
        button('Verschuiven').click();
        expect(canvas().classList.contains('avbk-receipt-viewer-moving')).toBe(true);
        const stage = dialog().querySelector('.avbk-receipt-viewer-stage');
        stage.scrollLeft = 50;
        await select(100, 100, 60, 100);
        expect(read).not.toHaveBeenCalled();
        expect(stage.scrollLeft).toBe(90);
        pointer(canvas(), 'pointercancel', 0, 0);
    });

    it('copies the read text', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
        open();
        await select(10, 10, 200, 60);
        button('Kopiëren').click();
        await settle();
        expect(writeText).toHaveBeenCalledWith('Datum 01-08-2026 Totaal 11,80');
        expect(hint().textContent).toBe('Gekopieerd.');
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
        const selectText = vi.spyOn(field(), 'select');
        button('Kopiëren').click();
        expect(selectText).toHaveBeenCalled();
    });

    it('closes on the close button and on a click beside the photo', () => {
        open();
        button('×').click();
        expect(dialog().open).toBe(false);
        open();
        dialog().dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(dialog().open).toBe(false);
        expect(image().getAttribute('src')).toBeNull();
    });
});
