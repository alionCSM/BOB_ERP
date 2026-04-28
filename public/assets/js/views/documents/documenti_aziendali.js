/**
 * documenti_aziendali.js
 * CSP-compliant: no inline handlers, no tailwind.Modal, no PHP in JS.
 * Worker ID is read from data-worker-id on #doc-worker-meta (set by the PHP partial).
 */

(function () {

    // ── Worker document validity rules ────────────────────────────────────────
    // { months: N }  → expiry = emission + N months
    // { never: true} → expiry = 31/12/2099
    var WORKER_DOC_VALIDITY = {
        'Verbale consegna DPI':   { months: 12  },
        'Visita medica':          { months: 12  },
        'Formazione sicurezza':   { months: 60  },
        'Lavori in quota DPI':    { months: 60  },
        'Piattaforma':            { months: 60  },
        'Carrello elevatore':     { months: 60  },
        'Braccio telescopico':    { months: 60  },
        'Preposto':               { months: 24  },
        'Antincendio':            { months: 60  },
        'Primo soccorso':         { months: 36  },
        'Gru a torre':            { months: 60  },
        'Gru mobile':             { months: 60  },
        'Saldatura':              { months: 60  },
    };

    function parseDate(str) {
        if (!str) return null;
        str = str.trim();
        if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
            var d = new Date(str + 'T00:00:00');
            return isNaN(d.getTime()) ? null : d;
        }
        if (/^\d{2}\/\d{2}\/\d{4}$/.test(str)) {
            var p = str.split('/');
            var d2 = new Date(p[2] + '-' + p[1] + '-' + p[0] + 'T00:00:00');
            return isNaN(d2.getTime()) ? null : d2;
        }
        return null;
    }

    function formatDDMMYYYY(d) {
        return String(d.getDate()).padStart(2, '0') + '/' +
               String(d.getMonth() + 1).padStart(2, '0') + '/' +
               d.getFullYear();
    }

    function calcExpiry(emissionStr, validity) {
        if (validity.never) return '31/12/2099';
        var d = parseDate(emissionStr);
        if (!d) return '';
        d.setMonth(d.getMonth() + validity.months);
        d.setDate(d.getDate() - 1);        
        return formatDDMMYYYY(d);
    }

    function formatValidity(validity) {
        if (validity.never) return 'nessuna scadenza fissa → 31/12/2099';
        var m = validity.months;
        if (m >= 12 && m % 12 === 0) {
            var y = m / 12;
            return y + (y === 1 ? ' anno' : ' anni');
        }
        return m + (m === 1 ? ' mese' : ' mesi');
    }

    function setupAutoExpiry(typeEl, emissionEl, expiryEl, hintEl) {
        if (!typeEl || !emissionEl || !expiryEl) return function () {};
        var userEdited = false;

        function tryFill() {
            if (userEdited) return;
            var type     = typeEl.value.trim();
            var emission = emissionEl.value.trim();
            var validity = WORKER_DOC_VALIDITY[type];
            if (!validity || !emission) {
                if (hintEl) { hintEl.textContent = ''; hintEl.style.display = 'none'; }
                return;
            }
            var result = calcExpiry(emission, validity);
            if (!result) {
                if (hintEl) { hintEl.textContent = ''; hintEl.style.display = 'none'; }
                return;
            }
            expiryEl.value = result;
            if (hintEl) {
                hintEl.textContent = '↻ Durata standard: ' + formatValidity(validity) + ' — modificabile';
                hintEl.style.display = '';
            }
        }

        typeEl.addEventListener('change', tryFill);
        typeEl.addEventListener('input',  tryFill);
        emissionEl.addEventListener('change', tryFill);
        emissionEl.addEventListener('blur',   tryFill);

        expiryEl.addEventListener('input', function () {
            userEdited = true;
            if (hintEl) { hintEl.textContent = ''; hintEl.style.display = 'none'; }
        });

        return function reset() { userEdited = false; };
    }

    // Wire up upload modal auto-expiry
    var resetEditAutoExpiry = setupAutoExpiry(
        document.getElementById('wd-upload-type'),
        document.getElementById('wd-upload-emission'),
        document.getElementById('wd-upload-expiry'),
        document.getElementById('wd-upload-expiry-hint')
    );

    // Wire up edit modal auto-expiry (reset is called when edit button is clicked)
    var resetEditDocAutoExpiry = setupAutoExpiry(
        document.getElementById('edit-doc-type'),
        document.getElementById('edit-doc-date-emission'),
        document.getElementById('edit-doc-expiry'),
        document.getElementById('wd-edit-expiry-hint')
    );
    // ── Helpers ──────────────────────────────────────────────────────────────

    function openModal(id) {
        const el = document.getElementById(id);
        if (el) {
            const modal = tailwind.Modal.getOrCreateInstance(el);
            modal.show();
        }
    }

    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) {
            const modal = tailwind.Modal.getOrCreateInstance(el);
            modal.hide();
        }
    }

    function getWorkerId() {
        const meta = document.getElementById('doc-worker-meta');
        return meta ? meta.dataset.workerId : '';
    }

    // ── Open / close via delegation ───────────────────────────────────────────

    // Open upload modal
    const btnUpload = document.getElementById('btn-open-upload-modal');
    if (btnUpload) {
        btnUpload.addEventListener('click', function () {
            openModal('upload-document-modal');
        });
    }

    // Open check modal
    const btnCheck = document.getElementById('btn-open-check-modal');
    if (btnCheck) {
        btnCheck.addEventListener('click', function () {
            openModal('check-document-modal');
            const body = document.getElementById('check-documents-body');
            if (body) {
                body.innerHTML = '<p style="text-align:center;padding:20px;color:#9ca3af;">Caricamento…</p>';
                fetch('/documents/check-mandatory?worker_id=' + getWorkerId())
                    .then(function (r) { return r.text(); })
                    .then(function (html) { body.innerHTML = html; })
                    .catch(function () {
                        body.innerHTML = '<p style="text-align:center;color:#ef4444;padding:20px;">Errore durante il caricamento.</p>';
                    });
            }
        });
    }

    // Close buttons (data-doc-close="modal-id")
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-doc-close]');
        if (btn) {
            closeModal(btn.dataset.docClose);
        }
        // Click on backdrop itself
        if (e.target.classList.contains('doc-modal-backdrop')) {
            e.target.style.display = 'none';
        }
    });

    // Edit button (data-doc-id, data-doc-type, etc.)
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.wd-edit-btn');
        if (!btn) return;
        e.preventDefault();
        resetEditDocAutoExpiry(); // reset userEdited flag; expiry pre-filled from DB, not auto-calc
        document.getElementById('edit-doc-id').value       = btn.dataset.docId;
        document.getElementById('edit-doc-type').value     = btn.dataset.docType;
        document.getElementById('edit-doc-date-emission').value = btn.dataset.docEmission;
        document.getElementById('edit-doc-expiry').value   = btn.dataset.docExpiry;
        var hint = document.getElementById('wd-edit-expiry-hint');
        if (hint) { hint.textContent = ''; hint.style.display = 'none'; }
        openModal('edit-document-modal');
    });

    // Delete button
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.wd-delete-btn');
        if (!btn) return;
        e.preventDefault();
        if (!confirm('Sei sicuro di voler eliminare questo documento?')) return;
        const docId = btn.dataset.docId;
        fetch('/documents/' + docId + '/delete', { method: 'POST' })
            .then(function (r) {
                if (r.ok) {
                    location.reload();
                } else {
                    alert('Errore durante l\'eliminazione del documento.');
                }
            })
            .catch(function (err) { console.error(err); });
    });

    // ── Upload form submit ────────────────────────────────────────────────────

    const uploadForm = document.getElementById('document-upload-form');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const fd = new FormData(this);
            fetch('/documents/upload', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Errore: ' + (data.error || 'Sconosciuto'));
                    }
                })
                .catch(function (err) { console.error(err); });
        });
    }

    // ── Edit form submit ──────────────────────────────────────────────────────

    const editForm = document.getElementById('edit-document-form');
    if (editForm) {
        editForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const docId = document.getElementById('edit-doc-id').value;
            const fd = new FormData(this);
            fetch('/documents/' + docId + '/update', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        closeModal('edit-document-modal');
                        location.reload();
                    } else {
                        alert('Errore: ' + (data.error || 'Sconosciuto'));
                    }
                })
                .catch(function (err) { console.error(err); });
        });
    }

})();
