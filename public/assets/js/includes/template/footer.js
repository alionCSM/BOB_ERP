    // Analytics heartbeat disabled - sendBeacon cannot include auth headers
    // causing 401 errors. Remove this comment block if you add auth to heartbeat.

(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    if (!csrfToken) return;

    // 1. Attach to all fetch() POST calls
    const _origFetch = window.fetch;
    window.fetch = function (url, opts) {
        opts = opts || {};
        if (opts.method && opts.method.toUpperCase() === 'POST') {
            opts.headers = Object.assign({}, opts.headers, {'X-CSRF-Token': csrfToken});
        }
        return _origFetch.call(this, url, opts);
    };

    // 2. Inject hidden field into every HTML form on page load
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form').forEach(function (form) {
            const method = (form.getAttribute('method') || 'get').toUpperCase();
            if (method === 'POST' && !form.querySelector('[name="_csrf"]')) {
                const input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = '_csrf';
                input.value = csrfToken;
                form.appendChild(input);
            }
        });
    });
})();

// 3. data-confirm: show native confirm dialog before any form with data-confirm submits
document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
        e.preventDefault();
    }
});
