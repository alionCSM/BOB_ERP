/*
 * BOB Zone — Annotator
 * Viewer interattivo dei disegni: pin→task, misure, markup (frecce, rettangoli,
 * ellissi, nuvole, testo, freehand). Coordinate normalizzate sulla larghezza.
 *
 * Esposto come window.BZAnnotator.open({worksiteId, csrf, docId, fileType,
 * fileName, viewUrl}).
 */
(function () {
    'use strict';

    const SCALE = 1000; // unità viewBox = norm * 1000
    const COLORS = ['#ef4444', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7', '#0f172a'];

    let S = null; // stato corrente

    // ── DOM build (una sola volta) ──────────────────────────────────────────
    let dom = null;
    function buildDom() {
        if (dom) return dom;
        const ov = document.createElement('div');
        ov.className = 'bza-overlay';
        ov.innerHTML = `
            <div class="bza-top">
                <button class="bza-close" data-act="close">&times;</button>
                <span class="bza-title" data-el="title"></span>
                <div class="bza-tools" data-el="tools">
                    <button class="bza-tool active" data-tool="select" title="Seleziona"><i class="fas fa-mouse-pointer"></i></button>
                    <button class="bza-tool" data-tool="pin" title="Pin / Task"><i class="fas fa-map-marker-alt"></i></button>
                    <button class="bza-tool" data-tool="measure" title="Misura"><i class="fas fa-ruler"></i></button>
                    <button class="bza-tool" data-tool="arrow" title="Freccia"><i class="fas fa-long-arrow-alt-right"></i></button>
                    <button class="bza-tool" data-tool="rectangle" title="Rettangolo"><i class="far fa-square"></i></button>
                    <button class="bza-tool" data-tool="ellipse" title="Ellisse"><i class="far fa-circle"></i></button>
                    <button class="bza-tool" data-tool="cloud" title="Nuvola"><i class="fas fa-cloud"></i></button>
                    <button class="bza-tool" data-tool="text" title="Testo"><i class="fas fa-font"></i></button>
                    <button class="bza-tool" data-tool="draw" title="Disegno libero"><i class="fas fa-pen"></i></button>
                </div>
                <div class="bza-color-swatches" data-el="swatches"></div>
                <div class="bza-top-right">
                    <button class="bza-btn bza-btn-ghost" data-act="calibrate"><i class="fas fa-ruler-combined"></i> Calibra</button>
                    <div class="bza-page-nav" data-el="pagenav" style="display:none;">
                        <button data-act="prev">&lsaquo;</button>
                        <span data-el="pagelabel">1/1</span>
                        <button data-act="next">&rsaquo;</button>
                    </div>
                    <div class="bza-zoom">
                        <button data-act="zoomout">&minus;</button>
                        <button data-act="zoomin">+</button>
                    </div>
                </div>
            </div>
            <div class="bza-body">
                <div class="bza-stage-wrap" data-el="stagewrap">
                    <div class="bza-loading" data-el="loading">Caricamento disegno...</div>
                    <div class="bza-stage" data-el="stage" style="display:none;">
                        <div data-el="page"></div>
                        <svg class="bza-svg tool-select" data-el="svg" xmlns="http://www.w3.org/2000/svg"></svg>
                    </div>
                </div>
                <div class="bza-side" data-el="side"></div>
            </div>
        `;
        document.body.appendChild(ov);
        dom = {
            ov,
            title:     ov.querySelector('[data-el="title"]'),
            tools:     ov.querySelector('[data-el="tools"]'),
            swatches:  ov.querySelector('[data-el="swatches"]'),
            stagewrap: ov.querySelector('[data-el="stagewrap"]'),
            stage:     ov.querySelector('[data-el="stage"]'),
            page:      ov.querySelector('[data-el="page"]'),
            svg:       ov.querySelector('[data-el="svg"]'),
            side:      ov.querySelector('[data-el="side"]'),
            loading:   ov.querySelector('[data-el="loading"]'),
            pagenav:   ov.querySelector('[data-el="pagenav"]'),
            pagelabel: ov.querySelector('[data-el="pagelabel"]'),
        };
        wireDom();
        return dom;
    }

    function wireDom() {
        dom.ov.addEventListener('click', e => {
            const act = e.target.closest('[data-act]')?.dataset.act;
            if (act === 'close')    close();
            if (act === 'zoomin')   setZoom(S.zoom * 1.25);
            if (act === 'zoomout')  setZoom(S.zoom / 1.25);
            if (act === 'prev')     gotoPage(S.page - 1);
            if (act === 'next')     gotoPage(S.page + 1);
            if (act === 'calibrate') startCalibration();
            const tool = e.target.closest('[data-tool]')?.dataset.tool;
            if (tool) setTool(tool);
        });
        // swatches
        COLORS.forEach((c, i) => {
            const sw = document.createElement('div');
            sw.className = 'bza-swatch' + (i === 0 ? ' active' : '');
            sw.style.background = c;
            sw.dataset.color = c;
            sw.addEventListener('click', () => {
                S.color = c;
                dom.swatches.querySelectorAll('.bza-swatch').forEach(x => x.classList.toggle('active', x.dataset.color === c));
            });
            dom.swatches.appendChild(sw);
        });
        // SVG pointer events
        dom.svg.addEventListener('pointerdown', onPointerDown);
        dom.svg.addEventListener('pointermove', onPointerMove);
        dom.svg.addEventListener('pointerup', onPointerUp);
        // ESC chiude
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && S && dom.ov.classList.contains('open')) close();
        });
    }

    // ── API ───────────────────────────────────────────────────────────────────
    async function api(url, opts = {}) {
        opts.headers = { 'X-CSRF-Token': S.csrf, ...(opts.headers || {}) };
        const r = await fetch(url, { credentials: 'same-origin', ...opts });
        const txt = await r.text();
        try { return JSON.parse(txt); }
        catch { console.error('[annotator] non-JSON', url, txt.slice(0, 300)); return { ok: false, error: 'HTTP ' + r.status }; }
    }
    const base = () => `/worksites/${S.worksiteId}/zone/disegni/${S.docId}`;

    // ── Open / close ────────────────────────────────────────────────────────
    async function open(cfg) {
        buildDom();
        S = {
            ...cfg,
            tool: 'select', color: COLORS[0], zoom: 1,
            page: 1, pageCount: 1,
            pdf: null, natW: 0, natH: 0,
            annotations: [], calibration: null,
            drawing: null, // stato disegno in corso
        };
        dom.title.textContent = cfg.fileName || 'Disegno';
        dom.ov.classList.add('open');
        dom.stage.style.display = 'none';
        dom.loading.style.display = 'block';
        dom.side.classList.remove('open');
        setTool('select');

        if ((cfg.fileType || '').toLowerCase() === 'pdf') {
            await loadPdf();
        } else {
            await loadImage();
        }
        await loadAnnotations();
    }

    function close() {
        dom.ov.classList.remove('open');
        dom.page.innerHTML = '';
        dom.svg.innerHTML = '';
        S = null;
    }

    // ── Rendering ──────────────────────────────────────────────────────────────
    async function loadImage() {
        return new Promise(resolve => {
            const img = new Image();
            img.onload = () => {
                S.natW = img.naturalWidth; S.natH = img.naturalHeight;
                S.pageCount = 1;
                dom.page.innerHTML = '';
                img.style.width = '100%';
                dom.page.appendChild(img);
                finishRender();
                resolve();
            };
            img.onerror = () => { dom.loading.textContent = 'Impossibile caricare l\'immagine.'; resolve(); };
            img.src = S.viewUrl;
        });
    }

    async function loadPdf() {
        if (!window.pdfjsLib) { dom.loading.textContent = 'PDF.js non disponibile.'; return; }
        try {
            S.pdf = await window.pdfjsLib.getDocument(S.viewUrl).promise;
            S.pageCount = S.pdf.numPages;
            await renderPdfPage(S.page);
        } catch (e) {
            console.error(e);
            dom.loading.textContent = 'Impossibile aprire il PDF.';
        }
    }

    async function renderPdfPage(num) {
        const pg = await S.pdf.getPage(num);
        const vp1 = pg.getViewport({ scale: 1 });
        S.natW = vp1.width; S.natH = vp1.height;
        // render ad alta risoluzione per nitidezza
        const renderScale = 2;
        const vp = pg.getViewport({ scale: renderScale });
        const canvas = document.createElement('canvas');
        canvas.width = vp.width; canvas.height = vp.height;
        canvas.style.width = '100%';
        const ctx = canvas.getContext('2d');
        await pg.render({ canvasContext: ctx, viewport: vp }).promise;
        dom.page.innerHTML = '';
        dom.page.appendChild(canvas);
        finishRender();
    }

    function finishRender() {
        // dimensione base in px = larghezza naturale limitata, * zoom
        applyStageSize();
        dom.loading.style.display = 'none';
        dom.stage.style.display = 'inline-block';
        // viewBox normalizzato (larghezza = SCALE)
        const vbH = SCALE * (S.natH / S.natW);
        dom.svg.setAttribute('viewBox', `0 0 ${SCALE} ${vbH}`);
        dom.pagenav.style.display = S.pageCount > 1 ? 'flex' : 'none';
        dom.pagelabel.textContent = `${S.page}/${S.pageCount}`;
        redraw();
    }

    function applyStageSize() {
        const wrapW = dom.stagewrap.clientWidth - 48;
        const baseW = Math.min(wrapW, S.natW); // non oltre la larghezza naturale
        const w = Math.max(200, baseW * S.zoom);
        dom.stage.style.width = w + 'px';
    }

    function setZoom(z) {
        S.zoom = Math.max(0.25, Math.min(6, z));
        applyStageSize();
    }

    async function gotoPage(n) {
        if (!S.pdf || n < 1 || n > S.pageCount) return;
        S.page = n;
        dom.loading.style.display = 'block';
        dom.stage.style.display = 'none';
        await renderPdfPage(n);
        await loadAnnotations();
    }

    // ── Annotazioni: load / draw ───────────────────────────────────────────────
    async function loadAnnotations() {
        const res = await api(`${base()}/annotations?page=${S.page}`);
        if (res.ok) {
            S.annotations = res.data.annotations || [];
            S.calibration = res.data.calibration || null;
        }
        redraw();
    }

    function redraw() {
        const vbH = SCALE * (S.natH / S.natW);
        let svg = '';
        // defs per le frecce
        svg += `<defs><marker id="bza-arrow" markerWidth="10" markerHeight="10" refX="8" refY="3" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L8,3 L0,6 Z" fill="context-stroke"/></marker></defs>`;
        let pinNum = 0;
        S.annotations.forEach(a => {
            const g = a.geom || {};
            const col = a.color || '#ef4444';
            if (a.type === 'pin') {
                pinNum++;
                const x = g.x * SCALE, y = g.y * SCALE;
                svg += `<g class="bza-pin" data-ann="${a.id}">
                    <path d="M${x},${y} c-9,-20 -16,-26 -16,-40 a16,16 0 1,1 32,0 c0,14 -7,20 -16,40 z" fill="${col}" stroke="#fff" stroke-width="2"/>
                    <circle cx="${x}" cy="${y-40}" r="11" fill="#fff"/>
                    <text class="bza-pin-label" x="${x}" y="${y-36}" text-anchor="middle" fill="${col}">${pinNum}</text>
                </g>`;
            } else if (a.type === 'measure') {
                const a1 = g.a, b1 = g.b;
                if (a1 && b1) {
                    const x1=a1.x*SCALE,y1=a1.y*SCALE,x2=b1.x*SCALE,y2=b1.y*SCALE;
                    const mx=(x1+x2)/2, my=(y1+y2)/2;
                    svg += `<g data-ann="${a.id}"><line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${col}" stroke-width="3"/>
                        <circle cx="${x1}" cy="${y1}" r="4" fill="${col}"/><circle cx="${x2}" cy="${y2}" r="4" fill="${col}"/>
                        <rect x="${mx-34}" y="${my-22}" width="68" height="18" rx="4" fill="#0f172a"/>
                        <text x="${mx}" y="${my-9}" text-anchor="middle" font-size="14" fill="#fff">${g.m != null ? g.m.toFixed(2)+' m' : '?'}</text></g>`;
                }
            } else if (a.type === 'arrow') {
                const a1=g.a,b1=g.b;
                if (a1&&b1) svg += `<line data-ann="${a.id}" x1="${a1.x*SCALE}" y1="${a1.y*SCALE}" x2="${b1.x*SCALE}" y2="${b1.y*SCALE}" stroke="${col}" stroke-width="4" marker-end="url(#bza-arrow)"/>`;
            } else if (a.type === 'rectangle') {
                svg += `<rect data-ann="${a.id}" x="${g.x*SCALE}" y="${g.y*SCALE}" width="${g.w*SCALE}" height="${g.h*SCALE}" fill="none" stroke="${col}" stroke-width="3"/>`;
            } else if (a.type === 'ellipse') {
                const cx=(g.x+g.w/2)*SCALE, cy=(g.y+g.h/2)*SCALE;
                svg += `<ellipse data-ann="${a.id}" cx="${cx}" cy="${cy}" rx="${Math.abs(g.w/2)*SCALE}" ry="${Math.abs(g.h/2)*SCALE}" fill="none" stroke="${col}" stroke-width="3"/>`;
            } else if (a.type === 'cloud') {
                svg += `<rect data-ann="${a.id}" x="${g.x*SCALE}" y="${g.y*SCALE}" width="${g.w*SCALE}" height="${g.h*SCALE}" rx="14" fill="none" stroke="${col}" stroke-width="3" stroke-dasharray="14 6"/>`;
            } else if (a.type === 'text') {
                svg += `<text data-ann="${a.id}" x="${g.x*SCALE}" y="${g.y*SCALE}" font-size="20" font-weight="700" fill="${col}">${escapeXml(a.text || '')}</text>`;
            } else if (a.type === 'drawing' && Array.isArray(g.pts)) {
                const d = g.pts.map((p, i) => `${i ? 'L' : 'M'}${p[0]*SCALE},${p[1]*SCALE}`).join(' ');
                svg += `<path data-ann="${a.id}" d="${d}" fill="none" stroke="${col}" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>`;
            }
        });
        // disegno in corso (preview)
        if (S.drawing) svg += S.drawing.preview || '';
        dom.svg.innerHTML = svg;
    }

    // ── Tool + pointer ─────────────────────────────────────────────────────────
    function setTool(t) {
        S.tool = t;
        dom.tools.querySelectorAll('.bza-tool').forEach(b => b.classList.toggle('active', b.dataset.tool === t));
        dom.svg.classList.toggle('tool-select', t === 'select');
    }

    function ptNorm(e) {
        const r = dom.svg.getBoundingClientRect();
        return { x: (e.clientX - r.left) / r.width, y: (e.clientY - r.top) / r.width };
    }

    function onPointerDown(e) {
        if (!S) return;
        const p = ptNorm(e);

        // SELECT: click su annotazione esistente
        if (S.tool === 'select') {
            const annEl = e.target.closest('[data-ann]');
            if (annEl) openAnnPanel(parseInt(annEl.dataset.ann));
            return;
        }

        // PIN: click singolo → pannello pin
        if (S.tool === 'pin') { openPinCreate(p); return; }

        // TEXT: click → prompt
        if (S.tool === 'text') {
            const txt = prompt('Testo annotazione:');
            if (txt && txt.trim()) saveAnn({ type: 'text', geom: { x: p.x, y: p.y }, text: txt.trim() });
            return;
        }

        // strumenti drag-based
        S.drawing = { tool: S.tool, start: p, pts: [[p.x, p.y]], preview: '' };
        dom.svg.setPointerCapture(e.pointerId);
    }

    function onPointerMove(e) {
        if (!S || !S.drawing) return;
        const p = ptNorm(e);
        const d = S.drawing, col = S.color;
        const x1 = d.start.x * SCALE, y1 = d.start.y * SCALE, x2 = p.x * SCALE, y2 = p.y * SCALE;
        if (d.tool === 'measure') {
            d.preview = `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${col}" stroke-width="3" stroke-dasharray="6 4"/>`;
        } else if (d.tool === 'arrow') {
            d.preview = `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${col}" stroke-width="4" marker-end="url(#bza-arrow)"/>`;
        } else if (d.tool === 'rectangle' || d.tool === 'cloud') {
            const rx = d.tool === 'cloud' ? 14 : 0;
            d.preview = `<rect x="${Math.min(x1,x2)}" y="${Math.min(y1,y2)}" width="${Math.abs(x2-x1)}" height="${Math.abs(y2-y1)}" rx="${rx}" fill="none" stroke="${col}" stroke-width="3" ${d.tool==='cloud'?'stroke-dasharray="14 6"':''}/>`;
        } else if (d.tool === 'ellipse') {
            d.preview = `<ellipse cx="${(x1+x2)/2}" cy="${(y1+y2)/2}" rx="${Math.abs(x2-x1)/2}" ry="${Math.abs(y2-y1)/2}" fill="none" stroke="${col}" stroke-width="3"/>`;
        } else if (d.tool === 'draw') {
            d.pts.push([p.x, p.y]);
            const path = d.pts.map((q,i)=>`${i?'L':'M'}${q[0]*SCALE},${q[1]*SCALE}`).join(' ');
            d.preview = `<path d="${path}" fill="none" stroke="${col}" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>`;
        }
        redraw();
    }

    function onPointerUp(e) {
        if (!S || !S.drawing) return;
        const p = ptNorm(e);
        const d = S.drawing; S.drawing = null;
        const a = d.start, b = p;
        const tiny = Math.abs(b.x - a.x) < 0.005 && Math.abs(b.y - a.y) < 0.005;

        if (d.tool === 'measure') {
            if (tiny) { redraw(); return; }
            const m = measureMeters(a, b);
            saveAnn({ type: 'measure', geom: { a, b, m } });
        } else if (d.tool === 'arrow') {
            if (tiny) { redraw(); return; }
            saveAnn({ type: 'arrow', geom: { a, b } });
        } else if (d.tool === 'rectangle' || d.tool === 'cloud') {
            if (tiny) { redraw(); return; }
            saveAnn({ type: d.tool, geom: { x: Math.min(a.x,b.x), y: Math.min(a.y,b.y), w: Math.abs(b.x-a.x), h: Math.abs(b.y-a.y) } });
        } else if (d.tool === 'ellipse') {
            if (tiny) { redraw(); return; }
            saveAnn({ type: 'ellipse', geom: { x: Math.min(a.x,b.x), y: Math.min(a.y,b.y), w: Math.abs(b.x-a.x), h: Math.abs(b.y-a.y) } });
        } else if (d.tool === 'draw') {
            if (d.pts.length < 2) { redraw(); return; }
            saveAnn({ type: 'drawing', geom: { pts: d.pts } });
        }
    }

    // ── Misure / calibrazione ────────────────────────────────────────────────
    function normDist(a, b) { return Math.hypot(b.x - a.x, b.y - a.y); } // in unità width-frazione
    function measureMeters(a, b) {
        if (!S.calibration) return null;
        return normDist(a, b) * S.calibration.m_per_wfrac;
    }

    function startCalibration() {
        setTool('select');
        alert('Calibrazione: traccia una linea su una misura nota, poi inserisci la lunghezza reale in metri.');
        // riusa lo strumento measure ma in modalità calibrazione
        S.calMode = true;
        setTool('measure');
        // override one-shot: il prossimo measure chiede la lunghezza reale
        S._calOnce = true;
    }

    // ── Pannello pin / annotazione ──────────────────────────────────────────────
    async function openPinCreate(p) {
        dom.side.classList.add('open');
        // carica i task del cantiere per il collegamento
        const tasksRes = await api(`/worksites/${S.worksiteId}/zone/tasks`);
        const tasks = (tasksRes.ok && Array.isArray(tasksRes.data)) ? tasksRes.data : [];
        dom.side.innerHTML = `
            <h4><i class="fas fa-map-marker-alt"></i> Nuovo pin</h4>
            <div class="bza-field">
                <label>Tipo</label>
                <select data-el="pinmode">
                    <option value="new">Crea nuovo task qui</option>
                    <option value="link">Collega task esistente</option>
                    <option value="note">Solo nota (no task)</option>
                </select>
            </div>
            <div data-el="pin-new">
                <div class="bza-field"><label>Nome task</label><input data-el="pin-name" placeholder="Es. Montare staffa"></div>
                <div class="bza-field"><label>Assegnato a (opzionale)</label><input data-el="pin-assignee" placeholder="Nome"></div>
            </div>
            <div data-el="pin-link" style="display:none;">
                <div class="bza-field"><label>Task esistente</label>
                    <select data-el="pin-task">${tasks.map(t=>`<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('')}</select>
                </div>
            </div>
            <div data-el="pin-note" style="display:none;">
                <div class="bza-field"><label>Nota</label><textarea data-el="pin-text" rows="3"></textarea></div>
            </div>
            <div class="bza-row">
                <button class="bza-btn bza-btn-primary" data-el="pin-save">Salva pin</button>
                <button class="bza-btn bza-btn-ghost" data-el="pin-cancel">Annulla</button>
            </div>
            <div class="bza-hint">Il pin verrà posizionato dove hai cliccato. I task creati appaiono nel Kanban.</div>
        `;
        const mode = dom.side.querySelector('[data-el="pinmode"]');
        const refresh = () => {
            dom.side.querySelector('[data-el="pin-new"]').style.display  = mode.value === 'new'  ? 'block' : 'none';
            dom.side.querySelector('[data-el="pin-link"]').style.display = mode.value === 'link' ? 'block' : 'none';
            dom.side.querySelector('[data-el="pin-note"]').style.display = mode.value === 'note' ? 'block' : 'none';
        };
        mode.addEventListener('change', refresh); refresh();
        dom.side.querySelector('[data-el="pin-cancel"]').addEventListener('click', () => dom.side.classList.remove('open'));
        dom.side.querySelector('[data-el="pin-save"]').addEventListener('click', async () => {
            const payload = { type: 'pin', geom: { x: p.x, y: p.y }, color: S.color };
            if (mode.value === 'new') {
                payload.create_task = true;
                payload.task_name = dom.side.querySelector('[data-el="pin-name"]').value.trim();
                payload.assignee_name = dom.side.querySelector('[data-el="pin-assignee"]').value.trim() || null;
                if (!payload.task_name) { alert('Inserisci il nome del task'); return; }
            } else if (mode.value === 'link') {
                payload.task_id = parseInt(dom.side.querySelector('[data-el="pin-task"]').value);
            } else {
                payload.text = dom.side.querySelector('[data-el="pin-text"]').value.trim();
            }
            await saveAnn(payload);
            dom.side.classList.remove('open');
        });
    }

    async function openAnnPanel(annId) {
        const a = S.annotations.find(x => x.id === annId);
        if (!a) return;
        dom.side.classList.add('open');
        let info = '';
        if (a.type === 'pin' && a.task_id) {
            info = `<div class="bza-field"><label>Task collegato</label>
                <div style="color:#e2e8f0;font-size:13px;">${escapeHtml(a.task_name||'—')}</div>
                <div style="color:#64748b;font-size:12px;">Stato: ${escapeHtml(a.task_status||'—')}${a.task_assignee?' · '+escapeHtml(a.task_assignee):''}</div></div>`;
        } else if (a.text) {
            info = `<div class="bza-field"><label>Nota</label><div style="color:#e2e8f0;font-size:13px;">${escapeHtml(a.text)}</div></div>`;
        } else if (a.type === 'measure' && a.geom?.m != null) {
            info = `<div class="bza-field"><label>Misura</label><div style="color:#e2e8f0;font-size:15px;font-weight:700;">${a.geom.m.toFixed(2)} m</div></div>`;
        }
        dom.side.innerHTML = `
            <h4>${labelFor(a.type)}</h4>
            ${info}
            <div class="bza-row">
                <button class="bza-btn bza-btn-danger" data-el="ann-del"><i class="fas fa-trash"></i> Elimina</button>
                <button class="bza-btn bza-btn-ghost" data-el="ann-close">Chiudi</button>
            </div>
        `;
        dom.side.querySelector('[data-el="ann-close"]').addEventListener('click', () => dom.side.classList.remove('open'));
        dom.side.querySelector('[data-el="ann-del"]').addEventListener('click', async () => {
            if (!confirm('Eliminare questa annotazione?')) return;
            await api(`${base()}/annotations/${annId}/delete`, { method: 'POST' });
            dom.side.classList.remove('open');
            await loadAnnotations();
        });
    }

    // ── Save annotazione ────────────────────────────────────────────────────────
    async function saveAnn(payload) {
        // gestione calibrazione one-shot
        if (S._calOnce && payload.type === 'measure') {
            S._calOnce = false; S.calMode = false;
            const real = prompt('Lunghezza reale di questa linea, in metri:');
            const meters = parseFloat((real || '').replace(',', '.'));
            if (!meters || meters <= 0) { setTool('select'); redraw(); return; }
            const d = normDist(payload.geom.a, payload.geom.b);
            if (d <= 0) { setTool('select'); return; }
            const scale = meters / d;
            await api(`${base()}/calibration`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ page: S.page, m_per_wfrac: scale }),
            });
            S.calibration = { m_per_wfrac: scale };
            setTool('select');
            alert('Scala calibrata: ora le misure mostrano i metri.');
            return;
        }
        payload.page = S.page;
        if (!payload.color) payload.color = S.color;
        const res = await api(`${base()}/annotations`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        if (res.ok) await loadAnnotations();
        else alert('Errore: ' + (res.error || 'salvataggio annotazione'));
    }

    // ── utils ────────────────────────────────────────────────────────────────
    function labelFor(t) {
        return ({ pin:'Pin', measure:'Misura', arrow:'Freccia', rectangle:'Rettangolo',
                  ellipse:'Ellisse', cloud:'Nuvola', text:'Testo', drawing:'Disegno' })[t] || t;
    }
    function escapeHtml(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
    function escapeXml(s){return escapeHtml(s);}

    window.addEventListener('resize', () => { if (S && dom?.ov.classList.contains('open')) applyStageSize(); });

    window.BZAnnotator = { open };
})();
