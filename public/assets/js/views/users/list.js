function openDeleteModal(userId, uid) {
    document.getElementById('delete-user-form').action = '/users/' + userId + '/delete';

    // Set or update the uid hidden input
    let uidInput = document.getElementById('delete-uid-input');
    if (!uidInput) {
        uidInput = document.createElement('input');
        uidInput.type = 'hidden';
        uidInput.id = 'delete-uid-input';
        uidInput.name = 'uid';
        document.getElementById('delete-user-form').appendChild(uidInput);
    }
    uidInput.value = uid;

    tailwind.Modal.getOrCreateInstance(document.querySelector('#delete-confirmation-modal')).show();
}

function openImageModal(imageUrl, fullName) {
    document.getElementById('preview-image').src = imageUrl;
    document.getElementById('preview-worker-name').textContent = fullName;
    tailwind.Modal.getOrCreateInstance(document.querySelector('#image-preview-modal')).show();
}

function changeEntriesPerPage(value) {
    const params = new URLSearchParams(window.location.search);
    params.set('page', 1);
    params.set('limit', value);
    fetchUsers(params);
}

let searchTimeout;
function debouncedSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(performSearch, 400);
}

function performSearch() {
    const searchValue = document.getElementById('search-input').value.trim();
    const params = new URLSearchParams(window.location.search);
    params.set('search', searchValue);
    params.set('page', 1);
    fetchUsers(params);
}

function fetchUsers(params) {
    const ajaxParams = new URLSearchParams(params.toString());
    ajaxParams.set('ajax', '1');

    fetch(`${window.location.pathname}?${ajaxParams.toString()}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('users-summary').textContent = data.summary;
            document.getElementById('users-table-body').innerHTML = data.rows;
            document.getElementById('users-pagination').innerHTML = data.pagination;
            window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`);
        });
}

document.addEventListener('DOMContentLoaded', function() {
    // Search input — live debounced
    document.getElementById('search-input')?.addEventListener('input', debouncedSearch);

    // Entries per page
    document.getElementById('users-limit')?.addEventListener('change', function() {
        changeEntriesPerPage(this.value);
    });

    // Pagination — event delegation
    document.getElementById('users-pagination')?.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (!link) return;
        e.preventDefault();
        const url = new URL(link.getAttribute('href'), window.location.origin);
        fetchUsers(url.searchParams);
    });

    // Table body — delegation for delete, photo preview, row navigation
    document.getElementById('users-table-body')?.addEventListener('click', function(e) {
        // Delete button — highest priority
        const delBtn = e.target.closest('.ul-del-btn');
        if (delBtn) {
            e.preventDefault();
            openDeleteModal(delBtn.dataset.id, delBtn.dataset.uid);
            return;
        }

        // Photo preview
        const photo = e.target.closest('.ul-photo-preview');
        if (photo) {
            e.preventDefault();
            openImageModal(photo.dataset.src, photo.dataset.name);
            return;
        }

        // Row click → navigate (but not if clicking the eye-icon link directly)
        if (!e.target.closest('a')) {
            const row = e.target.closest('.ul-row[data-href]');
            if (row) {
                const href = new URL(row.dataset.href, window.location.origin);
                // Add uid from the delete button if available
                const delBtn = row.querySelector('.ul-del-btn');
                if (delBtn && delBtn.dataset.uid) {
                    href.searchParams.set('uid', delBtn.dataset.uid);
                }
                window.location = href;
            }
        }
    });
});
