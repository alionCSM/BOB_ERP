/**
 * Chi accede a questo cantiere, e con che ruolo.
 *
 * Tre ruoli: Ufficio (noi), Cantiere (il capo squadra e chi lavora li'),
 * Cliente (gli account del committente). Cosa vede ciascuno si decide su
 * ogni cosa con "Chi la vede"; di base, quello che uno mette lo vedono
 * tutti e tre.
 *
 * "Condividi col cliente" non sceglie persone: apre la porta agli account
 * cliente messi in questo elenco, e solo a quelli del cliente del
 * cantiere. Il riquadro lo scrive coi nomi, perche' "con chi condivido?"
 * e' la prima domanda che viene.
 *
 * Si aggiungono piu' persone insieme, cercando per nome o per azienda.
 *
 * Niente onclick negli attributi: la CSP di BOB non ha 'unsafe-inline' e
 * quelli non partirebbero. Tutto agganciato da qui.
 */
(function () {
    'use strict';

    const apri    = document.getElementById('btn-accessi');
    const modale  = document.getElementById('accessi-modal');
    if (!apri || !modale) return;

    const chiudi   = document.getElementById('btn-acc-close');
    const tendina  = document.getElementById('acc-utente');
    const aggiungi = document.getElementById('acc-aggiungi');
    const elenco   = document.getElementById('acc-elenco');

    const RUOLI = {
        ufficio:  'Ufficio',
        cantiere: 'Cantiere',
    };
    const SPIEGA = {
        ufficio:  'vede tutto il cantiere, decide chi vede cosa e chi entra',
        cantiere: 'porta avanti le attività della squadra, carica foto e file, compila i suoi moduli',
        cliente:  'vede ciò che è condiviso col cliente, apre attività per CSM, scrive e carica',
    };
    const COLORE = { ufficio: '#1e40af', cantiere: '#0f766e', cliente: '#7c3aed' };

    let caricati = false;
    let scelta = null;      // Tom Select
    let utenti = [];        // tutti quelli che si possono aggiungere
    let dentro = [];        // user_id gia' nel cantiere
    let cliente = '';       // il cliente del cantiere

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
    const barra = document.createElement('div');
    barra.className = 'acc-barra';
    barra.innerHTML =
        '<label class="acc-barra-riga"><input type="checkbox" id="acc-cliente">' +
        '<span><strong>Condividi col cliente</strong><span class="acc-barra-chi" id="acc-cliente-chi"></span></span></label>';
    elenco.parentNode.insertBefore(barra, elenco.parentNode.querySelector('.bz-modal-field'));
    const spunta = barra.querySelector('input');
    const chiCliente = barra.querySelector('#acc-cliente-chi');
    spunta.addEventListener('change', function () {
        postJson('/worksites/' + WID + '/zone/condividi-cliente', { attivo: spunta.checked })
            .then(function (r) {
                if (!r.ok) { spunta.checked = !spunta.checked; alert(r.error || 'Non salvato'); return; }
                carica();
            });
    });

    /** Chi entra davvero col cliente acceso: i nomi, non una regola. */
    function scriviChiCliente(persone) {
        const clienti = persone.filter(p => p.ruolo === 'cliente').map(p => p.nome);
        const di = cliente ? ' di ' + esc(cliente) : '';
        let t;
        if (!clienti.length) {
            t = 'Nessun account cliente in questo cantiere. Aggiungi qui sotto gli account' + di + ' che devono entrare.';
        } else if (spunta.checked) {
            t = 'Entrano: <b>' + clienti.map(esc).join(', ') + '</b>. Vedono ciò che è condiviso col cliente e ricevono gli avvisi per email.';
        } else {
            t = 'Accesso clienti disattivato: ' + clienti.map(esc).join(', ') + ' non hanno ancora accesso. Attivalo quando il cantiere è pronto per essere condiviso.';
        }
        if (!cliente) {
            t = 'Questo cantiere non ha un cliente in anagrafica: nessun account cliente può entrare.';
        }
        chiCliente.innerHTML = t;
    }

    function riga(p) {
        const div = document.createElement('div');
        div.className = 'acc-riga';
        div.dataset.userId = p.user_id;

        const chi = document.createElement('div');
        chi.className = 'acc-chi';
        chi.innerHTML =
            '<strong>' + esc(p.nome) + '</strong>' +
            (p.company ? '<span class="acc-azienda">' + esc(p.company) + '</span>' : '') +
            '<div class="acc-spiega">' + esc(SPIEGA[p.ruolo] || '') + '</div>';
        div.appendChild(chi);

        if (p.ruolo === 'cliente') {
            // un account cliente e' cliente e basta
            const t = document.createElement('span');
            t.className = 'acc-tag';
            t.textContent = 'Cliente';
            div.appendChild(t);
        } else {
            const sel = document.createElement('select');
            sel.className = 'acc-ruolo';
            // "Ufficio" solo a chi ha il modulo Zone di BOB
            Object.keys(RUOLI).filter(r => r !== 'ufficio' || p.ha_modulo_zone || p.ruolo === 'ufficio')
                .forEach(function (r) {
                    const o = document.createElement('option');
                    o.value = r;
                    o.textContent = RUOLI[r];
                    if (p.ruolo === r) o.selected = true;
                    sel.appendChild(o);
                });
            sel.addEventListener('change', function () {
                post(base(), { user_id: p.user_id, ruolo: sel.value }).then(function (r) {
                    div.classList.add(r && r.ok !== false ? 'acc-ok' : 'acc-ko');
                    chi.querySelector('.acc-spiega').textContent = SPIEGA[sel.value] || '';
                    setTimeout(() => div.classList.remove('acc-ok', 'acc-ko'), 700);
                });
            });
            div.appendChild(sel);
        }

        const togli = document.createElement('button');
        togli.type = 'button';
        togli.className = 'acc-togli';
        togli.textContent = 'Togli';
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
                cliente = d.cliente || '';
                elenco.innerHTML = '';

                const persone = d.persone || [];
                // ufficio, poi cantiere, poi cliente: come si legge una squadra
                const ordine = { ufficio: 0, cantiere: 1, cliente: 2 };
                persone.sort((a, b) => (ordine[a.ruolo] ?? 3) - (ordine[b.ruolo] ?? 3));
                if (!persone.length) {
                    elenco.innerHTML = '<p class="acc-vuoto">Nessuno accede a questo cantiere, nemmeno l\'ufficio. '
                        + 'Aggiungi prima te stesso come Ufficio.</p>';
                } else {
                    persone.forEach(p => elenco.appendChild(riga(p)));
                }
                scriviChiCliente(persone);
                dentro = persone.map(p => Number(p.user_id));
                riempiScelta();
            });
    }

    function etichetta(u) {
        if (u.tipo === 'client') return 'Cliente';
        if (u.ha_modulo_zone) return 'Ufficio';
        if (u.tipo === 'worker') return 'Operaio';
        return '';
    }

    function riempiScelta() {
        const liberi = utenti.filter(u => dentro.indexOf(Number(u.id)) === -1);
        if (!window.TomSelect) {
            // senza la libreria: la tendina semplice, una persona alla volta
            tendina.innerHTML = '<option value="">Scegli…</option>' + liberi.map(u =>
                '<option value="' + u.id + '">' + esc(u.label + (u.azienda ? ' · ' + u.azienda : '')) + '</option>').join('');
            return;
        }
        if (!scelta) {
            scelta = new TomSelect(tendina, {
                valueField: 'id',
                labelField: 'label',
                searchField: ['label', 'azienda'],
                maxOptions: 200,
                plugins: ['remove_button'],
                placeholder: 'Cerca per nome o azienda…',
                render: {
                    option: (u, e) => '<div class="acc-opz"><span>' + e(u.label) + '</span>'
                        + '<small>' + e([u.azienda, etichetta(u)].filter(Boolean).join(' · ')) + '</small></div>',
                    item: (u, e) => '<div>' + e(u.label) + (u.azienda ? ' <small style="opacity:.7">· ' + e(u.azienda) + '</small>' : '') + '</div>',
                    no_results: () => '<div class="no-results">Nessuno con questo nome o azienda</div>',
                },
                onChange: () => { aggiungi.disabled = !scelta.getValue().length; },
            });
        }
        scelta.clear(true);
        scelta.clearOptions();
        scelta.addOptions(liberi);
        scelta.refreshOptions(false);
        aggiungi.disabled = true;
    }

    function caricaUtenti() {
        return fetch('/worksites/' + WID + '/zone/users', { headers: { 'X-CSRF-Token': CSRF } })
            .then(r => r.json())
            .then(function (r) { utenti = (r.data || r || []).map(u => ({ ...u, id: String(u.id) })); });
    }

    // Si entra come Cantiere, o come Ufficio se si ha il modulo Zone. Un
    // account cliente diventa cliente da solo, qualunque cosa si mandi.
    async function aggiungiScelti() {
        const ids = scelta ? scelta.getValue() : (tendina.value ? [tendina.value] : []);
        if (!ids.length) return;
        aggiungi.disabled = true;
        const errori = [];
        for (const id of [].concat(ids)) {
            const u = utenti.find(x => x.id === String(id)) || {};
            const r = await post(base(), { user_id: id, ruolo: u.ha_modulo_zone ? 'ufficio' : 'cantiere' });
            if (!r || r.ok === false) errori.push((u.label || id) + ': ' + (r && r.error || 'non aggiunto'));
        }
        if (errori.length) alert(errori.join('\n'));
        carica();
    }
    aggiungi.addEventListener('click', aggiungiScelti);
    // senza Tom Select la tendina aggiunge appena si sceglie
    tendina.addEventListener('change', function () { if (!window.TomSelect && tendina.value) aggiungiScelti(); });

    apri.addEventListener('click', function () {
        modale.classList.remove('hidden');
        if (caricati) { carica(); return; }
        caricati = true;
        caricaUtenti().then(carica);
    });

    chiudi.addEventListener('click', function () { modale.classList.add('hidden'); });
    modale.addEventListener('click', function (e) {
        if (e.target === modale) modale.classList.add('hidden');
    });
})();
