/*
 * Poti — la giornata dei tecnici.
 *
 * Tre cose, tutte pensate per chi la usa in piedi in officina:
 *
 *  1. filtro per blocco (Escono / Rientrano / ...) e ricerca dal vivo;
 *  2. segnare uscita, rientro e firma SENZA ricaricare la pagina: prima ogni
 *     tocco faceva ripartire tutto da capo e si perdeva il punto in cui si
 *     era arrivati, che su un elenco di trenta mezzi e' scomodo;
 *  3. la scheda si blocca mentre la richiesta e' in volo, cosi' due tocchi
 *     rapidi non segnano-e-subito-annullano.
 *
 * Tutto e' un miglioramento, non un requisito: senza JavaScript i form
 * partono da soli, il server risponde con un redirect e la pagina funziona
 * come prima.
 */
(function () {
    'use strict';

    var corpo  = document.getElementById('gi-corpo');
    var lista  = document.getElementById('gi-lista');
    var cerca  = document.getElementById('gi-cerca');
    var nessuno = document.getElementById('gi-nessuno');
    if (!corpo || !lista) return;

    var filtro = 'tutti';

    // ── Filtro e ricerca ────────────────────────────────────────────────────

    function applica() {
        var testo = (cerca && cerca.value || '').trim().toLowerCase();
        var visibili = 0;

        document.querySelectorAll('.gi-card').forEach(function (card) {
            var okBlocco = filtro === 'tutti' || card.dataset.giBlocco === filtro;
            var okTesto  = testo === '' || (card.dataset.giCerca || '').indexOf(testo) !== -1;
            var mostra   = okBlocco && okTesto;

            card.hidden = !mostra;
            if (mostra) visibili++;
        });

        // una sezione senza schede visibili sparisce col suo titolo:
        // "Escono (4)" con sotto il vuoto sarebbe solo confusione
        document.querySelectorAll('[data-gi-blocco-sez]').forEach(function (sez) {
            var conta = sez.querySelectorAll('.gi-card:not([hidden])').length;
            sez.hidden = conta === 0;
        });

        if (nessuno) {
            nessuno.hidden = visibili > 0;
        }
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-gi-filtro]');
        if (!b) return;

        document.querySelectorAll('[data-gi-filtro]').forEach(function (x) {
            x.classList.toggle('is-on', x === b);
        });
        filtro = b.dataset.giFiltro;
        applica();
    });

    if (cerca) {
        cerca.addEventListener('input', applica);
        // Esc svuota: piu' rapido che cancellare a mano
        cerca.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { cerca.value = ''; applica(); }
        });
    }

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
     * Richiede al server il solo pezzo che cambia (riepilogo + schede) e lo
     * rimette al suo posto.
     *
     * Si potrebbe aggiornare la singola scheda a mano, ma poi contatori,
     * barra di avanzamento e stato "fatta" andrebbero ricalcolati anche qui,
     * e prima o poi direbbero una cosa diversa dal database. Meglio una
     * lettura in piu' che due verita' diverse — tanto e' un frammento, non
     * la pagina intera.
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
                corpo.innerHTML = html;

                // i riferimenti cambiano insieme al contenuto
                lista   = document.getElementById('gi-lista');
                nessuno = document.getElementById('gi-nessuno');

                // filtro e ricerca erano scelte dell'utente: si rimettono
                // com'erano, altrimenti a ogni tocco tornerebbe tutto.
                // Il blocco filtrato puo' essere sparito (era l'ultima
                // scheda in ritardo): in quel caso si torna a "Tutti".
                if (!document.querySelector('[data-gi-filtro="' + filtro + '"]')) {
                    filtro = 'tutti';
                }
                var b = document.querySelector('[data-gi-filtro="' + filtro + '"]');
                document.querySelectorAll('[data-gi-filtro]').forEach(function (x) {
                    x.classList.toggle('is-on', x === b);
                });

                applica();
            })
            .catch(ricarica);
    }

    function ricarica() {
        window.location.reload();
    }

    applica();
})();
