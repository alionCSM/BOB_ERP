let cardN = 0;
let tsInstances  = {};
let consInstances = {};

function getDate() { return document.getElementById('pianDate').value; }

function changeDate(delta) {
    const inp = document.getElementById('pianDate');
    const d = new Date(inp.value);
    d.setDate(d.getDate() + delta);
    inp.value = d.toISOString().slice(0, 10);
    loadPiano();
}

function getAssignedIds() {
    const ids = new Set();
    Object.values(tsInstances).forEach(ts => {
        if (ts?.getValue) ts.getValue().forEach(v => ids.add(parseInt(v)));
    });
    return ids;
}

function updateStats() {
    const rows     = document.querySelectorAll('.pn-row');
    const assigned = getAssignedIds();
    let cons = 0;
    document.querySelectorAll('.pn-cons-qty-input').forEach(i => cons += parseInt(i.value) || 0);

    const nostri         = ALL_WORKERS.filter(w => w.is_nostro);
    const nostriAssigned = nostri.filter(w => assigned.has(w.id)).length;

    document.getElementById('statCantieri').textContent   = rows.length;
    document.getElementById('statNostri').textContent     = nostriAssigned;
    document.getElementById('statCons').textContent       = cons;
    document.getElementById('statDisponibili').textContent = nostri.length - nostriAssigned;
    document.getElementById('emptyState').style.display   = rows.length ? 'none' : '';

    updateSidebar();
}

function updateSidebar() {
    const assignedMap = getAssignedMap();
    const assignedIds = getAssignedIds();
    const container   = document.getElementById('sbList');
    const search      = (document.getElementById('sbSearch')?.value || '').toLowerCase();
    const nostri      = ALL_WORKERS.filter(w => w.is_nostro);

    let html = '';
    let availCount = 0;

    nostri.forEach(w => {
        const name       = w.last_name + ' ' + w.first_name;
        const isAssigned = assignedIds.has(w.id);
        const cantiere   = assignedMap[w.id] || '';
        if (!isAssigned) availCount++;
        if (search && !name.toLowerCase().includes(search)) return;

        if (isAssigned) {
            html += '<div class="pn-sb-item assigned">' +
                '<span class="pn-sb-dot taken"></span>' +
                '<span class="pn-sb-name">' + esc(name) + '</span>' +
                '<span class="pn-sb-where" title="' + esc(cantiere) + '">' + esc(cantiere) + '</span>' +
                '</div>';
        } else {
            html += '<div class="pn-sb-item">' +
                '<span class="pn-sb-dot available"></span>' +
                '<span class="pn-sb-name">' + esc(name) + '</span>' +
                '</div>';
        }
    });

    container.innerHTML = html;
    document.getElementById('sbCount').textContent = availCount + ' / ' + nostri.length;
}

function updateRowTags(id) {
    const row    = document.getElementById('row-' + id);
    if (!row) return;
    const tagsEl  = row.querySelector('.pn-row-tags');
    const workers = row.querySelectorAll('.pn-worker-row');
    let cons = 0;
    row.querySelectorAll('.pn-cons-qty-input').forEach(i => cons += parseInt(i.value) || 0);

    let html = '';
    workers.forEach((w, i) => {
        if (i < 3) html += '<span class="pn-tag pn-tag-blue">' + esc(w.dataset.workerName) + '</span>';
    });
    if (workers.length > 3) html += '<span class="pn-tag pn-tag-blue">+' + (workers.length - 3) + '</span>';
    if (cons > 0)            html += '<span class="pn-tag pn-tag-yellow">' + cons + ' cons.</span>';
    html += '<span class="pn-tag-count">' + (workers.length + cons) + '</span>';
    tagsEl.innerHTML = html;
}

function toggleRow(id) {
    const row = document.getElementById('row-' + id);
    if (row) row.classList.toggle('open');
}

