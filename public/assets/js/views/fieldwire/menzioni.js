/**
 * BOB Zone — Menzioni con @ nei messaggi.
 *
 * Scrivendo "@" in un campo collegato compare l'elenco delle persone che
 * leggono quella conversazione (lo decide il server: nella chat con il
 * committente la squadra non compare, in una nota interna solo CSM).
 * Scegliendo una persona si inserisce "@Nome Cognome" e se ne ricorda l'id;
 * chi invia legge gli id con BZMenzioni.ids(campo) e li manda come
 * `menzioni`. Il server avvisa solo chi e' davvero fra i lettori e ha
 * ancora il nome nel testo.
 *
 *   BZMenzioni.collega('[data-bzd-testo-chat]', () => 'contesto=chat&canale=cliente');
 */
(function () {
    'use strict';
    if (typeof WID === 'undefined') return;

    const BASE = '/worksites/' + WID + '/zone';
    const collegati = [];          // { sel, query }
    const cache = new Map();       // query -> Promise<persone>
    const scelti = new WeakMap();  // campo -> Map(id -> nome)
    let menu = null, stato = null; // stato: { campo, inizio, elenco, attivo }

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const RUOLI = { ufficio: 'CSM', cantiere: 'Cantiere', cliente: 'Committente' };

    function persone(query) {
        if (!cache.has(query)) {
            cache.set(query, fetch(BASE + '/menzionabili?' + query, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(j => (j && j.ok && Array.isArray(j.data)) ? j.data : [])
                .catch(() => []));
            // l'elenco cambia di rado: si rilegge dopo qualche minuto
            setTimeout(() => cache.delete(query), 5 * 60 * 1000);
        }
        return cache.get(query);
    }

    function collegamento(el) {
        return collegati.find(c => el.matches && el.matches(c.sel));
    }

    function creaMenu() {
        if (menu) return menu;
        menu = document.createElement('div');
        menu.className = 'bzm-menu';
        menu.hidden = true;
        document.body.appendChild(menu);
        menu.addEventListener('mousedown', e => {
            const b = e.target.closest('[data-bzm]');
            if (!b) return;
            e.preventDefault();          // il campo non perde il fuoco
            scegli(parseInt(b.dataset.bzm));
        });
        return menu;
    }

    function chiudi() {
        stato = null;
        if (menu) menu.hidden = true;
    }

    /** Il pezzo "@par" subito prima del cursore, se c'e'. */
    function frammento(campo) {
        const pos = campo.selectionStart;
        const prima = campo.value.slice(0, pos);
        const m = prima.match(/(^|[\s(])@([^\s@]{0,30}(?: [^\s@]{0,30})?)$/);
        if (!m) return null;
        return { inizio: pos - m[2].length - 1, testo: m[2].toLowerCase() };
    }

    async function aggiorna(campo) {
        const c = collegamento(campo);
        const f = c && frammento(campo);
        if (!f) { chiudi(); return; }
        const q = c.query();
        if (!q) { chiudi(); return; }
        const tutti = await persone(q);
        // nel frattempo si e' scritto altro: conta l'ultimo stato
        const f2 = frammento(campo);
        if (!f2 || f2.inizio !== f.inizio) { if (!f2) chiudi(); return; }
        const elenco = tutti.filter(p => {
            const n = p.nome.toLowerCase();
            return !f2.testo || n.startsWith(f2.testo) || n.split(' ').some(w => w.startsWith(f2.testo));
        }).slice(0, 8);
        if (!elenco.length) { chiudi(); return; }
        stato = { campo, inizio: f2.inizio, elenco, attivo: 0 };
        disegna();
    }

    function disegna() {
        const m = creaMenu();
        m.innerHTML = stato.elenco.map((p, i) => `
            <button type="button" class="bzm-voce ${i === stato.attivo ? 'on' : ''}" data-bzm="${p.id}">
                <span class="bzm-iniz">${esc(p.nome.split(' ').map(w => w[0] || '').join('').slice(0, 2).toUpperCase())}</span>
                <span class="bzm-nome">${esc(p.nome)}</span>
                <span class="bzm-ruolo">${esc(RUOLI[p.ruolo] || '')}</span>
            </button>`).join('');
        const r = stato.campo.getBoundingClientRect();
        m.hidden = false;
        const h = m.offsetHeight;
        const sopra = r.top > h + 8;   // sopra il campo, se c'e' spazio
        m.style.left = Math.max(8, Math.min(r.left, window.innerWidth - m.offsetWidth - 8)) + 'px';
        m.style.top = (sopra ? r.top - h - 6 : r.bottom + 6) + 'px';
    }

    function scegli(id) {
        if (!stato) return;
        const p = stato.elenco.find(x => x.id === id);
        if (!p) return;
        const campo = stato.campo;
        const fine = campo.selectionStart;
        const ins = '@' + p.nome + ' ';
        campo.value = campo.value.slice(0, stato.inizio) + ins + campo.value.slice(fine);
        const pos = stato.inizio + ins.length;
        campo.setSelectionRange(pos, pos);
        if (!scelti.has(campo)) scelti.set(campo, new Map());
        scelti.get(campo).set(p.id, p.nome);
        chiudi();
        campo.focus();
        campo.dispatchEvent(new Event('input', { bubbles: true }));
    }

    document.addEventListener('input', e => {
        if (collegamento(e.target)) aggiorna(e.target);
    });
    document.addEventListener('click', e => {
        if (stato && e.target !== stato.campo && !e.target.closest('.bzm-menu')) chiudi();
    });
    document.addEventListener('focusout', e => {
        if (stato && e.target === stato.campo) setTimeout(() => { if (stato && document.activeElement !== stato.campo) chiudi(); }, 150);
    });
    window.addEventListener('resize', chiudi);
    // in cattura: prima dell'Invio che manda il messaggio
    document.addEventListener('keydown', e => {
        if (!stato || e.target !== stato.campo) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const n = stato.elenco.length;
            stato.attivo = (stato.attivo + (e.key === 'ArrowDown' ? 1 : n - 1)) % n;
            disegna();
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            e.stopImmediatePropagation();
            scegli(stato.elenco[stato.attivo].id);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopImmediatePropagation();
            chiudi();
        }
    }, true);

    window.BZMenzioni = {
        /** Collega i campi che corrispondono al selettore; query() dice la conversazione. */
        collega(sel, query) { collegati.push({ sel, query }); },

        /** Gli id delle persone menzionate il cui nome e' ancora nel testo. */
        ids(campo) {
            const m = campo && scelti.get(campo);
            if (!m) return [];
            return [...m].filter(([, nome]) => campo.value.includes('@' + nome)).map(([id]) => id);
        },

        /** Dopo l'invio: si dimenticano le menzioni del campo. */
        azzera(campo) { if (campo) scelti.delete(campo); },

        /**
         * Evidenzia le menzioni in un testo gia' sicuro per l'HTML: "@" seguito
         * da una o due parole con l'iniziale maiuscola.
         */
        evidenzia(html) {
            return String(html ?? '').replace(
                /(^|[\s(>])@([A-ZÀ-Ý][\wÀ-ÿ'’-]*(?: [A-ZÀ-Ý][\wÀ-ÿ'’-]*)?)/g,
                '$1<span class="bzm-tag">@$2</span>'
            );
        },
    };
})();
