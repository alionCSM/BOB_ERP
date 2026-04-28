// ── Search ───────────────────────────────────────────────────────────────────
document.getElementById("search-offer").addEventListener("input", function () {
    const filtro = this.value.toLowerCase();
    applyFilters();
});

// ── Status filter tabs ────────────────────────────────────────────────────────
let activeStatus = 'all';

document.querySelectorAll('.cfl-status-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
        activeStatus = this.dataset.status;
        document.querySelectorAll('.cfl-status-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        applyFilters();
    });
});

function applyFilters() {
    const search = document.getElementById("search-offer").value.toLowerCase();
    document.querySelectorAll("#offerTable tr").forEach(function (row) {
        const matchSearch  = !search || row.textContent.toLowerCase().includes(search);
        const matchStatus  = activeStatus === 'all' || row.dataset.status === activeStatus;
        row.style.display  = (matchSearch && matchStatus) ? '' : 'none';
    });
}

// ── Inline status change ──────────────────────────────────────────────────────
document.addEventListener('click', function (e) {
    // Toggle dropdown open/close on badge click
    const btn = e.target.closest('.cfl-badge-btn');
    if (btn) {
        const cell     = btn.closest('.cfl-status-cell');
        const dropdown = cell.querySelector('.cfl-status-dropdown');
        const isOpen   = dropdown.classList.contains('open');

        // Close all others first
        document.querySelectorAll('.cfl-status-dropdown.open').forEach(d => d.classList.remove('open'));

        if (!isOpen) {
            dropdown.classList.add('open');
        }
        e.stopPropagation();
        return;
    }

    // Status option selected inside dropdown
    const option = e.target.closest('.cfl-status-dropdown button');
    if (option) {
        const dropdown = option.closest('.cfl-status-dropdown');
        const cell     = option.closest('.cfl-status-cell');
        const badgeBtn = cell.querySelector('.cfl-badge-btn');
        const offerId  = cell.dataset.offerId;
        const newStatus = option.dataset.val;

        dropdown.classList.remove('open');
        badgeBtn.classList.add('saving');

        fetch('/offers/' + offerId + '/status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_csrf=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]')?.content || '') +
                  '&status=' + encodeURIComponent(newStatus)
        })
        .then(r => r.json())
        .then(function (data) {
            badgeBtn.classList.remove('saving');
            if (data.success) {
                // Update badge class and text
                const labels = {
                    bozza: 'Bozza', inviata: 'Inviata', in_trattativa: 'In Trattativa',
                    approvata: 'Approvata', rifiutata: 'Rifiutata', scaduta: 'Scaduta'
                };
                badgeBtn.className = 'cfl-badge cfl-badge-' + newStatus + ' cfl-badge-btn';
                badgeBtn.childNodes[0].textContent = labels[newStatus] || newStatus;

                // Update row data-status for filter
                const row = cell.closest('tr');
                if (row) row.dataset.status = newStatus;
            }
        })
        .catch(function () {
            badgeBtn.classList.remove('saving');
        });

        e.stopPropagation();
        return;
    }

    // Click outside: close all dropdowns
    document.querySelectorAll('.cfl-status-dropdown.open').forEach(d => d.classList.remove('open'));
});
