/**
 * company_details.js  —  CSP-compliant, no inline handlers
 */

// ── Document validity rules ───────────────────────────────────────────────────
// { months: N }  → expiry = emission + N months − 1 day
// { never: true} → expiry = 31/12/2099 (no fixed expiry)
var DOC_VALIDITY = {
    'RLS':                                                  { never: true  },
    'RSPP':                                                 { never: true  },
    'RSPP Attestato':                                       { months: 60  },
    'RLS Attestato':                                        { months: 12  },
    'DVR':                                                  { never: true },
    'Visura':                                               { months: 6   },
    'Patente a crediti':                                    { never: true },
    'Nomina primo soccorso':                                { months: 12  },
    'Nomina medico competente':                             { never: true  },
    'Nomina preposto':                                      { months: 12  },
    'Nomina antincendio':                                   { months: 12  },
    'DURC':                                                 { months: 4   },
    'DOMA':                                                 { months: 12  },
    'Dichiarazione possesso requisiti tecnico professionali': { months: 12 },
    'Dichiarazione informazione e formazione':              { months: 12  },
    'Dichiarazione conformità attrezzature':                { months: 12  },
    'Dichiarazione art.14':                                 { months: 12  },
    'Assicurazione':                                        { months: 12  },
};

function calcExpiry(emissionDateStr, validity) {
    if (!emissionDateStr) return '';
    if (validity.never) return '2099-12-31';
    var d = new Date(emissionDateStr + 'T00:00:00');
    if (isNaN(d.getTime())) return '';
    d.setMonth(d.getMonth() + validity.months);
    return d.toISOString().slice(0, 10);
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

// Sets up auto-expiry for a pair of (type, emission, expiry, hint) elements.
// Returns a reset() function to call when the modal is opened fresh.
function setupAutoExpiry(typeEl, emissionEl, expiryEl, hintEl) {
    if (!typeEl || !emissionEl || !expiryEl) return function () {};
    var userEdited = false;

    function tryFill() {
        if (userEdited) return;
        var type     = typeEl.value.trim();
        var emission = emissionEl.value;
        var validity = DOC_VALIDITY[type];
        if (!validity || !emission) {
            if (hintEl) { hintEl.textContent = ''; hintEl.style.display = 'none'; }
            return;
        }
        expiryEl.value = calcExpiry(emission, validity);
        if (hintEl) {
            hintEl.textContent = '↻ Durata standard: ' + formatValidity(validity) + ' — modificabile';
            hintEl.style.display = '';
        }
    }

    typeEl.addEventListener('change', tryFill);
    typeEl.addEventListener('input',  tryFill);
    emissionEl.addEventListener('change', tryFill);
    emissionEl.addEventListener('input',  tryFill);

    expiryEl.addEventListener('input', function () {
        userEdited = true;
        if (hintEl) { hintEl.textContent = ''; hintEl.style.display = 'none'; }
    });

    return function reset() { userEdited = false; };
}

// ── Module-level state ────────────────────────────────────────────────────────
var resetEditAutoExpiry = null; // set up in DOMContentLoaded, called on modal open

// ── Worker filter state ───────────────────────────────────────────────────────

let currentFilter = 'all';

function filterWorkers(filter, btn) {
    currentFilter = filter;
    document.querySelectorAll('.cd-filter-btn').forEach(function (b) {
        b.classList.remove('active');
    });
    if (btn) {
        btn.classList.add('active');
    }
    applyWorkerFilters();
}

function applyWorkerFilters() {
    const search = (document.getElementById('search-workers')?.value || '').toLowerCase();
    document.querySelectorAll('.cd-workers-grid .worker-row').forEach(function (row) {
        const isActive = row.dataset.active === '1';
        const nameText = (row.querySelector('.cd-worker-name')?.textContent || '').toLowerCase();
        const compText = (row.querySelector('.cd-worker-company')?.textContent || '').toLowerCase();
        const passFilter = currentFilter === 'all' || (currentFilter === 'active' && isActive);
        const passSearch = !search || nameText.includes(search) || compText.includes(search);
        row.style.display = (passFilter && passSearch) ? '' : 'none';
    });
}

// ── Modal helpers ─────────────────────────────────────────────────────────────
// Open a Midone modal by directly replicating what app.js show() does.
// We avoid tailwind.Modal API and hidden trigger buttons because both
// proved unreliable under certain CSP / timing conditions.

function showMidoneModal(selector) {
    var el = document.querySelector(selector);
    if (!el) return;

    // If Midone's tailwind.Modal API is available, prefer it
    if (window.tailwind && window.tailwind.Modal) {
        try {
            tailwind.Modal.getOrCreateInstance(el).show();
            return;
        } catch (e) {
            // Fallback to manual approach below
        }
    }

    // Manual fallback: replicate Midone's show() logic
    // 1. Create a placeholder so the modal can be moved back later
    if (!document.querySelector('[data-modal-replacer="' + el.id + '"]')) {
        var placeholder = document.createElement('div');
        placeholder.setAttribute('data-modal-replacer', el.id);
        el.parentNode.insertBefore(placeholder, el.nextSibling);
        document.body.appendChild(el);
    }
    // 2. Show the modal
    el.removeAttribute('aria-hidden');
    el.style.marginTop = '0';
    el.style.marginLeft = '0';
    el.style.zIndex = '10001';
    el.classList.add('overflow-y-auto');
    document.body.classList.add('overflow-y-hidden');
    setTimeout(function () {
        el.classList.add('show');
    }, 10);
}

var pendingDeleteDocId = null;

function openDeleteCompanyDoc(id) {
    pendingDeleteDocId = id;
    showMidoneModal('#delete-company-doc-modal');
}

function openEditCompanyDoc(id, type, emission, expiry) {
    if (typeof resetEditAutoExpiry === 'function') resetEditAutoExpiry();
    document.getElementById('edit-doc-id').value       = id;
    document.getElementById('edit-doc-type').value     = type;
    document.getElementById('edit-doc-emission').value = emission;
    document.getElementById('edit-doc-expiry').value   = expiry;
    // Clear hint — expiry already comes from DB, not auto-calculated
    var hint = document.getElementById('edit-doc-expiry-hint');
    if (hint) { hint.textContent = ''; hint.style.display = 'none'; }
    showMidoneModal('#edit-company-doc-modal');
}

// ── DOMContentLoaded ─────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
    // ── CSP-compliant config from data attributes ───────────────────────────
    const pageEl = document.querySelector('.cd-page');
    const companyId    = pageEl ? parseInt(pageEl.dataset.companyId, 10) : 0;
    const defaultTab   = pageEl ? pageEl.dataset.defaultTab || 'tab-info' : 'tab-info';
    const restrictedTabsStr = pageEl ? pageEl.dataset.restrictedTabs || '[]' : '[]';
    let restrictedTabs = [];
    try {
        restrictedTabs = JSON.parse(restrictedTabsStr);
    } catch (e) {
        restrictedTabs = [];
    }

    // Debug log
    console.log('[DEBUG] Config from data attributes:', { companyId, defaultTab, restrictedTabs });

    // ── Active tab from URL / localStorage ───────────────────────────────────
    const urlParams   = new URLSearchParams(window.location.search);
    const tabFromUrl  = urlParams.get('tab');
    const storedTab   = localStorage.getItem('company_active_tab');
    const validStored = storedTab && !restrictedTabs.includes(storedTab) ? storedTab : null;
    const activeTab   = tabFromUrl || validStored || defaultTab;

    document.querySelectorAll('.cd-tab-content').forEach(c => c.classList.add('hidden'));
    const activeContent = document.getElementById(activeTab);
    if (activeContent) activeContent.classList.remove('hidden');

    document.querySelectorAll('.cd-nav-link').forEach(function (link) {
        link.classList.toggle('active', link.dataset.tab === activeTab);
        link.addEventListener('click', function (e) {
            e.preventDefault();
            const tab = this.dataset.tab;
            document.querySelectorAll('.cd-tab-content').forEach(c => c.classList.add('hidden'));
            document.getElementById(tab)?.classList.remove('hidden');
            document.querySelectorAll('.cd-nav-link').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            localStorage.setItem('company_active_tab', tab);
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);
        });
    });

    // ── Worker search ─────────────────────────────────────────────────────────
    const searchInput = document.getElementById('search-workers');
    if (searchInput) searchInput.addEventListener('keyup', applyWorkerFilters);

    // ── Delegated: filter buttons ─────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.cd-filter-btn');
        if (btn) filterWorkers(btn.dataset.filter, btn);
    });

    // ── Delegated: edit document button ──────────────────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.cd-edit-doc-btn');
        if (btn) {
            openEditCompanyDoc(
                btn.dataset.docId,
                btn.dataset.docType,
                btn.dataset.docEmission,
                btn.dataset.docExpiry
            );
        }
    });

    // ── Delegated: delete document button ────────────────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.cd-delete-doc-btn');
        if (btn) openDeleteCompanyDoc(btn.dataset.docId);
    });

    // ── Delegated: delete worker button ─────────────────────────────────────
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.cd-delete-worker-btn');
        if (btn) {
            const workerId = btn.dataset.workerId;
            const workerUid = btn.dataset.workerUid || '';
            const hiddenId = document.getElementById('delete-worker-id');
            if (hiddenId) hiddenId.value = workerId;

            // uid input is pre-rendered in the form; just set its value
            const uidInput = document.getElementById('delete-worker-uid');
            if (uidInput) uidInput.value = workerUid;

            showMidoneModal('#delete-worker-modal');
        }
    });

    // ── Delete doc confirm button (delegated — survives Midone modal move) ──
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#confirm-delete-doc-btn')) return;
        if (!pendingDeleteDocId) return;
        var url = '/companies/' + companyId + '/document/' + pendingDeleteDocId + '/delete';
        var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        var headers = { 'X-Requested-With': 'XMLHttpRequest' };
        if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
        fetch(url, { method: 'POST', headers: headers })
            .then(function (r) {
                if (r.ok) { location.reload(); }
                else { r.text().then(function (t) { alert('Errore: ' + r.status + ' ' + t); }); }
            })
            .catch(function (err) { console.error(err); alert('Errore di rete.'); });
    });

    // ── Upload form ───────────────────────────────────────────────────────────
    const uploadForm = document.getElementById('upload-company-form');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function (e) {
            e.preventDefault();
            fetch('/companies/' + companyId + '/document/upload', { method: 'POST', body: new FormData(this) })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { location.reload(); }
                    else { alert('Errore upload: ' + (data.message || data.error || 'Sconosciuto')); }
                })
                .catch(err => { console.error(err); alert('Errore di rete durante il caricamento.'); });
        });
    }

    // ── Edit form ─────────────────────────────────────────────────────────────
    const editForm = document.getElementById('edit-company-document-form');
    if (editForm) {
        editForm.addEventListener('submit', function (e) {
            e.preventDefault();
            fetch('/companies/' + companyId + '/document/update', { method: 'POST', body: new FormData(this) })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { location.reload(); }
                    else { alert('Errore modifica: ' + (data.error || 'Sconosciuto')); }
                })
                .catch(err => { console.error(err); alert('Errore di rete durante la modifica.'); });
        });
    }

    // ── Auto-expiry: upload modal ─────────────────────────────────────────────
    setupAutoExpiry(
        document.getElementById('upload-doc-type'),
        document.getElementById('upload-doc-emission'),
        document.getElementById('upload-doc-expiry'),
        document.getElementById('upload-doc-expiry-hint')
    );

    // ── Auto-expiry: edit modal ───────────────────────────────────────────────
    resetEditAutoExpiry = setupAutoExpiry(
        document.getElementById('edit-doc-type'),
        document.getElementById('edit-doc-emission'),
        document.getElementById('edit-doc-expiry'),
        document.getElementById('edit-doc-expiry-hint')
    );

    // ── Check docs modal ──────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-tw-target="#check-company-docs-modal"]');
        if (btn) {
            e.preventDefault();
            console.log('[DEBUG] companyId from closure:', companyId);

            // Defensive: ensure companyId is a valid positive integer
            if (!companyId || companyId <= 0) {
                console.error('companyId is missing or invalid:', companyId);
                alert('Errore: ID azienda non disponibile.');
                return;
            }

            var bodyEl = document.getElementById('check-company-docs-body');
            if (bodyEl) {
                // Show loading state
                bodyEl.innerHTML = '<div class="text-center py-10 text-slate-500"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="animate-spin mx-auto mb-3"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>Caricamento...</div>';
                // Open modal first
                showMidoneModal('#check-company-docs-modal');
                // Fetch content
                var url = '/documents/check-mandatory-company?company_id=' + encodeURIComponent(companyId);
                console.log('Fetching check mandatory for companyId:', companyId, 'URL:', url);
                fetch(url)
                    .then(function(r) {
                        if (!r.ok) {
                            return r.text().then(function(t) { throw new Error(t); });
                        }
                        return r.text();
                    })
                    .then(function(html) {
                        if (bodyEl) bodyEl.innerHTML = html;
                    })
                    .catch(function(err) {
                        console.error('Error fetching check mandatory:', err);
                        if (bodyEl) bodyEl.innerHTML = '<div class="text-center py-10 text-red-600">' + err.message + '</div>';
                    });
            }
        }
    });
});
