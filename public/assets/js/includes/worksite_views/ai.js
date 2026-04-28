(function(){
    const wid  = <?= (int)$worksite_id ?>;
    const chat = document.getElementById('ai-chat');
    const q    = document.getElementById('ai-question');
    const send = document.getElementById('ai-send');
    const st   = document.getElementById('ai-status');
    const history = []; // conversation history for multi-turn

    function clearEmpty() {
        const e = document.getElementById('ai-empty');
        if (e) e.remove();
    }

    function fmt(text) {
        text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/^#{1,3}\s+(.+)$/gm, '<strong style="display:block;margin:4px 0 1px;">$1</strong>');
        text = text.replace(/^[\-•]\s+(.+)$/gm, '<span style="display:block;padding-left:10px;">• $1</span>');
        text = text.replace(/\n/g, '<br>');
        return text;
    }

    function append(role, text) {
        clearEmpty();
        const row = document.createElement('div');
        row.className = 'ai-row' + (role === 'user' ? ' right' : '');

        const ava = document.createElement('div');
        ava.className = 'ai-ava ' + (role === 'user' ? 'user-a' : 'bot-a');
        ava.textContent = role === 'user' ? 'TU' : 'AI';

        const bub = document.createElement('div');
        bub.className = 'ai-bubble ' + (role === 'user' ? 'user' : 'bot');
        bub.innerHTML = role === 'user' ? text : fmt(text);

        if (role === 'user') { row.appendChild(bub); row.appendChild(ava); }
        else { row.appendChild(ava); row.appendChild(bub); }

        chat.appendChild(row);
        chat.scrollTop = chat.scrollHeight;
    }

    function showTyping() {
        clearEmpty();
        const row = document.createElement('div');
        row.className = 'ai-row'; row.id = 'ai-typing';
        const ava = document.createElement('div');
        ava.className = 'ai-ava bot-a'; ava.textContent = 'AI';
        const bub = document.createElement('div');
        bub.className = 'ai-bubble bot';
        bub.innerHTML = '<div class="ai-dots"><span></span><span></span><span></span></div>';
        row.appendChild(ava); row.appendChild(bub);
        chat.appendChild(row); chat.scrollTop = chat.scrollHeight;
    }
    function removeTyping() { document.getElementById('ai-typing')?.remove(); }

    function setLoading(s) {
        if (s) { st.textContent = 'BOB AI sta pensando...'; send.disabled = true; showTyping(); }
        else { st.textContent = ''; send.disabled = false; removeTyping(); }
    }

    async function ask(question) {
        question = question || q.value.trim();
        if (!question) return;
        append('user', question);
        history.push({ role: 'user', content: question });
        q.value = '';
        setLoading(true);
        try {
            const fd = new FormData();
            fd.append('worksite_id', wid);
            fd.append('question', question);
            // send last 10 messages for context (keep payload small)
            fd.append('history', JSON.stringify(history.slice(-10)));
            const res = await fetch('/worksites/ask-ai', { method: 'POST', body: fd });
            const data = await res.json();
            setLoading(false);
            const answer = data.ok ? (data.answer || 'Nessuna risposta.') : (data.error || 'Errore.');
            append('bot', answer);
            history.push({ role: 'assistant', content: answer });
        } catch(e) {
            setLoading(false);
            append('bot', 'Errore di connessione.');
        }
    }

    window.aiAsk = function(question) { q.value = question; ask(question); };
    send.addEventListener('click', () => ask());
    q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); ask(); } });
})();
