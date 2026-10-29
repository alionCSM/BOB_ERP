        document.addEventListener('DOMContentLoaded', function(){
            const ordineSelect = new TomSelect('#billing-ordine', {
                create:false,
                sortField:'text'
            });

            const ivaSelect = new TomSelect('#billing-iva', {
                create:false
            });

            // L'avviso "questa riga fattura l'extra EX-n": compare solo quando
            // il legame c'e' davvero, perche' solo in quel caso salvando si
            // modifica anche qualcosa in un'altra tab.
            //
            // Si nasconde con display inline e non con la classe "hidden":
            // la nota ha un suo style="display:flex" addosso, e uno stile
            // inline batte qualunque classe (che qui, poi, non e' nemmeno
            // definita in nessun CSS). Risultato: la nota si vedeva sempre,
            // anche sulle righe che non fatturano nessun extra.
            function mostraLegameExtra(extraId) {
                const nota = document.getElementById('billing-extra-nota');
                const rif  = document.getElementById('billing-extra-nota-rif');
                if (!nota) return;

                const n = parseInt(extraId, 10);
                if (!n) {
                    nota.style.display = 'none';
                    return;
                }

                if (rif) rif.textContent = 'EX-' + n;
                nota.style.display = 'flex';
            }

            // Il campo nascosto lo riempie anche extra.js (bottone "Fattura"
            // su una riga extra): chi lo cambia emette 'change' e la nota si
            // adegua, senza che i due file si conoscano.
            const extraIdField = document.getElementById('billing-extra-id');
            if (extraIdField) {
                extraIdField.addEventListener('change',
                    () => mostraLegameExtra(extraIdField.value));
            }

            // --- Handle “Aggiungi Fattura” ---
            document.querySelector('[data-tw-target="#billing-modal"]').addEventListener('click', function(){
                // reset modal for new entry
                document.getElementById('billing-id').value = '';
                document.getElementById('billing-extra-id').value = '';
                document.getElementById('billing-modal-title').textContent = 'Aggiungi Fattura';
                mostraLegameExtra(0);
                // keep default totale from PHP
            });

            // --- Handle “Modifica” buttons ---
            document.querySelectorAll('.edit-billing-btn').forEach(btn=>{
                btn.addEventListener('click',function(){
                    const id    = this.dataset.id;
                    const data  = this.dataset.data;
                    const artId = this.dataset.articoloId;
                    const desc  = this.dataset.descrizione;
                    const tot   = this.dataset.totale;
                    const iva   = this.dataset.iva;

                    document.getElementById('billing-id').value          = id;
                    document.getElementById('billing-data').value        = data;
                    ordineSelect.setValue(artId);
                    document.getElementById('billing-descrizione').value = desc;

                    // format total correctly
                    let n = parseFloat(tot);
                    document.getElementById('billing-totale').value =
                        isNaN(n) ? '' : n.toLocaleString('it-IT',{minimumFractionDigits:2,maximumFractionDigits:2});

                    ivaSelect.setValue(iva);

                    // Il legame con l'extra si porta dietro dalla riga: senza
                    // questo il campo nascosto restava quello dell'ultima
                    // apertura del modal.
                    const extraId = this.dataset.extraId || '';
                    document.getElementById('billing-extra-id').value = extraId;
                    mostraLegameExtra(extraId);

                    document.getElementById('billing-modal-title').textContent = 'Modifica Fattura';
                });
            });

            // --- Contatore caratteri descrizione ---
            // Il maxlength blocca la digitazione ma tronca un incolla lungo
            // senza dirlo: il contatore rende visibile il limite.
            const descrField = document.getElementById('billing-descrizione');
            const descrCount = document.getElementById('billing-descrizione-count');
            if (descrField && descrCount) {
                const MAX_DESCR = parseInt(descrField.getAttribute('maxlength'), 10) || 255;

                // Un textarea conta i ritorni a capo come un carattere (sia
                // value.length sia maxlength), ma nel POST li manda come
                // 
, due caratteri: il server ne contava sempre qualcuno
                // in piu'. Con tre righe scritte il contatore diceva 253/255
                // e il salvataggio rispondeva "troppo lunga: 256". Qui si
                // conta come conta il server.
                const lunghezzaInviata = (v) => v.replace(/
||
/g, '
').length;

                // extra.js riempie la descrizione da codice (prefisso ordine +
                // EXTRA + testo dell'extra) e il maxlength non vale per i
                // valori assegnati da JS: senza questo taglio si arrivava al
                // salvataggio oltre il limite.
                const stringiAlLimite = () => {
                    let v = descrField.value;
                    while (v.length > 0 && lunghezzaInviata(v) > MAX_DESCR) {
                        v = v.slice(0, -1);
                    }
                    if (v !== descrField.value) descrField.value = v;
                };

                const syncDescrCount = () => {
                    stringiAlLimite();
                    const len = lunghezzaInviata(descrField.value);
                    descrCount.textContent = len + ' / ' + MAX_DESCR;
                    descrCount.style.color = len >= MAX_DESCR ? '#dc2626'
                                           : (len > MAX_DESCR * 0.9 ? '#d97706' : '#94a3b8');
                };
                descrField.addEventListener('input', syncDescrCount);
                // il campo viene ripopolato all'apertura del modal (nuova/modifica)
                document.addEventListener('click', () => setTimeout(syncDescrCount, 0));
                syncDescrCount();
            }

            // --- Handle Italian numeric formatting ---
            const totField = document.getElementById('billing-totale');
            totField.addEventListener('focus', ()=> totField.value = totField.value.replace(/\./g,'').replace(',','.'));
            totField.addEventListener('blur', ()=>{
                let n = parseFloat(totField.value);
                totField.value = isNaN(n)? '' : n.toLocaleString('it-IT',{minimumFractionDigits:2,maximumFractionDigits:2});
            });
        });
