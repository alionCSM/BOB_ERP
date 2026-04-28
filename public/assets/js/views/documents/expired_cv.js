(function () {
    'use strict';

    let activeFilter = 'all';

    function applyFilters() {
        const q = (document.getElementById('dc-search').value || '').toLowerCase();
        let visible = 0;

        document.querySelectorAll('.dc-row').forEach(function (row) {
            const matchType = activeFilter === 'all' || row.dataset.type === activeFilter;
            const matchText = !q || (row.dataset.search || '').includes(q);
            const show = matchType && matchText;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        const countEl = document.getElementById('dc-count');
        if (countEl) {
            countEl.textContent = visible + ' risultat' + (visible === 1 ? 'o' : 'i');
        }
    }

    // Filter buttons
    document.querySelectorAll('.dc-filter-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            activeFilter = this.dataset.filter || 'all';
            document.querySelectorAll('.dc-filter-btn').forEach(function (b) {
                b.classList.remove('active');
            });
            this.classList.add('active');
            applyFilters();
        });
    });

    // Section collapse/expand toggle
    document.querySelectorAll('[data-section-toggle]').forEach(function (head) {
        head.addEventListener('click', function () {
            const targetId = this.dataset.sectionToggle;
            const section = document.getElementById(targetId);
            if (section) {
                section.classList.toggle('collapsed');
            }
        });
    });

    // Search input
    const searchEl = document.getElementById('dc-search');
    if (searchEl) {
        searchEl.addEventListener('input', applyFilters);
    }
}());
