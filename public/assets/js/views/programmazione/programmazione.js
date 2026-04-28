const PG = (() => {
    let anno = parseInt(document.getElementById('pg-year')?.textContent ?? new Date().getFullYear(), 10);
    let mese = parseInt(document.querySelector('.pg-month-tab.active')?.dataset.mese ?? (new Date().getMonth() + 1), 10);
    let rows = [];
    let saveTimers = {};
    let dragSrcRow = null;

    const API = '/programmazione/api';

    const STATO_CYCLE = [null, 'in_lavorazione', 'completato'];
    const STATO_LABELS = {
        'null':           'Da fare',
        'in_lavorazione': 'In lavorazione',
        'completato':     'Completato'
    };
    const STATO_ICONS = {
        'null':            '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/></svg>',
        'in_lavorazione':  '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
        'completato':      '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
    };

    function toast(msg, ok = true, type = null) {
        const el = document.getElementById('pg-toast');
        el.textContent = msg;
        const cls = type === 'info' ? 'pg-toast-info' : (ok ? 'pg-toast-ok' : 'pg-toast-err');
        el.className = 'pg-toast ' + cls + ' show';
        setTimeout(() => el.classList.remove('show'), 3500);
    }

    function esc(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function nl2br(str) {
        return esc(str).replace(/\n/g, '<br>');
    }

    // ─── Status chip HTML ───────────────────
    function statoChip(rowId, field, value) {
        const key = value || 'null';
        return `<button class="pg-stato-chip" data-stato="${key}"
                    data-row-id="${rowId}" data-field="${field}">${STATO_ICONS[key]} ${STATO_LABELS[key]}</button>`;
    }

    // ─── Data loading ───────────────────────
    async function loadMonth(m, y) {
        mese = m; anno = y;
        document.getElementById('pg-year').textContent = anno;

        document.querySelectorAll('.pg-month-tab').forEach(t => {
            t.classList.toggle('active', parseInt(t.dataset.mese) === mese);
        });

        try {
            const res = await fetch(`${API}?action=list&mese=${mese}&anno=${anno}`);
            const data = await res.json();
            rows = data.rows || [];
            render();
            loadBadges();
            loadAlerts();
        } catch (e) {
            toast('Errore caricamento', false);
        }
    }

    async function loadBadges() {
        for (let m = 1; m <= 12; m++) {
            const badge = document.getElementById('pg-badge-' + m);
            if (!badge) continue;
            try {
                const res = await fetch(`${API}?action=list&mese=${m}&anno=${anno}`);
                const data = await res.json();
                const count = (data.rows || []).length;
                if (count > 0) { badge.textContent = count; badge.style.display = ''; }
                else { badge.style.display = 'none'; }
            } catch(e) {}
        }
    }

    async function loadAlerts() {
        try {
            const res = await fetch(`${API}?action=alerts`);
            const data = await res.json();
            const alerts = data.alerts || [];
            const wrap = document.getElementById('pg-alerts');
            const list = document.getElementById('pg-alerts-list');

            if (alerts.length === 0) {
                wrap.style.display = 'none';
                return;
            }

            wrap.style.display = '';
            document.getElementById('pg-alerts-count').textContent = alerts.length;

            list.innerHTML = alerts.map(a => `
                <div class="pg-alert-card ${a.urgency}">
                    <div class="pg-alert-days">
                        ${a.days_left}<small>${a.days_left === 1 ? 'giorno' : 'giorni'}</small>
                    </div>
                    <div class="pg-alert-body">
                        <div class="pg-alert-label">${esc(a.label)}</div>
                        <div style="font-size:11px;opacity:.7">Inizio: ${a.data}</div>
                        <div class="pg-alert-pending">
                            ${a.pending.map(p => `<span class="pg-alert-tag ${a.urgency}">${p}</span>`).join('')}
                        </div>
                    </div>
                </div>
            `).join('');
        } catch (e) {}
    }

    // ─── Render table ───────────────────────
    function render() {
        const tbody = document.getElementById('pg-body');
        document.getElementById('pg-count').textContent = rows.length;

        if (rows.length === 0) {
            tbody.innerHTML = `
                <tr><td colspan="11">
                    <div class="pg-empty" style="text-align:center;padding:60px 20px;color:var(--pg-muted)">
                        <div style="width:64px;height:64px;margin:0 auto 16px;background:var(--pg-primary-light);border-radius:16px;display:flex;align-items:center;justify-content:center">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <h3 style="font-size:16px;font-weight:700;color:var(--pg-text);margin:0 0 6px">Nessuna programmazione</h3>
                        <p style="font-size:13px;margin:0">Aggiungi la prima riga per iniziare</p>
                    </div>
                </td></tr>`;
            return;
        }

        tbody.innerHTML = rows.map((r, idx) => {
            // Row color logic
            const sm = r.stato_mezzi || null;
            const st = r.stato_trasferta || null;
            const sb = r.stato_beppe || null;
            const allDone = sm === 'completato' && st === 'completato' && sb === 'completato';
            const anyIncomplete = sm !== 'completato' || st !== 'completato' || sb !== 'completato';

            let rowClass = '';
            if (allDone) {
                rowClass = 'pg-row-green';
            } else if (r.data && anyIncomplete) {
                const today = new Date(); today.setHours(0,0,0,0);
                const start = new Date(r.data + 'T00:00:00');
                const daysLeft = Math.ceil((start - today) / 86400000);
                if (daysLeft <= 7 && daysLeft >= 0) {
                    rowClass = 'pg-row-red';
                } else if (anyIncomplete) {
                    rowClass = 'pg-row-orange';
                }
            } else if (anyIncomplete) {
                rowClass = 'pg-row-orange';
            }

            return `
            <tr data-id="${r.id}" data-idx="${idx}" class="${rowClass}" draggable="true">
                <td>
                    <div class="pg-row-actions">
                        <button class="pg-row-btn pg-drag" title="Trascina">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>
                        </button>
                        <button class="pg-row-btn pg-delete-btn" data-id="${r.id}" title="Elimina">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                        </button>
                    </div>
                </td>
                <td>
                    <input type="date" class="pg-date-input" value="${r.data || ''}" data-id="${r.id}">
                </td>
                <td><div class="pg-cell" contenteditable="true" data-field="indirizzo" data-id="${r.id}"
                         data-placeholder="Indirizzo...">${nl2br(r.indirizzo)}</div></td>
                <td><div class="pg-cell" contenteditable="true" data-field="committente" data-id="${r.id}"
                         data-placeholder="Committente...">${esc(r.committente)}</div></td>
                <td>
                    <div class="pg-cell" contenteditable="true" data-field="mezzi" data-id="${r.id}"
                         data-placeholder="Mezzi necessari...">${nl2br(r.mezzi)}</div>
                    ${statoChip(r.id, 'stato_mezzi', r.stato_mezzi)}
                </td>
                <td><div class="pg-cell" contenteditable="true" data-field="durata" data-id="${r.id}"
                         data-placeholder="Durata...">${esc(r.durata)}</div></td>
                <td><div class="pg-cell" contenteditable="true" data-field="referente" data-id="${r.id}"
                         data-placeholder="Referente + info...">${nl2br(r.referente)}</div></td>
                <td><div class="pg-cell" contenteditable="true" data-field="capo_squadra" data-id="${r.id}"
                         data-placeholder="Capo squadra...">${esc(r.capo_squadra)}</div></td>
                <td><div class="pg-cell" contenteditable="true" data-field="tot_persone" data-id="${r.id}"
                         data-placeholder="N.">${esc(r.tot_persone ? String(r.tot_persone) : '')}</div></td>
                <td>
                    <div class="pg-cell" contenteditable="true" data-field="trasferta" data-id="${r.id}"
                         data-placeholder="Trasferta...">${esc(r.trasferta)}</div>
                    ${statoChip(r.id, 'stato_trasferta', r.stato_trasferta)}
                </td>
                <td>
                    <div class="pg-cell" contenteditable="true" data-field="info_beppe" data-id="${r.id}"
                         data-placeholder="Info...">${nl2br(r.info_beppe)}</div>
                    ${statoChip(r.id, 'stato_beppe', r.stato_beppe)}
                </td>
            </tr>`;
        }).join('');

        // Attach cell events
        tbody.querySelectorAll('.pg-cell').forEach(cell => {
            cell.addEventListener('blur', onCellBlur);
            cell.addEventListener('paste', onCellPaste);
        });

        // Drag & drop
        tbody.querySelectorAll('tr[draggable]').forEach(tr => {
            tr.addEventListener('dragstart', onDragStart);
            tr.addEventListener('dragover', onDragOver);
            tr.addEventListener('dragleave', onDragLeave);
            tr.addEventListener('drop', onDrop);
            tr.addEventListener('dragend', onDragEnd);
        });
    }

    // ─── Status toggle ──────────────────────
    async function toggleStato(rowId, field) {
        const row = rows.find(r => r.id == rowId);
        if (!row) return;

        const current = row[field] || null;
        const idx = STATO_CYCLE.indexOf(current);
        const next = STATO_CYCLE[(idx + 1) % STATO_CYCLE.length];

        row[field] = next;
        render(); // Re-render to update badge

        try {
            await fetch(API + '?action=status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: rowId, field, value: next })
            });
            const label = {
                'stato_mezzi': 'Mezzi',
                'stato_trasferta': 'Trasferta',
                'stato_beppe': 'Info Beppe'
            }[field];
            const statusLabel = next === 'completato' ? 'completato' : next === 'in_lavorazione' ? 'in lavorazione' : 'da fare';
            toast(`${label}: ${statusLabel}`);
            loadAlerts(); // Refresh alerts
        } catch (e) {
            toast('Errore aggiornamento stato', false);
        }
    }

    // ─── Cell editing ───────────────────────
    function onCellBlur(e) {
        const cell = e.target;
        const id = parseInt(cell.dataset.id);
        const field = cell.dataset.field;
        let value = cell.innerHTML
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/<[^>]+>/g, '')
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&nbsp;/g, ' ')
            .trim();

        cellChanged(id, field, value);
    }

    function onCellPaste(e) {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text/plain');
        document.execCommand('insertText', false, text);
    }

    function cellChanged(id, field, value) {
        const row = rows.find(r => r.id == id);
        if (!row) return;
        if (row[field] === value) return;

        row[field] = value;
        debounceSave(id);

        // If a tracked field just got content, re-render to show status badge
        if (['mezzi', 'trasferta', 'info_beppe'].includes(field)) {
            render();
        }
    }

    function debounceSave(id) {
        if (saveTimers[id]) clearTimeout(saveTimers[id]);
        saveTimers[id] = setTimeout(() => saveRow(id), 600);
    }

    async function saveRow(id) {
        const row = rows.find(r => r.id == id);
        if (!row) return;

        try {
            const res = await fetch(API + '?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...row, mese, anno })
            });
            const data = await res.json();
            if (data.moved_to_month) {
                const mesiNomi = ['','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
                const monthName = mesiNomi[data.moved_to_month] || data.moved_to_month;
                toast(`Riga spostata in ${monthName} ${data.moved_to_year}`, true, 'info');
                // Remove from current view and reload
                rows = rows.filter(r => r.id != id);
                render();
                updateBadge();
                loadBadges();
            }
        } catch (e) {
            toast('Errore salvataggio', false);
        }
    }

    // ─── Add row ────────────────────────────
    async function addRow() {
        const newRow = {
            id: 0, mese, anno,
            data: '', indirizzo: '', committente: '', mezzi: '',
            stato_mezzi: null, durata: '', referente: '',
            capo_squadra: '', tot_persone: null,
            trasferta: '', stato_trasferta: null,
            info_beppe: '', stato_beppe: null,
            sort_order: rows.length
        };

        try {
            const res = await fetch(API + '?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(newRow)
            });
            const data = await res.json();
            if (data.ok) {
                newRow.id = data.id;
                rows.push(newRow);
                render();
                const lastRow = document.querySelector(`tr[data-id="${data.id}"]`);
                if (lastRow) {
                    const firstCell = lastRow.querySelector('.pg-date-input');
                    if (firstCell) firstCell.focus();
                }
                updateBadge();
            }
        } catch (e) {
            toast('Errore aggiunta riga', false);
        }
    }

    // ─── Delete row ─────────────────────────
    async function deleteRow(id) {
        if (!confirm('Eliminare questa riga?')) return;
        try {
            await fetch(API + '?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            rows = rows.filter(r => r.id != id);
            render();
            updateBadge();
            toast('Riga eliminata');
        } catch (e) { toast('Errore eliminazione', false); }
    }

    // ─── Drag & Drop ────────────────────────
    function onDragStart(e) {
        dragSrcRow = e.currentTarget;
        dragSrcRow.classList.add('pg-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', dragSrcRow.dataset.idx);
    }
    function onDragOver(e) {
        e.preventDefault(); e.dataTransfer.dropEffect = 'move';
        e.currentTarget.classList.add('pg-drag-over');
    }
    function onDragLeave(e) { e.currentTarget.classList.remove('pg-drag-over'); }
    function onDragEnd() {
        document.querySelectorAll('.pg-dragging, .pg-drag-over').forEach(el => {
            el.classList.remove('pg-dragging', 'pg-drag-over');
        });
    }
    async function onDrop(e) {
        e.preventDefault();
        const target = e.currentTarget;
        target.classList.remove('pg-drag-over');
        if (!dragSrcRow || dragSrcRow === target) return;
        const fromIdx = parseInt(dragSrcRow.dataset.idx);
        const toIdx = parseInt(target.dataset.idx);
        const [moved] = rows.splice(fromIdx, 1);
        rows.splice(toIdx, 0, moved);
        render();
        const order = rows.map(r => r.id);
        try {
            await fetch(API + '?action=reorder', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order })
            });
        } catch (e) { toast('Errore riordino', false); }
    }

    // ─── Navigation ─────────────────────────
    function switchMonth(m) { loadMonth(m, anno); }
    function changeYear(delta) { loadMonth(mese, anno + delta); }

    function updateBadge() {
        const badge = document.getElementById('pg-badge-' + mese);
        if (badge) {
            if (rows.length > 0) { badge.textContent = rows.length; badge.style.display = ''; }
            else { badge.style.display = 'none'; }
        }
    }

    // ─── Init ───────────────────────────────
    // Wrapped in DOMContentLoaded so listeners are attached after the
    // template framework (app.js) has finished its own initialization.
    document.addEventListener('DOMContentLoaded', () => {
        loadMonth(mese, anno);

        // Alerts toggle
        document.getElementById('pg-alerts-title')?.addEventListener('click', () => {
            const list = document.getElementById('pg-alerts-list');
            list.style.display = list.style.display === 'none' ? '' : 'none';
        });

        // Year navigation
        document.querySelectorAll('.pg-year-btn').forEach(btn => {
            btn.addEventListener('click', () => changeYear(parseInt(btn.dataset.dir, 10)));
        });

        // Month tabs (event delegation on container)
        document.getElementById('pg-months')?.addEventListener('click', e => {
            const tab = e.target.closest('.pg-month-tab');
            if (tab) switchMonth(parseInt(tab.dataset.mese, 10));
        });

        // Add row buttons
        document.getElementById('pg-add-row-btn')?.addEventListener('click', addRow);
        document.getElementById('pg-add-row-bottom')?.addEventListener('click', addRow);

        // Tbody event delegation: stato chips, delete buttons, date inputs
        document.getElementById('pg-body')?.addEventListener('click', e => {
            const chip = e.target.closest('.pg-stato-chip');
            if (chip) { toggleStato(parseInt(chip.dataset.rowId, 10), chip.dataset.field); return; }

            const del = e.target.closest('.pg-delete-btn');
            if (del) { deleteRow(parseInt(del.dataset.id, 10)); }
        });

        document.getElementById('pg-body')?.addEventListener('change', e => {
            const input = e.target.closest('.pg-date-input');
            if (input) cellChanged(parseInt(input.dataset.id, 10), 'data', input.value);
        });
    });

    return {};
})();
