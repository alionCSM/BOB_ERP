// ── Struttura mode ─────────────────────────────────────────────────────────
var strutturaMode = 'search';
var strutturaTS   = null;
var _bkForm         = document.querySelector('form[data-period-index]');
var _oldStrutturaId   = JSON.parse(_bkForm?.dataset.oldStrutturaId   || '""');
var _oldStrutturaNome = JSON.parse(_bkForm?.dataset.oldStrutturaNome || '""');

function setStrutturaMode(mode) {
    strutturaMode = mode;
    var searchDiv  = document.getElementById('struttura-mode-search');
    var newDiv     = document.getElementById('struttura-mode-new');
    var detailsDiv = document.getElementById('struttura-details');
    var searchBtn  = document.getElementById('mode-search-btn');
    var newBtn     = document.getElementById('mode-new-btn');

    if (mode === 'search') {
        searchDiv.style.display  = '';
        newDiv.style.display     = 'none';
        searchBtn.classList.add('active');
        newBtn.classList.remove('active');
        document.getElementById('struttura_id').value   = '';
        document.getElementById('struttura_nome').value = '';
        detailsDiv.style.display = 'none';
        clearStrutturaFields();
    } else {
        searchDiv.style.display  = 'none';
        newDiv.style.display     = '';
        newBtn.classList.add('active');
        searchBtn.classList.remove('active');
        if (strutturaTS) strutturaTS.clear(true);
        document.getElementById('struttura_id').value = '';
        detailsDiv.style.display = '';
        clearStrutturaFields();
        setTimeout(function () { document.getElementById('struttura_nome_input').focus(); }, 100);
    }
}

function clearStrutturaFields() {
    ['struttura_telefono', 'struttura_indirizzo', 'struttura_citta',
     'struttura_provincia', 'struttura_ragione_sociale'].forEach(function (id) {
        document.getElementById(id).value = '';
    });
    document.getElementById('struttura_country').value = 'Italia';
}

function fillStrutturaFields(data) {
    document.getElementById('struttura_nome').value             = data.nome            || '';
    document.getElementById('struttura_telefono').value         = data.telefono        || '';
    document.getElementById('struttura_indirizzo').value        = data.indirizzo       || '';
    document.getElementById('struttura_citta').value            = data.citta           || '';
    document.getElementById('struttura_provincia').value        = data.provincia       || '';
    document.getElementById('struttura_country').value          = data.country         || 'Italia';
    document.getElementById('struttura_ragione_sociale').value  = data.ragione_sociale || '';
}

// Sync struttura_nome hidden field while typing in "crea nuova" mode
var _strutturaNameInput = document.getElementById('struttura_nome_input');
if (_strutturaNameInput) {
    _strutturaNameInput.addEventListener('input', function () {
        document.getElementById('struttura_nome').value = this.value;
    });
}

