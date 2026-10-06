/**
 * Chi accede a questo cantiere, e con che ruolo.
 *
 * Un ruolo per persona: capo squadra, operaio, cliente. Cosa vede ciascuno
 * non si decide piu' qui sezione per sezione, ma su ogni cosa (attivita',
 * cartella, disegno, modulo) con "Chi la vede". Sei tendine per persona per
 * cantiere non le teneva aggiornate nessuno.
 *
 * Il cliente entra solo se e' acceso "Condividi col cliente": cosi' si puo'
 * preparare tutto, e aprire la porta quando e' pronto.
 *
 * Niente onclick negli attributi: la CSP di BOB non ha 'unsafe-inline' e
 * quelli non partirebbero. Tutto agganciato da qui.
 */
(function () {
    'use strict';

    const apri    = document.getElementById('btn-accessi');
    const modale  = document.getElementById('accessi-modal');
    if (!apri || !modale) return;

    const chiudi  = document.getElementById('btn-acc-close');
    const tendina = document.getElementById('acc-utente');
    const elenco  = document.getElementById('acc-elenco');

    const RUOLI = {
        capo:    'Capo squadra',
        operaio: 'Operaio',
    };
    const SPIEGA = {
        capo:    'crea e assegna attivita\' alla squadra, carica file e foto, compila i moduli',
        operaio: 'vede le cose della squadra, lavora sulle sue attivita\', compila i suoi moduli',
        cliente: 'vede solo quello che l\'ufficio condivide col cliente',
    };

    let caricati = false;

    function post(url, dati) {
        const corpo = new URLSearchParams(dati);
        corpo.append('_csrf', CSRF);
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
            body: corpo,
        }).then(r => r.json());
    }

    function postJson(url, dati) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(dati),
        }).then(r => r.json());
    }

    function base() { return '/worksites/' + WID + '/zone/accessi'; }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML;
    }

    // ── "Condividi col cliente" ──
    const barra = document.createElement('label');
    barra.style.cssText = 'display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px;cursor:pointer;';
    barra.innerHTML =
        '<input type="checkbox" id="acc-cliente" style="width:18px;height:18px;">' +
        '<span><strong style="font-size:13px;color:#1e293b;">Condividi col cliente</strong><br>' +
        '<span style="font-size:11.5px;color:#64748b;">I clienti assegnati vedono quello che e\' segnato "Ufficio e cliente" o "Tutti". Spento, non vedono niente.</span></span>';
    elenco.parentNode.insertBefore(barra, elenco.previousElementSibling || elenco);
    const spunta = barra.querySelector('input');
    spunta.addEventListener('change', function () {
        postJson('/worksites/' + WID + '/zone/condividi-cliente', { attivo: spunta.checked })
            .then(function (r) { if (!r.ok) { spunta.checked = !spunta.checked; alert(r.error || 'Non salvato'); } });
    });

    function riga(p) {
        const div = document.createElement('div');
        div.style.cssText = 'display:flex;align-items:center;gap:12px;border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;margin-bottom:8px;';
        div.dataset.userId = p.user_id;

        const chi = document.createElement('div');
        chi.style.cssText = 'flex:1;min-width:0;';
        chi.innerHTML =
            '<strong style="font-size:13px;color:#1e293b;">' + esc(p.nome) + '</strong>' +
            '<span style="font-size:11px;color:#94a3b8;margin-left:8px;">' + esc(p.company || '') + '</span>' +
            '<div class="acc-spiega" style="font-size:11px;color:#64748b;margin-top:2px;">' + esc(SPIEGA[p.ruolo] || '') + '</div>';
        div.appendChild(chi);

        if (p.ruolo === 'cliente') {
            // un account cliente e' cliente e basta: non diventa capo di nessuno
            const t = document.createElement('span');
            t.textContent = 'Cliente';
            t.style.cssText = 'font-size:12px;font-weight:700;color:#7c3aed;background:#f5f3ff;border-radius:7px;padding:5px 10px;';
            div.appendChild(t);
        } else {
            const sel = document.createElement('select');
            sel.style.cssText = 'background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:6px 8px;font-size:12px;color:#1e293b;';
            Object.keys(RUOLI).forEach(function (r) {
                const o = document.createElement('option');
                o.value = r;
                o.textContent = RUOLI[r];
                if (p.ruolo === r) o.selected = true;
                sel.appendChild(o);
            });
            sel.addEventListener('change', function () {
                post(base(), { user_id: p.user_id, ruolo: sel.value }).then(function (r) {
                    div.style.borderColor = (r && r.ok !== false) ? '#4ade80' : '#f87171';
                    chi.querySelector('.acc-spiega').textContent = SPIEGA[sel.value] || '';
                    setTimeout(function () { div.style.borderColor = '#e2e8f0'; }, 700);
                });
            });
            div.appendChild(sel);
        }

        const togli = document.createElement('button');
        togli.textContent = 'Togli';
        togli.style.cssText = 'background:none;border:1px solid #fecaca;color:#b91c1c;border-radius:7px;padding:5px 10px;font-size:11px;font-weight:700;cursor:pointer;';
        togli.addEventListener('click', function () {
            if (!confirm('Togliere ' + p.nome + ' da questo cantiere?')) return;
            post(base() + '/elimina', { user_id: p.user_id }).then(carica);
        });
        div.appendChild(togli);
        return div;
    }

    function carica() {
        return fetch(base(), { headers: { 'X-CSRF-Token': CSRF } })
            .then(r => r.json())
            .then(function (r) {
                const d = r.data || r;
                spunta.checked = !!d.zone_cliente;
                elenco.innerHTML = '';

                const persone = d.persone || [];
                if (!persone.length) {
                    elenco.innerHTML = '<p style="font-size:12.5px;color:#94a3b8;padding:18px 0;text-align:center;">' +
                        'Nessuno assegnato. Chi ha il modulo Zone in BOB entra lo stesso.</p>';
                } else {
                    persone.forEach(function (p) { elenco.appendChild(riga(p)); });
                }

                const dentro = persone.map(p => p.user_id);
                [...tendina.options].forEach(function (o) {
                    if (o.value) o.hidden = dentro.indexOf(Number(o.value)) !== -1;
                });
                tendina.value = '';
            });
    }

    function caricaUtenti() {
        return fetch('/worksites/' + WID + '/zone/users', { headers: { 'X-CSRF-Token': CSRF } })
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

    // Si entra come operaio; capo si sceglie. Un account cliente diventa
    // cliente da solo, qualunque cosa si mandi.
    tendina.addEventListener('change', function () {
        if (!tendina.value) return;
        post(base(), { user_id: tendina.value, ruolo: 'operaio' }).then(carica);
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
