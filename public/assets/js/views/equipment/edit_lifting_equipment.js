document.addEventListener('DOMContentLoaded', function () {

    var DOW = { 1: 'Lun', 2: 'Mar', 3: 'Mer', 4: 'Gio', 5: 'Ven', 6: 'Sab', 7: 'Dom' };

    // ── Elimina noleggio ─────────────────────────────────────────────────────
    document.querySelectorAll('[data-delete-id]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Eliminare definitivamente questo noleggio? Ricorda di salvare le modifiche.')) return;

            var id   = btn.getAttribute('data-delete-id');
            var card = btn.closest('.rn-card');
            card.remove();

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

    // ── Tipo noleggio: mostra/nasconde pannelli + label costo ────────────────
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
            var isDaily = sel.value === 'Giornaliero';
            var panel = card.querySelector('.rn-count-panel');
            var extra = card.querySelector('.rn-extra');
            if (panel) panel.classList.toggle('rn-hidden', !isDaily);
            if (extra) extra.classList.toggle('rn-hidden', !isDaily);
            var label = card.querySelector('.costo-label');
            if (label) label.textContent = COSTO_LABELS[sel.value] || 'Costo €';
        });
    });

    // ── Giorni della settimana: chips → hidden CSV ───────────────────────────
    document.querySelectorAll('.rn-days').forEach(function (wrap) {
        var hidden = wrap.parentElement.querySelector('input.cal-value');

        wrap.querySelectorAll('.rn-day').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var active = wrap.querySelectorAll('.rn-day.on');
                // almeno un giorno deve restare selezionato
                if (chip.classList.contains('on') && active.length === 1) {
                    chip.classList.add('rn-shake');
                    setTimeout(function () { chip.classList.remove('rn-shake'); }, 300);
                    return;
                }
                chip.classList.toggle('on');

                var days = [];
                wrap.querySelectorAll('.rn-day.on').forEach(function (c) {
                    days.push(parseInt(c.dataset.day, 10));
                });
                days.sort(function (a, b) { return a - b; });
                if (hidden) hidden.value = days.join(',');
            });
        });
    });

    // ── Festivi: switch → hidden ─────────────────────────────────────────────
    document.querySelectorAll('.fest-check').forEach(function (chk) {
        chk.addEventListener('change', function () {
            var hidden = chk.closest('.rn-count-panel')
                ? chk.closest('.rn-count-panel').querySelector('input.fest-value')
                : null;
            if (hidden) hidden.value = chk.checked ? '1' : '';
        });
    });

    // ── Giorni extra: aggiungi/rimuovi chip di date ──────────────────────────
    function buildChip(rentalId, dateStr) {
        var d    = new Date(dateStr + 'T00:00:00');
        var dow  = d.getDay() === 0 ? 7 : d.getDay(); // ISO
        var chip = document.createElement('span');
        chip.className = 'rn-chip';
        chip.append(DOW[dow] + ' ' + d.toLocaleDateString('it-IT'));

        var hidden = document.createElement('input');
        hidden.type  = 'hidden';
        hidden.name  = 'extra_days[' + rentalId + '][]';
        hidden.value = dateStr;
        chip.appendChild(hidden);

        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'rn-chip-x';
        x.title = 'Rimuovi';
        x.innerHTML = '&times;';
        chip.appendChild(x);
        return chip;
    }

    document.querySelectorAll('.rn-extra').forEach(function (box) {
        var rentalId = box.dataset.rental;
        var chips    = box.querySelector('.rn-extra-chips');
        var dateIn   = box.querySelector('.rn-extra-date');
        var addBtn   = box.querySelector('.rn-btn-add-day');

        if (addBtn) addBtn.addEventListener('click', function () {
            var v = dateIn.value;
            if (!v) { dateIn.focus(); return; }

            // niente duplicati
            var dup = false;
            chips.querySelectorAll('input[type=hidden]').forEach(function (h) {
                if (h.value === v) dup = true;
            });
            if (dup) { alert('Questo giorno è già stato aggiunto.'); return; }

            chips.appendChild(buildChip(rentalId, v));
            dateIn.value = '';
        });

        // rimozione chip (delegata: vale anche per quelle server-rendered)
        box.addEventListener('click', function (e) {
            var x = e.target.closest && e.target.closest('.rn-chip-x');
            if (x) x.closest('.rn-chip').remove();
        });
    });

});
