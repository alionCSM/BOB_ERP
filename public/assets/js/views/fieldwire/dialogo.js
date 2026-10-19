/**
 * BOB Zone — Segnalazioni e chat del cantiere.
 *
 * Due viste della pagina Zone, con la stessa forma: elenco a sinistra,
 * dettaglio a destra. Cosa si vede lo decide il server (canali, chi vede
 * una segnalazione, chi la puo' chiudere): qui si mostra e si manda.
 *
 * I messaggi rapidi e le righe di BOB ("Presa in carico da...") arrivano
 * come chiave: l'app li traduce nella lingua di chi legge, qui in italiano.
 *
 * Mentre una vista e' aperta si chiedono i messaggi nuovi ogni pochi
 * secondi. Il link di una notifica (#segnalazione-12, #chat-squadra) apre
 * direttamente quella cosa.
 */
(function () {
    'use strict';

    if (typeof WID === 'undefined') return;

    const BASE = '/worksites/' + WID + '/zone';

    const TIPI = {
        sicurezza: { label: 'Sicurezza', icon: 'fa-helmet-safety' },
        materiale: { label: 'Materiale', icon: 'fa-boxes-stacked' },
        danno:     { label: 'Danno',     icon: 'fa-house-crack' },
        ritardo:   { label: 'Ritardo',   icon: 'fa-clock' },
        qualita:   { label: 'Qualità',   icon: 'fa-ruler-combined' },
        altro:     { label: 'Altro',     icon: 'fa-circle-question' },
    };
    const GRAVITA = {
        bassa:  { label: 'Normale' },
        alta:   { label: 'Alta' },
        blocca: { label: 'Blocca il lavoro' },
    };
    const STATI = { aperta: 'Aperta', presa: 'Presa in carico', risolta: 'Risolta' };
    const CANALI = {
        squadra: { label: 'Squadra', icon: 'fa-hard-hat',    desc: 'Ufficio, capi squadra e operai del cantiere.' },
        capi:    { label: 'Capi',    icon: 'fa-user-shield', desc: 'Solo ufficio e capi squadra.' },
        cliente: { label: 'Cliente', icon: 'fa-handshake',   desc: 'Ufficio, capi squadra e cliente. Gli operai non la vedono.' },
    };
    const RAPIDI = {
        materiale_arrivato: 'Materiale arrivato',
        serve_materiale:    'Serve materiale',
        finito_oggi:        'Finito per oggi',
        arrivati:           'Siamo arrivati in cantiere',
        ritardo:            'Siamo in ritardo',
        pausa_meteo:        'Fermi per il meteo',
        serve_aiuto:        'Serve aiuto',
        ok:                 'Ok, ricevuto',
        sys_presa:          'Presa in carico da {autore}',
        sys_risolta:        'Risolta da {autore}',
        sys_aperta:         'Riaperta da {autore}',
    };

    const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    const quando = s => {
        if (!s) return '';
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d)) return s;
        const oggi = new Date();
        const ora = d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
        return d.toDateString() === oggi.toDateString() ? ora
            : d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit' }) + ' ' + ora;
    };
    const post = (url, dati) => api(url, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(dati || {}),
    });
    const caricaFoto = (url, file, testo) => {
        const fd = new FormData();
        fd.append('photo', file);
        if (testo) fd.append('testo', testo);
        return api(url, { method: 'POST', body: fd });
    };

    let vistaAttiva = null;
    let timer = null;

    // ── Messaggi (comuni a chat e segnalazioni) ─────────────────────────────

    function testoMessaggio(m) {
        if (m.eliminato) return '<span class="eliminato">Messaggio eliminato</span>';
        let h = '';
        if (m.rapido) {
            h += `<div class="rapido">${esc((RAPIDI[m.rapido] || m.rapido).replace('{autore}', m.autore || ''))}</div>`;
        }
        if (m.testo) h += `<div class="testo">${esc(m.testo)}</div>`;
        if (m.ha_foto) h += `<img src="${BASE}/messaggi/${m.id}/foto" alt="" data-bzd-zoom loading="lazy">`;
        return h;
    }

    function rigaMessaggio(m, puoFissare) {
        if (m.sistema) {
            return `<div class="bzd-sys" data-mid="${m.id}">${testoMessaggio(m)} · ${esc(quando(m.created_at))}</div>`;
        }
        const azioni = [];
        if (!m.eliminato) {
            azioni.push(`<button class="att" data-bzd-letto="${m.id}" title="Chi l'ha letto"><i class="fas fa-eye"></i></button>`);
            if (puoFissare) {
                azioni.push(`<button class="att" data-bzd-fissa="${m.id}" data-si="${m.fissato ? 0 : 1}" title="${m.fissato ? 'Togli dagli avvisi' : 'Fissa come avviso'}"><i class="fas fa-thumbtack"></i></button>`);
            }
            if (m.mio || DA_UFFICIO) {
                azioni.push(`<button class="att" data-bzd-elimina="${m.id}" title="Elimina"><i class="fas fa-trash"></i></button>`);
            }
        }
        const cliente = m.autore_tipo === 'client' ? ' · cliente' : '';
        return `
            <div class="bzd-msg ${m.mio ? 'mio' : ''} ${m.fissato ? 'fisso' : ''}" data-mid="${m.id}">
                ${m.mio ? '' : `<div class="chi">${esc(m.autore || 'BOB')}${cliente}</div>`}
                ${testoMessaggio(m)}
                <div class="quando">${m.fissato ? '<i class="fas fa-thumbtack" style="color:#fbbf24"></i>' : ''}${esc(quando(m.created_at))} ${azioni.join('')}</div>
            </div>`;
    }

    function inFondo(box) { box.scrollTop = box.scrollHeight; }

    // ── Segnalazioni ────────────────────────────────────────────────────────

    const S = { elenco: [], puoGestire: false, filtro: 'aperte', apertaId: null, ultimo: 0, nuova: false };
    const rootS = () => document.getElementById('bzd-segn-root');

    async function caricaSegnalazioni() {
        const r = await api(BASE + '/segnalazioni');
        if (!r.ok) { rootS().innerHTML = `<div class="bzd-vuoto">${esc(r.error || 'Non disponibile')}</div>`; return; }
        S.elenco = r.data.segnalazioni || [];
        S.puoGestire = !!r.data.puo_gestire;
        badge('bzd-badge-segn', S.elenco.filter(s => s.stato === 'aperta').length);
        disegnaSegnalazioni();
    }

    function disegnaSegnalazioni() {
        const root = rootS();
        const vis = S.elenco.filter(s => S.filtro === 'tutte' || s.stato !== 'risolta');
        const card = s => {
            const t = TIPI[s.tipo] || TIPI.altro;
            return `
            <div class="bzd-card ${s.id === S.apertaId ? 'on' : ''}" data-bzd-segn="${s.id}">
                <div class="bzd-card-top">
                    <span class="bzd-pill ${s.stato}">${STATI[s.stato]}</span>
                    ${s.gravita !== 'bassa' ? `<span class="bzd-pill ${s.gravita}">${GRAVITA[s.gravita].label}</span>` : ''}
                    <span class="bzd-pill tipo"><i class="fas ${t.icon}"></i> ${t.label}</span>
                    ${s.non_letti ? '<span class="bzd-dot" title="Messaggi nuovi" style="margin-left:auto"></span>' : ''}
                </div>
                <div class="bzd-card-testo">${esc(s.testo)}</div>
                <div class="bzd-card-sotto">
                    <span>${esc(s.autore)} · ${esc(quando(s.created_at))}</span>
                    ${s.foto ? `<span><i class="fas fa-camera"></i> ${s.foto}</span>` : ''}
                    ${s.task_id ? '<span><i class="fas fa-list-check"></i> attività</span>' : ''}
                </div>
            </div>`;
        };
        let lista = root.querySelector('.bzd-lista');
        if (!lista) {
            root.innerHTML = `
                <div class="bzd-lista">
                    <div class="bzd-lista-bar">
                        <div class="bzd-filtro">
                            <button data-bzd-filtro="aperte">Da chiudere</button>
                            <button data-bzd-filtro="tutte">Tutte</button>
                        </div>
                        <button class="bzd-btn blu" data-bzd-nuova style="margin-left:auto"><i class="fas fa-plus"></i> Segnala</button>
                    </div>
                    <div class="bzd-lista-body"></div>
                </div>
                <div class="bzd-dett"><div class="bzd-vuoto"><i class="fas fa-triangle-exclamation"></i>Scegli una segnalazione, o segnala un problema.</div></div>`;
            lista = root.querySelector('.bzd-lista');
        }
        root.querySelectorAll('[data-bzd-filtro]').forEach(b => b.classList.toggle('on', b.dataset.bzdFiltro === S.filtro));
        lista.querySelector('.bzd-lista-body').innerHTML = vis.length
            ? vis.map(card).join('')
            : `<div class="bzd-vuoto"><i class="fas fa-circle-check"></i>${S.filtro === 'aperte' ? 'Niente da chiudere.' : 'Nessuna segnalazione.'}</div>`;
    }

    function formNuova() {
        S.nuova = { tipo: 'materiale', gravita: 'bassa' };
        S.apertaId = null;
        rootS().classList.add('dett-aperto');
        const dett = rootS().querySelector('.bzd-dett');
        const scelte = (nome, opz, cur) => `<div class="bzd-scelte" data-bzd-scelta="${nome}">${
            Object.keys(opz).map(k => `<button type="button" data-v="${k}" class="${k === cur ? 'on' : ''}">${opz[k].icon ? `<i class="fas ${opz[k].icon}"></i>` : ''}${esc(opz[k].label)}</button>`).join('')
        }</div>`;
        dett.innerHTML = `
            <div class="bzd-form">
                <h3><i class="fas fa-triangle-exclamation"></i> Nuova segnalazione</h3>
                <label>Che problema è</label>${scelte('tipo', TIPI, S.nuova.tipo)}
                <label>Quanto è grave</label>${scelte('gravita', GRAVITA, S.nuova.gravita)}
                <label>Cosa succede</label>
                <textarea id="bzd-nuova-testo" placeholder="Es. manca la bulloneria M12 per la campata 4"></textarea>
                <label>Foto (facoltative)</label>
                <input type="file" id="bzd-nuova-foto" accept="image/*" multiple>
                ${DA_UFFICIO ? `<label>Chi la vede</label><select id="bzd-nuova-vis">${Object.keys(VIS).map(k => `<option value="${k}" ${k === 'capi' ? 'selected' : ''}>${VIS[k].label}</option>`).join('')}</select>` : ''}
                <div class="bzd-azioni" style="margin-top:16px">
                    <button class="bzd-btn" data-bzd-annulla>Annulla</button>
                    <button class="bzd-btn blu" data-bzd-invia-nuova><i class="fas fa-paper-plane"></i> Invia</button>
                </div>
            </div>`;
    }

    async function inviaNuova(btn) {
        const testo = document.getElementById('bzd-nuova-testo').value.trim();
        if (!testo) { alert('Scrivi qual è il problema'); return; }
        btn.disabled = true;
        const r = await post(BASE + '/segnalazioni', {
            tipo: S.nuova.tipo, gravita: S.nuova.gravita, testo,
            visibilita: document.getElementById('bzd-nuova-vis')?.value,
        });
        if (!r.ok) { btn.disabled = false; alert(r.error || 'Non inviata'); return; }
        for (const f of document.getElementById('bzd-nuova-foto').files) {
            await caricaFoto(`${BASE}/segnalazioni/${r.data.id}/foto`, f);
        }
        S.nuova = false;
        await caricaSegnalazioni();
        apriSegnalazione(r.data.id);
    }

    async function apriSegnalazione(id, soloNuovi = false) {
        if (!soloNuovi) { S.apertaId = id; S.ultimo = 0; S.nuova = false; }
        const r = await api(`${BASE}/segnalazioni/${id}${soloNuovi ? '?dopo=' + S.ultimo : ''}`);
        if (!r.ok || S.apertaId !== id) return;
        const { segnalazione: s, messaggi } = r.data;
        const dett = rootS().querySelector('.bzd-dett');
        rootS().classList.add('dett-aperto');

        if (soloNuovi) {
            if (!messaggi.length) return;
            const box = dett.querySelector('.bzd-msgs');
            box.insertAdjacentHTML('beforeend', messaggi.map(m => rigaMessaggio(m, false)).join(''));
            S.ultimo = messaggi[messaggi.length - 1].id;
            inFondo(box);
            return;
        }

        const t = TIPI[s.tipo] || TIPI.altro;
        const gest = r.data.puo_gestire;
        const azioni = [];
        if (gest) {
            if (s.stato === 'aperta') azioni.push('<button class="bzd-btn blu" data-bzd-stato="presa"><i class="fas fa-hand"></i> Prendo in carico</button>');
            if (s.stato !== 'risolta') azioni.push('<button class="bzd-btn verde" data-bzd-stato="risolta"><i class="fas fa-check"></i> Risolta</button>');
            if (s.stato === 'risolta') azioni.push('<button class="bzd-btn" data-bzd-stato="aperta"><i class="fas fa-rotate-left"></i> Riapri</button>');
            if (!s.task_id) azioni.push('<button class="bzd-btn" data-bzd-attivita><i class="fas fa-list-check"></i> Crea attività</button>');
        }
        if (r.data.da_ufficio) {
            azioni.push(`<select data-bzd-vis title="Chi la vede">${Object.keys(VIS).map(k => `<option value="${k}" ${k === s.visibilita ? 'selected' : ''}>${VIS[k].label}</option>`).join('')}</select>`);
        }
        dett.innerHTML = `
            <div class="bzd-testa">
                <div class="bzd-card-top">
                    <button class="bzd-btn" data-bzd-indietro style="padding:4px 8px"><i class="fas fa-arrow-left"></i></button>
                    <span class="bzd-pill ${s.stato}">${STATI[s.stato]}</span>
                    ${s.gravita !== 'bassa' ? `<span class="bzd-pill ${s.gravita}">${GRAVITA[s.gravita].label}</span>` : ''}
                    <span class="bzd-pill tipo"><i class="fas ${t.icon}"></i> ${t.label}</span>
                    ${typeof visBadge === 'function' ? visBadge(s.visibilita, true) : ''}
                </div>
                <h3>${esc(s.testo)}</h3>
                <div class="sub">
                    ${esc(s.autore)} · ${esc(quando(s.created_at))}
                    ${s.presa_da_nome ? ` · in carico a <b>${esc(s.presa_da_nome)}</b>` : ''}
                    ${s.task_id ? ` · <a href="#" data-bzd-task="${s.task_id}" style="color:#60a5fa">apri l'attività</a>` : ''}
                </div>
                ${s.esito ? `<div class="sub" style="margin-top:6px;color:#4ade80"><i class="fas fa-check"></i> ${esc(s.esito)}</div>` : ''}
                ${azioni.length ? `<div class="bzd-azioni">${azioni.join('')}</div>` : ''}
            </div>
            <div class="bzd-msgs">${messaggi.map(m => rigaMessaggio(m, false)).join('') || '<div class="bzd-vuoto" style="padding:20px">Nessun messaggio. Scrivi qui sotto per rispondere.</div>'}</div>
            <div class="bzd-scrivi">
                <label class="ico" title="Foto"><i class="fas fa-camera"></i><input type="file" accept="image/*" hidden data-bzd-foto-segn></label>
                <textarea rows="1" placeholder="Rispondi..." data-bzd-testo-segn></textarea>
                <button class="ico invia" data-bzd-invia-segn><i class="fas fa-paper-plane"></i></button>
            </div>`;
        S.ultimo = messaggi.length ? messaggi[messaggi.length - 1].id : 0;
        inFondo(dett.querySelector('.bzd-msgs'));
        // i non letti di questa sono appena diventati letti
        const x = S.elenco.find(e => e.id === id);
        if (x) x.non_letti = 0;
        disegnaSegnalazioni();
    }

    // ── Chat ────────────────────────────────────────────────────────────────

    const C = { canali: [], canale: null, puoFissare: false, ultimo: 0 };
    const rootC = () => document.getElementById('bzd-chat-root');

    async function caricaChat() {
        const r = await api(BASE + '/chat');
        if (!r.ok) { rootC().innerHTML = `<div class="bzd-vuoto">${esc(r.error || 'Non disponibile')}</div>`; return; }
        C.canali = r.data.canali || [];
        C.puoFissare = !!r.data.puo_fissare;
        badge('bzd-badge-chat', C.canali.reduce((n, c) => n + (c.non_letti || 0), 0));
        if (!C.canale || !C.canali.find(c => c.canale === C.canale)) {
            C.canale = C.canali[0]?.canale || null;
        }
        if (!C.canale) {
            rootC().innerHTML = '<div class="bzd-vuoto"><i class="fas fa-comments"></i>Nessuna chat per te su questo cantiere.</div>';
            return;
        }
        apriCanale(C.canale);
    }

    async function apriCanale(canale, soloNuovi = false) {
        if (!soloNuovi) { C.canale = canale; C.ultimo = 0; }
        const r = await api(`${BASE}/chat/${canale}${soloNuovi ? '?dopo=' + C.ultimo : ''}`);
        if (!r.ok || C.canale !== canale) return;
        const { messaggi, fissati } = r.data;

        if (soloNuovi) {
            if (!messaggi.length) return;
            const box = rootC().querySelector('.bzd-msgs');
            box.querySelector('.bzd-vuoto')?.remove();
            box.insertAdjacentHTML('beforeend', messaggi.map(m => rigaMessaggio(m, C.puoFissare)).join(''));
            C.ultimo = messaggi[messaggi.length - 1].id;
            inFondo(box);
            return;
        }

        const c = CANALI[canale];
        const conto = C.canali.find(x => x.canale === canale);
        if (conto) conto.non_letti = 0;
        badge('bzd-badge-chat', C.canali.reduce((n, x) => n + (x.non_letti || 0), 0));

        rootC().innerHTML = `
            <div class="bzd-chat">
                <div class="bzd-canali">${C.canali.map(x => `
                    <button class="bzd-canale ${x.canale === canale ? 'on' : ''}" data-bzd-canale="${x.canale}">
                        <i class="fas ${CANALI[x.canale].icon}"></i> ${CANALI[x.canale].label}
                        ${x.non_letti ? `<span class="n">${x.non_letti}</span>` : ''}
                    </button>`).join('')}
                </div>
                <div class="bzd-canale-desc">${esc(c.desc)}</div>
                ${fissati && fissati.length ? `<div class="bzd-fissati">${fissati.map(f => `
                    <div class="bzd-fissato"><i class="fas fa-thumbtack"></i><span class="chi">${esc(f.autore || '')}</span>
                    <span>${f.rapido ? esc(RAPIDI[f.rapido] || f.rapido) : ''} ${esc(f.testo || (f.ha_foto ? 'Foto' : ''))}</span></div>`).join('')}</div>` : ''}
                <div class="bzd-msgs">${messaggi.map(m => rigaMessaggio(m, C.puoFissare)).join('') || '<div class="bzd-vuoto">Ancora nessun messaggio.</div>'}</div>
                <div class="bzd-rapidi">${Object.keys(RAPIDI).filter(k => !k.startsWith('sys_')).map(k => `<button data-bzd-rapido="${k}">${esc(RAPIDI[k])}</button>`).join('')}</div>
                <div class="bzd-scrivi">
                    <label class="ico" title="Foto"><i class="fas fa-camera"></i><input type="file" accept="image/*" hidden data-bzd-foto-chat></label>
                    <textarea rows="1" placeholder="Scrivi alla ${esc(c.label.toLowerCase())}..." data-bzd-testo-chat></textarea>
                    <button class="ico invia" data-bzd-invia-chat><i class="fas fa-paper-plane"></i></button>
                </div>
                ${C.puoFissare ? '<label class="bzd-fissa"><input type="checkbox" data-bzd-fissa-nuovo> <i class="fas fa-thumbtack"></i> Fissa come avviso (arriva a tutti)</label>' : ''}
            </div>`;
        C.ultimo = messaggi.length ? messaggi[messaggi.length - 1].id : 0;
        inFondo(rootC().querySelector('.bzd-msgs'));
    }

    async function inviaChat(dati) {
        const fissa = rootC().querySelector('[data-bzd-fissa-nuovo]');
        if (fissa?.checked) dati.fissa = true;
        const r = await post(`${BASE}/chat/${C.canale}`, dati);
        if (!r.ok) { alert(r.error || 'Non inviato'); return; }
        if (dati.fissa) { apriCanale(C.canale); return; }
        apriCanale(C.canale, true);
    }

    // ── Comune ──────────────────────────────────────────────────────────────

    function badge(id, n) {
        const b = document.getElementById(id);
        if (!b) return;
        b.hidden = !n;
        b.textContent = n > 99 ? '99+' : String(n);
    }

    function aggiorna() {
        if (document.hidden) return;
        if (vistaAttiva === 'segnalazioni' && S.apertaId) apriSegnalazione(S.apertaId, true);
        if (vistaAttiva === 'chat' && C.canale) apriCanale(C.canale, true);
    }

    async function chiLHaLetto(id) {
        const r = await api(`${BASE}/messaggi/${id}/letto`);
        if (!r.ok) return;
        const { letto, non_letto } = r.data;
        alert(
            (letto.length ? 'Letto da: ' + letto.join(', ') : 'Non l\'ha ancora letto nessuno.') +
            (non_letto.length ? '\n\nNon ancora: ' + non_letto.join(', ') : '')
        );
    }

    document.addEventListener('click', async function (e) {
        const t = e.target;
        let el;
        if ((el = t.closest('[data-bzd-segn]')))      { apriSegnalazione(parseInt(el.dataset.bzdSegn)); return; }
        if (t.closest('[data-bzd-nuova]'))            { formNuova(); return; }
        if (t.closest('[data-bzd-annulla]') || t.closest('[data-bzd-indietro]')) {
            S.nuova = false; S.apertaId = null;
            rootS().classList.remove('dett-aperto');
            rootS().querySelector('.bzd-dett').innerHTML = '<div class="bzd-vuoto"><i class="fas fa-triangle-exclamation"></i>Scegli una segnalazione, o segnala un problema.</div>';
            disegnaSegnalazioni();
            return;
        }
        if ((el = t.closest('[data-bzd-filtro]')))    { S.filtro = el.dataset.bzdFiltro; disegnaSegnalazioni(); return; }
        if ((el = t.closest('[data-bzd-scelta] button'))) {
            const nome = el.parentElement.dataset.bzdScelta;
            S.nuova[nome] = el.dataset.v;
            el.parentElement.querySelectorAll('button').forEach(b => b.classList.toggle('on', b === el));
            return;
        }
        if ((el = t.closest('[data-bzd-invia-nuova]'))) { inviaNuova(el); return; }
        if ((el = t.closest('[data-bzd-stato]'))) {
            let esito = null;
            if (el.dataset.bzdStato === 'risolta') {
                esito = prompt('Come è stata risolta? (facoltativo)');
                if (esito === null) return;
            }
            const r = await post(`${BASE}/segnalazioni/${S.apertaId}/stato`, { stato: el.dataset.bzdStato, esito });
            if (!r.ok) { alert(r.error || 'Non salvato'); return; }
            await caricaSegnalazioni();
            apriSegnalazione(S.apertaId);
            return;
        }
        if (t.closest('[data-bzd-attivita]')) {
            const s = S.elenco.find(x => x.id === S.apertaId);
            const nome = prompt('Nome dell\'attività per la squadra', s ? s.testo.slice(0, 120) : '');
            if (!nome) return;
            const r = await post(`${BASE}/segnalazioni/${S.apertaId}/attivita`, { name: nome });
            if (!r.ok) { alert(r.error || 'Non creata'); return; }
            if (typeof loadTasks === 'function') loadTasks();
            await caricaSegnalazioni();
            apriSegnalazione(S.apertaId);
            return;
        }
        if ((el = t.closest('[data-bzd-task]'))) {
            e.preventDefault();
            if (typeof switchTop === 'function') switchTop('attivita');
            if (typeof openTask === 'function') openTask(parseInt(el.dataset.bzdTask));
            return;
        }
        if (t.closest('[data-bzd-invia-segn]')) {
            const ta = rootS().querySelector('[data-bzd-testo-segn]');
            if (!ta.value.trim()) return;
            const r = await post(`${BASE}/segnalazioni/${S.apertaId}/messaggi`, { testo: ta.value });
            if (!r.ok) { alert(r.error || 'Non inviato'); return; }
            ta.value = '';
            apriSegnalazione(S.apertaId, true);
            return;
        }
        if ((el = t.closest('[data-bzd-canale]')))    { apriCanale(el.dataset.bzdCanale); return; }
        if ((el = t.closest('[data-bzd-rapido]')))    { inviaChat({ rapido: el.dataset.bzdRapido }); return; }
        if (t.closest('[data-bzd-invia-chat]')) {
            const ta = rootC().querySelector('[data-bzd-testo-chat]');
            if (!ta.value.trim()) return;
            const testo = ta.value;
            ta.value = '';
            inviaChat({ testo });
            return;
        }
        if ((el = t.closest('[data-bzd-letto]')))     { chiLHaLetto(el.dataset.bzdLetto); return; }
        if ((el = t.closest('[data-bzd-fissa]'))) {
            const r = await post(`${BASE}/messaggi/${el.dataset.bzdFissa}/fissa`, { si: el.dataset.si === '1' });
            if (!r.ok) { alert(r.error || 'Non salvato'); return; }
            apriCanale(C.canale);
            return;
        }
        if ((el = t.closest('[data-bzd-elimina]'))) {
            if (!confirm('Eliminare il messaggio?')) return;
            const r = await post(`${BASE}/messaggi/${el.dataset.bzdElimina}/elimina`);
            if (!r.ok) { alert(r.error || 'Non eliminato'); return; }
            if (vistaAttiva === 'chat') apriCanale(C.canale); else apriSegnalazione(S.apertaId);
            return;
        }
        if ((el = t.closest('[data-bzd-zoom]'))) {
            if (typeof openLightbox === 'function') openLightbox(el.src, '');
            else window.open(el.src, '_blank');
        }
    });

    document.addEventListener('change', async function (e) {
        const t = e.target;
        if (t.matches('[data-bzd-vis]')) {
            const r = await post(`${BASE}/segnalazioni/${S.apertaId}/visibilita`, { visibilita: t.value });
            if (!r.ok) alert(r.error || 'Non salvato');
            apriSegnalazione(S.apertaId);
            return;
        }
        if (t.matches('[data-bzd-foto-segn]') && t.files[0]) {
            const r = await caricaFoto(`${BASE}/segnalazioni/${S.apertaId}/foto`, t.files[0]);
            if (!r.ok) alert(r.error || 'Foto non caricata');
            t.value = '';
            apriSegnalazione(S.apertaId, true);
            return;
        }
        if (t.matches('[data-bzd-foto-chat]') && t.files[0]) {
            const r = await caricaFoto(`${BASE}/chat/${C.canale}/foto`, t.files[0]);
            if (!r.ok) alert(r.error || 'Foto non caricata');
            t.value = '';
            apriCanale(C.canale, true);
        }
    });

    // Invio col tasto Invio (a capo con Shift+Invio)
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.shiftKey) return;
        if (e.target.matches('[data-bzd-testo-chat]')) { e.preventDefault(); rootC().querySelector('[data-bzd-invia-chat]').click(); }
        if (e.target.matches('[data-bzd-testo-segn]')) { e.preventDefault(); rootS().querySelector('[data-bzd-invia-segn]').click(); }
    });

    window.BZDialogo = {
        /** La pagina cambia vista: si apre quella giusta e si ascolta. */
        vista(v) {
            vistaAttiva = (v === 'segnalazioni' || v === 'chat') ? v : null;
            clearInterval(timer);
            timer = null;
            if (vistaAttiva === 'segnalazioni') caricaSegnalazioni();
            if (vistaAttiva === 'chat') caricaChat();
            if (vistaAttiva) timer = setInterval(aggiorna, 8000);
        },
    };

    // I numeri sul menu anche senza aprire le viste
    (async function () {
        const [c, s] = await Promise.all([api(BASE + '/chat'), api(BASE + '/segnalazioni')]);
        if (c.ok) badge('bzd-badge-chat', (c.data.canali || []).reduce((n, x) => n + (x.non_letti || 0), 0));
        if (s.ok) badge('bzd-badge-segn', (s.data.segnalazioni || []).filter(x => x.stato === 'aperta').length);

        // Il link di una notifica: #segnalazione-12, #chat-squadra
        const h = location.hash.slice(1);
        if (h.startsWith('segnalazione-') && typeof switchTop === 'function') {
            switchTop('segnalazioni');
            const id = parseInt(h.slice(13));
            setTimeout(() => apriSegnalazione(id), 300);
        } else if (h.startsWith('chat-') && typeof switchTop === 'function') {
            C.canale = h.slice(5);
            switchTop('chat');
        }
    })();
})();
