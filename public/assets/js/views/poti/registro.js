/*
 * Registro modifiche Poti: ricerca dal vivo sulla tabella e dettaglio in
 * modale. Le voci sono gia' tutte nella pagina, quindi non serve tornare al
 * server ne' per filtrare ne' per aprire un'operazione.
 */
(function () {
    'use strict';

    var tabella = document.querySelector('.rg-tabella tbody');
    if (!tabella) return;

    var righe = Array.prototype.filter.call(
        tabella.querySelectorAll('tr'),
        function (r) { return r.classList.contains('rg-riga'); }
    );

    // ── Ricerca dal vivo ────────────────────────────────────────────────────
    var cerca     = document.getElementById('rg-cerca');
    var contatore = document.getElementById('rg-conta');
    var nessuno   = document.getElementById('rg-nessuno');

    function filtra() {
        var q = cerca.value.trim().toLowerCase();
        var visibili = 0;

        righe.forEach(function (r) {
            // il testo su cui cercare e' preparato lato server e comprende
            // anche i valori modificati, non solo le colonne visibili
            var ok = !q || (r.dataset.rgTesto || '').indexOf(q) !== -1;
            // display invece dell'attributo hidden: sulle righe di tabella
            // alcune regole CSS lo scavalcano
            r.style.display = ok ? '' : 'none';
            if (ok) visibili++;
        });

        if (nessuno) nessuno.style.display = (righe.length && !visibili) ? '' : 'none';
        if (contatore) {
            contatore.textContent = (q && visibili !== righe.length)
                ? visibili + ' di ' + righe.length
                : '';
        }
    }

    if (cerca) {
        cerca.addEventListener('input', filtra);
        // premere Invio non deve ricaricare: il filtro e' gia' applicato
        cerca.form && cerca.form.addEventListener('submit', function (e) {
            if (document.activeElement === cerca) e.preventDefault();
        });
    }

    // ── Dettaglio ───────────────────────────────────────────────────────────
    var modale  = document.getElementById('rg-modal');
    var titolo  = document.getElementById('rg-modal-tit');
    var scheda  = document.getElementById('rg-scheda');
    var cambi   = document.getElementById('rg-modal-cambi');
    var formRip = document.getElementById('rg-form-ripristina');
    if (!modale) return;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function voce(etichetta, valore) {
        if (!valore) return '';
        return '<div class="rg-voce-dato">'
             + '<span>' + esc(etichetta) + '</span>'
             + '<strong>' + esc(valore) + '</strong>'
             + '</div>';
    }

    /** "2026-08-12 13:18:04" -> "12/08/2026 13:18" */
    function quando(iso) {
        if (!iso) return '';
        var p = String(iso).split(' ');
        var g = (p[0] || '').split('-');
        if (g.length !== 3) return String(iso);
        return g[2] + '/' + g[1] + '/' + g[0] + ' ' + (p[1] || '').slice(0, 5);
    }

    function apri(d) {
        titolo.textContent = (d.etichetta || d.entita || 'Operazione');

        scheda.innerHTML =
              voce('Azione', d.azione)
            + voce('Tipo', d.entita)
            + voce('Quando', quando(d.created_at))
            + voce('Utente', d.user_nome || 'sistema')
            + voce('Riepilogo', d.dettaglio);

        if (d.cambi && d.cambi.length) {
            cambi.innerHTML =
                  '<div class="rg-cambi-tit">Modifiche</div>'
                + d.cambi.map(function (c) {
                      return '<div class="rg-cambio">'
                           + '<span class="rg-campo">' + esc(c.campo) + '</span>'
                           + '<span class="rg-prima">' + esc(c.prima) + '</span>'
                           + '<span class="rg-freccia">→</span>'
                           + '<span class="rg-dopo">' + esc(c.dopo) + '</span>'
                           + '</div>';
                  }).join('');
        } else {
            cambi.innerHTML = '<div class="rg-nulla">Nessun campo modificato.</div>';
        }

        // si ripristina solo cio' che era stato eliminato e che puo' tornare
        var recuperabile = d.azione === 'eliminato'
            && (d.entita === 'prenotazione' || d.entita === 'noleggio');
        if (formRip) {
            formRip.hidden = !recuperabile;
            if (recuperabile) {
                document.getElementById('rg-rip-id').value = d.entita_id;
            }
        }

        modale.hidden = false;
        modale.setAttribute('aria-hidden', 'false');
    }

    function chiudi() {
        modale.hidden = true;
        modale.setAttribute('aria-hidden', 'true');
    }

    righe.forEach(function (r) {
        r.addEventListener('click', function () {
            var d;
            try { d = JSON.parse(r.dataset.rg); } catch (e) { return; }
            apri(d);
        });
    });

    modale.addEventListener('click', function (e) {
        if (e.target === modale || e.target.closest('[data-rg-close]')) chiudi();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modale.hidden) chiudi();
    });
})();
