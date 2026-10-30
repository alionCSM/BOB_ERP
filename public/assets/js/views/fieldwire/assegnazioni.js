/**
 * Moduli da compilare.
 *
 * Un modello (il verbale giornaliero, la checklist DPI, il verbale di
 * consegna) assegnato a qualcuno: a una persona, o a tutti quelli di un
 * ruolo. Una volta, ogni giorno o ogni settimana, con una scadenza. E per
 * ognuno, chi legge le risposte: il DPI lo legge solo l'ufficio, il verbale
 * di consegna anche il cliente.
 *
 * L'ufficio li vede tutti e li assegna; gli altri vedono solo i propri, con
 * il pulsante per compilarli. Sta sopra all'elenco dei modelli, nella vista
 * Moduli.
 */
(function () {
    'use strict';

    const root = document.getElementById('bz-assegnazioni-root');
    if (!root || typeof WID === 'undefined') return;

    const A_CHI = { cantiere: 'Chi e\' in cantiere', cliente: 'Il cliente', ufficio: "L'ufficio" };
    // assegnazioni scritte prima che capo e operaio diventassero Cantiere
    const A_CHI_VECCHI = { capo: A_CHI.cantiere, operaio: A_CHI.cantiere };
    const FREQ  = { una_volta: 'Una volta', giornaliera: 'Ogni giorno', settimanale: 'Ogni settimana' };

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML;
    }

    async function get(url) {
        const r = await fetch(url, { headers: { 'X-CSRF-Token': CSRF } });
        return r.json();
    }
    async function post(url, dati) {
        const r = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(dati || {}),
        });
        return r.json();
    }

    function base() { return '/worksites/' + WID + '/zone/forms/assegnazioni'; }

    function data(s) {
        if (!s) return '';
        const d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: '2-digit' });
    }

    const PERIODO = { giornaliera: 'Oggi', settimanale: 'Questa settimana', una_volta: '' };

    /**
     * Chi l'ha fatto e chi manca nel periodo (oggi, questa settimana, o da
     * sempre). Arriva solo all'ufficio e ai capi.
     */
    function statoRiga(a) {
        const st = a.stato;
        if (!st) return '';
        const quando = PERIODO[a.frequenza] ? PERIODO[a.frequenza] + ': ' : '';
        const fatti = st.fatti.length
            ? `<span style="color:#22c55e"><i class="fas fa-check"></i> ${st.fatti.map(f => esc(f.nome)).join(', ')}</span>`
            : '';
        const mancano = st.mancano.length
            ? `<span style="color:#f87171"><i class="fas fa-hourglass-half"></i> mancano ${st.mancano.map(esc).join(', ')}</span>`
            : '';
        if (!fatti && !mancano) {
            return `<div class="sub">${esc(quando)}nessuno l'ha ancora compilato</div>`;
        }
        if (!mancano && a.a_ruolo !== 'ufficio') {
            return `<div class="sub">${esc(quando)}${fatti} · tutti fatto</div>`;
        }
        return `<div class="sub">${esc(quando)}${[fatti, mancano].filter(Boolean).join(' · ')}</div>`;
    }

    function riga(a) {
        const chi = a.a_user_id ? (a.persona || 'una persona') : (A_CHI[a.a_ruolo] || A_CHI_VECCHI[a.a_ruolo] || a.a_ruolo);
        const sub = [
            FREQ[a.frequenza] || a.frequenza,
            a.scadenza ? 'entro il ' + data(a.scadenza) : '',
            a.ultima_compilazione ? 'ultima: ' + data(a.ultima_compilazione) : 'mai compilato',
        ].filter(Boolean).join(' · ');
        // chi legge le risposte: un numero, e il pannello per cambiarlo.
        // L'ufficio le legge sempre e non si conta.
        const quanti = (a.condivisa_con || []).length;
        const vis = DA_UFFICIO
            ? `<button type="button" class="bz-chi-btn" data-chi-tipo="modulo" data-chi-oggetto="${a.id}" title="Chi legge le risposte"><i class="fas fa-eye"></i> ${quanti ? quanti : 'solo ufficio'}</button>`
            : '';
        return `
            <div class="bz-assegna-row">
                <i class="fas fa-clipboard-check" style="color:#60a5fa"></i>
                <div style="flex:1;min-width:0;">
                    <div><b>${esc(a.modulo)}</b> → ${esc(chi)}</div>
                    <div class="sub">${esc(sub)} ${vis}</div>
                    ${statoRiga(a)}
                </div>
                ${DA_UFFICIO
                    ? `<button data-togli-assegnazione="${a.id}">Togli</button>`
                    : a.solo_stato ? '<span class="sub">squadra</span>' : `<button data-compila="${a.template_id}" data-assegnazione="${a.id}" style="background:#2563eb;color:#fff;border:0;">Compila</button>`}
            </div>`;
    }

    async function carica() {
        root.innerHTML = '';
        const r = await get(base());
        const tutte = (r.ok && Array.isArray(r.data)) ? r.data : [];

        // chi non e' dell'ufficio e non ha niente da compilare non vede il
        // riquadro: un titolo con sotto "niente" non serve
        if (!DA_UFFICIO && !tutte.length) return;

        let form = '';
        if (DA_UFFICIO) {
            const [modelli, acc] = await Promise.all([
                get('/worksites/' + WID + '/zone/forms'),
                get('/worksites/' + WID + '/zone/accessi'),
            ]);
            const tpls    = (modelli.ok && Array.isArray(modelli.data)) ? modelli.data : [];
            const persone = ((acc.data || {}).persone) || [];


            form = tpls.length ? `
                <div class="bz-assegna-form">
                    <select id="bza-modello">${tpls.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('')}</select>
                    <select id="bza-chi">
                        <optgroup label="Un ruolo">
                            ${Object.keys(A_CHI).map(k => `<option value="r:${k}">${A_CHI[k]}</option>`).join('')}
                        </optgroup>
                        ${persone.length ? `<optgroup label="Una persona">${persone.map(p => `<option value="u:${p.user_id}">${esc(p.nome)}</option>`).join('')}</optgroup>` : ''}
                    </select>
                    <select id="bza-freq">${Object.keys(FREQ).map(k => `<option value="${k}">${FREQ[k]}</option>`).join('')}</select>
                    <input type="date" id="bza-scad" title="Scadenza (facoltativa)">
                    <button type="button" class="bz-chi-btn" id="bza-vis" data-persone="" title="Chi legge le risposte"><i class="fas fa-eye"></i> Solo ufficio</button>
                    <button id="bza-assegna">Assegna</button>
                </div>` : '<div class="sub" style="font-size:12px;color:#64748b;">Crea prima un modello qui sotto, poi assegnalo.</div>';
        }

        root.innerHTML = `
            <div class="bz-assegna">
                <h4><i class="fas fa-tasks"></i> Da compilare</h4>
                ${tutte.length ? tutte.map(riga).join('') : '<div style="font-size:12px;color:#64748b;">Nessun modulo assegnato.</div>'}
                ${form}
            </div>`;
    }

    root.addEventListener('click', async function (e) {
        const togli = e.target.closest('[data-togli-assegnazione]');
        if (togli) {
            if (!confirm('Togliere questo modulo da compilare? Le compilazioni gia\' fatte restano.')) return;
            await post(base() + '/' + togli.dataset.togliAssegnazione + '/disattiva');
            carica();
            return;
        }
        const compila = e.target.closest('[data-compila]');
        if (compila && window.BZForms) {
            BZForms.openFill(parseInt(compila.dataset.compila), parseInt(compila.dataset.assegnazione));
            document.getElementById('bz-forms-root')?.scrollIntoView({ behavior: 'smooth' });
            return;
        }
        // Chi legge le risposte, prima che il modulo esista: la scelta
        // resta sul bottone e parte col resto. Il pannello e' quello della
        // pagina (apriChi), cosi' i nomi si spuntano allo stesso modo
        // ovunque.
        const sceltaVis = e.target.closest('#bza-vis');
        if (sceltaVis && typeof window.apriChi === 'function') {
            const gia = (sceltaVis.dataset.persone || '').trim();
            window.apriChi('modulo', 0, gia === '' ? [] : JSON.parse(gia), function (persone) {
                sceltaVis.dataset.persone = JSON.stringify(persone);
                sceltaVis.innerHTML = '<i class="fas fa-eye"></i> '
                    + (persone.length ? persone.length + (persone.length === 1 ? ' persona' : ' persone')
                                      : 'Solo ufficio');
            });
            return;
        }

        if (e.target.closest('#bza-assegna')) {
            const chi = document.getElementById('bza-chi').value;
            const scelte = (document.getElementById('bza-vis')?.dataset.persone || '').trim();
            const r = await post(base(), {
                template_id: parseInt(document.getElementById('bza-modello').value),
                a_ruolo:     chi.startsWith('r:') ? chi.slice(2) : null,
                a_user_id:   chi.startsWith('u:') ? parseInt(chi.slice(2)) : null,
                frequenza:   document.getElementById('bza-freq').value,
                scadenza:    document.getElementById('bza-scad').value || null,
                persone:     scelte === '' ? [] : JSON.parse(scelte),
            });
            if (!r.ok) { alert(r.error || 'Non salvato'); return; }
            carica();
        }
    });

    // Si carica quando si apre la vista Moduli: openForms e' la funzione che
    // la pagina chiama in quel momento.
    if (typeof window.openForms === 'function') {
        const prima = window.openForms;
        window.openForms = function () {
            prima.apply(this, arguments);
            carica();
        };
    }
})();
