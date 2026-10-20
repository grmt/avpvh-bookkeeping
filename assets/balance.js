/*
 * Sorting, filtering, and column show/hide for the avbk-balance-table pages
 * now live in avpvh-members' assets/sortable-table.js (shared component,
 * see avpvh-members PR #64/#65) instead of being duplicated here. This file
 * only keeps the two bits that are specific to avpvh-bookkeeping:
 *  - keeping a <tfoot> cell marked data-total-col="<col>" in sync with the
 *    currently-visible rows, since sortable-table.js only toggles a
 *    tfoot cell's visibility on column show/hide, not its content;
 *  - enabling/disabling the payment-selection buttons based on which
 *    checkboxes are ticked.
 */
document.addEventListener('DOMContentLoaded', function () {
    // --- Footer totals ---
    function updateTotals(table) {
        var foot = table.tFoot;
        if (!foot || !foot.rows[0]) return;
        var totalCells = Array.prototype.slice.call(foot.rows[0].cells).filter(function (cell) {
            return cell.dataset.totalCol;
        });
        if (!totalCells.length) return;

        var colIndex = {};
        Array.prototype.slice.call(table.tHead.rows[0].cells).forEach(function (th, index) {
            if (th.dataset.col) colIndex[th.dataset.col] = index;
        });

        var sums = {};
        totalCells.forEach(function (cell) { sums[cell.dataset.totalCol] = 0; });
        Array.prototype.slice.call(table.tBodies[0].rows).forEach(function (row) {
            if (row.hidden) return;
            totalCells.forEach(function (cell) {
                var index = colIndex[cell.dataset.totalCol];
                var dataCell = index === undefined ? null : row.cells[index];
                var value = dataCell ? parseFloat(dataCell.dataset.sortValue || '0') : NaN;
                if (!isNaN(value)) sums[cell.dataset.totalCol] += value;
            });
        });
        totalCells.forEach(function (cell) {
            cell.textContent = '€ ' + sums[cell.dataset.totalCol].toLocaleString('nl-NL', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        });
    }

    // sortable-table.js dispatches this on the <table> itself after every
    // filter change, including the initial (possibly deep-linked) state —
    // the first one arrives asynchronously, but a listener added here still
    // catches it since this whole handler already runs on DOMContentLoaded.
    document.addEventListener('avpvh-table-filtered', function (event) {
        updateTotals(event.target);
    });

    // --- Payment-selection buttons: keep them disabled until a relevant
    // checkbox is actually ticked, mirroring what the server itself
    // requires (handle_balance_selection() needs fee_item_ids[],
    // settle_credit_selection() needs both fee_item_ids[] and apply_credit).
    var selectionForm = document.getElementById('avbk-payment-selection');
    if (selectionForm) {
        var itemCheckboxes = Array.prototype.slice.call(
            document.querySelectorAll('input[type="checkbox"][name="fee_item_ids[]"][form="avbk-payment-selection"]')
        );
        var creditCheckbox = document.querySelector('input[type="checkbox"][name="apply_credit"][form="avbk-payment-selection"]');
        var requestButtons = Array.prototype.slice.call(selectionForm.querySelectorAll('button[name="request_mode"]'));
        var settleButton = selectionForm.querySelector('button[name="do_settle_credit"]');

        var updateSelectionButtons = function () {
            var anyItemChecked = false;
            itemCheckboxes.forEach(function (cb) {
                if (cb.checked) anyItemChecked = true;
            });
            var creditChecked = !!(creditCheckbox && creditCheckbox.checked);
            requestButtons.forEach(function (btn) { btn.disabled = !anyItemChecked; });
            if (settleButton) settleButton.disabled = !(anyItemChecked && creditChecked);
        };

        itemCheckboxes.forEach(function (cb) { cb.addEventListener('change', updateSelectionButtons); });
        if (creditCheckbox) creditCheckbox.addEventListener('change', updateSelectionButtons);
        updateSelectionButtons();
    }
});
