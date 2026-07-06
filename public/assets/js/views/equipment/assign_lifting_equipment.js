    const _sel = document.querySelector('#worksiteSelect');
    const mezzi = JSON.parse(_sel?.dataset.mezzi || '[]');

    const _selectedValue = _sel?.dataset.selectedValue || '';
    const _selectedText  = _sel?.dataset.selectedText  || '';

    new TomSelect('#worksiteSelect', {
        create: false,
        preload: false,
        openOnFocus: false,
        valueField: 'value',
        labelField: 'text',
        searchField: 'text',
        shouldLoad: query => query.length >= 2,
        load: function(query, callback) {
            let url = '/equipment/search-worksites?context=attendance';
            if (query.length >= 2) {
                url += '&q=' + encodeURIComponent(query);
            }
            fetch(url)
                .then(res => res.json())
                .then(callback)
                .catch(() => callback());
        },
        ...(_selectedValue ? {
            items: [_selectedValue],
            options: [{ value: _selectedValue, text: _selectedText }]
        } : {})
    });




    document.querySelector('#worksiteSelect').addEventListener('change', function () {
        const id = this.value;
        if (id) {
            window.location.href = "/equipment/assign?worksite_id=" + id;
        }
    });

    document.querySelector('#add-row')?.addEventListener('click', function () {
        const container = document.querySelector('#mezzi-container');
        let options = '<option value="">-- Scegli mezzo --</option>';
        mezzi.forEach(m => {
            options += `
<option
    value="${m.id}"
    data-special="${m.descrizione === 'Trasporto A/R' ? '1' : '0'}"
>
    ${m.descrizione}
</option>`;
        });

        const div = document.createElement('div');
        div.classList.add("row-item", "grid", "grid-cols-12", "gap-2", "items-end", "bg-gray-50", "p-2", "rounded", "mt-2");
        div.innerHTML = `
        <div class="col-span-3">
            <label class="form-label">Mezzo</label>
            <select name="mezzo_id[]" class="form-select" required>${options}</select>
        </div>

        <div class="col-span-2">
            <label class="form-label">Tipo</label>
            <select name="tipo_noleggio[]" class="form-select tipo-select">
                <option value="Giornaliero">Giornaliero</option>
                <option value="Settimanale">Settimanale</option>
                <option value="Mensile">Mensile</option>
                <option value="Una Tantum">Una Tantum</option>
            </select>
        </div>

        <div class="col-span-2">
            <label class="form-label">Quantità</label>
            <input type="number" name="quantita[]" min="1" value="1" class="form-control" required>
        </div>

        <div class="col-span-2">
            <label class="form-label costo-label">Costo (€/giorno)</label>
            <input type="number" step="0.01" name="costo[]" class="form-control" required>
        </div>

        <div class="col-span-2">
            <label class="form-label">Data Inizio</label>
            <input type="date" name="data_inizio[]" class="form-control" required>
        </div>

        <div class="col-span-1 flex">
            <button type="button" class="btn btn-danger remove-row">Elimina Riga</button>
        </div>

        <div class="col-span-4 cal-wrap">
            <label class="form-label">Giorni conteggiati</label>
            <div class="rn-days">
                <button type="button" class="rn-day on" data-day="1">Lun</button>
                <button type="button" class="rn-day on" data-day="2">Mar</button>
                <button type="button" class="rn-day on" data-day="3">Mer</button>
                <button type="button" class="rn-day on" data-day="4">Gio</button>
                <button type="button" class="rn-day on" data-day="5">Ven</button>
                <button type="button" class="rn-day" data-day="6">Sab</button>
                <button type="button" class="rn-day" data-day="7">Dom</button>
            </div>
            <input type="hidden" name="calendario[]" class="cal-value" value="1,2,3,4,5">
        </div>
        <div class="col-span-3 cal-wrap">
            <label class="form-label">Festivi nazionali</label>
            <label class="rn-fest">
                <input type="checkbox" class="fest-check">
                <span class="rn-fest-track"></span>
                <span class="rn-fest-label">Conta anche i festivi</span>
            </label>
            <input type="hidden" name="festivi_inclusi[]" class="fest-value" value="">
        </div>
    `;

        container.appendChild(div);
        handleMezzoLogic(div);
        initCalendarUI(div);
        attachRemoveListeners();
    });

    function handleMezzoLogic(row) {
        const mezzoSelect = row.querySelector('select[name="mezzo_id[]"]');
        const tipoSelect  = row.querySelector('select[name="tipo_noleggio[]"]');

        if (!mezzoSelect || !tipoSelect) return;

        let lastMezzoWasSpecial = false;

        mezzoSelect.addEventListener('change', function () {
            const selectedOption = this.options[this.selectedIndex];
            const isSpecial = selectedOption?.dataset.special === '1';

            if (isSpecial) {
                // forza Una Tantum
                tipoSelect.value = 'Una Tantum';
                tipoSelect.dataset.locked = '1';
                lastMezzoWasSpecial = true;
            } else {
                // se prima era Trasporto A/R, chiedi conferma
                if (lastMezzoWasSpecial && tipoSelect.value === 'Una Tantum') {
                    const ok = confirm(
                        'Il mezzo selezionato non è Trasporto A/R.\nVuoi mantenere il tipo "Una Tantum"?'
                    );
                    if (!ok) {
                        tipoSelect.value = 'Giornaliero';
                    }
                }
                tipoSelect.dataset.locked = '0';
                lastMezzoWasSpecial = false;
            }
        });

        tipoSelect.addEventListener('change', function () {
            if (this.dataset.locked === '1' && this.value !== 'Una Tantum') {
                alert('Per il mezzo Trasporto A/R il tipo deve essere "Una Tantum".');
                this.value = 'Una Tantum';
            }
            syncTipoUI(row);
        });

        syncTipoUI(row);
    }

    // Calendario/festivi valgono solo per il Giornaliero; la label del costo
    // segue la periodicità scelta.
    const COSTO_LABELS = {
        'Giornaliero': 'Costo (€/giorno)',
        'Settimanale': 'Costo (€/settimana)',
        'Mensile':     'Costo (€/mese)',
        'Una Tantum':  'Costo (€)'
    };

    function syncTipoUI(row) {
        const tipo = row.querySelector('select[name="tipo_noleggio[]"]')?.value || 'Giornaliero';
        row.querySelectorAll('.cal-wrap').forEach(el => {
            el.classList.toggle('hidden', tipo !== 'Giornaliero');
        });
        const label = row.querySelector('.costo-label');
        if (label) label.textContent = COSTO_LABELS[tipo] || 'Costo (€)';
    }

    // Chips giorni settimana → hidden CSV; switch festivi → hidden
    function initCalendarUI(row) {
        const daysWrap = row.querySelector('.rn-days');
        const calValue = row.querySelector('input.cal-value');
        if (daysWrap && calValue && !daysWrap.dataset.bound) {
            daysWrap.dataset.bound = '1';
            daysWrap.querySelectorAll('.rn-day').forEach(chip => {
                chip.addEventListener('click', () => {
                    const active = daysWrap.querySelectorAll('.rn-day.on');
                    if (chip.classList.contains('on') && active.length === 1) return; // almeno 1 giorno
                    chip.classList.toggle('on');
                    const days = [...daysWrap.querySelectorAll('.rn-day.on')]
                        .map(c => parseInt(c.dataset.day, 10))
                        .sort((a, b) => a - b);
                    calValue.value = days.join(',');
                });
            });
        }
        const festCheck = row.querySelector('.fest-check');
        const festValue = row.querySelector('input.fest-value');
        if (festCheck && festValue && !festCheck.dataset.bound) {
            festCheck.dataset.bound = '1';
            festCheck.addEventListener('change', () => {
                festValue.value = festCheck.checked ? '1' : '';
            });
        }
    }

    function attachRemoveListeners() {
        document.querySelectorAll('.remove-row').forEach(button => {
            button.classList.remove("hidden");
            button.onclick = function () {
                this.closest('.row-item').remove();
            };
        });
    }

    document.querySelectorAll('.row-item').forEach(row => {
        handleMezzoLogic(row);
        initCalendarUI(row);
    });

    attachRemoveListeners();

    document.querySelector('#copy-start-date')?.addEventListener('click', function () {
        const rows = document.querySelectorAll('.row-item');

        if (rows.length === 0) return;

        // prendo la data dalla prima riga
        const firstDateInput = rows[0].querySelector('input[name="data_inizio[]"]');
        if (!firstDateInput || !firstDateInput.value) {
            alert('Imposta prima la data di inizio nella prima riga.');
            return;
        }

        const dateValue = firstDateInput.value;

        rows.forEach(row => {
            const dateInput = row.querySelector('input[name="data_inizio[]"]');
            if (dateInput) {
                dateInput.value = dateValue;
            }
        });
    });
