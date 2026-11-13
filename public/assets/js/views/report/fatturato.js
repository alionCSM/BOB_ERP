/*
 * Andamento fatturato: grafico multi-anno, dettaglio causali, e chi ci ha
 * pagato / chi abbiamo pagato.
 *
 * Chart.js lo carica la pagina dal CDN. Qui c'era scritto che stava nel
 * bundle del template: il codice la' dentro c'e', ma il bundle non lo
 * espone come variabile globale, e la pagina scriveva "Libreria grafici
 * non disponibile" senza che nessuno capisse perche'.
 *
 * Due blocchi separati, e separati apposta: il primo si ferma subito se in
 * pagina non c'e' il grafico, e finche' il resto stava dentro quello stesso
 * blocco moriva con lui. Un pezzo non deve portarsi dietro gli altri.
 */
(function () {
    'use strict';

    var canvas = document.getElementById('rp-chart');
    if (!canvas) return;

    var mesi  = JSON.parse(canvas.dataset.mesi  || '[]');
    var serie = JSON.parse(canvas.dataset.serie || '{}');
    var anni  = Object.keys(serie).sort();

    // una tinta per anno: l'anno piu' recente e' il piu' marcato
    var palette = ['#cbd5e1', '#94a3b8', '#38bdf8', '#0ea5e9', '#0284c7', '#0f766e'];
    function coloreAnno(i, tot) {
        var idx = palette.length - (tot - i);
        return palette[Math.max(0, Math.min(palette.length - 1, idx))];
    }

    function fmtEuro(v) {
        return '€ ' + (Number(v) || 0).toLocaleString('it-IT', { maximumFractionDigits: 0 });
    }

    function datasets(tipo) {
        return anni.map(function (y, i) {
            return {
                label: y,
                data: serie[y] ? serie[y][tipo] : [],
                borderColor: coloreAnno(i, anni.length),
                backgroundColor: coloreAnno(i, anni.length),
                borderWidth: i === anni.length - 1 ? 3 : 2,
                pointRadius: i === anni.length - 1 ? 3 : 2,
                tension: .3,
                fill: false
            };
        });
    }

    var chart = null;
    function render(tipo) {
        if (typeof Chart === 'undefined') {
            canvas.parentElement.innerHTML =
                '<div class="rp-empty">Libreria grafici non disponibile.</div>';
            return;
        }
        if (chart) chart.destroy();
        chart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels: mesi, datasets: datasets(tipo) },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: {
                        callbacks: {
                            label: function (c) { return c.dataset.label + ': ' + fmtEuro(c.parsed.y); }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function (v) {
                                // asse compatto: 120k invece di 120.000
                                return Math.abs(v) >= 1000
                                    ? (v / 1000).toLocaleString('it-IT') + 'k'
                                    : v;
                            }
                        },
                        grid: { color: '#f1f5f9' }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    render('entrate');

    var sw = document.getElementById('rp-switch');
    sw && sw.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-serie]');
        if (!b) return;
        sw.querySelectorAll('button').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        render(b.dataset.serie);
    });

    // ── Dettaglio causali del mese ──────────────────────────────────────────
    // Serve a verificare da dove arrivano i totali: e' il modo per accorgersi
    // se, per esempio, le note di credito stanno sommando invece di sottrarre.
    var modal = document.getElementById('rp-modal');
    var mTit  = document.getElementById('rp-modal-title');
    var mBody = document.getElementById('rp-modal-body');

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function badge(tipo) {
        var lbl = tipo === 'C' ? 'Cliente' : (tipo === 'F' ? 'Fornitore' : 'Altro');
        return '<span class="rp-badge rp-badge-' + esc((tipo || 's').toLowerCase()) + '">' + lbl + '</span>';
    }

    function openModal(anno, mese) {
        if (!modal) return;
        modal.hidden = false;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        mTit.textContent = 'Dettaglio per causale';
        mBody.innerHTML = '<div class="rp-loading">Caricamento…</div>';

        fetch('/report/fatturato/causali?anno=' + anno + '&mese=' + mese, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) throw new Error(d.error || 'errore');
                mTit.textContent = 'Dettaglio per causale — ' + d.periodo;
                if (!d.righe || !d.righe.length) {
                    mBody.innerHTML = '<div class="rp-empty">Nessun documento nel periodo.</div>';
                    return;
                }
                var REG = { V: 'Vendite', A: 'Acquisti' };
                var CONTO = {
                    entrate: '<span class="rp-badge rp-badge-c">Entrate</span>',
                    uscite:  '<span class="rp-badge rp-badge-f">Uscite</span>',
                    esclusa: '<span class="rp-badge rp-badge-s" title="Autofattura / integrazione del reverse charge: il costo è già negli acquisti">Esclusa – autofattura</span>'
                };
                var somma = function (c) { return d.righe.filter(function (r) { return r.conteggio === c; }).reduce(function (t, r) { return t + r.imponibile; }, 0); };
                mBody.innerHTML =
                    '<div class="rp-modal-hint">Ogni causale con il registro in cui è registrata e dove viene conteggiata. '
                  + 'Le righe del registro vendite con un fornitore come controparte sono autofatture del reverse charge: '
                  + 'non sono ricavi e restano fuori dalle entrate (il costo è già negli acquisti).</div>'
                  + '<div class="rp-table-wrap"><table class="rp-table"><thead><tr>'
                  + '<th>Causale</th><th>Registro</th><th>Controparte</th><th class="right">Doc.</th>'
                  + '<th class="right">Imponibile</th><th class="right">IVA</th><th>Conteggiata in</th>'
                  + '</tr></thead><tbody>'
                  + d.righe.map(function (r) {
                        return '<tr' + (r.conteggio === 'esclusa' ? ' style="opacity:.6"' : '') + '>'
                          + '<td>' + esc(r.descrizione ? r.descrizione + ' (' + r.causale + ')' : 'Causale ' + r.causale) + '</td>'
                          + '<td>' + esc(REG[r.registro] || r.registro) + '</td>'
                          + '<td>' + badge(r.tipo) + '</td>'
                          + '<td class="right">' + r.documenti + '</td>'
                          + '<td class="right">' + fmtEuro(r.imponibile) + '</td>'
                          + '<td class="right">' + fmtEuro(r.iva) + '</td>'
                          + '<td>' + (CONTO[r.conteggio] || '') + '</td>'
                          + '</tr>';
                    }).join('')
                  + '</tbody><tfoot>'
                  + '<tr><td colspan="4"><b>Entrate</b></td><td class="right"><b>' + fmtEuro(somma('entrate')) + '</b></td><td colspan="2"></td></tr>'
                  + '<tr><td colspan="4"><b>Uscite</b></td><td class="right"><b>' + fmtEuro(somma('uscite')) + '</b></td><td colspan="2"></td></tr>'
                  + (somma('esclusa') ? '<tr style="opacity:.7"><td colspan="4">Escluse (autofatture reverse charge)</td><td class="right">' + fmtEuro(somma('esclusa')) + '</td><td colspan="2"></td></tr>' : '')
                  + '</tfoot></table></div>';
            })
            .catch(function () {
                mBody.innerHTML = '<div class="rp-empty">Impossibile leggere il dettaglio.</div>';
            });
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('.rp-row').forEach(function (row) {
        row.addEventListener('click', function () {
            openModal(row.dataset.anno, row.dataset.mese);
        });
    });

    modal && modal.addEventListener('click', function (e) {
        if (e.target === modal || e.target.closest('[data-rp-close]')) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('is-open')) closeModal();
    });

})();

