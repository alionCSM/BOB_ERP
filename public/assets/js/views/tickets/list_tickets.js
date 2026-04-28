let editTomSelect = null;

document.addEventListener('DOMContentLoaded', function() {

    // ── TomSelect for main form worker search (multi-select)
    new TomSelect('#workerSearch', {
        valueField: 'nome_completo',
        labelField: 'nome_completo',
        searchField: ['nome_completo'],
        create: true,
        plugins: ['remove_button'],
        maxItems: null,
        placeholder: 'Seleziona uno o più operai...',
        load: function(query, callback) {
            if (!query.length) return callback();
            fetch('/tickets/fetch-workers?q=' + encodeURIComponent(query))
                .then(r => r.json())
                .then(data => callback(data))
                .catch(() => callback());
        }
    });

    // ── TomSelect for edit modal
    editTomSelect = new TomSelect('#editWorkerSearch', {
        valueField: 'nome_completo',
        labelField: 'nome_completo',
        searchField: ['nome_completo'],
        create: true,
        load: function(query, callback) {
            if (!query.length) return callback();
            fetch('/tickets/fetch-workers?q=' + encodeURIComponent(query))
                .then(r => r.json())
                .then(data => callback(data))
                .catch(() => callback());
        }
    });

    // ── Main form submit (create + print — supports multiple workers)
    document.getElementById('ticketForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const select = this.querySelector('[name="worker_names[]"]');
        const tsInstance = select.tomselect;
        const selectedWorkers = tsInstance ? tsInstance.getValue() : [];
        const ticketDate = this.querySelector('[name=ticket_date]').value;

        if (!selectedWorkers.length || !ticketDate) {
            alert('Seleziona almeno un operaio e una data.');
            return;
        }

        const fd = new FormData();
        selectedWorkers.forEach(name => fd.append('worker_names[]', name));
        fd.append('ticket_date', ticketDate);

        fetch('/tickets/add', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok && data.ids && data.ids.length) {
                    window.open('/tickets/print?ids=' + data.ids.join(','), '_blank');
                    window.location.reload();
                } else {
                    alert(data.errors?.join('\n') || data.error || 'Errore');
                }
            })
            .catch(() => alert('Errore di rete'));
    });

    // ── Edit form submit
    document.getElementById('editForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const id = document.getElementById('editId').value;
        const workerName = this.querySelector('[name=worker_name]').value;
        const ticketDate = document.getElementById('editDate').value;

        if (!id || !workerName || !ticketDate) return;

        const fd = new FormData();
        fd.append('id', id);
        fd.append('worker_name', workerName);
        fd.append('ticket_date', ticketDate);

        fetch('/tickets/update', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    closeModal('editModal');
                    window.location.reload();
                } else {
                    alert(data.error || 'Errore');
                }
            })
            .catch(() => alert('Errore di rete'));
    });

    // ── Table search
    const searchInput = document.getElementById('tkSearchInput');
    if (searchInput) searchInput.addEventListener('input', filterTable);

    // ── Clear search button
    document.getElementById('tk-clear-search')?.addEventListener('click', function() {
        document.getElementById('tkSearchInput').value = '';
        filterTable();
    });

    // ── Report button
    document.getElementById('tk-report-btn')?.addEventListener('click', function() {
        openModal('reportModal');
    });

    // ── Report form: close modal on submit
    document.getElementById('reportForm')?.addEventListener('submit', function() {
        closeModal('reportModal');
    });

    // ── Table: edit + delete via event delegation
    document.addEventListener('click', function(e) {
        // Close modal buttons
        const closeBtn = e.target.closest('[data-close-modal]');
        if (closeBtn) {
            closeModal(closeBtn.dataset.closeModal);
            return;
        }

        // Edit button
        const editBtn = e.target.closest('.tk-edit-btn');
        if (editBtn) {
            openEditModal(
                parseInt(editBtn.dataset.id, 10),
                editBtn.dataset.worker,
                editBtn.dataset.date
            );
            return;
        }

        // Delete button
        const deleteBtn = e.target.closest('.tk-delete-btn');
        if (deleteBtn) {
            deleteTicket(parseInt(deleteBtn.dataset.id, 10));
        }
    });
});

function openEditModal(id, workerName, ticketDate) {
    document.getElementById('editId').value = id;
    document.getElementById('editDate').value = ticketDate;

    // Set worker in TomSelect
    if (editTomSelect) {
        editTomSelect.addOption({ nome_completo: workerName });
        editTomSelect.setValue(workerName, true);
    }

    openModal('editModal');
}

function filterTable() {
    const table = document.getElementById('tkTable');
    if (!table) return;

    const val = document.getElementById('tkSearchInput').value.toLowerCase();
    const rows = table.querySelectorAll('tbody tr');
    let visible = 0;
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const show = text.includes(val);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('tkRowCount').textContent = visible;
}

function deleteTicket(id) {
    if (!confirm('Sei sicuro di voler eliminare questo bigliettino?')) return;
    const fd = new FormData();
    fd.append('id', id);
    fetch('/tickets/delete', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) window.location.reload();
            else alert(data.error || 'Errore');
        });
}

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
