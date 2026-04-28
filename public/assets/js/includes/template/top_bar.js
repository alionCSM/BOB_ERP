    const bellToggleEl = document.getElementById('notif-bell-toggle');

    function setBellBullet(hasUnread) {
        if (!bellToggleEl) return;
        bellToggleEl.classList.toggle('notification--bullet', Boolean(hasUnread));
    }

    function ensureEmptyMessage() {
        const list = document.getElementById('notification-list');
        if (!list) return;

        const remaining = list.querySelectorAll('.notification-item').length;
        if (remaining === 0 && !list.querySelector('.empty-notif')) {
            const empty = document.createElement('div');
            empty.className = 'empty-notif text-slate-500 text-center p-4';
            empty.textContent = 'Nessuna notifica non letta';
            list.appendChild(empty);
        }
        setBellBullet(remaining > 0);
    }

    async function markNotificationRead(notificationId) {
        const body = new URLSearchParams({ action: 'mark_read', notification_id: String(notificationId) });
        const response = await fetch('/notifications/action', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body
        });

        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Azione notifica fallita');
        }
    }

    document.addEventListener('click', async function (event) {
        const markBtn = event.target.closest('.notif-mark-read');
        const openLink = event.target.closest('.notif-open');

        if (!markBtn && !openLink) return;

        event.preventDefault();
        event.stopPropagation();

        const item = event.target.closest('.notification-item');
        if (!item) return;

        const notifId = item.getAttribute('data-id');
        try {
            await markNotificationRead(notifId);
            item.remove();
            document.getElementById('notification-list')?.querySelector('.empty-notif')?.remove();
            ensureEmptyMessage();

            if (openLink) {
                event.preventDefault();
                window.location.href = openLink.getAttribute('href');
            }
        } catch (error) {
            console.error(error);
        }
    });

    (function () {
        const seenKey = 'bob_browser_notif_seen_ids';

        function getSeenIds() {
            try {
                return JSON.parse(localStorage.getItem(seenKey) || '[]');
            } catch (_) {
                return [];
            }
        }

        function setSeenIds(ids) {
            localStorage.setItem(seenKey, JSON.stringify(ids.slice(-200)));
        }

        async function pollUnreadNotifications() {
            try {
                const res = await fetch('/notifications/unread');
                const data = await res.json();
                if (!res.ok || !data.success || !Array.isArray(data.notifications)) return;

                setBellBullet(data.count > 0);

                if (Notification.permission !== 'granted') return;

                const seenSet = new Set(getSeenIds());
                for (const notif of data.notifications) {
                    const id = Number(notif.id);
                    if (!id || seenSet.has(id)) continue;

                    new Notification(notif.title || 'BOB', {
                        body: notif.message || 'Hai una nuova notifica',
                        icon: '/assets/img/logo.png'
                    });
                    seenSet.add(id);
                }
                setSeenIds(Array.from(seenSet));
            } catch (_) {
            }
        }

        setInterval(pollUnreadNotifications, 20000);
        pollUnreadNotifications();
    })();

