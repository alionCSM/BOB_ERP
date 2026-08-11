/*
 * Andamento fatturato — grafico multi-anno + dettaglio causali.
 * Chart.js e' gia' incluso nel bundle del template (app.js).
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
                mBody.innerHTML =
                    '<div class="rp-modal-hint">Da qui si verifica che i totali siano corretti: '
                  + 'se una causale (per esempio le note di credito) somma invece di sottrarre, si vede qui.</div>'
                  + '<div class="rp-table-wrap"><table class="rp-table"><thead><tr>'
                  + '<th>Causale</th><th>Tipo</th><th class="right">Doc.</th>'
                  + '<th class="right">Imponibile</th><th class="right">Totale</th>'
                  + '</tr></thead><tbody>'
                  + d.righe.map(function (r) {
                        return '<tr>'
                          + '<td>' + esc(r.descrizione || ('cod. ' + r.causale)) + '</td>'
                          + '<td>' + badge(r.tipo) + '</td>'
                          + '<td class="right">' + r.documenti + '</td>'
                          + '<td class="right">' + fmtEuro(r.imponibile) + '</td>'
                          + '<td class="right">' + fmtEuro(r.totale) + '</td>'
                          + '</tr>';
                    }).join('')
                  + '</tbody></table></div>';
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
