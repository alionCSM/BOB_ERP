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

$originalId = $_GET['offer_id'] ?? null;
if (!$originalId || !is_numeric($originalId)) {
    die("ID offerta mancante o non valido.");
}

$originalData = $offerController->getOfferForEdit((int)$originalId, (int)$user->getCompanyId());
if (!$originalData) {
    header("Location: offer_list.php?error=Offerta non trovata o accesso negato.");
    exit();
}

$baseNumber = $originalData['is_revision'] ? $originalData['base_offer_number'] : $originalData['offer_number'];
$newRevisionNumber = $offerController->getNextRevisionNumber($baseNumber);
$itemsData = $offerController->getOfferItems((int)$originalId);
$clients = $offerController->getClients();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $offerController->createRevisionFromRequest(
        $_POST,
        $_FILES,
        $originalData,
        $baseNumber,
        (int)$user->getCompanyId(),
        (int)$authenticated_user['user_id']
    );

    if ($result['success']) {
        header("Location: offer_list.php?success=Revisione offerta creata con successo!");
        exit();
    } else {
        echo "<p class='text-red-500'>{$result['message']}</p>";
    }
}

$pageTitle = "Revisione Offerta #{$originalId}";
include_once '../../includes/template/template.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tom-select/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select/dist/js/tom-select.complete.min.js"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>

<div class="intro-y flex items-center mt-8">
    <h2 class="text-lg font-medium mr-auto">
        Crea Revisione per Offerta #<?= htmlspecialchars($originalId) ?>
    </h2>
</div>

