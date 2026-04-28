(function () {
    'use strict';

    let activeFilter = 'all';

    function applyFilters() {
        const q = (document.getElementById('ex-search').value || '').toLowerCase();
        let visible = 0;

        document.querySelectorAll('.ex-row').forEach(function (row) {
            const matchType = activeFilter === 'all' || row.dataset.type === activeFilter;
            const matchText = row.textContent.toLowerCase().includes(q);
            const show = matchType && matchText;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        // Show/hide whole sections based on filter
        document.querySelectorAll('.ex-section').forEach(function (sec) {
            if (activeFilter === 'all') {
                sec.style.display = '';
            } else {
                sec.style.display = sec.dataset.section === activeFilter ? '' : 'none';
            }
        });

        const countEl = document.getElementById('ex-count');
        if (countEl) {
            countEl.textContent = visible + ' risultat' + (visible === 1 ? 'o' : 'i');
        }
    }

    // Filter buttons
    document.querySelectorAll('.ex-filter-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            activeFilter = this.dataset.filter || 'all';
            document.querySelectorAll('.ex-filter-btn').forEach(function (b) {
                b.classList.remove('active');
            });
            this.classList.add('active');
            applyFilters();
        });
    });

    // Search input
    const searchEl = document.getElementById('ex-search');
    if (searchEl) {
        searchEl.addEventListener('input', applyFilters);
    }
}());
