/**
 * Rifiutare senza motivo non aiuta nessuno.
 *
 * L'operaio si vede la richiesta respinta, non sa cosa correggere, e la
 * rimanda uguale — oppure viene in ufficio a chiedere perche', che e' la
 * telefonata che questa pagina doveva evitare.
 *
 * Vale per le presenze dichiarate e per le ferie: stesso bottone, stesso
 * campo, stesso problema. Delegato sul documento, cosi' funziona su tutte le
 * schede senza agganciare un gestore a ognuna.
 */
document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[value="rifiuta"]');
    if (!btn) return;

    var form = btn.closest('form');
    var motivo = form && form.querySelector('.ri-motivo');

    if (motivo && motivo.value.trim() === '') {
        e.preventDefault();
        motivo.focus();
        motivo.classList.add('is-manca');
        setTimeout(function () { motivo.classList.remove('is-manca'); }, 1200);
    }
});
