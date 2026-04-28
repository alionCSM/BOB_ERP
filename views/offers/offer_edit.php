<?php
require_once '../../includes/middleware.php';
require_once '../../controllers/offers/OfferController.php';

$db = new Database();
$conn = $db->connect();

// Imposta user manualmente se necessario
$user->id = $authenticated_user['user_id'];

// Sicurezza: blocca accesso se company_id è null
if ($user->getCompanyId() === null) {
    header("Location: ../auth/logout.php");
    exit();
}

$offerController = new OfferController($conn);

$offerId = $_GET['offer_id'] ?? null;
if (!$offerId || !is_numeric($offerId)) {
    die("ID offerta mancante o non valido.");
}

// Recupera l'offerta con controllo accesso
$offerData = $offerController->getOfferForEdit((int)$offerId, (int)$user->getCompanyId());
if (!$offerData) {
    header("Location: offer_list.php?error=Offerta non trovata o accesso negato.");
    exit();
}

$itemsData = $offerController->getOfferItems((int)$offerId);
$clients = $offerController->getClients();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $offerController->updateFromRequest(
        (int)$offerId,
        $_POST,
        $_FILES,
        (int)$user->getCompanyId(),
        (int)$authenticated_user['user_id']
    );

    header("Location: offer_list.php?success=Offerta modificata con successo!");
    exit();
}

$pageTitle = "Modifica Offerta #{$offerId}";
include_once '../../includes/template/template.php';
?>


<link href="https://cdn.jsdelivr.net/npm/tom-select/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select/dist/js/tom-select.complete.min.js"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>

<div class="intro-y flex items-center mt-8">
    <h2 class="text-lg font-medium mr-auto">Modifica Offerta</h2>
