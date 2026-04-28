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
            <select name="tipo_noleggio[]" class="form-select">
                <option value="Giornaliero">Giornaliero</option>
                <option value="Una Tantum">Una Tantum</option>
            </select>
        </div>

        <div class="col-span-2">
            <label class="form-label">Quantità</label>
            <input type="number" name="quantita[]" min="1" value="1" class="form-control" required>
        </div>

        <div class="col-span-2">
            <label class="form-label">Costo (€)</label>
            <input type="number" step="0.01" name="costo[]" class="form-control" required>
        </div>

        <div class="col-span-2">
            <label class="form-label">Data Inizio</label>
            <input type="date" name="data_inizio[]" class="form-control" required>
        </div>

        <div class="col-span-1 flex">
            <button type="button" class="btn btn-danger remove-row">Elimina Riga</button>
        </div>
    `;

        container.appendChild(div);
        handleMezzoLogic(div);
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
        });
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
