document.addEventListener('DOMContentLoaded', function () {
    var configEl = document.getElementById('avbk-review-config');
    if (!configEl) return;
    var cfg = JSON.parse(configEl.textContent);

    document.querySelectorAll('.avbk-review-form').forEach(function (form) {
        form.querySelectorAll('select[name^="member_id"]').forEach(function (select) {
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
        });
    });
});
