// Attività tab - Edit functionality

document.addEventListener('DOMContentLoaded', function() {
    const editButtons = document.querySelectorAll('.edit-attivita-btn');

    editButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            const id = this.dataset.id;
            const data = this.dataset.data || '';
            const attivita = this.dataset.attivita || '';
            const persone = this.dataset.persone || '';
            const tempo = this.dataset.tempo || '';
            const quantita = this.dataset.quantita || '';
            const giuomo = this.dataset.giuomo || '';
            const attrezzature = this.dataset.attrezzature || '';
            const problemi = this.dataset.problemi || '';
            const soluzioni = this.dataset.soluzioni || '';
            const note = this.dataset.note || '';

            // Fill form fields
            document.getElementById('attivita-id').value = id;
            document.getElementById('attivita-data').value = data;
            document.getElementById('attivita-attivita').value = attivita;
            document.getElementById('attivita-persone').value = persone;
            document.getElementById('attivita-tempo').value = tempo;
            document.getElementById('attivita-quantita').value = quantita;
            document.getElementById('attivita-giuomo').value = giuomo;
            document.getElementById('attivita-attrezzature').value = attrezzature;
            document.getElementById('attivita-problemi').value = problemi;
            document.getElementById('attivita-soluzioni').value = soluzioni;
            document.getElementById('attivita-note').value = note;

            // Update modal title
            const titleEl = document.getElementById('attivita-modal-title');
            if (titleEl) {
                titleEl.textContent = 'Modifica Attività';
            }
        });
    });

    // Clear form when modal is hidden (reset for new entry)
    const modal = document.getElementById('attivita-modal');
    if (modal) {
        modal.addEventListener('hidden.bs.modal', function() {
            document.getElementById('attivita-id').value = '';
            document.getElementById('attivita-data').value = '';
            document.getElementById('attivita-attivita').value = '';
            document.getElementById('attivita-persone').value = '';
            document.getElementById('attivita-tempo').value = '';
            document.getElementById('attivita-quantita').value = '';
            document.getElementById('attivita-giuomo').value = '';
            document.getElementById('attivita-attrezzature').value = '';
            document.getElementById('attivita-problemi').value = '';
            document.getElementById('attivita-soluzioni').value = '';
            document.getElementById('attivita-note').value = '';

            const titleEl = document.getElementById('attivita-modal-title');
            if (titleEl) {
                titleEl.textContent = 'Aggiungi / Modifica Attività';
            }
        });
    }
});
