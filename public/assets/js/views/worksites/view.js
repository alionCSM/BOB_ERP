    // ── Delete Modal (pure JS) ──────────────────────
    function wvOpenDeleteModal() {
        document.getElementById('wv-delete-overlay').classList.add('active');
    }
    function wvCloseDeleteModal() {
        document.getElementById('wv-delete-overlay').classList.remove('active');
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') wvCloseDeleteModal();
    });

    document.addEventListener("DOMContentLoaded", function () {
        // === GESTIONE TABS (memorizza e ripristina l'ultimo tab attivo) ===
        const tabButtons = document.querySelectorAll('[data-bs-toggle="tab"]');

        // Sync custom tab styling with bootstrap
        tabButtons.forEach(btn => {
            btn.addEventListener('shown.bs.tab', function(event) {
                tabButtons.forEach(b => b.classList.remove('active'));
                event.target.classList.add('active');
                const targetTab = event.target.getAttribute('data-bs-target');
                sessionStorage.setItem('activeWorksiteTab', targetTab);
            });
        });

        // Ripristina l'ultimo tab salvato
        const lastTab = sessionStorage.getItem('activeWorksiteTab');
        if (lastTab) {
            const triggerTab = document.querySelector(`[data-bs-target="${lastTab}"]`);
            if (triggerTab) {
                const tab = new bootstrap.Tab(triggerTab);
                tab.show();
            }
        }

        // === Note tab: wire edit buttons to the shared edit modal ===
        // Worksite id read from window.location pathname (/worksites/{id})
        var pathParts   = (location.pathname || '').split('/');
        var worksiteIdx = pathParts.indexOf('worksites');
        var worksiteId  = (worksiteIdx >= 0 && pathParts[worksiteIdx + 1]) ? pathParts[worksiteIdx + 1] : null;

        document.querySelectorAll('.wv-note-edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var noteId  = this.dataset.noteId;
                var content = this.dataset.noteContent || '';
                var tipo    = this.dataset.noteTipo    || 'generica';
                var form    = document.getElementById('note-edit-form');
                var ta      = document.getElementById('note-edit-content');
                var sel     = document.getElementById('note-edit-tipo');
                if (!form || !ta || !worksiteId || !noteId) return;
                form.action  = '/worksites/' + worksiteId + '/finance-notes/' + noteId + '/edit';
                ta.value     = content;
                if (sel) sel.value = tipo;
                var modalEl  = document.getElementById('note-edit-modal');
                if (modalEl && window.tailwind && tailwind.Modal) {
                    tailwind.Modal.getOrCreateInstance(modalEl).show();
                } else if (modalEl && window.bootstrap && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(modalEl).show();
                }
                setTimeout(function () { ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); }, 60);
            });
        });

        // === Search filter wiring (extras + fatturazione tabs) ===
        function wireTableSearch(inputId, tableId) {
            const input = document.getElementById(inputId);
            const table = document.getElementById(tableId);
            if (!input || !table) return;
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            input.addEventListener('input', function () {
                const q = this.value.trim().toLowerCase();
                tbody.querySelectorAll('tr').forEach(function (row) {
                    // Skip the empty-state row (single big colspan)
                    if (row.children.length === 1) return;
                    const visible = q === '' || row.textContent.toLowerCase().indexOf(q) !== -1;
                    row.style.display = visible ? '' : 'none';
                });
            });
        }
        wireTableSearch('wv-extras-search',  'wv-extras-table');
        wireTableSearch('wv-billing-search', 'wv-billing-table');

        // === GESTIONE FILTRI DATE (per ogni tabella con bottoni filtro) ===
        const dateContainers = document.querySelectorAll('.date-scrollbar, .date-scrollbar-cons');
        dateContainers.forEach(container => {
            const buttons = container.querySelectorAll('.date-filter-btn');
            const rows = container.parentElement.querySelectorAll('tbody tr[data-date]');
            const selectedDates = new Set();

            buttons.forEach(btn => {
                btn.addEventListener('click', function (e) {
                    const selectedDate = this.getAttribute('data-filter-date');

                    if (selectedDate === 'all') {
                        selectedDates.clear();
                        rows.forEach(row => row.style.display = '');
                        buttons.forEach(b => {
                            b.style.backgroundColor = 'white';
                            b.style.color = '#333';
                            b.style.borderColor = '#ccc';
                            b.style.fontWeight = 'normal';
                        });
                        return;
                    }

                    if (!e.ctrlKey) {
                        selectedDates.clear();
                        selectedDates.add(selectedDate);
                    } else {
                        if (selectedDates.has(selectedDate)) {
                            selectedDates.delete(selectedDate);
                        } else {
                            selectedDates.add(selectedDate);
                        }
                    }

                    buttons.forEach(b => {
                        const date = b.getAttribute('data-filter-date');
                        if (selectedDates.has(date)) {
                            b.style.backgroundColor = '#3b82f6';
                            b.style.color = 'white';
                            b.style.borderColor = '#3b82f6';
                            b.style.fontWeight = 'bold';
                        } else {
                            b.style.backgroundColor = 'white';
                            b.style.color = '#333';
                            b.style.borderColor = '#ccc';
                            b.style.fontWeight = 'normal';
                        }
                    });

                    if (selectedDates.size === 0) {
                        rows.forEach(row => row.style.display = '');
                    } else {
                        rows.forEach(row => {
                            const rowDate = row.getAttribute('data-date');
                            row.style.display = selectedDates.has(rowDate) ? '' : 'none';
                        });
                    }
                });
            });
        });
    });
