// ── Init ────────────────────────────────────────────────────────────────────
var _bkForm   = document.querySelector('form[data-booking-id]');
var BOOKING_ID  = parseInt(_bkForm?.dataset.bookingId || '0', 10);
var strutturaMode = 'search';
var strutturaTS   = null;

// ── Struttura mode ──────────────────────────────────────────────────────────
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
            fetch('/bookings/search-strutture?q=' + encodeURIComponent(query) +
                  '&type=' + encodeURIComponent(document.getElementById('type-input').value))
                .then(function (r) { return r.json(); })
                .then(function (data) { callback(data); })
                .catch(function () { callback(); });
        },
        render: {
            option: function (data, escape) {
                return '<div style="padding:10px 12px;"><div style="font-weight:600;color:#1e293b;">' +
                    escape(data.nome) + '</div>' +
                    (data.citta ? '<div style="font-size:11px;color:#94a3b8;margin-top:2px;">' +
                        escape(data.citta) + (data.indirizzo ? ' — ' + escape(data.indirizzo) : '') +
                        '</div>' : '') + '</div>';
            },
            item: function (data, escape) { return '<div>' + escape(data.nome) + '</div>'; },
            no_results: function () {
                return '<div style="padding:12px 14px;color:#94a3b8;font-size:12px;text-align:center;">' +
                    'Nessun risultato. Usa <strong>"Crea nuova"</strong> per inserire.</div>';
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

    // Pre-select current struttura
    var _sId   = _bkForm?.dataset.strutturaId   || '';
    var _sNome = _bkForm?.dataset.strutturaNome  || '';
    if (_sId && _sNome) {
        strutturaTS.addOption({ id: _sId, nome: _sNome });
        strutturaTS.setValue(_sId, true);
    }
}

new TomSelect('#worksite-select',     { allowEmptyOption: true, placeholder: 'Cerca cantiere...' });
new TomSelect('#capo-squadra-select', { allowEmptyOption: true, placeholder: 'Cerca capo squadra...' });
initStrutturaSearch();

// ── Type toggle ────────────────────────────────────────────────────────────
function setType(t) {
    document.getElementById('type-input').value = t;
    document.querySelectorAll('.bk-type-btn').forEach(function (b) { b.classList.remove('active'); });
    var activeBtn = document.querySelector('.bk-type-btn[data-bk-type="' + t + '"]');
    if (activeBtn) activeBtn.classList.add('active');
    document.getElementById('restaurant-fields').style.display = t === 'restaurant' ? 'block' : 'none';
    document.getElementById('hotel-fields').style.display      = t === 'hotel'      ? 'block' : 'none';
    document.getElementById('submit-btn').className = 'bk-btn-submit' + (t === 'hotel' ? ' hotel' : '');
    // Sync override meal fields with booking type
    var ovRest  = document.getElementById('ov-restaurant-meals');
    var ovHotel = document.getElementById('ov-hotel-regime');
    if (ovRest)  ovRest.style.display  = t === 'restaurant' ? 'block' : 'none';
    if (ovHotel) ovHotel.style.display = t === 'hotel'      ? 'block' : 'none';
    initStrutturaSearch();
}

// ── Periods ────────────────────────────────────────────────────────────────
var periodIndex = parseInt(_bkForm?.dataset.periodIndex || '0', 10);

function addPeriod() {
    var c   = document.getElementById('periods-container');
    var i   = periodIndex++;
    var row = document.createElement('div');
    row.className      = 'bk-period-row';
    row.dataset.index  = i;
    row.dataset.periodId = '';
    row.innerHTML =
        '<input type="hidden" name="periods[' + i + '][id]" value="">' +
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
        '<button type="button" class="bk-period-remove">' +
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

// ── Override-aware day count ────────────────────────────────────────────────
function parseLocalDate(str) {
    var p = str.split('-');
    return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
}

function formatLocalDate(d) {
    var y  = d.getFullYear();
    var m  = ('0' + (d.getMonth() + 1)).slice(-2);
    var dd = ('0' + d.getDate()).slice(-2);
    return y + '-' + m + '-' + dd;
}

function getOverridesFromDom() {
    var ovs = [];
    document.querySelectorAll('.bk-override-row[data-ov-type]').forEach(function (row) {
        ovs.push({
            type:     row.dataset.ovType,
            weekday:  parseInt(row.dataset.weekday  || '0', 10),
            date:     row.dataset.date    || '',
            skip:     row.dataset.skipDay === '1',
            periodId: parseInt(row.dataset.periodId || '0', 10),
        });
    });
    return ovs;
}

function countSkippedDays(dalStr, alStr, overrides) {
    var skipped = 0;
    if (!dalStr || !alStr || !overrides.length) return 0;
    var d   = parseLocalDate(dalStr);
    var end = parseLocalDate(alStr);
    while (d <= end) {
        var iso    = formatLocalDate(d);           // local YYYY-MM-DD
        var jsDay  = d.getDay();                   // 0=Sun (local)
        var isoDay = jsDay === 0 ? 7 : jsDay;      // 1=Mon … 7=Sun
        var hit = overrides.find(function (o) {
            if (!o.skip) return false;
            if (o.type === 'date')    return o.date    === iso;
            if (o.type === 'weekday') return o.weekday === isoDay;
            return false;
        });
        if (hit) skipped++;
        d.setDate(d.getDate() + 1);
    }
    return skipped;
}

function calcRowSubtotal(row) {
    var dal = row.querySelector('.period-dal')  ? row.querySelector('.period-dal').value  : '';
    var al  = row.querySelector('.period-al')   ? row.querySelector('.period-al').value   : '';
    var p   = parseFloat(row.querySelector('.period-persone') ? row.querySelector('.period-persone').value : 0) || 0;
    var pr  = parseFloat(row.querySelector('.period-prezzo')  ? row.querySelector('.period-prezzo').value  : 0) || 0;
    var el  = row.querySelector('.period-subtotal');
    if (!dal || !al || p <= 0 || pr <= 0) { if (el) { el.style.display = 'none'; } return 0; }
    var totalDays = Math.max(1, Math.round((parseLocalDate(al) - parseLocalDate(dal)) / 86400000) + 1);
    var periodId  = parseInt(row.dataset.periodId || '0', 10);
    // Only apply overrides that belong to this period, or overrides with no period (booking-wide)
    var overrides = getOverridesFromDom().filter(function (o) {
        return o.periodId === 0 || o.periodId === periodId;
    });
    var skipped   = countSkippedDays(dal, al, overrides);
    var days      = Math.max(0, totalDays - skipped);
    var total     = days * p * pr;
    if (el) {
        el.style.display = '';
        var txt = days + 'gg';
        if (skipped > 0) txt += ' <span style="color:#ef4444;">(-' + skipped + ' skip)</span>';
        txt += ' × ' + p + ' × €' + pr.toFixed(0) + ' = €' +
            total.toLocaleString('it-IT', { minimumFractionDigits: 0 });
        el.innerHTML = txt;
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
            '€' + g.toLocaleString('it-IT', { minimumFractionDigits: 0 });
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

// ── Fatture (AJAX) ──────────────────────────────────────────────────────────
function submitFattura() {
    var fd = new FormData();
    fd.append('booking_id',   BOOKING_ID);
    fd.append('numero',       document.getElementById('new-fattura-numero').value);
    fd.append('data_fattura', document.getElementById('new-fattura-data').value);
    fd.append('importo',      document.getElementById('new-fattura-importo').value);
    fd.append('note',         document.getElementById('new-fattura-note').value);
    var fileInput = document.getElementById('new-fattura-file');
    if (fileInput.files.length > 0) fd.append('fattura_file', fileInput.files[0]);

    fetch('/bookings/' + BOOKING_ID + '/fattura', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) { location.reload(); }
            else { alert('Errore: ' + (data.error || 'sconosciuto')); }
        })
        .catch(function (e) { alert('Errore: ' + e.message); });
}

function deleteFattura(id) {
    if (!confirm('Eliminare questa fattura?')) return;
    fetch('/bookings/fattura/' + id + '/delete?booking_id=' + BOOKING_ID)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                var row = document.querySelector('[data-fattura-id="' + id + '"]');
                if (row) row.remove();
                if (!document.querySelectorAll('.bk-fattura-row').length) {
                    document.getElementById('fatture-list').innerHTML =
                        '<div class="bk-fattura-empty" id="no-fatture-msg">' +
                        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" style="margin:0 auto 8px;display:block;">' +
                        '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>' +
                        '<polyline points="14 2 14 8 20 8"/></svg>Nessuna fattura caricata</div>';
                }
            } else { alert('Errore: ' + (data.error || 'sconosciuto')); }
        })
        .catch(function (e) { alert('Errore: ' + e.message); });
}

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

    // Delete override — MUST come before .bk-period-remove to avoid class conflict
    var delOv = e.target.closest('[data-bk-delete-override]');
    if (delOv) { deleteOverride(parseInt(delOv.dataset.bkDeleteOverride, 10)); return; }

    // Remove period
    var removeBtn = e.target.closest('.bk-period-remove');
    if (removeBtn) { removePeriod(removeBtn); return; }

    // Delete fattura
    var delBtn = e.target.closest('[data-bk-delete-fattura]');
    if (delBtn) { deleteFattura(parseInt(delBtn.dataset.bkDeleteFattura, 10)); return; }

    // Show/hide add-fattura form
    if (e.target.closest('[data-bk-action="show-fattura-form"]')) {
        document.getElementById('add-fattura-form').style.display = '';
        return;
    }
    if (e.target.closest('[data-bk-action="hide-fattura-form"]')) {
        document.getElementById('add-fattura-form').style.display = 'none';
        return;
    }

    // Submit fattura
    if (e.target.closest('[data-bk-action="submit-fattura"]')) {
        submitFattura();
        return;
    }

    // Show/hide override form
    if (e.target.closest('[data-bk-action="show-override-form"]')) {
        document.getElementById('add-override-form').style.display = '';
        return;
    }
    if (e.target.closest('[data-bk-action="hide-override-form"]')) {
        document.getElementById('add-override-form').style.display = 'none';
        return;
    }

    // Override type radio toggle (weekday / date)
    if (e.target.closest('[data-bk-action="override-type"]')) {
        var val = e.target.closest('[data-bk-action="override-type"]').value;
        document.getElementById('ov-weekday-field').style.display = val === 'weekday' ? '' : 'none';
        document.getElementById('ov-date-field').style.display    = val === 'date'    ? '' : 'none';
        return;
    }

    // Skip day checkbox — hide/show meal fields
    if (e.target.closest('[data-bk-action="override-skip"]')) {
        var skipped = e.target.closest('[data-bk-action="override-skip"]').checked;
        document.getElementById('ov-meal-fields').style.display = skipped ? 'none' : '';
        return;
    }

    // Submit new override
    if (e.target.closest('[data-bk-action="submit-override"]')) {
        submitOverride();
        return;
    }

    // Toggle fattura pagato
    var togBtn = e.target.closest('[data-bk-toggle-pagato]');
    if (togBtn) { toggleFatturaPagato(parseInt(togBtn.dataset.bkTogglePagato, 10), togBtn); return; }
});