function addRow(data) {
    const id      = ++cardN;
    const cantiere = data?.cantiere || '';
    const isOpen   = !data;

    const row = document.createElement('div');
    row.className    = 'pn-row' + (isOpen ? ' open' : '');
    row.id           = 'row-' + id;
    row.dataset.dbId = data?.id || '';

    // Build inner HTML without any inline event handlers
    row.innerHTML =
        '<div class="pn-row-head">' +
            '<svg class="pn-row-toggle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>' +
            '<input type="text" class="pn-row-name" placeholder="Nome cantiere..." value="' + esc(cantiere) + '">' +
            '<div class="pn-row-tags"></div>' +
            '<button class="pn-row-remove" title="Rimuovi cantiere">' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
            '</button>' +
        '</div>' +
        '<div class="pn-row-body">' +
            '<div class="pn-section">' +
                '<div class="pn-section-label">Nostri operai</div>' +
                '<div class="pn-workers" id="workers-' + id + '"></div>' +
                '<div class="pn-select-wrap"><select id="ws-' + id + '" multiple placeholder="Cerca operaio..."></select></div>' +
            '</div>' +
            '<div class="pn-section">' +
                '<div class="pn-section-label">Consorziate</div>' +
                '<div class="pn-consorziate" id="cons-' + id + '"></div>' +
                '<div class="pn-select-wrap"><select id="cs-' + id + '" placeholder="Cerca o aggiungi consorziata..."></select></div>' +
            '</div>' +
        '</div>';

    // Attach event listeners now that the elements exist
    const head       = row.querySelector('.pn-row-head');
    const nameInput  = row.querySelector('.pn-row-name');
    const removeBtn  = row.querySelector('.pn-row-remove');

    head.addEventListener('click', function (e) {
        // Don't toggle when clicking the name input or remove button
        if (!e.target.closest('.pn-row-name') && !e.target.closest('.pn-row-remove')) {
            toggleRow(id);
        }
    });
    nameInput.addEventListener('focus', function () {
        this.closest('.pn-row').classList.add('open');
    });
    removeBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        removeRow(id);
    });

    document.getElementById('pianoList').appendChild(row);
    initWorkerTS(id, data?.nostri || []);
    initConsTS(id, data?.consorziate || []);
    updateRowTags(id);
    updateStats();

    if (!data) nameInput.focus();
}

function getAssignedMap() {
    const map = {};
    document.querySelectorAll('.pn-row').forEach(row => {
        const name = row.querySelector('.pn-row-name')?.value?.trim() || '?';
        row.querySelectorAll('.pn-worker-row').forEach(wr => {
            if (wr.dataset.custom !== '1') map[wr.dataset.wid] = name;
        });
    });
    return map;
}

function initWorkerTS(rowId, preselected) {
    const el = document.getElementById('ws-' + rowId);
    if (!el) return;

    const ts = new TomSelect(el, {
        valueField:   'id',
        labelField:   'display',
        searchField:  ['display'],
        options:      ALL_WORKERS.map(w => ({ id: w.id, display: w.last_name + ' ' + w.first_name })),
        items:        preselected.filter(p => p.worker_id).map(p => p.worker_id),
        plugins:      ['remove_button'],
        maxItems:     null,
        create: function (input, callback) {
            const customId = 'custom_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5);
            callback({ id: customId, display: input.trim() });
        },
        createFilter: function (input) { return input.trim().length >= 2; },
        placeholder:  'Cerca o scrivi nome...',
        onItemAdd(value) {
            const isCustom = String(value).startsWith('custom_');
            if (!isCustom) {
                const wid = parseInt(value);
                const assignedMap = getAssignedMap();
                if (assignedMap[wid]) {
                    const w     = ALL_WORKERS.find(x => x.id == wid);
                    const wName = w ? w.last_name + ' ' + w.first_name : 'Operaio';
                    showWarn(rowId, wName + ' è già assegnato a: ' + assignedMap[wid]);
                }
            }
            const cName = isCustom ? (this.options[value]?.display || '') : undefined;
            addWorkerRow(rowId, value, '', '', cName);
            updateRowTags(rowId);
            updateStats();
        },
        onItemRemove(value) {
            removeWorkerRow(rowId, value);
            clearWarn(rowId);
            updateRowTags(rowId);
            updateStats();
        },
        render: {
            option(data, escape) {
                const takenBy = getAssignedMap()[parseInt(data.id)];
                if (takenBy) {
                    return '<div class="option" style="opacity:.45;background:#fef2f2;">' +
                        escape(data.display) +
                        ' <span style="color:#dc2626;font-size:10px;font-weight:600;">⚠ ' + esc(takenBy) + '</span>' +
                        '</div>';
                }
                return '<div class="option">' + escape(data.display) + '</div>';
            },
            option_create(data, escape) {
                return '<div class="create" style="padding:8px 12px;color:#2563eb;font-style:italic;">Aggiungi <strong>' + escape(data.input) + '</strong>...</div>';
            },
        },
    });
    tsInstances['r' + rowId] = ts;

    // Add preselected workers (both DB workers and custom names)
    preselected.forEach(p => {
        if (p.worker_id) {
            addWorkerRow(rowId, p.worker_id, p.auto_targa, p.note);
        } else if (p.worker_name) {
            const customId = 'custom_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5);
            ts.addOption({ id: customId, display: p.worker_name });
            ts.addItem(customId, true);
            addWorkerRow(rowId, customId, p.auto_targa, p.note, p.worker_name);
        }
    });
}

