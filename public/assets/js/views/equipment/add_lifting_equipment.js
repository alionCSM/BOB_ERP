document.addEventListener('DOMContentLoaded', function () {

    // Search filter
    var searchInput = document.getElementById('search-mezzo');
    var rows = document.querySelectorAll('#mezzi-table tbody tr');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var filtro = this.value.toLowerCase();
            rows.forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(filtro) ? '' : 'none';
            });
        });
    }

    // Populate delete ID before the modal opens (Tailwind handles open/close)
    var deleteInput = document.getElementById('delete_id_input');

    document.querySelectorAll('[data-delete-id]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            deleteInput.value = btn.getAttribute('data-delete-id');
        });
    });

});
