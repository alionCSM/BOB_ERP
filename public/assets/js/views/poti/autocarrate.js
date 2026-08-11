/*
 * Poti Noleggi — autocarrate e prenotazioni.
 * Una sola modale per pagina, usata sia per l'inserimento sia per la modifica.
 */
(function () {
    'use strict';

    // ── Cambio vista ────────────────────────────────────────────────────────
    // Ogni pulsante data-ac-vista="x" mostra il blocco con id ac-vista-x e
    // nasconde gli altri: cosi' la stessa logica vale per qualsiasi coppia
    // di viste, senza id scritti a mano qui dentro.
    var pulsantiVista = document.querySelectorAll('[data-ac-vista]');

    pulsantiVista.forEach(function (b) {
        b.addEventListener('click', function () {
            pulsantiVista.forEach(function (x) {
                var blocco = document.getElementById('ac-vista-' + x.dataset.acVista);
                if (blocco) blocco.hidden = (x !== b);
                x.classList.toggle('on', x === b);
            });
        });
    });

    var modal = document.getElementById('ac-modal-mezzo') || document.getElementById('ac-modal-pren');
    if (!modal) return;

    var isPren = modal.id === 'ac-modal-pren';
    var titolo = document.getElementById('ac-modal-tit');

    function campo(id) { return document.getElementById(id); }

    function apri(titoloTesto) {
        titolo.textContent = titoloTesto;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
    }

    function chiudi() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
    }

    function svuota() {
        modal.querySelectorAll('input[type="text"], input[type="date"], input[type="number"], textarea')
            .forEach(function (i) { i.value = ''; });
        modal.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });

        // il commerciale torna a chi sta usando BOB, non alla prima voce
        var comm = campo('ac-p-commerciale');
        if (comm && comm.dataset.predefinito) comm.value = comm.dataset.predefinito;

        var id = campo(isPren ? 'ac-p-id' : 'ac-m-id');
        if (id) id.value = '';
        var del = campo('ac-p-elimina');
        if (del) del.hidden = true;
    }

    // ── Nuovo ───────────────────────────────────────────────────────────────
    document.querySelectorAll('[data-ac-nuovo]').forEach(function (b) {
        b.addEventListener('click', function () {
            svuota();
            apri(isPren ? 'Nuova prenotazione' : 'Nuova autocarrata');
        });
    });

    // ── Modifica ────────────────────────────────────────────────────────────
    document.querySelectorAll('[data-ac-modifica]').forEach(function (b) {
        b.addEventListener('click', function () {
            var d;
            try { d = JSON.parse(b.dataset.acModifica); } catch (e) { return; }
            svuota();

            if (isPren) {
                campo('ac-p-id').value      = d.id;
                campo('ac-p-mezzo').value   = d.autocarrata_id;
                campo('ac-p-cliente').value = d.cliente || '';
                campo('ac-p-tel').value     = d.telefono || '';
                campo('ac-p-luogo').value   = d.luogo || '';
                campo('ac-p-dal').value     = d.data_inizio || '';
                campo('ac-p-al').value      = d.data_fine || '';
                campo('ac-p-stato').value   = d.stato || 'confermata';
                campo('ac-p-note').value    = d.note || '';

                campo('ac-p-contratto').value = d.contratto || '';
                campo('ac-p-pagamento').value = d.pagamento || 'da_pagare';

                // in modifica si tiene il commerciale registrato; se manca
                // (prenotazioni vecchie) resta quello proposto
                var comm = campo('ac-p-commerciale');
                if (comm && d.commerciale_user_id) comm.value = d.commerciale_user_id;

                // i campi prezzo esistono solo per chi puo' vederli
                var t = campo('ac-p-tariffa');
                if (t) t.value = d.tariffa_giorno || '';
                var tot = campo('ac-p-totale');
                if (tot) tot.value = d.totale || '';
                var imp = campo('ac-p-importo');
                if (imp) imp.value = d.importo || '';

                var del = campo('ac-p-elimina');
                if (del) {
                    del.hidden = false;
                    del.dataset.id = d.id;
                }
                apri('Prenotazione di ' + (d.cliente || ''));
            } else {
                campo('ac-m-id').value      = d.id;
                campo('ac-m-targa').value   = d.targa || '';
                campo('ac-m-modello').value = d.modello || '';
                campo('ac-m-altezza').value = d.altezza_max_m || '';
                campo('ac-m-portata').value = d.portata_kg || '';
                campo('ac-m-stato').value   = d.stato || 'attiva';
                campo('ac-m-note').value    = d.note || '';
                apri('Autocarrata ' + (d.targa || ''));
            }
        });
    });

    // ── Chiusura ────────────────────────────────────────────────────────────
    modal.addEventListener('click', function (e) {
        if (e.target === modal || e.target.closest('[data-ac-close]')) chiudi();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) chiudi();
    });

    // ── Totale suggerito ────────────────────────────────────────────────────
    // proposto, non imposto: il totale resta modificabile a mano perche'
    // spesso ci sono trasporto o sconti che la moltiplicazione non sa
    var tariffa = campo('ac-p-tariffa');
    var totale  = campo('ac-p-totale');
    var calcolo = campo('ac-p-calcolo');

    function giorni() {
        var dal = campo('ac-p-dal'), al = campo('ac-p-al');
        if (!dal || !al || !dal.value || !al.value) return 0;
        var d1 = new Date(dal.value), d2 = new Date(al.value);
        if (d2 < d1) return 0;
        return Math.round((d2 - d1) / 86400000) + 1;
    }

    function aggiorna() {
        if (!tariffa || !calcolo) return;
        var gg = giorni();
        var tar = parseFloat(String(tariffa.value).replace(',', '.'));
        if (!gg || isNaN(tar)) { calcolo.textContent = ''; return; }
        var att = gg * tar;
        calcolo.textContent = gg + ' gg × ' + tar.toFixed(2) + ' = ' + att.toFixed(2);
        if (totale && !totale.value) totale.value = att.toFixed(2);
    }

    [tariffa, campo('ac-p-dal'), campo('ac-p-al')].forEach(function (el) {
        el && el.addEventListener('change', aggiorna);
        el && el.addEventListener('input', aggiorna);
    });

    // ── Eliminazione ────────────────────────────────────────────────────────
    var elimina = campo('ac-p-elimina');
    elimina && elimina.addEventListener('click', function () {
        if (!confirm('Eliminare la prenotazione?')) return;
        campo('ac-del-id').value = elimina.dataset.id;
        document.getElementById('ac-form-elimina').submit();
    });
})();
