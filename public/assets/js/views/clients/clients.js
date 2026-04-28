/**
 * clients.js  —  CSP-compliant, no inline handlers
 */

document.addEventListener('DOMContentLoaded', function() {
    // ── Modal functions ─────────────────────────────────────────────────────
    let currentModal = null;

    function openModal(modalEl) {
        if (!modalEl) return;
        currentModal = modalEl;
        modalEl.style.display = 'flex';
        setTimeout(() => modalEl.classList.add('active'), 10);
    }

    function closeModal() {
        if (currentModal) {
            currentModal.classList.remove('active');
            setTimeout(() => {
                currentModal.style.display = 'none';
                currentModal = null;
            }, 200);
        }
    }

    // ── Search ────────────────────────────────────────────────────────────────
    const searchInput = document.getElementById('search-client');
    const tableRows = document.querySelectorAll('.cl-table tbody tr');
    const emptyState = document.getElementById('cl-empty-state');
    const tableWrap = document.querySelector('.cl-table-wrap');
    const counterText = document.querySelector('.cl-counter-text strong');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            let visibleCount = 0;

            tableRows.forEach(function(row) {
                const text = row.textContent.toLowerCase();
                const matches = text.includes(filter);
                row.style.display = matches ? '' : 'none';
                if (matches) visibleCount++;
            });

            // Update counter
            if (counterText) {
                counterText.textContent = visibleCount;
            }

            // Show/hide empty state
            if (emptyState && tableWrap) {
                if (visibleCount === 0) {
                    tableWrap.style.display = 'none';
                    emptyState.style.display = 'block';
                } else {
                    tableWrap.style.display = '';
                    emptyState.style.display = 'none';
                }
            }
        });
    }

    // ── Delete modal ─────────────────────────────────────────────────────────
    const confirmPanel  = document.getElementById('delete-confirm-panel');
    const warningPanel  = document.getElementById('delete-warning-panel');
    const loadingPanel  = document.getElementById('delete-loading-panel');
    const warningText   = document.getElementById('delete-warning-text');
    const deleteForm    = document.getElementById('deleteForm');

    function showPanel(which) {
        [confirmPanel, warningPanel, loadingPanel].forEach(function(p) {
            if (p) p.style.display = 'none';
        });
        if (which) which.style.display = '';
    }

    document.querySelectorAll('[data-client-id]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const id     = this.getAttribute('data-client-id');
            const target = this.getAttribute('data-tw-target');
            const modal  = document.querySelector(target);
            if (!modal) return;

            // Show modal with loading state while we check
            showPanel(loadingPanel);
            openModal(modal);

            fetch('/clients/' + id + '/check-delete')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.can_delete) {
                        if (deleteForm) deleteForm.action = '/clients/' + id + '/delete';
                        showPanel(confirmPanel);
                    } else {
                        var parts = [];
                        if (data.offers    > 0) parts.push(data.offers    + ' offert' + (data.offers    === 1 ? 'a' : 'e'));
                        if (data.worksites > 0) parts.push(data.worksites + ' cantier' + (data.worksites === 1 ? 'e' : 'i'));
                        if (warningText) warningText.textContent = 'Questo cliente ha ' + parts.join(' e ') + ' associati e non può essere eliminato.';
                        showPanel(warningPanel);
                    }
                })
                .catch(function() {
                    if (warningText) warningText.textContent = 'Errore durante la verifica. Riprova.';
                    showPanel(warningPanel);
                });
        });
    });

    // Close modal on dismiss button click
    document.querySelectorAll('[data-tw-dismiss="modal"]').forEach(function(btn) {
        btn.addEventListener('click', closeModal);
    });

    // Close modal on overlay click
    document.querySelector('#delete-confirmation-modal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
});
