        document.addEventListener('DOMContentLoaded', function () {
            const ordineCompanySelect = new TomSelect('#ordine-company', {
                valueField: 'value',
                labelField: 'text',
                searchField: 'text',
                load: function(query, callback) {
                    var url = '/worksites/load-companies?q=' + encodeURIComponent(query);
                    fetch(url)
                        .then(response => response.json())
                        .then(json => {
                            callback(json);
                        }).catch(() => {
                        callback();
                    });
                }
            });

            document.querySelectorAll('.edit-ordine-btn').forEach(button => {
                button.addEventListener('click', function () {
                    const id = this.getAttribute('data-id');
                    const date = this.getAttribute('data-date');
                    const companyId = this.getAttribute('data-company');
                    const companyName = this.getAttribute('data-company-name');
                    const total = this.getAttribute('data-total');
                    const note = this.getAttribute('data-note');


                    document.getElementById('ordine-id').value = id;
                    document.getElementById('ordine-date').value = date;
                    document.getElementById('ordine-total').value = total;
                    document.getElementById('ordine-note').value = note;


                    const select = document.querySelector('#ordine-company').tomselect;

                    // Controlla se l'opzione esiste già (usa value = companyName)
                    const exists = Object.values(select.options).some(opt => opt.value === companyName);
                    if (!exists) {
                        // Aggiungi manualmente l'opzione (usando il nome come value e text)
                        select.addOption({ value: companyName, text: companyName });
                    }

                    // Imposta il valore selezionato
                    select.setValue(companyName);

                    document.getElementById('ordine-modal-title').textContent = id ? 'Modifica Ordine' : 'Aggiungi Ordine';
                });
            });
        });
