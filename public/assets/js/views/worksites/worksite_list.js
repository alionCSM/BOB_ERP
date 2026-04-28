
    ['status-filter', 'year-filter'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', () => {
            document.getElementById('worksite-filters-form')?.submit();
        });
    });

    (function () {
        const el = document.querySelector("#presenzeCantiereSelect");
        if (el && !el.tomselect) {
            new TomSelect(el, {
                create: false, preload: false, openOnFocus: false,
                valueField: "value", labelField: "text", searchField: "text",
                shouldLoad: query => query.length >= 3,
                load: function (query, callback) {
                    fetch(`/api/attendance/worksites?q=${encodeURIComponent(query)}`)
                        .then(res => res.json()).then(callback).catch(() => callback());
                }
            });
        }
    })();

    // ── Instant fuzzy search ──
    (function() {
        const input     = document.getElementById('instant-search');
        const tbody     = document.getElementById('worksiteTable');
        const counter   = document.getElementById('search-counter');
        const countEl   = document.getElementById('wl-count');
        const spinner   = document.getElementById('search-spinner');
        const showClient = tbody.dataset.showClient === '1';
        const showPrices = tbody.dataset.showPrices === '1';

        const originalHTML = tbody.innerHTML;
        const originalCount = parseInt(tbody.dataset.originalCount, 10);
        let debounceTimer = null;
        let abortCtrl     = null;

        function getFilters() {
            const status = document.getElementById('status-filter')?.value || '';
            const year   = document.getElementById('year-filter')?.value || '';
            return { status, year, client_id: '' };
        }

        function esc(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        function fmtNumber(n) {
            return new Intl.NumberFormat('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
        }

        const riskPath = '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>';

        function riskIcon(type) {
            if (type === 'loss') return `<div class="wl-risk wl-risk-loss"><svg viewBox="0 0 24 24" stroke="#dc2626" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${riskPath}</svg></div>`;
            if (type === 'low')  return `<div class="wl-risk wl-risk-low"><svg viewBox="0 0 24 24" stroke="#d97706" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${riskPath}</svg></div>`;
            return '';
        }

        function statusBadge(s) {
            const cls = { 'In corso': 'wl-status-incorso', 'Completato': 'wl-status-completato', 'Sospeso': 'wl-status-sospeso' };
            return `<span class="wl-status ${cls[s] || 'wl-status-completato'}">${esc(s)}</span>`;
        }

        function buildRow(w) {
            let h = `<tr data-risk="${w.risk ? '1' : '0'}">`;
            h += `<td>${riskIcon(w.risk_type)}</td>`;
            h += `<td><a href="/worksites/${w.id}" class="wl-name">${esc(w.worksite_name)}</a></td>`;
            h += `<td class="center"><span class="wl-code">${esc(w.worksite_code)}</span></td>`;
            h += `<td class="center wl-muted">${esc(w.order_number)}</td>`;
            h += `<td class="center wl-muted">${esc(w.order_date)}</td>`;
            if (showClient) h += `<td class="center wl-muted">${esc(w.client_name)}</td>`;
            if (showPrices) h += `<td class="center wl-price">${w.total !== undefined ? fmtNumber(w.total) + ' &euro;' : ''}</td>`;
            h += `<td class="center wl-muted">${esc(w.location)}</td>`;
            h += `<td class="center">${statusBadge(w.status)}</td>`;
            h += `<td class="center"><div class="wl-actions">
                <a href="/worksites/${w.id}" class="wl-action-btn primary" title="Apri"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" stroke="currentColor" fill="none" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></a>
                <a href="/worksites/${w.id}/edit" class="wl-action-btn warning" title="Modifica"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" stroke="currentColor" fill="none" stroke-width="1.8"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a>
            </div></td>`;
            h += '</tr>';
            return h;
        }

        async function doSearch(query) {
            if (abortCtrl) abortCtrl.abort();
            abortCtrl = new AbortController();

            const filters = getFilters();
            const params = new URLSearchParams({
                q: query, status: filters.status,
                year: filters.year, client_id: filters.client_id
            });

            spinner.classList.add('active');

            try {
                const res = await fetch('/api/worksites/search?' + params, {
                    signal: abortCtrl.signal
                });
                const data = await res.json();

                if (data.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="20"><div class="wl-empty">
                        <svg class="wl-empty-icon" viewBox="0 0 24 24" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="8" x2="14" y2="14"/><line x1="14" y1="8" x2="8" y2="14"/>
                        </svg>
                        <p class="wl-empty-text">Nessun risultato per "<strong>${esc(query)}</strong>"</p>
                    </div></td></tr>`;
                } else {
                    tbody.innerHTML = data.map(buildRow).join('');
                }

                countEl.textContent = data.length;
                counter.textContent = '';

            } catch (e) {
                if (e.name !== 'AbortError') console.error('Search error:', e);
            } finally {
                spinner.classList.remove('active');
            }
        }

        input.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            const val = input.value.trim();

            if (val.length < 2) {
                tbody.innerHTML = originalHTML;
                countEl.textContent = originalCount;
                counter.textContent = '';
                return;
            }

            debounceTimer = setTimeout(() => doSearch(val), 200);
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') e.preventDefault();
        });
    })();