/* ── Chi ci ha pagato, chi abbiamo pagato ──────────────────────────────────
   Una riga per controparte invece di un totale solo: la domanda vera,
   guardando un fatturato, non e' "quanto" ma "con chi".
   Blocco suo: non dipende dal grafico.                                    */
(function () {
    'use strict';

    var cp = {
        corpo:    document.getElementById('rp-cp-corpo'),
        anno:     document.getElementById('rp-cp-anno'),
        mese:     document.getElementById('rp-cp-mese'),
        cerca:    document.getElementById('rp-cp-cerca'),
        registro: 'V',
        righe:    []
    };

    function euro(n) {
        return '\u20ac ' + Math.round(n || 0).toLocaleString('it-IT');
    }

    function disegnaControparti() {
        if (!cp.corpo) return;

        var filtro = (cp.cerca.value || '').trim().toLowerCase();
        var righe  = filtro
            ? cp.righe.filter(function (r) { return r.nome.toLowerCase().indexOf(filtro) !== -1; })
            : cp.righe;

        if (!righe.length) {
            cp.corpo.innerHTML = '<div class="rp-empty">' +
                (cp.righe.length ? 'Nessun nome con questo testo.' : 'Nessun movimento in questo periodo.') +
                '</div>';
            return;
        }

        // Il totale e' quello del periodo, non del filtro: la quota di
        // ciascuno deve restare confrontabile mentre si cerca.
        var totale = cp.righe.reduce(function (t, r) { return t + r.imponibile; }, 0);
        var intest = cp.registro === 'V' ? 'Cliente' : 'Fornitore';

        var corpo = righe.map(function (r) {
            var quota = totale > 0 ? (r.imponibile / totale * 100) : 0;
            var nome = (r.nome || '').trim() || ('Conto ' + r.conto);
            return '<tr>' +
                '<td><div>' + esc(nome) + '</div>' +
                    (r.piva ? '<div class="rp-cp-piva">P.IVA ' + esc(r.piva) + '</div>' : '') +
                '</td>' +
                '<td class="right rp-muted">' + r.documenti + '</td>' +
                '<td class="right"><strong>' + euro(r.imponibile) + '</strong></td>' +
                '<td class="right rp-cp-quota">' + quota.toFixed(1).replace('.', ',') + '%</td>' +
            '</tr>';
        }).join('');

        cp.corpo.innerHTML =
            '<table class="rp-table">' +
                '<thead><tr>' +
                    '<th>' + intest + '</th>' +
                    '<th class="right">Fatture</th>' +
                    '<th class="right">Imponibile</th>' +
                    '<th class="right">Quota</th>' +
                '</tr></thead>' +
                '<tbody>' + corpo + '</tbody>' +
                '<tfoot><tr>' +
                    '<td>' + righe.length + (righe.length === 1 ? ' controparte' : ' controparti') +
                        (filtro ? ' su ' + cp.righe.length : '') + '</td>' +
                    '<td class="right">' + righe.reduce(function (t, r) { return t + r.documenti; }, 0) + '</td>' +
                    '<td class="right">' + euro(righe.reduce(function (t, r) { return t + r.imponibile; }, 0)) + '</td>' +
                    '<td></td>' +
                '</tr></tfoot>' +
            '</table>';
    }

    function esc(t) {
        var d = document.createElement('div');
        d.textContent = t == null ? '' : t;
        return d.innerHTML;
    }

    function caricaControparti() {
        if (!cp.corpo) return;
        cp.corpo.innerHTML = '<div class="rp-empty">Carico\u2026</div>';

        var url = '/report/fatturato/controparti?registro=' + cp.registro +
                  '&anno=' + encodeURIComponent(cp.anno.value) +
                  '&mese=' + encodeURIComponent(cp.mese.value);

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) {
                    cp.corpo.innerHTML = '<div class="rp-empty">' + esc(d.error || 'Non disponibile') + '</div>';
                    return;
                }
                cp.righe = d.righe || [];
                disegnaControparti();
            })
            .catch(function () {
                cp.corpo.innerHTML = '<div class="rp-empty">Non riesco a leggere i dati.</div>';
            });
    }

    if (cp.corpo) {
        document.querySelectorAll('#rp-controparti .rp-switch button').forEach(function (b) {
            b.addEventListener('click', function () {
                document.querySelectorAll('#rp-controparti .rp-switch button')
                        .forEach(function (x) { x.classList.remove('on'); });
                b.classList.add('on');
                cp.registro = b.dataset.registro;
                caricaControparti();
            });
        });
        cp.anno.addEventListener('change', caricaControparti);
        cp.mese.addEventListener('change', caricaControparti);
        // la ricerca lavora su quello gia' scaricato: non si ritorna al
        // server a ogni lettera
        cp.cerca.addEventListener('input', disegnaControparti);

        caricaControparti();
    }
})();
