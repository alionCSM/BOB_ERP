document.addEventListener('DOMContentLoaded', function () {

    // ── Contract type toggle ──────────────────────────────────────────────────
    var ppWrap = document.getElementById('prezzo_persona_wrap');
    var toWrap = document.getElementById('total_offer_wrap');
    var tfInput = document.getElementById('total_offer');
    var ppInput = document.getElementById('prezzo_persona');

    function applyTipo(isConsuntivo) {
        if (ppWrap) ppWrap.style.display = isConsuntivo ? 'block' : 'none';
        if (toWrap) toWrap.style.display = isConsuntivo ? 'none'  : 'block';
        if (tfInput) {
            tfInput.required = !isConsuntivo;
            tfInput.disabled = isConsuntivo;
        }
        if (ppInput) {
            ppInput.required = isConsuntivo;
            ppInput.disabled = !isConsuntivo;
        }
    }

    // Set initial state from the checked radio
    var checkedRadio = document.querySelector('input[name="is_consuntivo"]:checked');
    if (checkedRadio) applyTipo(checkedRadio.value === '1');

    // Since radios are visually hidden (opacity:0), clicking the label is how users interact
    document.querySelectorAll('label.wcf-type-label').forEach(function (label) {
        label.addEventListener('click', function () {
            var radioId = this.getAttribute('for');
            if (radioId) {
                var radio = document.getElementById(radioId);
                if (radio) {
                    radio.checked = true;
                    applyTipo(radio.value === '1');
                }
            }
        });
    });

    // Also handle direct change events as fallback
    document.querySelectorAll('input[name="is_consuntivo"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            applyTipo(this.value === '1');
        });
    });
    // ─────────────────────────────────────────────────────────────────────────

    // ── Client search (TomSelect) ─────────────────────────────────────────────
    var clientSelect = document.getElementById('client_search');
    if (clientSelect) {
        new TomSelect(clientSelect, {
            valueField: 'id',
            labelField: 'name',
            searchField: ['name'],
            create: false,
            load: function (query, callback) {
                if (!query.length) return callback();
                fetch('/clients/search?query=' + encodeURIComponent(query))
                    .then(function (r) { return r.json(); })
                    .then(function (data) { callback(data); })
                    .catch(function () { callback(); });
            },
            placeholder: 'Cerca un cliente...',
        });
    }

    // ── Offer number search (TomSelect) ───────────────────────────────────────
    var offerSelect = document.getElementById('offer_number_select');
    if (offerSelect) {
        new TomSelect(offerSelect, {
            valueField: 'offer_number',
            labelField: 'offer_number',
            searchField: ['offer_number'],
            create: true,
            load: function (query, callback) {
                if (!query.length) return callback();
                fetch('/offers/search?query=' + encodeURIComponent(query))
                    .then(function (r) { return r.json(); })
                    .then(function (data) { callback(data); })
                    .catch(function () { callback(); });
            },
            placeholder: 'Cerca o inserisci un numero offerta...',
        });
    }

    // ── End date visibility on status change ──────────────────────────────────
    var statusSelect = document.getElementById('status_select');
    if (statusSelect) {
        statusSelect.addEventListener('change', function () {
            var wrapper = document.getElementById('end_date_wrapper');
            if (wrapper) wrapper.style.display = this.value === 'Completato' ? 'block' : 'none';
        });
    }
});
