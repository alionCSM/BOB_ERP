document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('[data-delete-id]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Eliminare definitivamente questo noleggio? Ricorda di salvare le modifiche.')) return;

            var id   = btn.getAttribute('data-delete-id');
            var card = btn.closest('.rn-card') || btn.closest('tr');

            // Remove the card from the DOM
            card.remove();

            // Append a hidden input to the save form so the deletion is sent on submit
            var input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'delete_ids[]';
            input.value = id;
            document.querySelector('form').appendChild(input);
        });
    });

    // dismiss toast (era onclick inline → bloccato da CSP)
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-dismiss-alert]');
        if (btn && btn.parentElement) btn.parentElement.style.display = 'none';
    });

    // Calendario/festivi/giorni extra valgono solo per il Giornaliero: per gli
    // altri tipi il blocco viene nascosto (ma i campi vengono comunque inviati,
    // per mantenere allineati gli array del form). La label del costo segue
    // la periodicità scelta.
    var COSTO_LABELS = {
        'Giornaliero': 'Costo € / giorno',
        'Settimanale': 'Costo € / settimana',
        'Mensile':     'Costo € / mese',
        'Una Tantum':  'Costo €'
    };

    document.querySelectorAll('select.tipo-select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var card = sel.closest('.rn-card');
            if (!card) return;
            var cal = card.querySelector('.rn-cal-fields');
            if (cal) cal.classList.toggle('rn-hidden', sel.value !== 'Giornaliero');
            var label = card.querySelector('.costo-label');
            if (label) label.textContent = COSTO_LABELS[sel.value] || 'Costo €';
        });
    });

});
