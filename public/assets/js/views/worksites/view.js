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
