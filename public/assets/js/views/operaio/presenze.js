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

    chiedi();
});
