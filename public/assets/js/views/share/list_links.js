document.addEventListener("DOMContentLoaded", () => {
// ── Password store ──────────────────────────────
const linkPasswords = {};

    // ── Load tokenToLinkId from meta tag (CSP-compliant) ────────────────────────
    const meta = document.querySelector('meta[name="token-to-link-id"]');
    const tokenToLinkId = {};
    if (meta && meta.content) {
        try {
            const data = JSON.parse(meta.content);
            data.forEach(item => { tokenToLinkId[item.token] = item.id; });
        } catch (e) { console.error('Failed to parse tokenToLinkId:', e); }
    }

    // Read password from URL hash (passed from create_link.php or password save)
    (function() {
        const hash = window.location.hash.substring(1);
        if (!hash) return;
        const params = new URLSearchParams(hash);
        const pwd = params.get('pwd');
        const token = params.get('token');
        if (pwd && token && tokenToLinkId[token]) {
            linkPasswords[tokenToLinkId[token]] = pwd;
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    })();

    function generatePassword(length = 12) {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        const array = new Uint32Array(length);
        crypto.getRandomValues(array);
        return Array.from(array, v => chars[v % chars.length]).join('');
    }

    // ── Copy ────────────────────────────────────────
    function copyLinkInfo(linkId, url, btn) {
        const pwd = linkPasswords[linkId];
        let text = 'Link: ' + url;
        if (pwd) text += '\nPassword: ' + pwd;
        navigator.clipboard.writeText(text).then(() => {
            if (btn) {
                const orig = btn.innerHTML;
                btn.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> OK';
                btn.classList.add('copied');
                setTimeout(() => { btn.innerHTML = orig; btn.classList.remove('copied'); }, 2000);
            }
            const toast = document.getElementById('sl-toast');
            toast.textContent = pwd ? 'Link e password copiati!' : 'Link copiato negli appunti!';
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2500);
        });
    }

    // ── Toggle active/inactive ────────────────────────
    function toggleLinkActive(linkId, btn) {
        const form = new FormData();
        form.append('_csrf', document.querySelector('input[name="_csrf"]').value);
        form.append('link_id', linkId);
        fetch('/share/toggle-active', { method: 'POST', body: form, credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    location.reload();
                } else {
                    alert(data.error || 'Errore');
                }
            })
            .catch(err => {
                console.error('Toggle error:', err);
                alert('Errore di rete');
            });
    }

    // ── Event delegation for buttons ─────────────────
    document.addEventListener('click', function(e) {
        const target = e.target.closest('[data-action]');
        if (!target) return;

        const action = target.dataset.action;

        if (action === 'copy-link') {
            e.preventDefault();
            const linkId = target.dataset.linkId;
            const url = target.dataset.linkUrl;
            copyLinkInfo(linkId, url, target);
        }
        else if (action === 'toggle-link-active') {
            e.preventDefault();
            const linkId = target.dataset.linkId;
            toggleLinkActive(linkId, target);
        }
        else if (action === 'open-pwd-modal') {
            e.preventDefault();
            const linkId = target.dataset.linkId;
            openPwdModal(linkId);
        }
        else if (action === 'delete-link') {
            e.preventDefault();
            const linkId = target.dataset.linkId;
            document.getElementById('deleteLinkId').value = linkId;
            const modalEl = document.getElementById('delete-link-modal');
            const modal = tailwind.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }
    });

    // ── Password modal ──────────────────────────────
    function openPwdModal(linkId) {
        const pwd = generatePassword();
        document.getElementById('pwdLinkId').value = linkId;
        document.getElementById('pwdGenerated').value = pwd;
        document.getElementById('pwdResult').textContent = '';
        const el = document.getElementById('password-modal');
        const modal = tailwind.Modal.getOrCreateInstance(el);
        modal.show();
    }

    function saveLinkPassword(linkId, password, removing) {
        const form = new FormData();
        form.append('_csrf', document.querySelector('input[name="_csrf"]').value);
        form.append('link_id', linkId);
        form.append('password', password);
        fetch('/share/update-password', { method: 'POST', body: form, credentials: 'same-origin' })
            .then(r => {
                if (!r.ok) return r.text().then(t => { throw new Error('HTTP ' + r.status + ': ' + t); });
                return r.json();
            })
            .then(data => {
                if (data.ok) {
                    let token = '';
                    for (const [t, id] of Object.entries(tokenToLinkId)) {
                        if (id == linkId) { token = t; break; }
                    }
                    if (!removing && password && token) {
                        window.location.hash = 'pwd=' + encodeURIComponent(password) + '&token=' + encodeURIComponent(token);
                    }
                    location.reload();
                } else {
                    document.getElementById('pwdResult').textContent = data.error || 'Errore';
                }
            })
            .catch(err => {
                console.error('Password update error:', err);
                document.getElementById('pwdResult').textContent = 'Errore: ' + (err.message || 'Errore di rete');
            });
    }

    // ── Wire password modal buttons ─────────────────
    document.getElementById('pwdRegenBtn').addEventListener('click', function () {
        document.getElementById('pwdGenerated').value = generatePassword();
    });

    document.getElementById('pwdSaveBtn').addEventListener('click', function () {
        const linkId = document.getElementById('pwdLinkId').value;
        const password = document.getElementById('pwdGenerated').value;
        saveLinkPassword(linkId, password, false);
    });

    // ── Search + Pagination + Sort ──────────────────
    (function() {
        const tbody = document.getElementById('links-tbody');
        if (!tbody) return;

        const allRows = Array.from(tbody.querySelectorAll('tr.sl-row'));
        let filteredRows = allRows.slice();
        let perPage = 25;
        let currentPage = 1;
        let sortCol = null;
        let sortDir = 'asc';

        // Search
        document.getElementById('search-links')?.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            filteredRows = allRows.filter(r => !q || (r.dataset.title || '').includes(q));
            currentPage = 1;
            render();
        });

        // Per page
        document.getElementById('sl-per-page')?.addEventListener('change', function () {
            perPage = parseInt(this.value);
            currentPage = 1;
            render();
        });

        // Sort headers
        document.querySelectorAll('.sl-table thead th[data-sort]').forEach(th => {
            th.addEventListener('click', function () {
                const col = this.dataset.sort;
                if (sortCol === col) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortCol = col;
                    sortDir = 'asc';
                }
                // Update header classes
                document.querySelectorAll('.sl-table thead th').forEach(h => h.classList.remove('sorted-asc', 'sorted-desc'));
                this.classList.add('sorted-' + sortDir);

                filteredRows.sort((a, b) => {
                    let va = a.dataset[col] || '';
                    let vb = b.dataset[col] || '';
                    if (col === 'files') { va = parseInt(va); vb = parseInt(vb); return sortDir === 'asc' ? va - vb : vb - va; }
                    return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
                });
                currentPage = 1;
                render();
            });
        });

        function render() {
            const total = filteredRows.length;
            const pages = Math.max(1, Math.ceil(total / perPage));
            if (currentPage > pages) currentPage = pages;

            const start = (currentPage - 1) * perPage;
            const end = Math.min(start + perPage, total);

            // Hide all, show current page
            allRows.forEach(r => r.style.display = 'none');
            for (let i = start; i < end; i++) {
                filteredRows[i].style.display = '';
            }

            // Page info
            const info = document.getElementById('sl-page-info');
            if (info) info.textContent = total === 0 ? '0 risultati' : (start + 1) + '-' + end + ' di ' + total;

            // Count label
            const lbl = document.getElementById('sl-count-label');
            if (lbl) lbl.textContent = total + (total === 1 ? ' risultato' : ' risultati');

            // No results
            const noRes = document.getElementById('no-results');
            const tableWrap = document.querySelector('.sl-table-wrap');
            if (noRes && tableWrap) {
                noRes.style.display = total === 0 ? '' : 'none';
                tableWrap.style.display = total === 0 ? 'none' : '';
            }

            // Pagination buttons
            const btnsWrap = document.getElementById('sl-page-btns');
            if (!btnsWrap) return;
            btnsWrap.innerHTML = '';

            if (pages <= 1) return;

            // Prev
            const prev = mkBtn('‹', currentPage > 1, () => { currentPage--; render(); });
            btnsWrap.appendChild(prev);

            // Page numbers (show max 7)
            let startP = Math.max(1, currentPage - 3);
            let endP = Math.min(pages, startP + 6);
            if (endP - startP < 6) startP = Math.max(1, endP - 6);

            for (let p = startP; p <= endP; p++) {
                const btn = mkBtn(p, true, () => { currentPage = p; render(); });
                if (p === currentPage) btn.classList.add('active');
                btnsWrap.appendChild(btn);
            }

            // Next
            const next = mkBtn('›', currentPage < pages, () => { currentPage++; render(); });
            btnsWrap.appendChild(next);
        }

        function mkBtn(label, enabled, onClick) {
            const btn = document.createElement('button');
            btn.className = 'sl-page-btn';
            btn.textContent = label;
            btn.disabled = !enabled;
            if (enabled) btn.addEventListener('click', onClick);
            return btn;
        }

        render();
    })();
});
