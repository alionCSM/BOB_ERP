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

    // ── Tipo: chips Ferie/Permesso → select nascosto ────────────────────────
    const tipoSelect = document.getElementById('tipoSelect');
    const oreWrap    = document.querySelector('.ore-wrap');
    const chips      = document.querySelectorAll('.fe-tipo-chip');

    const protWrap = document.querySelector('.prot-wrap');

    function setTipo(tipo) {
        tipoSelect.value = tipo;
        chips.forEach(c => {
            c.classList.remove('on-ferie', 'on-permesso', 'on-malattia');
            if (c.dataset.tipo === tipo) c.classList.add('on-' + tipo);
        });

        // Ore ha senso solo per il permesso a ore
        const isPermesso = tipo === 'permesso';
        oreWrap.classList.toggle('hidden', !isPermesso);
        if (!isPermesso) document.querySelector('input[name="ore"]').value = '';

        // Il certificato solo sulla malattia. Svuotato quando si nasconde:
        // un numero rimasto da una scelta precedente partirebbe lo stesso
        // al salvataggio e finirebbe attaccato a una ferie.
        const isMalattia = tipo === 'malattia';
        protWrap.classList.toggle('hidden', !isMalattia);
        if (!isMalattia) document.querySelector('input[name="protocollo"]').value = '';
    }
    chips.forEach(c => c.addEventListener('click', () => setTipo(c.dataset.tipo)));
    setTipo('ferie');

    // ── Il "Dal" precompila l'"Al" (caso comune: giorno singolo) ────────────
    const dal = document.querySelector('input[name="data_inizio"]');
    const al  = document.querySelector('input[name="data_fine"]');
    dal.addEventListener('change', function () {
        if (!al.value || al.value < dal.value) al.value = dal.value;
    });

    // ── Modifica record ──────────────────────────────────────────────────────
    const formTitle = document.getElementById('fe-form-title');
    const cancelBtn = document.getElementById('fe-cancel');
    const recordId  = document.querySelector('input[name="record_id"]');

    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            recordId.value = this.dataset.id;
            setTipo(this.dataset.tipo);
            dal.value = this.dataset.dal;
            al.value  = this.dataset.al;
            document.querySelector('input[name="ore"]').value     = this.dataset.ore || '';
            document.querySelector('textarea[name="note"]').value = this.dataset.note || '';
            // dopo setTipo, che svuota il campo quando non serve
            document.querySelector('input[name="protocollo"]').value = this.dataset.protocollo || '';

            operaioSelect.addOption({ value: this.dataset.operaio, text: this.dataset.operaioNome });
            operaioSelect.setValue(this.dataset.operaio);

            formTitle.textContent = 'Modifica: ' + this.dataset.operaioNome;
            cancelBtn.classList.add('visible');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });

    // annulla modifica → torna in modalità inserimento
    cancelBtn.addEventListener('click', function () {
        recordId.value = '';
        setTipo('ferie');
        dal.value = '';
        al.value  = '';
        document.querySelector('input[name="ore"]').value       = '';
        document.querySelector('input[name="protocollo"]').value = '';
        document.querySelector('textarea[name="note"]').value   = '';
        operaioSelect.clear();
        formTitle.textContent = 'Registra nuova assenza';
        cancelBtn.classList.remove('visible');
    });

    // ── Filtro live tabella ──────────────────────────────────────────────────
    document.getElementById('searchInput').addEventListener('input', function () {
        const filter = this.value.toLowerCase();
        document.querySelectorAll('#ferieTable tbody tr').forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(filter) ? '' : 'none';
        });
    });

    // ── Dismiss toast ────────────────────────────────────────────────────────
    document.querySelectorAll('[data-dismiss-alert]').forEach(btn => {
        btn.addEventListener('click', () => btn.parentElement.remove());
    });

});
