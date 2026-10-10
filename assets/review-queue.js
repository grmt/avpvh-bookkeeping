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
    // pre-suggest their household/family at the top of the lid-combobox's
    // dropdown for every still-blank row below it — the overwhelmingly
    // likely candidates for the rest of a multi-person payment, and much
    // faster to pick from than the full member list.
    function applyHouseholdSuggestions(form, hiddenInputs, candidates) {
        hiddenInputs.forEach(function (hidden) {
            var wrapper = hidden.closest('.avbk-member-combo');
            if (wrapper) wrapper._householdSuggestions = candidates;
        });
    }

    var householdCache = {};

    // Same idea as the household-suggestions above, but scoped to the
    // row's own (already-guessed) activiteit instead of the payer's
    // household — the lid-combobox otherwise lists every payable lid
    // (which also excludes ex-leden, see AVBK_DB::get_payable_members()),
    // making the actual attendee of e.g. a 100-person reünie tedious to
    // find by hand.
    var activityParticipantsCache = {};

    function applyActivityParticipants(hiddenInput, candidates) {
        var wrapper = hiddenInput.closest('.avbk-member-combo');
        if (wrapper) wrapper._activitySuggestions = candidates;
    }

    function loadActivityParticipants(hiddenInput, activityId) {
        if (!activityId) {
            applyActivityParticipants(hiddenInput, []);
            return;
        }
        if (activityParticipantsCache[activityId]) {
            applyActivityParticipants(hiddenInput, activityParticipantsCache[activityId]);
            return;
        }
        var body = new URLSearchParams();
        body.set('action', 'avbk_activity_participants');
        body.set('nonce', cfg.nonce);
        body.set('activity_id', activityId);
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) return;
                activityParticipantsCache[activityId] = res.data;
                applyActivityParticipants(hiddenInput, res.data);
            });
    }

    function loadHouseholdSuggestions(form, memberId) {
        var hiddenInputs = Array.from(form.querySelectorAll('input[name="member_id[]"]'));
        var first = hiddenInputs[0];
        memberId = memberId || (first && first.value);
        if (!memberId) return Promise.resolve([]);
        if (householdCache[memberId]) {
            applyHouseholdSuggestions(form, hiddenInputs, householdCache[memberId]);
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
                applyHouseholdSuggestions(form, hiddenInputs, res.data);
                return res.data;
            });
    }

    // The lid-field is a small combobox, not a native <select>: typing
    // filters cfg.allMembers (matching either voornaam or achternaam) into
    // a clickable/keyboard-navigable dropdown list below the text input,
    // with any household/activiteit suggestions (set via
    // applyHouseholdSuggestions()/applyActivityParticipants() above,
    // stashed directly on the wrapper element) pinned above it. A plain
    // native <select> can't do this — typing into one only jumps to the
    // next option starting with that letter, it doesn't narrow the list.
    function wireMemberCombo(row) {
        var wrapper = row.querySelector('.avbk-member-combo');
        if (!wrapper) return;
        var hidden = wrapper.querySelector('.avbk-member-combo-value');
        var input = wrapper.querySelector('.avbk-member-combo-input');
        var list = wrapper.querySelector('.avbk-member-combo-list');
        if (!hidden || !input || !list) return;

        var activeIndex = -1;
        var renderedItems = [];

        function labelFor(id) {
            var m = cfg.allMembers.filter(function (x) { return String(x.id) === String(id); })[0];
            return m ? m.label : '';
        }

        function selectMember(id, label) {
            hidden.value = id;
            input.value = label;
            closeList();
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function closeList() {
            list.hidden = true;
            activeIndex = -1;
        }

        function setActive(index) {
            var children = Array.prototype.slice.call(list.querySelectorAll('.avbk-member-combo-item'));
            children.forEach(function (el, i) {
                el.classList.toggle('is-active', i === index);
            });
            if (children[index]) children[index].scrollIntoView({ block: 'nearest' });
            activeIndex = index;
        }

        function render(forceEmptyTerm) {
            var term = forceEmptyTerm ? '' : input.value.trim().toLowerCase();
            var seen = {};
            renderedItems = [];
            list.innerHTML = '';

            function addGroup(heading, candidates) {
                var filtered = (candidates || []).filter(function (c) {
                    if (String(c.id) === String(hidden.value) || seen[c.id]) return false;
                    return term === '' || c.label.toLowerCase().indexOf(term) !== -1;
                });
                if (!filtered.length) return;
                var h = document.createElement('div');
                h.className = 'avbk-member-combo-heading';
                h.textContent = heading;
                list.appendChild(h);
                filtered.forEach(function (c) {
                    seen[c.id] = true;
                    appendItem(c.id, c.label, !!c.paid);
                });
            }

            function appendItem(id, label, isPaid) {
                var item = document.createElement('div');
                item.className = 'avbk-member-combo-item' + (isPaid ? ' avbk-member-combo-item-paid' : '');
                item.textContent = label + (isPaid ? ' (al betaald)' : '');
                item.dataset.id = id;
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault(); // keep focus so the subsequent blur doesn't close the list first
                    selectMember(id, label);
                });
                list.appendChild(item);
                renderedItems.push(item);
            }

            addGroup('Deelnemers van deze activiteit', wrapper._activitySuggestions);
            addGroup('Suggesties (familie/huisgenoten)', wrapper._householdSuggestions);

            var rest = cfg.allMembers.filter(function (m) {
                if (seen[String(m.id)] || String(m.id) === String(hidden.value)) return false;
                if (term === '') return true;
                return (m.first || '').toLowerCase().indexOf(term) !== -1
                    || (m.last || '').toLowerCase().indexOf(term) !== -1;
            });
            if (rest.length) {
                if (renderedItems.length) {
                    var h = document.createElement('div');
                    h.className = 'avbk-member-combo-heading';
                    h.textContent = 'Alle leden';
                    list.appendChild(h);
                }
                rest.forEach(function (m) { appendItem(m.id, m.label); });
            }
            list.hidden = renderedItems.length === 0;
            activeIndex = -1;
        }

        input.addEventListener('input', function () { render(false); });
        input.addEventListener('focus', function () {
            // The field shows the current selection's label, not an empty
            // search box — select it so the first keystroke replaces it
            // instead of editing into the middle of "Hulst, Guy (van)",
            // and open with the full/suggested list (that old label isn't
            // something the treasurer actually typed, so it must not be
            // used as a filter term) instead of a dropdown that looks
            // empty or wrong until the first real keystroke.
            input.select();
            render(true);
        });
        input.addEventListener('keydown', function (e) {
            if (list.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                render();
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(Math.min(activeIndex + 1, renderedItems.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(Math.max(activeIndex - 1, 0));
            } else if (e.key === 'Enter') {
                if (!list.hidden && activeIndex >= 0 && renderedItems[activeIndex]) {
                    e.preventDefault();
                    var item = renderedItems[activeIndex];
                    selectMember(item.dataset.id, item.textContent);
                }
            } else if (e.key === 'Escape') {
                closeList();
            }
        });

        // A blur without an actual pick (clicked elsewhere mid-typing)
        // shouldn't leave the visible text out of sync with the hidden
        // value — revert to whatever is actually selected.
        input.addEventListener('blur', function () {
            setTimeout(function () {
                closeList();
                input.value = hidden.value ? labelFor(hidden.value) : '';
            }, 150);
        });
    }

    // Same combobox idea as wireMemberCombo() above, simplified: a flat
    // filtered list (no suggestion groups, no voornaam/achternaam
    // toggle) over cfg.duplicateCandidates — the transaction's own id
    // (data-exclude-id) never lists itself as its own "original".
    function wireDuplicateCombo(wrapper) {
        var hidden = wrapper.querySelector('.avbk-duplicate-combo-value');
        var input = wrapper.querySelector('.avbk-duplicate-combo-input');
        var list = wrapper.querySelector('.avbk-duplicate-combo-list');
        var excludeId = wrapper.dataset.excludeId;
        if (!hidden || !input || !list) return;

        var activeIndex = -1;
        var renderedItems = [];

        function labelFor(id) {
            var c = cfg.duplicateCandidates.filter(function (x) { return String(x.id) === String(id); })[0];
            return c ? c.label : '';
        }

        function closeList() {
            list.hidden = true;
            activeIndex = -1;
        }

        function selectCandidate(id, label) {
            hidden.value = id;
            input.value = label;
            closeList();
        }

        function setActive(index) {
            var children = Array.prototype.slice.call(list.querySelectorAll('.avbk-duplicate-combo-item'));
            children.forEach(function (el, i) {
                el.classList.toggle('is-active', i === index);
            });
            if (children[index]) children[index].scrollIntoView({ block: 'nearest' });
            activeIndex = index;
        }

        function render(forceEmptyTerm) {
            var term = forceEmptyTerm ? '' : input.value.trim().toLowerCase();
            list.innerHTML = '';
            renderedItems = [];
            cfg.duplicateCandidates.forEach(function (c) {
                if (String(c.id) === String(excludeId)) return;
                if (term !== '' && c.label.toLowerCase().indexOf(term) === -1) return;
                var item = document.createElement('div');
                item.className = 'avbk-duplicate-combo-item';
                item.textContent = c.label;
                item.dataset.id = c.id;
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    selectCandidate(c.id, c.label);
                });
                list.appendChild(item);
                renderedItems.push(item);
            });
            list.hidden = renderedItems.length === 0;
            activeIndex = -1;
        }

        input.addEventListener('input', function () { render(false); });
        input.addEventListener('focus', function () {
            input.select();
            render(true);
        });
        input.addEventListener('keydown', function (e) {
            if (list.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                render();
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(Math.min(activeIndex + 1, renderedItems.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(Math.max(activeIndex - 1, 0));
            } else if (e.key === 'Enter') {
                if (!list.hidden && activeIndex >= 0 && renderedItems[activeIndex]) {
                    e.preventDefault();
                    var item = renderedItems[activeIndex];
                    selectCandidate(item.dataset.id, item.textContent);
                }
            } else if (e.key === 'Escape') {
                closeList();
            }
        });
        input.addEventListener('blur', function () {
            setTimeout(function () {
                closeList();
                input.value = hidden.value ? labelFor(hidden.value) : '';
            }, 150);
        });

        wrapper.closest('form').addEventListener('submit', function (e) {
            if (!hidden.value) {
                e.preventDefault();
                input.focus();
            }
        });
    }

    // A flat snapshot of everything a draft-save would actually write —
    // used only to tell whether anything changed since the page loaded
    // (or since the last save), so "Opslaan" doesn't invite a no-op click.
    function serializeRows(form) {
        return Array.prototype.map.call(form.querySelectorAll('.avbk-review-split tr'), function (row) {
            var member = row.querySelector('input[name="member_id[]"]');
            var activity = row.querySelector('select[name="activity[]"]');
            var description = row.querySelector('input[name="description[]"]');
            var amount = row.querySelector('input[name="amount[]"]');
            return [
                member ? member.value : '',
                activity ? activity.value : '',
                description ? description.value : '',
                amount ? amount.value : '',
            ].join('|');
        }).join(';;');
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

        // "Markeer rest als schenking" only makes sense for a genuine
        // remainder still to be assigned (diff > 0) — an entered total
        // that already exceeds the bank amount (diff < 0) is a typo to
        // fix, not something to file as a donation. Once that's done
        // (diff back to 0), the button itself has nothing left to do and
        // hides, but the "stuur een mail hierover" checkbox stays —
        // still bound live to the schenking row it created (see
        // wireDonationEmailToggle() below), so toggling it right up
        // until Bevestigen still has an effect.
        var donationEl = form.querySelector('.avbk-review-donation');
        var donationBtn = form.querySelector('.avbk-donation-btn');
        var hasDonationRow = !!form.querySelector('[data-avbk-donation-row]');
        if (donationEl) {
            donationEl.hidden = diff <= 0.005 && !hasDonationRow;
            donationEl.dataset.remaining = diff.toFixed(2);
        }
        if (donationBtn) {
            donationBtn.hidden = diff <= 0.005 || hasDonationRow;
        }

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
        var memberSelect = row.querySelector('input[name="member_id[]"]');
        var activitySelect = row.querySelector('select[name="activity[]"]');
        var descriptionInput = row.querySelector('.avbk-row-description');
        var memberLink = row.querySelector('.avbk-detail-member-link');
        var memberBalanceLink = row.querySelector('.avbk-detail-member-balance-link');
        if (!memberSelect || !activitySelect) return;

        function updateDescriptionVisibility() {
            var isMatchedActivity = !!matchedActivityId(activitySelect.value) || /^f\d+$/.test(activitySelect.value);
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
                if (memberBalanceLink) {
                    memberBalanceLink.href = cfg.memberBalanceUrl + encodeURIComponent(memberSelect.value);
                    memberBalanceLink.style.display = '';
                }
            } else {
                if (memberLink) memberLink.style.display = 'none';
                if (memberBalanceLink) memberBalanceLink.style.display = 'none';
            }
        }

        function lookupDetail() {
            // f<id> is one exact existing fee, not a guessed activity.
            // Preserve its rendered balance and any manually entered partial
            // amount; a generic activity lookup could overwrite that choice.
            if (/^f\d+$/.test(activitySelect.value)) return;
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
            if (activitySelect.value && !matchedActivityId(activitySelect.value) && !/^f\d+$/.test(activitySelect.value)) {
                fillRemainingAmount(row, form);
            }
            loadActivityParticipants(memberSelect, matchedActivityId(activitySelect.value));
            lookupDetail();
        });
        updateDescriptionVisibility();
        updateMemberEditLink();
        loadActivityParticipants(memberSelect, matchedActivityId(activitySelect.value));
        wireMemberCombo(row);
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
            form.dispatchEvent(new Event('change'));
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

        // "Opslaan" writes a draft — pointless (and a little misleading,
        // as if something just happened) to leave clickable when nothing
        // about the rows actually differs from what's already there,
        // whether that's the saved draft or the as-rendered suggestion.
        var saveBtn = form.querySelector('button[value="avbk_save_transaction_draft"]');
        var savedState = serializeRows(form);
        function refreshSaveState() {
            if (saveBtn) saveBtn.disabled = serializeRows(form) === savedState;
        }
        refreshSaveState();

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
                refreshSaveState();
            });
        }

        var donationBtn = form.querySelector('.avbk-donation-btn');
        var donationEmailToggle = form.querySelector('.avbk-donation-email-toggle');
        if (donationBtn && rowTemplate) {
            donationBtn.addEventListener('click', function () {
                var donationEl = form.querySelector('.avbk-review-donation');
                var remaining = parseAmount(donationEl ? donationEl.dataset.remaining : '0');
                if (remaining <= 0) return;

                var table = form.querySelector('.avbk-review-split');
                var tbody = table.querySelector('tbody') || table;
                var row = rowTemplate.content.firstElementChild.cloneNode(true);
                row.setAttribute('data-avbk-donation-row', '1');
                tbody.appendChild(row);
                wireRow(row, form);
                wireRemoveButton(row, form);

                // Pre-fill lid only when every other row already agrees on
                // who's paying — a split payment with several different
                // people has no single obvious "who overpaid", so that's
                // left for the treasurer to pick by hand.
                var otherMemberIds = Array.prototype.map.call(
                    form.querySelectorAll('input[name="member_id[]"]'),
                    function (el) { return el.value; }
                ).filter(function (v, i, arr) { return v && arr.indexOf(v) === i; });
                var memberHidden = row.querySelector('.avbk-member-combo-value');
                var memberInput = row.querySelector('.avbk-member-combo-input');
                if (otherMemberIds.length === 1 && memberHidden && memberInput) {
                    var m = cfg.allMembers.filter(function (x) { return String(x.id) === otherMemberIds[0]; })[0];
                    if (m) {
                        memberHidden.value = m.id;
                        memberInput.value = m.label;
                    }
                }

                var activitySelect = row.querySelector('select[name="activity[]"]');
                if (activitySelect) {
                    activitySelect.value = 'Anders';
                    activitySelect.dispatchEvent(new Event('change', { bubbles: true }));
                }
                var descriptionInput = row.querySelector('.avbk-row-description');
                if (descriptionInput) descriptionInput.value = 'Schenking';
                var amountInput = row.querySelector('.avbk-amount-input');
                if (amountInput) amountInput.value = remaining.toFixed(2).replace('.', ',');
                var donationFlag = row.querySelector('.avbk-donation-email-flag');
                if (donationFlag) donationFlag.value = (donationEmailToggle && donationEmailToggle.checked) ? '1' : '';

                updateTotals(form);
                refreshSaveState();
            });
        }

        // The checkbox stays usable after the schenking row is created
        // (updateTotals() keeps it visible — see above) — keep it bound
        // to that row's own hidden flag so toggling it right up until
        // Bevestigen still changes whether the e-mail actually goes out,
        // instead of freezing whatever it happened to be at click time.
        if (donationEmailToggle) {
            donationEmailToggle.addEventListener('change', function () {
                var donationRow = form.querySelector('[data-avbk-donation-row]');
                var donationFlag = donationRow && donationRow.querySelector('.avbk-donation-email-flag');
                if (donationFlag) donationFlag.value = donationEmailToggle.checked ? '1' : '';
                donationEmailToggle.closest('.avbk-donation-email-label').classList.toggle('is-set', donationEmailToggle.checked);
            });
        }

        form.addEventListener('input', function (e) {
            if (e.target.matches('input[name="amount[]"]')) {
                updateTotals(form);
            }
            refreshSaveState();
        });
        form.addEventListener('change', refreshSaveState);
        updateTotals(form);
        if (householdObserver) householdObserver.observe(form);
    });

    document.querySelectorAll('.avbk-duplicate-combo').forEach(wireDuplicateCombo);
});
