/*
 * Uitgaven: show only the fields that belong to the chosen kind of expense,
 * open the edit form of an explained expense,
 * fill each activity select from the one list on the page (the page sends
 * it once instead of once per payment), and take a document dropped
 * anywhere on a payment into that payment's document field.
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
        if (select.dataset.value) {
            select.value = select.dataset.value;
        }
    });
    // Verantwoord: "bewerken" opens that expense's edit form.
    document.querySelectorAll('[data-dialog]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = document.getElementById(button.getAttribute('data-dialog'));
            if (dialog) {
                dialog.showModal();
            }
        });
    });
    function hasFiles(event) {
        return event.dataTransfer && Array.prototype.indexOf.call(event.dataTransfer.types, 'Files') !== -1;
    }

    function accepts(input, file) {
        var name = file.name.toLowerCase();
        return input.accept.split(',').some(function (ext) { return name.slice(-ext.trim().length) === ext.trim(); });
    }

    // A file put into the field: a document means an invoice unless another kind was chosen.
    function takeFile(form, file) {
        var input = form.querySelector('input[type="file"][name="document"]');
        var hint = form.querySelector('.avbk-drop-hint');
        if (!input || !file) {
            return;
        }
        if (!accepts(input, file)) {
            hint.textContent = file.name + ' is geen pdf of foto; kies een ander bestand.';
            return;
        }
        var transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        var kind = form.querySelector('.avbk-expense-kind');
        if (kind.value === '') {
            kind.value = 'invoice';
        }
        update(form);
        hint.textContent = 'Gekozen: ' + file.name;
    }

    function enableDrop(form) {
        var card = form.closest('.avbk-review-row') || form;
        var depth = 0;
        card.addEventListener('dragenter', function (event) {
            if (hasFiles(event)) {
                depth++;
                card.classList.add('avbk-drop-active');
            }
        });
        card.addEventListener('dragleave', function () {
            depth = Math.max(0, depth - 1);
            if (depth === 0) {
                card.classList.remove('avbk-drop-active');
            }
        });
        card.addEventListener('dragover', function (event) {
            if (hasFiles(event)) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'copy';
            }
        });
        card.addEventListener('drop', function (event) {
            if (!hasFiles(event)) {
                return;
            }
            event.preventDefault();
            depth = 0;
            card.classList.remove('avbk-drop-active');
            takeFile(form, event.dataTransfer.files[0]);
        });
    }

    // Bank costs get their category without asking.
    function fillCategory(form) {
        var category = form.querySelector('input[name="category"]');
        if (category && category.value === '' && form.querySelector('.avbk-expense-kind').value === 'bank_costs') {
            category.value = 'Bankkosten';
        }
    }

    document.querySelectorAll('.avbk-expense-form').forEach(function (form) {
        form.querySelector('.avbk-expense-kind').addEventListener('change', function () { fillCategory(form); update(form); });
        update(form);
        enableDrop(form);
    });

    // A file dropped next to a payment must not open in the browser and leave the page.
    ['dragover', 'drop'].forEach(function (type) {
        window.addEventListener(type, function (event) {
            if (hasFiles(event) && !(event.target instanceof Element && event.target.closest('.avbk-review-row'))) {
                event.preventDefault();
            }
        });
    });
}());