function initStrutturaSearch() {
    var el = document.getElementById('struttura-search-select');
    if (strutturaTS) strutturaTS.destroy();

    strutturaTS = new TomSelect(el, {
        valueField:  'id',
        labelField:  'nome',
        searchField: ['nome', 'citta', 'indirizzo'],
        load: function (query, callback) {
            if (query.length < 2) return callback();
            var type = document.getElementById('type-input').value;
            fetch('/bookings/search-strutture?q=' + encodeURIComponent(query) + '&type=' + encodeURIComponent(type))
                .then(function (r) { return r.json(); })
                .then(function (data) { callback(data); })
                .catch(function () { callback(); });
        },
        render: {
            option: function (data, escape) {
                return '<div style="padding:10px 12px;">' +
                    '<div style="font-weight:600;color:#1e293b;">' + escape(data.nome) + '</div>' +
                    (data.citta ? '<div style="font-size:11px;color:#94a3b8;margin-top:2px;">' +
                        escape(data.citta) + (data.indirizzo ? ' — ' + escape(data.indirizzo) : '') +
                        '</div>' : '') +
                    '</div>';
            },
            item: function (data, escape) {
                return '<div>' + escape(data.nome) + '</div>';
            },
            no_results: function () {
                return '<div style="padding:12px 14px;color:#94a3b8;font-size:12px;text-align:center;">' +
                    'Nessun risultato. Usa <strong>"Crea nuova"</strong> per inserire una nuova struttura.' +
                    '</div>';
            },
        },
        onChange: function (value) {
            var detailsDiv = document.getElementById('struttura-details');
            if (!value) {
                detailsDiv.style.display = 'none';
                document.getElementById('struttura_id').value   = '';
                document.getElementById('struttura_nome').value = '';
                return;
            }
            document.getElementById('struttura_id').value = value;
            detailsDiv.style.display = '';
            fetch('/bookings/get-struttura?id=' + encodeURIComponent(value))
                .then(function (r) { return r.json(); })
                .then(function (data) { fillStrutturaFields(data); })
                .catch(function (e) { console.error(e); });
        },
        placeholder: 'Digita il nome della struttura...',
    });

    if (_oldStrutturaId && _oldStrutturaNome) {
        strutturaTS.addOption({ id: _oldStrutturaId, nome: _oldStrutturaNome });
        strutturaTS.setValue(_oldStrutturaId, true);
    }
}

// Init TomSelects
new TomSelect('#worksite-select',     { allowEmptyOption: true, placeholder: 'Cerca cantiere...' });
new TomSelect('#capo-squadra-select', { allowEmptyOption: true, placeholder: 'Cerca capo squadra...' });
initStrutturaSearch();

if (_bkForm?.dataset.oldStrutturaInNewMode === '1') {
    setStrutturaMode('new');
    document.getElementById('struttura_nome_input').value = _oldStrutturaNome;
    document.getElementById('struttura_nome').value       = _oldStrutturaNome;
}

// ── Type toggle ────────────────────────────────────────────────────────────
function setType(t) {
    document.getElementById('type-input').value = t;
    document.querySelectorAll('.bk-type-btn').forEach(function (b) { b.classList.remove('active'); });
    var activeBtn = document.querySelector('.bk-type-btn[data-bk-type="' + t + '"]');
    if (activeBtn) activeBtn.classList.add('active');
    document.getElementById('restaurant-fields').style.display = t === 'restaurant' ? 'block' : 'none';
    document.getElementById('hotel-fields').style.display      = t === 'hotel'      ? 'block' : 'none';
    document.getElementById('header-icon').className  = 'bk-header-icon' + (t === 'hotel' ? ' hotel' : '');
    document.getElementById('header-title').textContent = t === 'hotel'
        ? 'Nuova Prenotazione Hotel'
        : 'Nuova Prenotazione Ristorante';
    document.getElementById('submit-btn').className = 'bk-btn-submit' + (t === 'hotel' ? ' hotel' : '');
    // Re-init search to filter by new type
    initStrutturaSearch();
}

// ── Periods ────────────────────────────────────────────────────────────────
var periodIndex = parseInt(_bkForm?.dataset.periodIndex || '1', 10);

function addPeriod() {
    var c   = document.getElementById('periods-container');
    var i   = periodIndex++;
    var row = document.createElement('div');
    row.className    = 'bk-period-row';
    row.dataset.index = i;
    row.innerHTML =
        '<div class="bk-period-field" style="width:120px;"><span class="bk-period-label">Dal</span>' +
        '<input type="date" name="periods[' + i + '][data_dal]" class="bk-period-input period-dal"></div>' +
        '<div class="bk-period-field" style="width:120px;"><span class="bk-period-label">Al</span>' +
        '<input type="date" name="periods[' + i + '][data_al]" class="bk-period-input period-al"></div>' +
        '<div class="bk-period-field" style="width:85px;"><span class="bk-period-label">Persone</span>' +
        '<input type="number" name="periods[' + i + '][n_persone]" class="bk-period-input period-persone" min="1" placeholder="4"></div>' +
        '<div class="bk-period-field" style="width:100px;"><span class="bk-period-label">€ / persona</span>' +
        '<input type="number" name="periods[' + i + '][prezzo_persona]" class="bk-period-input period-prezzo" step="0.01" min="0" placeholder="28.00" required></div>' +
        '<div class="bk-period-field grow"><span class="bk-period-label">Nota</span>' +
        '<input type="text" name="periods[' + i + '][note]" class="bk-period-input" placeholder="Es. arrivati 2 in più"></div>' +
        '<span class="bk-period-subtotal period-subtotal" style="display:none;"></span>' +
        '<button type="button" class="bk-period-remove" title="Rimuovi periodo">' +
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">' +
        '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
    c.appendChild(row);
    bindPeriodCalc(row);
}