<div class="grid grid-cols-12 gap-6 mt-5">
    <div class="intro-y col-span-12">
        <div class="box p-10">
            <!-- Form a 3 step: Step 1 (dati offerta), Step 2 (articoli), Step 3 (termini & cond) -->
            <form action="" method="post" enctype="multipart/form-data">
                <!-- Indicatore di passo -->
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg font-semibold">
                        Passo <span id="step-title">1</span> di 3
                    </h3>
                </div>

                <!-- STEP 1: dati offerta -->
                <div id="step1">
                    <div class="grid grid-cols-12 gap-5">
                        <div class="col-span-12 sm:col-span-4">
                            <label for="client_search" class="form-label">Cliente</label>
                            <select id="client_search" name="client" class="form-control">
                                <option value="">-- Seleziona Cliente --</option>
                                <?php foreach($clients as $c): ?>
                                    <option value="<?= $c['id'] ?>"
                                        <?= ($c['id'] == $originalData['client_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-span-12 sm:col-span-4">
                            <label for="offer_date" class="form-label">Data Revisione</label>
                            <input type="date" id="offer_date" name="offer_date"
                                   class="form-control w-full"
                                   value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-span-12 sm:col-span-4">
                            <label class="form-label">Nuovo Numero Offerta (Revisione)</label>
                            <input type="text" class="form-control w-full"
                                   value="<?= htmlspecialchars($newRevisionNumber) ?>"
                                   readonly>
                        </div>

                        <div class="col-span-12 sm:col-span-6">
                            <label for="riferimento" class="form-label">Riferimento Richiesta</label>
                            <input type="text" id="riferimento" name="riferimento"
                                   class="form-control w-full"
                                   value="<?= htmlspecialchars($originalData['reference']) ?>">
                        </div>
                        <div class="col-span-12 sm:col-span-6">
                            <label for="cortese_att" class="form-label">Alla CA di:</label>
                            <input type="text" id="cortese_att" name="cortese_att"
                                   class="form-control w-full"
                                   value="<?= htmlspecialchars($originalData['cortese_att']) ?>">
                        </div>
                        <div class="col-span-12">
                            <label for="oggetto" class="form-label">Oggetto</label>
                            <input type="text" id="oggetto" name="oggetto"
                                   class="form-control w-full"
                                   value="<?= htmlspecialchars($originalData['subject']) ?>">
                        </div>

                        <!-- Modale (Note interne) -->
                        <button type="button" class="btn btn-primary" data-tw-toggle="modal"
                                data-tw-target="#modal-notes">
                            Note Interne
                        </button>
                        <div id="modal-notes" class="modal">
                            <div class="modal-dialog modal-xl">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h2 class="text-lg font-medium">
                                            Note interne (non visibili nell'offerta)
                                        </h2>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-4">
                                            <label for="note" class="form-label">Note</label>
                                            <textarea id="note" name="note" class="form-control w-full"></textarea>
                                        </div>
                                        <div>
                                            <label for="offer_pdf" class="form-label">Allega PDF (opzionale)</label>
                                            <input type="file" id="offer_pdf" name="offer_pdf"
                                                   class="form-control w-full"
                                                   accept="application/pdf">
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

                        <!-- Pulsante per andare allo step 2 -->
                        <div class="col-span-12 mt-5 text-right">
                            <button type="button" class="btn btn-primary"
                                    id="goStep2">
                                Prossimo Passo
                            </button>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: DUE TEXTAREAS PER RIGA INVECE DELLA TABELLA -->
                <div id="step2" class="hidden">
                    <h3 class="text-lg font-semibold mb-4">
                        Dettagli Importi (Revisione)
                    </h3>

                    <!-- Etichette "Descrizione" / "Prezzo" -->
                    <div class="flex items-center gap-2 font-bold">
                        <div class="w-3/4">Descrizione</div>
                        <div class="w-1/4 text-right">Prezzo</div>
                    </div>

                    <!-- Contenitore righe -->
                    <div id="items-container" class="mt-2">
                        <!-- Righe generate in JS partendo da $itemsData -->
                    </div>

                    <!-- Bottone per aggiungere riga -->
                    <button type="button" class="btn btn-primary mt-4" id="add-row">
                        Aggiungi Riga
                    </button>

                    <div class="mt-6">
                        <label for="additional" class="font-medium">Info Aggiuntive</label>
                        <textarea class="form-control w-full font-bold p-2 mt-2" name="additional"><?= htmlspecialchars($originalData['note'] ?? '') ?></textarea>
                    </div>

                    <!-- Hidden dove salvo i dati JSON prima del submit -->
                    <input type="hidden" name="items_data" id="items_data">

                    <div class="mt-6">
                        <label for="total-amount" class="font-medium">Totale</label>
                        <input type="text"
                               id="total-amount"
                               name="total_amount"
                               class="form-control w-full font-bold p-2 mt-2"
                               value="<?= htmlspecialchars($originalData['total_amount']) ?>">
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" class="btn btn-secondary" id="backToStep1">
                            Indietro
                        </button>
                        <button type="button" class="btn btn-primary" id="goStep3">
                            Prossimo Passo
                        </button>
                    </div>
                </div>

                <!-- STEP 3: TERMINI & CONDIZIONI -->
                <div id="step3" class="hidden">
                    <h3 class="text-lg font-semibold mb-4">
                        Termini e Condizioni (Revisione)
                    </h3>

                    <label for="termini_pagamento" class="form-label">
                        Termini e Pagamento
                    </label>
                    <textarea id="termini_pagamento"
                              name="termini_pagamento"
                              class="form-control w-full">
                        <?= htmlspecialchars($originalData['termini_pagamento']) ?>
                    </textarea>

                    <label for="condizioni" class="form-label mt-4">
                        Condizioni
                    </label>
                    <textarea id="condizioni"
                              name="condizioni"
                              class="form-control w-full">
                        <?= htmlspecialchars($originalData['condizioni']) ?>
                    </textarea>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" class="btn btn-secondary" id="backToStep2">
                            Indietro
                        </button>
                        <button type="submit" class="btn btn-primary">
                            Crea Revisione
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
    // Riferimenti CKEditor e TomSelect
    let editorTermini;
    let editorCondizioni;

    // A flag indicating whether the DB total was leftover text (da definire)
    let dbTotalIsLeftover = false;

    document.addEventListener('DOMContentLoaded', function () {
        // TomSelect
        new TomSelect("#client_search", {
            valueField: 'id',
            labelField: 'name',
            searchField: ['name'],
            create: false
        });

        // CKEditors
        ClassicEditor.create(document.querySelector('#termini_pagamento'))
            .then(editor => { editorTermini = editor; })
            .catch(error => console.error(error));

        ClassicEditor.create(document.querySelector('#condizioni'))
            .then(editor => { editorCondizioni = editor; })
            .catch(error => console.error(error));
    });

    // Step navigation
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

    // Modale note
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
        console.log("Salvataggio note interne:", noteContent);
        if (pdfFile) {
            console.log("Salvataggio file:", pdfFile.name);
        }
        closeModal();
    });

    // --- PRICE PARSING ---
    // Check if DB total is purely numeric, e.g. "300.00"
    function isNumericDB(val) {
        return /^(\d+(\.\d+)?)$/.test(val.trim());
    }
    // "1.100,10" => 1100.10
    function parseItalianNumber(str) {
        str = str.replace(/[^\d,.\-]/g, '').trim();
        str = str.replace(/\./g, '');
        str = str.replace(',', '.');
        let val = parseFloat(str);
        return isNaN(val) ? 0 : val;
    }
    // 1100.1 => "1.100,10 €"
    function formatItalianNumber(num) {
        num = Number(num);
        if (isNaN(num)) return '';

        return num.toLocaleString('it-IT', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
            useGrouping: true // 🔥 fondamentale
        }) + ' €';
    }


    // parseNumberOnlyIfPure => leftover text => null => skip from sum
    function parseNumberOnlyIfPure(str) {
        if (!str) return null;

        let cleaned = str
            .replace(/€/g, '')
            .replace(/\s+/g, '')
            .trim();

        // blocco esplicito testo libero
        if (/da\s+definire/i.test(cleaned)) {
            return null;
        }

        // SOLO formato italiano valido
        // 100
        // 100,50
        // 1.100
        // 1.100,50
        if (!/^\d{1,3}(\.\d{3})*(,\d{1,2})?$|^\d+(,\d{1,2})?$/.test(cleaned)) {
            return null;
        }

        return parseItalianNumber(cleaned);
    }


    // If dbTotalIsLeftover => we skip overwriting #total-amount,
    // but if user modifies an item, we set dbTotalIsLeftover=false => local sum
    function calcolaTotale() {
        // If the DB total was leftover text, the first user change => override leftover text
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

    // Aggiungi riga
    function aggiungiRiga(descr = '', raw = '') {
        let container = document.getElementById('items-container');
        let row = document.createElement('div');
        row.classList.add('item-row', 'flex', 'items-center', 'gap-2', 'mt-2');

        row.innerHTML = `
        <textarea class="description-field form-control w-3/4 p-2"
                  placeholder="Descrizione">${descr}</textarea>

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

        /* ⬆ Commento personalizzato: sposta la riga sopra */
        row.querySelector('.move-up').addEventListener('click', function () {
            let prev = row.previousElementSibling;
            if (prev) {
                row.parentNode.insertBefore(row, prev);
            }
        });

        /* ⬇ Commento personalizzato: sposta la riga sotto (vero move-down) */
        row.querySelector('.move-down').addEventListener('click', function () {
            let next = row.nextElementSibling;
            if (next) {
                row.parentNode.insertBefore(row, next.nextElementSibling);
            }
        });

        let amtField = row.querySelector('.amount-field');

        // Commento personalizzato: formatto solo se numero puro
        amtField.addEventListener('blur', function () {
            let pureVal = parseNumberOnlyIfPure(this.value);
            if (pureVal !== null) {
                this.value = formatItalianNumber(pureVal);
            }
            calcolaTotale();
        });

        amtField.addEventListener('input', calcolaTotale);

        // ❌ rimuove riga
        row.querySelector('.remove-row').addEventListener('click', function () {
            row.remove();
            calcolaTotale();
        });
    }

    // Load total from DB
    let dbTotal = <?php echo json_encode($originalData['total_amount'] ?? '', JSON_HEX_TAG); ?>;
    document.addEventListener('DOMContentLoaded', function() {
        let totField = document.getElementById('total-amount');
        if (dbTotal && isNumericDB(dbTotal)) {
            dbTotalIsLeftover = false;
            let fl = parseFloat(dbTotal);
            totField.value = formatItalianNumber(fl);
        } else {
            dbTotalIsLeftover = true;
            totField.value = dbTotal; // e.g. "da definire"
        }
    });

    // If we have items
    <?php if (!empty($itemsData)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        let items = <?= json_encode($itemsData) ?>;
        items.forEach(it => {
            let d = it.description || '';
            let a = it.amount || '';
            // If purely numeric => parse => format
            if (isNumericDB(a)) {
                let fVal = parseFloat(a);
                let disp = formatItalianNumber(fVal);
                aggiungiRiga(d, disp);
            } else {
                // leftover text => skip from sum, show as typed
                aggiungiRiga(d, a);
            }
        });
        // If dbTotalIsLeftover => we won't recalc yet. If numeric => do initial calc
        // if (!dbTotalIsLeftover) calcolaTotale();
    });
    <?php else: ?>
    document.addEventListener('DOMContentLoaded', function() {
        aggiungiRiga('', '');
        // if not leftover => do calc
        // if (!dbTotalIsLeftover) calcolaTotale();
    });
    <?php endif; ?>

    // Add row button
    document.getElementById('add-row').addEventListener('click', function() {
        aggiungiRiga('', '');
    });

    // On submit => store lines
    document.querySelector('form').addEventListener('submit', function () {
        let items = [];

        document.querySelectorAll('#items-container .item-row').forEach(r => {
            let descField = r.querySelector('.description-field');
            let amountField = r.querySelector('.amount-field');

            if (!descField || !amountField) return; // sicurezza BOB

            let d = descField.value.trim();
            let raw = amountField.value.trim();

            if (d && raw) {
                let pureVal = parseNumberOnlyIfPure(raw);
                if (pureVal !== null) {
                    items.push({
                        description: d,
                        amount: pureVal.toFixed(2)
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
