/*
 * Uitgaven: show only the fields that belong to the chosen kind of expense,
 * and fill each activity select from the one list on the page (the page
 * sends it once instead of once per payment).
 */
(function () {
    'use strict';
    function update(form) {
        var kind = form.querySelector('.avbk-expense-kind').value;
        form.querySelectorAll('[data-kinds]').forEach(function (el) {
            var kinds = el.getAttribute('data-kinds').split(' ');
            el.hidden = kind === '' || kinds.indexOf(kind) === -1;
        });
    }
    document.querySelectorAll('select[data-options]').forEach(function (select) {
        var source = document.getElementById(select.getAttribute('data-options'));
        if (source) {
            select.appendChild(source.content.cloneNode(true));
        }
    });
    document.querySelectorAll('.avbk-expense-form').forEach(function (form) {
        form.querySelector('.avbk-expense-kind').addEventListener('change', function () { update(form); });
        update(form);
    });
}());
