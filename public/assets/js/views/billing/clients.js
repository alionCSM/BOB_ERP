document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('search-input');
    const rows  = document.querySelectorAll('.client-row');
    const noRes = document.getElementById('bc-no-results');
    const count = document.getElementById('bc-count');
    if (!input) return;

    input.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        let visible = 0;
        rows.forEach(function (r) {
            const match = !q || r.dataset.name.includes(q);
            r.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        if (count) count.textContent = visible;
        if (noRes) noRes.style.display = visible === 0 ? '' : 'none';
    });

    // ── Prospetto fatto modal ────────────────────────────────────────────
    const modal       = document.getElementById('bc-prospetto-modal');
    const periodLabel = document.getElementById('bc-prospetto-period-label');
    const clientName  = document.getElementById('bc-prospetto-client-name');
    const monthSel    = document.getElementById('bc-prospetto-month');
    const yearSel     = document.getElementById('bc-prospetto-year');
    const errBox      = document.getElementById('bc-prospetto-err');
    const changeLink  = document.getElementById('bc-prospetto-change-link');
    const periodPick  = document.getElementById('bc-prospetto-period-selector');
    const btnConfirm  = document.getElementById('bc-prospetto-confirm');
    const btnUnmark   = document.getElementById('bc-prospetto-unmark');
    if (!modal) return;

    const MESI = ['', 'Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];

    let currentClientId   = null;
    let currentClientName = '';
    let isAlreadyDone     = false;

    function updatePeriodLabel() {
        const m = parseInt(monthSel.value, 10);
        const y = parseInt(yearSel.value, 10);
        periodLabel.textContent = MESI[m] + ' ' + y;
    }

    function openModal(btn) {
        currentClientId   = btn.dataset.clientId;
        currentClientName = btn.dataset.clientName || '';
        isAlreadyDone     = btn.classList.contains('bc-prospetto-chip');

        clientName.textContent = currentClientName;

        // If row already marked → pre-select that period; else suggested
        if (isAlreadyDone && btn.dataset.doneMonth && btn.dataset.doneYear) {
            monthSel.value = btn.dataset.doneMonth;
            yearSel.value  = btn.dataset.doneYear;
        } else {
            monthSel.value = modal.dataset.suggestedMonth;
            yearSel.value  = modal.dataset.suggestedYear;
        }
        updatePeriodLabel();

        periodPick.style.display = 'none';
        changeLink.textContent   = 'Clicca qui per cambiare mese';
        errBox.style.display     = 'none';
        btnUnmark.style.display  = isAlreadyDone ? '' : 'none';
        btnConfirm.disabled      = false;

        modal.style.display = 'flex';
    }
    function closeModal() {
        modal.style.display = 'none';
    }

    document.addEventListener('click', function (e) {
        const opener = e.target.closest('[data-action="open-prospetto-modal"]');
        if (opener) { openModal(opener); return; }
        const closer = e.target.closest('[data-close]');
        if (closer && modal.contains(closer)) { closeModal(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
    });

    changeLink.addEventListener('click', function () {
        const isOpen = periodPick.style.display !== 'none';
        periodPick.style.display = isOpen ? 'none' : '';
        changeLink.textContent   = isOpen ? 'Clicca qui per cambiare mese' : 'Nascondi';
    });
    monthSel.addEventListener('change', updatePeriodLabel);
    yearSel.addEventListener('change',  updatePeriodLabel);

    function postJSON(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body),
        }).then(r => r.json());
    }

    btnConfirm.addEventListener('click', function () {
        const m = parseInt(monthSel.value, 10);
        const y = parseInt(yearSel.value, 10);
        errBox.style.display = 'none';
        btnConfirm.disabled  = true;

        postJSON('/billing/client/' + currentClientId + '/mark-prospetto-done', { year: y, month: m })
            .then(res => {
                if (!res.ok) {
                    errBox.textContent = res.error || 'Errore';
                    errBox.style.display = 'block';
                    btnConfirm.disabled = false;
                    return;
                }
                location.reload();
            })
            .catch(() => {
                errBox.textContent = 'Errore di rete';
                errBox.style.display = 'block';
                btnConfirm.disabled = false;
            });
    });

    btnUnmark.addEventListener('click', function () {
        if (!confirm('Rimuovere la marcatura "prospetto fatto" per ' + MESI[monthSel.value] + ' ' + yearSel.value + '?')) return;
        const m = parseInt(monthSel.value, 10);
        const y = parseInt(yearSel.value, 10);
        btnUnmark.disabled = true;
        postJSON('/billing/client/' + currentClientId + '/unmark-prospetto-done', { year: y, month: m })
            .then(res => {
                if (!res.ok) {
                    errBox.textContent = res.error || 'Errore';
                    errBox.style.display = 'block';
                    btnUnmark.disabled = false;
                    return;
                }
                location.reload();
            })
            .catch(() => {
                errBox.textContent = 'Errore di rete';
                errBox.style.display = 'block';
                btnUnmark.disabled = false;
            });
    });
});
