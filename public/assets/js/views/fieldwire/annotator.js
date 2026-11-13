/*
 * BOB Zone — Annotator
 * Viewer interattivo dei disegni: pin→task, misure, markup (frecce, rettangoli,
 * ellissi, nuvole, testo, freehand). Coordinate normalizzate sulla larghezza.
 *
 * Esposto come window.BZAnnotator.open({worksiteId, csrf, docId, fileType,
 * fileName, viewUrl, ...}). Opzioni:
 *
 *  - onPick(punto): "segna sul disegno" per una nuova attivita'. Si zooma,
 *    si sposta, si mette il punto e si possono disegnare un'area, una
 *    freccia, un testo. Niente viene salvato qui: con "Conferma" torna
 *    {page, x, y, segni:[...]} a chi l'ha aperto, e i segni si salvano
 *    insieme all'attivita'. iniziale: lo stesso oggetto, per riaprirlo.
 *  - evidenzia: {task_id, nome} o {segnalazione_id, nome}. I segni
 *    collegati si evidenziano (gli altri si attenuano) e il disegno si
 *    centra sul primo. page: la pagina da aprire.
 *  - senzaAnnotazioni: si apre il disegno pulito (si possono mostrare dopo).
 *  - solaLettura: niente strumenti, si guarda soltanto.
 */
