
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
