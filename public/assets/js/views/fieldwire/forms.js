/*
 * BOB Zone — Moduli (form builder + filler + compilazioni)
 * Modulo standalone CSP-safe. Init: BZForms.init({worksiteId, csrf}).
 * Renderizza dentro #bz-forms-root.
 */
(function () {
    'use strict';

    let WID = 0, CSRF = '';
    let META = { wsName: '', wsCode: '', clientName: '' };
    let root = null;
    let view = 'list';          // list | builder | fill | submission | submissions
    let builder = null;         // stato builder corrente
    let signaturePads = {};     // fid → {canvas, ctx, drawing}

    const TYPES = [
        { t: 'text',       label: 'Testo' },
        { t: 'textarea',   label: 'Area testo' },
        { t: 'number',     label: 'Numero' },
        { t: 'date',       label: 'Data' },
        { t: 'select',     label: 'Menu a tendina', opts: true },
        { t: 'radio',      label: 'Scelta singola',  opts: true },
        { t: 'checkboxes', label: 'Scelta multipla', opts: true },
        { t: 'yesno',      label: 'Sì / No' },
        { t: 'heading',    label: 'Titolo / sezione' },
        { t: 'signature',  label: 'Firma' },
        { t: 'photo',      label: 'Foto' },
    ];
    const typeLabel = t => (TYPES.find(x => x.t === t) || {}).label || t;

    function init(cfg) {
        WID = cfg.worksiteId; CSRF = cfg.csrf;
        META = { wsName: cfg.wsName || '', wsCode: cfg.wsCode || '', clientName: cfg.clientName || '' };
        root = document.getElementById('bz-forms-root');
    }

    /** Intestazione documento (foglio) comune a compilazione/anteprima/vista. */
    function docHeader(title, desc, metaLine) {
        return `
            <div class="bzf-doc-head">
                <div class="bzf-doc-brand">BOB Zone${META.wsCode ? ' · ' + esc(META.wsCode) : ''}</div>
                <div class="bzf-doc-title">${esc(title)}</div>
                ${desc ? `<div class="bzf-doc-desc">${esc(desc)}</div>` : ''}
                <div class="bzf-doc-meta">
                    ${META.clientName ? '<span><b>Cliente:</b> ' + esc(META.clientName) + '</span>' : ''}
                    ${META.wsName ? '<span><b>Cantiere:</b> ' + esc(META.wsName) + '</span>' : ''}
                    ${metaLine || ''}
                </div>
            </div>`;
    }

    async function api(url, opts = {}) {
        opts.headers = { 'X-CSRF-Token': CSRF, ...(opts.headers || {}) };
        const r = await fetch(url, { credentials: 'same-origin', ...opts });
        const txt = await r.text();
        try { return JSON.parse(txt); } catch { return { ok: false, error: 'HTTP ' + r.status }; }
    }
    const base = () => `/worksites/${WID}/zone/forms`;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
    const uid = () => 'f' + Math.random().toString(36).slice(2, 8);

    // ── LISTA ───────────────────────────────────────────────────────────────
    async function show() { await loadTemplates(); }

    async function loadTemplates() {
        view = 'list';
        root.innerHTML = '<div class="bz-empty"><i class="fas fa-spinner bz-spin" style="font-size:20px"></i></div>';
        const res = await api(base());
        const tpls = (res.ok && Array.isArray(res.data)) ? res.data : [];
        root.innerHTML = `
            <div class="bzf-bar">
                <div class="bzf-tabs">
                    <button class="bzf-tab active" data-tab="templates">Moduli</button>
                    <button class="bzf-tab" data-tab="submissions">Compilazioni</button>
                </div>
                <button class="bzf-btn-primary" id="bzf-new"><i class="fas fa-plus"></i> Nuovo modulo</button>
            </div>
            <div id="bzf-content"></div>
        `;
        root.querySelector('#bzf-new').addEventListener('click', () => openBuilder(null));
        root.querySelectorAll('.bzf-tab').forEach(b => b.addEventListener('click', () => {
            root.querySelectorAll('.bzf-tab').forEach(x => x.classList.remove('active'));
            b.classList.add('active');
            if (b.dataset.tab === 'submissions') renderSubmissions();
            else renderTemplates(tpls);
        }));
        renderTemplates(tpls);
    }

    function renderTemplates(tpls) {
        const c = root.querySelector('#bzf-content');
        if (!tpls.length) { c.innerHTML = '<div class="bzf-empty">Nessun modulo. Creane uno con "Nuovo modulo".</div>'; return; }
        c.innerHTML = '<div class="bzf-grid">' + tpls.map(t => `
            <div class="bzf-card">
                <div class="bzf-card-top">
                    <span class="bzf-card-name">${esc(t.name)}</span>
                    ${t.worksite_id ? '<span class="bzf-tag">cantiere</span>' : '<span class="bzf-tag universal">universale</span>'}
                </div>
                ${t.description ? `<div class="bzf-card-desc">${esc(t.description)}</div>` : ''}
                <div class="bzf-card-meta">${t.sub_count} compilazioni</div>
                <div class="bzf-card-actions">
                    <button class="bzf-btn-primary" data-fill="${t.id}"><i class="fas fa-pen-to-square"></i> Compila</button>
                    <button class="bzf-btn-ghost" data-edit="${t.id}"><i class="fas fa-sliders"></i></button>
                    <button class="bzf-btn-ghost danger" data-del="${t.id}"><i class="fas fa-trash"></i></button>
                </div>
            </div>`).join('') + '</div>';
        c.querySelectorAll('[data-fill]').forEach(b => b.addEventListener('click', () => openFill(parseInt(b.dataset.fill))));
        c.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => openBuilder(parseInt(b.dataset.edit))));
        c.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', async () => {
            if (!confirm('Eliminare questo modulo? Le compilazioni restano.')) return;
            await api(`${base()}/${b.dataset.del}/delete`, { method: 'POST' });
            loadTemplates();
        }));
    }

    async function renderSubmissions() {
        const c = root.querySelector('#bzf-content');
        c.innerHTML = '<div class="bz-empty"><i class="fas fa-spinner bz-spin"></i></div>';
        const res = await api(`${base()}/submissions`);
        const subs = (res.ok && Array.isArray(res.data)) ? res.data : [];
        if (!subs.length) { c.innerHTML = '<div class="bzf-empty">Nessuna compilazione.</div>'; return; }
        c.innerHTML = '<div class="bzf-sub-list">' + subs.map(s => `
            <div class="bzf-sub-row" data-sub="${s.id}">
                <i class="fas fa-file-lines"></i>
                <div style="flex:1;min-width:0;">
                    <div class="bzf-sub-name">${esc(s.template_name || '—')}</div>
                    <div class="bzf-sub-meta">${esc(s.submitter_name || s.user_name || '—')} · ${fmtDate(s.created_at)}${s.source === 'link' ? ' · link' : ''}</div>
                </div>
                <i class="fas fa-chevron-right"></i>
            </div>`).join('') + '</div>';
        c.querySelectorAll('[data-sub]').forEach(r => r.addEventListener('click', () => openSubmission(parseInt(r.dataset.sub))));
    }

    // ── BUILDER ─────────────────────────────────────────────────────────────
    async function openBuilder(id) {
        view = 'builder';
        if (id) {
            const res = await api(`${base()}/${id}`);
            if (!res.ok) { alert('Errore'); return; }
            builder = { id, name: res.data.name, description: res.data.description || '',
                        universal: !res.data.worksite_id, fields: res.data.fields || [] };
        } else {
            builder = { id: null, name: '', description: '', universal: false, fields: [] };
        }
        renderBuilder();
    }

    function renderBuilder() {
        root.innerHTML = `
            <div class="bzf-bar">
                <button class="bzf-btn-ghost" id="bzf-back"><i class="fas fa-arrow-left"></i> Indietro</button>
                <div style="display:flex;gap:8px;">
                    <button class="bzf-btn-ghost" id="bzf-preview"><i class="fas fa-eye"></i> Anteprima</button>
                    <button class="bzf-btn-primary" id="bzf-save"><i class="fas fa-floppy-disk"></i> Salva modulo</button>
                </div>
            </div>
            <div class="bzf-builder">
                <div class="bzf-field">
                    <label>Nome modulo *</label>
                    <input id="bzf-name" value="${esc(builder.name)}" placeholder="Es. Verbale di consegna">
                </div>
                <div class="bzf-field">
                    <label>Descrizione</label>
                    <input id="bzf-desc" value="${esc(builder.description)}" placeholder="Opzionale">
                </div>
                <label class="bzf-check"><input type="checkbox" id="bzf-univ" ${builder.universal ? 'checked' : ''}> Template universale (riusabile su ogni cantiere)</label>

                <div class="bzf-fields" id="bzf-fields"></div>

                <div class="bzf-addfield">
                    <select id="bzf-addtype">${TYPES.map(t => `<option value="${t.t}">${t.label}</option>`).join('')}</select>
                    <button class="bzf-btn-ghost" id="bzf-add"><i class="fas fa-plus"></i> Aggiungi campo</button>
                </div>
            </div>
        `;
        root.querySelector('#bzf-back').addEventListener('click', loadTemplates);
        root.querySelector('#bzf-save').addEventListener('click', saveBuilder);
        root.querySelector('#bzf-preview').addEventListener('click', () => {
            // sincronizza nome/desc dai campi prima dell'anteprima
            builder.name = root.querySelector('#bzf-name').value.trim() || '(senza nome)';
            builder.description = root.querySelector('#bzf-desc').value.trim();
            openPreview();
        });
        root.querySelector('#bzf-add').addEventListener('click', () => {
            const type = root.querySelector('#bzf-addtype').value;
            const f = { id: uid(), type, label: '', required: false };
            if (TYPES.find(x => x.t === type)?.opts) f.options = ['Opzione 1'];
            builder.fields.push(f);
            renderFieldsEditor();
        });
        renderFieldsEditor();
    }

    function renderFieldsEditor() {
        const el = root.querySelector('#bzf-fields');
        if (!builder.fields.length) { el.innerHTML = '<div class="bzf-empty">Nessun campo. Aggiungine sotto.</div>'; return; }
        el.innerHTML = builder.fields.map((f, i) => `
            <div class="bzf-fedit" data-i="${i}">
                <div class="bzf-fedit-head">
                    <span class="bzf-ftype">${typeLabel(f.type)}</span>
                    <div class="bzf-fedit-ord">
                        <button data-up="${i}" ${i === 0 ? 'disabled' : ''}>↑</button>
                        <button data-down="${i}" ${i === builder.fields.length - 1 ? 'disabled' : ''}>↓</button>
                        <button data-rm="${i}" class="danger">✕</button>
                    </div>
                </div>
                <input class="bzf-flabel" data-lbl="${i}" value="${esc(f.label)}" placeholder="${f.type === 'heading' ? 'Testo del titolo' : 'Etichetta campo'}">
                ${TYPES.find(x => x.t === f.type)?.opts ? `
                    <textarea class="bzf-fopts" data-opts="${i}" rows="2" placeholder="Una opzione per riga">${(f.options || []).join('\n')}</textarea>` : ''}
                ${f.type !== 'heading' ? `<label class="bzf-check small"><input type="checkbox" data-req="${i}" ${f.required ? 'checked' : ''}> Obbligatorio</label>` : ''}
            </div>`).join('');
        // bind
        el.querySelectorAll('[data-lbl]').forEach(inp => inp.addEventListener('input', e => { builder.fields[+e.target.dataset.lbl].label = e.target.value; }));
        el.querySelectorAll('[data-opts]').forEach(inp => inp.addEventListener('input', e => { builder.fields[+e.target.dataset.opts].options = e.target.value.split('\n').map(s => s.trim()).filter(Boolean); }));
        el.querySelectorAll('[data-req]').forEach(inp => inp.addEventListener('change', e => { builder.fields[+e.target.dataset.req].required = e.target.checked; }));
        el.querySelectorAll('[data-up]').forEach(b => b.addEventListener('click', () => { const i = +b.dataset.up; [builder.fields[i-1], builder.fields[i]] = [builder.fields[i], builder.fields[i-1]]; renderFieldsEditor(); }));
        el.querySelectorAll('[data-down]').forEach(b => b.addEventListener('click', () => { const i = +b.dataset.down; [builder.fields[i+1], builder.fields[i]] = [builder.fields[i], builder.fields[i+1]]; renderFieldsEditor(); }));
        el.querySelectorAll('[data-rm]').forEach(b => b.addEventListener('click', () => { builder.fields.splice(+b.dataset.rm, 1); renderFieldsEditor(); }));
    }

    async function saveBuilder() {
        builder.name = root.querySelector('#bzf-name').value.trim();
        builder.description = root.querySelector('#bzf-desc').value.trim();
        builder.universal = root.querySelector('#bzf-univ').checked;
        if (!builder.name) { alert('Inserisci il nome del modulo'); return; }
        if (!builder.fields.length) { alert('Aggiungi almeno un campo'); return; }
        const res = await api(base(), {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(builder),
        });
        if (res.ok) loadTemplates();
        else alert('Errore: ' + (res.error || 'salvataggio'));
    }

    function openPreview() {
        root.innerHTML = `
            <div class="bzf-bar">
                <button class="bzf-btn-ghost" id="bzf-back"><i class="fas fa-arrow-left"></i> Torna al builder</button>
                <span class="bzf-preview-tag">Anteprima — i campi non si salvano</span>
            </div>
            <div class="bzf-paper-wrap">
              <div class="bzf-paper">
                ${docHeader(builder.name, builder.description, '<span><b>Data:</b> ' + new Date().toLocaleDateString('it-IT') + '</span>')}
                <div class="bzf-field"><label>Compilato da</label><input disabled placeholder="Nome di chi compila"></div>
                ${builder.fields.map(renderFillField).join('')}
              </div>
            </div>
        `;
        root.querySelector('#bzf-back').addEventListener('click', renderBuilder);
    }

    // ── FILLER ──────────────────────────────────────────────────────────────
    async function openFill(tplId) {
        const res = await api(`${base()}/${tplId}`);
        if (!res.ok) { alert('Errore'); return; }
        const tpl = res.data;
        view = 'fill';
        signaturePads = {};
        const today = new Date().toLocaleDateString('it-IT');
        root.innerHTML = `
            <div class="bzf-bar">
                <button class="bzf-btn-ghost" id="bzf-back"><i class="fas fa-arrow-left"></i> Indietro</button>
                <button class="bzf-btn-primary" id="bzf-submit"><i class="fas fa-paper-plane"></i> Invia</button>
            </div>
            <div class="bzf-paper-wrap">
              <div class="bzf-paper">
                ${docHeader(tpl.name, tpl.description, '<span><b>Data:</b> ' + today + '</span>')}
                <div class="bzf-field"><label>Compilato da</label><input id="bzf-submitter" placeholder="Nome di chi compila"></div>
                <div id="bzf-fillfields">${tpl.fields.map(renderFillField).join('')}</div>
              </div>
            </div>
        `;
        root.querySelector('#bzf-back').addEventListener('click', loadTemplates);
        root.querySelector('#bzf-submit').addEventListener('click', () => submitFill(tpl));
        // init firma + foto
        tpl.fields.forEach(f => {
            if (f.type === 'signature') initSignature(f.id);
            if (f.type === 'photo') initPhoto(f.id);
        });
    }

    function renderFillField(f) {
        const req = f.required ? '<span style="color:#f87171">*</span>' : '';
        const L = `<label>${esc(f.label)} ${req}</label>`;
        switch (f.type) {
            case 'heading':   return `<h3 class="bzf-heading">${esc(f.label)}</h3>`;
            case 'text':      return `<div class="bzf-field">${L}<input data-fid="${f.id}"></div>`;
            case 'number':    return `<div class="bzf-field">${L}<input type="number" data-fid="${f.id}"></div>`;
            case 'date':      return `<div class="bzf-field">${L}<input type="date" data-fid="${f.id}"></div>`;
            case 'textarea':  return `<div class="bzf-field">${L}<textarea rows="3" data-fid="${f.id}"></textarea></div>`;
            case 'select':    return `<div class="bzf-field">${L}<select data-fid="${f.id}"><option value="">—</option>${(f.options||[]).map(o => `<option>${esc(o)}</option>`).join('')}</select></div>`;
            case 'yesno':     return `<div class="bzf-field">${L}<select data-fid="${f.id}"><option value="">—</option><option>Sì</option><option>No</option></select></div>`;
            case 'radio':     return `<div class="bzf-field">${L}<div class="bzf-opts">${(f.options||[]).map((o,i) => `<label><input type="radio" name="${f.id}" data-fid="${f.id}" value="${esc(o)}"> ${esc(o)}</label>`).join('')}</div></div>`;
            case 'checkboxes':return `<div class="bzf-field">${L}<div class="bzf-opts">${(f.options||[]).map(o => `<label><input type="checkbox" data-cb="${f.id}" value="${esc(o)}"> ${esc(o)}</label>`).join('')}</div></div>`;
            case 'signature': return `<div class="bzf-field">${L}<div class="bzf-sign"><canvas id="sig-${f.id}" width="600" height="180"></canvas><button type="button" class="bzf-btn-ghost" data-sigclear="${f.id}">Cancella</button></div></div>`;
            case 'photo':     return `<div class="bzf-field">${L}<input type="file" accept="image/*" capture="environment" data-photo="${f.id}"><img class="bzf-photo-prev" id="pp-${f.id}" style="display:none;"></div>`;
            default:          return '';
        }
    }

    function initSignature(fid) {
        const canvas = document.getElementById('sig-' + fid);
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        ctx.strokeStyle = '#0f172a'; ctx.lineWidth = 2; ctx.lineCap = 'round';
        const pad = { canvas, ctx, drawing: false, dirty: false };
        signaturePads[fid] = pad;
        const pos = e => { const r = canvas.getBoundingClientRect(); const t = e.touches ? e.touches[0] : e; return { x: (t.clientX - r.left) * (canvas.width / r.width), y: (t.clientY - r.top) * (canvas.height / r.height) }; };
        const down = e => { e.preventDefault(); pad.drawing = true; pad.dirty = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); };
        const move = e => { if (!pad.drawing) return; e.preventDefault(); const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); };
        const up = () => { pad.drawing = false; };
        canvas.addEventListener('mousedown', down); canvas.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
        canvas.addEventListener('touchstart', down, { passive: false }); canvas.addEventListener('touchmove', move, { passive: false }); canvas.addEventListener('touchend', up);
        document.querySelector(`[data-sigclear="${fid}"]`)?.addEventListener('click', () => { ctx.clearRect(0, 0, canvas.width, canvas.height); pad.dirty = false; });
    }

    function initPhoto(fid) {
        const inp = document.querySelector(`[data-photo="${fid}"]`);
        const prev = document.getElementById('pp-' + fid);
        inp?.addEventListener('change', () => {
            const file = inp.files?.[0]; if (!file) return;
            const reader = new FileReader();
            reader.onload = () => { prev.src = reader.result; prev.style.display = 'block'; prev.dataset.val = reader.result; };
            reader.readAsDataURL(file);
        });
    }

    async function submitFill(tpl) {
        const values = {};
        for (const f of tpl.fields) {
            if (f.type === 'heading') continue;
            let v = null;
            if (f.type === 'checkboxes') {
                v = [...root.querySelectorAll(`[data-cb="${f.id}"]:checked`)].map(x => x.value);
            } else if (f.type === 'radio') {
                v = root.querySelector(`[data-fid="${f.id}"]:checked`)?.value || '';
            } else if (f.type === 'signature') {
                const pad = signaturePads[f.id];
                v = (pad && pad.dirty) ? pad.canvas.toDataURL('image/png') : '';
            } else if (f.type === 'photo') {
                v = document.getElementById('pp-' + f.id)?.dataset.val || '';
            } else {
                v = root.querySelector(`[data-fid="${f.id}"]`)?.value || '';
            }
            if (f.required && (v === '' || (Array.isArray(v) && !v.length))) {
                alert('Campo obbligatorio: ' + (f.label || f.type)); return;
            }
            values[f.id] = v;
        }
        const submitter = root.querySelector('#bzf-submitter')?.value.trim() || '';
        const btn = root.querySelector('#bzf-submit'); btn.disabled = true; btn.textContent = 'Invio...';
        const res = await api(`${base()}/${tpl.id}/submit`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ values, submitter_name: submitter }),
        });
        if (res.ok) { alert('Modulo inviato!'); loadTemplates(); }
        else { alert('Errore: ' + (res.error || 'invio')); btn.disabled = false; btn.textContent = 'Invia'; }
    }

    // ── VISUALIZZA COMPILAZIONE ─────────────────────────────────────────────
    async function openSubmission(subId) {
        const res = await api(`${base()}/submission/${subId}`);
        if (!res.ok) { alert('Errore'); return; }
        const s = res.data;
        root.innerHTML = `
            <div class="bzf-bar">
                <button class="bzf-btn-ghost" id="bzf-back"><i class="fas fa-arrow-left"></i> Indietro</button>
                <button class="bzf-btn-primary" id="bzf-print"><i class="fas fa-print"></i> Stampa / PDF</button>
            </div>
            <div class="bzf-paper-wrap">
              <div class="bzf-paper" id="bzf-print-area">
                ${docHeader(s.template_name || '', '', '<span><b>Compilato da:</b> ' + esc(s.submitter_name || '—') + '</span><span><b>Data:</b> ' + fmtDate(s.created_at) + '</span>')}
                ${(s.fields || []).map(f => renderSubmissionField(f, s.values[f.id])).join('')}
              </div>
            </div>
        `;
        root.querySelector('#bzf-back').addEventListener('click', () => { loadTemplates().then(() => root.querySelector('[data-tab="submissions"]')?.click()); });
        root.querySelector('#bzf-print').addEventListener('click', () => window.print());
    }

    function renderSubmissionField(f, val) {
        if (f.type === 'heading') return `<h3 class="bzf-heading">${esc(f.label)}</h3>`;
        let display = '';
        if (f.type === 'signature' || f.type === 'photo') {
            display = val ? `<img class="bzf-sub-img" src="${esc(val)}">` : '<span class="bzf-muted">—</span>';
        } else if (Array.isArray(val)) {
            display = val.length ? esc(val.join(', ')) : '<span class="bzf-muted">—</span>';
        } else {
            display = (val !== '' && val != null) ? esc(val) : '<span class="bzf-muted">—</span>';
        }
        return `<div class="bzf-sub-field"><div class="bzf-sub-label">${esc(f.label)}</div><div class="bzf-sub-val">${display}</div></div>`;
    }

    function fmtDate(s) {
        if (!s) return '';
        const d = new Date(s.replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    window.BZForms = { init, show, loadTemplates };
})();
