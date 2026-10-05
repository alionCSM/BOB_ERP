/**
 * Il cantiere: prima lo diciamo noi, poi semmai lo cerca lui.
 *
 * Una tendina con dentro tutti i cantieri aperti e' inutilizzabile su un
 * telefono, e soprattutto e' la domanda sbagliata: nove volte su dieci la
 * risposta la sappiamo gia', perche' quel giorno era in pianificazione.
 *
 * Quindi: scelto il giorno, si chiede al server dove risultava. Se lo sa,
 * compare una riga sola da confermare e il campo e' gia' compilato. Se non
 * lo sa, o se non era quello, si apre la ricerca — che interroga il server
 * solo dopo due lettere, perche' in cantiere la linea va piano e mandare
 * trecento cantieri a ogni apertura di tendina e' tempo rubato.
 *
 * Il valore sta sempre nel campo nascosto: la ricerca non ha un `name`
 * proprio, altrimenti due campi con lo stesso nome si pesterebbero.
 */
document.addEventListener('DOMContentLoaded', function () {
    var data     = document.getElementById('p-data');
    var proposta = document.getElementById('p-proposta');
    var cerca    = document.getElementById('p-cerca');
    var scelto   = document.getElementById('p-scelto');
    if (!data || !proposta || !cerca || !scelto) return;

    var nome = proposta.querySelector('.op-proposta-n');
    var no   = proposta.querySelector('.op-proposta-no');

    var ts = new TomSelect('#p-cantiere', {
        valueField: 'id',
        labelField: 'testo',
        searchField: 'testo',
        maxOptions: 50,
        loadThrottle: 350,
        // niente elenco prima che scriva: e' il punto di tutto
        shouldLoad: function (q) { return q.length >= 2; },
        load: function (q, callback) {
            fetch('/io/cantieri?q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(callback)
                .catch(function () { callback(); });
        },
        onChange: function (v) { scelto.value = v || ''; },
    });

    function mostraRicerca() {
        proposta.hidden = true;
        cerca.hidden    = false;
        scelto.value    = '';
        ts.clear();
        ts.clearOptions();
    }

    function mostraProposta(c) {
        nome.textContent = (c.codice ? c.codice + ' — ' : '') + c.nome +
                           (c.luogo ? ' (' + c.luogo + ')' : '');
        scelto.value     = c.id;
        proposta.hidden  = false;
        cerca.hidden     = true;
    }

    function chiedi() {
        if (!data.value) { mostraRicerca(); return; }

        fetch('/io/cantiere-del-giorno?data=' + encodeURIComponent(data.value))
            .then(function (r) { return r.json(); })
            .then(function (c) {
                if (c && c.trovato) { mostraProposta(c); } else { mostraRicerca(); }
            })
            // se la richiesta non riesce si apre la ricerca: meglio farlo
            // cercare che lasciarlo davanti a un campo che non fa niente
            .catch(mostraRicerca);
    }

    data.addEventListener('change', chiedi);
    no.addEventListener('click', function () {
        mostraRicerca();
        ts.focus();
    });

    // "Scegli" sulle giornate senza cantiere: apre il modulo gia' su quel
    // giorno, invece di farlo ridigitare dopo che gliel'abbiamo appena
    // mostrato scritto sopra il bottone
    var modulo = document.getElementById('op-modulo');
    document.querySelectorAll('[data-apri-modulo]').forEach(function (b) {
        b.addEventListener('click', function () {
            data.value = b.getAttribute('data-apri-modulo');
            if (modulo) modulo.open = true;
            chiedi();
            modulo.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    chiedi();
});

/**
 * Il mese e la ricerca nell'elenco.
 *
 * Il mese si manda da solo al cambio: un bottone "Vai" accanto a una tendina
 * e' un tocco in piu' per fare la cosa che uno ha gia' detto di voler fare.
 * Chi ha JavaScript spento vede il bottone, che sta in un <noscript>.
 *
 * La ricerca filtra quello che c'e' gia' in pagina, senza richiamare il
 * server: il mese limita le righe a una trentina, e su un telefono in
 * cantiere una ricerca che va a colpo sicuro vale piu' di una che e'
 * completa ma aspetta la linea.
 */
document.addEventListener('DOMContentLoaded', function () {
    var mese = document.getElementById('op-mese-sel');
    if (mese) {
        mese.addEventListener('change', function () { mese.form.submit(); });
    }

    var filtro = document.getElementById('op-filtro');
    var elenco = document.getElementById('op-elenco');
    var niente = document.getElementById('op-niente');
    if (!filtro || !elenco) return;

    filtro.addEventListener('input', function () {
        var q = filtro.value.trim().toLowerCase();
        var visti = 0;

        elenco.querySelectorAll('.op-voce').forEach(function (v) {
            var ok = q === '' || (v.getAttribute('data-cerca') || '').indexOf(q) !== -1;
            v.hidden = !ok;
            if (ok) visti++;
        });

        if (niente) niente.hidden = visti > 0;
    });
});
