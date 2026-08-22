document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('avbk-fee-popup');
    var configEl = document.getElementById('avbk-fee-popup-config');
    var dismissBtn = document.getElementById('avbk-fee-dismiss');
    var detailLink = document.getElementById('avbk-fee-detail-link');
    if (!overlay || !configEl || !dismissBtn) return;

    var cfg = JSON.parse(configEl.textContent);

    function dismiss() {
        var body = new URLSearchParams();
        body.set('action', 'avbk_dismiss_popup');
        body.set('nonce', cfg.nonce);

        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        }).finally(function () {
            var expires = new Date();
            expires.setDate(expires.getDate() + 7);
            document.cookie = 'avbk_popup_dismissed=1; expires=' + expires.toUTCString() + '; path=/; SameSite=Lax';
            overlay.style.display = 'none';
        });
    }

    dismissBtn.addEventListener('click', dismiss);

    if (detailLink) {
        detailLink.addEventListener('click', function (e) {
            e.preventDefault();
            var href = detailLink.href;
            dismiss().finally(function () {
                window.location.href = href;
            });
        });
    }
});
