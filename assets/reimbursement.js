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

/** dd-mm-jjjj (also with / or ., a two-digit year, a space before a 4-digit year, or a month name) -> yyyy-mm-dd, or null. */
function avbkParseDate(text) {
    const value = String(text || '').trim();
    const looksLikeDate = /^\d{1,2}\s*[./-]\s*\d{1,2}\s*[./-]\s*(\d{2}|\d{4})$/.test(value)
        || /^\d{1,2}[\s./-]+\d{1,2}[\s./-]+(?:19|20)\d{2}$/.test(value)
        || /^\d{4}-\d{2}-\d{2}$/.test(value)
        || /^\d{1,2}[\s./-]+[a-zA-Z\u00C0-\u017F]{3,10}\.?[\s,./-]+(\d{2}|\d{4})$/.test(value);
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
        receiptId: null,
        hasReceipt: Boolean(file),
        isImage: Boolean(file?.type.startsWith('image/')),
        isPdf: Boolean(file && /pdf$/i.test(file.name)),
        photoUrl: '',
        status: file ? 'reading' : 'ok',
        amount: null,
        amountEdited: false,
        store: file ? null : '',
        storeEdited: false,
        description: '',
        descriptionEdited: false,
        date: '',
        dateEdited: false,
        dirty: false,
        saveTimer: null
    };
}

function avbkEntryFromDraft(raw) {
    const amount = raw.amount === null || raw.amount === undefined ? null : Number(raw.amount);
    const hasRead = amount !== null || Boolean(raw.store) || Boolean(raw.date);
    return {
        file: null,
        receiptId: raw.id || null,
        hasReceipt: Boolean(raw.hasReceipt),
        isImage: Boolean(raw.isImage),
        isPdf: Boolean(raw.isPdf),
        photoUrl: raw.photoUrl || '',
        status: 'ok',
        read: hasRead ? 'ok' : 'none',
        amount: amount,
        amountEdited: amount !== null,
        store: raw.store || '',
        storeEdited: Boolean(raw.store),
        description: raw.description || '',
        descriptionEdited: Boolean(raw.description),
        date: raw.date || '',
        dateEdited: Boolean(raw.date),
        dirty: false,
        saveTimer: null
    };
}

