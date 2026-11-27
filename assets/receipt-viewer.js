/**
 * Receipt viewer for the declaration form: a large, zoomable view of a
 * receipt photo. Dragging over a part of it reads that part (OCR on the
 * server) so a date, shop or amount can be taken over into the row.
 *
 * window.AVBKReceiptText: parsing the read text (also used by the tests).
 * window.AVBKReceiptViewer.open(src, options): options.read(blob, engine)
 * returns a promise of the text; options.apply(field, value) takes over a
 * value; options.engines (code => name) offers a choice when there are two.
 */
(function () {
    /** First valid date as yyyy-mm-dd: dd-mm-yyyy, dd.mm.yy, dd/mm/yyyy or yyyy-mm-dd. */
    function date(text) {
        var value = String(text || '');
        var m = value.match(/(?:^|\D)(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/);
        if (m) {
            return iso(+m[1], +m[2], +m[3]);
        }
        var re = /(?:^|\D)(\d{1,2})[./-](\d{1,2})[./-](\d{4}|\d{2})(?!\d)/g;
        while ((m = re.exec(value))) {
            var year = +m[3];
            if (m[3].length === 2) {
                year += year < 70 ? 2000 : 1900;
            }
            var found = iso(year, +m[2], +m[1]);
            if (found) return found;
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

    window.AVBKReceiptText = Object.freeze({ date: date, amount: amount, line: line });

    if (typeof document === 'undefined' || !document.createElement) return;

    var dialog = null;
    var ui = {};
    var state = { scale: 1, mode: 'select', options: null, start: null };

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
        var close = button('×', 'Sluiten', function () { dialog.close(); }, 'avbk-receipt-viewer-close');
        bar.appendChild(close);
        dialog.appendChild(bar);

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
        dialog.appendChild(ui.stage);

        var panel = el('div', 'avbk-receipt-viewer-panel');
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
        panel.appendChild(ui.engineWrap);
        panel.appendChild(ui.hint);
        panel.appendChild(ui.text);
        panel.appendChild(ui.actions);
        dialog.appendChild(panel);

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
        dialog.addEventListener('close', function () {
            state.options = null;
            ui.img.removeAttribute('src');
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

    function take(field, value) {
        if (value === null || value === '' || !state.options) return;
        state.options.apply(field, value);
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

    function open(src, options) {
        if (!dialog) build();
        state.options = options;
        state.scale = 1;
        var engines = options.engines || {};
        ui.engine.innerHTML = '';
        Object.keys(engines).forEach(function (code) {
            var option = el('option', '', engines[code]);
            option.value = code;
            ui.engine.appendChild(option);
        });
        ui.engineWrap.hidden = ui.engine.options.length < 2;
        ui.box.hidden = true;
        ui.text.value = '';
        showStatus('Sleep over de datum, de winkel of het bedrag om het over te nemen.', false);
        updateActions();
        setMode('select');
        ui.img.onload = fit;
        ui.img.src = src;
        dialog.showModal();
        if (ui.img.complete && ui.img.naturalWidth) fit();
    }

    window.AVBKReceiptViewer = Object.freeze({ open: open });
})();
