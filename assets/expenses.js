/* Uitgaven: show only the fields that belong to the chosen kind of expense. */
(function () {
    'use strict';
    function update(form) {
        var kind = form.querySelector('.avbk-expense-kind').value;
        form.querySelectorAll('[data-kinds]').forEach(function (el) {
            var kinds = el.getAttribute('data-kinds').split(' ');
            el.hidden = kind === '' || kinds.indexOf(kind) === -1;
        });
    }
    document.querySelectorAll('.avbk-expense-form').forEach(function (form) {
        form.querySelector('.avbk-expense-kind').addEventListener('change', function () { update(form); });
        update(form);
    });
}());
