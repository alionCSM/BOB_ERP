// Wait for DOM to be ready
document.addEventListener('DOMContentLoaded', function() {
    // Select all permission cards
    const cards = document.querySelectorAll('.pe-card');

    // Toggle single permission
    function togglePerm(card) {
        const cb = card.querySelector('input[type="checkbox"]');
        if (!cb) return;
        cb.checked = !cb.checked;
        card.classList.toggle('active', cb.checked);

        // Update icon stroke
        const svg = card.querySelector('.pe-card-icon svg');
        if (svg) {
            const color = card.style.getPropertyValue('--mod-color').trim();
            svg.setAttribute('stroke', cb.checked ? '#fff' : color);
        }

        updateCount();
    }

    // Update all counters
    function updateCount() {
        const active = document.querySelectorAll('.pe-card.active').length;
        const peCounter = document.getElementById('pe-counter');
        const peFooterCount = document.getElementById('pe-footer-count');
        if (peCounter) peCounter.textContent = active;
        if (peFooterCount) peFooterCount.textContent = active;

        // Update group counters
        document.querySelectorAll('.pe-group-count').forEach(el => {
            const groupKey = el.dataset.group;
            const groupActive = document.querySelectorAll(`.pe-card.active[data-group="${groupKey}"]`).length;
            const gcNum = el.querySelector('.pe-gc-num');
            if (gcNum) gcNum.textContent = groupActive;
        });
    }

    // Set all or none
    function setAll(state) {
        cards.forEach(card => {
            const cb = card.querySelector('input[type="checkbox"]');
            if (!cb) return;
            cb.checked = state;
            card.classList.toggle('active', state);
            const svg = card.querySelector('.pe-card-icon svg');
            if (svg) {
                const color = card.style.getPropertyValue('--mod-color').trim();
                svg.setAttribute('stroke', state ? '#fff' : color);
            }
        });
        updateCount();
    }

    // Attach click handlers to cards (using event delegation on each group body)
    document.querySelectorAll('.pe-group-body').forEach(groupBody => {
        groupBody.addEventListener('click', function(e) {
            const card = e.target.closest('.pe-card');
            if (card) {
                togglePerm(card);
            }
        });
    });

    // Attach handlers to buttons
    const btnAll = document.getElementById('btn-all');
    const btnNone = document.getElementById('btn-none');
    if (btnAll) btnAll.addEventListener('click', () => setAll(true));
    if (btnNone) btnNone.addEventListener('click', () => setAll(false));

    // Initial count update
    updateCount();
});
