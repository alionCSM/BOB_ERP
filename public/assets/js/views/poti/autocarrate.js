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

        // il commerciale e' solo mostrato: su una nuova prenotazione torna
        // a chi sta usando BOB
        var comm = campo('ac-p-commerciale');
        if (comm && comm.dataset.predefinito) comm.textContent = comm.dataset.predefinito;

        // l'elenco dei mezzi torna completo: una precedente ricerca puo'
        // averne tolti, e senza questo il mezzo da modificare non ci sarebbe
        var sel = campo('ac-p-mezzo');
        if (sel && tutteOpz.length) {
            sel.innerHTML = '';
            tutteOpz.forEach(function (o) { sel.add(new Option(o.text, o.value)); });
        }
        var av = campo('ac-p-avviso');
        if (av) { av.textContent = ''; av.className = 'ac-avviso'; }

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
            if (isPren) aggiornaMezzi();
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

                // in modifica si mostra chi l'aveva registrata, non chi sta
                // correggendo: il commerciale non cambia piu'
                var comm = campo('ac-p-commerciale');
                if (comm) comm.textContent = d.commerciale_nome || '—';

                // dal database arrivano con il punto decimale: si rimettono
                // all'italiana, altrimenti si modifica un campo che mostra
                // un formato diverso da quello che si e' scritto
                campo('ac-p-importo').value = d.importo        ? euro(d.importo)        : '';
                campo('ac-p-tariffa').value = d.tariffa_giorno ? euro(d.tariffa_giorno) : '';
                campo('ac-p-totale').value  = d.totale         ? euro(d.totale)         : '';

                var del = campo('ac-p-elimina');
                if (del) {
                    del.hidden = false;
                    del.dataset.id = d.id;
                }
                apri('Prenotazione di ' + (d.cliente || ''));
                // le date sono state impostate da codice, quindi l'evento
                // change non scatta: l'elenco va aggiornato a mano
                aggiornaMezzi();
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

    /**
     * Numero scritto all'italiana -> numero.
     * Accetta 1.234,56 (punto migliaia, virgola decimali), 1234,56 e anche
     * 1234.56. Sostituire solo la virgola col punto non basta: "1.234,56"
     * diventerebbe "1.234.56", cioe' niente.
     */
    function numero(testo) {
        var v = String(testo == null ? '' : testo).replace(/[^0-9,.-]/g, '');
        if (!v) return NaN;
        if (v.indexOf(',') !== -1) {
            v = v.replace(/\./g, '').replace(',', '.');
        } else if ((v.match(/\./g) || []).length > 1) {
            v = v.replace(/\./g, '');
        }
        return parseFloat(v);
    }

    /** Numero -> testo all'italiana, con la virgola per i decimali. */
    function euro(n) {
        return (Number(n) || 0).toLocaleString('it-IT', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function aggiorna() {
        if (!tariffa || !calcolo) return;
        var gg = giorni();
        var tar = numero(tariffa.value);
        if (!gg || isNaN(tar)) {
            calcolo.textContent = 'giorni per tariffa';
            return;
        }
        var att = gg * tar;
        calcolo.textContent = gg + ' gg × ' + euro(tar) + ' = ' + euro(att);
        // proposto solo se il totale e' ancora vuoto: se qualcuno l'ha
        // scritto a mano (trasporto, sconti) non va sovrascritto
        if (totale && !totale.value) totale.value = euro(att);
    }

    [tariffa, campo('ac-p-dal'), campo('ac-p-al')].forEach(function (el) {
        el && el.addEventListener('change', aggiorna);
        el && el.addEventListener('input', aggiorna);
    });

    // ── Mezzi disponibili nel periodo ───────────────────────────────────────
    // Scelte le date, dall'elenco spariscono le autocarrate gia' impegnate:
    // meglio non poterle scegliere che scoprirlo dopo aver compilato tutto.
    // Resta comunque il controllo al salvataggio, che e' quello che conta se
    // nel frattempo prenota qualcun altro.
    var selMezzo = campo('ac-p-mezzo');
    var avviso   = campo('ac-p-avviso');
    var tutteOpz = selMezzo
        ? Array.prototype.map.call(selMezzo.options, function (o) {
              return { value: o.value, text: o.text };
          })
        : [];

    function aggiornaMezzi() {
        if (!selMezzo) return;

        var dal = campo('ac-p-dal'), al = campo('ac-p-al');
        var idAttuale = campo('ac-p-id') ? campo('ac-p-id').value : '';
        // in modifica il mezzo assegnato va protetto dal filtro, altrimenti
        // sparirebbe da sotto gli occhi; su una prenotazione nuova invece
        // non c'e' niente da proteggere e va nascosto come gli altri
        var daTenere = idAttuale ? selMezzo.value : null;
        var scelto   = selMezzo.value;

        function ripristina(nota) {
            selMezzo.innerHTML = '';
            tutteOpz.forEach(function (o) {
                selMezzo.add(new Option(o.text, o.value));
            });
            selMezzo.value = scelto;
            selMezzo.disabled = false;
            if (avviso) { avviso.textContent = nota || ''; avviso.className = 'ac-avviso'; }
        }

        if (!dal || !al || !dal.value || !al.value || al.value < dal.value) {
            // senza date non si puo' sapere chi e' libero: meglio non far
            // scegliere che far scegliere a caso
            ripristina('Scegli prima le date.');
            selMezzo.disabled = true;
            return;
        }

        var q = '?dal=' + dal.value + '&al=' + al.value;
        if (idAttuale) q += '&escludi=' + idAttuale;

        fetch('/autocarrate/prenotazioni/occupati' + q, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { ripristina(''); return; }

                var nascoste = 0;
                selMezzo.innerHTML = '';
                selMezzo.disabled = false;

                tutteOpz.forEach(function (o) {
                    var occ = d.occupati[o.value];
                    if (occ && o.value !== daTenere) { nascoste++; return; }
                    selMezzo.add(new Option(o.text, o.value));
                });

                if (selMezzo.options.length) {
                    selMezzo.value = selMezzo.querySelector('option[value="' + scelto + '"]')
                        ? scelto
                        : selMezzo.options[0].value;
                }

                if (avviso) {
                    if (!selMezzo.options.length) {
                        avviso.textContent = 'Nessuna autocarrata libera in queste date.';
                        avviso.className = 'ac-avviso is-ko';
                    } else if (nascoste) {
                        avviso.textContent = nascoste + (nascoste === 1
                            ? ' autocarrata nascosta perche\' occupata'
                            : ' autocarrate nascoste perche\' occupate');
                        avviso.className = 'ac-avviso';
                    } else {
                        avviso.textContent = 'Tutte libere nel periodo.';
                        avviso.className = 'ac-avviso is-ok';
                    }
                }
            })
            .catch(function () { ripristina(''); });
    }

    [campo('ac-p-dal'), campo('ac-p-al')].forEach(function (el) {
        el && el.addEventListener('change', aggiornaMezzi);
    });

    // ── Eliminazione ────────────────────────────────────────────────────────
    var elimina = campo('ac-p-elimina');
    elimina && elimina.addEventListener('click', function () {
        if (!confirm('Eliminare la prenotazione?')) return;
        campo('ac-del-id').value = elimina.dataset.id;
        document.getElementById('ac-form-elimina').submit();
    });
})();
