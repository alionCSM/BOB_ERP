// ── Status change ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const statusGroup = document.getElementById('statusSelectGroup');
    if (!statusGroup) return;
    const ordineId  = statusGroup.dataset.ordineId;
    const heroBadge = document.getElementById('heroBadge');

    statusGroup.querySelectorAll('.cof-status-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            statusGroup.querySelectorAll('.cof-status-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            if (heroBadge) {
                heroBadge.className = 'col-badge col-badge-' + this.dataset.val;
                heroBadge.textContent = this.textContent.trim();
            }
            fetch('/ordini/' + ordineId + '/status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'status=' + encodeURIComponent(this.dataset.val)
            });
        });
    });
});

// ── Delete confirmation ───────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const deleteBtn  = document.getElementById('deleteOrdineBtn');
    const deleteForm = document.getElementById('deleteOrdineForm');
    if (!deleteBtn || !deleteForm) return;
    deleteBtn.addEventListener('click', function () {
        if (confirm('Sei sicuro di voler eliminare questo ordine? L\'operazione è irreversibile.')) {
            deleteForm.submit();
        }
    });
});
