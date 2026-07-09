document.addEventListener('DOMContentLoaded', function () {

    const operaioSelect = new TomSelect('#operaioSelect', {
        valueField: 'value',
        labelField: 'text',
        searchField: 'text',
        placeholder: 'Cerca operaio...',
        load: function (query, callback) {
            fetch('/api/attendance/workers?q=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(callback)
                .catch(() => callback());
        },
        shouldLoad: query => query.length >= 2
    });

    // Ore ha senso solo per il permesso (a ore); per le ferie resta nascosto
    const tipoSelect = document.getElementById('tipoSelect');
    const oreWrap    = document.querySelector('.ore-wrap');
    function syncOre() {
        const isPermesso = tipoSelect.value === 'permesso';
        oreWrap.classList.toggle('hidden', !isPermesso);
        if (!isPermesso) document.querySelector('input[name="ore"]').value = '';
    }
    tipoSelect.addEventListener('change', syncOre);
    syncOre();

    // Il "Dal" precompila l'"Al" se vuoto (caso comune: giorno singolo)
    const dal = document.querySelector('input[name="data_inizio"]');
    const al  = document.querySelector('input[name="data_fine"]');
    dal.addEventListener('change', function () {
        if (!al.value || al.value < dal.value) al.value = dal.value;
    });

    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelector('input[name="record_id"]').value = this.dataset.id;
            tipoSelect.value = this.dataset.tipo;
            syncOre();
            dal.value = this.dataset.dal;
            al.value  = this.dataset.al;
            document.querySelector('input[name="ore"]').value      = this.dataset.ore || '';
            document.querySelector('textarea[name="note"]').value  = this.dataset.note || '';

            operaioSelect.addOption({ value: this.dataset.operaio, text: this.dataset.operaioNome });
            operaioSelect.setValue(this.dataset.operaio);

            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });

    // Filtraggio live tabella
    document.getElementById('searchInput').addEventListener('input', function () {
        const filter = this.value.toLowerCase();
        document.querySelectorAll('#ferieTable tbody tr').forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(filter) ? '' : 'none';
        });
    });

});
