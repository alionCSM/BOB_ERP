/* Fleet (fl-) — modal + holder-toggle + history fetch */
(function () {
    'use strict';

    // ── Modal open/close ────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-fl-open]');
        if (opener) {
            e.preventDefault();
            var modal = document.getElementById(opener.getAttribute('data-fl-open'));
            if (modal) openModal(modal, opener);
            return;
        }
        var closer = e.target.closest('[data-fl-close]');
        if (closer) {
            e.preventDefault();
            var m = closer.closest('.fl-modal');
            if (m) closeModal(m);
            return;
        }
        if (e.target.classList && e.target.classList.contains('fl-modal-backdrop')) {
            var mm = e.target.closest('.fl-modal');
            if (mm) closeModal(mm);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.fl-modal.is-open').forEach(closeModal);
        }
    });

    function openModal(modal, opener) {
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';

        // se è un modal history, carica i dati via fetch
        var historyUrl = opener && opener.getAttribute('data-fl-history');
        if (historyUrl) {
            var body = modal.querySelector('[data-fl-history-body]');
            if (body) {
                body.innerHTML = '<div class="fl-tl-empty">Caricamento...</div>';
                fetch(historyUrl, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { renderHistory(body, data); })
                    .catch(function () { body.innerHTML = '<div class="fl-tl-empty">Errore caricamento.</div>'; });
            }
        }
    }
    function closeModal(modal) {
        modal.classList.remove('is-open');
        if (!document.querySelector('.fl-modal.is-open')) {
            document.body.style.overflow = '';
        }
    }

    // ── Holder toggle (radio vehicle/worker/none) ──────────────────────────
    document.addEventListener('change', function (e) {
        var radio = e.target.closest('input[data-fl-holder]');
        if (!radio) return;
        var modalId = radio.getAttribute('data-fl-holder');
        var val = radio.value;
        document.querySelectorAll('[data-fl-holder-panel][data-modal="' + modalId + '"]').forEach(function (p) {
            p.style.display = (p.getAttribute('data-fl-holder-panel') === val) ? 'block' : 'none';
        });
    });

    // ── History timeline renderer ──────────────────────────────────────────
    function renderHistory(container, data) {
        var rows = data.history || [];
        if (!rows.length) {
            container.innerHTML = '<div class="fl-tl-empty">Nessuna assegnazione registrata.</div>';
            return;
        }
        var html = '<div class="fl-timeline">';
        rows.forEach(function (r) {
            var isCurrent = !r.to_date;
            var who = '';
            if (r.first_name || r.last_name) {
                who = escapeHtml((r.first_name || '') + ' ' + (r.last_name || '')).trim();
            } else if (r.vehicle_targa) {
                who = '🚐 ' + escapeHtml(r.vehicle_targa);
            } else {
                who = '<span class="fl-muted">— libero —</span>';
            }
            var dateRange = formatDate(r.from_date) + ' → ' + (r.to_date ? formatDate(r.to_date) : 'oggi');
            html += '<div class="fl-tl-item' + (isCurrent ? ' is-current' : '') + '">' +
                    '<div class="fl-tl-date">' + dateRange + '</div>' +
                    '<div class="fl-tl-who">' + who +
                        (isCurrent ? '<span class="fl-tl-current-pill">corrente</span>' : '') +
                    '</div>' +
                    (r.notes ? '<div class="fl-tl-notes">' + escapeHtml(r.notes) + '</div>' : '') +
                    '</div>';
        });
        html += '</div>';
        container.innerHTML = html;
    }

    function formatDate(iso) {
        if (!iso) return '';
        var p = iso.split('-');
        if (p.length !== 3) return iso;
        return p[2] + '/' + p[1] + '/' + p[0];
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    // ── Live filter on tables ──────────────────────────────────────────────
    document.addEventListener('input', function (e) {
        var input = e.target.closest('[data-fl-filter]');
        if (!input) return;
        var tableSel = input.getAttribute('data-fl-filter');
        var q = input.value.trim().toLowerCase();
        var rows = document.querySelectorAll(tableSel + ' tbody tr[data-fl-row]');
        rows.forEach(function (tr) {
            var text = (tr.getAttribute('data-fl-search') || tr.textContent).toLowerCase();
            tr.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
        });
    });
})();
