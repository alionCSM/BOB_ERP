/**
 * BOB Zone — Richieste nei Documenti.
 *
 * Una richiesta sta in una cartella ed e' una piccola discussione: oggetto,
 * messaggi, documenti allegati. Si apre nel pannello dei Documenti (lo
 * stesso dei moduli) e, chiusa, si torna alla cartella.
 *
 *   BZRichieste.init({ apri: () => {...}, chiudi: (ricarica) => {...} })
 *   BZRichieste.nuova(folderId, nomeCartella)
 *   BZRichieste.apri(id)
 *
 * Un PDF allegato si compila in BOB (BZCompila): la copia compilata torna
 * nella discussione come risposta, e resta fra i documenti della cartella.
 */
(function () {
    'use strict';
    if (typeof WID === 'undefined') return;

    const BASE = '/worksites/' + WID + '/zone';
    let cb = { apri: () => {}, chiudi: () => {} };
    let aperta = null;     // id della richiesta aperta
    let cartellaNuova = null; // la cartella della richiesta che si sta scrivendo (0 = in cima)
    const menzioni = el => (window.BZMenzioni && el ? BZMenzioni.ids(el) : []);
    const root = () => document.getElementById('bz-richiesta-root');

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const quando = s => {
        const d = new Date(String(s || '').replace(' ', 'T'));
        if (isNaN(d)) return '';
        const ora = d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
        return d.toDateString() === new Date().toDateString() ? 'oggi alle ' + ora
            : d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' alle ' + ora;
    };
    const peso = b => { b = +b || 0; return b < 1024 ? b + ' B' : b < 1048576 ? Math.round(b / 1024) + ' KB' : (b / 1048576).toFixed(1) + ' MB'; };
    const icona = t => ({ pdf: 'fa-file-pdf', jpg: 'fa-file-image', jpeg: 'fa-file-image', png: 'fa-file-image', webp: 'fa-file-image',
                          doc: 'fa-file-word', docx: 'fa-file-word', xls: 'fa-file-excel', xlsx: 'fa-file-excel', dwg: 'fa-drafting-compass' })[t] || 'fa-file';

    async function chiama(url, opts = {}) {
        opts.headers = { 'X-CSRF-Token': CSRF, ...(opts.headers || {}) };
        const r = await fetch(url, { credentials: 'same-origin', ...opts });
        const t = await r.text();
        try { return JSON.parse(t); } catch { return { ok: false, error: 'Si è verificato un errore.' }; }
    }

    /** La lista dei file scelti, sotto il campo, con la possibilita' di toglierli. */
    function collegaAllegati(input, lista) {
        const scelti = [];
        const disegna = () => {
            lista.innerHTML = scelti.map((f, i) => `<span class="bzr-chip"><i class="fas ${icona((f.name.split('.').pop() || '').toLowerCase())}"></i> ${esc(f.name)} <button type="button" data-via="${i}" title="Rimuovi">×</button></span>`).join('');
        };
        input.addEventListener('change', () => { for (const f of input.files) scelti.push(f); input.value = ''; disegna(); });
        lista.addEventListener('click', e => { const b = e.target.closest('[data-via]'); if (b) { scelti.splice(+b.dataset.via, 1); disegna(); } });
        return () => scelti.slice();
    }

    // ── Nuova richiesta ─────────────────────────────────────────────────────
    function nuova(folderId, nomeCartella) {
        aperta = null;
        cartellaNuova = folderId || 0;
        nomeCartella = folderId ? nomeCartella : 'Documenti';
        cb.apri();
        root().innerHTML = `
            <div class="bzr">
                <div class="bzr-barra">
                    <button type="button" class="bzr-btn" data-bzr-indietro><i class="fas fa-arrow-left"></i> Indietro</button>
                    <span class="bzr-dove"><i class="fas fa-folder"></i> ${esc(nomeCartella || '')}</span>
                </div>
                <div class="bzr-form">
                    <h3><i class="fas fa-inbox"></i> Nuova richiesta</h3>
                    <label for="bzr-titolo">Oggetto</label>
                    <input type="text" id="bzr-titolo" maxlength="200" placeholder="Es. Modulo di collaudo da compilare">
                    <label for="bzr-testo">Messaggio</label>
                    <textarea id="bzr-testo" rows="5" placeholder="Es. Vi inviamo il modulo di collaudo da compilare e restituire entro venerdì. (@ per menzionare)"></textarea>
                    <label>Documenti allegati (facoltativi)</label>
                    <div class="bzr-allega">
                        <label class="bzr-btn"><i class="fas fa-paperclip"></i> Allega documento<input type="file" id="bzr-file" multiple hidden></label>
                        <div class="bzr-chips" id="bzr-file-lista"></div>
                    </div>
                    <p class="bzr-nota">${folderId
                        ? `La richiesta e i documenti allegati restano nella cartella «${esc(nomeCartella || '')}» e sono visibili a chi vede la cartella.`
                        : 'La richiesta resta in cima ai Documenti ed è visibile a CSM e al committente.'}</p>
                    <div class="bzr-azioni">
                        <button type="button" class="bzr-btn" data-bzr-indietro>Annulla</button>
                        <button type="button" class="bzr-btn primario" id="bzr-crea"><i class="fas fa-paper-plane"></i> Invia richiesta</button>
                    </div>
                </div>
            </div>`;
        const allegati = collegaAllegati(document.getElementById('bzr-file'), document.getElementById('bzr-file-lista'));
        document.getElementById('bzr-titolo').focus();
        document.getElementById('bzr-crea').addEventListener('click', async e => {
            const titolo = document.getElementById('bzr-titolo').value.trim();
            const testo = document.getElementById('bzr-testo').value.trim();
            if (!titolo) { alert('Inserire l\'oggetto della richiesta.'); return; }
            const fd = new FormData();
            if (folderId) fd.append('folder_id', folderId);
            fd.append('titolo', titolo);
            fd.append('testo', testo);
            menzioni(document.getElementById('bzr-testo')).forEach(id => fd.append('menzioni[]', id));
            allegati().forEach(f => fd.append('file[]', f));
            e.currentTarget.disabled = true;
            const r = await chiama(BASE + '/richieste', { method: 'POST', body: fd });
            if (!r.ok) { e.currentTarget.disabled = false; alert(r.error || 'Impossibile inviare la richiesta.'); return; }
            apri(r.data.id);
        });
    }

    // ── Una richiesta ───────────────────────────────────────────────────────
    async function apri(id) {
        aperta = id;
        cb.apri();
        const r = await chiama(`${BASE}/richieste/${id}`);
        if (!r.ok) { root().innerHTML = `<div class="bzr"><div class="bzr-barra"><button type="button" class="bzr-btn" data-bzr-indietro><i class="fas fa-arrow-left"></i> Indietro</button></div><div class="bz-dz-empty">${esc(r.error || 'Richiesta non disponibile.')}</div></div>`; return; }
        const { richiesta: q, messaggi, cartella, puo_eliminare } = r.data;
        const fatta = q.stato === 'completata';
        root().innerHTML = `
            <div class="bzr">
                <div class="bzr-barra">
                    <button type="button" class="bzr-btn" data-bzr-indietro><i class="fas fa-arrow-left"></i> Indietro</button>
                    <span class="bzr-dove"><i class="fas fa-folder"></i> ${esc(cartella)}</span>
                    <span class="bzr-spazio"></span>
                    ${fatta
                        ? '<button type="button" class="bzr-btn" data-bzr-stato="aperta"><i class="fas fa-rotate-left"></i> Riapri</button>'
                        : '<button type="button" class="bzr-btn verde" data-bzr-stato="completata"><i class="fas fa-check"></i> Segna come completata</button>'}
                    ${puo_eliminare ? '<button type="button" class="bzr-btn" data-bzr-elimina title="Elimina la richiesta"><i class="fas fa-trash"></i></button>' : ''}
                </div>
                <div class="bzr-testa">
                    <span class="bzr-stato ${fatta ? 'ok' : ''}">${fatta ? 'Completata' : 'In attesa'}</span>
                    <h3>${esc(q.titolo)}</h3>
                    <div class="bzr-sub">Aperta da ${esc(q.creata_da_nome || '—')} · ${esc(quando(q.created_at))}${fatta && q.completata_da_nome ? ` · completata da ${esc(q.completata_da_nome)} ${esc(quando(q.completata_at))}` : ''}</div>
                </div>
                <div class="bzr-filo">${messaggi.map(m => riga(m, fatta)).join('')}</div>
                <div class="bzr-scrivi">
                    <textarea id="bzr-risposta" rows="3" placeholder="Scrivere una risposta... (@ per menzionare)"></textarea>
                    <div class="bzr-chips" id="bzr-risposta-lista"></div>
                    <div class="bzr-azioni">
                        <label class="bzr-btn"><i class="fas fa-paperclip"></i> Allega documento<input type="file" id="bzr-risposta-file" multiple hidden></label>
                        <span class="bzr-spazio"></span>
                        ${fatta ? '' : '<label class="bzr-check"><input type="checkbox" id="bzr-completa"> Segna come completata</label>'}
                        <button type="button" class="bzr-btn primario" id="bzr-invia"><i class="fas fa-paper-plane"></i> Invia</button>
                    </div>
                </div>
            </div>`;
        const allegati = collegaAllegati(document.getElementById('bzr-risposta-file'), document.getElementById('bzr-risposta-lista'));
        document.getElementById('bzr-invia').addEventListener('click', async e => {
            const testo = document.getElementById('bzr-risposta').value.trim();
            const files = allegati();
            if (!testo && !files.length) { alert('Scrivere un messaggio o allegare un documento.'); return; }
            const fd = new FormData();
            fd.append('testo', testo);
            menzioni(document.getElementById('bzr-risposta')).forEach(m => fd.append('menzioni[]', m));
            files.forEach(f => fd.append('file[]', f));
            if (document.getElementById('bzr-completa')?.checked) fd.append('completa', '1');
            e.currentTarget.disabled = true;
            const x = await chiama(`${BASE}/richieste/${id}/messaggi`, { method: 'POST', body: fd });
            if (!x.ok) { e.currentTarget.disabled = false; alert(x.error || 'Impossibile inviare la risposta.'); return; }
            apri(id);
        });
        const filo = root().querySelector('.bzr-filo');
        filo.scrollTop = filo.scrollHeight;
    }

    function riga(m, fatta = false) {
        if (m.sistema) {
            return `<div class="bzr-sys">${m.sistema === 'completata' ? '<i class="fas fa-check"></i> Completata' : '<i class="fas fa-rotate-left"></i> Riaperta'} da ${esc(m.autore || '—')} · ${esc(quando(m.created_at))}</div>`;
        }
        const allegati = (m.allegati || []).map(a => a.esiste ? `
            <div class="bzr-allegato">
                <i class="fas ${icona(a.file_type)}"></i>
                <a href="${esc(a.download_url)}" target="_blank" rel="noopener">${esc(a.file_name)}</a>
                ${a.origine ? '<span class="bzr-compilato">Versione compilata</span>' : ''}
                <span class="bzr-peso">${peso(a.size_bytes)}</span>
                ${a.file_type === 'pdf' && !fatta ? `<button type="button" class="bzr-btn piccolo" data-bzr-compila="${a.id}" data-url="${esc(a.download_url)}" data-nome="${esc(a.file_name)}" title="Compilare il PDF in BOB e inviarlo come risposta"><i class="fas fa-pen-to-square"></i> Compila</button>` : ''}
            </div>` : '<div class="bzr-allegato via"><i class="fas fa-file-circle-xmark"></i> Documento eliminato dalla cartella</div>').join('');
        const testo = m.testo ? `<div class="bzr-testo">${window.BZMenzioni ? BZMenzioni.evidenzia(esc(m.testo)) : esc(m.testo)}</div>` : '';
        return `
            <div class="bzr-msg ${m.mio ? 'mio' : ''}">
                <div class="bzr-chi">${esc(m.mio ? 'Voi' : (m.autore || '—'))} · ${esc(quando(m.created_at))}</div>
                ${testo}${allegati}
            </div>`;
    }

    /** Il PDF compilato in BOB torna nella discussione come risposta. */
    function compila(btn) {
        if (!window.BZCompila) { alert('Editor PDF non disponibile.'); return; }
        const id = aperta;
        const nome = btn.dataset.nome || 'documento.pdf';
        BZCompila.apri({
            url: btn.dataset.url,
            nome,
            salva: async blob => {
                const completa = confirm('Documento compilato. Segnare anche la richiesta come completata?');
                const base = nome.replace(/\.pdf$/i, '');
                const fd = new FormData();
                fd.append('testo', 'Documento compilato: ' + nome);
                fd.append('file[]', new File([blob], base + ' (compilato).pdf', { type: 'application/pdf' }));
                fd.append('da_file_id', btn.dataset.bzrCompila);   // la copia compilata e' una versione dell'originale
                if (completa) fd.append('completa', '1');
                const x = await chiama(`${BASE}/richieste/${id}/messaggi`, { method: 'POST', body: fd });
                if (!x.ok) throw new Error(x.error || 'Salvataggio non riuscito.');
                apri(id);
            },
        });
    }

    document.addEventListener('click', async e => {
        if (!root() || !root().contains(e.target)) return;
        let b;
        if (e.target.closest('[data-bzr-indietro]')) { aperta = null; cb.chiudi(true); return; }
        if ((b = e.target.closest('[data-bzr-compila]'))) { compila(b); return; }
        if ((b = e.target.closest('[data-bzr-stato]'))) {
            const r = await chiama(`${BASE}/richieste/${aperta}/stato`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ stato: b.dataset.bzrStato }) });
            if (!r.ok) { alert(r.error || 'Si è verificato un errore.'); return; }
            apri(aperta);
            return;
        }
        if (e.target.closest('[data-bzr-elimina]')) {
            if (!confirm('Eliminare la richiesta? I documenti allegati restano nella cartella.')) return;
            const r = await chiama(`${BASE}/richieste/${aperta}/elimina`, { method: 'POST' });
            if (!r.ok) { alert(r.error || 'Impossibile eliminare la richiesta.'); return; }
            aperta = null;
            cb.chiudi(true);
        }
    });

    // @ nei messaggi: chi vede la cartella (o la richiesta)
    if (window.BZMenzioni) {
        BZMenzioni.collega('#bzr-testo', () => 'contesto=cartella&id=' + (cartellaNuova || 0));
        BZMenzioni.collega('#bzr-risposta', () => aperta ? 'contesto=richiesta&id=' + aperta : '');
    }

    window.BZRichieste = {
        init(c) { cb = { ...cb, ...c }; },
        nuova,
        apri,
    };
})();
