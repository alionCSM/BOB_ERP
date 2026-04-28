        const form = document.getElementById('attendanceForm');
        const deletedInputContainer = document.createElement('div');
        deletedInputContainer.id = 'deleted-nostri-container';
        form.appendChild(deletedInputContainer);

        const deletedConsContainer = document.createElement('div');
        deletedConsContainer.id = 'deleted-consorziate-container';
        form.appendChild(deletedConsContainer);


        // quando rimuovi una riga
        document.addEventListener("click", function (e) {
            if (!e.target.classList.contains("remove-row")) return;

            const tr = e.target.closest("tr");

            // ---- NOSTRI ----
            const existingNostro = tr.querySelector('input[name="existing_id[]"]');
            if (existingNostro && existingNostro.value) {
                const hid = document.createElement('input');
                hid.type  = 'hidden';
                hid.name  = 'deleted_nostri[]';
                hid.value = existingNostro.value;
                deletedInputContainer.appendChild(hid);
            }

            // ---- CONSORZIATE ----
            const existingCons = tr.querySelector('input[name^="existing_cons_id"]');
            if (existingCons && existingCons.value) {
                const hid = document.createElement('input');
                hid.type  = 'hidden';
                hid.name  = 'deleted_consorziate[]';
                hid.value = existingCons.value;
                deletedConsContainer.appendChild(hid);
            }

            tr.remove();
        });


        document.addEventListener("DOMContentLoaded", () => {
            let nextConsIndex = parseInt(document.getElementById('attendanceForm')?.dataset.consCount || '0', 10);

            function createNostriRow() {
                const tbody = document.getElementById("attendanceRows");
                const row = document.createElement("tr");
                row.classList.add("new-row");

                row.innerHTML = `
    <td><select name="worker_id[]" class="tomselect-worker" required></select></td>
    <td>
        <select name="turno[]" class="form-select" required>
            <option value="">Turno</option>
            <option value="Intero">Intero</option>
            <option value="Mezzo">Mezzo</option>
        </select>
    </td>
    <td class="pranzo-cell">
        <div class="flex items-center gap-2">
            <select name="pranzo[]" class="form-select pranzo-select">
                <option value="-">No Pranzo</option>
                <option value="Loro">Loro</option>
                <option value="Noi">Noi</option>
            </select>
            <input type="number" step="0.01" name="pranzo_prezzo[]"
                   class="form-control w-24 hidden"
                   placeholder="€">
        </div>
    </td>
    <td class="cena-cell">
        <div class="flex items-center gap-2">
            <select name="cena[]" class="form-select cena-select">
                <option value="-">-</option>
                <option value="Loro">Loro</option>
                <option value="Noi">Noi</option>
            </select>
            <input type="number" step="0.01" name="cena_prezzo[]"
                   class="form-control w-24 hidden"
                   placeholder="€">
        </div>
    </td>
<td>
    <input
        type="text"
        name="hotel[]"
        class="form-control"
        value=""
        placeholder="Costo Hotel"
    >
</td>
    <td><input type="text" name="auto[]" class="form-control"></td>
    <td><input type="text" name="note[]" class="form-control"></td>
    <td><button type="button" class="text-red-600 font-bold remove-row">✖</button></td>
    `;

                tbody.appendChild(row);

                // attach TomSelect for worker dropdown
                const workerSelect = row.querySelector(".tomselect-worker");
                new TomSelect(workerSelect, {
                    create: false,
                    valueField: 'value',
                    labelField: 'text',
                    searchField: 'text',
                    placeholder: 'Seleziona Operaio',
                    load: function(query, callback) {
                        if (query.length < 2) return callback();
                        fetch('/api/attendance/workers?q=' + encodeURIComponent(query))
                            .then(res => res.json())
                            .then(callback).catch(() => callback());
                    }
                });
            }


            function createConsorziataRow() {
                const i = nextConsIndex++;
                const tbody = document.getElementById("consorziataRows");
                const tr = document.createElement("tr");
                tr.classList.add("new-row");
                tr.innerHTML = `
      <td>
        <select name="consorziate[${i}][nome]" class="tomselect-company" required></select>
      </td>
      <td><input type="number" name="consorziate[${i}][numero]" class="form-control" step="0.5" min="0" required></td>
      <td><input type="number" name="consorziate[${i}][costo]" class="form-control" step="0.01"></td>
      <td><input type="number" name="consorziate[${i}][pasti]" class="form-control"></td>
      <td><input type="text" name="consorziate[${i}][auto]" class="form-control"></td>
      <td><input type="text" name="consorziate[${i}][hotel]" class="form-control"></td>
      <td><input type="text" name="consorziate[${i}][note]" class="form-control"></td>
      <td><button type="button" class="text-red-600 font-bold remove-row">✖</button></td>
    `;
                tbody.appendChild(tr);

                new TomSelect(tr.querySelector(".tomselect-company"), {
                    create: false,
                    valueField: "value",
                    labelField: "text",
                    searchField: "text",
                    placeholder: "Seleziona consorziata",
                    load: function(query, callback) {
                        if (query.length < 2) return callback();
                        fetch("/api/attendance/companies?context=attendance&q=" + encodeURIComponent(query))
                            .then(res => res.json())
                            .then(callback)
                            .catch(() => callback());
                    }
                });
            }

            document.getElementById("addRow").addEventListener("click", createNostriRow);
            document.getElementById("addConsorziataRow").addEventListener("click", createConsorziataRow);


        const tabNostri = document.getElementById("tab-nostri");
        const tabCons   = document.getElementById("tab-consorziata");
        const secNostri = document.getElementById("section-nostri");
        const secCons   = document.getElementById("section-consorziata");

        tabNostri.addEventListener("click", () => {
            secNostri.classList.remove("hidden");
            secCons.classList.add("hidden");
            tabNostri.classList.add("tab-active");
            tabCons.classList.remove("tab-active");
        });

        tabCons.addEventListener("click", () => {
            secCons.classList.remove("hidden");
            secNostri.classList.add("hidden");
            tabCons.classList.add("tab-active");
            tabNostri.classList.remove("tab-active");
        });


            // --- copia valori dalla prima riga a tutte le altre ---
            const copyToggle = document.getElementById('copy-first-row-toggle');
            copyToggle.addEventListener('change', () => {
                const rows = document.querySelectorAll('#attendanceRows tr');
                if (!rows.length) return;

                const first = rows[0];
                const vals = {
                    turno: first.querySelector('select[name="turno[]"]').value,
                    pranzo: first.querySelector('select[name="pranzo[]"]').value,
                    pranzoPrezzo: first.querySelector('input[name="pranzo_prezzo[]"]').value,
                    cena: first.querySelector('select[name="cena[]"]').value,
                    cenaPrezzo: first.querySelector('input[name="cena_prezzo[]"]').value,
                    hotel: first.querySelector('input[name="hotel[]"]').value,
                    note: first.querySelector('input[name="note[]"]').value,
                };

                if (copyToggle.checked) {
                    rows.forEach((row, idx) => {
                        if (idx === 0) return;

                        // Copio i valori base
                        row.querySelector('select[name="turno[]"]').value = vals.turno;
                        row.querySelector('select[name="pranzo[]"]').value = vals.pranzo;
                        row.querySelector('select[name="cena[]"]').value = vals.cena;
                        row.querySelector('input[name="hotel[]"]').value = vals.hotel;
                        row.querySelector('input[name="note[]"]').value = vals.note;

                        // Gestione dinamica del campo prezzo pranzo
                        const pranzoPrezzoInput = row.querySelector('input[name="pranzo_prezzo[]"]');
                        if (vals.pranzo === "Noi") {
                            pranzoPrezzoInput.classList.remove("hidden");
                            pranzoPrezzoInput.value = vals.pranzoPrezzo;
                        } else {
                            pranzoPrezzoInput.classList.add("hidden");
                            pranzoPrezzoInput.value = "";
                        }

                        // Gestione dinamica del campo prezzo cena
                        const cenaPrezzoInput = row.querySelector('input[name="cena_prezzo[]"]');
                        if (vals.cena === "Noi") {
                            cenaPrezzoInput.classList.remove("hidden");
                            cenaPrezzoInput.value = vals.cenaPrezzo;
                        } else {
                            cenaPrezzoInput.classList.add("hidden");
                            cenaPrezzoInput.value = "";
                        }
                    });
                }
            });


            // --- copia valori dalla prima riga consorziate a tutte le altre ---
            const copyConsToggle = document.getElementById('copy-first-cons-toggle');
            copyConsToggle.addEventListener('change', () => {
                const rows = document.querySelectorAll('#consorziataRows tr');
                if (rows.length < 2) return; // niente da copiare

                // leggo i valori dalla prima riga (escludo il select nome)
                const first = rows[0];
                const numero = first.querySelector('input[name*="[numero]"]').value;
                const costo  = first.querySelector('input[name*="[costo]"]').value;
                const pasti  = first.querySelector('input[name*="[pasti]"]').value;
                const auto   = first.querySelector('input[name*="[auto]"]').value;
                const hotel  = first.querySelector('input[name*="[hotel]"]').value;
                const note   = first.querySelector('input[name*="[note]"]').value;

                if (copyConsToggle.checked) {
                    rows.forEach((row, idx) => {
                        if (idx === 0) return;
                        row.querySelector('input[name*="[numero]"]').value = numero;
                        row.querySelector('input[name*="[costo]"]').value  = costo;
                        row.querySelector('input[name*="[pasti]"]').value  = pasti;
                        row.querySelector('input[name*="[auto]"]').value   = auto;
                        row.querySelector('input[name*="[hotel]"]').value  = hotel;
                        row.querySelector('input[name*="[note]"]').value   = note;
                    });
                }
            });


        });

        // --- gestione dinamica campi prezzo per Pranzo/Cena ---
        document.addEventListener("change", function(e) {
            if (e.target.classList.contains("pranzo-select")) {
                const input = e.target.closest("td").querySelector('input[name="pranzo_prezzo[]"]');
                if (e.target.value === "Noi") {
                    input.classList.remove("hidden");
                } else {
                    input.classList.add("hidden");
                    input.value = "";
                }
            }

            if (e.target.classList.contains("cena-select")) {
                const input = e.target.closest("td").querySelector('input[name="cena_prezzo[]"]');
                if (e.target.value === "Noi") {
                    input.classList.remove("hidden");
                } else {
                    input.classList.add("hidden");
                    input.value = "";
                }
            }
        });


        document.getElementById('preview-button').addEventListener('click', () => {
        const summary = document.getElementById('riepilogo-modal-content');
        summary.innerHTML = '';

        const rowsNostri = document.querySelectorAll('#attendanceRows tr');
        const rowsCons = document.querySelectorAll('#consorziataRows tr');

        // Presenze nostri
        if (rowsNostri.length) {
            summary.innerHTML += '<h3 class="font-medium text-slate-700 mb-2">Operai Interni</h3>';
            const table = document.createElement('table');
            table.className = 'w-full mb-6 text-sm border';
            table.innerHTML = `
<thead class="bg-slate-100">
    <tr>
        <th class="p-2">Nome</th>
        <th>Turno</th>
        <th>Pranzo</th>
        <th>€ Pranzo</th>
        <th>Cena</th>
        <th>€ Cena</th>
        <th>Hotel</th>
        <th>Auto</th>
        <th>Note</th>
    </tr>
</thead>
<tbody></tbody>`;

            const tbody = table.querySelector('tbody');

            rowsNostri.forEach(row => {
                const nome = row.querySelector('.tomselect-worker')?.selectedOptions[0]?.textContent || '-';
                const turno = row.querySelector('[name="turno[]"]')?.value || '-';
                const pranzo = row.querySelector('[name="pranzo[]"]')?.value || '-';
                const cena = row.querySelector('[name="cena[]"]')?.value || '-';
                const hotel = row.querySelector('[name="hotel[]"]')?.value || '-';
                const auto = row.querySelector('[name="auto[]"]')?.value || '-';
                const note = row.querySelector('[name="note[]"]')?.value || '-';
                const pranzoPrezzo = row.querySelector('[name="pranzo_prezzo[]"]')?.value || '-';
                const cenaPrezzo   = row.querySelector('[name="cena_prezzo[]"]')?.value || '-';
                tbody.innerHTML += `
<tr class="border-t">
    <td class="p-1">${nome}</td>
    <td>${turno}</td>
    <td>${pranzo}</td>
    <td>${pranzoPrezzo}</td>
    <td>${cena}</td>
    <td>${cenaPrezzo}</td>
    <td>${hotel}</td>
    <td>${auto}</td>
    <td>${note}</td>
</tr>`;

            });

            summary.appendChild(table);
        }

        // Consorziate
        if (rowsCons.length) {
            summary.innerHTML += '<h3 class="font-medium text-slate-700 mb-2">Presenze Consorziate</h3>';
            const table = document.createElement('table');
            table.className = 'w-full mb-6 text-sm border';
            table.innerHTML = `
            <thead class="bg-slate-100">
                <tr><th class="p-2">Consorziata</th><th>N. Persone</th><th>Costo</th><th>Pasti</th><th>Auto</th><th>Hotel</th><th>Note</th></tr>
            </thead>
            <tbody></tbody>`;
            const tbody = table.querySelector('tbody');

            rowsCons.forEach(row => {
                const nome = row.querySelector('.tomselect-company')?.selectedOptions[0]?.textContent || '-';
                const numero = row.querySelector('[name*="[numero]"]')?.value || '-';
                const costo = row.querySelector('[name*="[costo]"]')?.value || '-';
                const pasti = row.querySelector('[name*="[pasti]"]')?.value || '-';
                const auto = row.querySelector('[name*="[auto]"]')?.value || '-';
                const hotel = row.querySelector('[name*="[hotel]"]')?.value || '-';
                const note = row.querySelector('[name*="[note]"]')?.value || '-';

                tbody.innerHTML += `<tr class="border-t"><td class="p-1">${nome}</td><td>${numero}</td><td>${costo}</td><td>${pasti}</td><td>${auto}</td><td>${hotel}</td><td>${note}</td></tr>`;
            });

            summary.appendChild(table);
        }

        // Apri il modal
        tailwind.Modal.getOrCreateInstance(document.getElementById('riepilogo-modal')).show();
    });

        document.getElementById('confirm-save').addEventListener('click', async () => {
            const form = document.getElementById('attendanceForm');
            const fd   = new FormData(form);
            const btn  = document.getElementById('confirm-save');
            btn.disabled = true;

            // first, clear any old errors
            document.querySelectorAll('#attendanceRows tr').forEach(tr => {
                tr.classList.remove('error-row');
                const old = tr.querySelector('.error-msg');
                if (old) old.remove();
            });

            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    body: fd,
                    headers: { 'Accept': 'application/json' }
                });

                if (res.ok) {
                    const json = await res.json();
                    if (json.success) {
                        alert('Presenze salvate con successo!');
                        location.reload();
                    } else {
                        // non‐validation errors
                        alert(json.message);
                    }
                } else if (res.status === 422) {
                    // validation errors: JSON { errors: [ { index: 0, msg: "..." }, ... ] }
                    const { errors } = await res.json();
                    errors.forEach(({ index, msg }) => {
                        const row = document.querySelectorAll('#attendanceRows tr')[index];
                        if (!row) return;
                        row.classList.add('error-row');
                        const td = document.createElement('td');
                        td.className = 'error-msg';
                        td.colSpan = row.children.length; // or just append in the last cell
                        td.textContent = msg;
                        row.appendChild(td);
                    });
                    // scroll to first error
                    const firstError = document.querySelector('.error-row');
                    firstError?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    alert('Si è verificato un errore: ' + res.statusText);
                }
            } catch (err) {
                console.error(err);
                alert('Si è verificato un errore di rete.');
            } finally {
                btn.disabled = false;
            }
        });

        window.addEventListener("load", () => {
            document.querySelectorAll('select.tomselect-worker, select.tomselect-company').forEach(select => {
                const value = select.getAttribute('data-initial-value');
                const label = select.getAttribute('data-initial-name');

                if (value && label && !select.querySelector(`option[value="${value}"]`)) {
                    const opt = new Option(label, value, true, true);
                    select.appendChild(opt);
                }

                const isWorker = select.classList.contains('tomselect-worker');
                new TomSelect(select, {
                    create: false,
                    valueField: 'value',
                    labelField: 'text',
                    searchField: 'text',
                    placeholder: isWorker ? 'Seleziona Operaio' : 'Seleziona consorziata',
                    preload: true,
                    load: function(query, callback) {
                        if (query.length < 2) return callback();
                        const url = isWorker
                            ? '/api/attendance/workers?context=attendance&q=' + encodeURIComponent(query)
                            : '/api/attendance/companies?context=attendance&q=' + encodeURIComponent(query);
                        fetch(url)
                            .then(res => res.json())
                            .then(callback)
                            .catch(() => callback());
                    }
                });
            });
        });
