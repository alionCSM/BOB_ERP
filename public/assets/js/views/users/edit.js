    const photoInput = document.getElementById("profile-photo");
    if (photoInput) {
        photoInput.addEventListener("change", function (event) {
            const file = event.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = () => {
                document.getElementById("profile-preview").src = reader.result;
            };
            reader.readAsDataURL(file);
        });
    }


    document.addEventListener("DOMContentLoaded", function() {
        const urlParams = new URLSearchParams(window.location.search);
        const tabFromUrl = urlParams.get("tab");

        let activeTab = tabFromUrl || localStorage.getItem("activeTab") || "personal-info";

        document.querySelectorAll(".tab-content").forEach(c => c.classList.add("hidden"));
        document.querySelectorAll(".tab-link").forEach(t => { t.classList.remove("active-tab"); t.classList.remove("active"); });

        const activeContent = document.getElementById(activeTab);
        const activeLink = document.querySelector(`.tab-link[data-tab="${activeTab}"]`);

        if (activeContent && activeLink) {
            activeContent.classList.remove("hidden");
            activeLink.classList.add("active-tab");
        } else {
            localStorage.removeItem("activeTab");
            document.getElementById("personal-info").classList.remove("hidden");
            document.querySelector('.tab-link[data-tab="personal-info"]').classList.add("active-tab");
        }

        document.querySelectorAll(".tab-link").forEach(tab => {
            tab.addEventListener("click", function(event) {
                event.preventDefault();
                document.querySelectorAll(".tab-content").forEach(c => c.classList.add("hidden"));
                document.querySelectorAll(".tab-link").forEach(t => { t.classList.remove("active-tab"); t.classList.remove("active"); });
                let selectedTab = this.dataset.tab;
                const content = document.getElementById(selectedTab);
                if (!content) return;

                content.classList.remove("hidden");
                this.classList.add("active-tab");
                localStorage.setItem("activeTab", selectedTab);
                const url = new URL(window.location);
                url.searchParams.set("tab", selectedTab);
                window.history.replaceState({}, '', url);
            });
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        const cfInput = document.querySelector('input[name="fiscal_code"]');
        if (cfInput) {
            cfInput.addEventListener('input', function () {
                this.value = this.value.toUpperCase();
            });
        }
    });

    /** Il nome del campo come lo legge chi compila, non quello del database. */
    function etichetta(campo) {
        const l = campo.closest('.ue-field')?.querySelector('label');
        return (l?.textContent || campo.name || 'campo').trim().replace(/\s*\*$/, '');
    }

    function segna(campo, rotto) {
        campo.classList.toggle('border-red-500', rotto);
    }

    /**
     * Il telefono: si contano le CIFRE, non i caratteri.
     *
     * Prima la regola era /^[0-9\s\+\-\(\)]{7,15}$/, cioe' da 7 a 15
     * caratteri spazi compresi. "+39 333 123 4567" — il formato che BOB
     * stesso suggerisce nel segnaposto — sono 16 caratteri e veniva
     * rifiutato, mentre "3331234567" passava: due persone scrivevano lo
     * stesso numero in due modi e a una sola si apriva. E il messaggio non
     * diceva quale campo fosse, quindi non c'era modo di capirlo.
     *
     * Adesso gli spazi, i trattini e le parentesi non contano piu' niente:
     * contano le cifre, da 6 a 15 (quindici e' il massimo di un numero di
     * telefono al mondo, E.164), col prefisso + se c'e'.
     */
    function telefonoValido(valore) {
        const v = valore.trim();
        if (!/^\+?[0-9\s\-().]*$/.test(v)) {
            return 'puo\' contenere solo cifre, spazi, + - ( ) e punti';
        }
        const cifre = v.replace(/\D/g, '').length;
        if (cifre < 6)  return 'e\' troppo corto: servono almeno 6 cifre';
        if (cifre > 15) return 'e\' troppo lungo: al massimo 15 cifre';
        return null;
    }

    /**
     * Cosa non va, campo per campo.
     *
     * Torna un elenco di frasi gia' scritte per chi compila: prima si
     * diceva solo "completa tutti i campi obbligatori" e il campo colpevole
     * lo si doveva indovinare — su una scheda operaio con dodici campi, e
     * col codice fiscale che puo' arrivare vuoto dal database, era un
     * indovinello senza soluzione.
     *
     * @return {{problemi: string[], primo: HTMLElement|null}}
     */
    function controlla(form) {
        const problemi = [];
        let primo = null;

        const guasto = (campo, frase) => {
            segna(campo, true);
            problemi.push(frase);
            if (!primo) primo = campo;
        };

        form.querySelectorAll('[required]').forEach(function (campo) {
            if (!campo.value.trim()) {
                guasto(campo, etichetta(campo) + ': manca');
            } else {
                segna(campo, false);
            }
        });

        const email = form.querySelector('input[type="email"]');
        if (email && email.value.trim() && !validateEmail(email.value)) {
            guasto(email, etichetta(email) + ': indirizzo non valido');
        } else if (email) {
            segna(email, false);
        }

        const cf = form.querySelector('input[name="fiscal_code"]');
        if (cf && cf.value.trim() && cf.value.trim().length !== 16) {
            guasto(cf, etichetta(cf) + ': deve essere di 16 caratteri (adesso sono '
                     + cf.value.trim().length + ')');
        } else if (cf && cf.value.trim()) {
            segna(cf, false);
        }

        const tel = form.querySelector('input[name="phone"]');
        if (tel && tel.value.trim()) {
            const perche = telefonoValido(tel.value);
            if (perche) {
                guasto(tel, etichetta(tel) + ': ' + perche);
            } else {
                segna(tel, false);
            }
        } else if (tel) {
            segna(tel, false);
        }

        return { problemi, primo };
    }

    document.querySelectorAll('form.needs-validation').forEach(form => {
        form.addEventListener('submit', function (event) {
            const { problemi, primo } = controlla(form);
            if (!problemi.length) {
                return;
            }
            event.preventDefault();

            // Il campo colpevole si porta sotto gli occhi: su questa pagina
            // sta spesso fuori schermo, e un bordo rosso che non si vede
            // non dice niente a nessuno.
            if (primo) {
                primo.scrollIntoView({ behavior: 'smooth', block: 'center' });
                primo.focus({ preventScroll: true });
            }

            alert(problemi.length === 1
                ? problemi[0]
                : 'Ci sono ' + problemi.length + ' campi da sistemare:\n\n• ' + problemi.join('\n• '));
        });
    });

    // La foto del profilo: il click si aggancia qui perche' nell'attributo
    // onclick la CSP non lo fa partire.
    const foto = document.querySelector('[data-apri-foto]');
    if (foto) {
        foto.addEventListener('click', function () {
            openImageModal(this.dataset.src, this.dataset.nome);
        });
    }

    function openImageModal(imageUrl, fullName) {
        const imgElement = document.getElementById('preview-image');
        const nameElement = document.getElementById('preview-worker-name');

        imgElement.src = imageUrl;
        nameElement.textContent = fullName;

        const modal = tailwind.Modal.getOrCreateInstance(
            document.querySelector('#image-preview-modal')
        );
        modal.show();
    }

    // ── Tab Presenze: lista via JSON con ricerca/filtri/paginazione ─────────
    (function () {
        var app = document.getElementById('presenze-app');
        if (!app) return;

        var workerId = app.dataset.worker;
        var uid      = app.dataset.uid;
        var body     = document.getElementById('pz-body');
        var summary  = document.getElementById('pz-summary');
        var pageLbl  = document.getElementById('pz-page');
        var prevBtn  = document.getElementById('pz-prev');
        var nextBtn  = document.getElementById('pz-next');
        var search   = document.getElementById('pz-search');
        var yearSel  = document.getElementById('pz-year');
        var monthSel = document.getElementById('pz-month');

        var page = 1, pages = 1, loaded = false, debounce = null;

        function esc(s) {
            var d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }

        function fmtDate(iso) {
            if (!iso) return '';
            var p = String(iso).slice(0, 10).split('-');
            return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
        }

        function load() {
            body.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:28px; color:#94a3b8;">Caricamento…</td></tr>';
            var params = new URLSearchParams({
                uid: uid, page: page,
                q: search.value.trim(),
                year: yearSel.value, month: monthSel.value
            });
            fetch('/users/' + workerId + '/presenze-data?' + params)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) throw new Error(data.error || 'errore');
                    pages = data.pages;
                    page  = data.page;

                    summary.textContent = data.total + ' presenze · ' +
                        (data.giornate || 0).toLocaleString('it-IT', { maximumFractionDigits: 1 }) +
                        ' giornate equivalenti (filtri attuali)';
                    pageLbl.textContent = 'Pagina ' + page + ' di ' + pages;
                    prevBtn.style.visibility = page > 1     ? 'visible' : 'hidden';
                    nextBtn.style.visibility = page < pages ? 'visible' : 'hidden';

                    if (!data.rows.length) {
                        body.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:28px; color:#94a3b8;">Nessuna presenza con questi filtri.</td></tr>';
                        return;
                    }

                    body.innerHTML = data.rows.map(function (p) {
                        var cantiere = p.worksite_id
                            ? '<a href="/worksites/' + p.worksite_id + '" style="color:#2563eb; text-decoration:none;">' +
                              esc((p.worksite_code ? p.worksite_code + ' — ' : '') + (p.worksite_name || '')) + '</a>'
                            : '—';
                        var turnoStyle = p.turno === 'Intero'
                            ? 'background:#dcfce7; color:#15803d;'
                            : 'background:#fef3c7; color:#b45309;';
                        return '<tr>' +
                            '<td style="white-space:nowrap; font-weight:600;">' + fmtDate(p.data) + '</td>' +
                            '<td>' + cantiere + '</td>' +
                            '<td><span style="display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:700; ' + turnoStyle + '">' + esc(p.turno) + '</span></td>' +
                            '<td>' + esc(p.pranzo || '—') + '</td>' +
                            '<td>' + esc(p.cena || '—') + '</td>' +
                            '<td>' + esc(p.hotel && p.hotel !== '-' ? p.hotel : '—') + '</td>' +
                            '<td style="font-size:12px; color:#64748b;">' + esc(p.note || '') + '</td>' +
                            '</tr>';
                    }).join('');
                })
                .catch(function () {
                    body.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:28px; color:#dc2626;">Errore di caricamento. Riprova.</td></tr>';
                });
        }

        function reset() { page = 1; load(); }

        prevBtn.addEventListener('click', function () { if (page > 1) { page--; load(); } });
        nextBtn.addEventListener('click', function () { if (page < pages) { page++; load(); } });
        yearSel.addEventListener('change', reset);
        monthSel.addEventListener('change', reset);
        search.addEventListener('input', function () {
            clearTimeout(debounce);
            debounce = setTimeout(reset, 350);
        });

        // lazy: carica solo alla prima apertura del tab
        document.querySelectorAll('.tab-link[data-tab="presenze"]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                if (!loaded) { loaded = true; load(); }
            });
        });
    })();

    // Cambia Azienda: solo aziende a listino, ricercabili (niente testo libero)
    if (document.getElementById('company-change-select')) {
        new TomSelect('#company-change-select', {
            create: false,
            maxItems: 1,
            placeholder: 'Cerca azienda...',
            sortField: { field: 'text', direction: 'asc' }
        });
    }

    if (document.getElementById('worker-search')) {
        new TomSelect('#worker-search', {
            valueField: 'id',
            labelField: 'text',
            searchField: 'text',
            maxItems: 1,
            loadThrottle: 300,
            load: function(query, callback) {
                if (query.length < 2) return callback();
                fetch('/users/search-workers?q=' + encodeURIComponent(query))
                    .then(r => r.json())
                    .then(callback)
                    .catch(() => callback());
            },
            onChange(value) {
                if (value) {
                    const option = this.options[value];
                    const uid = option ? (option.uid || '') : '';
                    const url = new URL('/users/' + value + '/edit', window.location.origin);
                    if (uid) url.searchParams.set('uid', uid);
                    window.location.href = url.toString();
                }
            }
        });
    }
