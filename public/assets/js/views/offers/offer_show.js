// ── Status change ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const statusGroup = document.getElementById('statusSelectGroup');
    if (!statusGroup) return;

    const offerId = statusGroup.dataset.offerId;

    const heroBadge = document.getElementById('heroBadge');

    statusGroup.querySelectorAll('.cfo-status-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            statusGroup.querySelectorAll('.cfo-status-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            if (heroBadge) {
                heroBadge.className = 'cfl-badge cfl-badge-' + this.dataset.val;
                heroBadge.textContent = this.textContent.trim();
            }

            fetch('/offers/' + offerId + '/status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'status=' + encodeURIComponent(this.dataset.val)
            });
        });
    });
});

// ── Follow-up CRUD ────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('toggleFollowupForm');
    const form      = document.getElementById('followupForm');
    const cancelBtn = document.getElementById('cancelFollowup');
    const saveBtn   = document.getElementById('saveFollowup');
    const list      = document.getElementById('followupList');

    if (!toggleBtn) return;

    const offerId = saveBtn.dataset.offerId;

    toggleBtn.addEventListener('click', function () {
        form.classList.toggle('hidden');
    });

    cancelBtn.addEventListener('click', function () {
        form.classList.add('hidden');
    });

    saveBtn.addEventListener('click', function () {
        const type = document.querySelector('input[name="fu_type"]:checked')?.value || 'nota';
        const date = document.getElementById('fu_date').value;
        const note = document.getElementById('fu_note').value.trim();

        if (!note) { document.getElementById('fu_note').focus(); return; }

        saveBtn.disabled = true;

        fetch('/offers/' + offerId + '/followups', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'type=' + encodeURIComponent(type) +
                  '&date=' + encodeURIComponent(date) +
                  '&note=' + encodeURIComponent(note)
        })
        .then(r => r.json())
        .then(function (data) {
            saveBtn.disabled = false;
            if (!data.success) return;

            form.classList.add('hidden');
            document.getElementById('fu_note').value = '';

            const emptyEl = document.getElementById('followupEmpty');
            if (emptyEl) emptyEl.remove();

            const typeIcons = {
                chiamata: '<svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81a19.79 19.79 0 01-3.07-8.68A2 2 0 012 .91h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L6.09 8.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>',
                email:    '<svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
                sms:      '<svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
                riunione: '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>',
                nota:     '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'
            };
            const typeLabels = { chiamata:'Chiamata', email:'Email', sms:'SMS', riunione:'Riunione', nota:'Nota' };

            const today = new Date(date);
            const dateStr = String(today.getDate()).padStart(2,'0') + '/' +
                            String(today.getMonth()+1).padStart(2,'0') + '/' +
                            today.getFullYear();

            const el = document.createElement('div');
            el.className = 'cfo-followup-item';
            el.dataset.fuId = data.id;
            el.innerHTML = `
                <div class="cfo-followup-item-icon cfo-futype-${type}">${typeIcons[type] || typeIcons.nota}</div>
                <div class="cfo-followup-item-body">
                    <div class="cfo-followup-item-meta">
                        <span class="cfo-followup-item-type">${typeLabels[type] || type}</span>
                        <span class="cfo-followup-item-date">${dateStr}</span>
                    </div>
                    <p class="cfo-followup-item-note">${escapeHtml(note)}</p>
                </div>
                <button type="button" class="cfo-followup-delete" data-fu-id="${data.id}" title="Elimina">
                    <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </button>`;
            list.prepend(el);
        })
        .catch(function () { saveBtn.disabled = false; });
    });

    list.addEventListener('click', function (e) {
        const btn    = e.target.closest('.cfo-followup-delete');
        if (!btn) return;

        const fuId   = btn.dataset.fuId;
        const itemEl = btn.closest('.cfo-followup-item');

        fetch('/offers/' + offerId + '/followups/' + fuId + '/delete', { method: 'POST' })
        .then(r => r.json())
        .then(function (data) {
            if (!data.success) return;
            itemEl.remove();
            if (!list.querySelector('.cfo-followup-item')) {
                const empty = document.createElement('div');
                empty.className = 'cfo-followup-empty';
                empty.id = 'followupEmpty';
                empty.innerHTML = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Nessuna attività registrata';
                list.appendChild(empty);
            }
        });
    });

    function escapeHtml(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
});
