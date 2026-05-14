/**
 * Fatturazione bozza — inline edit (Phase 2)
 *
 * Each cell <input> autosaves on blur (or Enter) via POST. Server
 * returns the updated line + fresh totals, which we apply optimistically.
 * On error, revert the field and surface the message.
 */
(function () {
    'use strict';

    var tbody = document.getElementById('bd-draft-tbody');
    if (!tbody) return;

    var table        = tbody.closest('table');
    var isEditable   = table && table.dataset.editable === '1';
    var indicator    = document.getElementById('bd-save-indicator');
    var totalImpEl   = document.getElementById('bd-total-imponibile');
    var totalExclEl  = document.getElementById('bd-total-escluso');
    var totalExclWrap= document.getElementById('bd-total-escluso-card');

    var saving = 0;

    function setSaving(state, msg) {
        if (!indicator) return;
        indicator.classList.remove('is-saving', 'is-error', 'is-saved');
        var dot  = indicator.querySelector('.bd-save-dot');
        var text = indicator.querySelector('.bd-save-text');
        if (state === 'saving') {
            indicator.classList.add('is-saving');
            if (text) text.textContent = msg || 'Salvataggio…';
        } else if (state === 'error') {
            indicator.classList.add('is-error');
            if (text) text.textContent = msg || 'Errore di salvataggio';
        } else {
            indicator.classList.add('is-saved');
            if (text) text.textContent = msg || 'Tutte le modifiche salvate';
        }
    }

    function formatItalianNumber(n) {
        if (!isFinite(n)) return '0,00';
        var parts = n.toFixed(2).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return parts.join(',');
    }

    function applyTotals(totals) {
        if (!totals) return;
        if (totalImpEl) totalImpEl.textContent = '€ ' + formatItalianNumber(totals.imponibile);
        if (totalExclEl) totalExclEl.textContent = '€ ' + formatItalianNumber(totals.escluso);
        if (totalExclWrap) {
            totalExclWrap.style.display = (parseFloat(totals.escluso) > 0) ? '' : 'none';
        }
    }

    function applyLineStatus(row, line) {
        if (!row || !line) return;
        var excluded = parseInt(line.excluded, 10) === 1;
        var modified = parseInt(line.modified, 10) === 1;

        row.dataset.excluded = excluded ? '1' : '0';
        row.classList.toggle('bd-row-excluded', excluded);
        row.classList.toggle('bd-row-modified', modified && !excluded);

        var pillExc = row.querySelector('[data-tag="excluded"]');
        var pillMod = row.querySelector('[data-tag="modified"]');
        var pillClr = row.querySelector('[data-tag="clean"]');
        if (pillExc) pillExc.style.display = excluded ? '' : 'none';
        if (pillMod) pillMod.style.display = (!excluded && modified) ? '' : 'none';
        if (pillClr) pillClr.style.display = (!excluded && !modified) ? '' : 'none';

        // Update exclude button icon/title
        var btn = row.querySelector('[data-action="toggle-exclude"]');
        if (btn) {
            btn.title = excluded ? 'Includi nella fattura' : 'Escludi dalla fattura';
            btn.innerHTML = excluded
                ? '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>'
                : '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
        }
    }

    function trackSave(promise) {
        saving++;
        setSaving('saving');
        return promise.finally(function () {
            saving = Math.max(0, saving - 1);
            if (saving === 0) setSaving('saved');
        });
    }

    function postJSON(url, body) {
        return fetch(url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body:    JSON.stringify(body),
        }).then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); });
    }

    // ── Cell editing ─────────────────────────────────────────────────────────

    function handleCellCommit(input) {
        if (input.dataset.committing === '1') return;
        var row    = input.closest('tr.bd-draft-row');
        var lineId = row && row.dataset.lineId;
        var field  = input.dataset.field;
        if (!lineId || !field) return;

        var newValue = input.value;
        var prevValue = input.dataset.lastSaved != null ? input.dataset.lastSaved : input.defaultValue;
        if (newValue === prevValue) return;

        input.dataset.committing = '1';
        input.classList.remove('bd-cell-error', 'bd-cell-success');

        trackSave(
            postJSON('/billing/draft-lines/' + lineId + '/update', { field: field, value: newValue })
                .then(function (res) {
                    if (!res.data.ok) {
                        // Revert + show error
                        input.value = prevValue;
                        input.classList.add('bd-cell-error');
                        setSaving('error', res.data.error || 'Errore');
                        input.title = res.data.error || '';
                        return;
                    }
                    applyTotals(res.data.totals);
                    applyLineStatus(row, res.data.line);
                    // Update visible value with server-canonical formatting
                    if (field === 'totale_imponibile' || field === 'aliquota_iva') {
                        var n = parseFloat(res.data.line[field]);
                        input.value = formatItalianNumber(n);
                        input.dataset.raw = n;
                    } else if (field === 'data') {
                        // Server returns ISO; show italian
                        var iso = res.data.line.data;
                        if (iso) {
                            var p = iso.split('-');
                            if (p.length === 3) input.value = p[2] + '/' + p[1] + '/' + p[0];
                        }
                    }
                    input.dataset.lastSaved = input.value;
                    input.classList.add('bd-cell-success');
                    setTimeout(function () { input.classList.remove('bd-cell-success'); }, 700);
                })
                .catch(function (err) {
                    console.error(err);
                    input.value = prevValue;
                    input.classList.add('bd-cell-error');
                    setSaving('error', 'Errore di rete');
                })
                .finally(function () { input.dataset.committing = '0'; })
        );
    }

    if (isEditable) {
        tbody.addEventListener('blur', function (e) {
            var t = e.target;
            if (t && t.classList && t.classList.contains('bd-cell-input')) {
                handleCellCommit(t);
            }
        }, true);

        tbody.addEventListener('keydown', function (e) {
            var t = e.target;
            if (!t || !t.classList || !t.classList.contains('bd-cell-input')) return;
            if (e.key === 'Enter') {
                e.preventDefault();
                t.blur();
            } else if (e.key === 'Escape') {
                var prev = t.dataset.lastSaved != null ? t.dataset.lastSaved : t.defaultValue;
                t.value = prev;
                t.blur();
            }
        });
    }

    // Snapshot initial saved value so revert-on-error works
    tbody.querySelectorAll('.bd-cell-input').forEach(function (inp) {
        inp.dataset.lastSaved = inp.value;
    });

    // ── State-machine action buttons ─────────────────────────────────────────

    var actionsEl = document.querySelector('.bd-draft-actions');
    if (actionsEl) {
        actionsEl.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-transition], [data-disabled-reason]');
            if (!btn) return;

            // Disabled placeholder (Phase 4 button)
            if (btn.dataset.disabledReason) {
                alert(btn.dataset.disabledReason);
                return;
            }

            var to = btn.dataset.transition;
            if (!to) return;

            if (btn.dataset.confirm && !confirm(btn.dataset.confirm)) {
                return;
            }

            var clientId = actionsEl.dataset.clientId;
            var draftId  = actionsEl.dataset.draftId;
            btn.disabled = true;

            trackSave(
                postJSON('/billing/client/' + clientId + '/draft/' + draftId + '/transition', { to: to })
                    .then(function (res) {
                        if (!res.data.ok) {
                            alert(res.data.error || 'Errore nella transizione');
                            btn.disabled = false;
                            return;
                        }
                        // Reload — easier than swapping all the buttons + readonly
                        // flags + status pills inline. Status change is infrequent.
                        location.reload();
                    })
                    .catch(function (err) {
                        console.error(err);
                        alert('Errore di rete');
                        btn.disabled = false;
                    })
            );
        });
    }

    // ── Exclude toggle ───────────────────────────────────────────────────────

    if (isEditable) {
        tbody.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action="toggle-exclude"]');
            if (!btn) return;
            var row    = btn.closest('tr.bd-draft-row');
            var lineId = row && row.dataset.lineId;
            if (!lineId) return;

            var currentlyExcluded = row.dataset.excluded === '1';
            var willExclude       = !currentlyExcluded;
            var reason            = null;
            if (willExclude) {
                reason = prompt('Motivo esclusione (facoltativo):', '') || null;
            }

            trackSave(
                postJSON('/billing/draft-lines/' + lineId + '/exclude', {
                    excluded: willExclude,
                    reason:   reason,
                })
                .then(function (res) {
                    if (!res.data.ok) {
                        setSaving('error', res.data.error || 'Errore');
                        return;
                    }
                    applyTotals(res.data.totals);
                    applyLineStatus(row, res.data.line);
                })
                .catch(function (err) {
                    console.error(err);
                    setSaving('error', 'Errore di rete');
                })
            );
        });
    }
})();
