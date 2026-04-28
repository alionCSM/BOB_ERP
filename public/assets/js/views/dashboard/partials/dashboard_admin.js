const pageMap = <?= json_encode($pageMap) ?>;

function friendlyPage(raw) {
    if (!raw) return '—';
    try {
        const path = new URL(raw, location.origin).pathname;
        if (pageMap[path]) return pageMap[path];
    } catch(e) {}
    // Fallback: basename
    const parts = raw.split('/');
    let base = parts[parts.length - 1].split('?')[0].replace('.php','').replace(/_/g,' ');
    return base.charAt(0).toUpperCase() + base.slice(1);
}

function friendlyAction(a) {
    const map = {page_view:'ha visitato',login:'ha effettuato accesso',logout:'ha effettuato uscita',create:'ha creato',update:'ha aggiornato',delete:'ha eliminato'};
    return map[a] || a;
}

const uColors = ['#6366f1','#0ea5e9','#8b5cf6','#ec4899','#14b8a6','#f59e0b','#ef4444','#22c55e'];

async function refreshAnalytics() {
    try {
        const res = await fetch('/api/analytics/user-activity');
        const data = await res.json();

        document.getElementById('online-count').innerText = data.onlineCount;

        document.getElementById('online-users').innerHTML = !data.onlineUsers.length
            ? '<div class="da-empty">Nessun utente online</div>'
            : data.onlineUsers.map((u, i) => `
                <div class="da-user-row">
                    <div class="da-user-avatar" style="background:${uColors[i % uColors.length]};">${(u.first_name||'?')[0].toUpperCase()}</div>
                    <div style="flex:1;min-width:0;">
                        <div class="da-user-name">${u.first_name}</div>
                        <div class="da-user-page">${friendlyPage(u.page)}</div>
                    </div>
                    <div class="da-user-pulse"></div>
                </div>`).join('');

        document.getElementById('top-users').innerHTML = !data.topUsers.length
            ? '<div class="da-empty">Nessun dato disponibile</div>'
            : data.topUsers.map((u, i) => {
                const cls = i===0?'da-rank-1':i===1?'da-rank-2':i===2?'da-rank-3':'da-rank-n';
                const s = parseInt(u.total_seconds);
                const h = Math.floor(s/3600), m = Math.floor((s%3600)/60);
                const t = h > 0 ? h+'h '+m+'m' : m+'m';
                return `<div class="da-top-row">
                    <div class="da-top-rank ${cls}">${i+1}</div>
                    <div class="da-top-name">${u.first_name}</div>
                    <div class="da-top-time">${t}</div>
                </div>`;
            }).join('');

        document.getElementById('recent-actions').innerHTML = !data.recent.length
            ? '<div class="da-empty">Nessuna attività recente</div>'
            : data.recent.map(r => `
                <div class="da-act-row">
                    <div class="da-act-dot"></div>
                    <div style="min-width:0;">
                        <div><span class="da-act-name">${r.first_name}</span> <span class="da-act-action">${friendlyAction(r.action)}</span></div>
                        <span class="da-act-page">${friendlyPage(r.page)}</span>
                    </div>
                </div>`).join('');

    } catch (e) {
        console.error('Analytics refresh failed', e);
    }
}
setInterval(refreshAnalytics, 5000);
