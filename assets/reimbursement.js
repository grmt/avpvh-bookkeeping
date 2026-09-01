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

    function parseAmount(text) {
        var n = parseFloat(String(text || '').replace(',', '.'));
        return isNaN(n) ? null : n;
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
            amountInput.value = String(sum).replace('.', ',');
            lastSuggestedSum = amountInput.value;
        }
    }

    function updateStatusText() {
        if (entries.some(function (e) { return e.status === 'reading'; })) {
            statusEl.textContent = 'Bonnetje(s) worden gelezen…';
            statusEl.classList.remove('avbk-reimbursement-ocr-status-error');
        } else if (entries.some(function (e) { return e.status === 'duplicate'; })) {
            statusEl.textContent = 'Eén of meer bonnetjes lijken al eerder gedeclareerd te zijn — verwijder ze om verder te gaan.';
            statusEl.classList.add('avbk-reimbursement-ocr-status-error');
        } else if (entries.length) {
            statusEl.textContent = entries.length + ' regel(s) toegevoegd — controleer datum, winkel en bedrag.';
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

            var photoCell = document.createElement('td');
            if (entry.file) {
                var img = document.createElement('img');
                img.src = URL.createObjectURL(entry.file);
                img.alt = entry.file.name;
                img.className = 'avbk-dropzone-item-photo';
                photoCell.appendChild(img);
            } else {
                var noPhoto = document.createElement('span');
                noPhoto.className = 'avbk-dropzone-item-no-photo';
                noPhoto.textContent = 'geen foto';
                photoCell.appendChild(noPhoto);
            }
            tr.appendChild(photoCell);

            var dateCell = document.createElement('td');
            var dateInput = document.createElement('input');
            dateInput.type = 'date';
            dateInput.name = 'date[]';
            dateInput.className = 'avbk-dropzone-item-date';
            dateInput.value = entry.date || '';
            dateInput.addEventListener('input', function () {
                entry.date = dateInput.value;
                entry.dateEdited = true;
            });
            dateCell.appendChild(dateInput);
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
            amountRowInput.value = entry.amount !== null && entry.amount !== undefined ? String(entry.amount).replace('.', ',') : '';
            amountRowInput.addEventListener('input', function () {
                entry.amount = parseAmount(amountRowInput.value);
                entry.amountEdited = true;
                updateAmountSuggestion();
            });
            amountCell.appendChild(amountRowInput);
            tr.appendChild(amountCell);

            var actionCell = document.createElement('td');
            var hasReceiptInput = document.createElement('input');
            hasReceiptInput.type = 'hidden';
            hasReceiptInput.name = 'has_receipt[]';
            hasReceiptInput.value = entry.file ? '1' : '0';
            actionCell.appendChild(hasReceiptInput);

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

            var body = new FormData();
            body.append('action', 'avbk_ocr_receipt');
            body.append('nonce', cfg.nonce);
            body.append('receipt', file);

            fetch(cfg.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success && data.data.duplicate) {
                        entry.status = 'duplicate';
                    } else {
                        entry.status = 'ok';
                        // Only pre-fill from OCR if the member hasn't typed
                        // their own value in the meantime — a re-render
                        // (another file resolving) must never clobber what
                        // they already wrote.
                        if (!entry.amountEdited) {
                            entry.amount = data && data.success ? data.data.amount : null;
                        }
                        if (!entry.storeEdited) {
                            entry.store = data && data.success ? data.data.store : null;
                        }
                        if (!entry.dateEdited) {
                            var ocrDate = data && data.success ? data.data.date : null;
                            if (ocrDate) {
                                entry.date = ocrDate;
                            }
                        }
                    }
                })
                .catch(function () {
                    entry.status = 'ok';
                })
                .finally(function () {
                    updateAmountSuggestion();
                    updateSubmitState();
                    updateStatusText();
                    renderList();
                });
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
