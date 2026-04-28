document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('search-input');
    const rows  = document.querySelectorAll('.client-row');
    const noRes = document.getElementById('bc-no-results');
    const count = document.getElementById('bc-count');
    if (!input) return;

    input.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        let visible = 0;
        rows.forEach(function (r) {
            const match = !q || r.dataset.name.includes(q);
            r.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        if (count) count.textContent = visible;
        if (noRes) noRes.style.display = visible === 0 ? '' : 'none';
    });
});
