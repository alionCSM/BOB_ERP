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

    // Set initial state from the checked radio (defaults to contratto fisso)
    var checkedRadio = document.querySelector('input[name="is_consuntivo"]:checked');
    if (checkedRadio) applyTipo(checkedRadio.value === '1');

    document.querySelectorAll('input[name="is_consuntivo"]').forEach(function (radio) {
        radio.addEventListener('click', function () {
            applyTipo(this.value === '1');
        });
    });
    // ─────────────────────────────────────────────────────────────────────────

        const clientSelect = document.getElementById("client_search");
        if (clientSelect) {
            new TomSelect(clientSelect, {
                valueField: 'id',
                labelField: 'name',
                searchField: ['name'],
                create: false,
                load: function (query, callback) {
                    if (!query.length) return callback();
                    fetch('/clients/search?query=' + encodeURIComponent(query))
                        .then(response => response.json())
                        .then(data => callback(data))
                        .catch(() => callback());
                },
                placeholder: 'Cerca un cliente...',
            });
        }

        new TomSelect("#offer_number_select", {
            valueField: 'offer_number',
            labelField: 'text', // cambia qui!
            searchField: ['offer_number'],
            create: true,
            load: function (query, callback) {
                if (!query.length) return callback();
                fetch('/offers/search?query=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => callback(data))
                    .catch(() => callback());
            },
            placeholder: 'Cerca o inserisci un numero offerta...',
        });

    });
