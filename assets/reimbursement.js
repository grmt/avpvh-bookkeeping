document.addEventListener('DOMContentLoaded', function () {
    var fileInput = document.getElementById('avbk-receipt-input');
    var configEl = document.getElementById('avbk-reimbursement-config');
    var statusEl = document.getElementById('avbk-ocr-status');
    var amountInput = document.getElementById('avbk-amount');
    var dropzone = document.getElementById('avbk-dropzone');
    var listEl = document.getElementById('avbk-dropzone-list');
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

    // Each entry: { file, status: 'reading'|'ok'|'duplicate', amount }
    var entries = [];
    var lastSuggestedSum = null;

    function syncFileInput() {
        var dt = new DataTransfer();
        entries.forEach(function (entry) {
            dt.items.add(entry.file);
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
            statusEl.textContent = entries.length + ' bonnetje(s) toegevoegd — controleer het bedrag.';
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
        entries.forEach(function (entry, index) {
            var li = document.createElement('li');
            li.className = 'avbk-dropzone-item';

            var img = document.createElement('img');
            img.src = URL.createObjectURL(entry.file);
            img.alt = entry.file.name;
            li.appendChild(img);

            var label = document.createElement('span');
            label.className = 'avbk-dropzone-item-name';
            label.textContent = entry.file.name;
            li.appendChild(label);

            if (entry.status === 'duplicate') {
                var warn = document.createElement('span');
                warn.className = 'avbk-dropzone-item-warning';
                warn.textContent = 'al eerder gedeclareerd';
                li.appendChild(warn);
                li.classList.add('avbk-dropzone-item-duplicate');
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
            li.appendChild(remove);

            listEl.appendChild(li);
        });
    }

    function addFiles(fileListArg) {
        Array.prototype.forEach.call(fileListArg, function (file) {
            var entry = { file: file, status: 'reading', amount: null };
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
                        entry.amount = data && data.success ? data.data.amount : null;
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
});
