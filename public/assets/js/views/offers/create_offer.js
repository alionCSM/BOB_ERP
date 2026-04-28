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

    document.getElementById("clearModal")
        .addEventListener("click", function () {
            document.getElementById("note_interne").value = "";
            document.getElementById("offer_pdf").value = "";
            closeModal();
        });

    document.getElementById("saveModal")
        .addEventListener("click", function () {
            closeModal();
        });
});

// Commento personalizzato: sto tenendo i riferimenti agli editor di CKEditor
let editorTermini;
let editorCondizioni;

// --------------------------------------------------------------
// Template predefiniti
// --------------------------------------------------------------
const templateData = {
    template1: {
        oggetto: "Offerta montaggio scaffalature presso Vs. cliente sito a  ",
        items: [
            { description: "Importo montaggio scaffalature ", amount: "150" },
            { description: "Mezzi di sollevamento", amount: "300" }
        ],
        termini_pagamento: "<p>NOTA: La presente offerta è formulata sulla base delle informazioni e dei dati inclusi ed allegati alla vs. richiesta</p><p><strong>CONSORZIO SOLUZIONE MONTAGGI</strong> declina ogni responsabilità per qualsivoglia modifica all'offerta causata dalla ricezione tardiva di altra documentazione e/o informazione che comporti una qualunque modifica delle attività lavorative e si riserva il diritto di modificare l'offerta successivamente al ricevimento dei pesi e dei progetti e distinte esecutivi.</p><p><strong>VALIDITÀ OFFERTA</strong></p><p>La presente offerta economica s'intende valevole, in tutte le sue parti componenti, per giorni 60 (sessanta) dalla data di stesura.</p><p><strong>DATE DI INIZIO E FINE LAVORI</strong></p><p>Il Cliente/Committente comunicherà la data definitiva di inizio Lavori almeno 30 (trenta) giorni prima dell'inizio effettivo del cantiere (in caso di autoportante o per ordini superiori a € 30.000,00)</p><p>Il Cliente/Committente comunicherà il programma definitivo dei lavori almeno 15 (quindici) giorni prima dell'inizio effettivo del cantiere.</p><p><strong>TERMINI E MODALITÀ DI PAGAMENTO</strong></p><p>SOLITI IN ESSERE</p>",
        condizioni: "<p><strong>CONDIZIONI PRELIMINARI</strong></p><p>- Il Cliente/Committente consegnerà le aree di cantiere oggetto dei servizi di montaggio/smontaggio, nonché i relativi accessi, liberi da cose e materiali.</p><p>- Nel caso in cui ciò non fosse materialmente attuabile a causa di strutture precedentemente installate ed inamovibili e/o altri ostacoli di qualsiasi tipo, e tale situazione non fosse stata espressamente citata nella RDO, il Cliente/Committente dovrà informarne <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> precedentemente all'invio del Programma Lavori definitivo in quanto quest'ultimo potrebbe, di conseguenza, subire variazioni.</p><p>- Il Cliente/Committente potrà richiedere a <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> a condizioni da concordare, un sopralluogo delle aree di cantiere oggetto dei servizi di montaggio/smontaggio preventivamente all'emissione dell'Ordine di Acquisto e del Programma Lavori definitivo.</p><p>- Il Cliente/Committente metterà a disposizione una zona di stoccaggio materiale per assemblaggio, quantificato nel doppio della platea in caso di autoportante e da valutare nel caso in cui siano scaffalature all'interno del magazzino.&nbsp;</p><p>Quanto sopra previo sopralluogo da eseguirsi in collaborazione con un tecnico incaricato di <strong>CONSORZIO SOLUZIONE MONTAGGI.</strong></p><p>È responsabilità del Cliente fornire l'area più idonea assicurando il più facile accesso nonché movimentazione dei materiali. Qualora <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> dovesse valutare che le aree di stoccaggio preposte non fossero quelle più idonee, <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> chiederà di cambiare dette aree. In caso di mancata concessione delle aree, <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> non sarà ritenuta responsabile per gli eventuali costi aggiuntivi sostenuti, a causa del maggior tempo impiegato per la movimentazione dei materiali e non potrà garantire il rispetto delle date di completamento dell'installazione precedentemente concordate e avrà diritto al rimborso dei relativi costi aggiuntivi.</p><p><strong>CONSORZIO SOLUZIONE MONTAGGI</strong> non si riterrà responsabile di eventuali ammanchi di materiale ad esso affidati per la realizzazione dell'opera. Eventuale sorveglianza o deposito dello stesso dev'essere preventivamente concordato e pattuito.</p><p>- Il Cliente/Committente dovrà provvedere alla fornitura ininterrotta in cantiere di energia elettrica e acqua.</p><p>- Il Cliente/Committente dovrà provvedere (in caso di autoportanti) alla fornitura di una platea idonea al montaggio di una gru edile con i relativi calcoli, l'impianto elettrico, la messa a terra e la certificazione della stessa.</p><p>- Il Cliente/Committente dovrà provvedere alla fornitura quadro elettrico di cantiere a norma di legge</p><p>- Il Cliente/Committente consegnerà prima dell'apertura del cantiere:</p><ul><li>disegni di installazione in formato DWG e PDF</li><li>distinte dei materiali</li><li>tolleranze di montaggio</li><li>schemi assemblaggio</li></ul><p>- I servizi di <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> saranno forniti a regola d'arte e nel pieno rispetto della legislazione italiana e delle normative europee ad essi attinenti. Sarà compito del Cliente/Committente rendere preventivamente edotta <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> di qualunque normativa locale, estera e non, o nazionale, sia dell'UE che extra UE, attinente in qualsivoglia modo all'oggetto della presente fornitura.</p><p>- Nel caso in cui il Cliente/Committente sia responsabile della fornitura di strumenti e mezzi di sollevamento in cantiere, è responsabilità del Cliente/Committente procurare gli stessi in modo tempestivo e in buone condizioni di funzionamento. Gli strumenti e i mezzi di sollevamento devono essere conformi sia alle esigenze di <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> che ai requisiti in loco. In caso contrario, <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> non sarà responsabile per eventuali costi aggiuntivi derivanti da apparecchiature non idonee, danneggiate e/o consegnate in ritardo. <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> avrà il diritto di addebitare tutti i costi derivati al Cliente/Committente.</p><p>- La consegna del materiale gestita/concordata dal Cliente/Committente dovrà essere conforme alle fasi lavorative in cantiere e le date di consegna dovranno essere definite con precisione e rigorosamente rispettate. In caso contrario, <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> non potrà garantire il rispetto delle date di completamento dell'installazione precedentemente concordate e avrà diritto alle necessarie modifiche dell'offerta.</p><p>-&nbsp; Nel caso di cantieri dalla durata inferiore a giorni 2, venisse fornita la resina a lenta asciugatura la cui presa sarà garantita dal giorno successivo al termine del montaggio, sarà necessario inviare del personale a serrare i dadi delle barre filettate, pertanto sarà chiesto di riconoscerci un extra costo</p><p>- Ogni lavoro extra/aggiuntivo non concordato nel Contratto/Ordine di Acquisto sarà calcolato su base oraria al prezzo di 35 euro (trentacinque)/ora-uomo per la manodopera, con aggiunta dell'eventuale costo dei mezzi di sollevamento, salvo diverso accordo precedente.</p><p>- Ogni lavoro aggiuntivo sarà preventivamente concordato per iscritto prima dell'effettiva esecuzione.</p><p><strong>I seguenti eventi saranno considerati come lavoro extra:</strong></p><p>· Tempi di attesa causati dalla consegna tardiva del materiale (il piano delle consegne dei materiali in cantiere dovrà pervenire 15 giorni prima dell'inizio dei lavori – in caso di autoportante o per ordini superiori a € 30.000,00)</p><p>· Tempi di attesa causati da interruzione del lavoro indipendente dalla responsabilità <strong>di CONSORZIO SOLUZIONE MONTAGGI</strong></p><p>· Tempi aggiuntivi impiegati a causa della consegna materiale non in linea con le tempistiche del progetto</p><p>· Riparazione dell'attrezzatura che ricade sotto la responsabilità del Cliente/Committente</p><p>· Lavori aggiuntivi causati da materiale difettoso o mancante</p><p>· Una volta definito, lo spazio di lavoro è ad uso esclusivo di <strong>CONSORZIO SOLUZIONE MONTAGGI&nbsp;</strong></p><p>· In caso di richiesta da parte di un'azienda terza di spostare materiale nel nostro spazio di lavoro, il tempo dedicato verrà calcolato come costo aggiuntivo</p><p>· Tempo di attesa derivante da interferenza con altra/e azienda/e</p><p>· Nel caso in cui gli spazi concordati (di lavoro e spazio esterno) non siano pronti per il lavoro, la preparazione verrà addebitata come lavoro aggiuntivo</p><p>· In caso di interruzione dei lavori indipendente dalla responsabilità di <strong>CONSORZIO SOLUZIONE MONTAGGI</strong>, tutti i costi relativi sostenuti saranno calcolati come lavori aggiuntivi</p><p>· Qualsiasi altra interruzione o lavoro aggiuntivo che non rientri nelle competenze e non ricada sotto la responsabilità di <strong>CONSORZIO SOLUZIONE MONTAGGI</strong>&nbsp;</p><p>· Le perforazioni che incontrano il ferro d'armatura vengono eseguite con la perforazione diamantata. Ogni carota verificabile contenente il ferro d'armatura viene addebitata per pezzo da 25,00 € + IVA a 45,00 € + IVA, a seconda delle dimensioni della testa di perforazione e della profondità del foro. Il primo 3% dei fori non sarà fatturato separatamente e tale attività sarà considerata nell'ambito del contratto. Sarà addebitato anche il costo della corona utilizzata per effettuare il carotaggio in base al costo della stessa corona utilizzata.</p><p>- In caso di posticipo e/o annullamento del progetto<strong>, CONSORZIO SOLUZIONE MONTAGGI</strong> si riserva il diritto di emettere fattura per tutti i costi sostenuti per l'organizzazione del progetto (ad es. Costi per visti, costi per certificati, prenotazione di mezzi di sollevamento, mezzi di viaggio e hotel)</p><p>- Il sabato è da considerarsi giornata lavorativa.</p><p>- In generale, l'orario di accesso in cantiere deve essere garantito dalle ore 07:00 alle ore 19:00. L'orario di lavoro sarà valutato insieme al Cliente/Committente e avrà una durata minima di 8/9 ore effettive al giorno.</p><p>- Il costo indicato, se non esplicitamente richiesto in fase d'offerta, si riferisce ad attività da effettuarsi all'interno di locali a temperatura ambiente.</p><p>- <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> non sarà responsabile per problemi che possano verificarsi a causa di forza maggiore né del tempo perso a conseguenza di ciò.</p><p><strong>ESCLUSIONI</strong></p><p><strong>Salvo diversa indicazione contenuta in offerta</strong>, quanto segue non è compreso nei limiti di fornitura e sarà approvvigionato o eseguito a cura del Committente e/o del Cliente Finale. Previo accordo, alcune voci potranno in alcuni casi essere approvvigionate o eseguite da <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> e fatturate come da condizioni concordate:</p><p>· Certificati ed attestati dei propri dipendenti emessi da professionisti, enti ed istituzioni di Paesi esteri. Tutti i dipendenti della società <strong>CONSORZIO SOLUZIONE MONTAGGI</strong> sono sottoposti a visita medica del lavoro in Italia e informati e formati sui rischi generali e specifici della propria attività lavorativa e, in base alla mansione ricoperta, sull'uso dei DPI di 3^ categoria, sui rischi dei lavori in altezza, sulle procedure di sicurezza relative, sulla gestione del rischio incendio e del primo soccorso, autorizzati alla conduzione dei vari mezzi di sollevamento e certificati come saldatori professionali in conformità alla normativa italiana e europea (UE). Qualunque costo, (diretto o indiretto) relativo all'adeguamento e/o alla riemissione di detti certificati ed attestati in qualunque Paese estero rimane a carico del Cliente e sarà fatturato separatamente.</p><p>· Smaltimento in discarica dei rifiuti di risulta. Resta inteso che la suddivisione per tipologia di rifiuto è sempre compresa nelle nostre attività. Resta a carico del Committente/Cliente la predisposizione delle aree idonee a tale attività</p><p>· Lo scarico del materiale è compreso nell'offerta, ma è da considerarsi da effettuare all'interno dell'area di montaggio (a piè d'opera). Eventuali spostamenti del materiale dal punto di scarico al punto di assemblaggio/montaggio che comportino la movimentazione attraverso altri locali/corridoi dovranno esserci preventivamente comunicati e saranno da noi valutati.</p><p>· In caso di scarico merce all'esterno del deposito/magazzino e inserimento del materiale attraverso baie di carico, sarà da valutare un importo economico.</p><p>· Eventuali opere murarie o simili</p><p>· Costi e spese per il rilascio di visti, permessi e/o vaccinazioni al di fuori del territorio italiano</p><p>· Eventuali costi aggiuntivi di viaggio, vitto e alloggio derivanti da modifiche al Programma Lavori</p><p>&nbsp;non imputabili a <strong>CONSORZIO SOLUZIONE MONTAGGI</strong>&nbsp;</p><p>· Terreno/i, tasse e ispezioni</p><p>· Permessi di progetto e costruzione</p><p>· Lavori di movimentazione terra</p><p>· Attività derivanti dalla posizione della platea non a livello del suolo</p><p>· Lavori stradali</p><p>· Fondazioni</p><p>· Progettazione/ingegneria</p><p>· Progettazione/ingegneria utenze</p><p>· Personale per funzionamento magazzino</p><p>· Carpenteria metallica</p><p>· Stazione riduzione pressione gas e rete di alimentazione fino ai punti di attacco delle strumentazioni</p><p>· Sistema di messa a terra</p><p>· Impianti antincendio</p><p>· Stazione trasformatore elettrico, compreso pannello di distribuzione principale linee di alimentazione ai pannelli tecnologici</p><p>· Set generatore di emergenza collegato al pannello di distribuzione principale</p><p>· Fornitura materiali da assemblare/installare (carpenterie metalliche, pannelli, reti, cavi etc.)</p><p>· Fornitura ininterrotta di acqua, energia elettrica e aria compressa per l'intera durata dei lavori</p><p>· Servizi igienici di cantiere (quotati a parte)</p><p>· Safety Manager</p><p>. Opere di cantierizzazione</p><p>· Tutto quanto non espressamente indicato nella presente offerta</p>"
    },
    template2: {
        oggetto: "Oggetto di default - Template 2",
        items: [
            { description: "Riga predefinita (T2)", amount: "200" }
        ],
        termini_pagamento: "Termini di pagamento per Template 2",
        condizioni: "Condizioni generali per Template 2"
    },
    template3: {
        oggetto: "VISITA ANNUALE DI CONTROLLO DELLE STRUTTURE METALLICHE PER L'IMMAGAZZINAGGIO, AI SENSI DELLE NORME UNI-EN 15635 E UNI-EN 15629 PRESSO VS. CLIENTE",
        items: [
            {
                description: `Verifica annuale scaffalature – Sopralluogo realizzato da tecnici specializzati (ingegneri strutturisti che hanno effettuato i regolari corsi sulla base del D.lgs. 81/08, Uni En 15629 e Uni En 15635) per la verifica periodica. La prestazione comprende:
- Rilievo disposizione scaffalature e livelli di carico;
- Redazione di layout grafico dell'edificio e delle scaffalature presenti con misurazione sommaria delle aree di magazzino;
- Verifica documentazione esistente ed aggiornamento delle variazioni intercorse;
- Controllo visivo generale da terra (spalle-correnti–traverse);
- Verifica a campione serraggio tasselli a terra (paracolpi-spalleguardrail);
- Controllo di verticalità delle spalle effettuato con strumento posizionato a terra;
- Rilievo fotografico delle anomalie;
- Caratterizzazione delle anomalie (verde-giallo-rosso);
- Redazione scheda di VERIFICA PERIODICA SCAFFALATURE;
- Redazione del rapporto di Verifica Ispettiva corredato da immagini;
- Formazione del RSPP sul livello di danno;
- Computo metrico con descrizione delle operazioni di riparazione necessarie.`,
                amount: "100"
            },
            {
                description: `Spese vive per trasferta e rimborso pasti
È previsto 1 giorno di campagna di rilievo con spostamenti con mezzo proprio.`,
                amount: "250"
            }
        ],
        termini_pagamento: `<p>Termini e modalità di pagamento:</p>
            <ul>
                <li>Soliti in essere</li>
                <li>Tutti gli importi su esposti sono IVA Esclusa<br>&nbsp;</li>
            </ul>`,
        condizioni: `<p>Le tempistiche previste per concludere l'incarico consistono in circa 4 gg lavorativi comprensivi delle giornate di sopralluogo.<br>
            RingraziandoVi per la vostra richiesta, cogliamo l'occasione per porgere i nostri più cordiali saluti.</p>`
    },
    template4: {
        oggetto: "CERTIFICAZIONE STATICA PORTAPALLET PRESSO VS",
        items: [
            { description: `Redazione di relazione di verifica strutturale in condizioni di carico statico per n. 1 tipologia di scaffalatura portapallet
Le relazioni verificano la struttura "come nuova", non considerando eventuali danni agli elementi costruttivi già presenti al momento del sopralluogo né eventuali differenze locali nella tipologia degli elementi costruttivi tipici dell'impianto.` , amount: "100" },
            { description: `Redazione e spedizione dei cartelli di portata
Il layout grafico sarà fornito in formato digitale .pdf, riquadrato in formato adeguato a garantirne la leggibilità, da stampare a cura e spese del Cliente.
`, amount: "250" },
            { description: `Spese vive per sopralluogo e rilievo configurazione struttura`, amount: "250" }
        ],
        termini_pagamento: "<p>Termini e modalità di pagamento:</p><ul><li>B.B. 30 gg fm</li><li>Tutti gli importi su esposti sono IVA Esclusa</li><li>Validità offerta: 60 giorni<br>&nbsp;</li></ul>",
        condizioni: "<p>Le tempistiche previste per concludere l'incarico consistono in circa 10 gg lavorativi, comprensivi della visita di rilievo e sopralluogo.<br>RingraziandoVi per la vostra richiesta, cogliamo l'occasione per porgere i nostri più cordiali saluti.</p>"
    }
};

