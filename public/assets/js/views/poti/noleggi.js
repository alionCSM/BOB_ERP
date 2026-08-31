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

    /**
     * Mesi di calendario piu' i giorni che avanzano.
     *
     * Deve rispondere come Tariffa::mesiEGiorni in PHP: qui il conto serve
     * a far vedere il totale mentre si scrive, ma quello che vale e' il
     * conto del server. Se le due versioni si allontanano l'utente vede una
     * cifra e ne salva un'altra.
     */
    function mesiEGiorni(dal, al) {
        if (!dal || !al) return { mesi: 0, giorni: 0 };
        var inizio = new Date(dal + 'T00:00:00');
        var fine   = new Date(al + 'T00:00:00');
        if (fine < inizio) return { mesi: 0, giorni: 0 };

        var mesi = 0;
        while (piuMesi(inizio, mesi + 1) <= fine) mesi++;

        if (mesi === 0) {
            return { mesi: 0, giorni: giorniTra(dal, al) };
        }
        var scadenza = piuMesi(inizio, mesi);
        return { mesi: mesi, giorni: Math.round((fine - scadenza) / 86400000) };
    }

    /**
     * Somma mesi restando dentro il mese di arrivo: il 31 gennaio piu' un
     * mese e' il 28 febbraio, non il 3 marzo.
     */
    function piuMesi(data, n) {
        var giorno  = data.getDate();
        var arrivo  = new Date(data.getFullYear(), data.getMonth() + n, 1);
        var ultimo  = new Date(arrivo.getFullYear(), arrivo.getMonth() + 1, 0).getDate();
        arrivo.setDate(Math.min(giorno, ultimo));
        return arrivo;
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
                apriM('Nuovo mezzo');
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
                apriM('Mezzo ' + (d.matricola || ''));
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
    // ── Elenco mezzi: riga che si apre ──────────────────────────────────────
    // Sta prima dell'uscita qui sotto: la pagina dei mezzi non ha la modale
    // dei noleggi, quindi tutto quello che viene dopo li' non gira.
    //
    // Il click apre e chiude i dettagli. Niente onclick nell'HTML: le
    // regole di sicurezza della pagina non eseguono gli attributi inline,
    // quindi un handler scritto li' sarebbe morto in partenza.
    document.querySelectorAll('[data-nl-mezzo]').forEach(function (riga) {
        function apriChiudi() {
            var dett = document.querySelector('[data-nl-dett="' + riga.dataset.nlMezzo + '"]');
            if (!dett) return;
            dett.hidden = !dett.hidden;
            riga.classList.toggle('is-aperta', !dett.hidden);
        }

        riga.addEventListener('click', apriChiudi);

        // da tastiera: la riga e' raggiungibile col tab, deve rispondere
        // anche a Invio e barra spaziatrice
        riga.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                apriChiudi();
            }
        });
    });

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

    /**
     * Campo macchina con ricerca.
     *
     * Le macchine sono tante e un elenco a tendina normale costringerebbe a
     * scorrerlo: qui si scrive matricola o modello e l'elenco si restringe.
     * Va inizializzato DOPO aver impostato il valore, altrimenti TomSelect
     * legge il campo ancora vuoto e la riga risulta senza macchina.
     */
    function attivaRicerca(riga) {
        var sel = riga.querySelector('[name="riga_macchina[]"]');
        if (!sel || typeof TomSelect === 'undefined' || sel.tomselect) {
            return;
        }
        new TomSelect(sel, {
            // la voce contiene matricola, tipo e modello: cercando per uno
            // qualsiasi dei tre si arriva alla macchina
            searchField: ['text'],
            maxOptions: 200,
            placeholder: 'cerca matricola o modello…',
            // il ricalcolo si aggancia qui e non solo alla delega degli
            // eventi: TomSelect sostituisce il campo originale e non tutte
            // le sue versioni fanno risalire il change
            onChange: function () {
                ricalcolaRiga(riga);
                ricalcolaTotale();
            }
        });
    }

    /** Chiude il campo con ricerca prima di buttare via la riga. */
    function spegniRicerca(riga) {
        var sel = riga.querySelector('[name="riga_macchina[]"]');
        if (sel && sel.tomselect) {
            sel.tomselect.destroy();
        }
    }

    /** Quanto costa una riga, se il totale non e' stato scritto a mano. */
    function ricalcolaRiga(riga) {
        var dal = riga.querySelector('[name="riga_dal[]"]');
        var al  = riga.querySelector('[name="riga_al[]"]');
        var tar = riga.querySelector('[name="riga_tariffa[]"]');
        var tot = riga.querySelector('[name="riga_totale[]"]');
        if (!dal || !al || !tar || !tot) return;

        var unita  = riga.querySelector('[name="riga_unita[]"]');
        var u      = unita ? unita.value : 'giorno';
        var durata = riga.querySelector('.nl-riga-durata');

        aggiornaEtichetta(riga, u);

        var somma = calcolaRiga(u, dal.value, al.value, numero(tar.value) || 0);

        if (durata) durata.textContent = testoDurata(u, dal.value, al.value);
        if (!tot.dataset.aMano && somma) tot.value = euro(somma);
    }

    /**
     * Il conto della riga. Gemello di Tariffa::totaleRiga in PHP: se le due
     * versioni si allontanano si vede una cifra e se ne salva un'altra.
     */
    function calcolaRiga(unita, dal, al, tariffa) {
        if (unita === 'tantum') return tariffa;          // a corpo: la durata non conta

        if (unita === 'mese') {
            var q = mesiEGiorni(dal, al);
            // i giorni oltre l'ultimo mese intero si pagano in trentesimi
            // del canone: non si arrotonda al mese pieno, farebbe pagare un
            // mese per cinque giorni
            return q.mesi * tariffa + q.giorni * (tariffa / 30);
        }
        return giorniTra(dal, al) * tariffa;
    }

    /** "1 mese + 5 gg", "12 gg", oppure niente per la tariffa a corpo. */
    function testoDurata(unita, dal, al) {
        if (unita === 'tantum') return 'a corpo';

        if (unita === 'mese') {
            var q = mesiEGiorni(dal, al);
            if (!q.mesi) return q.giorni ? q.giorni + ' gg' : '';
            return q.mesi + (q.mesi === 1 ? ' mese' : ' mesi')
                 + (q.giorni ? ' + ' + q.giorni + ' gg' : '');
        }
        var gg = giorniTra(dal, al);
        return gg ? gg + ' gg' : '';
    }

    /** L'etichetta della tariffa dice sempre che cifra si sta scrivendo. */
    function aggiornaEtichetta(riga, unita) {
        var et = riga.querySelector('.nl-et-tariffa');
        if (!et) return;
        et.textContent = unita === 'mese' ? 'Importo/mese'
                       : unita === 'tantum' ? 'Importo fisso'
                       : 'Importo/g';
    }

    /** Totale del noleggio: mezzi, piu' trasporto, piu' assicurazione. */
    function ricalcolaTotale() {
        var mezzi = 0;
        righe().forEach(function (r) {
            var v = numero(r.querySelector('[name="riga_totale[]"]').value);
            if (!isNaN(v)) mezzi += v;
        });

        var trasp = numero(campo('nl-trasporto').value);
        if (isNaN(trasp)) trasp = 0;

        // L'assicurazione si calcola sui soli mezzi: il trasporto resta
        // fuori dalla base. Stessa regola di Tariffa::assicurazione.
        var assic  = 0;
        var spunta = campo('nl-assic');
        var perc   = campo('nl-assic-perc');
        var acceso = !!(spunta && spunta.checked);

        // la percentuale resta spenta finche' non si include: un campo
        // scrivibile che non incide su niente e' un invito a sbagliare
        if (perc) perc.disabled = !acceso;

        var etichetta = campo('nl-assic-importo');

        if (acceso) {
            var p = numero(perc ? perc.value : '');
            if (isNaN(p)) p = 12;
            assic = Math.round(mezzi * p) / 100;
            if (etichetta) etichetta.textContent = '€ ' + euro(assic) + ' sui mezzi';
        } else if (etichetta) {
            etichetta.textContent = '';
        }

        var somma = mezzi + trasp + assic;

        if (calcolo) {
            calcolo.textContent = righe().length
                ? 'mezzi ' + euro(mezzi)
                  + (trasp ? ' + trasporto ' + euro(trasp) : '')
                  + (assic ? ' + assicurazione ' + euro(assic) : '')
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
            var u = dati.unita || 'giorno';
            var unita = riga.querySelector('[name="riga_unita[]"]');
            if (unita) unita.value = u;

            // la tariffa sta nella colonna della sua unita': a mese in
            // tariffa_mese, negli altri casi in tariffa_giorno
            var salvata = u === 'mese' ? dati.tariffa_mese : dati.tariffa_giorno;
            riga.querySelector('[name="riga_tariffa[]"]').value = salvata ? euro(salvata) : '';
            aggiornaEtichetta(riga, u);

            var durata = riga.querySelector('.nl-riga-durata');
            if (durata) durata.textContent = testoDurata(u, dati.data_inizio, dati.data_fine);

            var tot = riga.querySelector('[name="riga_totale[]"]');
            tot.value = dati.totale ? euro(dati.totale) : '';

            // un totale che non coincide col calcolo era stato corretto a
            // mano e non va sovrascritto
            var atteso = euro(calcolaRiga(u, dati.data_inizio, dati.data_fine,
                                          numero(salvata) || 0));
            if (tot.value && tot.value !== atteso) tot.dataset.aMano = '1';
        }

        // dopo aver messo il valore, mai prima
        attivaRicerca(riga);

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
            spegniRicerca(riga);
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

    // spunta e percentuale rifanno il totale: e' l'unico modo di vedere
    // quanto pesa l'assicurazione prima di salvare
    var spuntaAssic = campo('nl-assic');
    var percAssic   = campo('nl-assic-perc');
    spuntaAssic && spuntaAssic.addEventListener('change', ricalcolaTotale);
    percAssic   && percAssic.addEventListener('input',  ricalcolaTotale);

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
        campo('nl-firmato').checked = false;
        // l'assicurazione torna come su una maschera appena aperta: spunta
        // giu', percentuale al valore di partenza e spenta, importo via.
        // Senza, aprendo un noleggio nuovo dopo uno assicurato restavano il
        // campo attivo e la cifra del noleggio di prima.
        if (spuntaAssic) spuntaAssic.checked = false;
        if (percAssic) {
            percAssic.value = '12';
            percAssic.disabled = true;
        }
        var etAssic = campo('nl-assic-importo');
        if (etAssic) etAssic.textContent = '';

        // le ricerche vanno chiuse prima di svuotare, altrimenti restano
        // agganciate a campi che non esistono piu'
        righe().forEach(spegniRicerca);
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
            campo('nl-firmato').checked = !!Number(d.contratto_firmato);
            campo('nl-stato').value     = d.stato || 'confermato';
            campo('nl-pagamento').value = d.pagamento || 'da_pagare';
            campo('nl-note').value      = d.note || '';
            campo('nl-trasporto').value = d.trasporto ? euro(d.trasporto) : '';

            // la percentuale si rilegge da com'era salvata e non dal valore
            // di oggi: se un domani cambia, i noleggi vecchi devono
            // continuare a mostrare quella con cui sono stati fatti
            if (spuntaAssic) spuntaAssic.checked = !!Number(d.assicurazione);
            if (percAssic && d.assicurazione_perc) {
                percAssic.value = String(Number(d.assicurazione_perc));
            }

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

    // ── Ricerca ─────────────────────────────────────────────────────────────
    // Qui prima c'era un filtro che nascondeva le schede gia' presenti nella
    // pagina. Adesso la pagina ne contiene una parte per volta, e cercare
    // fra quelle vorrebbe dire cercare in un ventiquattresimo dell'archivio
    // credendo di averlo guardato tutto: la ricerca la fa il database.
    //
    // Quel codice fermava anche l'invio del form quando il cursore era nel
    // campo di ricerca, quindi va tolto per intero: lasciandolo, premere
    // Invio non cercherebbe niente.

})();
