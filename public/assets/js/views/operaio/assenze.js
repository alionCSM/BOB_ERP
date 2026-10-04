/**
 * I campi che servono solo a un tipo di assenza.
 *
 * Le ore valgono per il permesso, il numero del certificato per la
 * malattia. Mostrarli sempre vuol dire chiedere a tutti due cose che a
 * quasi nessuno servono, su uno schermo dove ogni campo in piu' e' uno
 * scorrimento in piu'.
 *
 * Quello che si nasconde si svuota: un numero rimasto da una scelta
 * precedente partirebbe lo stesso al salvataggio e finirebbe attaccato a
 * una ferie. Il server lo butta via comunque, ma qui non deve nemmeno
 * partire.
 *
 * Niente onclick negli attributi: la CSP di BOB non ha 'unsafe-inline'.
 */
document.addEventListener('DOMContentLoaded', function () {
    var tipi  = document.querySelectorAll('[data-tipo]');
    var campi = document.querySelectorAll('[data-solo]');
    if (!tipi.length || !campi.length) return;

    function aggiorna() {
        var scelto = document.querySelector('[data-tipo]:checked');
        var tipo   = scelto ? scelto.value : '';

        campi.forEach(function (c) {
            var serve = c.getAttribute('data-solo') === tipo;
            c.hidden = !serve;
            if (!serve) {
                c.querySelectorAll('input, textarea').forEach(function (i) { i.value = ''; });
            }
        });
    }

    tipi.forEach(function (t) { t.addEventListener('change', aggiorna); });
    aggiorna();
});
