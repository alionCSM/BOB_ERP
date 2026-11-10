/* BOB Zone, tutti i cantieri: ricerca, filtri e ordinamento sulla pagina */
(function () {
    'use strict';
    const righe   = [...document.querySelectorAll('#zl-righe tr[data-cerca]')];
    const cerca   = document.getElementById('zl-cerca');
    const stato   = document.getElementById('zl-stato');
    const mostra  = document.getElementById('zl-mostra');
    const conta   = document.getElementById('zl-conta');
    const nessuno = document.getElementById('zl-nessuno');
    const corpo   = document.getElementById('zl-righe');
    if (!corpo) return;

    // i filtri restano quando si torna sulla pagina
    try {
        const s = JSON.parse(sessionStorage.getItem('zl-filtri') || '{}');
        if (s.q) cerca.value = s.q;
        if (s.stato) stato.value = s.stato;
        if (s.mostra) mostra.value = s.mostra;
    } catch (_) {}

    function filtra() {
        const q = cerca.value.trim().toLowerCase();
        const parole = q ? q.split(/\s+/) : [];
        let n = 0;
        righe.forEach(r => {
            const d = r.dataset;
            let ok = parole.every(p => d.cerca.includes(p));
            if (ok && stato.value) ok = d.status === stato.value;
            if (ok && mostra.value === 'segnalazioni') ok = Number(d.segnalazioni) > 0;
            if (ok && mostra.value === 'aperte') ok = Number(d.aperte) > 0;
            if (ok && mostra.value === 'cliente') ok = d.clienteOn === '1';
            if (ok && mostra.value === 'mio') ok = d.mio === '1';
            r.hidden = !ok;
            if (ok) n++;
        });
        conta.textContent = n;
        if (nessuno) nessuno.hidden = n > 0 || !righe.length;
        try { sessionStorage.setItem('zl-filtri', JSON.stringify({ q: cerca.value, stato: stato.value, mostra: mostra.value })); } catch (_) {}
    }

    // ordinamento: un clic sull'intestazione, un altro per invertire
    let campo = null, verso = 1;
    const numerici = ['aperte', 'segnalazioni', 'file', 'disegni', 'persone'];
    document.querySelectorAll('.zl-table th[data-ordina]').forEach(th => th.addEventListener('click', () => {
        const k = th.dataset.ordina;
        verso = campo === k ? -verso : (numerici.includes(k) || k === 'ultima' ? -1 : 1);
        campo = k;
        document.querySelectorAll('.zl-table th').forEach(x => x.classList.remove('zl-su', 'zl-giu'));
        th.classList.add(verso === 1 ? 'zl-su' : 'zl-giu');
        const val = r => numerici.includes(k) ? Number(r.dataset[k]) : (r.dataset[k] || '');
        righe.sort((a, b) => {
            const x = val(a), y = val(b);
            return (x < y ? -1 : x > y ? 1 : 0) * verso;
        });
        righe.forEach(r => corpo.insertBefore(r, nessuno));
    }));

    cerca.addEventListener('input', filtra);
    stato.addEventListener('change', filtra);
    mostra.addEventListener('change', filtra);
    document.getElementById('zl-reset')?.addEventListener('click', () => {
        cerca.value = ''; stato.value = ''; mostra.value = '';
        filtra();
        cerca.focus();
    });
    filtra();
})();
