document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('[data-delete-id]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id  = btn.getAttribute('data-delete-id');
            var row = btn.closest('tr');

            // Remove the row from the DOM
            row.remove();

            // Append a hidden input to the save form so the deletion is sent on submit
            var input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'delete_ids[]';
            input.value = id;
            document.querySelector('form').appendChild(input);
        });
    });

    // dismiss toast (era onclick inline → bloccato da CSP)
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-dismiss-alert]');
        if (btn && btn.parentElement) btn.parentElement.style.display = 'none';
    });

});
