document.addEventListener('DOMContentLoaded', function () {

    // ── Worksite ID (read from container data attribute) ─────────────────────
    const container   = document.getElementById('disegni');
    const worksiteId  = container ? container.dataset.worksite : '0';

    // ── Category accordion ───────────────────────────────────────────────────
    document.querySelectorAll('.toggle-category').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = document.getElementById(btn.dataset.target);
            if (!target) return;
            const isOpen = !target.classList.contains('hidden');
            target.classList.toggle('hidden', isOpen);
            const cat   = btn.closest('.dsg-cat');
            if (cat) cat.classList.toggle('collapsed', isOpen);
            const arrow = btn.querySelector('.dsg-cat-arrow');
            if (arrow) arrow.classList.toggle('open', !isOpen);
        });
    });

    // ── Category select sync ─────────────────────────────────────────────────
    const catSel = document.getElementById('existing-category');
    const catInp = document.getElementById('category-input');
    if (catSel && catInp) {
        catSel.addEventListener('change', () => {
            if (catSel.value) catInp.value = catSel.value;
        });
    }

    // ── File input preview + drag-and-drop ───────────────────────────────────
    const fileInput = document.getElementById('file-input');
    const preview   = document.getElementById('file-name-preview');
    const dropZone  = document.getElementById('drop-zone');

    if (fileInput && preview) {
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) {
                preview.textContent = fileInput.files[0].name;
                preview.style.display = '';
            } else {
                preview.style.display = 'none';
            }
        });
    }
    if (dropZone && fileInput) {
        dropZone.addEventListener('dragover', e => {
            e.preventDefault();
            dropZone.classList.add('dragging');
        });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragging'));
        dropZone.addEventListener('drop', e => {
            e.preventDefault();
            dropZone.classList.remove('dragging');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                fileInput.dispatchEvent(new Event('change'));
            }
        });
    }

    // ── Upload button (header + empty state) ─────────────────────────────────
    function openUploadModal() {
        resetUploadModal();
        tailwind.Modal.getOrCreateInstance(document.getElementById('disegno-modal')).show();
    }

    const uploadBtn      = document.getElementById('dsg-upload-btn');
    const uploadBtnEmpty = document.getElementById('dsg-upload-btn-empty');
    if (uploadBtn)      uploadBtn.addEventListener('click', openUploadModal);
    if (uploadBtnEmpty) uploadBtnEmpty.addEventListener('click', openUploadModal);

    // ── Event delegation: action buttons ─────────────────────────────────────
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;

        const action = btn.dataset.action;

        // Category share — stop propagation so accordion doesn't toggle
        if (action === 'dsg-share-cat') {
            e.stopPropagation();
            shareCategory(btn.dataset.cat);
            return;
        }

        if (action === 'dsg-versions') {
            toggleVersions(parseInt(btn.dataset.id), parseInt(btn.dataset.worksite));
            return;
        }

        if (action === 'dsg-update') {
            openUpdate(parseInt(btn.dataset.id), btn.dataset.cat);
            return;
        }

        if (action === 'dsg-share') {
            const sharedIds = JSON.parse(btn.dataset.shared || '[]');
            openShare(parseInt(btn.dataset.id), sharedIds);
            return;
        }

        if (action === 'dsg-delete') {
            openDeleteModal(parseInt(btn.dataset.id), btn.dataset.name);
            return;
        }
    });

    // ── Share submit ─────────────────────────────────────────────────────────
    const shareSubmitBtn = document.getElementById('dsg-share-submit');
    if (shareSubmitBtn) {
        shareSubmitBtn.addEventListener('click', submitShare);
    }

    // ── Delete confirm ───────────────────────────────────────────────────────
    const deleteConfirmBtn = document.getElementById('dsg-delete-confirm');
    if (deleteConfirmBtn) {
        deleteConfirmBtn.addEventListener('click', () => {
            const docId = parseInt(document.getElementById('dsg-delete-id').value);
            if (!docId) return;
            executeDelete(docId);
            tailwind.Modal.getOrCreateInstance(document.getElementById('dsg-delete-modal')).hide();
        });
    }
});

// ── Upload modal reset / update mode ─────────────────────────────────────────

function resetUploadModal() {
    document.getElementById('modal-title').textContent = 'Carica Disegno';
    document.getElementById('modal-submit-btn').textContent = 'Carica';
    document.getElementById('replace-id').value = '0';
    const inp = document.getElementById('category-input');
    if (inp) { inp.value = ''; inp.readOnly = false; inp.required = true; }
    const sel = document.getElementById('existing-category');
    if (sel) sel.value = '';
    const sec = document.getElementById('category-section');
    if (sec) sec.style.display = '';
    const fp = document.getElementById('file-name-preview');
    if (fp) fp.style.display = 'none';
}

function openUpdate(docId, category) {
    document.getElementById('modal-title').textContent = 'Aggiorna Disegno';
    document.getElementById('modal-submit-btn').textContent = 'Aggiorna';
    document.getElementById('replace-id').value = docId;
    const inp = document.getElementById('category-input');
    if (inp) { inp.value = category; inp.readOnly = true; }
    const sel = document.getElementById('existing-category');
    if (sel) sel.value = category;
    tailwind.Modal.getOrCreateInstance(document.getElementById('disegno-modal')).show();
}

