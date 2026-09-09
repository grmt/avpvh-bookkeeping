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
    function cellValue(row, index, type) {
        var cell = row.children[index];
        var text = cell.dataset.sortValue || cell.textContent.trim();
        if (type !== 'number') return text.toLowerCase();
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
            var previousScroll = window.scrollY;
            var type = th.dataset.type || 'text';
            var dir = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';
            headerCells.forEach(function (other) {
                delete other.dataset.sortDir;
                var ind = other.querySelector('.avbk-sort-indicator');
                if (ind) ind.textContent = '';
            });
            th.dataset.sortDir = dir;
            indicator.textContent = dir === 'asc' ? ' ▲' : ' ▼';

            var sorted = dataRows().sort(function (a, b) {
                var av = cellValue(a, index, type);
                var bv = cellValue(b, index, type);
                if (type === 'number') {
                    return dir === 'asc' ? av - bv : bv - av;
                }
                return dir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
            });
            sorted.forEach(function (row) { tbody.appendChild(row); });
            window.requestAnimationFrame(function () { window.scrollTo(0, previousScroll); });
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
            // A cell can carry several comma-separated values (e.g. a
            // member with two different banks) — split into individual
            // options rather than listing "ING, Rabobank" as one single
            // combined checkbox, and match a row if ANY of its own
            // values matches, not the whole joined string.
            function splitValues(cell) {
                return (cell.dataset.filterValue || cell.textContent)
                    .split(',')
                    .map(function (s) { return s.trim(); })
                    .filter(function (s) { return s !== ''; });
            }

            var wrapper = document.createElement('div');
            wrapper.className = 'avbk-checklist-filter';
            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'avbk-checklist-toggle';
            toggle.textContent = 'Alle';
            var menu = document.createElement('div');
            menu.className = 'avbk-checklist-menu';
            menu.hidden = true;
            // value (lowercased) -> 'include' | 'exclude', absent = no filter on it.
            var states = {};
            var values = [];
            dataRows().forEach(function (row) {
                splitValues(row.children[index]).forEach(function (v) {
                    if (values.indexOf(v) === -1) values.push(v);
                });
            });
            values.sort();

            function updateToggleLabel() {
                var included = 0, excluded = 0;
                Object.keys(states).forEach(function (v) {
                    if (states[v] === 'include') included++;
                    else if (states[v] === 'exclude') excluded++;
                });
                if (!included && !excluded) {
                    toggle.textContent = 'Alle';
                } else {
                    var parts = [];
                    if (included) parts.push(included + ' geselecteerd');
                    if (excluded) parts.push(excluded + ' uitgesloten');
                    toggle.textContent = parts.join(', ');
                }
            }

            values.forEach(function (v) {
                var key = v.toLowerCase();
                var option = document.createElement('button');
                option.type = 'button';
                option.className = 'avbk-tristate-option';
                option.dataset.state = 'none';
                var icon = document.createElement('span');
                icon.className = 'avbk-tristate-icon';
                option.appendChild(icon);
                option.appendChild(document.createTextNode(' ' + v));
                option.addEventListener('click', function () {
                    var next = option.dataset.state === 'none' ? 'include'
                        : option.dataset.state === 'include' ? 'exclude'
                        : 'none';
                    option.dataset.state = next;
                    icon.textContent = next === 'include' ? '✓' : next === 'exclude' ? '−' : '';
                    if (next === 'none') delete states[key];
                    else states[key] = next;
                    updateToggleLabel();
                    applyFiltersPreservingScroll();
                });
                menu.appendChild(option);
            });
            toggle.addEventListener('click', function (event) {
                event.stopPropagation();
                menu.hidden = !menu.hidden;
            });
            wrapper.appendChild(toggle);
            wrapper.appendChild(menu);
            control = {
                element: wrapper,
                // Named avbkMatches, not matches — Element.prototype.matches()
                // is a native DOM method every plain <input> already has, so
                // "typeof control.matches === 'function'" was true for those
                // too and wrongly routed them into this branch.
                // A row is visible if it has no values matching any
                // 'exclude', and — only when at least one 'include' is
                // set anywhere — it matches at least one of those.
                avbkMatches: function (cellValues) {
                    var hasInclude = false;
                    for (var key in states) {
                        if (states[key] === 'include') { hasInclude = true; break; }
                    }
                    var matchesInclude = !hasInclude;
                    for (var i = 0; i < cellValues.length; i++) {
                        var v = cellValues[i];
                        if (states[v] === 'exclude') return false;
                        if (states[v] === 'include') matchesInclude = true;
                    }
                    return matchesInclude;
                },
                splitValues: splitValues
            };
            filterTh.appendChild(wrapper);
        } else {
            control = document.createElement('input');
            control.type = 'text';
            control.placeholder = 'Filter…';
            control.addEventListener('input', applyFiltersPreservingScroll);
            filterTh.appendChild(control);
        }
        if (control.nodeType) control.className = 'avbk-filter-control';
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
                var cell = row.children[idx];
                if (control && typeof control.avbkMatches === 'function') {
                    var cellValues = control.splitValues(cell).map(function (v) { return v.toLowerCase(); });
                    if (!control.avbkMatches(cellValues)) visible = false;
                } else {
                    var cellText = (cell.dataset.filterValue || cell.textContent).trim().toLowerCase();
                    var filterVal = control.value.trim().toLowerCase();
                    if (filterVal && cellText.indexOf(filterVal) === -1) visible = false;
                }
            });
            row.style.display = visible ? '' : 'none';
        });
        updateTotals();
    }

    function updateTotals() {
        var foot = table.tFoot;
        if (!foot || !foot.rows[0]) return;
        var cells = foot.rows[0].children;
        var paid = 0;
        var due = 0;
        dataRows().forEach(function (row) {
            if (row.style.display === 'none') return;
            var paidValue = parseFloat((row.children[4].dataset.sortValue || '0').replace(',', '.'));
            var dueValue = parseFloat((row.children[5].dataset.sortValue || '0').replace(',', '.'));
            if (!isNaN(paidValue)) paid += paidValue;
            if (!isNaN(dueValue)) due += dueValue;
        });
        if (cells[4]) cells[4].textContent = '€ ' + paid.toLocaleString('nl-NL', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (cells[5]) cells[5].textContent = '€ ' + due.toLocaleString('nl-NL', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function applyFiltersPreservingScroll() {
        var previousScroll = window.scrollY;
        applyFilters();
        window.requestAnimationFrame(function () { window.scrollTo(0, previousScroll); });
    }

    // --- Column show/hide ---
    var storageKey = table.dataset.storageKey || 'avbk_balance_hidden_cols';
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
            var previousScroll = window.scrollY;
            setColumnVisibility(index, cb.checked);
            var current = storedHidden() || [];
            current = current.filter(function (c) { return c !== col; });
            if (!cb.checked) current.push(col);
            saveHidden(current);
            window.requestAnimationFrame(function () { window.scrollTo(0, previousScroll); });
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
    updateTotals();
});
