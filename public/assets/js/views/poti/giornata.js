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
            })
            .catch(ricarica);
    }

    function ricarica() {
        window.location.reload();
    }

    applica();
})();
