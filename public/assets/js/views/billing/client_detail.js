document.addEventListener('DOMContentLoaded', function () {
    // ── Search in Da Emettere ─────────────────────────────────────────────────
    var searchDe = document.getElementById('search-da-emettere');
    if (searchDe) {
        searchDe.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            document.querySelectorAll('.da-emettere-row').forEach(function (r) {
                r.style.display = (!q || r.dataset.cantiere.includes(q)) ? '' : 'none';
            });
        });
    }

    // ── Load More Emesse ─────────────────────────────────────────────────────
    var btn = document.getElementById('load-more-btn');
    if (!btn) return;

    btn.addEventListener('click', function () {
        var clientId = btn.dataset.client;
        var page     = parseInt(btn.dataset.page, 10);
        var perPage  = parseInt(btn.dataset.perPage, 10);
        var total    = parseInt(btn.dataset.total, 10);

        btn.disabled  = true;
        btn.innerHTML = 'Caricamento\u2026';

        fetch('/billing/client/' + clientId + '/emesse?page=' + page)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var tbody = document.getElementById('emesse-tbody');

                data.rows.forEach(function (r) {
                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td class="bd-muted" style="white-space:nowrap;">' + esc(r.data || '') + '</td>' +
                        '<td><a href="/worksites/' + (r.worksite_id || '') + '" class="bd-link">' + esc(r.cantiere || '') + '</a></td>' +
                        '<td class="bd-muted" style="white-space:nowrap;">' + esc(r.order_number || '\u2014') + '</td>' +
                        '<td style="font-size:12px;color:#64748b;">' + esc(r.descrizione || '') + '</td>' +
                        '<td class="right">\u20ac ' + fmt(r.totale_imponibile) + '</td>' +
                        '<td class="right bd-muted">' + esc(r.aliquota_iva || '') + '%</td>';
                    tbody.appendChild(tr);
                });

                var loaded = page * perPage;
                if (data.has_more) {
                    btn.dataset.page = page + 1;
                    btn.disabled = false;
                    var rem = total - loaded;
                    btn.innerHTML =
                        '<svg viewBox="0 0 24 24" style="width:15px;height:15px;stroke:currentColor;fill:none;vertical-align:middle;margin-right:4px;">' +
                        '<polyline points="7 13 12 18 17 13"/><polyline points="7 6 12 11 17 6"/></svg>' +
                        'Carica altri ' + rem + ' fatture';
                } else {
                    var wrap = document.getElementById('load-more-wrap');
                    if (wrap) wrap.remove();
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = 'Errore, riprova';
            });
    });

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function fmt(n) {
        return parseFloat(n || 0).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
});
