// ── Ordini List ───────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const tabs  = document.querySelectorAll('.col-tab');
    const rows  = document.querySelectorAll('#ordiniBody tr[data-status]');

    // ── Delete confirmation (delegation handles clicks on SVG children) ─────
    const tbody = document.getElementById('ordiniBody');
    if (tbody) {
        tbody.addEventListener('click', function (e) {
            const btn = e.target.closest('.col-action-btn-del');
            if (!btn) return;
            const id  = btn.dataset.id;
            const num = btn.dataset.num;
            if (confirm('Eliminare l\'ordine n\u00b0 ' + num + '?\nL\'operazione \u00e8 irreversibile.')) {
                document.getElementById('del-form-' + id).submit();
            }
        });
    }

    let activeFilter = 'all';

    function applyFilter() {
        rows.forEach(function (row) {
            const s = row.dataset.status || 'bozza';
            row.style.display = (activeFilter === 'all' || s === activeFilter) ? '' : 'none';
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            activeFilter = this.dataset.filter;
            applyFilter();
        });
    });
});