(function () {
    'use strict';

    const SCALE = 1000; // unità viewBox = norm * 1000
    const COLORS = ['#ef4444', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7', '#0f172a'];
    const EVID = '#facc15';

    let S = null; // stato corrente

    // ── DOM build (una sola volta) ──────────────────────────────────────────
    let dom = null;
    function buildDom() {
        if (dom) return dom;
        const ov = document.createElement('div');
        ov.className = 'bza-overlay';
        ov.innerHTML = `
            <div class="bza-top">
                <button class="bza-close" data-act="close" title="Chiudi">&times;</button>
                <span class="bza-title" data-el="title"></span>
                <div class="bza-tools" data-el="tools">
                    <button class="bza-tool active" data-tool="select" title="Seleziona"><i class="fas fa-mouse-pointer"></i></button>
                    <button class="bza-tool" data-tool="pan" title="Sposta il disegno"><i class="fas fa-hand"></i></button>
                    <button class="bza-tool" data-tool="pin" title="Punto"><i class="fas fa-map-marker-alt"></i></button>
                    <button class="bza-tool" data-tool="measure" title="Misura"><i class="fas fa-ruler"></i></button>
                    <button class="bza-tool" data-tool="arrow" title="Freccia"><i class="fas fa-long-arrow-alt-right"></i></button>
                    <button class="bza-tool" data-tool="rectangle" title="Area rettangolare"><i class="far fa-square"></i></button>
                    <button class="bza-tool" data-tool="ellipse" title="Area ellittica"><i class="far fa-circle"></i></button>
                    <button class="bza-tool" data-tool="cloud" title="Nuvola"><i class="fas fa-cloud"></i></button>
                    <button class="bza-tool" data-tool="text" title="Testo"><i class="fas fa-font"></i></button>
                    <button class="bza-tool" data-tool="draw" title="Disegno libero"><i class="fas fa-pen"></i></button>
                </div>
                <div class="bza-color-swatches" data-el="swatches"></div>
                <div class="bza-top-right">
                    <div class="bza-scarica">
                        <button class="bza-btn bza-btn-ghost" data-act="scarica-menu" title="Scarica il disegno"><i class="fas fa-download"></i> <span>Scarica</span></button>
                        <div class="bza-scarica-menu" data-el="scaricamenu" hidden>
                            <button type="button" data-act="scarica-orig"><i class="far fa-file"></i> Originale</button>
                            <button type="button" data-act="scarica-ann"><i class="fas fa-map-marker-alt"></i> Con annotazioni</button>
                        </div>
                    </div>
                    <button class="bza-btn bza-btn-ghost" data-act="toggleann" data-el="toggleann"><i class="fas fa-eye-slash"></i> <span>Nascondi annotazioni</span></button>
                    <button class="bza-btn bza-btn-ghost" data-act="calibrate"><i class="fas fa-ruler-combined"></i> Calibra</button>
                    <div class="bza-page-nav" data-el="pagenav" style="display:none;">
                        <button data-act="prev" title="Pagina precedente">&lsaquo;</button>
                        <span data-el="pagelabel">1/1</span>
                        <button data-act="next" title="Pagina successiva">&rsaquo;</button>
                    </div>
                    <div class="bza-zoom">
                        <button data-act="zoomout" title="Riduci">&minus;</button>
                        <span class="bza-zoom-val" data-el="zoomval">100%</span>
                        <button data-act="zoomin" title="Ingrandisci">+</button>
                        <button data-act="fit" title="Adatta alla finestra"><i class="fas fa-expand"></i></button>
                    </div>
                </div>
            </div>
            <div class="bza-banner" data-el="banner" hidden></div>
            <div class="bza-body">
                <div class="bza-stage-wrap" data-el="stagewrap">
                    <div class="bza-loading" data-el="loading">Caricamento disegno...</div>
                    <div class="bza-stage" data-el="stage" style="display:none;">
                        <div data-el="page"></div>
                        <div class="bza-dettaglio" data-el="det"></div>
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
            zoomval:   ov.querySelector('[data-el="zoomval"]'),
            banner:    ov.querySelector('[data-el="banner"]'),
            toggleann: ov.querySelector('[data-el="toggleann"]'),
            det:       ov.querySelector('[data-el="det"]'),
            scaricamenu: ov.querySelector('[data-el="scaricamenu"]'),
        };
        wireDom();
        return dom;
    }

    function wireDom() {
        dom.ov.addEventListener('click', e => {
            const el = e.target.closest('[data-act]');
            const act = el?.dataset.act;
            if (act === 'close')     close();
            if (act === 'zoomin')    zoomCentro(S.zoom * 1.25);
            if (act === 'zoomout')   zoomCentro(S.zoom / 1.25);
            if (act === 'fit')       { setZoom(1); dom.stagewrap.scrollTo(0, 0); }
            if (act === 'prev')      gotoPage(S.page - 1);
            if (act === 'next')      gotoPage(S.page + 1);
            if (act === 'calibrate') startCalibration();
            if (act === 'toggleann') mostraAnnotazioni(!S.mostra);
            if (act === 'evid-off')  { S.evid = null; dom.banner.hidden = true; redraw(); }
            if (act === 'apri')      apriCollegato(el.dataset.dove);
            if (act === 'scarica-menu') { dom.scaricamenu.hidden = !dom.scaricamenu.hidden; return; }
            if (act === 'scarica-orig') { dom.scaricamenu.hidden = true; scaricaOriginale(S); }
            if (act === 'scarica-ann')  { dom.scaricamenu.hidden = true; scaricaConAnnotazioni(S); }
            const tool = e.target.closest('[data-tool]')?.dataset.tool;
            if (tool) setTool(tool);
        });
        // swatches
        COLORS.forEach((c, i) => {
            const sw = document.createElement('div');
            sw.className = 'bza-swatch' + (i === 0 ? ' active' : '');
            sw.style.background = c;
            sw.dataset.color = c;
            sw.title = 'Colore';
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
        dom.svg.addEventListener('pointercancel', onPointerUp);
        // zoom con rotella o pinch del trackpad, ancorato al cursore: la
        // rotella fa scatti, il trackpad (ctrlKey) piccoli passi continui
        dom.stagewrap.addEventListener('wheel', e => {
            if (!S || !dom.ov.classList.contains('open')) return;
            e.preventDefault();
            const d = Math.max(-120, Math.min(120, e.deltaMode === 1 ? e.deltaY * 33 : e.deltaY));
            zoomVerso(S.zoom * Math.exp(-d * (e.ctrlKey ? 0.01 : 0.0015)), e.clientX, e.clientY);
        }, { passive: false });
        // doppio clic: ingrandisce (con Maiusc riduce) nel punto cliccato
        dom.svg.addEventListener('dblclick', e => {
            if (!S || !(['select', 'pan'].includes(S.tool) || (!S.mostra && !S.onPick))) return;
            e.preventDefault();
            zoomVerso(S.zoom * (e.shiftKey ? 0.5 : 2), e.clientX, e.clientY);
        });
        // il dettaglio nitido segue lo scorrimento
        dom.stagewrap.addEventListener('scroll', () => programmaDettaglio(), { passive: true });
        // tastiera: Esc chiude, + e - ingrandiscono e riducono, 0 adatta
        document.addEventListener('keydown', e => {
            if (!S || !dom.ov.classList.contains('open')) return;
            if (e.target.matches && e.target.matches('input, textarea, select')) return;
            if (e.key === 'Escape') close();
            else if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomCentro(S.zoom * 1.5); }
            else if (e.key === '-' || e.key === '_') { e.preventDefault(); zoomCentro(S.zoom / 1.5); }
            else if (e.key === '0') { e.preventDefault(); setZoom(1); dom.stagewrap.scrollTo(0, 0); }
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
        const ini = cfg.onPick && cfg.iniziale ? cfg.iniziale : null;
        S = {
            ...cfg,
            tool: 'select', color: cfg.onPick ? COLORS[3] : COLORS[0], zoom: 1,
            page: Math.max(1, parseInt(ini?.page || cfg.page || 1, 10)), pageCount: 1,
            pdf: null, natW: 0, natH: 0,
            annotations: [], calibration: null,
            drawing: null,        // disegno in corso
            pan: null,            // spostamento in corso
            ptrs: new Map(),      // dita sullo schermo (pinch)
            pinch: null,
            mostra: !cfg.senzaAnnotazioni,
            evid: cfg.evidenzia || null,
            centrato: false,
            // scelta del punto per una nuova attivita': tutto in bozza
            bozzaPin: ini ? { x: ini.x, y: ini.y } : null,
            bozza: ini && Array.isArray(ini.segni) ? ini.segni.map(x => ({ ...x })) : [],
        };
        dom.title.textContent = cfg.onPick
            ? 'Posizione dell\'attività · ' + (cfg.fileName || 'Disegno')
            : (cfg.fileName || 'Disegno') + (S.mostra ? '' : ' · senza annotazioni');
        dom.ov.classList.toggle('bza-pick', !!cfg.onPick);
        dom.ov.classList.toggle('bza-sola-lettura', !!cfg.solaLettura && !cfg.onPick);
        dom.ov.classList.add('open');
        dom.stage.style.display = 'none';
        dom.loading.style.display = 'block';
        dom.loading.textContent = 'Caricamento disegno...';
        dom.side.classList.remove('open');
        dom.banner.hidden = true;
        dom.swatches.querySelectorAll('.bza-swatch').forEach(x => x.classList.toggle('active', x.dataset.color === S.color));
        aggiornaToggle();
        setTool(cfg.onPick ? 'pin' : 'select');
        if (cfg.onPick) pannelloScelta();

        const ft = (cfg.fileType || '').toLowerCase();
        if (ft === 'pdf')                      await loadPdf();
        else if (ft === 'dwg' || ft === 'dxf') await loadDwg();
        else                                   await loadImage();
        await loadAnnotations();
    }

    function close() {
        dom.ov.classList.remove('open');
        nascondiDettaglio();
        dom.page.innerHTML = '';
        dom.svg.innerHTML = '';
        dom.banner.hidden = true;
        const dopo = S && S.onClose;
        S = null;
        // chi ha aperto il disegno si aggiorna: un punto collegato a
        // un'attivita' deve comparire subito nell'attivita'
        if (typeof dopo === 'function') { try { dopo(); } catch (_) {} }
    }

    /** Dal pannello di un segno all'attivita' o alla segnalazione collegata. */
    function apriCollegato(dove) {
        if (!dove) return;
        close();
        if (typeof window.vaiAdAvviso === 'function') window.vaiAdAvviso(dove);
        else location.hash = dove;
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
                img.draggable = false;
                dom.page.appendChild(img);
                finishRender();
                resolve();
            };
            img.onerror = () => { dom.loading.textContent = 'Impossibile caricare l\'immagine.'; resolve(); };
            img.src = S.viewUrl;
        });
    }

    // DWG → SVG vettoriale convertito server-side. Misure esatte dalle
    // coordinate CAD reali (extents + meters_per_unit), niente calibrazione.
    async function loadDwg() {
        const meta = await api(`${base()}/dwg`);
        if (!meta.ok || meta.data.status !== 'ok' || !meta.data.svg_url) {
            dom.loading.textContent = 'Il disegno DWG non è ancora stato convertito. Chiudere e selezionare "Converti".';
            return;
        }
        const m = meta.data;
        S.dwg = {
            minx: m.minx, miny: m.miny, maxx: m.maxx, maxy: m.maxy,
            mpu: m.meters_per_unit, // metri per unità disegno (può essere null)
        };
        return new Promise(resolve => {
            const img = new Image();
            img.onload = () => {
                S.natW = img.naturalWidth || 1000;
                S.natH = img.naturalHeight || Math.round(1000 * (m.maxy - m.miny) / Math.max(1e-6, (m.maxx - m.minx)));
                S.pageCount = 1;
                dom.page.innerHTML = '';
                img.style.width = '100%';
                img.draggable = false;
                dom.page.appendChild(img);
                finishRender();
                resolve();
            };
            img.onerror = () => { dom.loading.textContent = 'Impossibile caricare il disegno.'; resolve(); };
            img.src = m.svg_url;
        });
    }

    async function loadPdf() {
        if (!window.pdfjsLib) { dom.loading.textContent = 'Visualizzatore PDF non disponibile.'; return; }
        try {
            S.pdf = await window.pdfjsLib.getDocument(S.viewUrl).promise;
            S.pageCount = S.pdf.numPages;
            if (S.page > S.pageCount) S.page = 1;
            await renderPdfPage(S.page);
        } catch (e) {
            console.error(e);
            dom.loading.textContent = 'Impossibile aprire il PDF.';
        }
    }

    async function renderPdfPage(num) {
        nascondiDettaglio();
        const pg = await S.pdf.getPage(num);
        S.pagina = pg;
        const vp1 = pg.getViewport({ scale: 1 });
        S.natW = vp1.width; S.natH = vp1.height;
        // l'immagine di base, entro 4096 px per lato (il limite sicuro dei
        // browser, Safari compreso); oltre, il dettaglio nitido lo disegna
        // renderDettaglio() solo per la parte visibile
        const renderScale = Math.min(3, 4096 / Math.max(S.natW, S.natH));
        S.scalaBase = renderScale;
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
        applyStageSize();
        dom.loading.style.display = 'none';
        dom.stage.style.display = 'inline-block';
        // viewBox normalizzato (larghezza = SCALE)
        const vbH = SCALE * (S.natH / S.natW);
        dom.svg.setAttribute('viewBox', `0 0 ${SCALE} ${vbH}`);
        dom.pagenav.style.display = S.pageCount > 1 ? 'flex' : 'none';
        dom.pagelabel.textContent = `${S.page}/${S.pageCount}`;
        redraw();
        programmaDettaglio();
    }

    function applyStageSize() {
        const wrapW = dom.stagewrap.clientWidth - 48;
        // a zoom 1 il disegno occupa la larghezza della finestra; il PDF e'
        // disegnato a risoluzione alta, quindi si ingrandisce restando leggibile
        const w = Math.max(200, wrapW * S.zoom);
        dom.stage.style.width = w + 'px';
        dom.zoomval.textContent = Math.round(S.zoom * 100) + '%';
    }

    // fino a 6400%: sulle tavole di cantiere si legge la quota di un singolo
    // bullone. I PDF restano nitidi (vedi renderDettaglio), i DWG sono vettoriali.
    const ZOOM_MAX = 64;
    function setZoom(z) {
        const prima = S.zoom;
        S.zoom = Math.max(0.25, Math.min(ZOOM_MAX, z));
        applyStageSize();
        if (S.zoom !== prima) { nascondiDettaglio(); programmaDettaglio(); }
    }

    // ── Dettaglio nitido (PDF) ──────────────────────────────────────────────
    // L'immagine di base ha una risoluzione fissa: ingrandendo molto si
    // sgranerebbe. Quando serve, la parte di disegno visibile si ridisegna
    // alla risoluzione dello schermo e si appoggia sopra la base.
    let timerDettaglio = null;
    function programmaDettaglio() {
        clearTimeout(timerDettaglio);
        timerDettaglio = setTimeout(renderDettaglio, 140);
    }
    function nascondiDettaglio() {
        if (!dom) return;
        if (S && S.lavoroDettaglio) { try { S.lavoroDettaglio.cancel(); } catch (_) {} S.lavoroDettaglio = null; }
        dom.det.innerHTML = '';
        dom.det.style.display = 'none';
    }
    async function renderDettaglio() {
        if (!S || !S.pdf || !S.pagina || dom.stage.style.display === 'none') return;
        const st = dom.stage.getBoundingClientRect();
        const wr = dom.stagewrap.getBoundingClientRect();
        const dpr = Math.min(window.devicePixelRatio || 1, 3);
        const cssScala = st.width / S.natW;              // px CSS per unita' PDF
        if (cssScala * dpr <= S.scalaBase * 1.1) { nascondiDettaglio(); return; } // la base basta
        // la parte visibile, in px CSS dentro il disegno (con un margine)
        const m = 80;
        const x0 = Math.max(0, wr.left - st.left - m), y0 = Math.max(0, wr.top - st.top - m);
        const x1 = Math.min(st.width, wr.right - st.left + m), y1 = Math.min(st.height, wr.bottom - st.top + m);
        if (x1 <= x0 || y1 <= y0) return;
        // non oltre ~16 milioni di pixel per tela
        let k = dpr;
        const area = (x1 - x0) * (y1 - y0);
        if (area * k * k > 16e6) k = Math.sqrt(16e6 / area);
        const tela = document.createElement('canvas');
        tela.width = Math.floor((x1 - x0) * k);
        tela.height = Math.floor((y1 - y0) * k);
        const vp = S.pagina.getViewport({ scale: cssScala * k, offsetX: -x0 * k, offsetY: -y0 * k });
        if (S.lavoroDettaglio) { try { S.lavoroDettaglio.cancel(); } catch (_) {} }
        const lavoro = S.pagina.render({ canvasContext: tela.getContext('2d'), viewport: vp });
        S.lavoroDettaglio = lavoro;
        try { await lavoro.promise; } catch (_) { return; }       // annullato: c'e' uno piu' nuovo
        if (!S || S.lavoroDettaglio !== lavoro) return;
        S.lavoroDettaglio = null;
        Object.assign(dom.det.style, { display: 'block', left: x0 + 'px', top: y0 + 'px', width: (x1 - x0) + 'px', height: (y1 - y0) + 'px' });
        tela.style.width = '100%'; tela.style.height = '100%';
        dom.det.replaceChildren(tela);
    }

    /** Zoom tenendo fermo il punto dello schermo (x, y). */
    function zoomVerso(z, x, y) {
        const before = dom.stage.getBoundingClientRect();
        const fx = (x - before.left) / before.width;
        const fy = (y - before.top) / before.height;
        const prev = S.zoom;
        setZoom(z);
        if (S.zoom === prev) return;
        const after = dom.stage.getBoundingClientRect();
        dom.stagewrap.scrollLeft += (after.left + fx * after.width)  - x;
        dom.stagewrap.scrollTop  += (after.top  + fy * after.height) - y;
    }

    function zoomCentro(z) {
        const r = dom.stagewrap.getBoundingClientRect();
        zoomVerso(z, r.left + r.width / 2, r.top + r.height / 2);
    }

    /** Porta al centro della finestra un punto del disegno (coordinate normalizzate). */
    function centraSu(nx, ny) {
        const st = dom.stage.getBoundingClientRect();
        const wr = dom.stagewrap.getBoundingClientRect();
        const px = st.left + nx * st.width;
        const py = st.top + ny * st.width;
        dom.stagewrap.scrollLeft += px - (wr.left + wr.width / 2);
        dom.stagewrap.scrollTop  += py - (wr.top + wr.height / 2);
    }

    async function gotoPage(n) {
        if (!S.pdf || n < 1 || n > S.pageCount) return;
        if (S.onPick && (S.bozzaPin || S.bozza.length)
            && !confirm('Cambiando pagina il punto e i segni inseriti verranno rimossi. Continuare?')) return;
        S.page = n;
        S.bozzaPin = null; S.bozza = [];
        dom.loading.style.display = 'block';
        dom.stage.style.display = 'none';
        await renderPdfPage(n);
        await loadAnnotations();
        if (S.onPick) pannelloScelta();
    }

    // ── Annotazioni: load / draw ───────────────────────────────────────────────
    async function loadAnnotations() {
        const res = await api(`${base()}/annotations?page=${S.page}`);
        if (res.ok) {
            S.annotations = res.data.annotations || [];
            S.calibration = res.data.calibration || null;
        }
        redraw();
        if (S.evid && !S.centrato) evidenziaCollegati();
    }

    const collegato = a => !!S.evid && (
        (S.evid.task_id && a.task_id === Number(S.evid.task_id)) ||
        (S.evid.segnalazione_id && a.segnalazione_id === Number(S.evid.segnalazione_id)));

    /** Il centro di un segno, per portarci la vista. */
    function centroDi(a) {
        const g = a.geom || {};
        if (a.type === 'pin' || a.type === 'text') return { x: g.x, y: g.y };
        if (g.a && g.b) return { x: (g.a.x + g.b.x) / 2, y: (g.a.y + g.b.y) / 2 };
        if (Array.isArray(g.pts) && g.pts.length) {
            const n = g.pts.length;
            return { x: g.pts.reduce((s, p) => s + p[0], 0) / n, y: g.pts.reduce((s, p) => s + p[1], 0) / n };
        }
        if (g.w != null) return { x: g.x + g.w / 2, y: g.y + g.h / 2 };
        return null;
    }

    function evidenziaCollegati() {
        S.centrato = true;
        const trovati = S.annotations.filter(collegato);
        const cosa = S.evid.task_id ? 'all\'attività' : 'alla segnalazione';
        const nome = S.evid.nome ? ' «' + escapeHtml(S.evid.nome) + '»' : '';
        if (!S.mostra) mostraAnnotazioni(true);
        dom.banner.innerHTML = trovati.length
            ? `<i class="fas fa-highlighter"></i> In evidenza i segni collegati ${cosa}${nome}. <button type="button" data-act="evid-off">Mostra tutte le annotazioni allo stesso modo</button>`
            : `<i class="fas fa-circle-info"></i> Nessun segno collegato ${cosa}${nome} in questa pagina. <button type="button" data-act="evid-off">Chiudi</button>`;
        dom.banner.hidden = false;
        if (!trovati.length) return;
        const c = centroDi(trovati[0]);
        if (!c) return;
        // un po' di zoom e il segno al centro
        setZoom(Math.max(S.zoom, 2));
        requestAnimationFrame(() => centraSu(c.x, c.y));
    }

    function mostraAnnotazioni(si) {
        S.mostra = si;
        aggiornaToggle();
        if (!si && !['select', 'pan'].includes(S.tool) && !S.onPick) setTool('select');
        dom.side.classList.toggle('open', !!S.onPick);
        if (!S.onPick) dom.title.textContent = (S.fileName || 'Disegno') + (si ? '' : ' · senza annotazioni');
        redraw();
    }

    function aggiornaToggle() {
        dom.ov.classList.toggle('bza-nascoste', !S.mostra);
        dom.toggleann.innerHTML = S.mostra
            ? '<i class="fas fa-eye-slash"></i> <span>Nascondi annotazioni</span>'
            : '<i class="fas fa-eye"></i> <span>Mostra annotazioni</span>';
    }

    /** L'SVG di un segno. opz.colore/opz.spessore per l'alone dell'evidenza. */
    function svgDi(a, num, opz = {}) {
        const g = a.geom || {};
        const col = opz.colore || a.color || '#ef4444';
        const sw  = opz.spessore || 3;
        const att = opz.alone ? 'class="bza-alone" pointer-events="none"' : (a.id ? `data-ann="${a.id}"` : '');
        if (a.type === 'pin') {
            const x = g.x * SCALE, y = g.y * SCALE;
            if (opz.alone) return `<circle ${att} cx="${x}" cy="${y - 28}" r="34" fill="none" stroke="${EVID}" stroke-width="5"/>`;
            return `<g class="bza-pin" ${att}>
                <path d="M${x},${y} c-9,-20 -16,-26 -16,-40 a16,16 0 1,1 32,0 c0,14 -7,20 -16,40 z" fill="${col}" stroke="#fff" stroke-width="2"/>
                <circle cx="${x}" cy="${y - 40}" r="11" fill="#fff"/>
                <text class="bza-pin-label" x="${x}" y="${y - 36}" text-anchor="middle" fill="${col}">${num ?? ''}</text>
            </g>`;
        }
        if (a.type === 'measure') {
            const a1 = g.a, b1 = g.b;
            if (!a1 || !b1) return '';
            const x1 = a1.x * SCALE, y1 = a1.y * SCALE, x2 = b1.x * SCALE, y2 = b1.y * SCALE;
            if (opz.alone) return `<line ${att} x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${col}" stroke-width="${sw}" stroke-linecap="round"/>`;
            const mx = (x1 + x2) / 2, my = (y1 + y2) / 2;
            const lbl = measureLabel(g);
            const lblW = Math.max(40, lbl.length * 9);
            return `<g ${att}><line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${col}" stroke-width="3"/>
                <circle cx="${x1}" cy="${y1}" r="4" fill="${col}"/><circle cx="${x2}" cy="${y2}" r="4" fill="${col}"/>
                <rect x="${mx - lblW / 2}" y="${my - 22}" width="${lblW}" height="18" rx="4" fill="#0f172a"/>
                <text x="${mx}" y="${my - 9}" text-anchor="middle" font-size="14" fill="#fff">${lbl}</text></g>`;
        }
        if (a.type === 'arrow') {
            const a1 = g.a, b1 = g.b;
            if (!a1 || !b1) return '';
            return `<line ${att} x1="${a1.x * SCALE}" y1="${a1.y * SCALE}" x2="${b1.x * SCALE}" y2="${b1.y * SCALE}" stroke="${col}" stroke-width="${opz.alone ? sw : 4}" stroke-linecap="round" ${opz.alone ? '' : 'marker-end="url(#bza-arrow)"'}/>`;
        }
        if (a.type === 'rectangle' || a.type === 'cloud') {
            const area = !opz.alone && a.task_id ? `fill="${col}" fill-opacity=".08"` : 'fill="none"';
            const rx = a.type === 'cloud' ? 14 : 0;
            const dash = a.type === 'cloud' && !opz.alone ? 'stroke-dasharray="14 6"' : '';
            return `<rect ${att} x="${g.x * SCALE}" y="${g.y * SCALE}" width="${g.w * SCALE}" height="${g.h * SCALE}" rx="${rx}" ${area} stroke="${col}" stroke-width="${sw}" ${dash}/>`;
        }
        if (a.type === 'ellipse') {
            const area = !opz.alone && a.task_id ? `fill="${col}" fill-opacity=".08"` : 'fill="none"';
            const cx = (g.x + g.w / 2) * SCALE, cy = (g.y + g.h / 2) * SCALE;
            return `<ellipse ${att} cx="${cx}" cy="${cy}" rx="${Math.abs(g.w / 2) * SCALE}" ry="${Math.abs(g.h / 2) * SCALE}" ${area} stroke="${col}" stroke-width="${sw}"/>`;
        }
        if (a.type === 'text') {
            if (opz.alone) return `<text ${att} x="${g.x * SCALE}" y="${g.y * SCALE}" font-size="20" font-weight="700" fill="none" stroke="${EVID}" stroke-width="6">${escapeXml(a.text || '')}</text>`;
            return `<text ${att} x="${g.x * SCALE}" y="${g.y * SCALE}" font-size="20" font-weight="700" fill="${col}">${escapeXml(a.text || '')}</text>`;
        }
        if (a.type === 'drawing' && Array.isArray(g.pts)) {
            const d = g.pts.map((p, i) => `${i ? 'L' : 'M'}${p[0] * SCALE},${p[1] * SCALE}`).join(' ');
            return `<path ${att} d="${d}" fill="none" stroke="${col}" stroke-width="${sw}" stroke-linejoin="round" stroke-linecap="round"/>`;
        }
        return '';
    }

    function redraw() {
        if (!S) return;
        let svg = `<defs><marker id="bza-arrow" markerWidth="10" markerHeight="10" refX="8" refY="3" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L8,3 L0,6 Z" fill="context-stroke"/></marker></defs>`;
        if (S.mostra) {
            let pinNum = 0;
            const evidenza = !!S.evid;
            S.annotations.forEach(a => {
                if (a.type === 'pin') pinNum++;
                const si = collegato(a);
                const gruppo = evidenza ? (si ? 'bza-evid' : 'bza-attenuato') : '';
                svg += `<g class="${gruppo}">`;
                if (si) svg += svgDi(a, pinNum, { alone: true, colore: EVID, spessore: 10 });
                svg += svgDi(a, a.type === 'pin' ? pinNum : null);
                svg += '</g>';
            });
        }
        // la bozza di chi sta scegliendo il punto dell'attivita'
        if (S.onPick) {
            S.bozza.forEach(b => { svg += svgDi({ ...b, task_id: 1 }, null); });
            if (S.bozzaPin) {
                svg += `<g class="bza-evid">${svgDi({ type: 'pin', geom: S.bozzaPin }, null, { alone: true, colore: EVID, spessore: 10 })}`
                     + svgDi({ type: 'pin', geom: S.bozzaPin, color: '#3b82f6' }, '') + '</g>';
            }
        }
        // disegno in corso (preview)
        if (S.drawing) svg += S.drawing.preview || '';
        dom.svg.innerHTML = svg;
    }

    // ── Tool + pointer ─────────────────────────────────────────────────────────
    function setTool(t) {
        S.tool = t;
        dom.tools.querySelectorAll('.bza-tool').forEach(b => b.classList.toggle('active', b.dataset.tool === t));
        dom.svg.classList.toggle('tool-select', t === 'select');
        dom.svg.classList.toggle('tool-pan', t === 'pan');
    }

    function ptNorm(e) {
        const r = dom.svg.getBoundingClientRect();
        return { x: (e.clientX - r.left) / r.width, y: (e.clientY - r.top) / r.width };
    }

    function iniziaPan(e) {
        S.pan = { x: e.clientX, y: e.clientY, sl: dom.stagewrap.scrollLeft, st: dom.stagewrap.scrollTop };
        dom.svg.classList.add('panning');
    }

    function onPointerDown(e) {
        if (!S) return;
        S.ptrs.set(e.pointerId, { x: e.clientX, y: e.clientY });
        try { dom.svg.setPointerCapture(e.pointerId); } catch (_) {}

        // due dita: pinch per lo zoom, si lascia perdere il resto
        if (S.ptrs.size === 2) {
            const [p1, p2] = [...S.ptrs.values()];
            S.pinch = { d0: Math.hypot(p2.x - p1.x, p2.y - p1.y), z0: S.zoom };
            S.drawing = null; S.pan = null;
            redraw();
            return;
        }
        if (S.ptrs.size > 2) return;

        const p = ptNorm(e);

        // SPOSTA, o trascinare a vuoto con "Seleziona" (o con le annotazioni nascoste)
        if (S.tool === 'pan' || e.button === 1) { iniziaPan(e); return; }
        if (S.tool === 'select' || (!S.mostra && !S.onPick)) {
            const annEl = S.mostra ? e.target.closest('[data-ann]') : null;
            if (annEl) { openAnnPanel(parseInt(annEl.dataset.ann)); return; }
            iniziaPan(e);
            return;
        }
        if (S.solaLettura && !S.onPick) { iniziaPan(e); return; }

        // PUNTO
        if (S.tool === 'pin') {
            if (S.onPick) { S.bozzaPin = p; redraw(); pannelloScelta(); return; }
            openPinCreate(p);
            return;
        }

        // TESTO
        if (S.tool === 'text') {
            const txt = prompt('Testo dell\'annotazione:');
            if (txt && txt.trim()) aggiungi({ type: 'text', geom: { x: p.x, y: p.y }, text: txt.trim() });
            return;
        }

        // strumenti a trascinamento
        S.drawing = { tool: S.tool, start: p, pts: [[p.x, p.y]], preview: '' };
    }

    function onPointerMove(e) {
        if (!S) return;
        if (S.ptrs.has(e.pointerId)) S.ptrs.set(e.pointerId, { x: e.clientX, y: e.clientY });

        if (S.pinch && S.ptrs.size >= 2) {
            const [p1, p2] = [...S.ptrs.values()];
            const d = Math.hypot(p2.x - p1.x, p2.y - p1.y);
            if (S.pinch.d0 > 0) zoomVerso(S.pinch.z0 * d / S.pinch.d0, (p1.x + p2.x) / 2, (p1.y + p2.y) / 2);
            return;
        }
        if (S.pan) {
            dom.stagewrap.scrollLeft = S.pan.sl - (e.clientX - S.pan.x);
            dom.stagewrap.scrollTop  = S.pan.st - (e.clientY - S.pan.y);
            return;
        }
        if (!S.drawing) return;
        const p = ptNorm(e);
        const d = S.drawing, col = S.color;
        const x1 = d.start.x * SCALE, y1 = d.start.y * SCALE, x2 = p.x * SCALE, y2 = p.y * SCALE;
        if (d.tool === 'measure') {
            d.preview = `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${col}" stroke-width="3" stroke-dasharray="6 4"/>`;
        } else if (d.tool === 'arrow') {
            d.preview = `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${col}" stroke-width="4" marker-end="url(#bza-arrow)"/>`;
        } else if (d.tool === 'rectangle' || d.tool === 'cloud') {
            const rx = d.tool === 'cloud' ? 14 : 0;
            d.preview = `<rect x="${Math.min(x1, x2)}" y="${Math.min(y1, y2)}" width="${Math.abs(x2 - x1)}" height="${Math.abs(y2 - y1)}" rx="${rx}" fill="${col}" fill-opacity=".08" stroke="${col}" stroke-width="3" ${d.tool === 'cloud' ? 'stroke-dasharray="14 6"' : ''}/>`;
        } else if (d.tool === 'ellipse') {
            d.preview = `<ellipse cx="${(x1 + x2) / 2}" cy="${(y1 + y2) / 2}" rx="${Math.abs(x2 - x1) / 2}" ry="${Math.abs(y2 - y1) / 2}" fill="${col}" fill-opacity=".08" stroke="${col}" stroke-width="3"/>`;
        } else if (d.tool === 'draw') {
            d.pts.push([p.x, p.y]);
            const path = d.pts.map((q, i) => `${i ? 'L' : 'M'}${q[0] * SCALE},${q[1] * SCALE}`).join(' ');
            d.preview = `<path d="${path}" fill="none" stroke="${col}" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>`;
        }
        redraw();
    }

    function onPointerUp(e) {
        if (!S) return;
        S.ptrs.delete(e.pointerId);
        if (S.pinch) { if (S.ptrs.size < 2) S.pinch = null; return; }
        if (S.pan) { S.pan = null; dom.svg.classList.remove('panning'); return; }
        if (!S.drawing) return;
        const p = ptNorm(e);
        const d = S.drawing; S.drawing = null;
        const a = d.start, b = p;
        const tiny = Math.abs(b.x - a.x) < 0.005 && Math.abs(b.y - a.y) < 0.005;
        const box = { x: Math.min(a.x, b.x), y: Math.min(a.y, b.y), w: Math.abs(b.x - a.x), h: Math.abs(b.y - a.y) };

        if (d.tool === 'measure') {
            if (tiny) { redraw(); return; }
            const m  = measureMeters(a, b);
            const cu = measureCadUnits(a, b);
            aggiungi({ type: 'measure', geom: { a, b, m, cu } });
        } else if (d.tool === 'arrow') {
            if (tiny) { redraw(); return; }
            aggiungi({ type: 'arrow', geom: { a, b } });
        } else if (d.tool === 'rectangle' || d.tool === 'cloud' || d.tool === 'ellipse') {
            if (tiny) { redraw(); return; }
            aggiungi({ type: d.tool, geom: box });
        } else if (d.tool === 'draw') {
            if (d.pts.length < 2) { redraw(); return; }
            aggiungi({ type: 'drawing', geom: { pts: d.pts } });
        } else {
            redraw();
        }
    }

    /** Un segno nuovo: in bozza se si sta scegliendo il punto, se no si salva. */
    function aggiungi(payload) {
        if (S.onPick) {
            if (payload.type === 'measure') { redraw(); return; }
            S.bozza.push({ ...payload, color: payload.color || S.color });
            redraw();
            pannelloScelta();
            return;
        }
        saveAnn(payload);
    }

    // ── Scelta del punto per una nuova attivita' ──────────────────────────────
    function pannelloScelta() {
        dom.side.classList.add('open');
        const n = S.bozza.length;
        dom.side.innerHTML = `
            <h4><i class="fas fa-location-crosshairs"></i> Posizione dell'attività</h4>
            <ol class="bza-passi">
                <li>Ingrandire con la rotella, con i tasti <b>+</b>/<b>−</b> o con due dita; spostare il disegno con <i class="fas fa-hand"></i> o trascinando.</li>
                <li>Con <i class="fas fa-map-marker-alt"></i> indicare il punto esatto.</li>
                <li>Facoltativo: evidenziare un'area <i class="far fa-square"></i> <i class="far fa-circle"></i> <i class="fas fa-cloud"></i>, aggiungere una freccia, un testo o un disegno libero.</li>
            </ol>
            <div class="bza-stato-scelta">
                <div class="${S.bozzaPin ? 'ok' : ''}"><i class="fas ${S.bozzaPin ? 'fa-circle-check' : 'fa-circle'}"></i> ${S.bozzaPin ? 'Punto indicato' : 'Punto non ancora indicato'}</div>
                <div class="${n ? 'ok' : ''}"><i class="fas ${n ? 'fa-circle-check' : 'fa-circle'}"></i> ${n ? (n === 1 ? '1 segno aggiunto' : n + ' segni aggiunti') : 'Nessun segno aggiunto'}</div>
            </div>
            <div class="bza-row" style="flex-wrap:wrap;">
                <button class="bza-btn bza-btn-ghost" data-el="undo" ${n || S.bozzaPin ? '' : 'disabled'}><i class="fas fa-rotate-left"></i> Annulla ultimo</button>
                <button class="bza-btn bza-btn-ghost" data-el="svuota" ${n || S.bozzaPin ? '' : 'disabled'}><i class="fas fa-eraser"></i> Ricomincia</button>
            </div>
            <div class="bza-row" style="margin-top:14px;">
                <button class="bza-btn bza-btn-primary" data-el="conferma"><i class="fas fa-check"></i> Conferma posizione</button>
                <button class="bza-btn bza-btn-ghost" data-el="annulla">Annulla</button>
            </div>
            <div class="bza-hint">Il punto e i segni vengono salvati alla creazione dell'attività e restano collegati ad essa.</div>
        `;
        dom.side.querySelector('[data-el="undo"]').addEventListener('click', () => {
            if (S.bozza.length) S.bozza.pop(); else S.bozzaPin = null;
            redraw(); pannelloScelta();
        });
        dom.side.querySelector('[data-el="svuota"]').addEventListener('click', () => {
            S.bozza = []; S.bozzaPin = null; redraw(); pannelloScelta();
        });
        dom.side.querySelector('[data-el="annulla"]').addEventListener('click', close);
        dom.side.querySelector('[data-el="conferma"]').addEventListener('click', confermaScelta);
    }

    function confermaScelta() {
        let pin = S.bozzaPin;
        if (!pin && S.bozza.length) {
            // senza punto: il centro dell'ultimo segno
            const c = centroDi(S.bozza[S.bozza.length - 1]);
            if (c) pin = c;
        }
        if (!pin) { alert('Indicare almeno un punto o un\'area sul disegno.'); return; }
        const fatto = S.onPick;
        const punto = { page: S.page, x: pin.x, y: pin.y, segni: S.bozza.map(x => ({ type: x.type, geom: x.geom, color: x.color, text: x.text || null })) };
        close();
        fatto(punto);
    }

    // ── Misure / calibrazione ────────────────────────────────────────────────
    function normDist(a, b) { return Math.hypot(b.x - a.x, b.y - a.y); } // in unità width-frazione

    // distanza in unità disegno CAD (solo DWG/DXF)
    function measureCadUnits(a, b) {
        if (!S.dwg) return null;
        return normDist(a, b) * (S.dwg.maxx - S.dwg.minx);
    }

    // metri, se possibile: 1) DWG con unità note 2) calibrazione manuale. Altrimenti null.
    function measureMeters(a, b) {
        const nd = normDist(a, b);
        if (S.dwg && S.dwg.mpu) return measureCadUnits(a, b) * S.dwg.mpu;
        if (S.calibration)      return nd * S.calibration.m_per_wfrac; // vale anche per DWG unitless calibrato
        return null;
    }

    // etichetta da mostrare sulla misura
    function measureLabel(geom) {
        if (geom.m != null)  return geom.m.toFixed(2) + ' m';
        if (geom.cu != null) return fmtNum(geom.cu) + ' u'; // unità disegno (DWG unitless)
        return '?';
    }
    function fmtNum(n) {
        if (n >= 1000) return Math.round(n).toLocaleString('it-IT');
        return (Math.round(n * 100) / 100).toString();
    }

    function startCalibration() {
        setTool('select');
        alert('Calibrazione: tracciare una linea su una misura nota, poi inserire la lunghezza reale in metri.');
        S.calMode = true;
        setTool('measure');
        // il prossimo segno di misura chiede la lunghezza reale
        S._calOnce = true;
    }

    // ── Pannello pin / annotazione ──────────────────────────────────────────────
    async function openPinCreate(p) {
        dom.side.classList.add('open');
        // carica i task del cantiere per il collegamento
        const tasksRes = await api(`/worksites/${S.worksiteId}/zone/tasks`);
        const tasks = (tasksRes.ok && Array.isArray(tasksRes.data)) ? tasksRes.data : [];
        dom.side.innerHTML = `
            <h4><i class="fas fa-map-marker-alt"></i> Nuovo punto</h4>
            <div class="bza-field">
                <label>Tipo</label>
                <select data-el="pinmode">
                    <option value="new">Crea attività in questo punto</option>
                    <option value="segn">Segnala un problema in questo punto</option>
                    <option value="link">Collega attività esistente</option>
                    <option value="note">Solo nota</option>
                </select>
            </div>
            <div data-el="pin-new">
                ${S.onNuovaAttivita
                    ? '<div class="bza-hint" style="margin-bottom:10px;">Si apre il modulo completo della nuova attività (titolo, descrizione, a chi è assegnata, date, priorità, passaggi, foto), con questo punto già segnato sul disegno.</div>'
                    : `<div class="bza-field"><label>Titolo dell'attività</label><input data-el="pin-name" placeholder="Es. Montaggio staffa"></div>
                       <div class="bza-field"><label>Assegnata a (facoltativo)</label><input data-el="pin-assignee" placeholder="Nome"></div>`}
            </div>
            <div data-el="pin-segn" style="display:none;">
                <div class="bza-field"><label>Descrizione del problema</label>
                    <textarea data-el="segn-testo" rows="3" placeholder="Es. tirante mancante"></textarea>
                </div>
                <div class="bza-field"><label>Categoria</label>
                    <select data-el="segn-tipo">
                        <option value="sicurezza">Sicurezza</option>
                        <option value="materiale">Materiale</option>
                        <option value="danno">Danno</option>
                        <option value="ritardo">Ritardo</option>
                        <option value="qualita">Qualit&agrave;</option>
                        <option value="altro" selected>Altro</option>
                    </select>
                </div>
                <div class="bza-field"><label>Gravità</label>
                    <select data-el="segn-gravita">
                        <option value="bassa">Normale</option>
                        <option value="alta">Alta</option>
                        <option value="blocca">Blocca il lavoro</option>
                    </select>
                </div>
            </div>
            <div data-el="pin-link" style="display:none;">
                <div class="bza-field"><label>Attività esistente</label>
                    <select data-el="pin-task">${tasks.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('')}</select>
                </div>
            </div>
            <div data-el="pin-note" style="display:none;">
                <div class="bza-field"><label>Nota</label><textarea data-el="pin-text" rows="3"></textarea></div>
            </div>
            <div class="bza-row">
                <button class="bza-btn bza-btn-primary" data-el="pin-save">Salva punto</button>
                <button class="bza-btn bza-btn-ghost" data-el="pin-cancel">Annulla</button>
            </div>
            <div class="bza-hint">Il punto resta nella posizione selezionata. Le attività compaiono nel Kanban, le segnalazioni nel relativo elenco; da entrambi è possibile tornare a questo punto.</div>
        `;
        const mode = dom.side.querySelector('[data-el="pinmode"]');
        const refresh = () => {
            ['new', 'segn', 'link', 'note'].forEach(v => {
                dom.side.querySelector(`[data-el="pin-${v}"]`).style.display = mode.value === v ? 'block' : 'none';
            });
            dom.side.querySelector('[data-el="pin-save"]').textContent = mode.value === 'new' && S.onNuovaAttivita ? 'Apri modulo attività' : 'Salva punto';
        };
        mode.addEventListener('change', refresh); refresh();
        dom.side.querySelector('[data-el="pin-cancel"]').addEventListener('click', () => dom.side.classList.remove('open'));
        dom.side.querySelector('[data-el="pin-save"]').addEventListener('click', async () => {
            const payload = { type: 'pin', geom: { x: p.x, y: p.y }, color: S.color };
            if (mode.value === 'new' && S.onNuovaAttivita) {
                // il modulo completo, con il punto gia' segnato
                const apri = S.onNuovaAttivita;
                const punto = { document_id: S.docId, nome: S.fileName, page: S.page, x: p.x, y: p.y, segni: [] };
                close();
                apri(punto);
                return;
            }
            if (mode.value === 'new') {
                payload.create_task = true;
                payload.task_name = dom.side.querySelector('[data-el="pin-name"]').value.trim();
                payload.assignee_name = dom.side.querySelector('[data-el="pin-assignee"]').value.trim() || null;
                if (!payload.task_name) { alert('Inserire il titolo dell\'attività.'); return; }
            } else if (mode.value === 'segn') {
                payload.create_segnalazione = true;
                payload.segnalazione_testo   = dom.side.querySelector('[data-el="segn-testo"]').value.trim();
                payload.segnalazione_tipo    = dom.side.querySelector('[data-el="segn-tipo"]').value;
                payload.segnalazione_gravita = dom.side.querySelector('[data-el="segn-gravita"]').value;
                if (!payload.segnalazione_testo) { alert('Inserire la descrizione del problema.'); return; }
                // le segnalazioni che bloccano si vedono da lontano
                payload.color = payload.segnalazione_gravita === 'blocca' ? '#ef4444' : '#f59e0b';
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
        const stati = { open: 'Aperto', in_progress: 'In corso', complete: 'Completato', verified: 'Verificato' };
        if (a.segnalazione_id) {
            const grav = { bassa: 'Normale', alta: 'Alta', blocca: 'Blocca il lavoro' };
            const stat = { aperta: 'Aperta', presa: 'Presa in carico', risolta: 'Risolta' };
            info = `<div class="bza-field"><label>Segnalazione</label>
                <div class="bza-val">${escapeHtml(a.segn_testo || '—')}</div>
                <div class="bza-sub">${escapeHtml(stat[a.segn_stato] || a.segn_stato || '—')} · ${escapeHtml(grav[a.segn_gravita] || a.segn_gravita || '')}</div>
                <button type="button" class="bza-btn bza-btn-ghost" style="margin-top:8px;" data-act="apri" data-dove="segnalazione-${a.segnalazione_id}"><i class="fas fa-arrow-up-right-from-square"></i> Apri la segnalazione</button>
            </div>`;
        } else if (a.task_id) {
            info = `<div class="bza-field"><label>Attività collegata</label>
                <div class="bza-val">${escapeHtml(a.task_name || '—')}</div>
                <div class="bza-sub">Stato: ${escapeHtml(stati[a.task_status] || a.task_status || '—')}${a.task_assignee ? ' · ' + escapeHtml(a.task_assignee) : ''}</div>
                <button type="button" class="bza-btn bza-btn-ghost" style="margin-top:8px;" data-act="apri" data-dove="attivita-${a.task_id}"><i class="fas fa-arrow-up-right-from-square"></i> Apri l'attività</button>
            </div>`;
            if (a.text) info += `<div class="bza-field"><label>Testo</label><div class="bza-val">${escapeHtml(a.text)}</div></div>`;
        } else if (a.text) {
            info = `<div class="bza-field"><label>Nota</label><div class="bza-val">${escapeHtml(a.text)}</div></div>`;
        } else if (a.type === 'measure') {
            info = `<div class="bza-field"><label>Misura</label><div class="bza-val" style="font-size:15px;font-weight:700;">${measureLabel(a.geom || {})}</div>`
                 + (a.geom?.m == null ? '<div class="bza-hint">Disegno senza unità di misura: utilizzare "Calibra" per ottenere i metri.</div>' : '')
                 + `</div>`;
        }
        dom.side.innerHTML = `
            <h4>${labelFor(a.type)}</h4>
            ${info}
            <div class="bza-row">
                ${S.solaLettura ? '' : '<button class="bza-btn bza-btn-danger" data-el="ann-del"><i class="fas fa-trash"></i> Elimina</button>'}
                <button class="bza-btn bza-btn-ghost" data-el="ann-close">Chiudi</button>
            </div>
        `;
        dom.side.querySelector('[data-el="ann-close"]').addEventListener('click', () => dom.side.classList.remove('open'));
        dom.side.querySelector('[data-el="ann-del"]')?.addEventListener('click', async () => {
            if (!confirm('Eliminare questa annotazione?')) return;
            const r = await api(`${base()}/annotations/${annId}/delete`, { method: 'POST' });
            if (!r.ok) { alert(r.error || 'Impossibile eliminare l\'annotazione.'); return; }
            dom.side.classList.remove('open');
            await loadAnnotations();
        });
    }

    // ── Save annotazione ────────────────────────────────────────────────────────
    async function saveAnn(payload) {
        // calibrazione: il prossimo segno di misura
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
            alert('Scala calibrata. Le misure sono ora espresse in metri.');
            return;
        }
        payload.page = S.page;
        if (!payload.color) payload.color = S.color;
        const res = await api(`${base()}/annotations`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        if (res.ok) await loadAnnotations();
        else alert('Impossibile salvare l\'annotazione' + (res.error ? ': ' + res.error : '.'));
    }

    // ── utils ────────────────────────────────────────────────────────────────
    function labelFor(t) {
        return ({ pin: 'Punto', measure: 'Misura', arrow: 'Freccia', rectangle: 'Area rettangolare',
                  ellipse: 'Area ellittica', cloud: 'Nuvola', text: 'Testo', drawing: 'Disegno libero' })[t] || t;
    }
    function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
    function escapeXml(s) { return escapeHtml(s); }

    window.addEventListener('resize', () => { if (S && dom?.ov.classList.contains('open')) { applyStageSize(); nascondiDettaglio(); programmaDettaglio(); } });

    // ── Download ────────────────────────────────────────────────────────────
    function salvaFile(blob, nome) {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = nome;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }

    function scaricaOriginale(cfg) {
        const a = document.createElement('a');
        a.href = cfg.viewUrl;
        a.download = cfg.fileName || 'disegno';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    function caricaPdfLib() {
        if (window.PDFLib) return Promise.resolve();
        return new Promise((ok, ko) => {
            const sc = document.createElement('script');
            sc.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf-lib/1.17.1/pdf-lib.min.js';
            sc.onload = ok;
            sc.onerror = () => ko(new Error('Impossibile caricare il componente per creare il PDF.'));
            document.head.appendChild(sc);
        });
    }

    /**
     * Il disegno con le annotazioni disegnate sopra, in PDF. Le annotazioni
     * sono in coordinate normalizzate sulla larghezza della pagina (viewBox
     * largo 1000): con drawSvgPath si disegnano uguali a come si vedono.
     */
    async function scaricaConAnnotazioni(cfg) {
        const ft = (cfg.fileType || '').toLowerCase();
        if (!['pdf', 'png', 'jpg', 'jpeg'].includes(ft)) { alert('Il download con annotazioni è disponibile per PDF e immagini.'); return; }
        try {
            await caricaPdfLib();
            const { PDFDocument, StandardFonts, rgb } = window.PDFLib;
            const r = await fetch(cfg.viewUrl, { credentials: 'same-origin' });
            if (!r.ok) throw new Error('Impossibile scaricare il disegno.');
            const bytes = new Uint8Array(await r.arrayBuffer());
            let doc;
            if (ft === 'pdf') {
                doc = await PDFDocument.load(bytes, { ignoreEncryption: true });
            } else {
                doc = await PDFDocument.create();
                const img = ft === 'png' ? await doc.embedPng(bytes) : await doc.embedJpg(bytes);
                doc.addPage([img.width, img.height]).drawImage(img, { x: 0, y: 0, width: img.width, height: img.height });
            }
            const font = await doc.embedFont(StandardFonts.HelveticaBold);
            const colore = h => {
                const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(h || '') || [0, 'ef', '44', '44'];
                return rgb(parseInt(m[1], 16) / 255, parseInt(m[2], 16) / 255, parseInt(m[3], 16) / 255);
            };
            const testo = t => Array.from(String(t || '')).map(ch => { try { font.encodeText(ch); return ch; } catch { return ch.normalize('NFD').replace(/[\u0300-\u036f]/g, '') || '?'; } }).join('');
            const pagine = doc.getPages();
            for (let i = 0; i < pagine.length; i++) {
                const res = await fetch(`/worksites/${cfg.worksiteId}/zone/disegni/${cfg.docId}/annotations?page=${i + 1}`,
                    { credentials: 'same-origin', headers: { 'X-CSRF-Token': cfg.csrf } }).then(x => x.json()).catch(() => null);
                const anns = res && res.ok ? (res.data.annotations || []) : [];
                if (!anns.length) continue;
                const pg = pagine[i];
                const cb = pg.getCropBox();
                const k = cb.width / SCALE;                         // unita' viewBox -> punti PDF
                const o = { x: cb.x, y: cb.y + cb.height, scale: k };
                const P = (gx, gy) => ({ x: cb.x + gx * cb.width, y: cb.y + cb.height - gy * cb.width });
                let n = 0;
                for (const a of anns) {
                    const g = a.geom || {}, col = colore(a.color);
                    const linea = { ...o, borderColor: col, borderWidth: 3 };
                    if (a.type === 'pin') {
                        n++;
                        const x = g.x * SCALE, y = g.y * SCALE;
                        pg.drawSvgPath(`M${x},${y} c-9,-20 -16,-26 -16,-40 a16,16 0 1,1 32,0 c0,14 -7,20 -16,40 z`, { ...o, color: col, borderColor: rgb(1, 1, 1), borderWidth: 2 });
                        const c = P(g.x, g.y - 40 / SCALE);
                        pg.drawCircle({ x: c.x, y: c.y, size: 11 * k, color: rgb(1, 1, 1) });
                        const t = String(n), sz = 13 * k;
                        pg.drawText(t, { x: c.x - font.widthOfTextAtSize(t, sz) / 2, y: c.y - sz * 0.35, size: sz, font, color: col });
                    } else if ((a.type === 'arrow' || a.type === 'measure') && g.a && g.b) {
                        const x1 = g.a.x * SCALE, y1 = g.a.y * SCALE, x2 = g.b.x * SCALE, y2 = g.b.y * SCALE;
                        let d = `M${x1},${y1} L${x2},${y2}`;
                        if (a.type === 'arrow') {
                            const ang = Math.atan2(y2 - y1, x2 - x1), L = 16;
                            d += ` M${x2},${y2} L${x2 - L * Math.cos(ang - 0.45)},${y2 - L * Math.sin(ang - 0.45)} M${x2},${y2} L${x2 - L * Math.cos(ang + 0.45)},${y2 - L * Math.sin(ang + 0.45)}`;
                        }
                        pg.drawSvgPath(d, { ...linea, borderWidth: a.type === 'arrow' ? 4 : 3 });
                        if (a.type === 'measure') {
                            const lbl = testo(measureLabel(g)), sz = 14 * k;
                            const m = P((g.a.x + g.b.x) / 2, (g.a.y + g.b.y) / 2);
                            const w = font.widthOfTextAtSize(lbl, sz);
                            pg.drawRectangle({ x: m.x - w / 2 - 3 * k, y: m.y + 6 * k, width: w + 6 * k, height: sz + 4 * k, color: rgb(0.06, 0.09, 0.16) });
                            pg.drawText(lbl, { x: m.x - w / 2, y: m.y + 8 * k, size: sz, font, color: rgb(1, 1, 1) });
                        }
                    } else if ((a.type === 'rectangle' || a.type === 'cloud') && g.w != null) {
                        const x = g.x * SCALE, y = g.y * SCALE, w = g.w * SCALE, h = g.h * SCALE;
                        pg.drawSvgPath(`M${x},${y} h${w} v${h} h${-w} Z`, { ...linea, ...(a.type === 'cloud' ? { borderDashArray: [14, 6] } : {}) });
                    } else if (a.type === 'ellipse' && g.w != null) {
                        const rx = Math.abs(g.w / 2) * SCALE, ry = Math.abs(g.h / 2) * SCALE, cx = (g.x + g.w / 2) * SCALE, cy = (g.y + g.h / 2) * SCALE;
                        pg.drawSvgPath(`M${cx - rx},${cy} a${rx},${ry} 0 1,0 ${2 * rx},0 a${rx},${ry} 0 1,0 ${-2 * rx},0`, linea);
                    } else if (a.type === 'drawing' && Array.isArray(g.pts) && g.pts.length > 1) {
                        pg.drawSvgPath(g.pts.map((p, j) => `${j ? 'L' : 'M'}${p[0] * SCALE},${p[1] * SCALE}`).join(' '), linea);
                    } else if (a.type === 'text' && a.text) {
                        const p = P(g.x, g.y);
                        pg.drawText(testo(a.text), { x: p.x, y: p.y, size: 20 * k, font, color: col });
                    }
                }
            }
            const out = await doc.save();
            const base = (cfg.fileName || 'disegno').replace(/\.[^.]+$/, '');
            salvaFile(new Blob([out], { type: 'application/pdf' }), base + ' (con annotazioni).pdf');
        } catch (e) {
            alert(e && e.message ? e.message : 'Impossibile creare il PDF con le annotazioni.');
        }
    }

    window.BZAnnotator = { open, scaricaConAnnotazioni, scaricaOriginale };
})();
