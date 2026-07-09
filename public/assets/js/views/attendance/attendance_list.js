
    // ===============================
    //  Inizializzazione TomSelect
    //  Versione corretta + sicura
    //  Evita doppie inizializzazioni
    // ===============================


    // --- OPERAIO MODAL ---
    (function() {
        const el = document.querySelector("#operaioSelect");
        if (el && !el.tomselect) {
            new TomSelect(el, {
                valueField: "value",
                labelField: "text",
                searchField: "text",
                load: function(query, callback) {
                    if (query.length < 2) return callback();
                    fetch(`/api/attendance/workers?q=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(callback)
                        .catch(() => callback());
                }
            });
        }
    })();


    // --- AZIENDA MODAL ---
    (function() {
        const el = document.querySelector("#aziendaSelect");
        if (el && !el.tomselect) {
            new TomSelect(el, {
                valueField: "value",
                labelField: "text",
                searchField: "text",
                load: function(query, callback) {
                    if (query.length < 2) return callback();
                    fetch(`/api/attendance/companies?q=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(callback)
                        .catch(() => callback());
                }
            });
        }
    })();


    // --- COMMITTENTE MODAL ---
    (function() {
        const el = document.querySelector("#committenteSelect");
        if (el && !el.tomselect) {
            new TomSelect(el, {
                valueField: "value",
                labelField: "text",
                searchField: "text",
                load: function(query, callback) {
                    if (query.length < 2) return callback();
                    fetch(`/api/attendance/clients?q=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(callback)
                        .catch(() => callback());
                }
            });
        }
    })();


    // --- CANTIERE (tab presenze nostri) ---
    (function() {
        const el = document.querySelector("#cantiereSelect");
        if (el && !el.tomselect) {
            new TomSelect(el, {
                create: false,
                preload: false,
                openOnFocus: false,
                valueField: "value",
                labelField: "text",
                searchField: "text",
                shouldLoad: query => query.length >= 3,

                load: function(query, callback) {
                    fetch(`/api/attendance/worksites?q=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(callback)
                        .catch(() => callback());
                }
            });
        }
    })();


    // --- OPERAIO (tab presenze nostri) ---
    (function() {
        const el = document.querySelector("#workerSelect");
        if (el && !el.tomselect) {
            new TomSelect(el, {
                create: false,
                preload: false,
                openOnFocus: false,
                valueField: "value",
                labelField: "text",
                searchField: "text",
                shouldLoad: query => query.length >= 2,

                load: function(query, callback) {
                    fetch(`/api/attendance/workers?q=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(callback)
                        .catch(() => callback());
                }
            });
        }
    })();


    // --- CONS CANTIERE (tab consorziate) ---
    (function() {
        const el = document.querySelector("#consCantiereSelect");
        if (el && !el.tomselect) {
            new TomSelect(el, {
                create: false,
                preload: false,
                openOnFocus: false,
                valueField: "value",
                labelField: "text",
                searchField: "text",
                shouldLoad: query => query.length >= 3,

                load: function(query, callback) {
                    fetch(`/api/attendance/worksites?q=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(callback)
                        .catch(() => callback());
                }
            });
        }
    })();


    // --- CONSORZIATA (tab consorziate): opzioni statiche dal server ---
    (function() {
        const el = document.querySelector("#consAziendaSelect");
        if (el && !el.tomselect) {
            new TomSelect(el, {
                create: false,
                maxItems: 1,
                allowEmptyOption: true,
                placeholder: "Cerca consorziata...",
                sortField: { field: "text", direction: "asc" }
            });
        }
    })();


    // --- Preset rapidi date: compilano il range del PROPRIO form e filtrano ---
    (function() {
        const pad = n => String(n).padStart(2, '0');
        const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());

        function range(preset) {
            const now = new Date();
            let from, to;
            switch (preset) {
                case 'oggi':
                    from = to = now;
                    break;
                case 'settimana': { // lunedi -> domenica corrente
                    const dow = (now.getDay() + 6) % 7; // 0 = lunedi
                    from = new Date(now); from.setDate(now.getDate() - dow);
                    to   = new Date(from); to.setDate(from.getDate() + 6);
                    break;
                }
                case 'mese':
                    from = new Date(now.getFullYear(), now.getMonth(), 1);
                    to   = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                    break;
                case 'mese-scorso':
                    from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                    to   = new Date(now.getFullYear(), now.getMonth(), 0);
                    break;
                default:
                    return null;
            }
            return [iso(from), iso(to)];
        }

        document.querySelectorAll('.fe-preset').forEach(btn => {
            btn.addEventListener('click', function () {
                const r = range(this.dataset.preset);
                if (!r) return;
                const form  = this.closest('form');
                const start = form.querySelector('[data-range-start]');
                const end   = form.querySelector('[data-range-end]');
                if (start) start.value = r[0];
                if (end)   end.value   = r[1];
                form.submit();
            });
        });
    })();


    // JS AGGIORNATO
    document.querySelectorAll('.nav-tabs .nav-link').forEach(tabBtn => {
        tabBtn.addEventListener('click', function () {
            // reset
            document.querySelectorAll('.nav-tabs .nav-link').forEach(el => {
                el.setAttribute('aria-selected', 'false');
            });

            // attiva il cliccato
            this.setAttribute('aria-selected', 'true');

            // nascondi / mostra pane
            const targetId = this.getAttribute('data-tw-target');
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.add('hidden'));
            document.querySelector(targetId).classList.remove('hidden');
        });
    });
