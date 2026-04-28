document.addEventListener('DOMContentLoaded', function () {

    // ── Filter state (two independent dimensions) ─────────────────────────────
    var cpTypeFilter   = 'all';    // all | yes | no  (consorziata)
    var cpStatusFilter = 'active'; // all | active | inactive  — default: active

    function cpApply() {
        var q = (document.getElementById('cp-search').value || '').toLowerCase();
        var visible = 0;
        document.querySelectorAll('.cp-row').forEach(function (row) {
            var matchType   = cpTypeFilter   === 'all' || row.dataset.consorziata === cpTypeFilter;
            var matchStatus = cpStatusFilter === 'all' || row.dataset.active       === cpStatusFilter;
            var matchText   = row.textContent.toLowerCase().includes(q);
            var show = matchType && matchStatus && matchText;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        document.getElementById('cp-count').textContent =
            visible + ' aziend' + (visible === 1 ? 'a' : 'e');
    }

    // Type filter buttons (Tutte / Consorziate / Non consorziate)
    document.querySelectorAll('[data-filter-type]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            cpTypeFilter = btn.dataset.filterType;
            document.querySelectorAll('[data-filter-type]').forEach(function (b) {
                b.classList.toggle('active', b === btn);
            });
            cpApply();
        });
    });

    // Status filter buttons (Tutti stati / Attive / Inattive)
    document.querySelectorAll('[data-filter-status]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            cpStatusFilter = btn.dataset.filterStatus;
            document.querySelectorAll('[data-filter-status]').forEach(function (b) {
                b.classList.toggle('active', b === btn);
            });
            cpApply();
        });
    });

    document.getElementById('cp-search').addEventListener('input', cpApply);

    // Apply default filter on load
    cpApply();

    // ── Row click → navigate ──────────────────────────────────────────────────
    document.querySelectorAll('.cp-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('.cp-no-nav')) return;
            if (row.dataset.href) window.location = row.dataset.href;
        });
    });

    // ── Delete modal ──────────────────────────────────────────────────────────
    document.querySelectorAll('[data-company-id][data-tw-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('delete-company-form').action =
                '/companies/' + btn.dataset.companyId + '/delete';
        });
    });

    // ── Toggle active (inline, no page reload) ────────────────────────────────
    document.querySelectorAll('.cp-act-toggle').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var id = btn.dataset.companyId;
            var row = btn.closest('.cp-row');

            btn.disabled = true;
            fetch('/companies/' + id + '/toggle-active', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Fetch': '1',
                },
                body: '_csrf=' + encodeURIComponent(
                    document.querySelector('meta[name="csrf-token"]')?.content ?? ''
                )
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) { btn.disabled = false; return; }

                var isActive = res.active === 1;

                // Update row data attribute
                row.dataset.active = isActive ? 'active' : 'inactive';
                row.classList.toggle('cp-row-inactive', !isActive);

                // Update status badge
                var badge = document.getElementById('cp-status-' + id);
                if (badge) {
                    badge.className = 'cp-badge ' + (isActive ? 'cp-badge-active' : 'cp-badge-inactive');
                    badge.innerHTML = '<span class="cp-status-dot"></span>' +
                        (isActive ? 'Attiva' : 'Inattiva');
                }

                // Update toggle button icon + title
                btn.dataset.active = isActive ? '1' : '0';
                btn.title = isActive ? 'Disattiva azienda' : 'Attiva azienda';
                btn.innerHTML = isActive
                    ? '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>'
                    : '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21 5 3"/></svg>';

                btn.disabled = false;

                // Re-apply current filter (inactive rows should hide if filter is 'active')
                cpApply();
            })
            .catch(function () { btn.disabled = false; });
        });
    });

    // ── TomSelect for azienda search ──────────────────────────────────────────
    var aziendaEl = document.querySelector('#aziendaSelect');
    if (aziendaEl && !aziendaEl.tomselect) {
        new TomSelect(aziendaEl, {
            valueField: 'id',
            labelField: 'name',
            searchField: 'name',
            load: function (query, callback) {
                if (query.length < 2) return callback();
                fetch('/api/search-company?q=' + encodeURIComponent(query))
                    .then(function (res) { return res.json(); })
                    .then(callback)
                    .catch(function () { callback(); });
            }
        });
    }

});
