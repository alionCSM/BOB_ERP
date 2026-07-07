/*
 * Termina noleggio mezzi sollevamento.
 *
 * Inline onchange/onclick erano bloccati dalla CSP del sito (no
 * unsafe-inline su script). Ora tutto via addEventListener +
 * data-attributes. Nessuna funzione esposta su window.
 */
(function () {
    'use strict';

    function applyRowState(idx) {
        var chk = document.querySelector('input[data-toggle-row="' + idx + '"]');
        var qf  = document.getElementById('quantita_' + idx);
        var df  = document.getElementById('datafine_' + idx);
        if (!chk || !qf || !df) return;
        var on = chk.checked;
        qf.disabled = !on;
        df.disabled = !on;
        qf.required = on;
        df.required = on;
        // evidenzia la card selezionata
        var card = chk.closest('.rn-card');
        if (card) card.classList.toggle('is-selected', on);
        // se ho appena abilitato la data e e' vuota, preimposta oggi
        if (on && !df.value) {
            var t = new Date();
            df.value = t.getFullYear() + '-' +
                       String(t.getMonth() + 1).padStart(2, '0') + '-' +
                       String(t.getDate()).padStart(2, '0');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {

        // toggle riga: delegated change su tutti i checkbox data-toggle-row
        document.addEventListener('change', function (e) {
            var t = e.target;
            if (t && t.matches && t.matches('input[data-toggle-row]')) {
                applyRowState(t.getAttribute('data-toggle-row'));
            }
        });

        // dismiss del toast di successo
        document.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('[data-dismiss-alert]');
            if (btn && btn.parentElement) {
                btn.parentElement.style.display = 'none';
            }
        });

        // al submit, riabilita i campi obbligatori cosi' che vengano inviati
        var form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function () {
                document.querySelectorAll('input[data-toggle-row]').forEach(function (chk) {
                    if (chk.checked) {
                        var idx = chk.getAttribute('data-toggle-row');
                        var qf = document.getElementById('quantita_' + idx);
                        var df = document.getElementById('datafine_' + idx);
                        if (qf) qf.disabled = false;
                        if (df) df.disabled = false;
                    }
                });
            });
        }
    });
})();