function avbkPhotoUrl(entry) {
    if (entry.url) return entry.url;
    if (entry.file) {
        entry.url = URL.createObjectURL(entry.file);
        return entry.url;
    }
    return entry.photoUrl || '';
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
    const sortDateBtn = document.getElementById('avbk-sort-date');
    const form = document.getElementById('avbk-reimbursement-form');
    const submitButton = form?.querySelector('button[type="submit"]');
    const draftIdInput = document.getElementById('avbk-draft-id');
    const draftBar = document.getElementById('avbk-draft-bar');
    const draftStatusEl = document.getElementById('avbk-draft-status');
    const draftDiscardBtn = document.getElementById('avbk-draft-discard');
    const draftDataEl = document.getElementById('avbk-reimbursement-draft');
    const activityInput = document.getElementById('avbk-activity');
    const ibanInput = document.getElementById('avbk-iban');
    const noteInput = document.getElementById('avbk-member-note');
    if (!fileInput || !configEl || !statusEl || !amountInput) return;

    const cfg = JSON.parse(configEl.textContent);
    const engines = cfg.engines || {};
    const engineCodes = Object.keys(engines);
    const draftsEnabled = Boolean(draftIdInput);
    let draftId = draftIdInput?.value ? Number.parseInt(draftIdInput.value, 10) : null;
    let ensurePromise = null;
    let savesInFlight = 0;
    let draftSaveTimer = null;

    document.querySelectorAll('.avbk-iban-select').forEach(function (select) {
        const target = document.getElementById(select.dataset.target);
        if (!target) return;
        select.addEventListener('change', function () {
            const chosen = select.value !== '';
            target.value = chosen ? select.value : '';
            target.hidden = chosen;
            target.required = !chosen;
            if (!chosen) target.focus();
            if (target === ibanInput) {
                saveDraftHeader();
            }
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
    let viewingEntry = null;
    let activeEntry = null;
    let dateSortDir = null;



    function setDraftId(id) {
        draftId = id || null;
        if (draftIdInput) {
            draftIdInput.value = draftId ? String(draftId) : '';
        }
        if (draftBar) {
            draftBar.hidden = !draftId;
        }
    }

    function setDraftStatus(text, isError) {
        if (!draftStatusEl) return;
        draftStatusEl.textContent = text;
        draftStatusEl.classList.toggle('avbk-draft-status-error', Boolean(isError));
    }

    function postAjax(action, fields) {
        const body = new FormData();
        body.append('action', action);
        body.append('nonce', cfg.nonce);
        Object.keys(fields || {}).forEach(function (key) {
            const value = fields[key];
            if (value !== undefined && value !== null) {
                body.append(key, value);
            }
        });
        return fetch(cfg.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data?.success) return data.data;
                throw new Error(typeof data?.data === 'string' ? data.data : 'Opslaan mislukt');
            });
    }

    function beginSave() {
        savesInFlight++;
        setDraftStatus('Concept wordt opgeslagen…', false);
    }

    function finishSave(ok) {
        savesInFlight = Math.max(0, savesInFlight - 1);
        if (!ok) {
            setDraftStatus('Kon concept niet opslaan — laat dit tabblad open', true);
        } else if (savesInFlight === 0 && draftId) {
            setDraftStatus('✓ Concept automatisch opgeslagen — je kunt later verdergaan', false);
        }
    }

    function ensureDraft() {
        if (!draftsEnabled) return Promise.resolve(null);
        if (draftId) return Promise.resolve(draftId);
        if (ensurePromise) return ensurePromise;
        ensurePromise = postAjax('avbk_draft_ensure', { iban: ibanInput?.value || '' })
            .then(function (data) {
                setDraftId(data.draft_id);
                return draftId;
            })
            .finally(function () {
                ensurePromise = null;
            });
        return ensurePromise;
    }

    function saveDraftHeader() {
        if (!draftsEnabled || !draftId) return;
        clearTimeout(draftSaveTimer);
        draftSaveTimer = null;
        beginSave();
        postAjax('avbk_draft_save', {
            draft_id: String(draftId),
            activity_id: activityInput?.value || '',
            amount: amountInput.value || '',
            iban: ibanInput?.value || '',
            member_note: noteInput?.value || ''
        }).then(function () {
            finishSave(true);
        }, function () {
            finishSave(false);
        });
    }

    function scheduleDraftSave() {
        if (!draftsEnabled || !draftId) return;
        clearTimeout(draftSaveTimer);
        draftSaveTimer = setTimeout(saveDraftHeader, 400);
    }

    function saveReceiptNow(entry) {
        if (!draftsEnabled || !draftId || !entry.receiptId) {
            entry.dirty = true;
            return;
        }
        clearTimeout(entry.saveTimer);
        entry.saveTimer = null;
        entry.dirty = false;
        beginSave();
        postAjax('avbk_draft_save_receipt', {
            draft_id: String(draftId),
            receipt_id: String(entry.receiptId),
            date: entry.date || '',
            store: entry.store || '',
            description: entry.description || '',
            amount: entry.amount !== null && entry.amount !== undefined ? String(entry.amount) : ''
        }).then(function () {
            finishSave(true);
        }, function () {
            finishSave(false);
        });
    }

    function scheduleReceiptSave(entry, immediate) {
        if (!draftsEnabled) return;
        entry.dirty = true;
        clearTimeout(entry.saveTimer);
        if (immediate) {
            saveReceiptNow(entry);
        } else {
            entry.saveTimer = setTimeout(function () { saveReceiptNow(entry); }, 400);
        }
    }

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

    function isViewable(entry) {
        return Boolean(entry.hasReceipt && entry.isImage);
    }

    function adjacentViewable(entry, step) {
        const start = entries.indexOf(entry);
        if (start < 0) return null;
        for (let i = start + step; i >= 0 && i < entries.length; i += step) {
            if (isViewable(entries[i])) return entries[i];
        }
        return null;
    }

    function viewerNav(entry) {
        const index = entries.indexOf(entry);
        if (index < 0) return null;
        const prevEntry = adjacentViewable(entry, -1);
        const nextEntry = adjacentViewable(entry, 1);
        return {
            index: index + 1,
            total: entries.length,
            hasPrev: Boolean(prevEntry),
            hasNext: Boolean(nextEntry),
            prev: function () { if (prevEntry) openViewer(prevEntry); },
            next: function () { if (nextEntry) openViewer(nextEntry); }
        };
    }

    function viewerState(entry) {
        return {
            values: {
                date: entry.date || '',
                store: entry.store || '',
                amount: entry.amount
            },
            candidates: entry.candidates || null,
            reading: entry.status === 'reading',
            nav: viewerNav(entry)
        };
    }

    function focusRow(entry) {
        const index = entries.indexOf(entry);
        if (index < 0 || !listEl) return;
        const row = listEl.children[index];
        if (!row) return;
        const target = row.querySelector('.avbk-dropzone-item-zoom') || row.querySelector('input');
        if (target) {
            target.focus({ preventScroll: true });
        }
        if (row.scrollIntoView) {
            row.scrollIntoView({ block: 'center' });
        }
    }

    function openViewer(entry) {
        viewingEntry = entry;
        activeEntry = entry;
        renderList();
        const current = viewerState(entry);
        window.AVBKReceiptViewer.open(avbkPhotoUrl(entry), {
            read: readRegion,
            engines: engines,
            values: current.values,
            candidates: current.candidates,
            reading: current.reading,
            nav: current.nav,
            apply: function (field, value) {
                entry[field] = value;
                entry[field + 'Edited'] = true;
                if (field === 'amount') {
                    updateAmountSuggestion(true);
                }
                scheduleReceiptSave(entry, true);
                renderList();
            },
            onClose: function () {
                if (viewingEntry === entry) {
                    viewingEntry = null;
                }
                focusRow(entry);
            }
        });
    }

    function syncFileInput() {
        const dt = new DataTransfer();
        entries.forEach(function (entry) {
            if (entry.file && !entry.receiptId) {
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

    function sumEntryAmounts() {
        let sum = 0;
        let any = false;
        entries.forEach(function (e) {
            if (e.amount) {
                sum = Math.round((sum + e.amount) * 100) / 100;
                any = true;
            }
        });
        return any ? sum : null;
    }

    function updateAmountSuggestion(immediate) {
        const sum = sumEntryAmounts();
        if (sum === null) return;
        if (!amountInput.value || amountInput.value === lastSuggestedSum) {
            amountInput.value = avbkFormatAmount(sum);
            lastSuggestedSum = amountInput.value;
            if (immediate) {
                saveDraftHeader();
            } else {
                scheduleDraftSave();
            }
        }
    }


    function updateStatusText() {
        const scanned = entries.filter(function (e) { return e.hasReceipt; });
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

    function attachDraftPhoto(entry, file) {
        if (!draftsEnabled || !draftId || !entry.receiptId || !file) return;
        entry.status = 'reading';
        entry.hasReceipt = true;
        entry.file = file;
        entry.isImage = Boolean(file.type.startsWith('image/'));
        entry.isPdf = /pdf$/i.test(file.name);
        beginSave();
        updateStatusText();
        renderList();
        postAjax('avbk_member_attach_receipt_photo', {
            reimbursement_id: String(draftId),
            receipt_id: String(entry.receiptId),
            receipt: file
        }).then(function (data) {
            if (data?.duplicate) {
                entry.hasReceipt = false;
                entry.file = null;
                entry.isImage = false;
                entry.isPdf = false;
                entry.status = 'ok';
                finishSave(false);
                setDraftStatus('Dit bonnetje lijkt al eerder gedeclareerd te zijn.', true);
                return;
            }
            entry.photoUrl = data.photo_url || '';
            entry.isImage = Boolean(data.is_image);
            entry.isPdf = Boolean(data.is_pdf);
            entry.status = 'ok';
            avbkApplyFound(entry, data || {});
            finishSave(true);
        }, function () {
            entry.hasReceipt = false;
            entry.file = null;
            entry.isImage = false;
            entry.isPdf = false;
            entry.status = 'ok';
            finishSave(false);
        }).finally(function () {
            updateAmountSuggestion(true);
            updateSubmitState();
            updateStatusText();
            renderList();
        });
    }

    function photoCell(entry) {
        const cell = document.createElement('td');
        if (!entry.hasReceipt) {
            if (draftsEnabled && draftId && entry.receiptId) {
                const label = avbkElement('label', 'avbk-attach-photo');
                label.title = 'Foto toevoegen aan deze regel';
                label.appendChild(avbkElement('span', '', '+ Foto'));
                const picker = avbkElement('input', 'avbk-draft-attach-input');
                picker.type = 'file';
                picker.accept = 'image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf';
                picker.hidden = true;
                picker.addEventListener('change', function () {
                    const chosen = picker.files?.[0];
                    if (chosen) attachDraftPhoto(entry, chosen);
                });
                label.appendChild(picker);
                cell.appendChild(label);
            } else {
                cell.appendChild(avbkElement('span', 'avbk-dropzone-item-no-photo', 'geen foto'));
            }
        } else if (entry.isImage && window.AVBKReceiptViewer) {
            const labelName = entry.file?.name || entry.store || 'bonnetje';
            const zoom = avbkButton('avbk-dropzone-item-zoom', '', 'Bonnetje ' + labelName + ' groot bekijken');
            zoom.title = 'Bekijk groot om te controleren of een stukje op de foto opnieuw uit te lezen';
            const img = avbkElement('img', 'avbk-dropzone-item-photo');
            img.src = avbkPhotoUrl(entry);
            img.alt = '';
            zoom.appendChild(img);
            zoom.addEventListener('click', function () { openViewer(entry); });
            cell.appendChild(zoom);
        } else {
            cell.appendChild(avbkElement('span', 'avbk-dropzone-item-no-photo', entry.isPdf ? 'pdf' : 'bestand'));
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
            scheduleReceiptSave(entry, !fromTyping);
        }
        input.addEventListener('input', function () { setDate(avbkParseDate(input.value), true); });
        input.addEventListener('blur', function () {
            if (entry.date) {
                input.value = avbkFormatDate(entry.date);
            }
            if (entry.dirty) {
                saveReceiptNow(entry);
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
            scheduleReceiptSave(entry, false);
        });
        input.addEventListener('blur', function () {
            if (entry.dirty) {
                saveReceiptNow(entry);
            }
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
        input.name = 'row_amount[]';
        input.placeholder = '0,00';
        input.inputMode = 'decimal';
        input.value = avbkFormatAmount(entry.amount);
        input.addEventListener('input', function () {
            entry.amount = avbkParseAmount(input.value);
            entry.amountEdited = true;
            updateAmountSuggestion();
            scheduleReceiptSave(entry, false);
        });
        input.addEventListener('blur', function () {
            if (entry.amount !== null) {
                input.value = avbkFormatAmount(entry.amount);
            }
            if (entry.dirty) {
                saveReceiptNow(entry);
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
        hasReceipt.value = entry.hasReceipt ? '1' : '0';
        cell.appendChild(hasReceipt);

        if (draftsEnabled) {
            const receiptIdField = avbkElement('input');
            receiptIdField.type = 'hidden';
            receiptIdField.name = 'receipt_id[]';
            receiptIdField.value = entry.receiptId ? String(entry.receiptId) : '';
            cell.appendChild(receiptIdField);
        }

        if (entry.status === 'duplicate') {
            cell.appendChild(avbkElement('span', 'avbk-dropzone-item-warning', 'al eerder gedeclareerd'));
        } else if (entry.hasReceipt) {
            appendProgress(cell, entry);
        }

        const remove = avbkButton('avbk-dropzone-item-remove', '×', 'Verwijderen');
        remove.addEventListener('click', function () {
            clearTimeout(entry.saveTimer);
            if (entry.url) {
                URL.revokeObjectURL(entry.url);
            }
            if (activeEntry === entry) {
                activeEntry = null;
            }
            if (draftsEnabled && draftId && entry.receiptId) {
                beginSave();
                postAjax('avbk_draft_remove_receipt', { draft_id: String(draftId), receipt_id: String(entry.receiptId) })
                    .then(function () { finishSave(true); }, function () { finishSave(false); });
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
        if (tableEl) {
            tableEl.hidden = entries.length === 0;
        }
        const fragment = document.createDocumentFragment();
        entries.forEach(function (entry, index) {
            const tr = document.createElement('tr');
            tr.classList.toggle('avbk-dropzone-item-duplicate', entry.status === 'duplicate');
            tr.classList.toggle('avbk-dropzone-item-reading', entry.status === 'reading');
            tr.classList.toggle('avbk-dropzone-item-active', entry === activeEntry);
            tr.appendChild(photoCell(entry));
            tr.appendChild(dateCell(entry));
            tr.appendChild(textCell(entry, 'store', 'Winkel', entry.candidates?.stores));
            tr.appendChild(textCell(entry, 'description', 'Omschrijving (optioneel)', null));
            tr.appendChild(amountCell(entry));
            tr.appendChild(actionCell(entry, index));
            fragment.appendChild(tr);
        });
        listEl.replaceChildren(fragment);
    }

    function addManualRow() {
        const entry = avbkNewEntry(null);
        entries.push(entry);
        updateSubmitState();
        updateStatusText();
        renderList();
        if (draftsEnabled) {
            beginSave();
            ensureDraft()
                .then(function (id) {
                    return postAjax('avbk_draft_add_manual', { draft_id: String(id), iban: ibanInput?.value || '' });
                })
                .then(function (data) {
                    setDraftId(data.draft_id);
                    entry.receiptId = data.receipt_id;
                    renderList();
                    if (entry.dirty) {
                        saveReceiptNow(entry);
                    }
                    finishSave(true);
                }, function () {
                    finishSave(false);
                });
        }
    }

    /** The engine to try next for a receipt: the other one when there are two, else the same. */
    function nextEngine(entry) {
        if (engineCodes.length < 2) return '';
        const current = entry.engine || engineCodes[0];
        return engineCodes[(engineCodes.indexOf(current) + 1) % engineCodes.length];
    }

    function sendReceiptRequest(entry, engine) {
        if (draftsEnabled && entry.receiptId && draftId) {
            return postAjax('avbk_draft_retry_receipt', {
                draft_id: String(draftId),
                receipt_id: String(entry.receiptId),
                engine: engine || undefined
            });
        }
        if (draftsEnabled && entry.file) {
            return ensureDraft().then(function (id) {
                return postAjax('avbk_draft_add_receipt', {
                    draft_id: String(id),
                    iban: ibanInput?.value || '',
                    receipt: entry.file,
                    engine: engine || undefined
                });
            });
        }
        const body = new FormData();
        body.append('action', 'avbk_ocr_receipt');
        body.append('nonce', cfg.nonce);
        body.append('receipt', entry.file);
        if (engine) {
            body.append('engine', engine);
        }
        return fetch(cfg.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) { return data?.success ? data.data : {}; });
    }

    /** Reads a receipt (again), with the given engine or the default. */
    function readEntry(entry, engine) {
        const isRetry = Boolean(draftsEnabled && entry.receiptId && draftId);
        entry.status = 'reading';
        entry.engine = engine || engineCodes[0] || '';
        if (draftsEnabled) {
            beginSave();
        }
        updateStatusText();
        renderList();
        sendReceiptRequest(entry, engine)
            .then(function (data) {
                if (data?.duplicate) {
                    entry.status = 'duplicate';
                    if (draftsEnabled) finishSave(true);
                    return;
                }
                if (draftsEnabled && data?.receipt_id) {
                    setDraftId(data.draft_id);
                    entry.receiptId = data.receipt_id;
                    entry.photoUrl = data.photo_url || entry.photoUrl;
                    entry.isImage = Boolean(data.is_image);
                    entry.isPdf = Boolean(data.is_pdf);
                    syncFileInput();
                }
                entry.status = 'ok';
                avbkApplyFound(entry, data || {});
                if (draftsEnabled) {
                    finishSave(true);
                    if (entry.dirty || isRetry) {
                        saveReceiptNow(entry);
                    }
                }
            })
            .catch(function () {
                entry.status = 'ok';
                entry.read = 'none';
                if (draftsEnabled) finishSave(false);
            })
            .finally(function () {
                updateAmountSuggestion(true);
                updateSubmitState();
                updateStatusText();
                renderList();
                if (viewingEntry === entry && window.AVBKReceiptViewer?.update) {
                    window.AVBKReceiptViewer.update(viewerState(entry));
                }
            });
    }

    function discardDraft() {
        if (!draftId || !window.confirm('Concept en bijbehorende bonnetjes verwijderen?')) return;
        const idToDiscard = draftId;
        beginSave();
        postAjax('avbk_draft_discard', { draft_id: String(idToDiscard) })
            .then(function () {
                entries.forEach(function (entry) {
                    clearTimeout(entry.saveTimer);
                    if (entry.url) URL.revokeObjectURL(entry.url);
                });
                entries.length = 0;
                activeEntry = null;
                viewingEntry = null;
                lastSuggestedSum = null;
                setDraftId(null);
                if (activityInput) activityInput.value = '';
                amountInput.value = '';
                if (noteInput) noteInput.value = '';
                syncFileInput();
                updateSubmitState();
                updateStatusText();
                renderList();
                finishSave(true);
            }, function () {
                finishSave(false);
            });
    }

    function hydrateDraft() {
        if (!draftDataEl) return;
        const data = JSON.parse(draftDataEl.textContent);
        if (!data?.id) return;
        setDraftId(data.id);
        (data.receipts || []).forEach(function (raw) {
            entries.push(avbkEntryFromDraft(raw));
        });
        const sum = sumEntryAmounts();
        if (sum !== null) {
            const formatted = avbkFormatAmount(sum);
            if (!amountInput.value) {
                amountInput.value = formatted;
            }
            if (amountInput.value === formatted) {
                lastSuggestedSum = formatted;
            }
        }
        updateSubmitState();
        updateStatusText();
        renderList();
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

    function sortByDate() {
        dateSortDir = dateSortDir === 'asc' ? 'desc' : 'asc';
        const dir = dateSortDir === 'asc' ? 1 : -1;
        entries.sort(function (a, b) {
            const da = a.date || '';
            const db = b.date || '';
            if (da === db) return 0;
            if (!da) return 1;
            if (!db) return -1;
            return da < db ? -dir : dir;
        });
        if (sortDateBtn) {
            const indicator = sortDateBtn.querySelector('.avbk-sort-indicator');
            if (indicator) {
                indicator.textContent = dateSortDir === 'asc' ? '↑' : '↓';
            }
            const th = sortDateBtn.closest('th');
            if (th) {
                th.setAttribute('aria-sort', dateSortDir === 'asc' ? 'ascending' : 'descending');
            }
        }
        syncFileInput();
        renderList();
    }

    if (addManualRowBtn) {
        addManualRowBtn.addEventListener('click', addManualRow);
    }

    if (sortDateBtn) {
        sortDateBtn.addEventListener('click', sortByDate);
    }

    function pendingRowField(row, field) {
        return row.querySelector('.avbk-pending-input[data-field="' + field + '"]');
    }

    function pendingSortValue(row, field) {
        if (field === 'date') return row.dataset.date || '';
        const val = pendingRowField(row, field)?.value || '';
        if (field === 'amount') return avbkParseAmount(val);
        return val.trim().toLowerCase();
    }

    function comparePendingRows(a, b, field, dir) {
        const va = pendingSortValue(a, field);
        const vb = pendingSortValue(b, field);
        const aEmpty = va === '' || va === null;
        const bEmpty = vb === '' || vb === null;
        if (aEmpty && bEmpty) return 0;
        if (aEmpty) return 1;
        if (bEmpty) return -1;
        if (typeof va === 'number' && typeof vb === 'number') {
            return (va - vb) * dir;
        }
        return String(va).localeCompare(String(vb), 'nl') * dir;
    }

    function initPendingReceipts(container) {
        const reimbursementId = container.dataset.reimbursementId;
        const filterInput = container.querySelector('.avbk-pending-filter');
        const pendingStatus = container.querySelector('.avbk-pending-status');
        const tbody = container.querySelector('tbody');
        const totalEl = container.querySelector('.avbk-pending-total');
        const countEl = container.querySelector('.avbk-pending-count');
        const parentAmount = container.closest('td')?.querySelector('form input[name="amount"]');
        if (!reimbursementId || !tbody) return;

        let sortState = { field: null, dir: 'asc' };
        let pendingSaves = 0;
        let lastAllSum = null;

        function allRows() {
            return Array.prototype.slice.call(tbody.querySelectorAll('tr.avbk-pending-row'));
        }

        function setPendingStatus(text, isError) {
            if (!pendingStatus) return;
            pendingStatus.textContent = text;
            pendingStatus.classList.toggle('avbk-draft-status-error', Boolean(isError));
        }

        function updateTotals() {
            const rows = allRows();
            let visibleCount = 0;
            let visibleSum = 0;
            let allSum = 0;
            let anyAmount = false;
            rows.forEach(function (row) {
                const n = avbkParseAmount(pendingRowField(row, 'amount')?.value || '');
                if (n !== null) {
                    allSum = Math.round((allSum + n) * 100) / 100;
                    anyAmount = true;
                    if (!row.hidden) {
                        visibleSum = Math.round((visibleSum + n) * 100) / 100;
                    }
                }
                if (!row.hidden) visibleCount++;
            });
            if (countEl) {
                if (visibleCount < rows.length) {
                    countEl.textContent = 'Totaal (' + visibleCount + ' van ' + rows.length + ' regels)';
                } else {
                    countEl.textContent = 'Totaal (' + rows.length + (rows.length === 1 ? ' regel)' : ' regels)');
                }
            }
            if (totalEl) {
                totalEl.textContent = '€ ' + avbkFormatAmount(visibleSum);
            }
            if (parentAmount && anyAmount) {
                const formattedAll = avbkFormatAmount(allSum);
                if (lastAllSum === null) {
                    if (parentAmount.value === formattedAll) lastAllSum = formattedAll;
                } else if (parentAmount.value === lastAllSum) {
                    parentAmount.value = formattedAll;
                    lastAllSum = formattedAll;
                }
            }
        }

        function applyFilter() {
            const query = (filterInput?.value || '').trim().toLowerCase();
            allRows().forEach(function (row) {
                if (!query) {
                    row.hidden = false;
                    return;
                }
                const haystack = ['date', 'store', 'description', 'amount']
                    .map(function (f) { return pendingRowField(row, f)?.value || ''; })
                    .join(' ')
                    .toLowerCase();
                row.hidden = !haystack.includes(query);
            });
            updateTotals();
        }

        function sortRows(field) {
            const nextDir = sortState.field === field && sortState.dir === 'asc' ? 'desc' : 'asc';
            sortState = { field: field, dir: nextDir };
            const dir = nextDir === 'asc' ? 1 : -1;
            const sorted = allRows().sort(function (a, b) { return comparePendingRows(a, b, field, dir); });
            sorted.forEach(function (row) { tbody.appendChild(row); });
            container.querySelectorAll('.avbk-dropzone-sort').forEach(function (btn) {
                const active = btn.dataset.sort === field;
                const indicator = btn.querySelector('.avbk-sort-indicator');
                if (indicator) {
                    indicator.textContent = active ? (nextDir === 'asc' ? '↑' : '↓') : '↕';
                }
                const th = btn.closest('th');
                if (th) {
                    if (active) {
                        th.setAttribute('aria-sort', nextDir === 'asc' ? 'ascending' : 'descending');
                    } else {
                        th.removeAttribute('aria-sort');
                    }
                }
            });
        }

        function saveRowNow(row) {
            clearTimeout(row._saveTimer);
            row._saveTimer = null;
            row._dirty = false;
            pendingSaves++;
            setPendingStatus('Opslaan…', false);
            const amt = avbkParseAmount(pendingRowField(row, 'amount')?.value || '');
            postAjax('avbk_member_update_receipt', {
                reimbursement_id: reimbursementId,
                receipt_id: row.dataset.receiptId || '',
                date: row.dataset.date || '',
                store: pendingRowField(row, 'store')?.value || '',
                description: pendingRowField(row, 'description')?.value || '',
                amount: amt !== null ? String(amt) : ''
            }).then(function () {
                pendingSaves = Math.max(0, pendingSaves - 1);
                if (pendingSaves === 0) setPendingStatus('✓ Opgeslagen', false);
            }, function () {
                pendingSaves = Math.max(0, pendingSaves - 1);
                setPendingStatus('Opslaan mislukt', true);
            });
        }

        function scheduleRowSave(row, immediate) {
            row._dirty = true;
            clearTimeout(row._saveTimer);
            if (immediate) {
                saveRowNow(row);
            } else {
                row._saveTimer = setTimeout(function () { saveRowNow(row); }, 400);
            }
        }

        function openPendingViewer(row) {
            if (!window.AVBKReceiptViewer || !row.dataset.photoUrl) return;
            const visible = allRows().filter(function (r) { return !r.hidden; });
            const idx = visible.indexOf(row);
            const findAdj = function (step) {
                for (let i = idx + step; i >= 0 && i < visible.length; i += step) {
                    if (visible[i].dataset.photoUrl) return visible[i];
                }
                return null;
            };
            const prevRow = idx >= 0 ? findAdj(-1) : null;
            const nextRow = idx >= 0 ? findAdj(1) : null;
            allRows().forEach(function (r) { r.classList.toggle('avbk-dropzone-item-active', r === row); });
            window.AVBKReceiptViewer.open(row.dataset.photoUrl, {
                read: readRegion,
                engines: engines,
                values: {
                    date: row.dataset.date || '',
                    store: pendingRowField(row, 'store')?.value || '',
                    amount: avbkParseAmount(pendingRowField(row, 'amount')?.value || '')
                },
                nav: {
                    index: idx + 1,
                    total: visible.length,
                    hasPrev: Boolean(prevRow),
                    hasNext: Boolean(nextRow),
                    prev: function () { if (prevRow) openPendingViewer(prevRow); },
                    next: function () { if (nextRow) openPendingViewer(nextRow); }
                },
                apply: function (field, value) {
                    const input = pendingRowField(row, field);
                    if (!input || input.disabled) return;
                    if (field === 'date') {
                        row.dataset.date = value || '';
                        input.value = avbkFormatDate(value);
                        input.classList.remove('avbk-dropzone-item-invalid');
                    } else if (field === 'amount') {
                        input.value = avbkFormatAmount(value);
                        updateTotals();
                    } else {
                        input.value = value || '';
                    }
                    scheduleRowSave(row, true);
                },
                onClose: function () {
                    const zoom = row.querySelector('.avbk-pending-zoom');
                    if (zoom) zoom.focus({ preventScroll: true });
                    if (row.scrollIntoView) row.scrollIntoView({ block: 'center' });
                }
            });
        }

        function attachPendingPhoto(row, file) {
            if (!file) return;
            pendingSaves++;
            setPendingStatus('Foto uploaden…', false);
            postAjax('avbk_member_attach_receipt_photo', {
                reimbursement_id: reimbursementId,
                receipt_id: row.dataset.receiptId || '',
                receipt: file
            }).then(function (data) {
                pendingSaves = Math.max(0, pendingSaves - 1);
                if (data?.duplicate) {
                    setPendingStatus('Dit bonnetje lijkt al eerder gedeclareerd te zijn.', true);
                    return;
                }
                const photoTd = row.cells[0];
                if (photoTd) {
                    photoTd.textContent = '';
                    if (data.is_image) {
                        row.dataset.photoUrl = data.photo_url || '';
                        const zoom = avbkButton('avbk-dropzone-item-zoom avbk-pending-zoom', '', 'Bonnetje groot bekijken');
                        zoom.title = 'Bekijk groot om te controleren of een stukje op de foto opnieuw uit te lezen';
                        const img = avbkElement('img', 'avbk-dropzone-item-photo');
                        img.src = data.photo_url || '';
                        img.alt = '';
                        zoom.appendChild(img);
                        zoom.addEventListener('click', function () { openPendingViewer(row); });
                        photoTd.appendChild(zoom);
                    } else {
                        const link = avbkElement('a', 'avbk-dropzone-item-no-photo', data.is_pdf ? 'pdf' : 'bestand');
                        link.href = data.photo_url || '';
                        link.target = '_blank';
                        photoTd.appendChild(link);
                    }
                }
                const dateIn = pendingRowField(row, 'date');
                if (dateIn && !row.dataset.date && data.date) {
                    row.dataset.date = data.date;
                    dateIn.value = avbkFormatDate(data.date);
                }
                const storeIn = pendingRowField(row, 'store');
                if (storeIn && !storeIn.value.trim() && data.store) {
                    storeIn.value = data.store;
                }
                const amtIn = pendingRowField(row, 'amount');
                if (amtIn && !amtIn.value.trim() && data.amount !== null && data.amount !== undefined) {
                    amtIn.value = avbkFormatAmount(data.amount);
                    updateTotals();
                }
                if (pendingSaves === 0) setPendingStatus('✓ Foto toegevoegd', false);
            }, function () {
                pendingSaves = Math.max(0, pendingSaves - 1);
                setPendingStatus('Foto toevoegen mislukt', true);
            });
        }

        allRows().forEach(function (row) {
            const dateIn = pendingRowField(row, 'date');
            const datePick = row.querySelector('.avbk-pending-date-pick');
            const datePicker = row.querySelector('.avbk-pending-date-picker');
            const storeIn = pendingRowField(row, 'store');
            const descIn = pendingRowField(row, 'description');
            const amtIn = pendingRowField(row, 'amount');
            const zoomBtn = row.querySelector('.avbk-pending-zoom');
            const attachIn = row.querySelector('.avbk-pending-attach-input');

            if (dateIn && !dateIn.disabled) {
                dateIn.addEventListener('input', function () {
                    const parsed = avbkParseDate(dateIn.value);
                    row.dataset.date = parsed || '';
                    dateIn.classList.toggle('avbk-dropzone-item-invalid', !parsed && dateIn.value.trim() !== '');
                    scheduleRowSave(row, false);
                });
                dateIn.addEventListener('blur', function () {
                    if (row.dataset.date) dateIn.value = avbkFormatDate(row.dataset.date);
                    if (row._dirty) saveRowNow(row);
                });
            }
            if (datePick && datePicker && dateIn) {
                datePick.addEventListener('click', function () {
                    datePicker.value = row.dataset.date || '';
                    try {
                        datePicker.showPicker();
                    } catch (error) { // showPicker() missing or refused: click still opens picker.
                        datePicker.focus();
                        datePicker.click();
                    }
                });
                datePicker.addEventListener('change', function () {
                    row.dataset.date = datePicker.value || '';
                    dateIn.value = avbkFormatDate(row.dataset.date);
                    dateIn.classList.remove('avbk-dropzone-item-invalid');
                    scheduleRowSave(row, true);
                });
            }
            [storeIn, descIn].forEach(function (input) {
                if (!input || input.disabled) return;
                input.addEventListener('input', function () { scheduleRowSave(row, false); });
                input.addEventListener('blur', function () { if (row._dirty) saveRowNow(row); });
            });
            if (amtIn && !amtIn.disabled) {
                amtIn.addEventListener('input', function () {
                    updateTotals();
                    scheduleRowSave(row, false);
                });
                amtIn.addEventListener('blur', function () {
                    const n = avbkParseAmount(amtIn.value);
                    if (n !== null) amtIn.value = avbkFormatAmount(n);
                    if (row._dirty) saveRowNow(row);
                });
            }
            if (zoomBtn) {
                zoomBtn.addEventListener('click', function () { openPendingViewer(row); });
            }
            if (attachIn) {
                attachIn.addEventListener('change', function () {
                    const chosen = attachIn.files?.[0];
                    if (chosen) attachPendingPhoto(row, chosen);
                });
            }
        });

        container.querySelectorAll('.avbk-dropzone-sort').forEach(function (btn) {
            btn.addEventListener('click', function () { sortRows(btn.dataset.sort); });
        });
        if (filterInput) {
            filterInput.addEventListener('input', applyFilter);
        }
        updateTotals();
    }

    document.querySelectorAll('.avbk-pending-receipts').forEach(initPendingReceipts);

    if (draftsEnabled) {
        [activityInput, amountInput, ibanInput, noteInput].forEach(function (field) {
            if (!field) return;
            field.addEventListener('input', scheduleDraftSave);
            field.addEventListener('change', saveDraftHeader);
        });
        if (draftDiscardBtn) {
            draftDiscardBtn.addEventListener('click', discardDraft);
        }
        hydrateDraft();
    }
});
