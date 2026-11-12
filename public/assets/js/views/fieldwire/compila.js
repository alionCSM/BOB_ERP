/**
 * BOB Zone — Compilare un PDF nel browser.
 *
 *   BZCompila.apri({ url, nome, salva: async (blob) => {...} })
 *
 * Se il PDF ha campi compilabili (moduli PDF veri), si compilano quelli.
 * Se e' un PDF "piatto" (una scansione, un modulo stampato), si scrive sopra:
 * testo, spunta, croce, data, firma. Salvando, il PDF viene scritto con
 * pdf-lib e consegnato a chi l'ha aperto; l'originale non si tocca.
 *
 * pdf.js c'e' gia' nella pagina; pdf-lib si carica alla prima apertura.
 */
(function () {
    'use strict';

    const PDF_LIB = 'https://cdnjs.cloudflare.com/ajax/libs/pdf-lib/1.17.1/pdf-lib.min.js';
    let S = null, dom = null;

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const oggi = () => new Date().toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' });

    function caricaPdfLib() {
        if (window.PDFLib) return Promise.resolve();
        return new Promise((ok, ko) => {
            const s = document.createElement('script');
            s.src = PDF_LIB;
            s.onload = () => ok();
            s.onerror = () => ko(new Error('Impossibile caricare il componente per il salvataggio del PDF.'));
            document.head.appendChild(s);
        });
    }

    function costruisci() {
        if (dom) return;
        const ov = document.createElement('div');
        ov.className = 'bzc-overlay';
        ov.innerHTML = `
            <div class="bzc-top">
                <button type="button" class="bzc-chiudi" data-c="chiudi" title="Chiudi">&times;</button>
                <span class="bzc-titolo" data-el="titolo"></span>
                <div class="bzc-strumenti" data-el="strumenti">
                    <button type="button" class="bzc-tool on" data-tool="sposta" title="Seleziona e sposta"><i class="fas fa-arrow-pointer"></i></button>
                    <button type="button" class="bzc-tool" data-tool="testo" title="Testo: fare clic dove scrivere"><i class="fas fa-font"></i> Testo</button>
                    <button type="button" class="bzc-tool" data-tool="spunta" title="Spunta"><i class="fas fa-check"></i></button>
                    <button type="button" class="bzc-tool" data-tool="croce" title="Croce"><i class="fas fa-xmark"></i></button>
                    <button type="button" class="bzc-tool" data-tool="data" title="Data di oggi"><i class="fas fa-calendar-day"></i> Data</button>
                    <button type="button" class="bzc-tool" data-tool="firma" title="Firma"><i class="fas fa-signature"></i> Firma</button>
                </div>
                <div class="bzc-destra">
                    <button type="button" class="bzc-btn" data-c="annulla" title="Annulla l'ultimo inserimento"><i class="fas fa-rotate-left"></i></button>
                    <button type="button" class="bzc-btn" data-c="meno" title="Riduci">&minus;</button>
                    <span class="bzc-zoom" data-el="zoom">100%</span>
                    <button type="button" class="bzc-btn" data-c="piu" title="Ingrandisci">+</button>
                    <button type="button" class="bzc-btn primario" data-c="salva"><i class="fas fa-floppy-disk"></i> Salva</button>
                </div>
            </div>
            <div class="bzc-info" data-el="info"></div>
            <div class="bzc-corpo" data-el="corpo"><div class="bzc-pagine" data-el="pagine"></div></div>
            <div class="bzc-firmapad" data-el="firma" hidden>
                <div class="bzc-firma-box">
                    <h4>Firma</h4>
                    <p>Firmare nel riquadro con il mouse o con il dito.</p>
                    <canvas width="560" height="200" data-el="pad"></canvas>
                    <div class="bzc-firma-azioni">
                        <button type="button" class="bzc-btn" data-c="firma-pulisci">Cancella</button>
                        <span style="flex:1"></span>
                        <button type="button" class="bzc-btn" data-c="firma-annulla">Annulla</button>
                        <button type="button" class="bzc-btn primario" data-c="firma-ok">Usa questa firma</button>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(ov);
        const q = n => ov.querySelector(`[data-el="${n}"]`);
        dom = { ov, titolo: q('titolo'), strumenti: q('strumenti'), info: q('info'), corpo: q('corpo'), pagine: q('pagine'),
                zoom: q('zoom'), firma: q('firma'), pad: q('pad') };

        ov.addEventListener('click', e => {
            const c = e.target.closest('[data-c]')?.dataset.c;
            const t = e.target.closest('[data-tool]')?.dataset.tool;
            if (t) { strumento(t); return; }
            if (c === 'chiudi') chiudi();
            else if (c === 'salva') salva();
            else if (c === 'annulla') { const x = S.voci.pop(); if (x) { x.el.remove(); S.sporco = true; } }
            else if (c === 'piu') zoom(S.zoom * 1.25);
            else if (c === 'meno') zoom(S.zoom / 1.25);
            else if (c === 'firma-pulisci') pulisciPad();
            else if (c === 'firma-annulla') { dom.firma.hidden = true; strumento('sposta'); }
            else if (c === 'firma-ok') confermaFirma();
            const x = e.target.closest('[data-togli]');
            if (x) { const v = S.voci.find(v => v.id === x.dataset.togli); if (v) { v.el.remove(); S.voci = S.voci.filter(y => y !== v); S.sporco = true; } }
        });
        dom.pagine.addEventListener('pointerdown', suPagina);
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && S && dom.ov.classList.contains('open') && dom.firma.hidden) chiudi(); });
        preparaPad();
    }

    // ── Apertura ────────────────────────────────────────────────────────────
    async function apri(cfg) {
        if (!window.pdfjsLib) { alert('Visualizzatore PDF non disponibile.'); return; }
        costruisci();
        S = { ...cfg, zoom: 1, tool: 'sposta', campi: [], voci: [], pagine: [], sporco: false, firmaPng: null, n: 0 };
        dom.titolo.textContent = 'Compilazione · ' + (cfg.nome || 'Documento');
        dom.pagine.innerHTML = '<div class="bzc-carico">Caricamento del documento...</div>';
        dom.info.textContent = '';
        dom.ov.classList.add('open');
        strumento('sposta');
        try {
            const r = await fetch(cfg.url, { credentials: 'same-origin' });
            if (!r.ok) throw new Error();
            S.bytes = new Uint8Array(await r.arrayBuffer());
            const pdf = await window.pdfjsLib.getDocument({ data: S.bytes.slice() }).promise;
            dom.pagine.innerHTML = '';
            for (let i = 1; i <= pdf.numPages; i++) await pagina(pdf, i);
            dom.info.innerHTML = S.infoBase = S.campi.length
                ? `<i class="fas fa-circle-info"></i> Il documento ha ${S.campi.length} campi compilabili: compilarli direttamente. Con gli strumenti in alto si possono aggiungere testo, spunte, data e firma.`
                : '<i class="fas fa-circle-info"></i> Il documento non ha campi compilabili: scegliere uno strumento in alto e fare clic sul punto del documento in cui inserirlo.';
            zoom(1);
        } catch (e) {
            dom.pagine.innerHTML = '<div class="bzc-carico">Impossibile aprire il documento.</div>';
        }
    }

    async function pagina(pdf, n) {
        const pg = await pdf.getPage(n);
        const vp = pg.getViewport({ scale: 1 });
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const resa = pg.getViewport({ scale: 2 * dpr });
        const box = document.createElement('div');
        box.className = 'bzc-pagina';
        box.dataset.pagina = String(n - 1);
        box.style.aspectRatio = `${vp.width} / ${vp.height}`;
        const tela = document.createElement('canvas');
        tela.width = resa.width; tela.height = resa.height;
        await pg.render({ canvasContext: tela.getContext('2d'), viewport: resa }).promise;
        const strato = document.createElement('div');
        strato.className = 'bzc-strato';
        box.append(tela, strato);
        dom.pagine.appendChild(box);
        S.pagine.push({ box, strato, w: vp.width, h: vp.height });

        // i campi del modulo PDF, uno sopra l'altro nel punto giusto
        const annotazioni = await pg.getAnnotations({ intent: 'display' });
        for (const a of annotazioni) {
            if (a.subtype !== 'Widget' || !a.fieldName || a.hidden) continue;
            const [x1, y1, x2, y2] = vp.convertToViewportRectangle(a.rect);
            const pos = `left:${Math.min(x1, x2) / vp.width * 100}%;top:${Math.min(y1, y2) / vp.height * 100}%;width:${Math.abs(x2 - x1) / vp.width * 100}%;height:${Math.abs(y2 - y1) / vp.height * 100}%;`;
            let el = null, tipo = null;
            if (a.fieldType === 'Tx') {
                tipo = 'testo';
                el = document.createElement(a.multiLine ? 'textarea' : 'input');
                el.value = typeof a.fieldValue === 'string' ? a.fieldValue : '';
                if (a.maxLen) el.maxLength = a.maxLen;
            } else if (a.fieldType === 'Btn' && a.checkBox) {
                tipo = 'spunta';
                el = document.createElement('input'); el.type = 'checkbox';
                el.checked = !!a.fieldValue && a.fieldValue !== 'Off';
            } else if (a.fieldType === 'Btn' && a.radioButton) {
                tipo = 'scelta';
                el = document.createElement('input'); el.type = 'radio';
                el.name = 'bzc-' + a.fieldName; el.value = a.buttonValue;
                el.checked = a.fieldValue === a.buttonValue;
            } else if (a.fieldType === 'Ch') {
                tipo = 'elenco';
                el = document.createElement('select');
                el.innerHTML = '<option value=""></option>' + (a.options || []).map(o => `<option value="${esc(o.exportValue)}">${esc(o.displayValue)}</option>`).join('');
                el.value = Array.isArray(a.fieldValue) ? (a.fieldValue[0] || '') : (a.fieldValue || '');
            }
            if (!el) continue;
            el.className = 'bzc-campo';
            el.style.cssText = pos;
            if (a.readOnly) el.disabled = true;
            el.addEventListener('input', () => { S.sporco = true; });
            el.addEventListener('change', () => { S.sporco = true; });
            strato.appendChild(el);
            S.campi.push({ nome: a.fieldName, tipo, el, valore: a.buttonValue });
        }
    }

    function zoom(z) {
        S.zoom = Math.max(0.5, Math.min(4, z));
        const largo = Math.min(dom.corpo.clientWidth - 48, 900) * S.zoom;
        S.pagine.forEach(p => {
            p.box.style.width = largo + 'px';
            // 1 punto PDF in px sullo schermo: le scritte restano in proporzione
            p.box.style.setProperty('--k', String(largo / p.w));
        });
        dom.zoom.textContent = Math.round(S.zoom * 100) + '%';
    }

    function strumento(t) {
        if (!S) return;
        S.tool = t;
        dom.strumenti.querySelectorAll('[data-tool]').forEach(b => b.classList.toggle('on', b.dataset.tool === t));
        dom.pagine.classList.toggle('bzc-mirino', t !== 'sposta');
        if (t === 'firma') { pulisciPad(); dom.firma.hidden = false; }
    }

    // ── Inserimenti sul documento ───────────────────────────────────────────
    function suPagina(e) {
        if (!S || e.target.closest('.bzc-campo, .bzc-voce')) return;
        const box = e.target.closest('.bzc-pagina');
        if (!box || S.tool === 'sposta') return;
        const r = box.getBoundingClientRect();
        const fx = (e.clientX - r.left) / r.width, fy = (e.clientY - r.top) / r.height;
        const p = +box.dataset.pagina;
        if (S.tool === 'testo') aggiungi({ tipo: 'testo', p, fx, fy, testo: '' }, true);
        else if (S.tool === 'data') aggiungi({ tipo: 'testo', p, fx, fy, testo: oggi() });
        else if (S.tool === 'spunta' || S.tool === 'croce') aggiungi({ tipo: S.tool, p, fx, fy });
        else if (S.tool === 'firma' && S.firmaPng) { aggiungi({ tipo: 'firma', p, fx, fy, png: S.firmaPng, larghezza: 150 }); strumento('sposta'); dom.info.innerHTML = S.infoBase || ''; }
        e.preventDefault();
    }

    function aggiungi(v, scrivi) {
        v.id = 'v' + (++S.n);
        const pg = S.pagine[v.p];
        const el = document.createElement('div');
        el.className = 'bzc-voce bzc-' + v.tipo;
        el.style.left = (v.fx * 100) + '%';
        el.style.top = (v.fy * 100) + '%';
        const maniglia = '<span class="bzc-maniglia" title="Trascinare per spostare"><i class="fas fa-grip-vertical"></i></span>';
        const togli = `<button type="button" class="bzc-togli" data-togli="${v.id}" title="Rimuovi">×</button>`;
        if (v.tipo === 'testo') {
            el.innerHTML = maniglia + `<div class="bzc-scritto" contenteditable="true" spellcheck="false">${esc(v.testo)}</div>` + togli;
            const ed = el.querySelector('.bzc-scritto');
            ed.addEventListener('input', () => { S.sporco = true; });
            if (scrivi) setTimeout(() => ed.focus(), 0);
        } else if (v.tipo === 'spunta' || v.tipo === 'croce') {
            el.innerHTML = maniglia + `<span class="bzc-segno">${v.tipo === 'spunta' ? '✓' : '✗'}</span>` + togli;
        } else if (v.tipo === 'firma') {
            el.innerHTML = maniglia + `<img src="${v.png}" alt="Firma" style="width:calc(var(--k) * ${v.larghezza}px)">` + togli;
        }
        pg.strato.appendChild(el);
        v.el = el;
        S.voci.push(v);
        S.sporco = true;
        trascinabile(v);
    }

    function trascinabile(v) {
        const h = v.el.querySelector('.bzc-maniglia');
        h.addEventListener('pointerdown', e => {
            e.preventDefault(); e.stopPropagation();
            h.setPointerCapture(e.pointerId);
            const box = S.pagine[v.p].box.getBoundingClientRect();
            const muovi = ev => {
                v.fx = Math.max(0, Math.min(0.98, (ev.clientX - box.left) / box.width));
                v.fy = Math.max(0, Math.min(0.98, (ev.clientY - box.top) / box.height));
                v.el.style.left = (v.fx * 100) + '%';
                v.el.style.top = (v.fy * 100) + '%';
                S.sporco = true;
            };
            const fine = () => { h.removeEventListener('pointermove', muovi); h.removeEventListener('pointerup', fine); };
            h.addEventListener('pointermove', muovi);
            h.addEventListener('pointerup', fine);
        });
    }

    // ── Firma ───────────────────────────────────────────────────────────────
    function preparaPad() {
        const c = dom.pad, ctx = c.getContext('2d');
        let giu = false, ultimo = null;
        const pt = e => { const r = c.getBoundingClientRect(); return { x: (e.clientX - r.left) * c.width / r.width, y: (e.clientY - r.top) * c.height / r.height }; };
        c.addEventListener('pointerdown', e => { giu = true; ultimo = pt(e); c.setPointerCapture(e.pointerId); c.dataset.vuoto = '0'; });
        c.addEventListener('pointermove', e => {
            if (!giu) return;
            const p = pt(e);
            ctx.strokeStyle = '#0f172a'; ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
            ctx.beginPath(); ctx.moveTo(ultimo.x, ultimo.y); ctx.lineTo(p.x, p.y); ctx.stroke();
            ultimo = p;
        });
        c.addEventListener('pointerup', () => { giu = false; });
    }
    function pulisciPad() { dom.pad.getContext('2d').clearRect(0, 0, dom.pad.width, dom.pad.height); dom.pad.dataset.vuoto = '1'; }
    function confermaFirma() {
        if (dom.pad.dataset.vuoto !== '0') { alert('Inserire la firma nel riquadro.'); return; }
        S.firmaPng = dom.pad.toDataURL('image/png');
        dom.firma.hidden = true;
        S.tool = 'firma';
        dom.info.innerHTML = '<i class="fas fa-signature"></i> Fare clic sul punto del documento in cui apporre la firma.';
    }

    // ── Salvataggio ─────────────────────────────────────────────────────────
    async function salva() {
        const btn = dom.ov.querySelector('[data-c="salva"]');
        btn.disabled = true;
        const prima = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner bz-spin"></i> Salvataggio...';
        try {
            await caricaPdfLib();
            const { PDFDocument, StandardFonts, rgb, PDFTextField, PDFCheckBox, PDFRadioGroup, PDFDropdown, PDFOptionList } = window.PDFLib;
            const doc = await PDFDocument.load(S.bytes, { ignoreEncryption: true });
            const font = await doc.embedFont(StandardFonts.Helvetica);
            const segni = await doc.embedFont(StandardFonts.ZapfDingbats);
            const pulito = testoPerFont(font);

            if (S.campi.length) {
                const form = doc.getForm();
                const fatti = new Set();
                for (const c of S.campi) {
                    let f; try { f = form.getField(c.nome); } catch { continue; }
                    try {
                        if (f instanceof PDFTextField) f.setText(pulito(c.el.value || ''));
                        else if (f instanceof PDFCheckBox) c.el.checked ? f.check() : f.uncheck();
                        else if (f instanceof PDFRadioGroup) { if (c.el.checked) f.select(c.valore); }
                        else if ((f instanceof PDFDropdown || f instanceof PDFOptionList) && !fatti.has(c.nome)) { if (c.el.value) f.select(c.el.value); }
                    } catch (_) { /* un campo che non accetta il valore resta com'era */ }
                    fatti.add(c.nome);
                }
                try { form.updateFieldAppearances(font); } catch (_) {}
            }

            const pagine = doc.getPages();
            for (const v of S.voci) {
                const pg = pagine[v.p];
                if (!pg) continue;
                const cb = pg.getCropBox();
                const x = cb.x + v.fx * cb.width;
                const yAlto = cb.y + cb.height - v.fy * cb.height;
                if (v.tipo === 'testo') {
                    const righe = (v.el.querySelector('.bzc-scritto').innerText || '').split('\n');
                    righe.forEach((r, i) => {
                        if (r.trim() !== '') pg.drawText(pulito(r), { x: x + 2, y: yAlto - 11 * (i + 1), size: 11, font, color: rgb(0.05, 0.1, 0.3) });
                    });
                } else if (v.tipo === 'spunta' || v.tipo === 'croce') {
                    pg.drawText(v.tipo === 'spunta' ? '4' : '8', { x: x + 2, y: yAlto - 14, size: 14, font: segni, color: rgb(0.05, 0.1, 0.3) });
                } else if (v.tipo === 'firma') {
                    const img = await doc.embedPng(v.png);
                    const w = v.larghezza, h = w * img.height / img.width;
                    pg.drawImage(img, { x, y: yAlto - h, width: w, height: h });
                }
            }
            const out = await doc.save();
            await S.salva(new Blob([out], { type: 'application/pdf' }));
            S.sporco = false;
            chiudi();
        } catch (e) {
            alert(e && e.message ? e.message : 'Impossibile salvare il documento.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = prima;
        }
    }

    /** I caratteri che il font del PDF non sa scrivere diventano la lettera senza accento. */
    function testoPerFont(font) {
        const ok = new Map();
        const regge = ch => {
            if (!ok.has(ch)) { try { font.encodeText(ch); ok.set(ch, true); } catch { ok.set(ch, false); } }
            return ok.get(ch);
        };
        return s => Array.from(String(s)).map(ch => {
            if (regge(ch)) return ch;
            const base = ch.normalize('NFD').replace(/[̀-ͯ]/g, '');
            return base && regge(base) ? base : '?';
        }).join('');
    }

    function chiudi() {
        if (S && S.sporco && !confirm('Ci sono modifiche non salvate. Chiudere comunque? Le modifiche andranno perse.')) return;
        dom.ov.classList.remove('open');
        dom.pagine.innerHTML = '';
        dom.firma.hidden = true;
        S = null;
    }

    window.addEventListener('resize', () => { if (S && dom.ov.classList.contains('open')) zoom(S.zoom); });
    window.BZCompila = { apri };
})();
