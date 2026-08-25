document.addEventListener('DOMContentLoaded', function () {
    var fileInput = document.getElementById('avbk-receipt-input');
    var configEl = document.getElementById('avbk-reimbursement-config');
    var statusEl = document.getElementById('avbk-ocr-status');
    var amountInput = document.getElementById('avbk-amount');
    var ocrAmountField = document.getElementById('avbk-ocr-amount');
    if (!fileInput || !configEl || !statusEl || !amountInput) return;

    var cfg = JSON.parse(configEl.textContent);

    fileInput.addEventListener('change', function () {
        if (!fileInput.files || !fileInput.files[0]) return;

        statusEl.hidden = false;
        statusEl.textContent = 'Bonnetje wordt gelezen…';

        var body = new FormData();
        body.append('action', 'avbk_ocr_receipt');
        body.append('nonce', cfg.nonce);
        body.append('receipt', fileInput.files[0]);

        fetch(cfg.ajaxUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var amount = data && data.success ? data.data.amount : null;
                if (amount) {
                    ocrAmountField.value = amount;
                    if (!amountInput.value) {
                        amountInput.value = String(amount).replace('.', ',');
                    }
                    statusEl.textContent = 'Bedrag herkend: € ' + String(amount).replace('.', ',') + ' — controleer en pas aan indien nodig.';
                } else {
                    statusEl.textContent = 'Bedrag niet automatisch herkend — vul het handmatig in.';
                }
            })
            .catch(function () {
                statusEl.textContent = 'Bedrag niet automatisch herkend — vul het handmatig in.';
            });
    });
});