function removePeriod(btn) {
    if (document.querySelectorAll('.bk-period-row').length <= 1) {
        alert('Serve almeno un periodo.');
        return;
    }
    btn.closest('.bk-period-row').remove();
    calcGrandTotal();
}

function calcRowSubtotal(row) {
    var dalEl = row.querySelector('.period-dal');
    var alEl  = row.querySelector('.period-al');
    var pEl   = row.querySelector('.period-persone');
    var prEl  = row.querySelector('.period-prezzo');
    var el    = row.querySelector('.period-subtotal');
    var dal = dalEl ? dalEl.value : '';
    var al  = alEl  ? alEl.value  : '';
    var p   = parseFloat(pEl  ? pEl.value  : 0) || 0;
    var pr  = parseFloat(prEl ? prEl.value : 0) || 0;
    if (!dal || !al || p <= 0 || pr <= 0) {
        if (el) { el.style.display = 'none'; el.textContent = ''; }
        return 0;
    }
    var days  = Math.max(1, Math.round((new Date(al) - new Date(dal)) / 86400000) + 1);
    var total = days * p * pr;
    if (el) {
        el.style.display = '';
        el.textContent   = days + 'gg × ' + p + ' × €' + pr.toFixed(0) + ' = €' +
            total.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    return total;
}

function calcGrandTotal() {
    var g = 0;
    document.querySelectorAll('.bk-period-row').forEach(function (r) { g += calcRowSubtotal(r); });
    var el = document.getElementById('grand-total');
    if (g > 0) {
        el.classList.add('visible');
        document.getElementById('grand-total-value').textContent =
            '€' + g.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    } else {
        el.classList.remove('visible');
    }
}

function bindPeriodCalc(row) {
    row.querySelectorAll('.period-dal,.period-al,.period-persone,.period-prezzo').forEach(function (inp) {
        inp.addEventListener('input', calcGrandTotal);
    });
}

document.querySelectorAll('.bk-period-row').forEach(bindPeriodCalc);
calcGrandTotal();

// ── Event delegation ────────────────────────────────────────────────────────
document.addEventListener('click', function (e) {
    // Type toggle
    var typeBtn = e.target.closest('[data-bk-type]');
    if (typeBtn) { setType(typeBtn.dataset.bkType); return; }

    // Struttura mode
    var modeBtn = e.target.closest('[data-bk-mode]');
    if (modeBtn) { setStrutturaMode(modeBtn.dataset.bkMode); return; }

    // Add period
    if (e.target.closest('[data-bk-action="add-period"]')) { addPeriod(); return; }

    // Remove period
    var removeBtn = e.target.closest('.bk-period-remove');
    if (removeBtn) { removePeriod(removeBtn); return; }
});

// ── Consorziata checkbox toggle ─────────────────────────────────────────────
(function () {
    var chk  = document.getElementById('chk-consorziata');
    var wrap = document.getElementById('consorziata-select-wrap');
    if (!chk || !wrap) return;
    chk.addEventListener('change', function () {
        wrap.style.display = this.checked ? 'block' : 'none';
        if (!this.checked) {
            var sel = wrap.querySelector('select');
            if (sel) sel.value = '';
        }
    });
}());
