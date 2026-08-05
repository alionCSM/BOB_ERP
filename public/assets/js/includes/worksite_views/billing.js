        document.addEventListener('DOMContentLoaded', function(){
            const ordineSelect = new TomSelect('#billing-ordine', {
                create:false,
                sortField:'text'
            });

            const ivaSelect = new TomSelect('#billing-iva', {
                create:false
            });

            // --- Handle “Aggiungi Fattura” ---
            document.querySelector('[data-tw-target="#billing-modal"]').addEventListener('click', function(){
                // reset modal for new entry
                document.getElementById('billing-id').value = '';
                document.getElementById('billing-extra-id').value = '';
                document.getElementById('billing-modal-title').textContent = 'Aggiungi Fattura';
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
                const syncDescrCount = () => {
                    const len = descrField.value.length;
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
