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
 * Il cantiere, prima di approvare.
 *
 * L'operaio non cerca fra i cantieri: quando quello della pianificazione non
 * e' giusto scrive dov'era, e qui l'ufficio sceglie quello vero. La ricerca
 * e' la stessa dei cantieri di BOB; la scelta finisce nel campo nascosto
 * worksite_id del modulo, e l'approvazione registra la presenza li'.
 */
(function () {
    var attesa = null;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function cerca(box) {
        var input = box.querySelector('.ri-cs-cerca input');
        var out = box.querySelector('.ri-cs-risultati');
        var q = input.value.trim();
        if (q.length < 2) { out.innerHTML = '<div class="ri-cs-vuoto">Scrivi almeno due lettere.</div>'; return; }
        fetch('/api/worksites/search?status=all&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (righe) {
                if (!Array.isArray(righe) || !righe.length) {
                    out.innerHTML = '<div class="ri-cs-vuoto">Nessun cantiere trovato.</div>';
                    return;
                }
                out.innerHTML = righe.slice(0, 15).map(function (w) {
                    var nome = (w.worksite_code ? w.worksite_code + ' — ' : '') + (w.worksite_name || '');
                    return '<button type="button" class="ri-cs-risultato" data-id="' + w.id + '" data-nome="' + esc(nome) + '">'
                        + esc(nome) + (w.location ? ' <small>' + esc(w.location) + '</small>' : '') + '</button>';
                }).join('');
            })
            .catch(function () { out.innerHTML = '<div class="ri-cs-vuoto">Ricerca non riuscita.</div>'; });
    }

    document.addEventListener('click', function (e) {
        var cambia = e.target.closest('.ri-cs-cambia');
        if (cambia) {
            var box = cambia.closest('.ri-cantiere-scelta');
            var cerca_ = box.querySelector('.ri-cs-cerca');
            cerca_.hidden = !cerca_.hidden;
            if (!cerca_.hidden) {
                var input = cerca_.querySelector('input');
                input.focus();
                if (input.value.trim().length >= 2) cerca(box);
            }
            return;
        }
        var scelto = e.target.closest('.ri-cs-risultato');
        if (scelto) {
            var b = scelto.closest('.ri-cantiere-scelta');
            b.querySelector('input[name="worksite_id"]').value = scelto.dataset.id;
            b.querySelector('.ri-cs-nome').textContent = scelto.dataset.nome;
            b.querySelector('.ri-cs-cerca').hidden = true;
            b.querySelector('.ri-cs-cambia').textContent = 'Cambia';
            b.classList.remove('da-scegliere');
        }
    });

    document.addEventListener('input', function (e) {
        if (!e.target.matches('.ri-cs-cerca input')) return;
        var box = e.target.closest('.ri-cantiere-scelta');
        clearTimeout(attesa);
        attesa = setTimeout(function () { cerca(box); }, 250);
    });

    // Senza cantiere non si approva: meglio dirlo qui che con un errore dopo
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.classList || !form.classList.contains('ri-form')) return;
        if (!e.submitter || e.submitter.value !== 'approva') return;
        var ws = form.querySelector('input[name="worksite_id"]');
        if (ws && !ws.value) {
            e.preventDefault();
            alert('Scegli il cantiere prima di approvare.');
            form.querySelector('.ri-cs-cambia').click();
        }
    });
})();
