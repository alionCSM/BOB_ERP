/**
 * Chi accede a questo cantiere, e fin dove.
 *
 * Una riga per persona, sei tendine: non vede / vede / vede e modifica.
 * Le tendine si salvano da sole al cambio, senza un bottone Salva: su una
 * griglia di sei per dieci persone un bottone unico vuol dire o salvare
 * tutto a ogni colpo, o perdere le modifiche a chi chiude la finestra.
 *
 * Niente onclick negli attributi: la CSP di BOB non ha 'unsafe-inline' e
 * quelli non partirebbero. Tutto agganciato da qui.
 */
(function () {
    'use strict';

    const apri      = document.getElementById('btn-accessi');
    const modale    = document.getElementById('accessi-modal');
    if (!apri || !modale) return;

    const chiudi    = document.getElementById('btn-acc-close');
    const tendina   = document.getElementById('acc-utente');
    const elenco    = document.getElementById('acc-elenco');
    const LIVELLI   = [
        [0, 'non vede'],
        [1, 'vede'],
        [2, 'vede e modifica'],
    ];

    let famiglie = {};
    let caricati = false;

    function post(url, dati) {
        const corpo = new URLSearchParams(dati);
        corpo.append('_csrf', CSRF);
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: corpo,
        }).then(r => r.json());
    }

    function riga(p) {
        const div = document.createElement('div');
        div.style.cssText = 'border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-bottom:10px;';
        div.dataset.userId = p.user_id;

        const testa = document.createElement('div');
        testa.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;';
        testa.innerHTML =
            '<div><strong style="font-size:13px;color:#1e293b;">' + esc(p.nome) + '</strong>' +
            '<span style="font-size:11px;color:#94a3b8;margin-left:8px;">' + esc(p.company || '') + '</span></div>';

        const togli = document.createElement('button');
        togli.textContent = 'Togli';
        togli.style.cssText = 'background:none;border:1px solid #fecaca;color:#b91c1c;border-radius:7px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;';
        togli.addEventListener('click', function () {
            if (!confirm('Togliere ' + p.nome + ' da questo cantiere?')) return;
            post(base() + '/elimina', { user_id: p.user_id }).then(carica);
        });
        testa.appendChild(togli);
        div.appendChild(testa);

        const griglia = document.createElement('div');
        griglia.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;';

        Object.keys(famiglie).forEach(function (f) {
            const campo = document.createElement('label');
            campo.style.cssText = 'display:flex;flex-direction:column;gap:3px;';

            const et = document.createElement('span');
            et.textContent = famiglie[f];
            et.style.cssText = 'font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;';
            campo.appendChild(et);

            const sel = document.createElement('select');
            sel.style.cssText = 'background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:6px 8px;font-size:12px;color:#1e293b;';
            LIVELLI.forEach(function (l) {
                const o = document.createElement('option');
                o.value = l[0];
                o.textContent = l[1];
                if (Number(p[f]) === l[0]) o.selected = true;
                sel.appendChild(o);
            });
            sel.addEventListener('change', function () { salvaRiga(div); });
            campo.appendChild(sel);
            griglia.appendChild(campo);
        });

        div.appendChild(griglia);
        return div;
    }

    function salvaRiga(div) {
        const dati = { user_id: div.dataset.userId };
        const sel  = div.querySelectorAll('select');
        Object.keys(famiglie).forEach(function (f, i) { dati[f] = sel[i].value; });

        // Un lampo verde e via: su una tendina che si salva da sola serve
        // sapere che e' andata, non leggere un messaggio.
        post(base(), dati).then(function (r) {
            div.style.borderColor = (r && r.success !== false) ? '#4ade80' : '#f87171';
            setTimeout(function () { div.style.borderColor = '#e2e8f0'; }, 700);
        });
    }

    function base() { return '/worksites/' + WID + '/zone/accessi'; }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML;
    }

    function carica() {
        return fetch(base())
            .then(r => r.json())
            .then(function (r) {
                const d = r.data || r;
                famiglie = d.famiglie || {};
                elenco.innerHTML = '';

                const persone = d.persone || [];
                if (!persone.length) {
                    elenco.innerHTML = '<p style="font-size:12.5px;color:#94a3b8;padding:18px 0;text-align:center;">' +
                        'Nessuno assegnato. Chi ha il modulo Zone in BOB entra lo stesso.</p>';
                } else {
                    persone.forEach(function (p) { elenco.appendChild(riga(p)); });
                }

                // chi c'e' gia' sparisce dalla tendina: riassegnarlo non
                // farebbe danni, ma e' una scelta che non vuol dire niente
                const dentro = persone.map(p => p.user_id);
                [...tendina.options].forEach(function (o) {
                    if (o.value) o.hidden = dentro.indexOf(Number(o.value)) !== -1;
                });
                tendina.value = '';
            });
    }

    function caricaUtenti() {
        return fetch('/worksites/' + WID + '/zone/users')
            .then(r => r.json())
            .then(function (r) {
                (r.data || r || []).forEach(function (u) {
                    const o = document.createElement('option');
                    o.value = u.id;
                    o.textContent = u.label;
                    tendina.appendChild(o);
                });
            });
    }

    tendina.addEventListener('change', function () {
        if (!tendina.value) return;
        // Nasce vedendo tutto senza toccare niente: e' il caso piu' comune
        // e il piu' innocuo da sbagliare.
        const dati = { user_id: tendina.value };
        Object.keys(famiglie).forEach(function (f) { dati[f] = 1; });
        post(base(), dati).then(carica);
    });

    apri.addEventListener('click', function () {
        modale.classList.remove('hidden');
        if (caricati) { carica(); return; }
        caricati = true;
        carica().then(caricaUtenti).then(carica);
    });

    chiudi.addEventListener('click', function () { modale.classList.add('hidden'); });
    modale.addEventListener('click', function (e) {
        if (e.target === modale) modale.classList.add('hidden');
    });
})();
