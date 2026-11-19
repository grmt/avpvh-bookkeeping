/*
 * Herinneringen: count and confirm the selection, and send a round in
 * batches while its progress page is open.
 */
(function () {
    'use strict';

    var form = document.getElementById('avbk-reminder-form');
    if (form) {
        var boxes = form.querySelectorAll('.avbk-reminder-member');
        var count = document.getElementById('avbk-reminder-count');
        var all = document.getElementById('avbk-reminder-all');
        var update = function () {
            count.textContent = form.querySelectorAll('.avbk-reminder-member:checked').length;
        };
        boxes.forEach(function (box) { box.addEventListener('change', update); });
        if (all) {
            all.addEventListener('change', function () {
                boxes.forEach(function (box) { box.checked = all.checked; });
                update();
            });
        }
        document.getElementById('avbk-reminder-send').addEventListener('click', function (event) {
            var n = form.querySelectorAll('.avbk-reminder-member:checked').length;
            if (!n || !window.confirm('Je stuurt ' + n + (n === 1 ? ' herinnering' : ' herinneringen') + '. Doorgaan?')) {
                event.preventDefault();
            }
        });
        update();
    }

    var progress = document.getElementById('avbk-reminder-progress');
    if (!progress || !window.avbkReminders || Number(progress.dataset.pending) === 0) {
        return;
    }
    var bar = progress.querySelector('progress');
    var status = progress.querySelector('.avbk-reminder-status');
    var hint = progress.querySelector('.avbk-reminder-hint');
    var next = function () {
        var body = new URLSearchParams({ action: 'avbk_reminders_batch', _ajax_nonce: window.avbkReminders.nonce, round: progress.dataset.round });
        fetch(window.avbkReminders.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result.success) {
                    throw new Error('batch');
                }
                var c = result.data;
                var total = c.pending + c.sending + c.sent + c.failed + c.skipped;
                bar.max = total;
                bar.value = total - c.pending - c.sending;
                status.textContent = c.sent + ' verstuurd, ' + c.failed + ' mislukt, ' + c.skipped + ' overgeslagen, ' + c.pending + ' te gaan';
                if (c.pending > 0) {
                    next();
                } else {
                    hint.textContent = 'Klaar. Herlaad de pagina voor de uitkomst per lid.';
                }
            })
            .catch(function () {
                hint.textContent = 'Het versturen is onderbroken. Herlaad de pagina om verder te gaan.';
            });
    };
    next();
}());