// ── Overrides (AJAX) ────────────────────────────────────────────────────────
function submitOverride() {
    var type    = document.querySelector('input[name="_ov_type"]:checked');
    var ovType  = type ? type.value : 'weekday';
    var weekday = document.getElementById('ov-weekday').value;
    var data    = document.getElementById('ov-data').value;
    var skipDay = document.getElementById('ov-skip-day').checked ? '1' : '0';
    var periodEl = document.getElementById('ov-period-id');
    var periodId = periodEl ? periodEl.value : '';
    var pranzo  = document.getElementById('ov-pranzo')  ? (document.getElementById('ov-pranzo').checked  ? '1' : '0') : '';
    var cena    = document.getElementById('ov-cena')    ? (document.getElementById('ov-cena').checked    ? '1' : '0') : '';
    var regime  = document.getElementById('ov-regime')  ? document.getElementById('ov-regime').value : '';
    var note    = document.getElementById('ov-note').value;

    if (ovType === 'weekday' && !weekday) { alert('Seleziona il giorno della settimana.'); return; }
    if (ovType === 'date'    && !data)    { alert('Inserisci la data.'); return; }

    var fd = new FormData();
    fd.append('override_type', ovType);
    if (periodId) fd.append('period_id', periodId);
    if (ovType === 'weekday') fd.append('weekday', weekday);
    if (ovType === 'date')    fd.append('data', data);
    fd.append('skip_day', skipDay);
    if (skipDay !== '1') {
        fd.append('pranzo', pranzo);
        fd.append('cena',   cena);
        fd.append('regime', regime);
    }
    fd.append('note', note);

    fetch('/bookings/' + BOOKING_ID + '/overrides', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) { location.reload(); }
            else { alert('Errore: ' + (d.error || 'sconosciuto')); }
        })
        .catch(function (err) { alert('Errore: ' + err.message); });
}

function toggleFatturaPagato(id, btn) {
    fetch('/bookings/fattura/' + id + '/pagato', { method: 'POST', body: new FormData() })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) { alert('Errore: ' + (d.error || 'sconosciuto')); return; }
            var paid = d.pagato === 1;
            btn.classList.toggle('is-paid', paid);
            btn.title = paid ? 'Segna come non pagata' : 'Segna come pagata';
            btn.innerHTML = paid
                ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Pagata'
                : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/></svg> Non pagata';
        })
        .catch(function (err) { alert('Errore: ' + err.message); });
}

function deleteOverride(id) {
    if (!confirm('Rimuovere questa regola?')) return;
    var fd = new FormData();
    fetch('/bookings/override/' + id + '/delete', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) { location.reload(); }
            else { alert('Errore: ' + (d.error || 'sconosciuto')); }
        })
        .catch(function (err) { alert('Errore: ' + err.message); });
}


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