// --------------------------------------------------------------
// Update progress indicator based on current step
// --------------------------------------------------------------
function updateProgress(step) {
    const track = document.getElementById('progress-track');
    if (track) {
        // Remove all step classes
        track.classList.remove('step-1', 'step-2', 'step-3', 'step-4');
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
        dropdownParent: 'body',
        load: function (query, callback) {
            if (!query.length) return callback();
            fetch('/clients/search?query=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => callback(data))
                .catch(() => callback());
        }
    });

    let staticAmountField = document.querySelector('#static-row .amount-field');

    staticAmountField.addEventListener('blur', function() {
        let val = parseNumberOnlyIfPure(this.value);
        if (val !== null) {
            this.value = formatItalianNumber(val);
        }
        calcolaTotale();
    });

    staticAmountField.addEventListener('input', calcolaTotale);

    // Event listener per il campo totale modificabile
    let totalAmountField = document.getElementById('total-amount');
    if (totalAmountField) {
        totalAmountField.addEventListener('blur', function() {
            let val = parseNumberOnlyIfPure(this.value);
            if (val !== null) {
                this.value = formatItalianNumber(val);
            }
            // Non aggiorniamo il totale calcolato - l'utente può scrivere "da definire" ecc.
        });
    }

    ClassicEditor.create(document.querySelector('#termini_pagamento'))
        .then(editor => {
            editorTermini = editor;
        })
        .catch(error => console.error(error));

    ClassicEditor.create(document.querySelector('#condizioni'))
        .then(editor => {
            editorCondizioni = editor;
        })
        .catch(error => console.error(error));

    // Initialize template card selection
    document.querySelectorAll('.cfo-template-card[data-template]').forEach(card => {
        card.addEventListener('click', function() {
            const templateId = this.dataset.template;
            applyTemplate(templateId);
        });
    });

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



//old format italian number
/*
   function formatItalianNumber(num) {
    return num.toLocaleString('it-IT', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' €';
}

 */

// --------------------------------------------------------------
// Calcolo del totale (somma solo righe puramente numeriche)
// Il totale è modificabile manualmente (es. "da definire")
// --------------------------------------------------------------
function calcolaTotale() {
    let total = 0;
    // Somma solo le righe degli items, non il campo totale
    document.querySelectorAll('#static-row .amount-field, #items-container .amount-field').forEach(field => {
        let val = parseNumberOnlyIfPure(field.value);
        // Se val === null => leftover text => skip
        if (val !== null) {
            total += val;
        }
    });

    // Update visible calculated total display (solo informativo)
    let totalCalculated = document.getElementById('total-calculated');
    if (totalCalculated) {
        totalCalculated.textContent = formatItalianNumber(total);
    }

    // Update hidden input per il salvataggio
    let totalInput = document.getElementById('total-amount');
    if (totalInput) {
        totalInput.value = formatItalianNumber(total);
    }
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
// Apply template and advance to step 2
// --------------------------------------------------------------
function applyTemplate(templateId) {
    if (!templateData[templateId]) return;

    let dataT = templateData[templateId];

    // Precompilo oggetto
    document.getElementById('oggetto').value = dataT.oggetto;

    // Riga statica
    let staticDesc = document.querySelector('#static-row .description-field');
    let staticAmount = document.querySelector('#static-row .amount-field');

    if (dataT.items.length > 0) {
        staticDesc.value = dataT.items[0].description;
        let initialVal = parseItalianNumber(dataT.items[0].amount);
        staticAmount.value = formatItalianNumber(initialVal);
    }

    // Righe dinamiche
    document.getElementById('items-container').innerHTML = '';
    if (dataT.items.length > 1) {
        for (let i = 1; i < dataT.items.length; i++) {
            let item = dataT.items[i];
            aggiungiRiga(
                item.description,
                formatItalianNumber(parseItalianNumber(item.amount))
            );
        }
    }

    // Editor
    setTimeout(() => {
        editorTermini.setData(dataT.termini_pagamento);
        editorCondizioni.setData(dataT.condizioni);
    }, 300);

    calcolaTotale();

    // Passo allo step 2
    goToStep(2);
}

// --------------------------------------------------------------
// Navigate to step
// --------------------------------------------------------------
function goToStep(step) {
    // Hide all steps
    document.getElementById('step1').classList.add('hidden');
    document.getElementById('step2').classList.add('hidden');
    document.getElementById('step3').classList.add('hidden');
    document.getElementById('step4').classList.add('hidden');

    // Show target step
    document.getElementById(`step${step}`).classList.remove('hidden');

    // Update progress indicator
    updateProgress(step);
}

// --------------------------------------------------------------
// Navigazione Step (event delegation - buttons don't exist on page load)
// --------------------------------------------------------------
document.addEventListener('click', function(e) {
    // Find the button element (e.target might be text inside button)
    const button = e.target.closest('button[id]');
    if (!button) return;

    const id = button.id;
    if (id === 'toStep2NoTemplate') {
        goToStep(2);
    } else if (id === 'toStep3') {
        goToStep(3);
    } else if (id === 'toStep4') {
        goToStep(4);
    } else if (id === 'backToStep1') {
        goToStep(1);
    } else if (id === 'backToStep2') {
        goToStep(2);
    } else if (id === 'backToStep3') {
        goToStep(3);
    } else if (id === 'add-row') {
        aggiungiRiga('', '');
    }
});

// --------------------------------------------------------------
// Submit: serializzo la riga statica + righe dinamiche
// --------------------------------------------------------------
document.querySelector('form').addEventListener('submit', function () {
    let items = [];

    // RIGA STATICA
    let staticDesc = document.querySelector('#static-row .description-field').value.trim();
    let staticAmount = document.querySelector('#static-row .amount-field').value.trim();

    if (staticDesc && staticAmount) {
        let val = parseNumberOnlyIfPure(staticAmount);
        items.push({
            description: staticDesc,
            amount: val !== null ? val.toFixed(2) : staticAmount
        });
    }

    // RIGHE DINAMICHE — SOLO item-row
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
