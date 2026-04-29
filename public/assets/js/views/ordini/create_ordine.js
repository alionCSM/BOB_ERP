// ── Create / Edit Ordine ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

    const container = document.getElementById('itemsContainer');
    const addBtn    = document.getElementById('addItemRow');
    const ivaSelect = document.getElementById('iva_percentage');

    // ── TomSelect on header selects ────────────────────────────────────────
    if (typeof TomSelect !== 'undefined') {
        if (document.getElementById('worksite_id')) {
            new TomSelect('#worksite_id', {
                create: false,
                sortField: { field: 'text', direction: 'asc' },
                placeholder: '— Seleziona cantiere —',
                dropdownParent: 'body',
            });
        }
        if (document.getElementById('destinatario_id')) {
            new TomSelect('#destinatario_id', {
                create: false,
                sortField: { field: 'text', direction: 'asc' },
                placeholder: '— Seleziona consorziata —',
                dropdownParent: 'body',
            });
        }
    }

    // ── Article code lookup ────────────────────────────────────────────────
    // Codes with *CANTIERE* have the selected worksite name appended automatically
    const CODICI = {
        'D':         '',
        'M':         '',
        'MAT':       '',
        'PRE':       'VI PRESENTIAMO ORDINE PER LAVORI DI MONTAGGIO SCAFFALATURE DA ESEGUIRE PRESSO CANTIERE *CANTIERE*',
        'ELE':       'VI PRESENTIAMO FATTURA PER LAVORI DI INSTALLAZIONE ELETTROMECCANICA ESEGUITI PRESSO: *CANTIERE*',
        'FITTI ATT': 'AFFITTI ATTIVI IMMOBILI',
        'CESS':      'CESSIONE OCCASIONALE DI BENI',
        'ORD':       "DECORSI 2 GIORNI DAL RICEVIMENTO DEL PRESENTE SENZA OBIEZIONI O RICHIESTE, L'ORDINE SI INTENDE ACCETTATO IN OGNI SUA PARTE.",
        'NOMEZ':     "IL COSTO DEI MEZZI DI SOLLEVAMENTO E' A CARICO DEL CONSORZIO",
        'LAV':       'Lavorazioni da terzi',
        'SMONT':     'LAVORO DI SMONTAGGIO',
        'MONT':      'Lavoro Montaggio',
        'RAPP':      "L'ORDINE VERRA' QUANTIFICATO DIETRO RICEZIONE DEL RAPPORTINO DI LAVORO FIRMATO DAL CLIENTE",
        'CON':       "L'ORDINE VERRA' VALORIZZATO DIETRO RICEZIONE DEL RAPPORTINO FIRMATO E TIMBRATO DAL CLIENTE",
        'CONS':      'RIMBORSO SERVIZI A CONSORZIATE',
        'NOLO':      'RIMBORSO SPESE DI NOLEGGIO',
        'TRASP':     'RIMBORSO SPESE DI TRASPORTO',
        'VARIE':     'RIMBORSO SPESE VARIE',
        'VEND':      'VENDITA DI BENI DESTINATI ALLA PRODUZIONE DI SERVIZI',
        'VISP':      'VI PRESENTIAMO ORDINE PER VISITA ISPETTIVA PRESSO *CANTIERE*',
    };

    function getWorksite() {
        const sel = document.getElementById('worksite_id');
        if (!sel) return '';
        // TomSelect wraps the select — read selected option text
        const val = sel.value;
        if (!val) return '';
        const opt = sel.querySelector('option[value="' + val + '"]');
        return opt ? opt.textContent.trim() : '';
    }

    function applyCode(cod, descInput) {
        if (!cod) return;
        const key = Object.keys(CODICI).find(k => k.toUpperCase() === cod.toUpperCase());
        if (key === undefined) return;
        let desc = CODICI[key];
        if (desc.includes('*CANTIERE*')) {
            desc = desc.replace('*CANTIERE*', getWorksite()).trim();
        }
        descInput.value = desc;
    }

    // Build <option> list for code select
    const codOptionsHtml = '<option value="">— Cod —</option>' +
        Object.keys(CODICI).map(k => '<option value="' + k + '">' + k + '</option>').join('');

    // ── Row builder ────────────────────────────────────────────────────────
    function buildRow(data) {
        data = data || {};
        const selId = 'cod-sel-' + Math.random().toString(36).slice(2, 8);
        const row = document.createElement('div');
        row.className = 'cof-item-row';
        row.innerHTML = `
            <select name="item_cod[]" id="${selId}" style="min-width:0;">${codOptionsHtml}</select>
            <textarea name="item_desc[]" placeholder="Descrizione..." rows="2" autocomplete="off">${escText(data.descrizione || '')}</textarea>
            <input type="text"   name="item_um[]"     placeholder="N" value="${esc(data.um || 'N')}" style="text-align:center;" autocomplete="off">
            <input type="number" name="item_qta[]"    placeholder="1" value="${esc(data.qta || '1')}" step="0.001" min="0" class="text-right" autocomplete="off">
            <input type="number" name="item_prezzo[]" placeholder="0,00" value="${esc(data.prezzo_unitario || '')}" step="0.01" min="-999999" class="text-right" autocomplete="off">
            <div class="cof-item-importo" data-importo="0">0,00 €</div>
            <button type="button" class="cof-btn-del-row" title="Rimuovi">
                <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
            </button>`;

        const codEl   = row.querySelector('#' + selId);
        const descEl  = row.querySelector('[name="item_desc[]"]');
        const qta     = row.querySelector('[name="item_qta[]"]');
        const prezzo  = row.querySelector('[name="item_prezzo[]"]');
        const impDiv  = row.querySelector('.cof-item-importo');

        // Set existing value if editing
        if (data.cod_articolo) {
            codEl.value = data.cod_articolo;
        }

        // Init TomSelect on this cod select
        if (typeof TomSelect !== 'undefined') {
            const ts = new TomSelect(codEl, {
                create: false,
                allowEmptyOption: true,
                placeholder: '— Cod —',
                dropdownParent: 'body',
                onChange: function (value) {
                    applyCode(value, descEl);
                },
            });
            // If editing and value already set, sync TomSelect
            if (data.cod_articolo) {
                ts.setValue(data.cod_articolo, true);
            }
        } else {
            // Fallback without TomSelect
            codEl.addEventListener('change', function () {
                applyCode(this.value, descEl);
            });
        }

        function recalcRow() {
            const q = parseFloat(qta.value)    || 0;
            const p = parseFloat(prezzo.value) || 0;
            const imp = Math.round(q * p * 100) / 100;
            impDiv.dataset.importo = imp;
            impDiv.textContent = formatEur(imp);
            recalcTotals();
        }

        qta.addEventListener('input', recalcRow);
        prezzo.addEventListener('input', recalcRow);

        if (data.importo !== undefined) {
            const imp = parseFloat(data.importo) || 0;
            impDiv.dataset.importo = imp;
            impDiv.textContent = formatEur(imp);
        }

        row.querySelector('.cof-btn-del-row').addEventListener('click', function () {
            row.remove();
            recalcTotals();
        });

        return row;
    }

    function esc(str) {
        return String(str).replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    // For textarea content (between tags, not attribute values)
    function escText(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function formatEur(n) {
        return n.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    // ── Totals ─────────────────────────────────────────────────────────────
    function recalcTotals() {
        let merce = 0;
        document.querySelectorAll('.cof-item-importo').forEach(function (el) {
            merce += parseFloat(el.dataset.importo) || 0;
        });
        const ivaPerc  = parseFloat(ivaSelect ? ivaSelect.value : 0) || 0;
        const ivaAmt   = Math.round(merce * ivaPerc) / 100;
        const totDoc   = Math.round((merce + ivaAmt) * 100) / 100;

        const sub = document.getElementById('subtotalDisplay');
        const tm  = document.getElementById('totaleMerce');
        const ia  = document.getElementById('ivaAmount');
        const il  = document.getElementById('ivaLabel');
        const td  = document.getElementById('totaleDocumento');
        const ir  = document.getElementById('ivaRow');

        if (sub) sub.textContent = formatEur(merce);
        if (tm)  tm.textContent  = formatEur(merce);
        if (ia)  ia.textContent  = formatEur(ivaAmt);
        if (il)  il.textContent  = 'IVA ' + ivaPerc + '%';
        if (td)  td.textContent  = formatEur(totDoc);
        if (ir)  ir.style.display = ivaPerc > 0 ? '' : 'none';
    }

    // ── Add row button ──────────────────────────────────────────────────────
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            container.appendChild(buildRow({}));
        });
    }

    // ── IVA change ──────────────────────────────────────────────────────────
    if (ivaSelect) {
        ivaSelect.addEventListener('change', recalcTotals);
    }

    // ── Prefill for edit page / first empty row ─────────────────────────────
    const existingItems = container && container.dataset.items
        ? JSON.parse(container.dataset.items)
        : [];
    if (existingItems.length > 0) {
        existingItems.forEach(function (item) {
            container.appendChild(buildRow(item));
        });
        recalcTotals();
    } else if (container && container.children.length === 0) {
        container.appendChild(buildRow({}));
    }
});
