// Auto-fill username from email
document.getElementById('email').addEventListener('blur', function() {
    const usernameField = document.getElementById('username');
    if (usernameField.value === '' && this.value !== '') {
        usernameField.value = this.value.toLowerCase().trim();
    }
});


// ── "Azienda" segue il profilo di accesso ───────────────────────────────────
// Il campo vuol dire una cosa diversa per ogni profilo: societa' del gruppo
// per un interno, consorziata per un'azienda, cliente per un cliente, e per
// un operaio non si sceglie — arriva dalla sua anagrafica.
//
// I quattro campi esistono tutti nella pagina e si mostra quello che serve.
// Quelli nascosti si disabilitano: un select nascosto invia comunque il suo
// valore, e cambiando profilo dopo aver scelto si finirebbe per mandare al
// server due legami diversi, con il rischio di scrivere quello sbagliato.
(function () {
    var profilo = document.getElementById('access_profile');
    if (!profilo) return;

    var campi   = document.querySelectorAll('[data-azienda]');
    var operaio = document.getElementById('worker_id');
    var etichettaAzienda = document.getElementById('azienda-operaio');

    function mostraAziendaOperaio() {
        if (!operaio || !etichettaAzienda) return;
        var scelta = operaio.options[operaio.selectedIndex];
        var nome   = scelta ? scelta.getAttribute('data-azienda-operaio') : '';
        etichettaAzienda.textContent = nome ? 'Azienda: ' + nome : '';
    }

    function applica() {
        campi.forEach(function (campo) {
            var suo = campo.getAttribute('data-azienda') === profilo.value;
            campo.hidden = !suo;

            // il valore di un campo nascosto non deve partire
            campo.querySelectorAll('select, input').forEach(function (c) {
                c.disabled = !suo;
            });
        });
        mostraAziendaOperaio();
    }

    profilo.addEventListener('change', applica);
    if (operaio) operaio.addEventListener('change', mostraAziendaOperaio);

    applica();   // al caricamento e dopo un errore, con i valori gia' scelti
})();
