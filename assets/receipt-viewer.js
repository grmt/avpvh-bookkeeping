/**
 * Receipt viewer for the declaration form: a large, zoomable view of a
 * receipt photo. Dragging over a part of it reads that part (OCR on the
 * server) so a date, shop or amount can be taken over into the row.
 *
 * window.AVBKReceiptText: parsing the read text (also used by the tests).
 * window.AVBKReceiptViewer.open(src, options): options.read(blob, engine)
 * returns a promise of the text; options.apply(field, value) takes over a
 * value; options.engines (code => name) offers a choice when there are two;
 * options.values ({ date, store, amount }), options.candidates and
 * options.reading show what was read so far and allow direct edits;
 * options.nav ({ index, total, hasPrev, hasNext, prev, next }) steps
 * between receipts without closing the dialog;
 * options.onClose() runs when the dialog closes.
 * window.AVBKReceiptViewer.update(info): refreshes values, candidates and
 * reading state while the dialog is open.
 */
(function () {
    var MONTHS = {
        jan: 1, januari: 1, january: 1, janv: 1, janvier: 1,
        feb: 2, februari: 2, february: 2, fev: 2, fevr: 2, fevrier: 2,
        mrt: 3, maa: 3, maart: 3, mar: 3, march: 3, mars: 3, marz: 3,
        apr: 4, april: 4, avr: 4, avril: 4,
        mei: 5, may: 5, mai: 5,
        jun: 6, juni: 6, june: 6, juin: 6,
        jul: 7, juli: 7, july: 7, juil: 7, juillet: 7,
        aug: 8, augustus: 8, august: 8, aout: 8,
        sep: 9, sept: 9, september: 9, septembre: 9,
        okt: 10, oktober: 10, oct: 10, october: 10, octobre: 10,
        nov: 11, november: 11, novembre: 11,
        dec: 12, december: 12, decembre: 12, dez: 12, dezember: 12
    };

    function monthNumber(word) {
        var clean = word.toLowerCase().normalize('NFD').replaceAll(/[\u0300-\u036f]/g, '');
        return MONTHS[clean] || null;
    }

    function expandYear(raw) {
        var year = +raw;
        return raw.length === 2 ? year + (year < 70 ? 2000 : 1900) : year;
    }

    /** First valid date as yyyy-mm-dd: dd-mm-yyyy, dd.mm.yy, dd/mm/yyyy, dd.mm yyyy, dd mmm yy or yyyy-mm-dd. */
    function date(text) {
        var value = String(text || '');
        var m = value.match(/(?:^|\D)(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/);
        if (m) {
            return iso(+m[1], +m[2], +m[3]);
        }
        var patterns = [
            /(?:^|\D)(\d{1,2})\s*[./-]\s*(\d{1,2})\s*[./-]\s*(\d{4}|\d{2})(?!\d)/g,
            /(?:^|\D)(\d{1,2})[\s./-]+(\d{1,2})[\s./-]+((?:19|20)\d{2})(?!\d)/g
        ];
        for (var i = 0; i < patterns.length; i++) {
            while ((m = patterns[i].exec(value))) {
                var found = iso(expandYear(m[3]), +m[2], +m[1]);
                if (found) return found;
            }
        }
        var named = /(?:^|\D)(\d{1,2})[\s./-]+([a-zA-Z\u00C0-\u017F]{3,10})\.?[\s,./-]+(\d{4}|\d{2})(?!\d)/g;
        while ((m = named.exec(value))) {
            var month = monthNumber(m[2]);
            var foundNamed = month ? iso(expandYear(m[3]), month, +m[1]) : null;
            if (foundNamed) return foundNamed;
        }
        return null;
    }

    function iso(year, month, day) {
        var d = new Date(Date.UTC(year, month - 1, day));
        if (d.getUTCFullYear() !== year || d.getUTCMonth() !== month - 1 || d.getUTCDate() !== day) return null;
        return year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
    }

    /** The amount in the text: the last one with cents, else a lone whole number. */
    function amount(text) {
        var value = String(text || '').replaceAll(/(\d)\s+([.,]\d{2})(?!\d)/g, '$1$2');
        var withCents = value.match(/\d{1,3}(?:\.\d{3})+,\d{2}(?!\d)|\d{1,3}(?:,\d{3})+\.\d{2}(?!\d)|\d+[.,]\d{2}(?!\d)/g);
        var raw = withCents ? withCents[withCents.length - 1] : null;
        if (!raw) {
            var whole = value.trim().match(/^(?:€\s*)?(\d{1,5})(?:\s*€|,-)?$/);
            raw = whole ? whole[1] : null;
        }
        if (!raw) return null;
        var normal = /,\d{2}$/.test(raw) ? raw.replaceAll('.', '').replace(',', '.') : raw.replaceAll(/,(?=\d{3})/g, '');
        var n = Number.parseFloat(normal);
        return Number.isNaN(n) ? null : Math.round(n * 100) / 100;
    }

    /** The read text on one line, for a shop name. */
    function line(text) {
        return String(text || '').replaceAll(/\s+/g, ' ').trim().slice(0, 100);
    }

    /** "2026-08-01" -> "01-08-2026". */
    function formatDate(isoValue) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoValue || '');
        return m ? m[3] + '-' + m[2] + '-' + m[1] : '';
    }

    /** 11.8 -> "11,80". */
    function formatAmount(n) {
        return n === null || n === undefined ? '' : n.toFixed(2).replace('.', ',');
    }

    /** Parses a typed date (dd-mm-jjjj, dd.mm jjjj, dd mmm jj, or yyyy-mm-dd) -> yyyy-mm-dd, or null. */
    function parseInputDate(text) {
        var value = String(text || '').trim();
        var looksLikeDate = /^\d{1,2}\s*[./-]\s*\d{1,2}\s*[./-]\s*(\d{2}|\d{4})$/.test(value)
            || /^\d{1,2}[\s./-]+\d{1,2}[\s./-]+(?:19|20)\d{2}$/.test(value)
            || /^\d{4}-\d{2}-\d{2}$/.test(value)
            || /^\d{1,2}[\s./-]+[a-zA-Z\u00C0-\u017F]{3,10}\.?[\s,./-]+(\d{2}|\d{4})$/.test(value);
        return looksLikeDate ? date(value) : null;
    }

    /** Parses a typed amount ("11,8", "11.80", "1.234,56"); null when invalid or empty. */
    function parseInputAmount(text) {
        var value = String(text || '').replaceAll(/[\s€]/g, '');
        if (/^\d{1,3}(\.\d{3})+(,\d+)?$/.test(value)) {
            value = value.replaceAll('.', '');
        }
        if (!/^\d+(?:[.,]\d+)?$/.test(value)) return null;
        var n = Number.parseFloat(value.replace(',', '.'));
        return Number.isNaN(n) ? null : Math.round(n * 100) / 100;
    }

    window.AVBKReceiptText = Object.freeze({ date: date, amount: amount, line: line });

    if (typeof document === 'undefined' || !document.createElement) return;

    var dialog = null;
    var ui = {};
    var state = { scale: 1, mode: 'select', options: null, start: null, reading: false };

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    function button(text, label, onClick, className) {
        var b = el('button', 'avbk-receipt-viewer-button' + (className ? ' ' + className : ''), text);
        b.type = 'button';
        if (label) {
            b.setAttribute('aria-label', label);
            b.title = label;
        }
        b.addEventListener('click', onClick);
        return b;
    }

    function buildField(field, labelText, ariaLabel, placeholder, inputMode, extraClass) {
        var wrap = el('div', 'avbk-receipt-viewer-field');
        var id = 'avbk-viewer-field-' + field;
        var label = el('label', 'avbk-receipt-viewer-field-label', labelText);
        label.htmlFor = id;
        wrap.appendChild(label);
        var input = el('input', 'avbk-receipt-viewer-field-input' + (extraClass ? ' ' + extraClass : ''));
        input.id = id;
        input.type = 'text';
        input.dataset.field = field;
        input.placeholder = placeholder;
        input.setAttribute('aria-label', ariaLabel);
        if (inputMode) input.inputMode = inputMode;
        var list = el('datalist');
        list.id = 'avbk-viewer-suggest-' + field;
        input.setAttribute('list', list.id);
        if (field === 'date') {
            var row = el('span', 'avbk-receipt-viewer-date-wrap');
            var picker = el('input', 'avbk-receipt-viewer-date-picker');
            picker.type = 'date';
            picker.tabIndex = -1;
            picker.setAttribute('aria-hidden', 'true');
            var pick = button('📅', 'Kies een datum', openDatePicker, 'avbk-receipt-viewer-date-pick');
            row.appendChild(input);
            row.appendChild(pick);
            row.appendChild(picker);
            wrap.appendChild(row);
            ui.datePicker = picker;
        } else {
            wrap.appendChild(input);
        }
        wrap.appendChild(list);
        ui.fields[field] = input;
        ui.lists[field] = list;
        return wrap;
    }

    function openDatePicker() {
        ui.datePicker.value = parseInputDate(ui.fields.date.value) || '';
        try {
            ui.datePicker.showPicker();
        } catch (error) { // showPicker() missing or refused: the picker still opens on a click.
            ui.datePicker.focus();
            ui.datePicker.click();
        }
    }

    function wireFieldInputs() {
        ui.fields.date.addEventListener('input', function () {
            var raw = ui.fields.date.value;
            var parsed = parseInputDate(raw);
            ui.fields.date.classList.toggle('avbk-receipt-viewer-field-invalid', !parsed && raw.trim() !== '');
            updateEmpty(ui.fields.date);
            if (state.options) state.options.apply('date', parsed || '');
        });
        ui.fields.date.addEventListener('blur', function () {
            var parsed = parseInputDate(ui.fields.date.value);
            if (parsed) ui.fields.date.value = formatDate(parsed);
        });
        ui.datePicker.addEventListener('change', function () {
            var picked = ui.datePicker.value || '';
            setFieldDisplay('date', picked);
            if (state.options) state.options.apply('date', picked);
        });
        ui.fields.store.addEventListener('input', function () {
            updateEmpty(ui.fields.store);
            if (state.options) state.options.apply('store', ui.fields.store.value);
        });
        ui.fields.amount.addEventListener('input', function () {
            var raw = ui.fields.amount.value;
            var parsed = parseInputAmount(raw);
            ui.fields.amount.classList.toggle('avbk-receipt-viewer-field-invalid', parsed === null && raw.trim() !== '');
            updateEmpty(ui.fields.amount);
            if (state.options) state.options.apply('amount', parsed);
        });
        ui.fields.amount.addEventListener('blur', function () {
            var parsed = parseInputAmount(ui.fields.amount.value);
            if (parsed !== null) ui.fields.amount.value = formatAmount(parsed);
        });
    }

    function buildValues() {
        var section = el('div', 'avbk-receipt-viewer-values');
        section.appendChild(el('h3', 'avbk-receipt-viewer-heading', 'Gelezen van dit bonnetje'));
        ui.reading = el('p', 'avbk-receipt-viewer-reading', 'Bonnetje wordt nog gelezen…');
        ui.reading.hidden = true;
        section.appendChild(ui.reading);
        ui.fields = {};
        ui.lists = {};
        var grid = el('div', 'avbk-receipt-viewer-fields');
        grid.appendChild(buildField('date', 'Datum', 'Datum (dd-mm-jjjj)', 'dd-mm-jjjj', 'numeric', ''));
        grid.appendChild(buildField('store', 'Winkel', 'Winkel', 'niet gevonden', '', ''));
        grid.appendChild(buildField('amount', 'Bedrag (€)', 'Bedrag', '0,00', 'decimal', 'avbk-receipt-viewer-field-amount'));
        wireFieldInputs();
        section.appendChild(grid);
        return section;
    }

    function buildOcr() {
        var section = el('div', 'avbk-receipt-viewer-ocr');
        ui.hint = el('p', 'avbk-receipt-viewer-hint', 'Sleep over de datum, de winkel of het bedrag om het over te nemen.');
        ui.text = el('input', 'avbk-receipt-viewer-text');
        ui.text.type = 'text';
        ui.text.setAttribute('aria-label', 'Gelezen tekst');
        ui.text.placeholder = 'Gelezen tekst';
        ui.text.addEventListener('input', updateActions);
        ui.actions = el('div', 'avbk-receipt-viewer-actions');
        ui.toDate = button('→ Datum', '', function () { take('date', date(ui.text.value)); });
        ui.toStore = button('→ Winkel', '', function () { take('store', line(ui.text.value)); });
        ui.toAmount = button('→ Bedrag', '', function () { take('amount', amount(ui.text.value)); });
        ui.copy = button('Kopiëren', '', copy);
        [ui.toDate, ui.toStore, ui.toAmount, ui.copy].forEach(function (b) { ui.actions.appendChild(b); });
        ui.engineWrap = el('label', 'avbk-receipt-viewer-engine', 'Lezen met ');
        ui.engine = el('select');
        ui.engineWrap.appendChild(ui.engine);
        ui.engineWrap.hidden = true;
        section.appendChild(ui.engineWrap);
        section.appendChild(ui.hint);
        section.appendChild(ui.text);
        section.appendChild(ui.actions);
        return section;
    }

    function build() {
        dialog = el('dialog', 'avbk-receipt-viewer');
        dialog.setAttribute('aria-label', 'Bonnetje bekijken');

        var bar = el('div', 'avbk-receipt-viewer-bar');
        bar.appendChild(button('−', 'Kleiner', function () { zoom(state.scale / 1.25); }));
        bar.appendChild(button('+', 'Groter', function () { zoom(state.scale * 1.25); }));
        bar.appendChild(button('Passend', 'Passend in het venster', fit));
        ui.selectMode = button('Selecteren', 'Sleep over een stuk om het te lezen', function () { setMode('select'); });
        ui.moveMode = button('Verschuiven', 'Sleep om de foto te verschuiven', function () { setMode('move'); });
        bar.appendChild(ui.selectMode);
        bar.appendChild(ui.moveMode);
        var barEnd = el('div', 'avbk-receipt-viewer-bar-end');
        ui.nav = el('div', 'avbk-receipt-viewer-nav');
        ui.prev = button('‹ Vorige', 'Vorig bonnetje', function () {
            if (state.options && state.options.nav && state.options.nav.prev) state.options.nav.prev();
        });
        ui.counter = el('span', 'avbk-receipt-viewer-counter');
        ui.counter.setAttribute('aria-live', 'polite');
        ui.next = button('Volgende ›', 'Volgend bonnetje', function () {
            if (state.options && state.options.nav && state.options.nav.next) state.options.nav.next();
        });
        ui.nav.appendChild(ui.prev);
        ui.nav.appendChild(ui.counter);
        ui.nav.appendChild(ui.next);
        ui.nav.hidden = true;
        var close = button('×', 'Sluiten', function () { dialog.close(); }, 'avbk-receipt-viewer-close');
        barEnd.appendChild(ui.nav);
        barEnd.appendChild(close);
        bar.appendChild(barEnd);
        dialog.appendChild(bar);

        var body = el('div', 'avbk-receipt-viewer-body');
        ui.stage = el('div', 'avbk-receipt-viewer-stage');
        ui.canvas = el('div', 'avbk-receipt-viewer-canvas');
        ui.img = el('img', 'avbk-receipt-viewer-image');
        ui.img.alt = 'Bonnetje';
        ui.img.draggable = false;
        ui.box = el('div', 'avbk-receipt-viewer-selection');
        ui.box.hidden = true;
        ui.canvas.appendChild(ui.img);
        ui.canvas.appendChild(ui.box);
        ui.stage.appendChild(ui.canvas);
        body.appendChild(ui.stage);

        var panel = el('div', 'avbk-receipt-viewer-panel');
        panel.appendChild(buildValues());
        panel.appendChild(buildOcr());
        body.appendChild(panel);
        dialog.appendChild(body);

        ui.canvas.addEventListener('pointerdown', down);
        ui.canvas.addEventListener('pointermove', move);
        ui.canvas.addEventListener('pointerup', up);
        ui.canvas.addEventListener('pointercancel', cancel);
        ui.stage.addEventListener('wheel', function (e) {
            if (!e.ctrlKey) return;
            e.preventDefault();
            zoom(state.scale * (e.deltaY < 0 ? 1.1 : 1 / 1.1));
        }, { passive: false });
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) dialog.close();
        });
        dialog.addEventListener('keydown', function (e) {
            var nav = state.options && state.options.nav;
            if (!nav || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
            var tag = e.target && e.target.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            if (e.key === 'ArrowLeft' && nav.hasPrev && nav.prev) {
                e.preventDefault();
                nav.prev();
            } else if (e.key === 'ArrowRight' && nav.hasNext && nav.next) {
                e.preventDefault();
                nav.next();
            }
        });
        dialog.addEventListener('close', function () {
            var onClose = state.options && state.options.onClose;
            state.options = null;
            state.reading = false;
            ui.img.removeAttribute('src');
            if (onClose) onClose();
        });
        document.body.appendChild(dialog);
    }

    function setMode(mode) {
        state.mode = mode;
        ui.canvas.classList.toggle('avbk-receipt-viewer-moving', mode === 'move');
        ui.selectMode.setAttribute('aria-pressed', String(mode === 'select'));
        ui.moveMode.setAttribute('aria-pressed', String(mode === 'move'));
    }

    function zoom(scale) {
        var natural = ui.img.naturalWidth;
        if (!natural) return;
        var stage = ui.stage;
        var centerX = (stage.scrollLeft + stage.clientWidth / 2) / state.scale;
        var centerY = (stage.scrollTop + stage.clientHeight / 2) / state.scale;
        state.scale = Math.min(8, Math.max(0.05, scale));
        ui.img.style.width = Math.round(natural * state.scale) + 'px';
        stage.scrollLeft = centerX * state.scale - stage.clientWidth / 2;
        stage.scrollTop = centerY * state.scale - stage.clientHeight / 2;
    }

    function fit() {
        var w = ui.img.naturalWidth;
        var h = ui.img.naturalHeight;
        if (!w || !h) return;
        zoom(Math.min(1, (ui.stage.clientWidth - 16) / w, (ui.stage.clientHeight - 16) / h));
    }

    function point(e) {
        var r = ui.img.getBoundingClientRect();
        return {
            x: Math.min(Math.max(e.clientX - r.left, 0), r.width),
            y: Math.min(Math.max(e.clientY - r.top, 0), r.height)
        };
    }

    function down(e) {
        if (e.button !== 0 || !ui.img.naturalWidth) return;
        e.preventDefault();
        ui.canvas.setPointerCapture(e.pointerId);
        if (state.mode === 'move') {
            state.start = { x: e.clientX, y: e.clientY, left: ui.stage.scrollLeft, top: ui.stage.scrollTop };
            return;
        }
        state.start = point(e);
        drawBox(state.start, state.start);
        ui.box.hidden = false;
    }

    function move(e) {
        if (!state.start) return;
        if (state.mode === 'move') {
            ui.stage.scrollLeft = state.start.left - (e.clientX - state.start.x);
            ui.stage.scrollTop = state.start.top - (e.clientY - state.start.y);
            return;
        }
        drawBox(state.start, point(e));
    }

    function up(e) {
        if (!state.start) return;
        var start = state.start;
        state.start = null;
        if (state.mode === 'move') return;
        var end = point(e);
        var rect = { x: Math.min(start.x, end.x), y: Math.min(start.y, end.y), w: Math.abs(end.x - start.x), h: Math.abs(end.y - start.y) };
        if (rect.w < 6 || rect.h < 6) {
            ui.box.hidden = true;
            return;
        }
        read(rect);
    }

    function cancel() {
        state.start = null;
        ui.box.hidden = true;
    }

    function drawBox(a, b) {
        ui.box.style.left = (ui.img.offsetLeft + Math.min(a.x, b.x)) + 'px';
        ui.box.style.top = (ui.img.offsetTop + Math.min(a.y, b.y)) + 'px';
        ui.box.style.width = Math.abs(b.x - a.x) + 'px';
        ui.box.style.height = Math.abs(b.y - a.y) + 'px';
    }

    /** Crops the selection at the photo's own resolution (small parts enlarged for the OCR) and reads it. */
    function read(rect) {
        var ratio = ui.img.naturalWidth / ui.img.getBoundingClientRect().width;
        var sx = rect.x * ratio, sy = rect.y * ratio, sw = rect.w * ratio, sh = rect.h * ratio;
        var up = Math.min(3, Math.max(1, 600 / sw));
        var down = Math.min(1, 2400 / (sw * up), 2400 / (sh * up));
        var factor = up * down;
        var canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(sw * factor));
        canvas.height = Math.max(1, Math.round(sh * factor));
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(ui.img, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
        var options = state.options;
        showStatus('Lezen…', false);
        canvas.toBlob(function (blob) {
            if (!blob || !options) return showStatus('Dit stuk kon niet worden gelezen.', true);
            options.read(blob, ui.engine.value).then(function (text) {
                if (state.options !== options) return;
                ui.text.value = text;
                showStatus('Gelezen. Pas de tekst zo nodig aan en neem hem over.', false);
                updateActions();
            }).catch(function (error) {
                if (state.options !== options) return;
                showStatus((error && error.message) || 'Dit stuk kon niet worden gelezen. Typ het over.', true);
            });
        }, 'image/png');
    }

    function showStatus(message, isError) {
        ui.hint.textContent = message;
        ui.hint.classList.toggle('avbk-receipt-viewer-hint-error', !!isError);
    }

    function updateActions() {
        var value = ui.text.value;
        ui.toDate.disabled = !date(value);
        ui.toAmount.disabled = amount(value) === null;
        ui.toStore.disabled = line(value) === '';
        ui.copy.disabled = value.trim() === '';
    }

    function updateEmpty(input) {
        input.classList.toggle('avbk-receipt-viewer-field-empty', !state.reading && input.value.trim() === '');
    }

    function setFieldDisplay(field, value) {
        var input = ui.fields[field];
        if (!input) return;
        if (field === 'date') {
            input.value = formatDate(value);
        } else if (field === 'amount') {
            input.value = formatAmount(value);
        } else {
            input.value = value || '';
        }
        input.classList.remove('avbk-receipt-viewer-field-invalid');
        updateEmpty(input);
    }

    function fillList(list, values) {
        list.textContent = '';
        (values || []).forEach(function (value) {
            if (!value) return;
            var option = el('option');
            option.value = value;
            list.appendChild(option);
        });
    }

    function applyNav(nav) {
        var show = Boolean(nav && (nav.total > 1 || nav.hasPrev || nav.hasNext));
        ui.nav.hidden = !show;
        if (!show) return;
        ui.counter.textContent = nav.index + ' van ' + nav.total;
        ui.prev.disabled = !nav.hasPrev;
        ui.next.disabled = !nav.hasNext;
    }

    function applyState(info, keepFocused) {
        state.reading = Boolean(info.reading);
        ui.reading.hidden = !state.reading;
        var values = info.values || {};
        ['date', 'store', 'amount'].forEach(function (field) {
            if (keepFocused && document.activeElement === ui.fields[field]) {
                updateEmpty(ui.fields[field]);
                return;
            }
            if (!keepFocused || field in values) {
                setFieldDisplay(field, values[field]);
            }
        });
        var candidates = info.candidates || {};
        fillList(ui.lists.date, (candidates.dates || []).map(formatDate));
        fillList(ui.lists.store, candidates.stores);
        fillList(ui.lists.amount, (candidates.amounts || []).map(formatAmount));
        if (!keepFocused || 'nav' in info) {
            if (state.options && 'nav' in info) state.options.nav = info.nav;
            applyNav(info.nav);
        }
    }

    function take(field, value) {
        if (value === null || value === '' || !state.options) return;
        state.options.apply(field, value);
        setFieldDisplay(field, value);
        var label = { date: 'datum', store: 'winkel', amount: 'bedrag' }[field];
        showStatus('Overgenomen als ' + label + '. Sleep over een volgend stuk, of sluit.', false);
    }

    function copy() {
        var value = ui.text.value.trim();
        if (!value) return;
        var done = function () { showStatus('Gekopieerd.', false); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(done, function () { ui.text.select(); });
        } else {
            ui.text.select();
        }
    }

    function update(info) {
        if (!dialog || !dialog.open || !info) return;
        applyState(info, true);
    }

    function open(src, options) {
        if (!dialog) build();
        state.options = options;
        state.scale = 1;
        var engines = options.engines || {};
        ui.engine.textContent = '';
        Object.keys(engines).forEach(function (code) {
            var option = el('option', '', engines[code]);
            option.value = code;
            ui.engine.appendChild(option);
        });
        ui.engineWrap.hidden = ui.engine.options.length < 2;
        ui.box.hidden = true;
        ui.text.value = '';
        applyState(options, false);
        showStatus('Sleep over de datum, de winkel of het bedrag om het over te nemen.', false);
        updateActions();
        setMode('select');
        ui.stage.scrollLeft = 0;
        ui.stage.scrollTop = 0;
        ui.img.onload = fit;
        ui.img.src = src;
        if (!dialog.open) {
            dialog.showModal();
        }
        if (ui.img.complete && ui.img.naturalWidth) fit();
    }

    window.AVBKReceiptViewer = Object.freeze({ open: open, update: update });
})();