function showWarn(rowId, msg) {
    clearWarn(rowId);
    const container = document.getElementById('workers-' + rowId)?.parentElement;
    if (!container) return;
    const warn = document.createElement('div');
    warn.className = 'pn-warn';
    warn.id        = 'warn-' + rowId;
    warn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' + esc(msg);
    container.appendChild(warn);
    setTimeout(() => warn.remove(), 5000);
}

function clearWarn(rowId) {
    document.getElementById('warn-' + rowId)?.remove();
}

function addWorkerRow(rowId, workerId, targa, note, customName) {
    const isCustom = String(workerId).startsWith('custom_');
    let name;
    if (isCustom) {
        name = customName || tsInstances['r' + rowId]?.getOption(workerId)?.textContent?.trim() || 'Sconosciuto';
    } else {
        const w = ALL_WORKERS.find(x => x.id == workerId);
        if (!w) return;
        name = w.last_name + ' ' + w.first_name;
    }
    const container = document.getElementById('workers-' + rowId);
    if (container.querySelector('[data-wid="' + workerId + '"]')) return;

    const row = document.createElement('div');
    row.className        = 'pn-worker-row';
    row.dataset.wid      = workerId;
    row.dataset.workerName = name;
    if (isCustom) row.dataset.custom = '1';

    row.innerHTML =
        '<span class="pn-worker-name">' + (isCustom ? '✎ ' : '') + esc(name) + '</span>' +
        '<input type="text" class="pn-worker-targa" placeholder="Targa" value="' + esc(targa || '') + '" data-field="targa">' +
        '<button class="pn-worker-remove">' +
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
        '</button>';

    row.querySelector('.pn-worker-remove').addEventListener('click', function () {
        removeWorkerFromRow(rowId, workerId);
    });

    container.appendChild(row);
}

function removeWorkerRow(rowId, workerId) {
    document.getElementById('workers-' + rowId)?.querySelector('[data-wid="' + workerId + '"]')?.remove();
}

function removeWorkerFromRow(rowId, workerId) {
    const ts = tsInstances['r' + rowId];
    if (ts) ts.removeItem(String(workerId), true);
    removeWorkerRow(rowId, workerId);
    updateRowTags(rowId);
    updateStats();
}

function getConsTotalAssigned(companyName) {
    let total = 0;
    document.querySelectorAll('.pn-cons-row').forEach(row => {
        if (row.dataset.consName === companyName) {
            total += parseInt(row.querySelector('.pn-cons-qty-input')?.value) || 0;
        }
    });
    return total;
}

function checkConsCapacity(consRow) {
    const name     = consRow.dataset.consName;
    if (!name) { consRow.classList.remove('pn-over'); return; }

    const comp          = ALL_CONSORZIATE.find(c => c.name === name);
    const capacity      = comp ? parseInt(comp.tot_workers) : 0;
    const totalAssigned = getConsTotalAssigned(name);

    consRow.querySelector('.pn-cons-warn')?.remove();

    if (capacity > 0 && totalAssigned > capacity) {
        consRow.classList.add('pn-over');
        const warn = document.createElement('div');
        warn.className  = 'pn-warn pn-warn-amber pn-cons-warn';
        warn.style.margin = '4px 0 0';
        warn.innerHTML  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
            esc(name) + ': ' + totalAssigned + ' assegnati su ' + capacity + ' disponibili';
        consRow.after(warn);
    } else {
        consRow.classList.remove('pn-over');
    }
}

