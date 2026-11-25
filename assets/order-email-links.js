/** Shared text and account-link formatting for order email checks. */
(function () {
    function text(value) {
        const entities = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        return String(value || '').replace(/[&<>"']/g, char => entities[char]);
    }
    function href(value, authOrigin) {
        const fallback = '/avpvh-login/';
        try {
            const url = new URL(value || fallback, window.location.origin);
            const auth = new URL(authOrigin);
            const local = url.origin === window.location.origin && url.protocol === window.location.protocol;
            const account = url.protocol === 'https:' && auth.protocol === 'https:' && url.origin === auth.origin;
            if (!url.username && !url.password && (local || account)) return text(url.href);
        } catch (error) {
            return fallback;
        }
        return fallback;
    }
    window.AVBKOrderEmailLinks = Object.freeze({ text, href });
})();
