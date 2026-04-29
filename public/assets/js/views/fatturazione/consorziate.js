// ── Index page: update period form action before modal opens ──────────────────
//    Midone opens the modal via data-tw-toggle; we just patch the form first.

document.addEventListener('click', function (e) {
    // Update form action when a "Seleziona periodo" row button is clicked
    var btn = e.target.closest('[data-action="update-period-form"]');
    if (btn) {
        var form  = document.getElementById('period-form');
        var title = document.getElementById('modal-title');
        if (form)  form.action       = '/fatturazione/consorziate/' + btn.dataset.id;
        if (title) title.textContent = btn.dataset.name || 'Seleziona Periodo';
    }

    // Storico accordion toggle (show page)
    if (e.target.closest('[data-action="toggle-storico"]')) {
        toggleStorico();
    }
});

// ── Index page: live search filter ────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('fc-search');
    var table = document.getElementById('fc-table');
    if (!input || !table) return;

    input.addEventListener('input', function () {
        var q = (input.value || '').trim().toLowerCase();
        var rows = table.querySelectorAll('tbody tr[data-search]');
        rows.forEach(function (tr) {
            var hay = tr.dataset.search || '';
            tr.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
        });
    });
});

// ── Confirm delete forms ──────────────────────────────────────────────────────

document.addEventListener('submit', function (e) {
    var msg = e.target.dataset.confirm;
    if (msg && !confirm(msg)) {
        e.preventDefault();
    }
});

// ── Show page: storico accordion ──────────────────────────────────────────────

function toggleStorico() {
    var body    = document.getElementById('storico-body');
    var chevron = document.getElementById('storico-chevron');
    if (!body) return;

    var hidden = body.classList.toggle('hidden');
    if (chevron) {
        chevron.style.transform = hidden ? '' : 'rotate(180deg)';
    }
}

// ── Show page: live row calculations ─────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
    var rows     = document.querySelectorAll('.detail-row');
    var saveBtn  = document.getElementById('save-btn');
    var totResEl = document.getElementById('tot-residuo');
    var totDapEl = document.getElementById('tot-da-pagare');

    if (!rows.length) return;

    // Wire up inputs
    rows.forEach(function (row) {
        var input = row.querySelector('.fc-importo-input');
        if (input) input.addEventListener('input', onInputChange);
    });

    // Initial footer render
    updateFooter();

    function onInputChange() {
        updateFooter();
        updateSaveButton();
    }

    function updateFooter() {
        var sumResiduo  = 0;
        var sumDaPagare = 0;

        rows.forEach(function (row) {
            var valore  = parseFloat(row.dataset.valore || 0) || 0;
            var pagato  = parseFloat(row.dataset.pagato || 0) || 0;
            var spese   = parseFloat(row.dataset.spese  || 0) || 0;
            sumResiduo += valore - pagato - spese;

            var input = row.querySelector('.fc-importo-input');
            if (input) {
                var v = parseFloat((input.value || '').replace(',', '.')) || 0;
                if (v > 0) sumDaPagare += v;
            }
        });

        if (totResEl) {
            totResEl.textContent = '€ ' + fmt(sumResiduo);
            totResEl.className   = 'right' + (sumResiduo < 0 ? ' fp-danger' : '');
        }
        if (totDapEl) {
            totDapEl.textContent = sumDaPagare > 0 ? '€ ' + fmt(sumDaPagare) : '—';
            totDapEl.className   = 'right' + (sumDaPagare > 0 ? ' fp-success' : '');
        }
    }

    function updateSaveButton() {
        if (!saveBtn) return;
        var hasAny = false;
        rows.forEach(function (row) {
            var input = row.querySelector('.fc-importo-input');
            if (!input) return;
            var v = parseFloat((input.value || '').replace(',', '.')) || 0;
            if (v > 0) hasAny = true;
        });
        saveBtn.disabled = !hasAny;
    }

    // Guard on submit
    var form = document.getElementById('payment-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            var dateEl = document.getElementById('data_pagamento');
            if (!dateEl || !dateEl.value) {
                e.preventDefault();
                alert('Inserire la data del pagamento.');
                if (dateEl) dateEl.focus();
                return;
            }
            var hasAny = false;
            rows.forEach(function (row) {
                var input = row.querySelector('.fc-importo-input');
                if (!input) return;
                var v = parseFloat((input.value || '').replace(',', '.')) || 0;
                if (v > 0) hasAny = true;
            });
            if (!hasAny) {
                e.preventDefault();
                alert('Inserire almeno un importo da pagare.');
            }
        });
    }

    function fmt(n) {
        return (parseFloat(n) || 0).toLocaleString('it-IT', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
});
