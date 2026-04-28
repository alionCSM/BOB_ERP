// Commento personalizzato: gestione della modale per note interne + PDF
function closeModal() {
    let modal = document.querySelector("#modal-notes");
    if (modal) {
        modal.classList.remove("active");
    }
    // Restore body scroll
    document.body.style.overflow = "";
    document.body.style.marginRight = "";
}
function openModal() {
    let modal = document.querySelector("#modal-notes");
    if (modal) {
        modal.classList.add("active");
    }
    // Prevent body scroll when modal is open
    const scrollWidth = document.documentElement.scrollWidth - document.documentElement.clientWidth;
    document.body.style.overflow = "hidden";
    document.body.style.marginRight = scrollWidth > 0 ? scrollWidth + "px" : "";
}

document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function(e) {
        if (e.target.closest('[data-open-notes]')) {
            openModal();
        }
    });
});

// Commento personalizzato: sto tenendo i riferimenti agli editor di CKEditor
let editorTermini;
let editorCondizioni;

// --------------------------------------------------------------
// Update progress indicator based on current step
// --------------------------------------------------------------
function updateProgress(step) {
    const track = document.getElementById('progress-track');
    if (track) {
        // Remove all step classes
        track.classList.remove('step-1', 'step-2', 'step-3');
        // Add current step class
        track.classList.add(`step-${step}`);
    }

    // Update step dots
    document.querySelectorAll('.cfo-progress-step').forEach((s, i) => {
        if (i + 1 < step) {
            s.classList.add('completed');
        } else {
            s.classList.remove('completed');
        }

        if (i + 1 === step) {
            s.classList.add('active');
        } else {
            s.classList.remove('active');
        }
    });
}

// --------------------------------------------------------------
// Inizializzazione TomSelect e CKEditor
// --------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function () {
    new TomSelect("#client_search", {
        valueField: 'id',
        labelField: 'name',
        searchField: ['name'],
        create: false,
        dropdownParent: 'body'
    });

    ClassicEditor.create(document.querySelector('#termini_pagamento'))
        .then(editor => { editorTermini = editor; })
        .catch(error => console.error(error));

    ClassicEditor.create(document.querySelector('#condizioni'))
        .then(editor => { editorCondizioni = editor; })
        .catch(error => console.error(error));

    // Load items from JSON data
    let itemsEl = document.getElementById('items-data');
    let itemsData = itemsEl ? JSON.parse(itemsEl.textContent) : [];
    if (itemsData && itemsData.length > 0) {
        itemsData.forEach(it => {
            let d = it.description || '';
            let a = it.amount || '';
            if (typeof a === 'number' || /^\d+(\.\d+)?$/.test(a)) {
                aggiungiRiga(d, formatItalianNumber(parseFloat(a)));
            } else {
                aggiungiRiga(d, a);
            }
        });
    } else {
        aggiungiRiga('', '');
    }

    // File input change handler - show uploaded PDF info
    const fileInput = document.getElementById('offer_pdf');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            const file = this.files[0];
            const uploadedContainer = document.getElementById('uploaded-pdf-container');
            const uploadedName = document.getElementById('uploaded-pdf-name');

            if (file && uploadedContainer && uploadedName) {
                uploadedName.textContent = file.name;
                uploadedContainer.style.display = 'flex';
            } else if (uploadedContainer) {
                uploadedContainer.style.display = 'none';
            }
        });
    }

    // Modal button handlers
    const clearModalBtn = document.getElementById("clearModal");
    if (clearModalBtn) {
        clearModalBtn.addEventListener("click", function () {
            document.getElementById("note_interne").value = "";
            document.getElementById("offer_pdf").value = "";
            const uploadedContainer = document.getElementById("uploaded-pdf-container");
            if (uploadedContainer) uploadedContainer.style.display = "none";
            closeModal();
        });
    }

    const saveModalBtn = document.getElementById("saveModal");
    if (saveModalBtn) {
        saveModalBtn.addEventListener("click", function () {
            let noteContent = document.getElementById("note_interne").value;
            let pdfFile = document.getElementById("offer_pdf").files[0];
            console.log("Salvataggio note interne:", noteContent);
            if (pdfFile) {
                console.log("Salvataggio file:", pdfFile.name);
            }
            closeModal();
        });
    }
});

// --------------------------------------------------------------
// Parsing / formattazione in stile italiano
// --------------------------------------------------------------

// Restituisce null se la stringa NON è un numero puro
// (ovvero se c'è leftover text come "a persona", "da definire", ecc.)
function parseNumberOnlyIfPure(str) {
    // Elimino simbolo "€" e spazi
    let cleaned = str.replace('€', '').trim();

    // Se c'è "da definire"
    if (/da\s+definire/i.test(cleaned)) {
        return null;
    }

    // Permetto SOLO un blocco numerico. Se leftover text => skip
    let match = cleaned.match(/^(\d[\d.,]*)$/);
    if (!match) {
        return null;
    }

    // Altrimenti parse in stile italiano
    return parseItalianNumber(match[1]);
}

