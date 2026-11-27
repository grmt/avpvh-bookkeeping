/** "11,8", "11.80" and "1.234,56" all read; null when it isn't a number. */
function avbkParseAmount(text) {
    let value = String(text || '').replaceAll(/[\s€]/g, '');
    if (/^\d{1,3}(\.\d{3})+(,\d+)?$/.test(value)) {
        value = value.replaceAll('.', '');
    }
    const n = Number.parseFloat(value.replace(',', '.'));
    return Number.isNaN(n) ? null : Math.round(n * 100) / 100;
}

/** 11.8 -> "11,80". */
function avbkFormatAmount(n) {
    return n === null || n === undefined ? '' : n.toFixed(2).replace('.', ',');
}

/** "2026-08-01" -> "01-08-2026". */
function avbkFormatDate(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
    return m ? m[3] + '-' + m[2] + '-' + m[1] : '';
}

/** dd-mm-jjjj (also with / or ., or a two-digit year) -> yyyy-mm-dd, or null. */
function avbkParseDate(text) {
    const value = String(text || '').trim();
    const looksLikeDate = /^\d{1,2}[./-]\d{1,2}[./-](\d{2}|\d{4})$/.test(value) || /^\d{4}-\d{2}-\d{2}$/.test(value);
    return looksLikeDate ? window.AVBKReceiptText.date(value) : null;
}

/** An element with a class and, optionally, text. */
function avbkElement(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
}

function avbkButton(className, text, label) {
    const button = avbkElement('button', className, text);
    button.type = 'button';
    if (label) {
        button.setAttribute('aria-label', label);
        button.title = label;
    }
    return button;
}

/** What the progress label of a row says, and its modifier class. */
function avbkProgress(entry) {
    if (entry.status === 'reading') return { kind: 'reading', text: 'lezen…' };
    if (entry.read === 'ok') return { kind: 'ok', text: '✓ gelezen' };
    return { kind: 'none', text: 'niets gelezen – vul zelf in' };
}

function avbkNewEntry(file) {
    return {
        file: file,
        status: file ? 'reading' : 'ok',
        amount: null,
        amountEdited: false,
        store: file ? null : '',
        storeEdited: false,
        description: '',
        descriptionEdited: false,
        date: '',
        dateEdited: false
    };
}

function avbkPhotoUrl(entry) {
    if (!entry.url) {
        entry.url = URL.createObjectURL(entry.file);
    }
    return entry.url;
}

/** "Klaar: 3 bonnetjes gelezen, bij 1 niets gevonden. " (empty without scanned receipts). */
function avbkDoneText(scanned) {
    if (!scanned.length) return '';
    const unread = scanned.filter(function (e) { return e.read !== 'ok'; }).length;
    const count = scanned.length === 1 ? '1 bonnetje' : scanned.length + ' bonnetjes';
    const missing = unread ? ', bij ' + unread + ' niets gevonden' : '';
    return 'Klaar: ' + count + ' gelezen' + missing + '. ';
}

