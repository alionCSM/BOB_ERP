/*
 * Pubblicazione dell'app: copia del link e conferma di eliminazione.
 *
 * Legati da qui e non con onclick=""/onsubmit="" nell'HTML: la CSP di BOB
 * (script-src con nonce, senza 'unsafe-inline') blocca gli handler scritti
 * come attributo, e la conferma non comparirebbe mai — il che sarebbe
 * peggio di non averla, perche' il pulsante sembrerebbe protetto e non lo
 * sarebbe.
 */
(function () {
    'use strict';

    var copia = document.getElementById('ap-copia');
    var campo = document.getElementById('ap-url');

    if (copia && campo) {
        copia.addEventListener('click', function () {
            campo.select();
            campo.setSelectionRange(0, 99999); // iOS ignora select() da solo

            var fatto = function () {
                copia.textContent = 'Copiato';
                setTimeout(function () { copia.textContent = 'Copia'; }, 1800);
            };

            if (navigator.clipboard) {
                navigator.clipboard.writeText(campo.value).then(fatto, function () {
                    document.execCommand('copy');
                    fatto();
                });
            } else {
                document.execCommand('copy');
                fatto();
            }
        });
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        var msg  = form.dataset ? form.dataset.apConferma : null;
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
        }
    });
})();