</div>
<div class="grid grid-cols-12 gap-6 mt-5">
    <div class="intro-y col-span-12">
        <div class="box p-10">
            <!-- 3 step:
                 Step 1 = Dati Offerta
                 Step 2 = Descrizioni (2 textareas x riga + remove + add row)
                 Step 3 = Termini e Condizioni -->
            <form action="" method="post" enctype="multipart/form-data">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg font-semibold">Passo <span id="step-title">1</span> di 3</h3>
                </div>

                <!-- STEP 1: DATI OFFERTA -->
                <div id="step1">
                    <div class="grid grid-cols-12 gap-5">
                        <div class="col-span-12 sm:col-span-4">
                            <label for="client_search" class="form-label">Cliente</label>
                            <select id="client_search" name="client" required>
                                <option value="">Seleziona un cliente...</option>
                                <?php foreach($clients as $c): ?>
                                    <option value="<?= $c['id'] ?>"
                                        <?= $c['id'] == $offerData['client_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-span-12 sm:col-span-4">
                            <label for="offer_date" class="form-label">Data</label>
                            <input type="date"
                                   id="offer_date"
                                   name="offer_date"
                                   class="form-control w-full"
                                   value="<?= htmlspecialchars($offerData['offer_date']) ?>"
                                   required>
                        </div>
                        <div class="col-span-12 sm:col-span-4">
                            <label class="form-label">Numero Offerta</label>
                            <!-- Di solito non lo si modifica, ma potresti renderlo input se necessario -->
                            <input type="text"
                                   class="form-control w-full"
                                   value="<?= htmlspecialchars($offerData['offer_number']) ?>"
                                   readonly>
                        </div>

                        <div class="col-span-12 sm:col-span-6">
                            <label for="riferimento" class="form-label">Riferimento Richiesta</label>
                            <input type="text"
                                   id="riferimento"
                                   name="riferimento"
                                   class="form-control w-full"
                                   value="<?= htmlspecialchars($offerData['reference']) ?>"
                                   >
                        </div>
                        <div class="col-span-12 sm:col-span-6">
                            <label for="cortese_att" class="form-label">Alla CA di:</label>
                            <input type="text"
                                   id="cortese_att"
                                   name="cortese_att"
                                   class="form-control w-full"
                                   value="<?= htmlspecialchars($offerData['cortese_att']) ?>">
                        </div>
                        <div class="col-span-12">
                            <label for="oggetto" class="form-label">Oggetto</label>
                            <input type="text"
                                   id="oggetto"
                                   name="oggetto"
                                   class="form-control w-full"
                                   value="<?= htmlspecialchars($offerData['subject']) ?>"
                                   required>
                        </div>

                        <!-- Modale note interne -->
                        <button type="button" class="btn btn-primary" data-tw-toggle="modal"
                                data-tw-target="#modal-notes">
                            Note Interne
                        </button>
                        <div id="modal-notes" class="modal">
                            <div class="modal-dialog modal-xl">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <div class="flex items-center text-lg font-medium text-slate-800">
                                            Note interne (non visibili nell'offerta)
                                        </div>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-4">
                                            <label for="note" class="form-label">Note</label>
                                            <!-- Se esiste colonna 'notes' la potresti mostrare qui -->
                                            <textarea id="note"
                                                      name="note"
                                                      class="form-control w-full"></textarea>
                                        </div>
                                        <div>
                                            <label for="offer_pdf" class="form-label">Allega PDF (facoltativo)</label>
                                            <input type="file"
                                                   id="offer_pdf"
                                                   name="offer_pdf"
                                                   class="form-control w-full"
                                                   accept="application/pdf">
                                            <?php if($offerData['pdf_path']): ?>
                                                <p class="mt-2 text-sm">
                                                    PDF attuale: <?= htmlspecialchars($offerData['pdf_path']) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button"
                                                class="btn btn-secondary"
                                                id="clearModal">
                                            Cancella
                                        </button>
                                        <button type="button"
                                                class="btn btn-primary"
                                                id="saveModal">
                                            Salva
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bottone per passare allo Step 2 -->
                        <div class="col-span-12 mt-5 text-right">
                            <button type="button" class="btn btn-primary px-6 py-2" id="goStep2">
                                Prossimo Passo
                            </button>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: 2 TEXTAREAS PER RIGA (Descrizione / Prezzo) -->
                <div id="step2" class="hidden">
                    <h3 class="text-lg font-semibold mb-4">📜 Dettagli Importi</h3>

                    <!-- Header per le textareas -->
                    <div class="flex items-center gap-2 font-bold">
                        <div class="w-3/4">Descrizione</div>
                        <div class="w-1/4 text-right">Prezzo</div>
                    </div>

                    <!-- Contenitore per le righe (JS popola) -->
                    <div id="items-container" class="mt-2">
                        <!-- Righe generate da JS, caricate da $itemsData -->
                    </div>

                    <!-- Hidden per salvare JSON -->
                    <input type="hidden" name="items_data" id="items_data">

                    <!-- Bottone aggiungi riga -->
                    <button type="button" class="btn btn-primary mt-4" id="add-row">
                        Aggiungi Riga
                    </button>

                    <div class="mt-6">
                        <label for="additional" class="font-medium">Info Aggiuntive</label>
                        <textarea class="form-control w-full font-bold p-2 mt-2" name="additional"><?= htmlspecialchars($offerData['note'] ?? '') ?></textarea>
                    </div>

                    <div class="mt-6">
                        <label for="total-amount" class="font-medium">Totale</label>
                        <input type="text" id="total-amount" name="total_amount"
                               class="form-control w-full font-bold p-2 mt-2"
                               value="<?= htmlspecialchars($offerData['total_amount']) ?>">
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button"
                                class="btn btn-secondary px-6 py-2"
                                id="backToStep1">
                            Indietro
                        </button>
                        <button type="button"
                                class="btn btn-primary px-6 py-2"
                                id="goStep3">
                            Prossimo Passo
                        </button>
                    </div>
                </div>

                <!-- STEP 3: TERMINI E CONDIZIONI -->
                <div id="step3" class="hidden">
                    <h3 class="text-lg font-semibold mb-4">📜 Termini e Condizioni</h3>

                    <label for="termini_pagamento" class="form-label">
                        Termini e Pagamento
                    </label>
                    <textarea id="termini_pagamento"
                              name="termini_pagamento"
                              class="form-control w-full">
                        <?= htmlspecialchars($offerData['termini_pagamento']) ?>
                    </textarea>

                    <label for="condizioni" class="form-label mt-4">
                        Condizioni
                    </label>
                    <textarea id="condizioni"
                              name="condizioni"
                              class="form-control w-full">
                        <?= htmlspecialchars($offerData['condizioni']) ?>
                    </textarea>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button"
                                class="btn btn-secondary px-6 py-2"
                                id="backToStep2">
                            Indietro
                        </button>
                        <button type="submit"
                                class="btn btn-primary px-6 py-2">
                            Aggiorna Offerta
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

    <script>
        // Riferimenti editor e TomSelect
        let editorTermini;
        let editorCondizioni;

        // This flag says "The DB total was leftover text initially, and we haven't switched to local sum yet"
        let dbTotalIsLeftover = false;

        document.addEventListener('DOMContentLoaded', function () {
            new TomSelect("#client_search", {
                valueField: 'id',
                labelField: 'name',
                searchField: ['name'],
                create: false
            });

            ClassicEditor.create(document.querySelector('#termini_pagamento'))
                .then(editor => { editorTermini = editor; })
                .catch(error => console.error(error));

            ClassicEditor.create(document.querySelector('#condizioni'))
                .then(editor => { editorCondizioni = editor; })
                .catch(error => console.error(error));
        });

        // --------------------------------------------------------------
        // STEP NAVIGATION
        // --------------------------------------------------------------
        document.getElementById('goStep2').addEventListener('click', function () {
            document.getElementById('step1').classList.add('hidden');
            document.getElementById('step2').classList.remove('hidden');
            document.getElementById('step-title').innerText = '2';
        });
        document.getElementById('backToStep1').addEventListener('click', function () {
            document.getElementById('step2').classList.add('hidden');
            document.getElementById('step1').classList.remove('hidden');
            document.getElementById('step-title').innerText = '1';
        });
        document.getElementById('goStep3').addEventListener('click', function () {
            document.getElementById('step2').classList.add('hidden');
            document.getElementById('step3').classList.remove('hidden');
            document.getElementById('step-title').innerText = '3';
        });
        document.getElementById('backToStep2').addEventListener('click', function () {
            document.getElementById('step3').classList.add('hidden');
            document.getElementById('step2').classList.remove('hidden');
            document.getElementById('step-title').innerText = '2';
        });

        // --------------------------------------------------------------
        // MODALE NOTE
        // --------------------------------------------------------------
        function closeModal() {
            let modal = document.querySelector("#modal-notes");
            modal.classList.remove("show");
            setTimeout(() => { modal.style.display = "none"; }, 200);
        }
        function openModal() {
            let modal = document.querySelector("#modal-notes");
            modal.style.display = "flex";
            setTimeout(() => { modal.classList.add("show"); }, 10);
        }
        document.querySelector('[data-tw-toggle="modal"]').addEventListener("click", openModal);

        document.getElementById("clearModal").addEventListener("click", function () {
            document.getElementById("note").value = "";
            document.getElementById("offer_pdf").value = "";
            closeModal();
        });
        document.getElementById("saveModal").addEventListener("click", function () {
            let noteContent = document.getElementById("note").value;
            let pdfFile = document.getElementById("offer_pdf").files[0];
            console.log("Salvataggio note:", noteContent);
            if (pdfFile) {
                console.log("Salvataggio file:", pdfFile.name);
            }
            closeModal();
        });

        // --------------------------------------------------------------
        // PARSING E FORMATTAZIONE PREZZI ITALIANI
        // --------------------------------------------------------------
        // Esempio: "300,00" => 300
        function parseItalianNumber(str) {
            str = str.replace(/[^\d,.\-]/g, '').trim();
            str = str.replace(/\./g, '');
            str = str.replace(',', '.');
            let val = parseFloat(str);
            return isNaN(val) ? 0 : val;
        }
        // Esempio: 300 => "300,00 €"
        function formatItalianNumber(num) {
            num = Number(num);
            if (isNaN(num)) return '';

            return num.toLocaleString('it-IT', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
                useGrouping: true   // 🔥 THIS IS THE FIX
            }) + ' €';
        }


        // Se stringa è "300,50" o "1.100,10" => parse => float
        // If user typed leftover text => return null => skip
        function parseNumberOnlyIfPure(str) {
            if (!str) return null;

            let cleaned = str
                .replace(/€/g, '')
                .replace(/\s+/g, '')
                .trim();

            // blocco esplicito "da definire"
            if (/da\s+definire/i.test(cleaned)) {
                return null;
            }

            // accetto SOLO formato italiano:
            // 100
            // 100,50
            // 1.100
            // 1.100,50
            if (!/^\d{1,3}(\.\d{3})*(,\d{1,2})?$|^\d+(,\d{1,2})?$/.test(cleaned)) {
                return null;
            }

            return parseItalianNumber(cleaned);
        }


        // --------------------------------------------------------------
        // CALCOLO TOTALE
        // --------------------------------------------------------------
        function calcolaTotale() {
            // If the DB total was leftover text, we do not skip anymore
            // because user changed an item => we override leftover text
            // => set dbTotalIsLeftover = false
            if (dbTotalIsLeftover) {
                dbTotalIsLeftover = false;
            }

            let total = 0;
            document.querySelectorAll('.amount-field').forEach(field => {
                let maybeVal = parseNumberOnlyIfPure(field.value);
                if (maybeVal !== null) {
                    total += maybeVal;
                }
            });
            document.getElementById('total-amount').value = formatItalianNumber(total);
        }

        // --------------------------------------------------------------
        // AGGIUNGI RIGA
        // --------------------------------------------------------------
        function aggiungiRiga(desc='', raw='') {
            let container = document.getElementById('items-container');
            let row = document.createElement('div');
            row.classList.add('item-row', 'flex', 'items-center', 'gap-2', 'mt-2');

            row.innerHTML = `
    <textarea class="description-field form-control w-3/4 p-2"
              placeholder="Descrizione">${desc}</textarea>

    <textarea class="amount-field form-control w-1/4 p-2 text-right"
              placeholder="Importo (€)">${raw}</textarea>

    <div class="flex flex-col gap-1">
        <button type="button" class="btn btn-secondary move-up">⬆</button>
        <button type="button" class="btn btn-secondary move-down">⬇</button>
    </div>
    <div class="flex flex-col gap-1">
        <button type="button" class="btn btn-danger remove-row">✖</button>
    </div>

`;

            container.appendChild(row);

            //  porto la riga sopra (se esiste)
            row.querySelector('.move-up').addEventListener('click', function () {
                let prev = row.previousElementSibling;
                if (prev) {
                    row.parentNode.insertBefore(row, prev);
                }
            });

//  porto la riga sotto (vero move-down)
            row.querySelector('.move-down').addEventListener('click', function () {
                let next = row.nextElementSibling;
                if (next) {
                    row.parentNode.insertBefore(row, next.nextElementSibling);
                }
            });



            let amt = row.querySelector('.amount-field');

            // On blur => if purely numeric => format => else keep text
            // Then always calcolaTotale => which might override leftover text in #total-amount
            amt.addEventListener('blur', function() {
                let pureVal = parseNumberOnlyIfPure(this.value);
                if (pureVal !== null) {
                    this.value = formatItalianNumber(pureVal);
                }
                calcolaTotale();
            });
            amt.addEventListener('input', calcolaTotale);

            // remove row => recalc
            row.querySelector('.remove-row').addEventListener('click', function() {
                row.remove();
                calcolaTotale();
            });
        }

        // --------------------------------------------------------------
        // SE IL TOT DAL DB E' "DA DEFINIRE" => mostralo as-is
        // ALTRIMENTI PARSE/FORMAT
        // --------------------------------------------------------------
        let dbTotal = <?php echo json_encode($offerData['total_amount'] ?? '', JSON_HEX_TAG); ?>;

        // Helper check
        function isNumericDB(val) {
            // e.g. "300.00" => true, "da definire" => false
            return /^(\d+(\.\d+)?)$/.test(val.trim());
        }

        // On load => show #total-amount
        document.addEventListener('DOMContentLoaded', function() {
            let totField = document.getElementById('total-amount');
            if (dbTotal && isNumericDB(dbTotal)) {
                // numeric => format
                let fVal = parseFloat(dbTotal);
                totField.value = formatItalianNumber(fVal);
                dbTotalIsLeftover = false;
            } else {
                // leftover text => e.g. "da definire"
                totField.value = dbTotal;
                dbTotalIsLeftover = true;
            }
        });

        // --------------------------------------------------------------
        // LOAD RIGHE DA DB
        // --------------------------------------------------------------
        <?php if (!empty($itemsData)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            let items = <?= json_encode($itemsData) ?>;
            items.forEach(it => {
                let d = it.description || '';
                let a = it.amount || '';
                // if numeric => parse => format => else leftover text
                if (isNumericDB(a)) {
                    let floatVal = parseFloat(a);
                    let display = formatItalianNumber(floatVal);
                    aggiungiRiga(d, display);
                } else {
                    aggiungiRiga(d, a);
                }
            });
            // We do not recalc if leftover text => user must change something
            if (!dbTotalIsLeftover) {
                calcolaTotale();
            }
        });
        <?php else: ?>
        document.addEventListener('DOMContentLoaded', function() {
            aggiungiRiga('', '');
            if (!dbTotalIsLeftover) {
                calcolaTotale();
            }
        });
        <?php endif; ?>

        // Add row manually
        document.getElementById('add-row').addEventListener('click', function() {
            aggiungiRiga('', '');
        });

        // --------------------------------------------------------------
        // SUBMIT => SALVO I DATI
        // --------------------------------------------------------------
        document.querySelector('form').addEventListener('submit', function () {
            let items = [];

            document.querySelectorAll('#items-container .item-row').forEach(r => {
                let descField = r.querySelector('.description-field');
                let amountField = r.querySelector('.amount-field');

                if (!descField || !amountField) return; // sicurezza BOB

                let d = descField.value.trim();
                let raw = amountField.value.trim();

                if (d && raw) {
                    let val = parseNumberOnlyIfPure(raw);
                    if (val !== null) {
                        items.push({
                            description: d,
                            amount: val.toFixed(2)
                        });
                    } else {
                        items.push({
                            description: d,
                            amount: raw
                        });
                    }
                }
            });

            document.getElementById('items_data').value = JSON.stringify(items);
        });
    </script>


<?php include "../../includes/template/footer.php"; ?>