(function() {
    const hasHigh = document.getElementById('top-bar-config')?.dataset.hasHighPriority === '1';
    if (!hasHigh) return;

    fetch('/notifications/unread')
        .then(r => r.json())
        .then(data => {
            if (!data.success || !Array.isArray(data.notifications)) return;
            const priority = data.notifications.filter(n => n.priority === 'high');
            if (priority.length === 0) return;

            window._priorityNotifIds = priority.map(n => n.id);

            const list = document.getElementById('priority-notif-list');
            list.innerHTML = priority.map(n => `
                <div class="pnm-item" data-nid="${n.id}">
                    <div class="pnm-item-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5">
                            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                    </div>
                    <div style="flex:1">
                        <div class="pnm-item-title">${escHtml(n.title)}</div>
                        <div class="pnm-item-msg">${escHtml(n.message)}</div>
                        <div class="pnm-item-time">${formatTime(n.created_at)}</div>
                    </div>
                    ${n.link ? `<a href="${escHtml(n.link)}" style="font-size:11px;font-weight:700;color:#dc2626;text-decoration:underline;white-space:nowrap;">Apri</a>` : ''}
                </div>
            `).join('');

            document.getElementById('priority-notif-modal').style.display = 'flex';
        })
        .catch(() => {});

    function escHtml(s) {
        if (!s) return '';
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
    function formatTime(ts) {
        if (!ts) return '';
        try {
            const d = new Date(ts);
            return d.toLocaleDateString('it-IT') + ' ' + d.toLocaleTimeString('it-IT', {hour:'2-digit',minute:'2-digit'});
        } catch(e) { return ts; }
    }
})();

async function dismissPriorityModal() {
    document.getElementById('priority-notif-modal').style.display = 'none';

    // Mark all shown priority notifications as read
    const ids = window._priorityNotifIds || [];
    for (const id of ids) {
        try {
            await fetch('/notifications/action', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&notification_id=' + id
            });
        } catch(e) {}
    }
    // Remove from bell dropdown too
    ids.forEach(id => {
        const item = document.querySelector(`.notification-item[data-id="${id}"]`);
        if (item) item.remove();
    });
    if (typeof ensureEmptyMessage === 'function') ensureEmptyMessage();
}

    (function () {
        const openBtn = document.getElementById('open-history');
        const historyList = document.getElementById('history-list');

        async function loadHistory() {
            historyList.innerHTML = '<div class="text-slate-500">Caricamento...</div>';

            const res = await fetch('/notifications/history');
            const data = await res.json();
            if (!res.ok || !data.success) {
                historyList.innerHTML = '<div class="text-rose-600">Errore nel caricamento storico.</div>';
                return;
            }

            if (!data.notifications.length) {
                historyList.innerHTML = '<div class="text-slate-500">Nessuna notifica letta.</div>';
                return;
            }

            historyList.innerHTML = data.notifications.map((n) => {
                const createdAt = n.created_at ? new Date(n.created_at.replace(' ', 'T')).toLocaleString('it-IT') : '-';
                const readAt = n.read_at ? new Date(n.read_at.replace(' ', 'T')).toLocaleString('it-IT') : '-';
                const open = n.link ? `<a href="${n.link}" class="text-blue-600 underline">Apri</a>` : '';
                return `
                    <div class="border-b py-2">
                        <div class="font-medium">${n.title || 'Notifica'}</div>
                        <div class="text-slate-600">${n.message || ''}</div>
                        <div class="text-xs text-slate-500 mt-1">Da: ${n.created_by_name || 'Sistema'}</div>
                        <div class="text-xs text-slate-400 mt-1">Creata: ${createdAt} • Letta: ${readAt}</div>
                        <div class="mt-1 text-xs">${open}</div>
                    </div>
                `;
            }).join('');
        }

        openBtn?.addEventListener('click', function () {
            loadHistory();
        });
    })();

    document.getElementById('run-recalculate-margin')?.addEventListener('click', function (e) {
        e.preventDefault();

        const resultBox = document.getElementById('recalculate-margin-result');
        resultBox.className = 'text-xs mt-1 text-slate-500';
        resultBox.textContent = 'Ricalcolo in corso…';
        resultBox.classList.remove('hidden');

        fetch('/worksites/recalculate-margin', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'ok') {
                    resultBox.className = 'text-xs mt-1 text-emerald-700';
                    resultBox.textContent = '✅ ' + data.message;
                } else {
                    resultBox.className = 'text-xs mt-1 text-rose-700';
                    resultBox.textContent = '❌ ' + data.message;
                }
            })
            .catch(() => {
                resultBox.className = 'text-xs mt-1 text-rose-700';
                resultBox.textContent = '❌ Errore imprevisto durante il ricalcolo.';
            });
    });

    document.getElementById('run-yard-status')?.addEventListener('click', function (e) {
        e.preventDefault();

        const resultBox = document.getElementById('yard-status-result');
        resultBox.className = 'text-xs mt-1 text-slate-500';
        resultBox.textContent = 'Verifica in corso…';
        resultBox.classList.remove('hidden');

        fetch('/worksites/yard-status', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'ok') {
                    resultBox.className = 'text-xs mt-1 text-emerald-700';
                    resultBox.textContent = '✅ ' + data.message;
                } else {
                    resultBox.className = 'text-xs mt-1 text-rose-700';
                    resultBox.textContent = '❌ ' + data.message;
                }
            })
            .catch(() => {
                resultBox.className = 'text-xs mt-1 text-rose-700';
                resultBox.textContent = '❌ Errore imprevisto durante la verifica.';
            });
    });

    (function () {
        const btn = document.getElementById('enable-browser-push');
        const statusBox = document.getElementById('push-status');
        const vapidPublicKey = document.getElementById('top-bar-config')?.dataset.vapidPublicKey ?? '';

        function setStatus(message, isError = false) {
            if (!statusBox) return;
            statusBox.className = isError ? 'text-xs text-rose-600 mt-2' : 'text-xs text-emerald-700 mt-2';
            statusBox.textContent = message;
        }

        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
            return outputArray;
        }

        async function saveSubscription(subscription) {
            const response = await fetch('/notifications/push-subscription', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(subscription)
            });

            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Impossibile salvare la subscription');
        }

        async function reflectPushButtonState() {
            if (!btn) return;

            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                btn.classList.remove('hidden');
                return;
            }

            try {
                const registration = await navigator.serviceWorker.getRegistration('/');
                const subscription = registration ? await registration.pushManager.getSubscription() : null;
                const isEnabled = Notification.permission === 'granted' && !!subscription;

                if (isEnabled) {
                    btn.classList.add('hidden');
                    setStatus('Notifiche browser già attive ✅');
                } else {
                    btn.classList.remove('hidden');
                }
            } catch (_) {
                btn.classList.remove('hidden');
            }
        }

        async function waitForActiveServiceWorker(registration) {
            if (registration.active) return registration;

            await new Promise((resolve) => {
                const timeout = setTimeout(resolve, 5000);
                const worker = registration.installing || registration.waiting;

                if (!worker) {
                    clearTimeout(timeout);
                    resolve();
                    return;
                }

                worker.addEventListener('statechange', () => {
                    if (worker.state === 'activated') {
                        clearTimeout(timeout);
                        resolve();
                    }
                });
            });

            return registration;
        }

        reflectPushButtonState();

        btn?.addEventListener('click', async () => {
            try {
                if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                    setStatus('Push non supportate da questo browser.', true);
                    return;
                }

                if (!vapidPublicKey) {
                    setStatus('VAPID_PUBLIC_KEY non configurata.', true);
                    return;
                }

                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    setStatus('Permesso notifiche non concesso.', true);
                    return;
                }

                const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
                await navigator.serviceWorker.ready;
                await waitForActiveServiceWorker(registration);

                const activeRegistration = await navigator.serviceWorker.getRegistration('/');
                if (!activeRegistration) throw new Error('Service Worker non attivo. Ricarica la pagina e riprova.');

                const existingSubscription = await activeRegistration.pushManager.getSubscription();
                const subscription = existingSubscription || await activeRegistration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
                });

                await saveSubscription(subscription.toJSON());
                setStatus('Browser registrato correttamente ✅');
                await reflectPushButtonState();
            } catch (error) {
                setStatus('Errore: ' + error.message, true);
            }
        });
    })();
