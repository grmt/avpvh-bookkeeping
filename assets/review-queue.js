document.addEventListener('DOMContentLoaded', function () {
    var configEl = document.getElementById('avbk-review-config');
    if (!configEl) return;
    var cfg = JSON.parse(configEl.textContent);

    // Every form on this page is a full-page admin-post submit (recompute,
    // confirm, opslaan, negeren, concept wissen) — without this, the
    // clicked button just sits there looking clickable for as long as the
    // request takes, inviting a second, wasted click before the page
    // navigates away. e.submitter is whichever button was actually clicked
    // (this page has two on one form — Opslaan vs. Bevestigen — so the
    // first-found submit button isn't necessarily the right one).
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var btn = e.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
            if (!btn || btn.disabled) return;
            // Disabling the clicked button synchronously here can make the
            // browser drop its name=value pair from the submitted form data
            // (disabled controls aren't submitted) — and since the action
            // this page dispatches to comes from *that* button, not a
            // hidden field, that silently empties $_POST['action'] server-
            // side. Deferring to the next tick lets the browser finish
            // reading the form before we disable anything.
            setTimeout(function () {
                btn.disabled = true;
                if (btn.tagName === 'BUTTON') btn.textContent = 'Bezig...';
            }, 0);
        });
    });

    // Once the first (payer) row on a transaction has a member selected,
    // pre-suggest their household/family as an option group at the top of
    // every still-blank row below it — the overwhelmingly likely candidates
    // for the rest of a multi-person payment, and much faster to pick from
    // than the full member list.
    function applyHouseholdSuggestions(form, selects, candidates) {
        selects.forEach(function (select) {
            // A selected family member can currently live inside the
            // suggested optgroup itself. Removing that group makes the
            // browser immediately fall back to the first regular option
            // (usually the originally suggested payer), so remember the
            // treasurer's choice before rebuilding and restore it after.
            var selectedValue = select.value;
            var existing = select.querySelector('optgroup[data-avbk-suggested]');
            if (existing) existing.remove();
            if (!candidates.length) {
                select.value = selectedValue;
                return;
            }

            var group = document.createElement('optgroup');
            group.label = 'Suggesties (familie/huisgenoten)';
            group.setAttribute('data-avbk-suggested', '1');
            candidates.forEach(function (c) {
                if (String(c.id) === String(select.value)) return;
                var opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.label;
                group.appendChild(opt);
            });
            select.insertBefore(group, select.firstChild);
            select.value = selectedValue;
        });
    }

    var householdCache = {};

    function loadHouseholdSuggestions(form, memberId) {
        var selects = Array.from(form.querySelectorAll('select[name="member_id[]"]'));
        var first = selects[0];
        memberId = memberId || (first && first.value);
        if (!memberId) return Promise.resolve([]);
        if (householdCache[memberId]) {
            applyHouseholdSuggestions(form, selects, householdCache[memberId]);
            return Promise.resolve(householdCache[memberId]);
        }

        var body = new URLSearchParams();
        body.set('action', 'avbk_household_candidates');
        body.set('nonce', cfg.nonce);
        body.set('member_id', memberId);

        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) return [];
                householdCache[memberId] = res.data;
                applyHouseholdSuggestions(form, selects, res.data);
                return res.data;
            });
    }

    function parseAmount(value) {
        var n = parseFloat(String(value).replace(',', '.'));
        return isNaN(n) ? 0 : n;
    }

    function updateRowShortfall(input) {
        var row = input.closest('tr');
        var shortfallEl = row && row.querySelector('.avbk-detail-shortfall');
        if (!shortfallEl) return;
        var openAmount = parseAmount(input.dataset.openAmount || '0');
        var shortfall = Math.round((openAmount - parseAmount(input.value)) * 100) / 100;
        shortfallEl.textContent = shortfall > 0.005
            ? '⚠ Gedeeltelijke betaling: € ' + shortfall.toFixed(2).replace('.', ',') + ' blijft voor deze bijdrage open.'
            : '';
    }

    // Sums every regel-bedrag on this transaction and checks it against the
    // transaction's own amount — a payment that doesn't add up (a row left
    // at its old suggested share after the treasurer corrected another, a
    // typo'd bedrag) would otherwise only surface later as a mysteriously
    // wrong balance.
    function updateTotals(form) {
        var sumEl = form.querySelector('.avbk-review-total-sum');
        var diffEl = form.querySelector('.avbk-review-total-diff');
        if (!sumEl || !diffEl) return;

        var total = 0;
        form.querySelectorAll('input[name="amount[]"]').forEach(function (input) {
            total += parseAmount(input.value);
            updateRowShortfall(input);
        });
        total = Math.round(total * 100) / 100;
        sumEl.textContent = '€ ' + total.toFixed(2).replace('.', ',');

        var txAmount = parseAmount(form.dataset.txAmount);
        var diff = Math.round((txAmount - total) * 100) / 100;
        if (Math.abs(diff) < 0.005) {
            diffEl.textContent = '';
            diffEl.classList.remove('avbk-diff-mismatch');
        } else {
            var diffAbs = Math.abs(diff).toFixed(2).replace('.', ',');
            var txText = txAmount.toFixed(2).replace('.', ',');
            var assignedText = total.toFixed(2).replace('.', ',');
            diffEl.textContent = diff > 0
                ? '— nog € ' + diffAbs + ' van de ontvangen € ' + txText + ' moet worden toegewezen'
                : '— ontvangen € ' + txText + '; geselecteerde openstaande bijdragen € ' + assignedText
                    + ' — € ' + diffAbs + ' minder ontvangen';
            diffEl.classList.add('avbk-diff-mismatch');
        }
    }

    // A row's activity value is either "a<id>" — a specific, dated
    // activiteit the treasurer picked, matched unambiguously against that
    // activiteit's own open bijdrage-regel — or a bare type name (Drank,
    // Overig, ...) that isn't tied to any dated activiteit and creates a
    // brand new one-off regel instead. See admin/review-queue.php's
    // avbk_activity_select() for where these values come from.
    function matchedActivityId(value) {
        var m = /^a(\d+)$/.exec(value);
        return m ? m[1] : null;
    }

    // A loose category such as Drank has no registered activity/rate from
    // which an amount can be calculated. The most useful default is the
    // exact part of this bank payment that the other rows have not already
    // consumed. Excluding this row's previous value also makes switching
    // Drank -> Eten idempotent instead of subtracting the old value twice.
    function fillRemainingAmount(row, form) {
        var amountInput = row.querySelector('.avbk-amount-input');
        if (!amountInput) return;

        var assignedElsewhere = 0;
        form.querySelectorAll('input[name="amount[]"]').forEach(function (input) {
            if (input !== amountInput) assignedElsewhere += parseAmount(input.value);
        });
        var remaining = Math.max(0, Math.round((parseAmount(form.dataset.txAmount) - assignedElsewhere) * 100) / 100);
        amountInput.value = remaining.toFixed(2).replace('.', ',');
        amountInput.dataset.known = '0';
        updateTotals(form);
    }

    // Wires one regel's lid- and activiteit-dropdowns: toggling the
    // optional omschrijving field's visibility (only relevant for a losse
    // kostenpost, not a matched activiteit), auto-filling "Overig"'s
    // omschrijving from the raw bank-omschrijving, keeping the "bewerk
    // lid"-link next to the lid-dropdown pointed at whoever is currently
    // selected, and — for a matched activiteit — a live AJAX-lookup of the
    // member's actual open bedrag for that one specific activiteit.
    // Extracted so it applies both to rows rendered by PHP at page load and
    // to blank rows cloned client-side via "+ voeg regel toe".
    function wireRow(row, form) {
        var memberSelect = row.querySelector('select[name="member_id[]"]');
        var activitySelect = row.querySelector('select[name="activity[]"]');
        var descriptionInput = row.querySelector('.avbk-row-description');
        var memberLink = row.querySelector('.avbk-detail-member-link');
        if (!memberSelect || !activitySelect) return;

        function updateDescriptionVisibility() {
            var isMatchedActivity = !!matchedActivityId(activitySelect.value);
            if (descriptionInput) {
                descriptionInput.style.display = isMatchedActivity ? 'none' : '';
                if (!isMatchedActivity && activitySelect.value === 'Overig' && !descriptionInput.value) {
                    descriptionInput.value = form.dataset.txDescription || '';
                }
            }
        }

        function updateMemberEditLink() {
            if (memberSelect.value) {
                if (memberLink) {
                    memberLink.href = cfg.memberDetailUrl + encodeURIComponent(memberSelect.value);
                    memberLink.style.display = '';
                }
            } else {
                if (memberLink) memberLink.style.display = 'none';
            }
        }

        function lookupDetail() {
            var fragmentsEl = row.querySelector('.avbk-detail-fragments');
            var estimatedEl = row.querySelector('.avbk-detail-estimated');
            var amountInput = row.querySelector('.avbk-amount-input');

            // Clear stale detail immediately — showing the *previous*
            // person's/activiteit's age/nights would otherwise be actively
            // misleading, even briefly.
            if (fragmentsEl) fragmentsEl.innerHTML = '';
            if (estimatedEl) estimatedEl.textContent = '';
            if (amountInput) amountInput.dataset.openAmount = '';

            // No matched activiteit (Weekend, Drank, Overig, ...) means no
            // tarief to compute a bedrag from, but the endpoint still
            // returns the member's scholier/student status for activity_id
            // 0 — worth the round-trip even then, see
            // AVBK_DB::get_member_status_detail().
            var activityId = matchedActivityId(activitySelect.value);
            if (!memberSelect.value) return;

            var body = new URLSearchParams();
            body.set('action', 'avbk_member_fee_detail');
            body.set('nonce', cfg.nonce);
            body.set('member_id', memberSelect.value);
            body.set('activity_id', activityId || '0');

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
                    if (estimatedEl) {
                        estimatedEl.textContent = d.estimated_text || '';
                        estimatedEl.classList.toggle('avbk-detail-estimated-warning', !!d.estimated_warning);
                    }
                    if (amountInput) {
                        amountInput.dataset.known = d.found ? '1' : '0';
                        amountInput.dataset.openAmount = d.found ? d.share.toFixed(2) : '';
                        if (d.found) {
                            amountInput.value = d.share.toFixed(2).replace('.', ',');
                        }
                    }
                    updateTotals(form); // amountInput.value was set programmatically, no native 'input' event fires
                });
        }

        memberSelect.addEventListener('change', function () {
            loadHouseholdSuggestions(form, memberSelect.value);
            updateMemberEditLink();
            lookupDetail();
        });
        activitySelect.addEventListener('change', function () {
            updateDescriptionVisibility();
            if (activitySelect.value && !matchedActivityId(activitySelect.value)) {
                fillRemainingAmount(row, form);
            }
            lookupDetail();
        });
        updateDescriptionVisibility();
        updateMemberEditLink();
    }

    // A guessed/spurious regel (e.g. "Weekend" matched from the bank
    // omschrijving alongside "Drank", but with no dated activiteit to back
    // it up) shouldn't just vanish when removed — its bedrag was part of
    // the transaction's own total, so it gets divided over the remaining
    // regels instead of leaving a gap the treasurer has to re-type by hand.
    // The redistribution starts fresh from the transaction's own amount
    // (not from whatever the removed regel happened to hold) minus every
    // *known* regel — one with a real matched bijdrage-regel behind it,
    // amountInput.dataset.known === '1' (set server-side on render and
    // client-side by lookupDetail()) — so an already-correct matched
    // bedrag is never nudged by a later, unrelated removal; only the
    // still-guessed regels absorb the remainder.
    function wireRemoveButton(row, form) {
        var removeBtn = row.querySelector('.avbk-remove-row');
        if (!removeBtn) return;
        removeBtn.addEventListener('click', function () {
            var table = form.querySelector('.avbk-review-split');
            row.remove();

            var remainingInputs = Array.from(table.querySelectorAll('input[name="amount[]"]'));
            var knownSum = 0;
            var unknownInputs = [];
            remainingInputs.forEach(function (input) {
                if (input.dataset.known === '1') {
                    knownSum += parseAmount(input.value);
                } else {
                    unknownInputs.push(input);
                }
            });
            if (unknownInputs.length) {
                var remaining = Math.round((parseAmount(form.dataset.txAmount) - knownSum) * 100) / 100;
                var share = Math.round((remaining / unknownInputs.length) * 100) / 100;
                var distributed = 0;
                unknownInputs.forEach(function (input, idx) {
                    var isLast = idx === unknownInputs.length - 1;
                    var value = isLast ? Math.round((remaining - distributed) * 100) / 100 : share;
                    distributed += value;
                    input.value = value.toFixed(2).replace('.', ',');
                });
            }
            updateTotals(form);
        });
    }

    var householdObserver = window.IntersectionObserver ? new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            var form = entry.target;
            loadHouseholdSuggestions(form);
            householdObserver.unobserve(form);
        });
    }, { rootMargin: '250px 0px' }) : null;

    document.querySelectorAll('.avbk-review-form').forEach(function (form) {
        form.querySelectorAll('.avbk-review-split tr').forEach(function (row) {
            wireRow(row, form);
            wireRemoveButton(row, form);
        });

        var addRowBtn = form.querySelector('.avbk-add-row');
        var rowTemplate = form.querySelector('.avbk-row-template');
        if (addRowBtn && rowTemplate) {
            addRowBtn.addEventListener('click', function () {
                var table = form.querySelector('.avbk-review-split');
                var tbody = table.querySelector('tbody') || table;
                var row = rowTemplate.content.firstElementChild.cloneNode(true);
                tbody.appendChild(row);
                wireRow(row, form);
                wireRemoveButton(row, form);
                // The new row is blank, so it's exactly the case
                // applyHouseholdSuggestions() targets.
                loadHouseholdSuggestions(form);
            });
        }

        form.addEventListener('input', function (e) {
            if (e.target.matches('input[name="amount[]"]')) {
                updateTotals(form);
            }
        });
        updateTotals(form);
        if (householdObserver) householdObserver.observe(form);
    });
});