// "1.100,10" => 1100.10
function parseItalianNumber(str) {
    str = str.replace(/[^\d,.-]/g, '').trim();
    // Rimuovo i "." di separatore migliaia
    str = str.replace(/\./g, '');
    // Converto la virgola in punto
    str = str.replace(',', '.');
    let val = parseFloat(str);
    return isNaN(val) ? 0 : val;
}

// float => "1.100,10 €"
function formatItalianNumber(num) {
    num = Number(num);

    if (isNaN(num)) return '';

    return num.toLocaleString('it-IT', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        useGrouping: true
    }) + ' €';
}

// --------------------------------------------------------------
// Calcolo del totale
// --------------------------------------------------------------
function calcolaTotale() {
    let total = 0;
    document.querySelectorAll('.amount-field').forEach(field => {
        let val = parseNumberOnlyIfPure(field.value);
        if (val !== null) {
            total += val;
        }
    });
    document.getElementById('total-amount').value = formatItalianNumber(total);
}

// --------------------------------------------------------------
// Aggiunta riga dinamica
// --------------------------------------------------------------
function aggiungiRiga(descrizione = '', importo = '') {
    let contenitore = document.getElementById('items-container');
    let riga = document.createElement('div');
    riga.classList.add('cfo-items-row', 'item-row');

    riga.innerHTML = `
    <textarea class="description-field cfo-items-desc" placeholder="Descrizione">${descrizione}</textarea>

    <textarea class="amount-field cfo-items-amount" placeholder="Importo (€)">${importo}</textarea>

    <div class="cfo-items-actions">
        <div class="cfo-btn-move-wrapper">
            <button type="button" class="cfo-btn-item cfo-btn-move-up" title="Sposta su">
                <svg viewBox="0 0 24 24"><polyline points="18 15 12 9 6 15"/></svg>
            </button>
            <button type="button" class="cfo-btn-item cfo-btn-move-down" title="Sposta giù">
                <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
        </div>
    </div>

    <div class="cfo-items-buttons">
        <button type="button" class="cfo-btn-item cfo-btn-remove" title="Rimuovi">
            <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
`;

    contenitore.appendChild(riga);

    // Sposta la riga sopra
    riga.querySelector('.cfo-btn-move-up').addEventListener('click', function () {
        let prev = riga.previousElementSibling;
        if (prev) {
            riga.parentNode.insertBefore(riga, prev);
        }
    });

    // Sposta la riga sotto
    riga.querySelector('.cfo-btn-move-down').addEventListener('click', function () {
        let next = riga.nextElementSibling;
        if (next) {
            riga.parentNode.insertBefore(riga, next.nextElementSibling);
        }
    });

    let amountField = riga.querySelector('.amount-field');

    // Al blur, riformattiamo SOLO se puramente numerico
    amountField.addEventListener('blur', function() {
        let val = parseNumberOnlyIfPure(this.value);
        if (val !== null) {
            this.value = formatItalianNumber(val);
        }
        calcolaTotale();
    });

    // Mentre digita, aggiorno totale
    amountField.addEventListener('input', calcolaTotale);

    // Rimozione riga
    riga.querySelector('.cfo-btn-remove').addEventListener('click', function () {
        riga.remove();
        calcolaTotale();
    });
}

// --------------------------------------------------------------
// Navigate to step
// --------------------------------------------------------------
function goToStep(step) {
    // Hide all steps
    document.getElementById('step1').classList.add('hidden');
    document.getElementById('step2').classList.add('hidden');
    document.getElementById('step3').classList.add('hidden');

    // Show target step
    document.getElementById(`step${step}`).classList.remove('hidden');

    // Update progress indicator
    updateProgress(step);
}

// --------------------------------------------------------------
// Navigazione Step (event delegation - buttons don't exist on page load)
// --------------------------------------------------------------
document.addEventListener('click', function(e) {
    const button = e.target.closest('button[id]');
    if (!button) return;

    const id = button.id;
    if (id === 'toStep2') {
        goToStep(2);
    } else if (id === 'toStep3') {
        goToStep(3);
    } else if (id === 'backToStep1') {
        goToStep(1);
    } else if (id === 'backToStep2') {
        goToStep(2);
    }
});

// --------------------------------------------------------------
// Submit: serializzo le righe
// --------------------------------------------------------------
document.querySelector('form').addEventListener('submit', function () {
    let items = [];

    document.querySelectorAll('#items-container .item-row').forEach(riga => {
        let descrizione = riga.querySelector('.description-field')?.value.trim();
        let importo = riga.querySelector('.amount-field')?.value.trim();

        if (!descrizione || !importo) return;

        let val = parseNumberOnlyIfPure(importo);
        items.push({
            description: descrizione,
            amount: val !== null ? val.toFixed(2) : importo
        });
    });

    document.getElementById('items_data').value = JSON.stringify(items);
});
