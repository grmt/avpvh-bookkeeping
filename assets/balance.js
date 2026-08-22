document.addEventListener('DOMContentLoaded', function () {
    var table = document.getElementById('avbk-balance-table');
    if (!table) return;

    var thead = table.tHead;
    var headerRow = thead.querySelector('.avbk-balance-header-row');
    var headerCells = Array.prototype.slice.call(headerRow.children);
    var tbody = table.tBodies[0];

    function dataRows() {
        return Array.prototype.slice.call(tbody.rows).filter(function (row) {
            return row.children.length === headerCells.length;
        });
    }

    var rows = dataRows();
    if (!rows.length) return;

    // --- Sorting ---
    function cellValue(row, index, numeric) {
        var text = row.children[index].textContent.trim();
        if (!numeric) return text.toLowerCase();
        var n = parseFloat(text.replace(/[^0-9,.-]/g, '').replace(/\./g, '').replace(',', '.'));
        return isNaN(n) ? 0 : n;
    }

    headerCells.forEach(function (th, index) {
        if (!th.dataset.col) return;
        th.classList.add('avbk-sortable');
        var indicator = document.createElement('span');
        indicator.className = 'avbk-sort-indicator';
        th.appendChild(indicator);

        th.addEventListener('click', function () {
            var numeric = th.dataset.type === 'number';
            var dir = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';
            headerCells.forEach(function (other) {
                delete other.dataset.sortDir;
                var ind = other.querySelector('.avbk-sort-indicator');
                if (ind) ind.textContent = '';
            });
            th.dataset.sortDir = dir;
            indicator.textContent = dir === 'asc' ? ' ▲' : ' ▼';

            var sorted = dataRows().sort(function (a, b) {
                var av = cellValue(a, index, numeric);
                var bv = cellValue(b, index, numeric);
                if (numeric) {
                    return dir === 'asc' ? av - bv : bv - av;
                }
                return dir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
            });
            sorted.forEach(function (row) { tbody.appendChild(row); });
        });
    });

    // --- Filter row ---
    var filterRow = document.createElement('tr');
    filterRow.className = 'avbk-balance-filter-row';
    var filters = {};

    headerCells.forEach(function (th, index) {
        var col = th.dataset.col;
        var filterTh = document.createElement('th');
        if (!col) {
            filterRow.appendChild(filterTh);
            return;
        }

        var control;
        if (th.dataset.filter === 'select') {
            control = document.createElement('select');
            var values = [];
            dataRows().forEach(function (row) {
                var v = row.children[index].textContent.trim();
                if (v && values.indexOf(v) === -1) values.push(v);
            });
            values.sort();
            var optAll = document.createElement('option');
            optAll.value = '';
            optAll.textContent = 'Alle';
            control.appendChild(optAll);
            values.forEach(function (v) {
                var opt = document.createElement('option');
                opt.value = v;
                opt.textContent = v;
                control.appendChild(opt);
            });
            control.addEventListener('change', applyFilters);
        } else {
            control = document.createElement('input');
            control.type = 'text';
            control.placeholder = 'Filter…';
            control.addEventListener('input', applyFilters);
        }
        control.className = 'avbk-filter-control';
        filterTh.appendChild(control);
        filterRow.appendChild(filterTh);
        filters[index] = control;
    });
    thead.appendChild(filterRow);

    function applyFilters() {
        dataRows().forEach(function (row) {
            var visible = true;
            Object.keys(filters).forEach(function (idxStr) {
                if (!visible) return;
                var idx = parseInt(idxStr, 10);
                var control = filters[idx];
                var filterVal = control.value.trim().toLowerCase();
                if (!filterVal) return;
                var cellText = row.children[idx].textContent.trim().toLowerCase();
                if (control.tagName === 'SELECT') {
                    if (cellText !== filterVal) visible = false;
                } else if (cellText.indexOf(filterVal) === -1) {
                    visible = false;
                }
            });
            row.style.display = visible ? '' : 'none';
        });
    }

    // --- Column show/hide ---
    var storageKey = 'avbk_balance_hidden_cols';
    var panel = document.querySelector('.avbk-col-toggle-panel');
    var toggleBtn = document.querySelector('.avbk-col-toggle-btn');
    var footRow = table.tFoot ? table.tFoot.rows[0] : null;

    function storedHidden() {
        try {
            var raw = window.localStorage.getItem(storageKey);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function saveHidden(list) {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(list));
        } catch (e) { /* ignore */ }
    }

    function setColumnVisibility(index, visible) {
        // Explicit 'table-cell' (not '') so this inline style reliably wins
        // over the .avbk-col-optional{display:none} mobile media-query rule
        // when the visitor re-enables a column by hand. tfoot has one cell
        // per column (no colspan) precisely so it can be toggled the same
        // way as thead/tbody without drifting out of alignment.
        var rows = [headerRow, filterRow].concat(dataRows());
        if (footRow) rows.push(footRow);
        rows.forEach(function (row) {
            var cell = row.children[index];
            if (cell) cell.style.display = visible ? 'table-cell' : 'none';
        });
    }

    var stored = storedHidden();
    var hidden = stored !== null
        ? stored
        : (window.matchMedia && window.matchMedia('(max-width: 600px)').matches
            ? headerCells.filter(function (th) { return th.classList.contains('avbk-col-optional'); }).map(function (th) { return th.dataset.col; })
            : []);

    headerCells.forEach(function (th, index) {
        var col = th.dataset.col;
        if (!col) return;
        var isHidden = hidden.indexOf(col) !== -1;
        if (isHidden) setColumnVisibility(index, false);

        var label = document.createElement('label');
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = !isHidden;
        cb.addEventListener('change', function () {
            setColumnVisibility(index, cb.checked);
            var current = storedHidden() || [];
            current = current.filter(function (c) { return c !== col; });
            if (!cb.checked) current.push(col);
            saveHidden(current);
        });
        label.appendChild(cb);
        label.appendChild(document.createTextNode(' ' + th.textContent.replace(/[▲▼]/g, '').trim()));
        panel.appendChild(label);
    });

    if (toggleBtn && panel) {
        toggleBtn.addEventListener('click', function () {
            panel.hidden = !panel.hidden;
        });
    }
});
