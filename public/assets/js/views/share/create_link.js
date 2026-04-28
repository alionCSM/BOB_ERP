document.addEventListener("DOMContentLoaded", () => {
// Password auto-generate
    function generatePassword(length = 12) {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        const array = new Uint32Array(length);
        crypto.getRandomValues(array);
        return Array.from(array, v => chars[v % chars.length]).join('');
    }

    function setGeneratedPassword() {
        const pwd = generatePassword();
        document.getElementById('cl-password').value = pwd;
        document.getElementById('cl-password-show').value = pwd;
    }

    // Always generate password on page load (mandatory)
    setGeneratedPassword();

    document.getElementById("cl-pwd-regen").addEventListener("click", setGeneratedPassword);

    const createLinkForm    = document.getElementById("create-link-form");
    const createLinkSubmit  = document.getElementById("create-link-submit");
    const uploadProgressWrapper = document.getElementById("upload-progress-wrapper");
    const uploadProgressLabel   = document.getElementById("upload-progress-label");
    const uploadProgressBar     = document.getElementById("upload-progress-bar");
    const CHUNK_SIZE = 20 * 1024 * 1024;

    function setUploadProgress(percent, label) {
        uploadProgressWrapper.style.display = '';
        uploadProgressBar.style.width = `${Math.min(100, Math.max(0, Math.round(percent)))}%`;
        uploadProgressLabel.textContent = label;
    }

    function hidePlaceholder() {
        document.getElementById('docs-placeholder').style.display = 'none';
    }

    function uploadChunk(formData) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open("POST", "/share/upload-chunk", true);
            xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
            xhr.onload = function () {
                if (xhr.status < 200 || xhr.status >= 300) { reject(new Error("Chunk upload fallito")); return; }
                try {
                    const payload = JSON.parse(xhr.responseText);
                    if (!payload || payload.ok !== true) { reject(new Error(payload?.message || "Chunk upload non valido")); return; }
                    resolve(payload);
                } catch (e) { reject(new Error("Risposta chunk non valida")); }
            };
            xhr.onerror = () => reject(new Error("Errore di rete durante chunk upload"));
            xhr.send(formData);
        });
    }

    async function uploadManualFilesInChunks() {
        const fileInputs = [...createLinkForm.querySelectorAll('input[type="file"][name^="manual_docs"]')]
            .filter(input => input.files && input.files.length > 0);
        if (!fileInputs.length) return;
        const totalBytes = fileInputs.reduce((sum, input) => sum + input.files[0].size, 0);
        let uploadedBytes = 0;
        for (let fileIndex = 0; fileIndex < fileInputs.length; fileIndex++) {
            const input = fileInputs[fileIndex];
            const file  = input.files[0];
            const nameMatch = input.name.match(/manual_docs\[(\d+)\]\[file\]/);
            if (!nameMatch) throw new Error("Indice documento manuale non valido");
            const rowIndex    = nameMatch[1];
            const uploadId    = `${Date.now()}_${Math.random().toString(36).slice(2,10)}_${rowIndex}`;
            const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
            let uploadedToken = "";
            for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                const start = chunkIndex * CHUNK_SIZE;
                const end   = Math.min(start + CHUNK_SIZE, file.size);
                const fd    = new FormData();
                fd.append("_csrf", document.querySelector('input[name="_csrf"]').value);
                fd.append("upload_id", uploadId);
                fd.append("chunk_index", String(chunkIndex));
                fd.append("total_chunks", String(totalChunks));
                fd.append("filename", file.name);
                fd.append("chunk", file.slice(start, end), file.name);
                const response = await uploadChunk(fd);
                if (response.uploaded_token) uploadedToken = response.uploaded_token;
                uploadedBytes += (end - start);
                const pct = totalBytes > 0 ? (uploadedBytes / totalBytes) * 100 : 100;
                setUploadProgress(pct, `Caricamento file ${fileIndex+1}/${fileInputs.length}: ${Math.round(pct)}%`);
            }
            if (!uploadedToken) throw new Error("Token upload non ricevuto");
            const tokenInput = document.createElement("input");
            tokenInput.type  = "hidden";
            tokenInput.name  = `manual_docs[${rowIndex}][uploaded_token]`;
            tokenInput.value = uploadedToken;
            createLinkForm.appendChild(tokenInput);
            input.removeAttribute("name");
        }
        setUploadProgress(100, "Upload completato. Salvataggio link…");
    }

    function submitCreateLink(formData, hasManualUploads) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open("POST", createLinkForm.action || window.location.href, true);
            xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
            xhr.onload = function () {
                if (xhr.status < 200 || xhr.status >= 300) { reject(new Error("Errore durante la creazione del link.")); return; }
                try {
                    const payload = JSON.parse(xhr.responseText);
                    if (!payload || payload.ok !== true) { reject(new Error(payload?.message || "Impossibile creare il link.")); return; }
                    resolve(payload);
                } catch (e) { reject(new Error("Risposta non valida dal server.")); }
            };
            xhr.onerror = () => reject(new Error("Errore di rete durante il caricamento."));
            if (!hasManualUploads) {
                xhr.upload.addEventListener("progress", evt => {
                    if (!evt.lengthComputable) return;
                    const pct = Math.min(100, Math.round((evt.loaded / evt.total) * 100));
                    setUploadProgress(pct, `Invio richiesta: ${pct}%`);
                });
            }
            xhr.send(formData);
        });
    }

    createLinkForm.addEventListener("submit", async function (event) {
        event.preventDefault();
        const hasManualUploads = [...createLinkForm.querySelectorAll('input[type="file"][name^="manual_docs"]')]
            .some(input => input.files && input.files.length > 0);
        createLinkSubmit.disabled = true;
        createLinkSubmit.textContent = hasManualUploads ? "Upload in corso…" : "Creazione…";
        try {
            if (hasManualUploads) { setUploadProgress(0, "Avvio upload…"); await uploadManualFilesInChunks(); }
            const payload = await submitCreateLink(new FormData(createLinkForm), hasManualUploads);
            setUploadProgress(100, "Completato! Reindirizzamento…");
            // Pass password + token via hash so list page can offer copy with password
            const pwd = document.getElementById('cl-password').value;
            let redirect = payload.redirect || "/share";
            if (pwd && payload.link_token) {
                redirect += '#pwd=' + encodeURIComponent(pwd) + '&token=' + encodeURIComponent(payload.link_token);
            }
            window.location.href = redirect;
        } catch (error) {
            alert(error.message || "Errore durante il caricamento.");
        } finally {
            createLinkSubmit.disabled = false;
            createLinkSubmit.textContent = "Crea Link";
        }
    });

    /* ── Workers search & select ── */
    document.getElementById("search-workers").addEventListener("input", function () {

        const tokens = this.value
            .toLowerCase()
            .trim()
            .split(/\s+/)
            .filter(Boolean);

        document.querySelectorAll("#workers-list tr").forEach(tr => {

            const text = tr.textContent.toLowerCase();

            const match = tokens.every(token => text.includes(token));

            tr.style.display = match ? "" : "none";
        });

        document.getElementById("select-all-workers").checked = false;
    });
    document.getElementById("select-all-workers").addEventListener("change", function () {
        document.querySelectorAll("#workers-list tr").forEach(row => {
            if (row.style.display !== "none") {
                const cb = row.querySelector(".worker-checkbox");
                if (cb) cb.checked = this.checked;
            }
        });
    });
    document.getElementById("confirm-workers").addEventListener("click", function () {
        const selected = [...document.querySelectorAll(".worker-checkbox:checked")].map(cb => cb.value);
        if (!selected.length) { alert("Seleziona almeno un lavoratore."); return; }
        document.getElementById("workers-hidden").value = JSON.stringify(selected);
        // update badge
        const badge = document.getElementById('workers-badge');
        badge.textContent = selected.length + ' selezionat' + (selected.length === 1 ? 'o' : 'i');
        badge.style.display = '';
        document.getElementById('btn-sel-workers').classList.add('has-selection');
        tailwind.Modal.getOrCreateInstance(document.querySelector('#select-workers-modal')).hide();
        loadWorkerDocuments(selected);
    });

    /* ── Worker docs (dynamic — all docs shared live) ── */
    function loadWorkerDocuments(workerIds) {
        const container = document.getElementById("workers-documents");
        const loader    = document.getElementById("loader");
        const section   = document.getElementById("documents-section");
        container.innerHTML = "";
        section.style.display = 'none';
        loader.style.display  = '';
        fetch("/share/fetch-worker-documents-multiple?ids=" + encodeURIComponent(JSON.stringify(workerIds)))
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => {
                loader.style.display = 'none';
                hidePlaceholder();
                Object.keys(data).forEach(workerId => {
                    const w = data[workerId];
                    const initials = w.worker.split(' ').map(p => p[0]).slice(0,2).join('').toUpperCase();
                    const docCount = w.documents ? w.documents.length : 0;
                    const box = document.createElement('div');
                    box.className = 'cl-entity-block';
                    box.id = `worker-card-${workerId}`;
                    box.innerHTML = `
                        <div class="cl-entity-head">
                            <div class="cl-entity-avatar">${initials}</div>
                            <div style="flex:1;">
                                <div class="cl-entity-name">${w.worker}</div>
                                <div class="cl-entity-company">${w.company}</div>
                            </div>
                            <span style="font-size:11px;font-weight:600;color:#64748b;margin-right:8px;">${docCount} doc</span>
                            <span style="display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:600;padding:2px 8px;border-radius:12px;background:#dcfce7;color:#15803d;">
                                <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="5" fill="currentColor"/></svg>
                                LIVE
                            </span>
                            <button type="button" class="cl-entity-remove" onclick="removeWorker(${workerId})">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>`;
                    container.appendChild(box);
                });
                section.style.display = '';
            })
            .catch(err => { loader.style.display = 'none'; console.error('loadWorkerDocuments error:', err); alert('Errore caricamento operai: ' + err.message); });
    }

    function removeWorker(workerId) {
        document.getElementById(`worker-card-${workerId}`)?.remove();
        let sel = JSON.parse(document.getElementById("workers-hidden").value || '[]');
        sel = sel.filter(id => id != workerId);
        document.getElementById("workers-hidden").value = JSON.stringify(sel);
        const badge = document.getElementById('workers-badge');
        if (sel.length === 0) {
            document.getElementById("documents-section").style.display = 'none';
            badge.style.display = 'none';
            document.getElementById('btn-sel-workers').classList.remove('has-selection');
        } else {
            badge.textContent = sel.length + ' selezionat' + (sel.length === 1 ? 'o' : 'i');
        }
    }

    /* ── Companies search & select ── */
    document.getElementById("search-company").addEventListener("input", function () {
        const q = this.value.toLowerCase();
        document.getElementById("select-all-companies").checked = false;
        document.querySelectorAll("#company-list tr").forEach(tr => {
            tr.style.display = tr.textContent.toLowerCase().includes(q) ? "" : "none";
        });
    });
    document.getElementById("select-all-companies").addEventListener("change", function () {
        document.querySelectorAll("#company-list tr").forEach(row => {
            if (row.style.display !== "none") {
                const cb = row.querySelector(".company-checkbox");
                if (cb) cb.checked = this.checked;
            }
        });
    });
    document.getElementById("confirm-company").addEventListener("click", function () {
        const selected = [...document.querySelectorAll(".company-checkbox:checked")].map(cb => cb.value);
        if (!selected.length) { alert("Seleziona almeno una azienda."); return; }
        document.getElementById("companies-hidden").value = JSON.stringify(selected);
        const badge = document.getElementById('companies-badge');
        badge.textContent = selected.length + ' selezionat' + (selected.length === 1 ? 'a' : 'e');
        badge.style.display = '';
        document.getElementById('btn-sel-companies').classList.add('has-selection');
        tailwind.Modal.getOrCreateInstance(document.querySelector('#select-company-modal')).hide();
        loadCompanyDocuments(selected);
    });

    /* ── Company docs (dynamic — all docs shared live) ── */
    function loadCompanyDocuments(companyIds) {
        const container = document.getElementById("company-documents");
        const section   = document.getElementById("company-documents-section");
        container.innerHTML = "";
        section.style.display = 'none';
        fetch("/share/fetch-company-documents?ids=" + encodeURIComponent(JSON.stringify(companyIds)))
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => {
                hidePlaceholder();
                Object.keys(data).forEach(companyId => {
                    const c = data[companyId];
                    const initials = c.company.split(' ').map(p => p[0]).slice(0,2).join('').toUpperCase();
                    const docCount = c.documents ? c.documents.length : 0;
                    const box = document.createElement('div');
                    box.className = 'cl-entity-block';
                    box.dataset.companyRemove = companyId;
                    box.innerHTML = `
                        <div class="cl-entity-head">
                            <div class="cl-entity-avatar" style="background:linear-gradient(135deg,#1d4ed8,#0ea5e9);">${initials}</div>
                            <div style="flex:1;">
                                <div class="cl-entity-name">${c.company}</div>
                            </div>
                            <span style="font-size:11px;font-weight:600;color:#64748b;margin-right:8px;">${docCount} doc</span>
                            <span style="display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:600;padding:2px 8px;border-radius:12px;background:#dcfce7;color:#15803d;">
                                <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="5" fill="currentColor"/></svg>
                                LIVE
                            </span>
                            <button type="button" class="cl-entity-remove" onclick="removeCompanyBlock(${companyId})">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>`;
                    container.appendChild(box);
                });
                section.style.display = '';
            })
            .catch(err => { console.error('loadCompanyDocuments error:', err); alert('Errore caricamento aziende: ' + err.message); });
    }

    function removeCompanyBlock(companyId) {
        document.querySelector(`[data-company-remove="${companyId}"]`)?.remove();
        const hidden = document.getElementById("companies-hidden");
        let sel = JSON.parse(hidden.value || '[]');
        sel = sel.filter(id => id != companyId);
        hidden.value = JSON.stringify(sel);
        const badge = document.getElementById('companies-badge');
        if (sel.length === 0) {
            document.getElementById("company-documents-section").style.display = 'none';
            badge.style.display = 'none';
            document.getElementById('btn-sel-companies').classList.remove('has-selection');
        } else {
            badge.textContent = sel.length + ' selezionat' + (sel.length === 1 ? 'a' : 'e');
        }
    }

});

