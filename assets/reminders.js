/*
 * Herinneringen: count and confirm the selection, and send a round in
 * batches while its progress page is open.
 */
(function () {
    'use strict';

    var form = document.getElementById('avbk-reminder-form');
    if (form) {
        var count = document.getElementById('avbk-reminder-count');
        var all = document.getElementById('avbk-reminder-all');
        var boxes = function () { return Array.prototype.slice.call(form.querySelectorAll('.avbk-reminder-member')); };
        // A member hidden by a filter or the search box is never mailed,
        // even when ticked: only the ticked rows you can see count.
        var visible = function (box) { return !box.closest('tr').hidden; };
        var chosen = function () { return boxes().filter(function (box) { return box.checked && visible(box); }); };
        var update = function () {
            count.textContent = chosen().length;
        };
        form.addEventListener('change', function (event) {
            if (event.target.classList.contains('avbk-reminder-member')) {
                update();
            }
        });
        if (all) {
            all.addEventListener('change', function () {
                boxes().filter(visible).forEach(function (box) { box.checked = all.checked; });
                update();
            });
        }
        form.addEventListener('avpvh-table-filtered', update);
        // Hidden rows' boxes are left out of what is posted, for every
        // button (preview, test, send), and put back right after.
        form.addEventListener('submit', function () {
            var hidden = boxes().filter(function (box) { return !visible(box) && !box.disabled; });
            hidden.forEach(function (box) { box.disabled = true; });
            window.setTimeout(function () { hidden.forEach(function (box) { box.disabled = false; }); }, 0);
        });
        document.getElementById('avbk-reminder-send').addEventListener('click', function (event) {
            var n = chosen().length;
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
