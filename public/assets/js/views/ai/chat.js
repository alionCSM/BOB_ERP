/**
 * BOB AI — la chat.
 *
 * Due cambiamenti rispetto a prima:
 *
 * - La risposta arriva come flusso di eventi (SSE) invece che come un unico
 *   JSON alla fine. Finche' arrivano byte nessuno chiude la connessione, e
 *   chi aspetta legge a che punto siamo invece di guardare tre puntini.
 *
 * - La conversazione sta sul server. Prima viveva in questa variabile qui
 *   sotto: un refresh e non era mai esistita. Ora il browser tiene solo l'id
 *   della chat aperta, e il contesto per il modello lo mette insieme il
 *   server leggendo il database.
 */
(function () {
    'use strict';

    var messagesEl = document.getElementById('chat-messages');
    var inputEl    = document.getElementById('chat-input');
    var sendBtn    = document.getElementById('chat-send');
    var clearBtn   = document.getElementById('chat-clear');
    var stopBtn    = document.getElementById('chat-stop');
    var loadingEl  = document.getElementById('chat-loading');
    var statoEl    = document.getElementById('ai-stato');
    var listaEl    = document.getElementById('ai-side-lista');

    var stato = window.BOB_AI || { conversazione: 0, messaggi: [] };

    var conversazione = stato.conversazione || 0;
    var busy          = false;
    var annullatore   = null;   // AbortController della richiesta in corso

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    function esc(str) {
        var d = document.createElement('div');
        d.textContent = (str === null || str === undefined) ? '' : String(str);
        return d.innerHTML;
    }

    // ── Markdown → HTML ───────────────────────────────────────────────────────

    function parseTable(block, tableIndex) {
        var lines = block.trim().split('\n');
        if (lines.length < 3) return esc(block);

        var headers = lines[0].split('|')
            .map(function (c) { return c.trim(); })
            .filter(function (c) { return c.length > 0; });

        // Intestazioni e celle passano da esc(): il testo arriva dal database
        // e dal modello, e finisce dentro innerHTML.
        var tableHtml = '<table class="ai-table"><thead><tr>';
        headers.forEach(function (h) { tableHtml += '<th>' + esc(h) + '</th>'; });
        tableHtml += '</tr></thead><tbody>';

        for (var i = 2; i < lines.length; i++) {
            var line = lines[i].trim();
            if (!line) continue;
            var rawCells = line.split('|');
            if (rawCells[0].trim() === '') rawCells.shift();
            if (rawCells[rawCells.length - 1].trim() === '') rawCells.pop();
            tableHtml += '<tr>';
            rawCells.forEach(function (c) { tableHtml += '<td>' + esc(c.trim()) + '</td>'; });
            tableHtml += '</tr>';
        }
        tableHtml += '</tbody></table>';

        var filename = 'bob_ai_tabella_' + (tableIndex + 1) + '_' + new Date().toISOString().slice(0, 10) + '.csv';

        return '<div class="ai-table-wrap">' +
               '<div class="ai-table-toolbar">' +
                   '<span class="ai-table-count">' + (lines.length - 2) + ' righe</span>' +
                   '<button class="ai-table-export" data-filename="' + esc(filename) + '">' +
                       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                           '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>' +
                           '<polyline points="7 10 12 15 17 10"/>' +
                           '<line x1="12" y1="15" x2="12" y2="3"/>' +
                       '</svg>' +
                       'Esporta Excel' +
                   '</button>' +
               '</div>' +
               tableHtml +
               '</div>';
    }

    function renderMd(text) {
        var html = esc(text);

        html = html.replace(/```sql\s*\n([\s\S]*?)\n```/g, '<pre><code>$1</code></pre>');
        html = html.replace(/```\s*\n([\s\S]*?)\n```/g,    '<pre><code>$1</code></pre>');

        var tableIndex = 0;
        html = html.replace(/((?:\|[^\n]+\|\n)(?:\|[-| :]+\|\n)(?:\|[^\n]+\|\n?)+)/g, function (match) {
            return parseTable(match, tableIndex++);
        });

        html = html.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/_([^_\n]+)_/g, '<em>$1</em>');

        var parts = html.split(/(<(?:pre|table)[\s\S]*?<\/(?:pre|table)>)/);
        html = parts.map(function (part, i) {
            return (i % 2 === 0) ? part.replace(/\n/g, '<br>') : part;
        }).join('');

        return html;
    }

    function makeSqlDetail(sql) {
        var details = document.createElement('details');
        details.className = 'ai-sql-detail';
        var summary = document.createElement('summary');
        summary.textContent = 'Query eseguita';
        var pre  = document.createElement('pre');
        var code = document.createElement('code');
        code.textContent = sql;
        pre.appendChild(code);
        details.appendChild(summary);
        details.appendChild(pre);
        return details;
    }

    function addMsg(role, text, sql) {
        var wrap = document.createElement('div');
        wrap.className = 'ai-msg ai-msg-' + role;

        var av = document.createElement('div');
        av.className = 'ai-msg-avatar';
        av.textContent = role === 'user' ? 'TU' : 'BOB';

        var bubble = document.createElement('div');
        bubble.className = 'ai-msg-bubble';
        bubble.innerHTML = renderMd(text);

        if (bubble.querySelector('.ai-table')) {
            wrap.classList.add('ai-msg--wide');
        }

        if (sql) {
            bubble.appendChild(makeSqlDetail(sql));
        }

        if (role === 'user') {
            wrap.appendChild(bubble);
            wrap.appendChild(av);
        } else {
            wrap.appendChild(av);
            wrap.appendChild(bubble);
        }

        messagesEl.appendChild(wrap);
        inFondo();
        return wrap;
    }

    function addAiMsg(t, sql) { return addMsg('ai', t, sql || null); }

    function inFondo() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    // ── La chat riaperta ──────────────────────────────────────────────────────

    (function dipingiStorico() {
        if (!stato.messaggi || !stato.messaggi.length) return;

        stato.messaggi.forEach(function (m) {
            addMsg(m.ruolo === 'utente' ? 'user' : 'ai', m.testo, m.sql || null);
        });
    })();

    // ── Stato mentre si aspetta ───────────────────────────────────────────────

    var FRASI = {
        inizio:  'Ci sto pensando…',
        penso:   'Sto scrivendo la query…',
        scrivo:  'Sto scrivendo la query…',
        riprovo: 'Il primo tentativo non andava, riprovo…',
        eseguo:  'Interrogo il database…',
        pronto:  'Preparo la risposta…'
    };

    function mostraStato(fase, dati) {
        if (!statoEl) return;

        var testo = FRASI[fase] || '';
        if (fase === 'pronto' && dati && typeof dati.righe === 'number') {
            testo = dati.righe + ' righe trovate, preparo la risposta…';
        }
        statoEl.textContent = testo;
    }

    function aspetta(attivo) {
        busy = attivo;
        sendBtn.disabled = attivo;
        loadingEl.classList.toggle('hidden', !attivo);
        stopBtn.classList.toggle('hidden', !attivo);
        if (!attivo && statoEl) statoEl.textContent = '';
        if (attivo) inFondo();
    }

    // ── Invio ─────────────────────────────────────────────────────────────────

    function send(testo) {
        var q = (testo !== undefined ? testo : inputEl.value).trim();
        if (!q || busy) return;

        inputEl.value = '';
        inputEl.style.height = 'auto';

        // I bottoni di esempio spariscono dopo la prima domanda
        var esempi = messagesEl.querySelector('.ai-esempi');
        if (esempi) esempi.remove();
        var esempiT = messagesEl.querySelector('.ai-esempi-t');
        if (esempiT) esempiT.remove();

        addMsg('user', q, null);
        aspetta(true);
        mostraStato('inizio');

        annullatore = new AbortController();

        var corpo = 'domanda=' + encodeURIComponent(q) +
                    '&conversazione=' + encodeURIComponent(conversazione);

        fetch('/ai/chat', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf()
            },
            body:   corpo,
            signal: annullatore.signal
        })
        .then(function (r) {
            if (!r.ok && r.status !== 200) {
                throw new Error('Il server ha risposto ' + r.status);
            }
            if (!r.body || !r.body.getReader) {
                // Browser senza streaming: si rilegge tutto alla fine. Non e'
                // il percorso buono, ma e' meglio di una pagina che non va.
                return r.text().then(leggiTuttoInsieme);
            }
            return leggiFlusso(r.body.getReader());
        })
        .catch(function (err) {
            if (err && err.name === 'AbortError') {
                // Si smette di aspettare, ma il server finisce e salva: la
                // risposta si trova riaprendo la chat dall'elenco.
                addAiMsg('_Ho smesso di aspettare. Il server sta finendo: ricarica la pagina fra un momento per vedere la risposta._', null);
                return;
            }
            addAiMsg('**Non ci siamo riusciti.** ' + (err && err.message ? err.message : 'Errore di collegamento.'), null);
        })
        .then(function () {
            aspetta(false);
            annullatore = null;
            inputEl.focus();
        });
    }

    /** Legge il flusso SSE e reagisce a ogni evento appena arriva. */
    function leggiFlusso(reader) {
        var decoder = new TextDecoder();
        var buffer  = '';

        function giro() {
            return reader.read().then(function (res) {
                if (res.done) {
                    if (buffer.trim()) consumaBlocco(buffer);
                    return;
                }

                buffer += decoder.decode(res.value, { stream: true });

                var taglio;
                while ((taglio = buffer.indexOf('\n\n')) !== -1) {
                    consumaBlocco(buffer.slice(0, taglio));
                    buffer = buffer.slice(taglio + 2);
                }

                return giro();
            });
        }

        return giro();
    }

    function consumaBlocco(blocco) {
        var evento = '';
        var dati   = '';

        blocco.split('\n').forEach(function (riga) {
            if (riga.indexOf('event:') === 0) {
                evento = riga.slice(6).trim();
            } else if (riga.indexOf('data:') === 0) {
                dati += riga.slice(5).trim();
            }
        });

        if (!evento || !dati) return;

        var payload;
        try {
            payload = JSON.parse(dati);
        } catch (e) {
            return;
        }

        if (evento === 'inizio' || evento === 'stato') {
            mostraStato(payload.fase || 'inizio', payload);
            return;
        }

        if (evento === 'fine') {
            concludi(payload);
        }
    }

    /** Ripiego per i browser senza streaming: il corpo intero arriva in fondo. */
    function leggiTuttoInsieme(testo) {
        var blocchi = testo.split('\n\n');
        for (var i = blocchi.length - 1; i >= 0; i--) {
            if (blocchi[i].indexOf('event: fine') !== -1) {
                consumaBlocco(blocchi[i]);
                return;
            }
        }

        // Non era SSE: forse e' il JSON del percorso senza streaming.
        try {
            concludi(JSON.parse(testo));
        } catch (e) {
            addAiMsg('**Risposta non leggibile dal server.**', null);
        }
    }

    function concludi(payload) {
        if (!payload.ok) {
            addAiMsg('**' + (payload.error || 'Errore imprevisto.') + '**', null);
        } else {
            addAiMsg(payload.answer, payload.query || null);
        }

        // Chat nuova: adesso ha un id, e va messa in cima all'elenco.
        if (payload.conversazione && payload.conversazione !== conversazione) {
            conversazione = payload.conversazione;
            aggiornaElenco();
        } else if (payload.conversazione) {
            aggiornaElenco();
        }
    }

    // ── L'elenco delle chat ───────────────────────────────────────────────────

    function aggiornaElenco() {
        if (!listaEl) return;

        fetch('/ai/conversazioni', { headers: { 'X-CSRF-TOKEN': csrf() } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) return;
                dipingiElenco(d.conversazioni || []);
            })
            .catch(function () { /* l'elenco e' comodo, non essenziale */ });
    }

    function dipingiElenco(righe) {
        listaEl.textContent = '';

        if (!righe.length) {
            var vuoto = document.createElement('li');
            vuoto.className = 'ai-side-vuoto';
            vuoto.textContent = 'Nessuna chat salvata. Fai la tua prima domanda.';
            listaEl.appendChild(vuoto);
            return;
        }

        righe.forEach(function (c) {
            var li = document.createElement('li');
            li.className = 'ai-side-item' + (c.id === conversazione ? ' attiva' : '');
            li.dataset.id = c.id;

            var a = document.createElement('a');
            a.className = 'ai-side-link';
            a.href = '/ai/chat?c=' + c.id;

            var nome = document.createElement('span');
            nome.className = 'ai-side-nome';
            nome.textContent = c.titolo;

            var data = document.createElement('span');
            data.className = 'ai-side-data';
            data.textContent = formattaData(c.updated_at);

            a.appendChild(nome);
            a.appendChild(data);

            var azioni = document.createElement('span');
            azioni.className = 'ai-side-azioni';
            [['rinomina', '✎', 'Rinomina'], ['elimina', '🗑', 'Elimina']].forEach(function (b) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'ai-side-azione';
                btn.dataset.azione = b[0];
                btn.dataset.id = c.id;
                btn.dataset.titolo = c.titolo;
                btn.title = b[2];
                btn.setAttribute('aria-label', b[2] + ' la chat ' + c.titolo);
                btn.textContent = b[1];
                azioni.appendChild(btn);
            });

            li.appendChild(a);
            li.appendChild(azioni);
            listaEl.appendChild(li);
        });
    }

    function formattaData(sql) {
        if (!sql) return '';
        var p = String(sql).split(/[- :]/);
        if (p.length < 5) return sql;
        return p[2] + '/' + p[1] + '/' + p[0] + ' ' + p[3] + ':' + p[4];
    }

    if (listaEl) {
        listaEl.addEventListener('click', function (e) {
            var btn = e.target.closest('.ai-side-azione');
            if (!btn) return;

            e.preventDefault();
            var id     = btn.dataset.id;
            var titolo = btn.dataset.titolo || '';

            if (btn.dataset.azione === 'rinomina') {
                var nuovo = window.prompt('Nuovo nome della chat:', titolo);
                if (nuovo === null) return;
                nuovo = nuovo.trim();
                if (!nuovo) return;

                azione('/ai/conversazioni/' + id + '/rinomina', 'titolo=' + encodeURIComponent(nuovo));
                return;
            }

            if (btn.dataset.azione === 'elimina') {
                if (!window.confirm('Elimino la chat "' + titolo + '"? Non si recupera.')) return;

                azione('/ai/conversazioni/' + id + '/elimina', '', function () {
                    // Se era quella aperta, si riparte da una chat nuova.
                    if (String(id) === String(conversazione)) {
                        window.location.href = '/ai/chat';
                    }
                });
            }
        });
    }

    function azione(url, corpo, poi) {
        fetch(url, {
            method:  'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf()
            },
            body: corpo
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) {
                window.alert(d.error || 'Non ci sono riuscito.');
                return;
            }
            if (poi) poi();
            aggiornaElenco();
        })
        .catch(function () {
            window.alert('Errore di collegamento.');
        });
    }

    // ── Comandi ───────────────────────────────────────────────────────────────

    inputEl.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });

    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !busy) {
            e.preventDefault();
            send();
        }
    });

    sendBtn.addEventListener('click', function () { if (!busy) send(); });

    stopBtn.addEventListener('click', function () {
        if (annullatore) annullatore.abort();
    });

    clearBtn.addEventListener('click', function () {
        // Una chat nuova e' una pagina nuova: quella di prima resta salvata e
        // si riapre dall'elenco.
        window.location.href = '/ai/chat';
    });

    messagesEl.addEventListener('click', function (e) {
        var esempio = e.target.closest('.ai-esempio');
        if (esempio) {
            send(esempio.textContent.trim());
            return;
        }

        var btn = e.target.closest('.ai-table-export');
        if (!btn) return;
        var wrap  = btn.closest('.ai-table-wrap');
        var table = wrap && wrap.querySelector('.ai-table');
        if (table) {
            exportTableToXlsx(table, btn.dataset.filename || undefined, btn);
        }
    });

    // ── Export tabella → .xlsx ────────────────────────────────────────────────

    function exportTableToXlsx(tableEl, filename, btn) {
        var headers = [];
        tableEl.querySelectorAll('thead th').forEach(function (th) {
            headers.push(th.textContent.trim());
        });

        var rows = [];
        tableEl.querySelectorAll('tbody tr').forEach(function (tr) {
            var cells = [];
            tr.querySelectorAll('td').forEach(function (td) {
                cells.push(td.textContent.trim());
            });
            rows.push(cells);
        });

        var originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.textContent = 'Generando…';

        var xlsxFilename = (filename || ('bob_ai_' + new Date().toISOString().slice(0, 10)))
            .replace(/\.csv$/i, '') + '.xlsx';

        fetch('/ai/export-table', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf()
            },
            body: 'headers=' + encodeURIComponent(JSON.stringify(headers)) +
                  '&rows='    + encodeURIComponent(JSON.stringify(rows)) +
                  '&filename='+ encodeURIComponent(xlsxFilename)
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.blob();
        })
        .then(function (blob) {
            var url = URL.createObjectURL(blob);
            var a   = document.createElement('a');
            a.href     = url;
            a.download = xlsxFilename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        })
        .catch(function (err) {
            window.alert('Errore esportazione: ' + err.message);
        })
        .then(function () {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
    }

    inFondo();
})();
