/*
 * Gestione societa' del gruppo: modale "nuova societa'", ricerca e
 * selezione rapida degli utenti.
 */
(function () {
    'use strict';

    // ── Modale nuova societa' ───────────────────────────────────────────────
    var modal = document.getElementById('sg-modal');
    var apri  = document.getElementById('sg-nuova');

    function chiudi() {
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
    }

    apri && apri.addEventListener('click', function () {
        if (!modal) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        var primo = modal.querySelector('input[name="nome"]');
        primo && primo.focus();
    });

    modal && modal.addEventListener('click', function (e) {
        // chiude cliccando fuori dal riquadro o sui pulsanti dedicati
        if (e.target === modal || e.target.closest('[data-sg-close]')) chiudi();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && !modal.hidden) chiudi();
    });

    // ── Moduli abilitati ────────────────────────────────────────────────────
    // "Tutti i moduli" spegne l'elenco invece di nasconderlo: si continua a
    // vedere cosa comprende, senza poterlo modificare.
    var tutti = document.getElementById('sg-tutti');
    var corpo = document.getElementById('sg-moduli-body');

    tutti && corpo && tutti.addEventListener('change', function () {
        corpo.classList.toggle('is-off', tutti.checked);
    });

    document.querySelectorAll('[data-sg-gruppo]').forEach(function (b) {
        b.addEventListener('click', function () {
            var lista = document.querySelector('.sg-mod-lista[data-gruppo="' + b.dataset.sgGruppo + '"]');
            if (!lista) return;
            var caselle = lista.querySelectorAll('input[type="checkbox"]');
            // se sono gia' tutte spuntate il pulsante le toglie
            var tutteOn = Array.prototype.every.call(caselle, function (c) { return c.checked; });
            caselle.forEach(function (c) { c.checked = !tutteOn; });
        });
    });

    // ── Ricerca utenti ──────────────────────────────────────────────────────
    var cerca = document.getElementById('sg-cerca');
    var righe = Array.prototype.slice.call(document.querySelectorAll('.sg-user'));

    cerca && cerca.addEventListener('input', function () {
        var q = cerca.value.trim().toLowerCase();
        righe.forEach(function (r) {
            r.style.display = !q || (r.dataset.nome || '').indexOf(q) !== -1 ? '' : 'none';
        });
    });

    // ── Seleziona / deseleziona ─────────────────────────────────────────────
    // agisce solo sulle righe visibili, cosi' funziona insieme alla ricerca
    document.querySelectorAll('[data-sg-all]').forEach(function (b) {
        b.addEventListener('click', function () {
            var val = b.dataset.sgAll === '1';
            righe.forEach(function (r) {
                if (r.style.display === 'none') return;
                var cb = r.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = val;
            });
        });
    });
})();
