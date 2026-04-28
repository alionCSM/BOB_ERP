document.addEventListener('DOMContentLoaded', function () {
    const yearSelect   = document.getElementById('year-filter');
    const monthSelect  = document.getElementById('month-filter');
    const searchInput  = document.getElementById('search-input');
    const tableBody    = document.getElementById('worksiteTable');
    const exportBtn    = document.getElementById('export-excel');
    const kpiRow       = document.getElementById('kpi-row');
    const kpiCantieri  = document.getElementById('kpi-cantieri');
    const kpiOfferta   = document.getElementById('kpi-offerta');
    const kpiFatturato = document.getElementById('kpi-fatturato');
    const kpiResiduo   = document.getElementById('kpi-residuo');
    const bawCount     = document.getElementById('baw-count');

    const months = [
        'Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
        'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'
    ];

    // Populate year/month selects
    const currentYear = new Date().getFullYear();
    for (let y = currentYear; y >= currentYear - 5; y--) {
        const opt = document.createElement('option');
        opt.value = y; opt.textContent = y;
        yearSelect.appendChild(opt);
    }
    months.forEach((m, i) => {
        const opt = document.createElement('option');
        opt.value = i + 1; opt.textContent = m;
        monthSelect.appendChild(opt);
    });

    // Read URL params
    const params       = new URLSearchParams(window.location.search);
    const defaultYear  = parseInt(params.get('year')  || currentYear);
    const defaultMonth = parseInt(params.get('month') || (new Date().getMonth() + 1));
    yearSelect.value  = defaultYear;
    monthSelect.value = defaultMonth;

    let allRows = [];

    yearSelect.addEventListener('change',  updateUrlAndLoad);
    monthSelect.addEventListener('change', updateUrlAndLoad);
    searchInput.addEventListener('input',  renderFiltered);

    updateExcelLink(defaultYear, defaultMonth);
    loadData(defaultYear, defaultMonth);

    function updateUrlAndLoad() {
        const y = yearSelect.value, m = monthSelect.value;
        const url = new URL(window.location.href);
        url.searchParams.set('year', y);
        url.searchParams.set('month', m);
        window.history.pushState({}, '', url.toString());
        updateExcelLink(y, m);
        loadData(y, m);
    }

    function loadData(y, m) {
        tableBody.innerHTML = `<tr><td colspan="6" class="baw-loading">Caricamento…</td></tr>`;
        kpiRow.style.display = 'none';

        fetch(`/billing/fetch?year=${y}&month=${m}`)
            .then(r => r.json())
            .then(data => {
                allRows = data;
                searchInput.value = '';
                renderFiltered();
            })
            .catch(() => {
                tableBody.innerHTML = `<tr><td colspan="6" class="baw-empty"><p class="baw-empty-text" style="color:#dc2626;">Errore nel caricamento dati</p></td></tr>`;
                kpiRow.style.display = 'none';
            });
    }

    function renderFiltered() {
        const q = searchInput.value.trim().toLowerCase();
        const rows = q
            ? allRows.filter(r =>
                (r.cliente     || '').toLowerCase().includes(q) ||
                (r.name        || '').toLowerCase().includes(q) ||
                (r.order_number|| '').toLowerCase().includes(q)
              )
            : allRows;

        if (bawCount) bawCount.textContent = rows.length;

        if (rows.length === 0) {
            const msg = q
                ? `Nessun risultato per &ldquo;${escHtml(q)}&rdquo;`
                : 'Nessun cantiere movimentato in questo periodo';
            tableBody.innerHTML = `<tr><td colspan="6" class="baw-empty"><p class="baw-empty-text">${msg}</p></td></tr>`;
            kpiRow.style.display = 'none';
            return;
        }

        // KPIs
        const totOfferta   = rows.reduce((s, r) => s + parseFloat(r.total_offer      || 0), 0);
        const totFatturato = rows.reduce((s, r) => s + parseFloat(r.totale_fatturato || 0), 0);
        const totResiduo   = rows.reduce((s, r) => s + parseFloat(r.residuo          || 0), 0);

        kpiCantieri.textContent  = rows.length;
        kpiOfferta.textContent   = eur(totOfferta);
        kpiFatturato.textContent = eur(totFatturato);
        kpiResiduo.textContent   = eur(totResiduo);
        kpiRow.style.display     = '';

        tableBody.innerHTML = rows.map(row => {
            const residuo    = parseFloat(row.residuo || 0);
            const residuoCls = residuo > 0 ? 'baw-residuo-pos' : 'baw-residuo-zero';
            return `
            <tr>
                <td class="baw-muted" style="font-size:13px;">${escHtml(row.cliente || '')}</td>
                <td><a href="/worksites/${row.id}" class="baw-name">${escHtml(row.name || '')}</a></td>
                <td class="center"><span class="baw-code">${escHtml(row.order_number || '—')}</span></td>
                <td class="right baw-price">${eur(row.total_offer)}</td>
                <td class="right baw-price">${eur(row.totale_fatturato)}</td>
                <td class="right ${residuoCls}">${eur(row.residuo)}</td>
            </tr>`;
        }).join('');
    }

    function updateExcelLink(y, m) {
        exportBtn.href = `/billing/export?year=${y}&month=${m}`;
    }

    function eur(n) {
        return parseFloat(n || 0).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
    }

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
});