/** Takes over what the OCR found; a value the member typed is never overwritten. */
function avbkApplyFound(entry, found) {
    const hasAmount = found.amount !== null && found.amount !== undefined;
    entry.read = hasAmount || found.store || found.date ? 'ok' : 'none';
    entry.candidates = found.candidates || null;
    if (!entry.amountEdited && hasAmount) {
        entry.amount = found.amount;
    }
    if (!entry.storeEdited && found.store) {
        entry.store = found.store;
    }
    if (!entry.dateEdited && found.date) {
        entry.date = found.date;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const fileInput = document.getElementById('avbk-receipt-input');
    const configEl = document.getElementById('avbk-reimbursement-config');
    const statusEl = document.getElementById('avbk-ocr-status');
    const amountInput = document.getElementById('avbk-amount');
    const dropzone = document.getElementById('avbk-dropzone');
    const listEl = document.getElementById('avbk-dropzone-list');
    const tableEl = document.getElementById('avbk-dropzone-table');
    const addManualRowBtn = document.getElementById('avbk-add-manual-row');
    const form = document.getElementById('avbk-reimbursement-form');
    const submitButton = form?.querySelector('button[type="submit"]');
    if (!fileInput || !configEl || !statusEl || !amountInput) return;

    const cfg = JSON.parse(configEl.textContent);
    const engines = cfg.engines || {};
    const engineCodes = Object.keys(engines);

    document.querySelectorAll('.avbk-iban-select').forEach(function (select) {
        const target = document.getElementById(select.dataset.target);
        if (!target) return;
        select.addEventListener('change', function () {
            const chosen = select.value !== '';
            target.value = chosen ? select.value : '';
            target.hidden = chosen;
            target.required = !chosen;
            if (!chosen) target.focus();
        });
    });

    document.querySelectorAll('.avbk-reimbursement-edit-toggle').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            const row = document.getElementById(link.dataset.target);
            if (row) {
                row.hidden = !row.hidden;
            }
        });
    });

    // Each entry is one declaration line: a scanned receipt (file set,
    // OCR pre-fills amount/store/date) or a manually-added line with no
    // photo at all (file null, member types everything). status is only
    // meaningful for file-based entries: 'reading'|'ok'|'duplicate'.
    const entries = [];
    let lastSuggestedSum = null;
    let listCount = 0;



    /** Reads one selected part of a receipt photo; resolves with its text. */
    function readRegion(blob, engine) {
        const body = new FormData();
        if (engine) {
            body.append('engine', engine);
        }
        body.append('action', 'avbk_ocr_region');
        body.append('nonce', cfg.nonce);
        body.append('region', blob, 'region.png');
        return fetch(cfg.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data?.success && data.data?.text) {
                    return data.data.text;
                }
                throw new Error(typeof data?.data === 'string' ? data.data : 'Dit stuk kon niet worden gelezen. Typ het over.');
            });
    }

    function openViewer(entry) {
        window.AVBKReceiptViewer.open(avbkPhotoUrl(entry), {
            read: readRegion,
            engines: engines,
            apply: function (field, value) {
                entry[field] = value;
                entry[field + 'Edited'] = true;
                if (field === 'amount') {
                    updateAmountSuggestion();
                }
                renderList();
            }
        });
    }

    function syncFileInput() {
        const dt = new DataTransfer();
        entries.forEach(function (entry) {
            if (entry.file) {
                dt.items.add(entry.file);
            }
        });
        fileInput.files = dt.files;
    }

    function updateSubmitState() {
        if (!submitButton) return;
        const hasDuplicate = entries.some(function (e) { return e.status === 'duplicate'; });
        submitButton.disabled = entries.length === 0 || hasDuplicate;
    }

    function updateAmountSuggestion() {
        let sum = 0;
        let any = false;
        entries.forEach(function (e) {
            if (e.amount) {
                sum = Math.round((sum + e.amount) * 100) / 100;
                any = true;
            }
        });
        if (!any) return;
        if (!amountInput.value || amountInput.value === lastSuggestedSum) {
            amountInput.value = avbkFormatAmount(sum);
            lastSuggestedSum = amountInput.value;
        }
    }


    function updateStatusText() {
        const scanned = entries.filter(function (e) { return e.file; });
        const reading = scanned.filter(function (e) { return e.status === 'reading'; }).length;
        statusEl.setAttribute('aria-live', 'polite');
        statusEl.classList.toggle('avbk-reimbursement-ocr-status-busy', reading > 0);
        const duplicate = entries.some(function (e) { return e.status === 'duplicate'; });
        statusEl.classList.toggle('avbk-reimbursement-ocr-status-error', !reading && duplicate);
        if (reading) {
            statusEl.textContent = 'Bonnetjes worden gelezen: ' + (scanned.length - reading) + ' van ' + scanned.length + ' klaar…';
        } else if (duplicate) {
            statusEl.textContent = 'Eén of meer bonnetjes lijken al eerder gedeclareerd te zijn — verwijder ze om verder te gaan.';
        } else if (entries.length) {
            statusEl.textContent = avbkDoneText(scanned) + 'Controleer datum, winkel en bedrag; klik op een foto om hem groot te bekijken.';
        }
        statusEl.hidden = entries.length === 0;
    }

    /** A <datalist> of suggestions for one field, or null when there is nothing to suggest. */
    function suggestions(input, values) {
        if (!values?.length) return null;
        const list = document.createElement('datalist');
        listCount++;
        list.id = 'avbk-suggest-' + listCount;
        values.forEach(function (value) {
            const option = document.createElement('option');
            option.value = value;
            list.appendChild(option);
        });
        input.setAttribute('list', list.id);
        return list;
    }

    function photoCell(entry) {
        const cell = document.createElement('td');
        if (!entry.file) {
            cell.appendChild(avbkElement('span', 'avbk-dropzone-item-no-photo', 'geen foto'));
        } else if (entry.file.type.startsWith('image/') && window.AVBKReceiptViewer) {
            const zoom = avbkButton('avbk-dropzone-item-zoom', '', 'Bonnetje ' + entry.file.name + ' groot bekijken');
            zoom.title = 'Bekijk groot; sleep over datum, winkel of bedrag om het over te nemen';
            const img = avbkElement('img', 'avbk-dropzone-item-photo');
            img.src = avbkPhotoUrl(entry);
            img.alt = '';
            zoom.appendChild(img);
            zoom.addEventListener('click', function () { openViewer(entry); });
            cell.appendChild(zoom);
        } else {
            cell.appendChild(avbkElement('span', 'avbk-dropzone-item-no-photo', /pdf$/i.test(entry.file.name) ? 'pdf' : 'bestand'));
        }
        return cell;
    }

    // A text field, so the date reads dd-mm-jjjj whatever the browser's
    // language; the server gets yyyy-mm-dd in the hidden date[]. The
    // calendar button opens the browser's own date picker.
    function dateCell(entry) {
        const cell = document.createElement('td');
        const wrap = avbkElement('span', 'avbk-dropzone-item-date-wrap');
        const input = avbkElement('input', 'avbk-dropzone-item-date');
        input.type = 'text';
        input.inputMode = 'numeric';
        input.placeholder = 'dd-mm-jjjj';
        input.setAttribute('aria-label', 'Datum (dd-mm-jjjj)');
        input.value = avbkFormatDate(entry.date);
        const hidden = avbkElement('input');
        hidden.type = 'hidden';
        hidden.name = 'date[]';
        hidden.value = entry.date || '';
        const picker = avbkElement('input', 'avbk-dropzone-item-date-picker');
        picker.type = 'date';
        picker.tabIndex = -1;
        picker.setAttribute('aria-hidden', 'true');
        const pick = avbkButton('avbk-dropzone-item-date-pick', '📅', 'Kies een datum');

        function setDate(iso, fromTyping) {
            entry.date = iso || '';
            entry.dateEdited = true;
            hidden.value = entry.date;
            input.classList.toggle('avbk-dropzone-item-invalid', !iso && input.value.trim() !== '');
            if (!fromTyping) {
                input.value = avbkFormatDate(entry.date);
            }
        }
        input.addEventListener('input', function () { setDate(avbkParseDate(input.value), true); });
        input.addEventListener('blur', function () {
            if (entry.date) {
                input.value = avbkFormatDate(entry.date);
            }
        });
        picker.addEventListener('change', function () { setDate(picker.value, false); });
        pick.addEventListener('click', function () {
            picker.value = entry.date || '';
            try {
                picker.showPicker();
            } catch (error) { // showPicker() missing or refused: the picker still opens on a click.
                picker.focus();
                picker.click();
            }
        });

        const list = suggestions(input, (entry.candidates?.dates || []).map(avbkFormatDate));
        if (list) wrap.appendChild(list);
        [input, pick, picker, hidden].forEach(function (node) { wrap.appendChild(node); });
        cell.appendChild(wrap);
        return cell;
    }

    /** A plain text field for store or description, with optional suggestions. */
    function textCell(entry, field, placeholder, values) {
        const cell = document.createElement('td');
        const input = avbkElement('input', 'avbk-dropzone-item-' + field);
        input.type = 'text';
        input.name = field + '[]';
        input.placeholder = placeholder;
        input.value = entry[field] || '';
        input.addEventListener('input', function () {
            entry[field] = input.value;
            entry[field + 'Edited'] = true;
        });
        cell.appendChild(input);
        const list = suggestions(input, values);
        if (list) cell.appendChild(list);
        return cell;
    }

    function amountCell(entry) {
        const cell = document.createElement('td');
        const input = avbkElement('input', 'avbk-dropzone-item-amount');
        input.type = 'text';
        input.name = 'amount[]';
        input.placeholder = '0,00';
        input.inputMode = 'decimal';
        input.value = avbkFormatAmount(entry.amount);
        input.addEventListener('input', function () {
            entry.amount = avbkParseAmount(input.value);
            entry.amountEdited = true;
            updateAmountSuggestion();
        });
        input.addEventListener('blur', function () {
            if (entry.amount !== null) {
                input.value = avbkFormatAmount(entry.amount);
            }
        });
        cell.appendChild(input);
        const list = suggestions(input, (entry.candidates?.amounts || []).map(avbkFormatAmount));
        if (list) cell.appendChild(list);
        return cell;
    }

    /** Progress and "opnieuw lezen" for a scanned receipt. */
    function appendProgress(cell, entry) {
        const progress = avbkProgress(entry);
        cell.appendChild(avbkElement('span', 'avbk-dropzone-item-progress avbk-dropzone-item-progress-' + progress.kind, progress.text));
        if (entry.status === 'reading') return;
        const engine = nextEngine(entry);
        const retry = avbkButton('avbk-dropzone-item-retry', '↻ opnieuw lezen' + (engine ? ' met ' + engines[engine] : ''));
        retry.title = 'Leest dit bonnetje opnieuw; wat je zelf hebt ingevuld blijft staan.';
        retry.addEventListener('click', function () { readEntry(entry, engine); });
        cell.appendChild(retry);
    }

    function actionCell(entry, index) {
        const cell = document.createElement('td');
        const hasReceipt = avbkElement('input');
        hasReceipt.type = 'hidden';
        hasReceipt.name = 'has_receipt[]';
        hasReceipt.value = entry.file ? '1' : '0';
        cell.appendChild(hasReceipt);

        if (entry.status === 'duplicate') {
            cell.appendChild(avbkElement('span', 'avbk-dropzone-item-warning', 'al eerder gedeclareerd'));
        } else if (entry.file) {
            appendProgress(cell, entry);
        }

        const remove = avbkButton('avbk-dropzone-item-remove', '×', 'Verwijderen');
        remove.addEventListener('click', function () {
            if (entry.url) {
                URL.revokeObjectURL(entry.url);
            }
            entries.splice(index, 1);
            syncFileInput();
            updateSubmitState();
            updateStatusText();
            renderList();
        });
        cell.appendChild(remove);
        return cell;
    }

    function renderList() {
        if (!listEl) return;
        listEl.innerHTML = '';
        if (tableEl) {
            tableEl.hidden = entries.length === 0;
        }
        entries.forEach(function (entry, index) {
            const tr = document.createElement('tr');
            tr.classList.toggle('avbk-dropzone-item-duplicate', entry.status === 'duplicate');
            tr.classList.toggle('avbk-dropzone-item-reading', entry.status === 'reading');
            tr.appendChild(photoCell(entry));
            tr.appendChild(dateCell(entry));
            tr.appendChild(textCell(entry, 'store', 'Winkel', entry.candidates?.stores));
            tr.appendChild(textCell(entry, 'description', 'Omschrijving (optioneel)', null));
            tr.appendChild(amountCell(entry));
            tr.appendChild(actionCell(entry, index));
            listEl.appendChild(tr);
        });
    }

    function addManualRow() {
        entries.push(avbkNewEntry(null));
        updateSubmitState();
        updateStatusText();
        renderList();
    }

    /** The engine to try next for a receipt: the other one when there are two, else the same. */
    function nextEngine(entry) {
        if (engineCodes.length < 2) return '';
        const current = entry.engine || engineCodes[0];
        return engineCodes[(engineCodes.indexOf(current) + 1) % engineCodes.length];
    }


    /** Reads a receipt (again), with the given engine or the default. */
    function readEntry(entry, engine) {
        entry.status = 'reading';
        entry.engine = engine || engineCodes[0] || '';
        const body = new FormData();
        body.append('action', 'avbk_ocr_receipt');
        body.append('nonce', cfg.nonce);
        body.append('receipt', entry.file);
        if (engine) {
            body.append('engine', engine);
        }
        updateStatusText();
        renderList();
        fetch(cfg.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data?.success && data.data.duplicate) {
                    entry.status = 'duplicate';
                    return;
                }
                entry.status = 'ok';
                avbkApplyFound(entry, data?.success ? data.data : {});
            })
            .catch(function () {
                entry.status = 'ok';
                entry.read = 'none';
            })
            .finally(function () {
                updateAmountSuggestion();
                updateSubmitState();
                updateStatusText();
                renderList();
            });
    }

    function addFiles(fileListArg) {
        Array.prototype.forEach.call(fileListArg, function (file) {
            const entry = avbkNewEntry(file);
            entries.push(entry);
            readEntry(entry, '');
        });
        syncFileInput();
        updateSubmitState();
        updateStatusText();
        renderList();
    }

    if (dropzone) {
        ['dragenter', 'dragover'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                dropzone.classList.add('avbk-dropzone-dragover');
            });
        });
        ['dragleave', 'dragend', 'drop'].forEach(function (evt) {
            dropzone.addEventListener(evt, function () {
                dropzone.classList.remove('avbk-dropzone-dragover');
            });
        });
        dropzone.addEventListener('drop', function (e) {
            e.preventDefault();
            const files = e.dataTransfer?.files;
            if (files?.length) {
                addFiles(files);
            }
        });
    }

    fileInput.addEventListener('change', function () {
        if (!fileInput.files?.length) return;
        addFiles(fileInput.files);
    });

    if (addManualRowBtn) {
        addManualRowBtn.addEventListener('click', addManualRow);
    }
});
