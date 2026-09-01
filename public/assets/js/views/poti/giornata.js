/*
 * Poti — la giornata dei tecnici.
 *
 * Quattro cose, tutte pensate per chi la usa in piedi in officina:
 *
 *  1. ricerca dal vivo su tutta la board (targa, cliente, paese, contratto);
 *  2. segnare uscita, rientro e firma SENZA ricaricare la pagina: prima ogni
 *     tocco faceva ripartire tutto da capo e si perdeva il punto in cui si
 *     era arrivati, che su una giornata piena e' scomodo;
 *  3. la scheda si blocca mentre la richiesta e' in volo, cosi' due tocchi
 *     rapidi non segnano-e-subito-annullano;
 *  4. il menu "···" di una scheda chiude quello di un'altra.
 *
 * Gli eventi sono legati tutti da qui e mai con onclick="" nell'HTML: la CSP
 * di BOB (script-src con nonce, senza 'unsafe-inline') blocca gli handler
 * scritti come attributo, e non partirebbero mai.
 *
 * Tutto e' un miglioramento, non un requisito: senza JavaScript i form
 * partono da soli, il server risponde con un redirect e la pagina funziona.
 */
(function () {
    'use strict';

    var corpo = document.getElementById('gi-corpo');
    var cerca = document.getElementById('gi-cerca');
    if (!corpo) return;

    // ── Ricerca dal vivo ────────────────────────────────────────────────────

    function applica() {
        var testo = (cerca && cerca.value || '').trim().toLowerCase();

        corpo.querySelectorAll('.gi-col').forEach(function (col) {
            var schede  = col.querySelectorAll('.gi-card');
            var visibili = 0;

            schede.forEach(function (card) {
                var ok = testo === '' || (card.dataset.giCerca || '').indexOf(testo) !== -1;
                card.hidden = !ok;
                if (ok) visibili++;
            });

            // "nessuna corrispondenza" solo se qualcosa c'era davvero: una
            // colonna gia' vuota ha il suo trattino e non va contraddetta
            var avviso = col.querySelector('.gi-col-cercato');
            if (avviso) {
                avviso.hidden = !(schede.length > 0 && visibili === 0);
            }

            // il contatore segue la ricerca, altrimenti direbbe 4 con una
            // scheda sola a schermo
            var n = col.querySelector('.gi-col-n');
            if (n) {
                if (n.dataset.giTotale === undefined) {
                    n.dataset.giTotale = n.textContent.trim();
                }
                n.textContent = testo === '' ? n.dataset.giTotale : visibili;
            }
        });
    }

    if (cerca) {
        cerca.addEventListener('input', applica);
        // Esc svuota: piu' rapido che cancellare a mano
        cerca.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { cerca.value = ''; applica(); }
        });
    }

    // ── Cambio giorno dal calendario ────────────────────────────────────────

    var campoData = document.querySelector('.gi-giorni input[type="date"]');
    if (campoData) {
        campoData.addEventListener('change', function () {
            if (this.form) this.form.submit();
        });
    }

    // ── Un menu "···" alla volta ────────────────────────────────────────────

    document.addEventListener('click', function (e) {
        var aperto = e.target.closest('.gi-altro');
        document.querySelectorAll('.gi-altro[open]').forEach(function (d) {
            if (d !== aperto) d.removeAttribute('open');
        });
    });

    // ── Segnare senza ricaricare ────────────────────────────────────────────

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.classList || !form.classList.contains('gi-form')) return;
        if (!window.fetch) return; // browser vecchio: invio normale

        e.preventDefault();

        var card = form.closest('.gi-card');
        if (card) card.classList.add('is-attesa');

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.ok ? aggiorna() : ricarica(); })
            .catch(ricarica);
    });

    // ── Foto di uscita e rientro ────────────────────────────────────────────
    // Due tempi: prima si scelgono e restano qui, si guarda cosa si e' preso
    // e si butta quello venuto male; poi si carica. Mandarle appena scelte
    // vorrebbe dire avere la foto sfocata sul server prima di accorgersene.
    //
    // Le foto in attesa vivono in questa mappa e non nel documento: la board
    // viene riscritta a ogni aggiornamento della giornata, e attaccate alle
    // schede sparirebbero al primo tocco portandosi via la scelta.
    var inAttesa = {};   // chiave "entita_momento" -> elenco di File

    function chiaveZona(zona) {
        return zona.dataset.giEntita + '_' + zona.dataset.giMomento;
    }

    /** Ridisegna le miniature in attesa e il pulsante di caricamento. */
    function disegnaAttesa(zona) {
        var chiave = chiaveZona(zona);
        var file = inAttesa[chiave] || [];
        var cassetto = zona.querySelector('[data-gi-attesa]');
        var bottone = zona.querySelector('[data-gi-carica]');
        if (!cassetto) return;

        // gli indirizzi temporanei si liberano prima di rifarli: ognuno tiene
        // in memoria la sua copia del file finche' non lo si revoca
        Array.prototype.forEach.call(cassetto.querySelectorAll('img'), function (img) {
            URL.revokeObjectURL(img.src);
        });
        cassetto.innerHTML = '';

        file.forEach(function (f, i) {
            var box = document.createElement('span');
            box.className = 'gi-foto-t is-attesa';

            var img = document.createElement('img');
            img.src = URL.createObjectURL(f);
            img.alt = f.name;
            box.appendChild(img);

            var eti = document.createElement('span');
            eti.className = 'gi-foto-nuova';
            eti.textContent = 'nuova';
            box.appendChild(eti);

            var x = document.createElement('button');
            x.type = 'button';
            x.className = 'gi-foto-x';
            x.textContent = '×';
            x.title = 'Togli questa foto';
            x.dataset.giScarta = String(i);
            box.appendChild(x);

            cassetto.appendChild(box);
        });

        if (bottone) {
            bottone.hidden = file.length === 0;
            bottone.textContent = file.length === 1
                ? 'Carica 1 foto'
                : 'Carica ' + file.length + ' foto';
        }
    }

    // scelta dei file: si mettono in attesa, non partono
    document.addEventListener('change', function (e) {
        var campo = e.target;
        if (!campo.dataset || campo.dataset.giScegli === undefined) return;

        var zona = campo.closest('[data-gi-foto-zona]');
        if (!zona || !campo.files || !campo.files.length) return;

        var chiave = chiaveZona(zona);
        inAttesa[chiave] = (inAttesa[chiave] || []).concat(
            Array.prototype.slice.call(campo.files)
        );
        campo.value = '';   // stesso file due volte di fila: deve ripartire
        disegnaAttesa(zona);
    });

    document.addEventListener('click', function (e) {
        var zona = e.target.closest ? e.target.closest('[data-gi-foto-zona]') : null;
        if (!zona) return;

        // togliere una foto dalla fila d'attesa
        var scarta = e.target.closest('[data-gi-scarta]');
        if (scarta) {
            var chiave = chiaveZona(zona);
            (inAttesa[chiave] || []).splice(Number(scarta.dataset.giScarta), 1);
            disegnaAttesa(zona);
            return;
        }

        // eliminare una foto gia' caricata
        var elimina = e.target.closest('[data-gi-elimina]');
        if (elimina) {
            if (!confirm('Eliminare questa foto? Non si recupera.')) return;
            eliminaFoto(zona, elimina.dataset.giElimina);
            return;
        }

        if (e.target.closest('[data-gi-carica]')) {
            caricaAttesa(zona);
        }
    });

    /** Manda tutte le foto in attesa di questa scheda, in una richiesta sola. */
    function caricaAttesa(zona) {
        if (!window.fetch) return;

        var chiave = chiaveZona(zona);
        var file = inAttesa[chiave] || [];
        if (!file.length) return;

        var card = zona.closest('.gi-card');
        if (card) card.classList.add('is-attesa');

        var dati = new FormData();
        dati.append('_csrf', zona.dataset.giCsrf);
        dati.append('entita_id', zona.dataset.giEntita);
        dati.append('momento', zona.dataset.giMomento);
        file.forEach(function (f) { dati.append('foto[]', f); });

        fetch(zona.dataset.giUrl, {
            method: 'POST',
            body: dati,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (risposta) {
                if (risposta && risposta.ok) {
                    // si svuota solo adesso: svuotando prima, un errore di
                    // rete si porterebbe via le foto appena scelte
                    delete inAttesa[chiave];
                    if (risposta.messaggio) alert(risposta.messaggio);
                    return aggiorna();
                }
                alert(risposta && risposta.messaggio ? risposta.messaggio
                                                     : 'Caricamento non riuscito');
                if (card) card.classList.remove('is-attesa');
            })
            .catch(function () {
                alert('Caricamento non riuscito');
                if (card) card.classList.remove('is-attesa');
            });
    }

    function eliminaFoto(zona, id) {
        if (!window.fetch) return;

        var card = zona.closest('.gi-card');
        if (card) card.classList.add('is-attesa');

        var dati = new FormData();
        dati.append('_csrf', zona.dataset.giCsrf);
        dati.append('id', id);

        fetch(zona.dataset.giUrlElimina, {
            method: 'POST',
            body: dati,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (risposta) {
                if (risposta && risposta.ok) return aggiorna();
                alert('Foto non eliminata');
                if (card) card.classList.remove('is-attesa');
            })
            .catch(function () {
                alert('Foto non eliminata');
                if (card) card.classList.remove('is-attesa');
            });
    }

    /**
     * Richiede al server il solo pezzo che cambia (avanzamento + board) e lo
     * rimette al suo posto.
     *
     * Si potrebbe aggiornare la singola scheda a mano, ma poi contatori,
     * avanzamento e stato "fatta" andrebbero ricalcolati anche qui, e prima
     * o poi direbbero una cosa diversa dal database. Meglio una lettura in
     * piu' che due verita' diverse — tanto e' un frammento, non la pagina.
     */
    function aggiorna() {
        var url = window.location.href.split('#')[0];
        url += (url.indexOf('?') === -1 ? '?' : '&') + 'frammento=1';

        return fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) {
                if (!r.ok) throw new Error('frammento non disponibile');
                return r.text();
            })
            .then(function (html) {
                // la posizione della board si conserva: sul telefono si sta
                // scorrendo di lato, e tornare alla prima colonna a ogni
                // tocco farebbe perdere il segno
                var board = corpo.querySelector('.gi-board');
                var scorrimento = board ? board.scrollLeft : 0;

                corpo.innerHTML = html;

                board = corpo.querySelector('.gi-board');
                if (board) board.scrollLeft = scorrimento;

                applica();
                // le foto scelte e non ancora caricate stanno in memoria, non
                // nel documento: la board appena riscritta non le ha, e vanno
                // rimesse o sembrerebbero perse
                ridisegnaAttese();
            })
            .catch(ricarica);
    }

    function ricarica() {
        window.location.reload();
    }

    /** Rimette le miniature in attesa su tutte le schede che ne hanno. */
    function ridisegnaAttese() {
        document.querySelectorAll('[data-gi-foto-zona]').forEach(function (zona) {
            if ((inAttesa[chiaveZona(zona)] || []).length) disegnaAttesa(zona);
        });
    }

    applica();
})();