/* ── Manual docs (CSP-compliant via event delegation) ── */
let manualDocIndex = 0;

function addManualDocRowInternal() {
    const hidePlaceholder = () => {
        const placeholder = document.getElementById('docs-placeholder');
        if (placeholder) placeholder.style.display = 'none';
    };

    fetch("/share/fetch-companies")
        .then(r => r.json())
        .then(companies => {
            if (!companies.length) { alert("Nessuna azienda trovata."); return; }
            hidePlaceholder();
            const index   = manualDocIndex++;
            const options = companies.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
            const wrap    = document.createElement('div');
            wrap.className = 'cl-manual-row';
            wrap.innerHTML = `
                <div class="cl-manual-row-field">
                    <label class="cl-manual-label">Nome documento</label>
                    <input type="text" name="manual_docs[${index}][name]" class="cl-input" placeholder="Es. DURC" required>
                </div>
                <div class="cl-manual-row-field">
                    <label class="cl-manual-label">Azienda</label>
                    <select name="manual_docs[${index}][company_id]" class="cl-input" required>
                        <option value="">-- seleziona --</option>
                        ${options}
                    </select>
                </div>
                <div class="cl-manual-row-field">
                    <label class="cl-manual-label">File</label>
                    <input type="file" name="manual_docs[${index}][file]" class="cl-input" style="padding:6px;" required>
                </div>
                <button type="button" data-action="remove-manual-doc"
                        style="height:40px;width:36px;border-radius:8px;background:#fee2e2;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#dc2626;align-self:flex-end;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>`;
            document.getElementById('manual-rows').appendChild(wrap);
        });
}

// Event delegation for CSP compliance
document.addEventListener('click', function(e) {
    const target = e.target.closest('[data-action]');
    if (!target) return;

    const action = target.dataset.action;

    if (action === 'add-manual-doc') {
        addManualDocRowInternal();
    } else if (action === 'remove-manual-doc') {
        target.closest('.cl-manual-row')?.remove();
    }
});
