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
        var selects = Array.from(form.querySelectorAll('select[name="member_id[]"]'));
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

    function parseAmount(value) {
        var n = parseFloat(String(value).replace(',', '.'));
        return isNaN(n) ? 0 : n;
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
        });
        total = Math.round(total * 100) / 100;
        sumEl.textContent = '€ ' + total.toFixed(2).replace('.', ',');

        var txAmount = parseAmount(form.dataset.txAmount);
        var diff = Math.round((txAmount - total) * 100) / 100;
        if (Math.abs(diff) < 0.005) {
            diffEl.textContent = '';
            diffEl.classList.remove('avbk-diff-mismatch');
        } else {
            // Framed from the payment's own perspective, not the
            // allocation's — "de betaling is te weinig/te veel", not
            // "toegewezen is te veel/weinig" — since it's the treasurer's
            // row edits being checked against a fixed, already-known
            // payment amount, not the other way round.
            var diffAbs = Math.abs(diff).toFixed(2).replace('.', ',');
            diffEl.textContent = diff > 0
                ? '— de betaling is € ' + diffAbs + ' te veel (nog niet alles toegewezen)'
                : '— de betaling is € ' + diffAbs + ' te weinig (meer toegewezen dan ontvangen)';
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
            if (!memberLink) return;
            if (memberSelect.value) {
                memberLink.href = cfg.memberProfileUrl + '?member_id=' + encodeURIComponent(memberSelect.value);
                memberLink.style.display = '';
            } else {
                memberLink.style.display = 'none';
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

            var activityId = matchedActivityId(activitySelect.value);
            if (!memberSelect.value || !activityId) return;

            var body = new URLSearchParams();
            body.set('action', 'avbk_member_fee_detail');
            body.set('nonce', cfg.nonce);
            body.set('member_id', memberSelect.value);
            body.set('activity_id', activityId);

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
                    if (amountInput && d.found) {
                        amountInput.value = d.share.toFixed(2).replace('.', ',');
                    }
                    updateTotals(form); // amountInput.value was set programmatically, no native 'input' event fires
                });
        }

        memberSelect.addEventListener('change', function () {
            loadHouseholdSuggestions(form);
            updateMemberEditLink();
            lookupDetail();
        });
        activitySelect.addEventListener('change', function () {
            updateDescriptionVisibility();
            lookupDetail();
        });
        updateDescriptionVisibility();
        updateMemberEditLink();
    }

    document.querySelectorAll('.avbk-review-form').forEach(function (form) {
        loadHouseholdSuggestions(form); // rows often already arrive pre-filled with a suggested payer

        form.querySelectorAll('.avbk-review-split tr').forEach(function (row) {
            wireRow(row, form);
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
    });
});
