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

// 3. data-confirm: la domanda prima di fare una cosa che non si annulla.
//
// Sta qui e non negli attributi onsubmit/onclick perche' la CSP di BOB non
// ha 'unsafe-inline': un onsubmit="return confirm(...)" non parte, e il
// risultato e' il peggiore possibile — la form si manda lo stesso, senza
// chiedere niente. Erano otto, fra cui "Eliminare veicolo?" e "Eliminare
// definitivamente la bozza?".
document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
        e.preventDefault();
    }
});

// Lo stesso su un link o un bottone che non sta dentro una form: si guarda
// anche il genitore, perche' spesso il data-confirm e' sulla form e il
// click arriva dal bottone dentro.
document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (!el || el.tagName === 'FORM') {
        return;   // le form le gestisce il submit qui sopra
    }
    if (!window.confirm(el.getAttribute('data-confirm'))) {
        e.preventDefault();
        e.stopPropagation();
    }
});

// 4. data-invia-al-cambio: i filtri che si applicano appena li tocchi.
//
// Era onchange="this.form.submit()", bloccato dalla stessa regola: i
// filtri delle anomalie non filtravano niente e sembravano rotti.
document.addEventListener('change', function (e) {
    var el = e.target.closest('[data-invia-al-cambio]');
    if (el && el.form) {
        el.form.submit();
    }
});