function checkAllConsCapacity() {
    document.querySelectorAll('.pn-cons-warn').forEach(w => w.remove());
    document.querySelectorAll('.pn-cons-row').forEach(row => checkConsCapacity(row));
}

function initConsTS(rowId, preselected) {
    const el = document.getElementById('cs-' + rowId);
    if (!el) return;

    const ts = new TomSelect(el, {
        valueField:  'name',
        labelField:  'label',
        searchField: ['name', 'label'],
        options: ALL_CONSORZIATE.map(c => {
            const cap      = parseInt(c.tot_workers) || 0;
            const capLabel = cap > 0 ? ' (' + cap + ' disp.)' : '';
            return { name: c.name, label: c.name + capLabel };
        }),
        plugins:      ['remove_button'],
        maxItems:     null,
        create: function (input, callback) {
            callback({ name: input.trim(), label: input.trim() + ' (nuova)' });
        },
        createFilter: function (input) { return input.trim().length >= 2; },
        placeholder:  'Cerca o aggiungi consorziata...',
        onItemAdd(value) {
            addConsRow(rowId, { azienda_nome: value });
            updateRowTags(rowId);
            updateStats();
        },
        onItemRemove(value) {
            removeConsRow(rowId, value);
            checkAllConsCapacity();
            updateRowTags(rowId);
            updateStats();
        },
        render: {
            option_create(data, escape) {
                return '<div class="create" style="padding:8px 12px;color:#2563eb;font-style:italic;">Aggiungi <strong>' + escape(data.input) + '</strong>...</div>';
            },
        },
    });
    consInstances['r' + rowId] = ts;

    if (preselected && preselected.length) {
        preselected.forEach(p => {
            if (!ALL_CONSORZIATE.find(c => c.name === p.azienda_nome)) {
                ts.addOption({ name: p.azienda_nome, label: p.azienda_nome });
            }
            ts.addItem(p.azienda_nome, true);
            addConsRow(rowId, p);
        });
    }
}

function addConsRow(rowId, data) {
    const container = document.getElementById('cons-' + rowId);
    const consName  = data?.azienda_nome || '';
    if (container.querySelector('[data-cons-name="' + CSS.escape(consName) + '"]')) return;

    const row = document.createElement('div');
    row.className       = 'pn-cons-row';
    row.dataset.consName = consName;

    row.innerHTML =
        '<span class="pn-cons-name" style="font-weight:600;min-width:120px;">' + esc(consName) + '</span>' +
        '<input type="number" class="pn-cons-qty pn-cons-qty-input" min="1" value="' + (data?.quantita || 1) + '">' +
        '<span style="font-size:10px;color:#92400e;">pers.</span>' +
        '<input type="text" class="pn-cons-note" placeholder="Targa" value="' + esc(data?.note || '') + '">' +
        '<button class="pn-cons-remove" title="Rimuovi">' +
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
        '</button>';

    row.querySelector('.pn-cons-qty-input').addEventListener('change', function () {
        checkAllConsCapacity();
        updateRowTags(rowId);
        updateStats();
    });
    row.querySelector('.pn-cons-remove').addEventListener('click', function () {
        removeConsFromRow(rowId, consName);
    });

    container.appendChild(row);
    checkAllConsCapacity();
}

function removeConsFromRow(rowId, consName) {
    const cs = consInstances['r' + rowId];
    if (cs) cs.removeItem(consName, true);
    removeConsRow(rowId, consName);
    updateRowTags(rowId);
    updateStats();
}

function removeConsRow(rowId, consName) {
    document.getElementById('cons-' + rowId)
        ?.querySelector('[data-cons-name="' + CSS.escape(consName) + '"]')
        ?.remove();
    checkAllConsCapacity();
}

function removeRow(id) {
    if (!confirm('Rimuovere questo cantiere?')) return;
    const ts = tsInstances['r' + id];
    if (ts) { ts.destroy(); delete tsInstances['r' + id]; }
    const cs = consInstances['r' + id];
    if (cs) { cs.destroy(); delete consInstances['r' + id]; }
    document.getElementById('row-' + id)?.remove();
    updateStats();
}

