document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('avbk-fee-popup');
    var configEl = document.getElementById('avbk-fee-popup-config');
    var dismissBtn = document.getElementById('avbk-fee-dismiss');
    if (!overlay || !configEl || !dismissBtn) return;

    var cfg = JSON.parse(configEl.textContent);

    dismissBtn.addEventListener('click', function () {
        var body = new URLSearchParams();
        body.set('action', 'avbk_dismiss_popup');
        body.set('nonce', cfg.nonce);

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        }).finally(function () {
            var expires = new Date();
            expires.setDate(expires.getDate() + 7);
            document.cookie = 'avbk_popup_dismissed=1; expires=' + expires.toUTCString() + '; path=/; SameSite=Lax';
            overlay.style.display = 'none';
        });
    });
});