// ── Version history ───────────────────────────────────────────────────────────

function toggleVersions(docId, worksiteId) {
    const row = document.getElementById('versions-' + docId);
    if (!row) return;
    if (row.style.display === 'none' || row.style.display === '') {
        row.style.display = '';
        row.style.visibility = 'visible';
        loadVersions(docId, worksiteId);
    } else {
        row.style.display = 'none';
    }
}

function loadVersions(docId, worksiteId) {
    const body = document.getElementById('versions-body-' + docId);
    if (!body) return;
    body.innerHTML = '<span class="dsg-versions-loading">Caricamento...</span>';

    // Get worksiteId from container if not passed
    if (!worksiteId) {
        const c = document.getElementById('disegni');
        worksiteId = c ? parseInt(c.dataset.worksite) : 0;
    }

    fetch('/worksites/' + worksiteId + '/disegni/' + docId + '/versions')
        .then(r => r.json())
        .then(versions => {
            if (!versions.length) {
                body.innerHTML = '<em style="color:#94a3b8;font-size:12px;">Nessuna versione precedente</em>';
                return;
            }
            let html = '<table class="dsg-versions-table">';
            versions.forEach(v => {
                const name = ((v.first_name || '') + ' ' + (v.last_name || '')).trim() || '—';
                const date = new Date(v.created_at).toLocaleDateString('it-IT');
                html += '<tr>' +
                    '<td><span class="dsg-version v1" style="font-size:10px;">v' + v.version_number + '</span></td>' +
                    '<td style="color:#1e293b;font-weight:500;">' + escHtml(v.file_name) + '</td>' +
                    '<td>' + escHtml(name) + '</td>' +
                    '<td>' + date + '</td>' +
                    '<td><a href="/worksites/' + worksiteId + '/disegni/' + docId + '/view?version_id=' + v.id + '" ' +
                        'target="_blank" class="dsg-act dsg-act-open" style="font-size:11px;padding:3px 8px;text-decoration:none;">Apri</a></td>' +
                    '</tr>';
            });
            html += '</table>';
            body.innerHTML = html;
        })
        .catch(() => {
            body.innerHTML = '<span style="color:#e11d48;font-size:12px;">Errore nel caricamento</span>';
        });
}

// ── Delete ────────────────────────────────────────────────────────────────────

function openDeleteModal(docId, name) {
    document.getElementById('dsg-delete-id').value = docId;
    document.getElementById('dsg-delete-name').textContent = name || 'ID ' + docId;
    tailwind.Modal.getOrCreateInstance(document.getElementById('dsg-delete-modal')).show();
}

function executeDelete(docId) {
    const container = document.getElementById('disegni');
    const worksiteId = container ? container.dataset.worksite : '0';

    fetch('/worksites/' + worksiteId + '/disegni/' + docId + '/delete')
        .then(r => {
            if (r.ok || r.status === 204) {
                const row = document.getElementById('row-' + docId);
                const verRow = document.getElementById('versions-' + docId);
                if (row) {
                    row.style.transition = 'opacity .3s';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 300);
                }
                if (verRow) setTimeout(() => verRow.remove(), 300);
            } else {
                alert('Errore durante l\'eliminazione.');
            }
        })
        .catch(() => alert('Errore di rete.'));
}

// ── Share ─────────────────────────────────────────────────────────────────────

function openShare(docId, currentSharedIds) {
    document.getElementById('share-doc-ids').value = JSON.stringify([docId]);
    document.getElementById('share-category').value = '';
    document.getElementById('share-modal-title').innerHTML =
        '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:6px;"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>Condividi Disegno';
    document.querySelectorAll('.share-user-cb').forEach(cb => {
        cb.checked = currentSharedIds.includes(parseInt(cb.value));
    });
    tailwind.Modal.getOrCreateInstance(document.getElementById('share-modal')).show();
}

function shareCategory(category) {
    document.getElementById('share-doc-ids').value = '';
    document.getElementById('share-category').value = category;
    document.getElementById('share-modal-title').innerHTML =
        '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:6px;"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>Condividi: ' + escHtml(category);
    document.querySelectorAll('.share-user-cb').forEach(cb => cb.checked = false);
    tailwind.Modal.getOrCreateInstance(document.getElementById('share-modal')).show();
}

function submitShare() {
    const container  = document.getElementById('disegni');
    const worksiteId = container ? container.dataset.worksite : '0';
    const userIds    = [];
    document.querySelectorAll('.share-user-cb:checked').forEach(cb => userIds.push(parseInt(cb.value)));
    if (!userIds.length) { alert('Seleziona almeno un utente.'); return; }

    const body = new URLSearchParams();
    body.append('worksite_id', worksiteId);
    const docIds  = document.getElementById('share-doc-ids').value;
    const category = document.getElementById('share-category').value;
    if (docIds) JSON.parse(docIds).forEach(id => body.append('document_ids[]', id));
    if (category) body.append('category', category);
    userIds.forEach(id => body.append('user_ids[]', id));

    fetch('/worksites/' + worksiteId + '/disegni/share', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.error || 'Errore');
            }
        })
        .catch(() => alert('Errore di rete'));
}

// ── Utility ───────────────────────────────────────────────────────────────────

function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
