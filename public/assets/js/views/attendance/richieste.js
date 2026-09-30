/**
 * Presenze dichiarate: il prezzo compare solo quando serve.
 *
 * "Loro" vuol dire che ha pagato l'operaio, e all'azienda non costa niente:
 * lasciare li' un campo importo su quel caso e' un invito a scriverci un
 * numero che poi finisce nei costi del cantiere senza motivo.
 *
 * Delegato sul documento: le schede sono tante e agganciare un gestore a
 * ognuna vorrebbe dire ripassarle tutte a ogni ricaricamento.
 */
document.addEventListener('change', function (e) {
    var pasto = e.target;
    if (!pasto.classList || !pasto.classList.contains('ri-pasto')) return;

    var campi = pasto.closest('.ri-campi');
    if (!campi) return;

    // il campo prezzo e' quello subito dopo, dentro la stessa riga di campi
    var cella = pasto.closest('.ri-f');
    var prezzo = cella && cella.nextElementSibling;
    if (!prezzo || !prezzo.classList.contains('ri-prezzo')) return;

    var serve = pasto.value === 'Noi';
    prezzo.hidden = !serve;

    // svuotato quando si nasconde: un importo rimasto da una scelta
    // precedente partirebbe lo stesso al salvataggio
    if (!serve) {
        var input = prezzo.querySelector('input');
        if (input) input.value = '';
    }
});

/**
 * Rifiutare senza motivo non aiuta nessuno: l'operaio si vede la
 * dichiarazione respinta e non sa cosa correggere, quindi la rimanda uguale.
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
