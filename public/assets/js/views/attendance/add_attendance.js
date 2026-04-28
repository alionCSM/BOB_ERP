    const _form               = document.getElementById('attendanceForm');
    const initialCantiereOption = JSON.parse(_form?.dataset.cantiereOption || 'null');
    const disableCantiereSelect = _form?.dataset.disableCantiere === '1';
    const duplicateData         = JSON.parse(_form?.dataset.duplicate || 'null');

    document.addEventListener("DOMContentLoaded", () => {

        function toggleOverwriteBtn(show = false){
            document.getElementById('confirm-save-overwrite').style.display =
                show ? 'inline-block' : 'none';
        }


        const addRowBtn = document.getElementById("addRow");
        const copyToggle = document.getElementById("copyToggle");
        const tbody = document.getElementById("attendanceRows");
        const addConsBtn = document.getElementById("addConsorziataRow");
        const consTable = document.getElementById("consorziataRows");
        const copyConsToggle = document.getElementById("copyConsToggle");

        const tabNostri = document.getElementById("tab-nostri");
        const tabCons = document.getElementById("tab-consorziata");
        const secNostri = document.getElementById("section-nostri");
        const secCons = document.getElementById("section-consorziata");

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

        const selectedValue = "<?php echo $cantiere_id ?? ''; ?>";

        new TomSelect("#cantiere", {
            create: false,
            valueField: 'value',
            labelField: 'text',
            searchField: 'text',
            placeholder: "Seleziona cantiere",
            preload: true,
            load: function(query, callback) {
                let url = '/api/attendance/worksites?context=attendance';
                if (query.length >= 3) {
                    url += '&q=' + encodeURIComponent(query);
                }
                fetch(url)
                    .then(response => response.json())
                    .then(callback)
                    .catch(() => callback());
            },
            ...(initialCantiereOption ? {
                items: [initialCantiereOption.value],
                options: [initialCantiereOption]
            } : {})
        });

        if (disableCantiereSelect) {
            document.querySelector('#cantiere').tomselect.disable();
        }




        function createRow() {
            const row = document.createElement("tr");
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
                <input type="number" step="0.01" name="pranzo_prezzo[]" class="form-control hidden w-24" placeholder="€" />
            </div>
        </td>
        <td class="cena-cell">
            <div class="flex items-center gap-2">
                <select name="cena[]" class="form-select cena-select">
                    <option value="-">No Cena</option>
                    <option value="Loro">Loro</option>
                    <option value="Noi">Noi</option>
                </select>
                <input type="number" step="0.01" name="cena_prezzo[]" class="form-control hidden w-24" placeholder="€" />
            </div>
        </td>
        <td><input type="text" name="hotel[]" class="form-control"></td>
        <td><input type="text" name="auto[]" class="form-control"></td>
        <td><input type="text" name="note[]" class="form-control"></td>
        <td><button type="button" class="text-red-600 font-bold remove-row">✖</button></td>
    `;

            tbody.appendChild(row);

            // === TomSelect per lavoratore ===
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
                        .then(callback)
                        .catch(() => callback());
                }
            });

            // === Mostra/Nascondi prezzo pranzo ===
            const pranzoSelect = row.querySelector(".pranzo-select");
            const pranzoPrezzo = row.querySelector('input[name="pranzo_prezzo[]"]');
            pranzoSelect.addEventListener("change", () => {
                if (pranzoSelect.value === "Noi") {
                    pranzoPrezzo.classList.remove("hidden");
                } else {
                    pranzoPrezzo.classList.add("hidden");
                    pranzoPrezzo.value = "";
                }
            });

            // === Mostra/Nascondi prezzo cena ===
            const cenaSelect = row.querySelector(".cena-select");
            const cenaPrezzo = row.querySelector('input[name="cena_prezzo[]"]');
            cenaSelect.addEventListener("change", () => {
                if (cenaSelect.value === "Noi") {
                    cenaPrezzo.classList.remove("hidden");
                } else {
                    cenaPrezzo.classList.add("hidden");
                    cenaPrezzo.value = "";
                }
            });
        }


        let consIndex = 0;

        function createConsorziataRow() {
            const row = document.createElement("tr");
            row.innerHTML = `
        <td><select name="consorziate[${consIndex}][nome]" class="form-control tomselect-company" required></select></td>
        <td><input name="consorziate[${consIndex}][numero]" type="number" class="form-control" step="0.5" min="0" required></td>
        <td><input name="consorziate[${consIndex}][costo]" type="number" class="form-control" step="0.01" required></td>
        <td><input name="consorziate[${consIndex}][pasti]" type="number" class="form-control"></td>
        <td><input name="consorziate[${consIndex}][auto]" type="text" class="form-control"></td>
        <td><input name="consorziate[${consIndex}][hotel]" type="text" class="form-control"></td>
        <td><input name="consorziate[${consIndex}][note]" type="text" class="form-control"></td>
        <td><button type="button" class="text-red-600 font-bold remove-row">✖</button></td>
    `;
            consTable.appendChild(row);

            new TomSelect(row.querySelector(".tomselect-company"), {
                create: false,
                valueField: 'value',
                labelField: 'text',
                searchField: 'text',
                placeholder: 'Seleziona consorziata',
                load: function(query, callback) {
                    if (query.length < 2) return callback();
                    fetch('/api/attendance/companies?context=attendance&q=' + encodeURIComponent(query))
                        .then(res => res.json())
                        .then(callback).catch(() => callback());
                }
            });

            consIndex++;
        }

        addRowBtn.addEventListener("click", createRow);
        addConsBtn.addEventListener("click", createConsorziataRow);

        document.addEventListener("click", function (e) {
            if (e.target.classList.contains("remove-row")) {
                e.target.closest("tr").remove();
            }
        });

        copyToggle.addEventListener("change", function () {
            const rows = document.querySelectorAll("#attendanceRows tr");
            if (rows.length < 2) return;

            const first = rows[0];
            const vals = {
                turno: first.querySelector('select[name="turno[]"]').value,
                pranzo: first.querySelector('select[name="pranzo[]"]').value,
                pranzoPrezzo: first.querySelector('input[name="pranzo_prezzo[]"]').value,
                cena: first.querySelector('select[name="cena[]"]').value,
                cenaPrezzo: first.querySelector('input[name="cena_prezzo[]"]').value,
                hotel: first.querySelector('input[name="hotel[]"]').value,
                note: first.querySelector('input[name="note[]"]').value
            };

            if (this.checked) {
                rows.forEach((row, idx) => {
                    if (idx === 0) return;

                    // Copy standard values
                    row.querySelector('select[name="turno[]"]').value = vals.turno;
                    row.querySelector('select[name="pranzo[]"]').value = vals.pranzo;
                    row.querySelector('select[name="cena[]"]').value = vals.cena;
                    row.querySelector('input[name="hotel[]"]').value = vals.hotel;
                    row.querySelector('input[name="note[]"]').value = vals.note;

                    // Copy and toggle Pranzo Prezzo
                    const pranzoPrezzoInput = row.querySelector('input[name="pranzo_prezzo[]"]');
                    if (vals.pranzo === "Noi") {
                        pranzoPrezzoInput.classList.remove("hidden");
                        pranzoPrezzoInput.value = vals.pranzoPrezzo;
                    } else {
                        pranzoPrezzoInput.classList.add("hidden");
                        pranzoPrezzoInput.value = "";
                    }

                    // Copy and toggle Cena Prezzo
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



        copyConsToggle.addEventListener("change", function () {
            if (!this.checked) return;
            const rows = document.querySelectorAll("#consorziataRows tr");
            if (rows.length < 2) return;
            const first = rows[0];
            const getVal = (selector) => first.querySelector(selector)?.value || "";
            for (let i = 1; i < rows.length; i++) {
                rows[i].querySelector('input[name*="[pasti]"]').value = getVal('input[name*="[pasti]"]');
                rows[i].querySelector('input[name*="[hotel]"]').value = getVal('input[name*="[hotel]"]');
                rows[i].querySelector('input[name*="[costo]"]').value = getVal('input[name*="[costo]"]');
                rows[i].querySelector('input[name*="[numero]"]').value = getVal('input[name*="[numero]"]');
                rows[i].querySelector('input[name*="[note]"]').value = getVal('input[name*="[note]"]');
            }
        });




        document.getElementById("tab-nostri").addEventListener("click", () => {
            document.getElementById("section-nostri").classList.remove("hidden");
            document.getElementById("section-consorziata").classList.add("hidden");
            document.getElementById("tab-nostri").classList.add("border-blue-500", "text-blue-600");
            document.getElementById("tab-consorziata").classList.remove("border-blue-500", "text-blue-600");
        });

        document.getElementById("tab-consorziata").addEventListener("click", () => {
            document.getElementById("section-consorziata").classList.remove("hidden");
            document.getElementById("section-nostri").classList.add("hidden");
            document.getElementById("tab-consorziata").classList.add("border-blue-500", "text-blue-600");
            document.getElementById("tab-nostri").classList.remove("border-blue-500", "text-blue-600");
        });

// Aggiungi una riga solo quando clicchi su tab "Nostri"
        tabNostri.addEventListener("click", () => {
            secNostri.classList.remove("hidden");
            secCons.classList.add("hidden");
            tabNostri.classList.add("tab-active");
            tabCons.classList.remove("tab-active");

            if (tbody.children.length === 0) {
                createRow(); // <-- crea riga solo se non ce n'è già
            }
        });        document.getElementById("attendanceForm").addEventListener("submit", function (e) {
            e.preventDefault();

            const saveMode = document.activeElement.value;
            const form = this;

            // Costruisci riepilogo da inputs visibili
            const data = [];

            if (saveMode === 'both' || document.getElementById("section-nostri").offsetParent !== null) {
                document.querySelectorAll("#attendanceRows tr").forEach(row => {
                    const cells = Array.from(row.querySelectorAll("select, input")).map(i => i.value).filter(Boolean);
                    if (cells.length) data.push("👷‍♂️ " + cells.join(" | "));
                });
            }

            if (saveMode === 'both' || document.getElementById("section-consorziata").offsetParent !== null) {
                document.querySelectorAll("#consorziataRows tr").forEach(row => {
                    const cells = Array.from(row.querySelectorAll("select, input")).map(i => i.value).filter(Boolean);
                    if (cells.length) data.push("🏢 " + cells.join(" | "));
                });
            }

            if (!data.length) {
                alert("Nessun dato da salvare.");
                return;
            }

            // Mostra il modal
            window.TailwindModal?.show?.("#riepilogo-modal") ||
            document.querySelector("#riepilogo-modal").classList.add("show", "flex");
        });


        function buildRiepilogoTable(errorList = []) {
            const cantiere = document.querySelector('#cantiere')?.tomselect?.getItem(document.querySelector('#cantiere')?.value)?.innerText || "—";
            const start = document.querySelector('input[name="start_date"]').value;
            const end = document.querySelector('input[name="end_date"]').value || start;
            const rows = [];

            const formattedStart = formatDateIT(start);
            const formattedEnd = formatDateIT(end);
            const totalDays = (() => {
                const d1 = new Date(start);
                const d2 = new Date(end || start);
                const diffTime = Math.abs(d2 - d1);
                return Math.floor(diffTime / (1000 * 60 * 60 * 24)) + 1;
            })();

            rows.push(`
            <div class="text-sm mb-2">
                <strong>Cantiere:</strong> ${cantiere}<br>
                <strong>Dal:</strong> ${formattedStart} <strong>al:</strong> ${formattedEnd}<br>
                <strong>Totale Giorni:</strong> ${totalDays}
            </div>
        `);

            const tableNostri = [];
            const headersNostri = [
                "Operaio",
                "Turno",
                "Pranzo",
                "Prezzo Pranzo",
                "Cena",
                "Prezzo Cena",
                "Hotel",
                "Auto",
                "Note",
                "Errore"
            ];
            tableNostri.push(`<table class="table w-full text-xs border mb-5"><thead><tr>${headersNostri.map(h => `<th>${h}</th>`).join("")}</tr></thead><tbody>`);

            document.querySelectorAll("#attendanceRows tr").forEach((row, i) => {
                const select = row.querySelector('select[name="worker_id[]"]');
                const name = select?.tomselect?.getItem(select.value)?.innerText || "—";
                const cells = [
                    name,
                    row.querySelector('select[name="turno[]"]')?.value || "",
                    row.querySelector('select[name="pranzo[]"]')?.value || "",
                    row.querySelector('input[name="pranzo_prezzo[]"]')?.value || "",
                    row.querySelector('select[name="cena[]"]')?.value || "",
                    row.querySelector('input[name="cena_prezzo[]"]')?.value || "",
                    row.querySelector('select[name="hotel[]"]')?.value || "",
                    row.querySelector('input[name="auto[]"]')?.value || "",
                    row.querySelector('input[name="note[]"]')?.value || ""
                ];


                const errorObj = errorList.find(e => e.index === i);
                const errorText = errorObj ? `<span class='text-red-600'>${errorObj.msg}</span>` : "";
                const errorClass = errorObj ? "bg-red-100" : "";

                tableNostri.push(`<tr class="${errorClass}">${cells.map(c => `<td>${c}</td>`).join("")}<td>${errorText}</td></tr>`);
            });

            tableNostri.push("</tbody></table>");
            if (tableNostri.length > 2) rows.push("<h3 class='text-base font-medium mb-1'>👷‍♂️ Operai</h3>" + tableNostri.join(""));

            const tableCons = [];
            const headersCons = ["Consorziata", "Numero", "Costo", "Pasti", "Auto", "Hotel", "Note"];
            tableCons.push(`<table class="table w-full text-xs border"><thead><tr>${headersCons.map(h => `<th>${h}</th>`).join("")}</tr></thead><tbody>`);

            document.querySelectorAll("#consorziataRows tr").forEach(row => {
                const select = row.querySelector('select[name^="consorziate"][name*="[nome]"]');
                const nome = select?.tomselect?.getItem(select.value)?.innerText || "—";
                const cells = [
                    nome,
                    row.querySelector('input[name*="[numero]"]')?.value || "",
                    row.querySelector('input[name*="[costo]"]')?.value || "",
                    row.querySelector('input[name*="[pasti]"]')?.value || "",
                    row.querySelector('input[name*="[auto]"]')?.value || "",
                    row.querySelector('input[name*="[hotel]"]')?.value || "",
                    row.querySelector('input[name*="[note]"]')?.value || ""
                ];
                tableCons.push(`<tr>${cells.map(c => `<td>${c}</td>`).join("")}</tr>`);
            });
            tableCons.push("</tbody></table>");
            if (tableCons.length > 2) rows.push("<h3 class='text-base font-medium mb-1 mt-3'>🏢 Consorziate</h3>" + tableCons.join(""));

            return rows.join("");
        }

        function formatDateIT(dateStr) {
            if (!dateStr) return "";
            const [yyyy, mm, dd] = dateStr.split("-");
            return `${dd}/${mm}/${yyyy}`;
        }


        function inviaPresenze({ overwrite = false } = {}) {
            const form = document.getElementById("attendanceForm");
            const formData = new FormData(form);

            if (overwrite) {
                formData.append("overwrite", "1");
            }

            const hasNostri = document.querySelectorAll('#attendanceRows tr select[name="worker_id[]"]').length > 0 &&
                [...document.querySelectorAll('#attendanceRows select[name="worker_id[]"]')].some(el => el.value);

            const hasConsorziate = document.querySelectorAll('#consorziataRows tr select[name^="consorziate"][name*="[nome]"]').length > 0 &&
                [...document.querySelectorAll('#consorziataRows select[name^="consorziate"][name*="[nome]"]')].some(el => el.value);

            const saveMode = (hasNostri && hasConsorziate) ? "both" : hasNostri ? "nostri" : hasConsorziate ? "consorziate" : "single";
            formData.append("save_mode", saveMode);




            fetch(form.action, {
                method: "POST",
                body: formData
            })
                .then(async res => {
                    const txt = await res.text();
                    try {
                        const json = JSON.parse(txt);
                        if (!json.success || (json.errors && json.errors.length)) {
                            toggleOverwriteBtn(true);
                            document.getElementById("riepilogo-modal-content").innerHTML =
                                `<div class="text-danger text-sm font-medium mb-3">⚠️ ${json.message}</div>` +
                                buildRiepilogoTable(json.errors || []);
                            window.TailwindModal?.show?.("#riepilogo-modal") ||
                            document.querySelector("#riepilogo-modal").classList.add("show", "flex");
                            return;
                        }
                        toggleOverwriteBtn(false);
                        location.reload();
                    } catch {
                        toggleOverwriteBtn(false);
                        document.getElementById("riepilogo-modal-content").innerHTML =
                            `<div class="text-danger text-sm font-medium mb-3">⚠️ Errore imprevisto</div>
                 <pre class="text-xs text-red-500">${txt}</pre>`;
                        window.TailwindModal?.show?.("#riepilogo-modal") ||
                        document.querySelector("#riepilogo-modal").classList.add("show", "flex");
                    }
                })
                .catch(() => alert("Errore di comunicazione col server."));
        }



        document.getElementById("confirm-save")
            .addEventListener("click", () => inviaPresenze());

        document.getElementById("confirm-save-overwrite")
            .addEventListener("click", () => inviaPresenze({ overwrite: true }));

        document.getElementById("preview-button").addEventListener("click", function () {
            const content = buildRiepilogoTable();
            if (!content) {
                alert("Nessun dato da riepilogare.");
                return;
            }
            document.getElementById("riepilogo-modal-content").innerHTML = content;
            window.TailwindModal?.show?.("#riepilogo-modal") ||
            document.querySelector("#riepilogo-modal").classList.add("show", "flex");
        });



        document.getElementById("duplicate-last-day").addEventListener("click", function () {

            const cantiereSelect = document.querySelector('#cantiere');
            const cantiereId = cantiereSelect?.tomselect?.getValue();

            if (!cantiereId) {
                alert("Seleziona prima un cantiere.");
                return;
            }

            fetch('/api/attendance/last-day?cantiere_id=' + cantiereId)
                .then(res => res.json())
                .then(data => {

                    if (!data.success) {
                        alert(data.message || "Nessuna presenza trovata.");
                        return;
                    }

                    // Clear current rows
                    tbody.innerHTML = "";
                    consTable.innerHTML = "";

                    // Set date to TODAY
                    const today = new Date().toISOString().split("T")[0];
                    document.querySelector('input[name="start_date"]').value = today;
                    document.querySelector('input[name="end_date"]').value = today;

                    // === COPY NOSTRI ===
                    data.nostri.forEach(p => {

                        createRow();

                        const lastRow = document.querySelector("#attendanceRows tr:last-child");
                        const workerSelect = lastRow.querySelector('select[name="worker_id[]"]');

                        workerSelect.tomselect.addOption({
                            value: p.worker_id,
                            text: p.worker_name
                        });

                        workerSelect.tomselect.setValue(p.worker_id);

                        lastRow.querySelector('select[name="turno[]"]').value = p.turno;
                        lastRow.querySelector('select[name="pranzo[]"]').value = p.pranzo;
                        lastRow.querySelector('input[name="pranzo_prezzo[]"]').value = p.pranzo_prezzo || '';
                        lastRow.querySelector('select[name="cena[]"]').value = p.cena;
                        lastRow.querySelector('input[name="cena_prezzo[]"]').value = p.cena_prezzo || '';
                        lastRow.querySelector('input[name="hotel[]"]').value = p.hotel || '';
                        lastRow.querySelector('input[name="auto[]"]').value = p.targa_auto || '';
                        lastRow.querySelector('input[name="note[]"]').value = p.note || '';
                    });

                    // === COPY CONSORZIATE ===
                    data.consorziate.forEach(c => {

                        createConsorziataRow();

                        const lastRow = document.querySelector("#consorziataRows tr:last-child");
                        const select = lastRow.querySelector('select[name^="consorziate"]');

                        select.tomselect.addOption({
                            value: c.azienda_id,
                            text: c.company_name
                        });

                        select.tomselect.setValue(c.azienda_id);

                        lastRow.querySelector('input[name*="[numero]"]').value = parseFloat(c.quantita) || 0;
                        lastRow.querySelector('input[name*="[costo]"]').value = c.costo_unitario;
                        lastRow.querySelector('input[name*="[pasti]"]').value = c.pasti;
                        lastRow.querySelector('input[name*="[hotel]"]').value = c.hotel;
                        lastRow.querySelector('input[name*="[auto]"]').value = c.auto;
                        lastRow.querySelector('input[name*="[note]"]').value = c.note;
                    });

                })
                .catch(() => alert("Errore nel caricamento."));
        });

        if (duplicateData) {

    // Set today automatically
    const today = new Date().toISOString().split("T")[0];
    document.querySelector('input[name="start_date"]').value = today;
    document.querySelector('input[name="end_date"]').value = today;

    // === Nostri ===
    duplicateData.nostri.forEach(p => {

        createRow();

        const lastRow = document.querySelector("#attendanceRows tr:last-child");
        const workerSelect = lastRow.querySelector('select[name="worker_id[]"]');

        workerSelect.tomselect.addOption({
            value: p.worker_id,
            text: p.worker_name
        });

        workerSelect.tomselect.setValue(p.worker_id);

        lastRow.querySelector('select[name="turno[]"]').value = p.turno;
        lastRow.querySelector('select[name="pranzo[]"]').value = p.pranzo;
        lastRow.querySelector('input[name="pranzo_prezzo[]"]').value = p.pranzo_prezzo || '';
        lastRow.querySelector('select[name="cena[]"]').value = p.cena;
        lastRow.querySelector('input[name="cena_prezzo[]"]').value = p.cena_prezzo || '';
        lastRow.querySelector('input[name="hotel[]"]').value = p.hotel || '';
        lastRow.querySelector('input[name="auto[]"]').value = p.targa_auto || '';
        lastRow.querySelector('input[name="note[]"]').value = p.note || '';
    });

    // === Consorziate ===
    duplicateData.consorziate.forEach(c => {

        createConsorziataRow();

        const lastRow = document.querySelector("#consorziataRows tr:last-child");
        const select = lastRow.querySelector('select[name^="consorziate"]');

        select.tomselect.addOption({
            value: c.azienda_id,
            text: c.company_name
        });

        select.tomselect.setValue(c.azienda_id);

        lastRow.querySelector('input[name*="[numero]"]').value = parseFloat(c.quantita) || 0;
        lastRow.querySelector('input[name*="[costo]"]').value = c.costo_unitario;
        lastRow.querySelector('input[name*="[pasti]"]').value = c.pasti;
        lastRow.querySelector('input[name*="[hotel]"]').value = c.hotel;
        lastRow.querySelector('input[name*="[auto]"]').value = c.auto;
        lastRow.querySelector('input[name*="[note]"]').value = c.note;
    });
}

    });
