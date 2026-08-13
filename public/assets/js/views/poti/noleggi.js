/*
 * Poti Noleggi — macchine e noleggi.
 *
 * Differenza dalle autocarrate: un noleggio contiene piu' macchine, quindi
 * la maschera ha righe che si aggiungono e si tolgono, e i totali si
 * sommano dalle righe.
 */
(function () {
    'use strict';

    // ── Numeri all'italiana ─────────────────────────────────────────────────

    /**
     * Testo -> numero. Accetta 1.234,56 (punto migliaia, virgola decimali),
     * 1234,56 e 1234.56. Sostituire solo la virgola col punto non basta:
     * "1.234,56" diventerebbe "1.234.56", cioe' niente.
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

    function euro(n) {
        return (Number(n) || 0).toLocaleString('it-IT', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function giorniTra(dal, al) {
        if (!dal || !al) return 0;
        var d1 = new Date(dal), d2 = new Date(al);
        if (d2 < d1) return 0;
        return Math.round((d2 - d1) / 86400000) + 1;
    }

    function campo(id) { return document.getElementById(id); }

    // ── Cambio vista (calendario / timeline) ────────────────────────────────
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

    // ── Anagrafica macchine ─────────────────────────────────────────────────
    // La modale delle macchine e' identica a quella delle autocarrate, con
    // in piu' il tipo.
    var modaleMacchina = campo('ac-modal-mezzo');
    if (modaleMacchina) {
        var titoloM = campo('ac-modal-tit');

        var apriM = function (t) {
            titoloM.textContent = t;
            modaleMacchina.hidden = false;
            modaleMacchina.setAttribute('aria-hidden', 'false');
        };
        var chiudiM = function () {
            modaleMacchina.hidden = true;
            modaleMacchina.setAttribute('aria-hidden', 'true');
        };

        document.querySelectorAll('[data-ac-nuovo]').forEach(function (b) {
            b.addEventListener('click', function () {
                modaleMacchina.querySelectorAll('input[type="text"], input[type="number"], textarea')
                    .forEach(function (i) { i.value = ''; });
                modaleMacchina.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
                campo('ac-m-id').value = '';
                apriM('Nuova macchina');
            });
        });

        document.querySelectorAll('[data-ac-modifica]').forEach(function (b) {
            b.addEventListener('click', function () {
                var d;
                try { d = JSON.parse(b.dataset.acModifica); } catch (e) { return; }
                campo('ac-m-id').value        = d.id;
                campo('ac-m-tipo').value      = d.tipo || '';
                campo('ac-m-matricola').value = d.matricola || '';
                campo('ac-m-modello').value   = d.modello || '';
                // dal database arriva col punto: si rimette all'italiana
                campo('ac-m-altezza').value   = d.altezza_max_m ? euro(d.altezza_max_m) : '';
                campo('ac-m-portata').value   = d.portata_kg || '';
                campo('ac-m-stato').value     = d.stato || 'attiva';
                campo('ac-m-note').value      = d.note || '';
                apriM('Macchina ' + (d.matricola || ''));
            });
        });

        modaleMacchina.addEventListener('click', function (e) {
            if (e.target === modaleMacchina || e.target.closest('[data-ac-close]')) chiudiM();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modaleMacchina.hidden) chiudiM();
        });
    }

    // ── Noleggi ─────────────────────────────────────────────────────────────
    var modale = campo('nl-modal');
    if (!modale) return;

    var titolo   = campo('nl-modal-tit');
    var contRighe = campo('nl-righe');
    var modello  = campo('nl-riga-modello');
    var vuoto    = campo('nl-righe-vuoto');
    var calcolo  = campo('nl-calcolo');
    var totale   = campo('nl-totale');
    var totaleAMano = false;

    // il flag si alza solo digitando: assegnare il valore da codice non
    // scatena l'evento, cosi' il ricalcolo non si auto-disattiva
    totale && totale.addEventListener('input', function () { totaleAMano = true; });

    function righe() {
        return Array.prototype.slice.call(contRighe.querySelectorAll('.nl-riga'));
    }

    /** Totale di una riga: giorni per tariffa, se non e' stato scritto a mano. */
    function ricalcolaRiga(riga) {
        var dal  = riga.querySelector('[name="riga_dal[]"]');
        var al   = riga.querySelector('[name="riga_al[]"]');
        var tar  = riga.querySelector('[name="riga_tariffa[]"]');
        var tot  = riga.querySelector('[name="riga_totale[]"]');
        if (!dal || !al || !tar || !tot) return;

        var gg = giorniTra(dal.value, al.value);
        var t  = numero(tar.value);

        if (gg && !isNaN(t) && !tot.dataset.aMano) {
            tot.value = euro(gg * t);
        }
    }

    /** Totale del noleggio: somma delle righe piu' il trasporto. */
    function ricalcolaTotale() {
        var somma = 0;
        righe().forEach(function (r) {
            var v = numero(r.querySelector('[name="riga_totale[]"]').value);
            if (!isNaN(v)) somma += v;
        });

        var trasp = numero(campo('nl-trasporto').value);
        if (!isNaN(trasp)) somma += trasp;

        if (calcolo) {
            calcolo.textContent = righe().length
                ? 'macchine ' + euro(somma - (isNaN(trasp) ? 0 : trasp))
                  + (isNaN(trasp) ? '' : ' + trasporto ' + euro(trasp))
                : '';
        }
        if (totale && !totaleAMano) totale.value = euro(somma);
    }

    function aggiornaVuoto() {
        if (vuoto) vuoto.style.display = righe().length ? 'none' : '';
    }

    function aggiungiRiga(dati) {
        var nodo = modello.content.cloneNode(true);
        contRighe.appendChild(nodo);
        var riga = contRighe.lastElementChild;

        if (dati) {
            riga.querySelector('[name="riga_macchina[]"]').value = dati.macchina_id || '';
            riga.querySelector('[name="riga_dal[]"]').value      = dati.data_inizio || '';
            riga.querySelector('[name="riga_al[]"]').value       = dati.data_fine || '';
            riga.querySelector('[name="riga_tariffa[]"]').value  =
                dati.tariffa_giorno ? euro(dati.tariffa_giorno) : '';
            var tot = riga.querySelector('[name="riga_totale[]"]');
            tot.value = dati.totale ? euro(dati.totale) : '';

            // un totale che non coincide col calcolo era stato corretto a
            // mano e non va sovrascritto
            var atteso = euro(giorniTra(dati.data_inizio, dati.data_fine) * (numero(dati.tariffa_giorno) || 0));
            if (tot.value && tot.value !== atteso) tot.dataset.aMano = '1';
        }

        aggiornaVuoto();
        return riga;
    }

    // ── Eventi sulle righe ──────────────────────────────────────────────────
    contRighe.addEventListener('input', function (e) {
        var riga = e.target.closest('.nl-riga');
        if (!riga) return;

        if (e.target.name === 'riga_totale[]') {
            riga.querySelector('[name="riga_totale[]"]').dataset.aMano = '1';
        } else {
            ricalcolaRiga(riga);
        }
        ricalcolaTotale();
    });

    contRighe.addEventListener('change', function (e) {
        var riga = e.target.closest('.nl-riga');
        if (riga) { ricalcolaRiga(riga); ricalcolaTotale(); }
    });

    contRighe.addEventListener('click', function (e) {
        var riga = e.target.closest('.nl-riga');
        if (!riga) return;

        if (e.target.closest('[data-nl-togli]')) {
            riga.remove();
            aggiornaVuoto();
            ricalcolaTotale();
            return;
        }

        // copia il periodo di questa riga su tutte le altre: di solito le
        // macchine vanno e tornano insieme
        if (e.target.closest('[data-nl-copia]')) {
            var dal = riga.querySelector('[name="riga_dal[]"]').value;
            var al  = riga.querySelector('[name="riga_al[]"]').value;
            if (!dal || !al) return;

            righe().forEach(function (r) {
                if (r === riga) return;
                r.querySelector('[name="riga_dal[]"]').value = dal;
                r.querySelector('[name="riga_al[]"]').value  = al;
                ricalcolaRiga(r);
            });
            ricalcolaTotale();
        }
    });

    campo('nl-aggiungi').addEventListener('click', function () { aggiungiRiga(); });
    campo('nl-trasporto').addEventListener('input', ricalcolaTotale);

    // ── Apertura e chiusura ─────────────────────────────────────────────────
    function apri(t) {
        titolo.textContent = t;
        modale.hidden = false;
        modale.setAttribute('aria-hidden', 'false');
    }
    function chiudi() {
        modale.hidden = true;
        modale.setAttribute('aria-hidden', 'true');
    }

    function svuota() {
        modale.querySelectorAll('input[type="text"], input[type="search"], textarea')
            .forEach(function (i) { i.value = ''; });
        modale.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
        campo('nl-id').value = '';
        contRighe.innerHTML = '';
        totaleAMano = false;
        if (calcolo) calcolo.textContent = '';

        var comm = campo('nl-commerciale');
        if (comm && comm.dataset.predefinito) comm.textContent = comm.dataset.predefinito;

        var del = campo('nl-elimina');
        if (del) del.hidden = true;

        aggiornaVuoto();
    }

    document.querySelectorAll('[data-nl-nuovo]').forEach(function (b) {
        b.addEventListener('click', function () {
            svuota();
            aggiungiRiga();          // una riga pronta: serve sempre almeno una macchina
            apri('Nuovo noleggio');
        });
    });

    document.querySelectorAll('[data-nl-modifica]').forEach(function (b) {
        b.addEventListener('click', function () {
            var d;
            try { d = JSON.parse(b.dataset.nlModifica); } catch (e) { return; }
            svuota();

            campo('nl-id').value        = d.id;
            campo('nl-cliente').value   = d.cliente || '';
            campo('nl-telefono').value  = d.telefono || '';
            campo('nl-luogo').value     = d.luogo || '';
            campo('nl-contratto').value = d.contratto || '';
            campo('nl-stato').value     = d.stato || 'confermato';
            campo('nl-pagamento').value = d.pagamento || 'da_pagare';
            campo('nl-note').value      = d.note || '';
            campo('nl-trasporto').value = d.trasporto ? euro(d.trasporto) : '';

            (d.righe || []).forEach(function (r) { aggiungiRiga(r); });
            if (!(d.righe || []).length) aggiungiRiga();

            // il commerciale resta chi aveva preso il noleggio
            var comm = campo('nl-commerciale');
            if (comm) comm.textContent = d.commerciale_nome || '—';

            // se il totale salvato non coincide con la somma era una
            // correzione voluta e va lasciato stare
            totaleAMano = false;
            ricalcolaTotale();
            var calcolato = campo('nl-totale').value;
            campo('nl-totale').value = d.totale ? euro(d.totale) : calcolato;
            totaleAMano = !!(d.totale && euro(d.totale) !== calcolato);

            var del = campo('nl-elimina');
            if (del) { del.hidden = false; del.dataset.id = d.id; }

            apri('Noleggio di ' + (d.cliente || ''));
        });
    });

    modale.addEventListener('click', function (e) {
        if (e.target === modale || e.target.closest('[data-nl-close]')) chiudi();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modale.hidden) chiudi();
    });

    var elimina = campo('nl-elimina');
    elimina && elimina.addEventListener('click', function () {
        if (!confirm('Eliminare il noleggio e tutte le sue righe?')) return;
        campo('nl-del-id').value = elimina.dataset.id;
        campo('nl-form-elimina').submit();
    });

    // ── Ricerca dal vivo ────────────────────────────────────────────────────
    // I noleggi del periodo sono gia' tutti nella pagina: filtrarli qui e'
    // immediato e non serve tornare al server.
    var cerca    = campo('nl-cerca');
    var contatore = campo('nl-conta');
    var nessuno   = campo('nl-nessuno');
    var schede    = Array.prototype.slice.call(document.querySelectorAll('.nl-card'));

    if (cerca) {
        var filtra = function () {
            var q = cerca.value.trim().toLowerCase();
            var visibili = 0;

            schede.forEach(function (c) {
                var ok = !q || (c.dataset.nlTesto || '').indexOf(q) !== -1;
                c.style.display = ok ? '' : 'none';
                if (ok) visibili++;
            });

            if (nessuno) nessuno.style.display = (schede.length && !visibili) ? '' : 'none';
            if (contatore) {
                contatore.textContent = (q && visibili !== schede.length)
                    ? visibili + ' di ' + schede.length
                    : '';
            }
        };

        cerca.addEventListener('input', filtra);
        cerca.form && cerca.form.addEventListener('submit', function (e) {
            if (document.activeElement === cerca) e.preventDefault();
        });
        if (cerca.value) filtra();
    }
})();
