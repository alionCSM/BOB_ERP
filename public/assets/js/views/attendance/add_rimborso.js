    document.addEventListener('DOMContentLoaded', function() {
        const operaioSelect = new TomSelect('#operaioSelect', {
            valueField: 'value',
            labelField: 'text',
            searchField: 'text',
            placeholder: 'Cerca operaio...',
            load: function(query, callback) {
                fetch('/api/attendance/workers?q=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(callback)
                    .catch(() => callback());
            },
            shouldLoad: query => query.length >= 2
        });

        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelector('input[name="rimborso_id"]').value = this.dataset.id;
                document.querySelector('input[name="data"]').value = this.dataset.data;
                document.querySelector('input[name="importo"]').value = this.dataset.importo;
                document.querySelector('textarea[name="note"]').value = this.dataset.note || '';

                operaioSelect.addOption({ value: this.dataset.operaio, text: this.closest('tr').children[1].innerText });
                operaioSelect.setValue(this.dataset.operaio);

                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });

        document.getElementById('searchInput').addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#rimborsiTable tbody tr');

            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
    });
