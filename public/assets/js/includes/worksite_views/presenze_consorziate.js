    document.addEventListener("DOMContentLoaded", function () {
        const buttons = document.querySelectorAll('.date-filter-btn');
        const rows = document.querySelectorAll('#presenze_cons tbody tr[data-date]');
        const selectedDates = new Set();

        buttons.forEach(btn => {
            btn.addEventListener('click', function (e) {
                const selectedDate = this.getAttribute('data-filter-date');

                if (selectedDate === 'all') {
                    selectedDates.clear();
                    rows.forEach(row => row.style.display = '');
                    buttons.forEach(b => b.classList.remove('active'));
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
                    b.classList.toggle('active', selectedDates.has(date));
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
        const searchInputCons = document.getElementById('searchInputCons');
        const tableRowsCons = document.querySelectorAll('#presenze_cons tbody tr[data-date]');

        searchInputCons.addEventListener('keyup', function () {
            const query = this.value.toLowerCase();

            tableRowsCons.forEach(row => {
                const textContent = row.textContent.toLowerCase();
                if (textContent.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

    });
