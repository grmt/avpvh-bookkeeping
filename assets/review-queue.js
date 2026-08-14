document.addEventListener('DOMContentLoaded', function () {
    var configEl = document.getElementById('avbk-review-config');
    if (!configEl) return;
    var cfg = JSON.parse(configEl.textContent);

    // Every form on this page is a full-page admin-post submit (recompute,
    // confirm, ignore) — without this, the submit button just sits there
    // looking clickable for as long as the request takes, inviting a
    // second, wasted click before the page navigates away.
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"], input[type="submit"]');
            if (!btn || btn.disabled) return;
            btn.disabled = true;
            if (btn.tagName === 'BUTTON') btn.textContent = 'Bezig...';
        });
    });

    // Once the first (payer) row on a transaction has a member selected,
    // pre-suggest their household/family as an option group at the top of
    // every still-blank row below it — the overwhelmingly likely candidates
    // for the rest of a multi-person payment, and much faster to pick from
    // than the full member list.
    function applyHouseholdSuggestions(form, selects, candidates) {
        selects.forEach(function (select, idx) {
            if (idx === 0 || select.value) return; // leave the trigger row and any already-filled row alone

            var existing = select.querySelector('optgroup[data-avbk-suggested]');
            if (existing) existing.remove();
            if (!candidates.length) return;

            var group = document.createElement('optgroup');
            group.label = 'Suggesties (familie/huisgenoten)';
            group.setAttribute('data-avbk-suggested', '1');
            candidates.forEach(function (c) {
                var opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.label;
                group.appendChild(opt);
            });
            select.insertBefore(group, select.firstChild);
        });
    }

    function loadHouseholdSuggestions(form) {
        var selects = Array.from(form.querySelectorAll('select[name^="member_id"]'));
        var first = selects[0];
        if (!first || !first.value) return;

        var body = new URLSearchParams();
        body.set('action', 'avbk_household_candidates');
        body.set('nonce', cfg.nonce);
        body.set('member_id', first.value);

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) return;
                applyHouseholdSuggestions(form, selects, res.data);
            });
    }

    // Extracted so it can be attached both to rows rendered by PHP at page
    // load and to blank rows cloned client-side via "+ voeg lid toe" —
    // otherwise only the original rows would get live fee-detail lookups.
    function wireMemberSelect(select, form) {
        select.addEventListener('change', function () {
            var row = select.closest('tr');
            if (!row) return;

            var fragmentsEl = row.querySelector('.avbk-detail-fragments');
            var estimatedEl = row.querySelector('.avbk-detail-estimated');
            var nightsLink = row.querySelector('.avbk-detail-nights-link');
            var memberLink = row.querySelector('.avbk-detail-member-link');
            var amountInput = row.querySelector('.avbk-amount-input');

            // Clear stale detail immediately — showing the *previous*
            // person's age/nights against the *newly* selected member
            // would be actively misleading, even briefly.
            if (fragmentsEl) fragmentsEl.innerHTML = '';
            if (estimatedEl) estimatedEl.textContent = '';
            if (nightsLink) nightsLink.style.display = 'none';
            if (memberLink) memberLink.style.display = 'none';

            if (!select.value) return;

            var types = Array.from(form.querySelectorAll('input[name="type[]"]:checked')).map(function (cb) {
                return cb.value;
            });

            var body = new URLSearchParams();
            body.set('action', 'avbk_member_fee_detail');
            body.set('nonce', cfg.nonce);
            body.set('member_id', select.value);
            types.forEach(function (t) { body.append('types[]', t); });

            fetch(cfg.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) return;
                    var d = res.data;
                    if (fragmentsEl) fragmentsEl.innerHTML = d.fragments_html || '';
                    if (estimatedEl) estimatedEl.textContent = d.estimated_text || '';
                    if (nightsLink) {
                        if (d.nights_edit_url) {
                            nightsLink.href = d.nights_edit_url;
                            nightsLink.style.display = '';
                        } else {
                            nightsLink.style.display = 'none';
                        }
                    }
                    if (memberLink && d.member_edit_url) {
                        memberLink.href = d.member_edit_url;
                        memberLink.style.display = '';
                    }
                    if (amountInput && d.found) {
                        amountInput.value = d.share.toFixed(2).replace('.', ',');
                    }
                });
        });
    }

    // The "Type:" dropdown's summary text (visible while the checkbox list
    // itself is collapsed) — recomputed from whichever boxes are currently
    // checked, contributie/kamp and losse-post categories alike.
    function updateTypeSummary(dropdown) {
        var summaryEl = dropdown.querySelector('.avbk-type-summary');
        if (!summaryEl) return;
        var labels = Array.from(dropdown.querySelectorAll('.avbk-type-checkbox:checked')).map(function (cb) {
            return cb.closest('label').textContent.trim();
        });
        summaryEl.textContent = labels.length ? labels.join(', ') : 'geen';
    }

    document.querySelectorAll('.avbk-review-form').forEach(function (form) {
        loadHouseholdSuggestions(form); // rows often already arrive pre-filled with a suggested payer

        var firstSelect = form.querySelector('select[name^="member_id"]');
        if (firstSelect) {
            firstSelect.addEventListener('change', function () { loadHouseholdSuggestions(form); });
        }

        form.querySelectorAll('select[name^="member_id"]').forEach(function (select) {
            wireMemberSelect(select, form);
        });

        var addRowBtn = form.querySelector('.avbk-add-member-row');
        var rowTemplate = form.querySelector('.avbk-member-row-template');
        if (addRowBtn && rowTemplate) {
            addRowBtn.addEventListener('click', function () {
                var table = form.querySelector('.avbk-review-split');
                var tbody = table.querySelector('tbody') || table;
                var row = rowTemplate.content.firstElementChild.cloneNode(true);
                tbody.appendChild(row);
                var select = row.querySelector('select[name^="member_id"]');
                if (select) wireMemberSelect(select, form);
            });
        }

        var typeDropdown = form.querySelector('.avbk-type-dropdown');
        var extraLines = form.querySelector('.avbk-extra-lines');
        var extraTemplate = form.querySelector('.avbk-extra-line-template');
        if (typeDropdown) {
            typeDropdown.querySelectorAll('.avbk-type-checkbox').forEach(function (checkbox) {
                checkbox.addEventListener('change', function () {
                    updateTypeSummary(typeDropdown);
                    if (checkbox.dataset.kind !== 'extra' || !extraLines || !extraTemplate) return;

                    var category = checkbox.dataset.category;
                    if (checkbox.checked) {
                        var line = extraTemplate.content.firstElementChild.cloneNode(true);
                        line.dataset.category = category;
                        line.querySelector('.avbk-extra-line-label').textContent = category;
                        line.querySelector('.avbk-extra-line-category-input').value = category;
                        if (checkbox.dataset.description) {
                            line.querySelector('.avbk-extra-line-description').value = checkbox.dataset.description;
                        }
                        extraLines.appendChild(line);
                    } else {
                        var existing = extraLines.querySelector('.avbk-extra-line[data-category="' + category + '"]');
                        if (existing) existing.remove();
                    }
                });
            });
        }
    });
});
