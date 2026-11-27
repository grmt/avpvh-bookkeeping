document.addEventListener('DOMContentLoaded', function () {
    var fileInput = document.getElementById('avbk-receipt-input');
    var configEl = document.getElementById('avbk-reimbursement-config');
    var statusEl = document.getElementById('avbk-ocr-status');
    var amountInput = document.getElementById('avbk-amount');
    var dropzone = document.getElementById('avbk-dropzone');
    var listEl = document.getElementById('avbk-dropzone-list');
    var tableEl = document.getElementById('avbk-dropzone-table');
    var addManualRowBtn = document.getElementById('avbk-add-manual-row');
    var form = document.getElementById('avbk-reimbursement-form');
    var submitButton = form ? form.querySelector('button[type="submit"]') : null;
    if (!fileInput || !configEl || !statusEl || !amountInput) return;

    var cfg = JSON.parse(configEl.textContent);

    document.querySelectorAll('.avbk-iban-select').forEach(function (select) {
        var target = document.getElementById(select.dataset.target);
        if (!target) return;
        select.addEventListener('change', function () {
            if (select.value !== '') {
                target.value = select.value;
                target.hidden = true;
                target.required = false;
            } else {
                target.value = '';
                target.hidden = false;
                target.required = true;
                target.focus();
            }
        });
    });

    document.querySelectorAll('.avbk-reimbursement-edit-toggle').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var row = document.getElementById(link.dataset.target);
            if (row) {
                row.hidden = !row.hidden;
            }
        });
    });

    // Each entry is one declaration line: a scanned receipt (file set,
    // OCR pre-fills amount/store/date) or a manually-added line with no
    // photo at all (file null, member types everything). status is only
    // meaningful for file-based entries: 'reading'|'ok'|'duplicate'.
    var entries = [];
    var lastSuggestedSum = null;

    /** "11,8", "11.80" and "1.234,56" all read; null when it isn't a number. */
    function parseAmount(text) {
        var value = String(text || '').replace(/[\s€]/g, '');
        if (/^\d{1,3}(\.\d{3})+(,\d+)?$/.test(value)) {
            value = value.replace(/\./g, '');
        }
        var n = parseFloat(value.replace(',', '.'));
        return isNaN(n) ? null : Math.round(n * 100) / 100;
    }

    /** 11.8 -> "11,80". */
    function formatAmount(n) {
        return n === null || n === undefined ? '' : n.toFixed(2).replace('.', ',');
    }

    /** "2026-08-01" -> "01-08-2026". */
    function formatDate(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        return m ? m[3] + '-' + m[2] + '-' + m[1] : '';
    }

    /** dd-mm-jjjj (also with / or ., or a two-digit year) -> yyyy-mm-dd, or null. */
    function parseDate(text) {
        var value = String(text || '').trim();
        return /^\d{1,2}[.\-\/]\d{1,2}[.\-\/](\d{2}|\d{4})$/.test(value) || /^\d{4}-\d{2}-\d{2}$/.test(value)
            ? window.AVBKReceiptText.date(value)
            : null;
    }

    function photoUrl(entry) {
        if (!entry.url) {
            entry.url = URL.createObjectURL(entry.file);
        }
        return entry.url;
    }

    /** Reads one selected part of a receipt photo; resolves with its text. */
    function readRegion(blob, engine) {
        var body = new FormData();
        if (engine) {
            body.append('engine', engine);
        }
        body.append('action', 'avbk_ocr_region');
        body.append('nonce', cfg.nonce);
        body.append('region', blob, 'region.png');
        return fetch(cfg.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success && data.data && data.data.text) {
                    return data.data.text;
                }
                throw new Error(data && typeof data.data === 'string' ? data.data : 'Dit stuk kon niet worden gelezen. Typ het over.');
            });
    }

    function openViewer(entry) {
        window.AVBKReceiptViewer.open(photoUrl(entry), {
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
        var dt = new DataTransfer();
        entries.forEach(function (entry) {
            if (entry.file) {
                dt.items.add(entry.file);
            }
        });
        fileInput.files = dt.files;
    }

    function updateSubmitState() {
        if (!submitButton) return;
        var hasDuplicate = entries.some(function (e) { return e.status === 'duplicate'; });
        submitButton.disabled = entries.length === 0 || hasDuplicate;
    }

    function updateAmountSuggestion() {
        var sum = 0;
        var any = false;
        entries.forEach(function (e) {
            if (e.amount) {
                sum = Math.round((sum + e.amount) * 100) / 100;
                any = true;
            }
        });
        if (!any) return;
        if (!amountInput.value || amountInput.value === lastSuggestedSum) {
            amountInput.value = formatAmount(sum);
            lastSuggestedSum = amountInput.value;
        }
    }

    function updateStatusText() {
        var scanned = entries.filter(function (e) { return e.file; });
        var reading = scanned.filter(function (e) { return e.status === 'reading'; }).length;
        statusEl.setAttribute('aria-live', 'polite');
        statusEl.classList.toggle('avbk-reimbursement-ocr-status-busy', reading > 0);
        if (reading) {
            statusEl.textContent = 'Bonnetjes worden gelezen: ' + (scanned.length - reading) + ' van ' + scanned.length + ' klaar…';
            statusEl.classList.remove('avbk-reimbursement-ocr-status-error');
        } else if (entries.some(function (e) { return e.status === 'duplicate'; })) {
            statusEl.textContent = 'Eén of meer bonnetjes lijken al eerder gedeclareerd te zijn — verwijder ze om verder te gaan.';
            statusEl.classList.add('avbk-reimbursement-ocr-status-error');
        } else if (entries.length) {
            var unread = scanned.filter(function (e) { return e.read !== 'ok'; }).length;
            statusEl.textContent = (scanned.length ? 'Klaar: ' + scanned.length + (scanned.length === 1 ? ' bonnetje' : ' bonnetjes') + ' gelezen'
                + (unread ? ', bij ' + unread + ' niets gevonden' : '') + '. ' : '')
                + 'Controleer datum, winkel en bedrag; klik op een foto om hem groot te bekijken.';
            statusEl.classList.remove('avbk-reimbursement-ocr-status-error');
        } else {
            statusEl.hidden = true;
            return;
        }
        statusEl.hidden = false;
    }

    function renderList() {
        if (!listEl) return;
        listEl.innerHTML = '';
        if (tableEl) {
            tableEl.hidden = entries.length === 0;
        }
        entries.forEach(function (entry, index) {
            var tr = document.createElement('tr');
            if (entry.status === 'duplicate') {
                tr.classList.add('avbk-dropzone-item-duplicate');
            }
            tr.classList.toggle('avbk-dropzone-item-reading', entry.status === 'reading');

            var photoCell = document.createElement('td');
            if (entry.file && /^image\//.test(entry.file.type) && window.AVBKReceiptViewer) {
                var zoom = document.createElement('button');
                zoom.type = 'button';
                zoom.className = 'avbk-dropzone-item-zoom';
                zoom.title = 'Bekijk groot; sleep over datum, winkel of bedrag om het over te nemen';
                zoom.setAttribute('aria-label', 'Bonnetje ' + entry.file.name + ' groot bekijken');
                var img = document.createElement('img');
                img.src = photoUrl(entry);
                img.alt = '';
                img.className = 'avbk-dropzone-item-photo';
                zoom.appendChild(img);
                zoom.addEventListener('click', function () { openViewer(entry); });
                photoCell.appendChild(zoom);
            } else if (entry.file) {
                var fileLabel = document.createElement('span');
                fileLabel.className = 'avbk-dropzone-item-no-photo';
                fileLabel.textContent = /pdf$/i.test(entry.file.name) ? 'pdf' : 'bestand';
                photoCell.appendChild(fileLabel);
            } else {
                var noPhoto = document.createElement('span');
                noPhoto.className = 'avbk-dropzone-item-no-photo';
                noPhoto.textContent = 'geen foto';
                photoCell.appendChild(noPhoto);
            }
            tr.appendChild(photoCell);

            var dateCell = document.createElement('td');
            // A text field, so the date reads dd-mm-jjjj whatever the browser's
            // language; the server gets yyyy-mm-dd in the hidden date[].
            var dateWrap = document.createElement('span');
            dateWrap.className = 'avbk-dropzone-item-date-wrap';
            var dateInput = document.createElement('input');
            dateInput.type = 'text';
            dateInput.inputMode = 'numeric';
            dateInput.className = 'avbk-dropzone-item-date';
            dateInput.placeholder = 'dd-mm-jjjj';
            dateInput.setAttribute('aria-label', 'Datum (dd-mm-jjjj)');
            dateInput.value = formatDate(entry.date);
            var dateValue = document.createElement('input');
            dateValue.type = 'hidden';
            dateValue.name = 'date[]';
            dateValue.value = entry.date || '';
            var datePicker = document.createElement('input');
            datePicker.type = 'date';
            datePicker.className = 'avbk-dropzone-item-date-picker';
            datePicker.tabIndex = -1;
            datePicker.setAttribute('aria-hidden', 'true');
            var datePick = document.createElement('button');
            datePick.type = 'button';
            datePick.className = 'avbk-dropzone-item-date-pick';
            datePick.setAttribute('aria-label', 'Kies een datum');
            datePick.title = 'Kies een datum';
            datePick.textContent = '📅';
            function setDate(iso, fromTyping) {
                entry.date = iso || '';
                entry.dateEdited = true;
                dateValue.value = entry.date;
                dateInput.classList.toggle('avbk-dropzone-item-invalid', !iso && dateInput.value.trim() !== '');
                if (!fromTyping) {
                    dateInput.value = formatDate(entry.date);
                }
            }
            dateInput.addEventListener('input', function () { setDate(parseDate(dateInput.value), true); });
            dateInput.addEventListener('blur', function () {
                if (entry.date) {
                    dateInput.value = formatDate(entry.date);
                }
            });
            datePicker.addEventListener('change', function () { setDate(datePicker.value, false); });
            datePick.addEventListener('click', function () {
                datePicker.value = entry.date || '';
                if (typeof datePicker.showPicker === 'function') {
                    try { datePicker.showPicker(); return; } catch (err) { /* falls through */ }
                }
                datePicker.focus();
                datePicker.click();
            });
            var c = entry.candidates || {};
            var dateList = suggestions(dateInput, (c.dates || []).map(formatDate));
            if (dateList) dateWrap.appendChild(dateList);
            dateWrap.appendChild(dateInput);
            dateWrap.appendChild(datePick);
            dateWrap.appendChild(datePicker);
            dateWrap.appendChild(dateValue);
            dateCell.appendChild(dateWrap);
            tr.appendChild(dateCell);

            var storeCell = document.createElement('td');
            var storeInput = document.createElement('input');
            storeInput.type = 'text';
            storeInput.name = 'store[]';
            storeInput.className = 'avbk-dropzone-item-store';
            storeInput.placeholder = 'Winkel';
            storeInput.value = entry.store || '';
            storeInput.addEventListener('input', function () {
                entry.store = storeInput.value;
                entry.storeEdited = true;
            });
            storeCell.appendChild(storeInput);
            var storeList = suggestions(storeInput, c.stores);
            if (storeList) storeCell.appendChild(storeList);
            tr.appendChild(storeCell);

            var descriptionCell = document.createElement('td');
            var descriptionInput = document.createElement('input');
            descriptionInput.type = 'text';
            descriptionInput.name = 'description[]';
            descriptionInput.className = 'avbk-dropzone-item-description';
            descriptionInput.placeholder = 'Omschrijving (optioneel)';
            descriptionInput.value = entry.description || '';
            descriptionInput.addEventListener('input', function () {
                entry.description = descriptionInput.value;
                entry.descriptionEdited = true;
            });
            descriptionCell.appendChild(descriptionInput);
            tr.appendChild(descriptionCell);

            var amountCell = document.createElement('td');
            var amountRowInput = document.createElement('input');
            amountRowInput.type = 'text';
            amountRowInput.name = 'amount[]';
            amountRowInput.className = 'avbk-dropzone-item-amount';
            amountRowInput.placeholder = '0,00';
            amountRowInput.inputMode = 'decimal';
            amountRowInput.value = formatAmount(entry.amount);
            amountRowInput.addEventListener('input', function () {
                entry.amount = parseAmount(amountRowInput.value);
                entry.amountEdited = true;
                updateAmountSuggestion();
            });
            amountRowInput.addEventListener('blur', function () {
                if (entry.amount !== null) {
                    amountRowInput.value = formatAmount(entry.amount);
                }
            });
            amountCell.appendChild(amountRowInput);
            var amountList = suggestions(amountRowInput, (c.amounts || []).map(formatAmount));
            if (amountList) amountCell.appendChild(amountList);
            tr.appendChild(amountCell);

            var actionCell = document.createElement('td');
            var hasReceiptInput = document.createElement('input');
            hasReceiptInput.type = 'hidden';
            hasReceiptInput.name = 'has_receipt[]';
            hasReceiptInput.value = entry.file ? '1' : '0';
            actionCell.appendChild(hasReceiptInput);

            if (entry.file && entry.status !== 'duplicate') {
                var progress = document.createElement('span');
                progress.className = 'avbk-dropzone-item-progress avbk-dropzone-item-progress-' + (entry.status === 'reading' ? 'reading' : entry.read);
                progress.textContent = entry.status === 'reading' ? 'lezen…' : (entry.read === 'ok' ? '✓ gelezen' : 'niets gelezen – vul zelf in');
                actionCell.appendChild(progress);
                if (entry.status !== 'reading') {
                    var engine = nextEngine(entry);
                    var retry = document.createElement('button');
                    retry.type = 'button';
                    retry.className = 'avbk-dropzone-item-retry';
                    retry.textContent = '↻ opnieuw lezen' + (engine ? ' met ' + engines[engine] : '');
                    retry.title = 'Leest dit bonnetje opnieuw; wat je zelf hebt ingevuld blijft staan.';
                    retry.addEventListener('click', function () { readEntry(entry, engine); });
                    actionCell.appendChild(retry);
                }
            }
            if (entry.status === 'duplicate') {
                var warn = document.createElement('span');
                warn.className = 'avbk-dropzone-item-warning';
                warn.textContent = 'al eerder gedeclareerd';
                actionCell.appendChild(warn);
            }

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'avbk-dropzone-item-remove';
            remove.setAttribute('aria-label', 'Verwijderen');
            remove.textContent = '×';
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
            actionCell.appendChild(remove);
            tr.appendChild(actionCell);

            listEl.appendChild(tr);
        });
    }

    function addManualRow() {
        entries.push({
            file: null,
            status: 'ok',
            amount: null,
            amountEdited: false,
            store: '',
            storeEdited: false,
            description: '',
            descriptionEdited: false,
            date: '',
            dateEdited: false
        });
        updateSubmitState();
        updateStatusText();
        renderList();
    }

    var engines = cfg.engines || {};
    var engineCodes = Object.keys(engines);

    /** The engine to try next for a receipt: the other one when there are two, else the same. */
    function nextEngine(entry) {
        if (engineCodes.length < 2) return '';
        var current = entry.engine || engineCodes[0];
        return engineCodes[(engineCodes.indexOf(current) + 1) % engineCodes.length];
    }

    /** Reads a receipt (again); a value the member typed is never overwritten. */
    function readEntry(entry, engine) {
        entry.status = 'reading';
        entry.engine = engine || engineCodes[0] || '';
        var body = new FormData();
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
                if (data && data.success && data.data.duplicate) {
                    entry.status = 'duplicate';
                    return;
                }
                entry.status = 'ok';
                var found = data && data.success ? data.data : {};
                entry.read = found.amount !== null && found.amount !== undefined || found.store || found.date ? 'ok' : 'none';
                entry.candidates = found.candidates || null;
                if (!entry.amountEdited && found.amount !== null && found.amount !== undefined) {
                    entry.amount = found.amount;
                }
                if (!entry.storeEdited && found.store) {
                    entry.store = found.store;
                }
                if (!entry.dateEdited && found.date) {
                    entry.date = found.date;
                }
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

    var listCount = 0;
    /** A <datalist> of suggestions for one field, or null when there is nothing to suggest. */
    function suggestions(input, values) {
        if (!values || !values.length) return null;
        var list = document.createElement('datalist');
        list.id = 'avbk-suggest-' + (++listCount);
        values.forEach(function (value) {
            var option = document.createElement('option');
            option.value = value;
            list.appendChild(option);
        });
        input.setAttribute('list', list.id);
        return list;
    }

    function addFiles(fileListArg) {
        Array.prototype.forEach.call(fileListArg, function (file) {
            var entry = {
                file: file,
                status: 'reading',
                amount: null,
                amountEdited: false,
                store: null,
                storeEdited: false,
                description: '',
                descriptionEdited: false,
                date: '',
                dateEdited: false
            };
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
            var files = e.dataTransfer && e.dataTransfer.files;
            if (files && files.length) {
                addFiles(files);
            }
        });
    }

    fileInput.addEventListener('change', function () {
        if (!fileInput.files || !fileInput.files.length) return;
        addFiles(fileInput.files);
    });

    if (addManualRowBtn) {
        addManualRowBtn.addEventListener('click', addManualRow);
    }
});
