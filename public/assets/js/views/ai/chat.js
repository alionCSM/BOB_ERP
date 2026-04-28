(function () {
    'use strict';

    var messagesEl  = document.getElementById('chat-messages');
    var inputEl     = document.getElementById('chat-input');
    var sendBtn     = document.getElementById('chat-send');
    var clearBtn    = document.getElementById('chat-clear');
    var loadingEl   = document.getElementById('chat-loading');

    var history = [];
    var busy = false;

    // Auto-resize
    inputEl.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });

    // Enter to send
    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !busy) {
            e.preventDefault();
            send();
        }
    });

    sendBtn.addEventListener('click', function () { if (!busy) send(); });

    clearBtn.addEventListener('click', function () {
        history = [];
        messagesEl.innerHTML = '';
        addAiMsg('Conversazione resettata. Sono BOB AI, come posso aiutarti?', null);
    });

    // ── Export table → server → styled .xlsx download ────────────────────────
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

        // Visual feedback while generating
        var originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;animation:ai-spin .7s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Generando…';

        var xlsxFilename = (filename || ('bob_ai_' + new Date().toISOString().slice(0, 10)))
            .replace(/\.csv$/i, '') + '.xlsx';

        fetch('/ai/export-table', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
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
        ['catch'](function (err) {
            alert('Errore esportazione: ' + err.message);
        })
        ['finally'](function () {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
    }

    // Delegate export-button clicks (tables are injected via innerHTML, can't bind directly)
    messagesEl.addEventListener('click', function (e) {
        var btn = e.target.closest('.ai-table-export');
        if (!btn) return;
        var wrap  = btn.closest('.ai-table-wrap');
        var table = wrap && wrap.querySelector('.ai-table');
        if (table) {
            exportTableToXlsx(table, btn.dataset.filename || undefined, btn);
        }
    });

    // ── Helpers ───────────────────────────────────────────────────────────────
    function esc(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    // ── Markdown table → HTML table with export button ────────────────────────
    function parseTable(block, tableIndex) {
        var lines = block.trim().split('\n');
        if (lines.length < 3) return esc(block);

        var headers = lines[0].split('|')
            .map(function (c) { return c.trim(); })
            .filter(function (c) { return c.length > 0; });

        var tableHtml = '<table class="ai-table"><thead><tr>';
        headers.forEach(function (h) { tableHtml += '<th>' + h + '</th>'; });
        tableHtml += '</tr></thead><tbody>';

        for (var i = 2; i < lines.length; i++) {
            var line = lines[i].trim();
            if (!line) continue;
            var rawCells = line.split('|');
            // strip leading/trailing empty splits caused by | at start/end of line
            if (rawCells[0].trim() === '') rawCells.shift();
            if (rawCells[rawCells.length - 1].trim() === '') rawCells.pop();
            tableHtml += '<tr>';
            rawCells.forEach(function (c) { tableHtml += '<td>' + c.trim() + '</td>'; });
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

        // 1. Fenced code blocks
        html = html.replace(/```sql\s*\n([\s\S]*?)\n```/g, '<pre><code>$1</code></pre>');
        html = html.replace(/```\s*\n([\s\S]*?)\n```/g,    '<pre><code>$1</code></pre>');

        // 2. Markdown tables — convert before \n → <br>
        var tableIndex = 0;
        html = html.replace(/((?:\|[^\n]+\|\n)(?:\|[-| :]+\|\n)(?:\|[^\n]+\|\n?)+)/g, function (match) {
            return parseTable(match, tableIndex++);
        });

        // 3. Inline code
        html = html.replace(/`([^`\n]+)`/g, '<code>$1</code>');

        // 4. Bold
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

        // 5. Newlines → <br> (skip inside <pre> and <table> blocks)
        var parts = html.split(/(<(?:pre|table)[\s\S]*?<\/(?:pre|table)>)/);
        html = parts.map(function (part, i) {
            return (i % 2 === 0) ? part.replace(/\n/g, '<br>') : part;
        }).join('');

        return html;
    }

    // ── SQL reveal block ──────────────────────────────────────────────────────
    function makeSqlDetail(sql) {
        var details = document.createElement('details');
        details.className = 'ai-sql-detail';
        var summary = document.createElement('summary');
        summary.textContent = 'Query eseguita';
        var pre = document.createElement('pre');
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

        // Expand to full width when a table is present
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
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function addAiMsg(t, sql) { addMsg('ai', t, sql || null); }

    function send() {
        var q = inputEl.value.trim();
        if (!q || busy) return;

        busy = true;
        inputEl.value = '';
        inputEl.style.height = 'auto';
        sendBtn.disabled = true;

        addMsg('user', q, null);
        history.push({ role: 'user', content: q });

        loadingEl.classList.remove('hidden');
        messagesEl.scrollTop = messagesEl.scrollHeight;

        fetch('/ai/chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: 'messages=' + encodeURIComponent(JSON.stringify(history))
        })
        .then(function (r) {
            if (r.status >= 400) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .catch(function (err) {
            if (err.message && err.message.indexOf('HTTP') === 0) {
                throw new Error('Errore server (' + err.message + ')');
            }
            throw new Error('Risposta non valida dal server');
        })
        .then(function (data) {
            loadingEl.classList.add('hidden');
            if (!data.ok) {
                addAiMsg('Errore: ' + (data.error || 'Imprevisto'), null);
                history.pop();
                return;
            }
            addAiMsg(data.answer, data.query || null);
            history.push({ role: 'assistant', content: data.answer });
        })
        ['catch'](function (err) {
            loadingEl.classList.add('hidden');
            addAiMsg('Errore di connessione: ' + err.message, null);
            history.pop();
        })
        ['finally'](function () {
            busy = false;
            sendBtn.disabled = false;
            inputEl.focus();
        });
    }
})();
