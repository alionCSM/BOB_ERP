        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.edit-extra-btn').forEach(button => {
                button.addEventListener('click', function () {
                    const id = this.getAttribute('data-id');
                    const data = this.getAttribute('data-data');
                    const ordine = this.getAttribute('data-ordine');
                    const descrizione = this.getAttribute('data-descrizione');
                    const totale = this.getAttribute('data-totale');

                    document.getElementById('extra-id').value = id;
                    document.getElementById('extra-data').value = data;
                    document.getElementById('extra-ordine').value = ordine;
                    document.getElementById('extra-descrizione').value = descrizione;
                    document.getElementById('extra-totale').value = totale;

                    document.getElementById('extra-modal-title').textContent = id ? 'Modifica Extra' : 'Aggiungi Extra';
                });
            });

        });

        document.addEventListener('DOMContentLoaded', function () {

            document.querySelectorAll('.create-billing-from-extra').forEach(btn => {
                btn.addEventListener('click', function () {

                    document.getElementById('billing-id').value = '';

                    document.getElementById('billing-extra-id').value = this.dataset.extraId;

                    document.getElementById('billing-data').value = this.dataset.data;

                    // Description: extend the server-built billing prefix
                    // (ORDINE [commessa] N. del data - CANTIERE name) with
                    // the EXTRA segment. Falls back to the cantiere-only
                    // prefix if the data attribute isn't set.
                    var prefix = this.dataset.billingPrefix
                                || ('CANTIERE ' + this.dataset.cantiere);
                    document.getElementById('billing-descrizione').value =
                        prefix +
                        ' - EXTRA ' + this.dataset.ordine +
                        ' - ' + this.dataset.descrizione;


                    let n = parseFloat(this.dataset.totale);
                    document.getElementById('billing-totale').value =
                        isNaN(n) ? '' : n.toLocaleString('it-IT', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });

                    document.getElementById('billing-modal-title').textContent =
                        'Crea fattura da Extra';

                    document.querySelector('[data-tw-target="#billing-modal"]').click();
                });
            });

        });
