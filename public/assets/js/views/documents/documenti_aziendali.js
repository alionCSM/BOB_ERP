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

    // ── BOB AI: suggerimento al cambio file ───────────────────────────────────
    // Quando l'utente seleziona un PDF, BOB lo legge e prova a pre-compilare
    // tipo / emissione / scadenza. L'utente può sempre sovrascrivere.
    // Stesso flusso usato sia nel modal di upload sia nel modal di modifica.

    function ymdToItalian(s) {
        if (!s || s === 'INDETERMINATO') return s;
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
        return m ? m[3] + '/' + m[2] + '/' + m[1] : s;
    }

    function setupAIDocumentReader(config) {
        var fileInput  = document.getElementById(config.fileId);
        if (!fileInput) return;

        var banner     = document.getElementById(config.bannerId);
        var bannerText = document.getElementById(config.bannerTextId);
        var typeInput  = document.getElementById(config.typeId);
        var emisInput  = document.getElementById(config.emisId);
        var expInput   = document.getElementById(config.expId);
        var dropzone   = fileInput.closest('.wd-dropzone');
        var fileNameEl = config.fileNameId ? document.getElementById(config.fileNameId) : null;
        var defaultFilenameLabel = fileNameEl ? fileNameEl.textContent : '';

        function setBanner(state, msg) {
            if (!banner) return;
            banner.style.display = 'flex';
            banner.classList.remove('is-loading', 'is-done', 'is-error');
            if (state === 'loading') banner.classList.add('is-loading');
            if (state === 'done')    banner.classList.add('is-done');
            if (state === 'error')   banner.classList.add('is-error');
            if (bannerText) bannerText.textContent = msg;
        }

        function setIfEmpty(el, value) {
            if (!el || !value) return false;
            if (el.value && el.value.trim() !== '') return false;
            el.value = value;
            el.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }

        function renderSuggestionChip(el, suggestedValue, label) {
            var existing = el.parentElement && el.parentElement.querySelector('.wd-ai-chip');
            if (existing) existing.remove();
            var chip = document.createElement('div');
            chip.className = 'wd-ai-chip';
            chip.innerHTML = '<img class="wd-ai-chip-icon" src="/includes/template/dist/images/logo.png" alt="BOB" />'
                + '<span class="wd-ai-chip-text">BOB suggerisce <strong></strong> per ' + label
                + ' &middot; <a href="#" class="wd-ai-chip-apply">usa</a></span>';
            chip.querySelector('strong').textContent = suggestedValue;
            chip.querySelector('.wd-ai-chip-apply').addEventListener('click', function (e) {
                e.preventDefault();
                el.value = suggestedValue;
                el.dispatchEvent(new Event('change', { bubbles: true }));
                chip.remove();
            });
            if (el.parentElement) el.parentElement.appendChild(chip);
        }

        function tryApply(el, suggested, applied, label) {
            if (!suggested) return;
            if (setIfEmpty(el, suggested)) {
                applied.push(label);
            } else if ((el.value || '').trim() !== suggested) {
                renderSuggestionChip(el, suggested, label);
            }
        }

        function showWorkerNameWarning(data, scopeId) {
            var existing = document.getElementById(scopeId + '-name-warning');
            if (existing) existing.remove();
            if (data.worker_match !== false) return;
            if (!data.worker_in_doc) return;

            var warn = document.createElement('div');
            warn.id = scopeId + '-name-warning';
            warn.className = 'wd-ai-banner is-name-warning';
            warn.style.marginTop = '8px';
            warn.innerHTML = '<img class="wd-ai-icon" src="/includes/template/dist/images/logo.png" alt="BOB" />'
                + '<span>Attenzione: nel documento ho letto <strong></strong>, '
                + 'ma stai caricando per un operaio diverso. Verifica.</span>';
            warn.querySelector('strong').textContent = data.worker_in_doc;
            if (banner && banner.parentElement) {
                banner.parentElement.insertBefore(warn, banner.nextSibling);
            }
        }

        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];

            // Pulisci stato precedente
            (config.scopeEl || document).querySelectorAll('.wd-ai-chip').forEach(c => c.remove());
            var prevWarn = document.getElementById(config.bannerId + '-name-warning');
            if (prevWarn) prevWarn.remove();

            if (!file) {
                if (banner) banner.style.display = 'none';
                if (dropzone) dropzone.classList.remove('is-file-selected');
                if (fileNameEl) fileNameEl.textContent = defaultFilenameLabel;
                return;
            }

            if (dropzone) dropzone.classList.add('is-file-selected');
            if (fileNameEl) fileNameEl.textContent = file.name;

            setBanner('loading', 'BOB sta leggendo il documento… puoi compilare anche tu nel frattempo.');

            var fd = new FormData();
            fd.append('document_file', file);

            // Passa worker_id se disponibile (il campo hidden del form upload
            // sta sulla stessa pagina e si applica anche al modal di edit)
            var workerIdInput = document.querySelector('#document-upload-form input[name="worker_id"]');
            if (workerIdInput && workerIdInput.value) {
                fd.append('worker_id', workerIdInput.value);
            }

            fetch('/documents/ai-suggest', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) {
                        setBanner('error', 'BOB non è riuscito a leggere il file — compila pure a mano.');
                        return;
                    }
                    var applied = [];
                    tryApply(typeInput, data.type,                          applied, 'tipo');
                    tryApply(emisInput, ymdToItalian(data.emission || ''),  applied, 'emissione');
                    tryApply(expInput,  ymdToItalian(data.expiry   || ''),  applied, 'scadenza');

                    var recognizedCount =
                          (data.type     ? 1 : 0)
                        + (data.emission ? 1 : 0)
                        + (data.expiry   ? 1 : 0);

                    if (recognizedCount === 0) {
                        var hint = data.note && data.note.trim() !== ''
                            ? data.note
                            : 'Non ho riconosciuto nulla, compila pure a mano.';
                        setBanner('error', hint);
                    } else {
                        var conf = data.confidence ? ' (' + data.confidence + '%)' : '';
                        if (applied.length === recognizedCount) {
                            setBanner('done', 'Ho pre-compilato ' + applied.join(', ') + conf + ' — verifica e correggi se serve.');
                        } else if (applied.length === 0) {
                            setBanner('done', 'Avevi già compilato, ti lascio i miei suggerimenti sotto ai campi.');
                        } else {
                            setBanner('done', 'Ho aggiunto ' + applied.join(', ') + conf + '. Gli altri suggerimenti sono sotto ai campi.');
                        }
                    }

                    showWorkerNameWarning(data, config.bannerId);
                })
                .catch(function (err) {
                    console.error(err);
                    setBanner('error', 'Errore di rete — compila pure a mano.');
                });
        });
    }

    // Wiring: modal upload (file obbligatorio) + modal edit (file opzionale)
    setupAIDocumentReader({
        fileId:       'document_file',
        bannerId:     'wd-ai-banner',
        bannerTextId: 'wd-ai-banner-text',
        typeId:       'wd-upload-type',
        emisId:       'wd-upload-emission',
        expId:        'wd-upload-expiry',
        fileNameId:   'wd-dropzone-filename',
    });
    setupAIDocumentReader({
        fileId:       'edit-doc-file',
        bannerId:     'edit-ai-banner',
        bannerTextId: 'edit-ai-banner-text',
        typeId:       'edit-doc-type',
        emisId:       'edit-doc-date-emission',
        expId:        'edit-doc-expiry',
        fileNameId:   'edit-dropzone-filename',
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
