document.addEventListener("DOMContentLoaded", () => {
// ── Tracking removed items ───────────────────
    const removedFiles = [];
    const removedWorkers = [];
    const removedCompanies = [];

    function updateRemovedInputs() {
        document.getElementById('removed-files').value = JSON.stringify(removedFiles);
        document.getElementById('removed-workers').value = JSON.stringify(removedWorkers);
        document.getElementById('removed-companies').value = JSON.stringify(removedCompanies);
    }

    // ── Remove existing file (manual) ────────────
    function removeExistingFile(fileId, btn) {
        removedFiles.push(fileId);
        updateRemovedInputs();
        const row = document.getElementById('existing-file-' + fileId);
        if (row) {
            row.style.opacity = '0.3';
            row.style.pointerEvents = 'none';
            row.style.textDecoration = 'line-through';
        }
    }

    // ── Remove existing worker ───────────────────
    function removeExistingWorker(workerId) {
        removedWorkers.push(workerId);
        updateRemovedInputs();
        const block = document.getElementById('existing-worker-' + workerId);
        if (block) {
            block.style.opacity = '0.3';
            block.style.pointerEvents = 'none';
        }
    }

    // ── Remove existing company ──────────────────
    function removeExistingCompany(companyId) {
        removedCompanies.push(companyId);
        updateRemovedInputs();
        const block = document.getElementById('existing-company-' + companyId);
        if (block) {
            block.style.opacity = '0.3';
            block.style.pointerEvents = 'none';
        }
    }

    // ── Form submit with chunk upload ────────────
    const editLinkForm    = document.getElementById("edit-link-form");
    const editLinkSubmit  = document.getElementById("edit-link-submit");
    const uploadProgressWrapper = document.getElementById("upload-progress-wrapper");
    const uploadProgressLabel   = document.getElementById("upload-progress-label");
    const uploadProgressBar     = document.getElementById("upload-progress-bar");
    const CHUNK_SIZE = 20 * 1024 * 1024;

    function setUploadProgress(percent, label) {
        uploadProgressWrapper.style.display = '';
        uploadProgressBar.style.width = `${Math.min(100, Math.max(0, Math.round(percent)))}%`;
        uploadProgressLabel.textContent = label;
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
        const fileInputs = [...editLinkForm.querySelectorAll('input[type="file"][name^="manual_docs"]')]
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
            editLinkForm.appendChild(tokenInput);
            input.removeAttribute("name");
        }
        setUploadProgress(100, "Upload completato. Salvataggio...");
    }

    function submitEditLink(formData) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open("POST", editLinkForm.action || window.location.href, true);
            xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
            xhr.onload = function () {
                if (xhr.status < 200 || xhr.status >= 300) { reject(new Error("Errore durante l'aggiornamento.")); return; }
                try {
                    const payload = JSON.parse(xhr.responseText);
                    if (!payload || payload.ok !== true) { reject(new Error(payload?.message || "Impossibile aggiornare il link.")); return; }
                    resolve(payload);
                } catch (e) { reject(new Error("Risposta non valida dal server.")); }
            };
            xhr.onerror = () => reject(new Error("Errore di rete."));
            xhr.send(formData);
        });
    }

    editLinkForm.addEventListener("submit", async function (event) {
        event.preventDefault();
        const hasManualUploads = [...editLinkForm.querySelectorAll('input[type="file"][name^="manual_docs"]')]
            .some(input => input.files && input.files.length > 0);
        editLinkSubmit.disabled = true;
        editLinkSubmit.textContent = hasManualUploads ? "Upload in corso..." : "Salvataggio...";
        try {
            if (hasManualUploads) { setUploadProgress(0, "Avvio upload..."); await uploadManualFilesInChunks(); }
            await submitEditLink(new FormData(editLinkForm));
            setUploadProgress(100, "Completato! Reindirizzamento...");
            window.location.href = "/share";
        } catch (error) {
            alert(error.message || "Errore durante il salvataggio.");
        } finally {
            editLinkSubmit.disabled = false;
            editLinkSubmit.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Salva Modifiche';
        }
    });

    /* ── Workers search & select ── */
    document.getElementById("search-workers").addEventListener("input", function () {
        const tokens = this.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
        document.querySelectorAll("#workers-list tr").forEach(tr => {
            const text = tr.textContent.toLowerCase();
            tr.style.display = tokens.every(token => text.includes(token)) ? "" : "none";
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
        const badge = document.getElementById('workers-badge');
        badge.textContent = selected.length + ' selezionat' + (selected.length === 1 ? 'o' : 'i');
        badge.style.display = '';
        document.getElementById('btn-sel-workers').classList.add('has-selection');
        tailwind.Modal.getOrCreateInstance(document.querySelector('#select-workers-modal')).hide();
        loadWorkerDocuments(selected);
    });

    /* ── Worker docs (dynamic — just show worker name, all docs shared live) ── */
    function loadWorkerDocuments(workerIds) {
        const container = document.getElementById("workers-documents");
        const loader    = document.getElementById("loader");
        const section   = document.getElementById("documents-section");
        container.innerHTML = "";
        section.style.display = 'none';
        loader.style.display  = '';
        fetch("/share/fetch-worker-documents-multiple?ids=" + encodeURIComponent(JSON.stringify(workerIds)))
            .then(r => r.json())
            .then(data => {
                loader.style.display = 'none';
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
                            <span class="cl-live-badge">
                                <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="5" fill="currentColor"/></svg>
                                LIVE
                            </span>
                            <button type="button" class="cl-entity-remove" data-action="remove-worker" data-worker-id="${workerId}">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>`;
                    container.appendChild(box);
                });
                section.style.display = '';
            });
    }

    function removeNewWorker(workerId) {
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

    /* ── Company docs (dynamic — just show company name, all docs shared live) ── */
    function loadCompanyDocuments(companyIds) {
        const container = document.getElementById("company-documents");
        const section   = document.getElementById("company-documents-section");
        container.innerHTML = "";
        section.style.display = 'none';
        fetch("/share/fetch-company-documents?ids=" + encodeURIComponent(JSON.stringify(companyIds)))
            .then(r => r.json())
            .then(data => {
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
                            <span class="cl-live-badge">
                                <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="5" fill="currentColor"/></svg>
                                LIVE
                            </span>
                            <button type="button" class="cl-entity-remove" data-action="remove-company" data-company-id="${companyId}">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>`;
                    container.appendChild(box);
                });
                section.style.display = '';
            });
    }

    function removeNewCompanyBlock(companyId) {
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

    /* ── Manual docs ── */
    let manualDocIndex = 0;
    function addManualDocRow() {
        fetch("/share/fetch-companies")
            .then(r => r.json())
            .then(companies => {
                if (!companies.length) { alert("Nessuna azienda trovata."); return; }
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

    // ── Event delegation for remove buttons (CSP-compliant) ───────────────────
    document.addEventListener('click', function(e) {
        const target = e.target.closest('[data-action]');
        if (!target) return;

        const action = target.dataset.action;

        if (action === 'remove-worker') {
            e.preventDefault();
            const workerId = parseInt(target.dataset.workerId);
            removeNewWorker(workerId);
        }
        else if (action === 'remove-company') {
            e.preventDefault();
            const companyId = target.dataset.companyId;
            removeNewCompanyBlock(companyId);
        }
        else if (action === 'remove-manual-doc') {
            e.preventDefault();
            target.closest('.cl-manual-row')?.remove();
        }
        else if (action === 'remove-existing-worker') {
            e.preventDefault();
            const workerId = parseInt(target.dataset.workerId);
            removeExistingWorker(workerId);
        }
        else if (action === 'remove-existing-company') {
            e.preventDefault();
            const companyId = target.dataset.companyId;
            removeExistingCompany(companyId);
        }
        else if (action === 'remove-existing-file') {
            e.preventDefault();
            const fileId = parseInt(target.dataset.fileId);
            removeExistingFile(fileId, target);
        }
        else if (action === 'add-manual-doc') {
            e.preventDefault();
            addManualDocRow();
        }
    });
});
