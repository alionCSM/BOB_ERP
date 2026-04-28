document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('search-companies');
    var toolbar = document.querySelector('.mc-toolbar-title');
    if (!input) return;

    input.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        var cards = document.querySelectorAll('#companies-grid .mc-card');
        var visible = 0;

        cards.forEach(function (card) {
            var match = !q
                || (card.dataset.name   || '').includes(q)
                || (card.dataset.codice || '').includes(q);
            card.style.display = match ? '' : 'none';
            if (match) visible++;
        });

        if (toolbar) {
            toolbar.textContent = visible + (visible === 1 ? ' risultato' : ' risultati');
        }

        var noRes = document.getElementById('no-results');
        var grid  = document.getElementById('companies-grid');
        if (noRes && grid) {
            noRes.style.display = visible === 0 ? '' : 'none';
            grid.style.display  = visible === 0 ? 'none' : '';
        }
    });
});