function collectData() {
    const result = { data: getDate(), cantieri: [] };
    document.querySelectorAll('.pn-row').forEach(row => {
        const cantiere = row.querySelector('.pn-row-name')?.value?.trim();
        if (!cantiere) return;

        const nostri = [];
        row.querySelectorAll('.pn-worker-row').forEach(wr => {
            const wid      = wr.dataset.wid;
            const isCustom = wr.dataset.custom === '1';
            nostri.push({
                worker_id:   isCustom ? 0 : parseInt(wid),
                worker_name: isCustom ? (wr.dataset.workerName || '') : '',
                auto_targa:  wr.querySelector('[data-field=targa]')?.value?.trim() || '',
                note:        '',
            });
        });

        const consorziate = [];
        row.querySelectorAll('.pn-cons-row').forEach(cr => {
            const nome = cr.dataset.consName;
            if (!nome) return;
            consorziate.push({
                azienda_nome: nome,
                quantita:     parseInt(cr.querySelector('.pn-cons-qty-input')?.value) || 1,
                note:         cr.querySelector('.pn-cons-note')?.value?.trim() || '',
            });
        });

        result.cantieri.push({ db_id: row.dataset.dbId || '', cantiere, nostri, consorziate });
    });
    return result;
}

async function savePiano() {
    const data = collectData();
    if (!data.data) { alert('Seleziona una data.'); return; }
    try {
        const r   = await fetch('/pianificazione/save', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(data),
        });
        const res = await r.json();
        if (res.ok) { showToast('Piano salvato!'); loadPiano(); }
        else alert(res.error || 'Errore');
    } catch (e) { alert('Errore di rete'); }
}

async function loadPiano() {
    const date = getDate();
    if (!date) return;
    Object.values(tsInstances).forEach(ts => { if (ts) ts.destroy(); });
    tsInstances = {};
    Object.values(consInstances).forEach(cs => { if (cs) cs.destroy(); });
    consInstances = {};
    cardN = 0;
    document.getElementById('pianoList').querySelectorAll('.pn-row').forEach(r => r.remove());

    try {
        const r    = await fetch('/pianificazione/get?data=' + date);
        const data = await r.json();
        if (data.ok && data.cantieri?.length) data.cantieri.forEach(c => addRow(c));
    } catch (e) { console.error(e); }
    updateStats();
}

async function copyFromPrevious() {
    const cur = getDate();
    if (!cur) return;
    if (document.querySelectorAll('.pn-row').length > 0 &&
        !confirm('Il piano attuale verrà sostituito. Continuare?')) return;

    const d = new Date(cur);
    d.setDate(d.getDate() - 1);
    const prev = d.toISOString().slice(0, 10);

    try {
        const r   = await fetch('/pianificazione/copy', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ from_date: prev, to_date: cur }),
        });
        const res = await r.json();
        if (res.ok) { showToast('Piano copiato!'); loadPiano(); }
        else alert(res.error || 'Nessun piano ieri');
    } catch (e) { alert('Errore di rete'); }
}

function printPiano() {
    const date = getDate();
    if (date) window.open('/pianificazione/print?data=' + date, '_blank');
}

function esc(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function showToast(msg) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#10b981;color:#fff;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.15);';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2500);
}

// ── Static button wiring ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const pianDate = document.getElementById('pianDate');
    pianDate.value = new Date().toISOString().slice(0, 10);

    document.getElementById('pn-date-prev').addEventListener('click', function () { changeDate(-1); });
    document.getElementById('pn-date-next').addEventListener('click', function () { changeDate(1); });
    pianDate.addEventListener('change', loadPiano);

    document.getElementById('pn-copy-btn').addEventListener('click', copyFromPrevious);
    document.getElementById('pn-save-btn').addEventListener('click', savePiano);
    document.getElementById('pn-print-btn').addEventListener('click', printPiano);
    document.getElementById('pn-add-row-btn').addEventListener('click', function () { addRow(); });
    document.getElementById('sbSearch').addEventListener('input', updateSidebar);

    loadPiano();
});
