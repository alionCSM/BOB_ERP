const toggleTrack = document.getElementById('toggle-track');
let showInactive = localStorage.getItem('wl_show_inactive') !== '0';

function applyFilters() {
    const query = (document.getElementById('wl-search-input')?.value ?? '').toLowerCase().trim();
    toggleTrack.classList.toggle('active', showInactive);

    document.querySelectorAll('.wl-company-group').forEach(group => {
        let visibleInGroup = 0;

        group.querySelectorAll('.wl-table tbody tr').forEach(row => {
            const isActive = row.dataset.active === '1';

            if (!showInactive && !isActive) {
                row.style.display = 'none';
                return;
            }

            if (query && !row.textContent.toLowerCase().includes(query)) {
                row.style.display = 'none';
                return;
            }

            row.style.display = '';
            visibleInGroup++;
        });

        group.style.display = visibleInGroup === 0 ? 'none' : '';
    });
}

applyFilters();

document.getElementById('toggle-inactive').addEventListener('click', function(e) {
    e.preventDefault();
    showInactive = !showInactive;
    localStorage.setItem('wl_show_inactive', showInactive ? '1' : '0');
    applyFilters();
});

const searchInput = document.getElementById('wl-search-input');
if (searchInput) {
    searchInput.addEventListener('input', applyFilters);
    searchInput.closest('form')?.addEventListener('submit', function(e) {
        e.preventDefault();
        applyFilters();
    });
}

// Company group collapse/expand
document.querySelectorAll('.wl-company-header').forEach(header => {
    header.addEventListener('click', function() {
        this.closest('.wl-company-group').classList.toggle('collapsed');
    });
});

// Row navigation + delete via event delegation on each table body
document.querySelectorAll('.wl-table tbody').forEach(tbody => {
    tbody.addEventListener('click', function(e) {
        // Delete button
        const delBtn = e.target.closest('.wl-btn-del');
        if (delBtn) {
            e.preventDefault();
            openDeleteModal(delBtn.dataset.id, delBtn.dataset.uid);
            return;
        }

        // Row click → navigate (skip if clicking a direct link)
        if (!e.target.closest('a')) {
            const row = e.target.closest('tr[data-href]');
            if (row) window.location = row.dataset.href;
        }
    });
});

function openDeleteModal(userId, uid) {
    document.getElementById('wl-delete-id').value = userId;
    document.getElementById('wl-delete-form').action = '/users/' + userId + '/delete';

    let uidInput = document.getElementById('wl-delete-uid-input');
    if (!uidInput) {
        uidInput = document.createElement('input');
        uidInput.type = 'hidden';
        uidInput.id   = 'wl-delete-uid-input';
        uidInput.name = 'uid';
        document.getElementById('wl-delete-form').appendChild(uidInput);
    }
    uidInput.value = uid || '';

    tailwind.Modal.getOrCreateInstance(document.querySelector('#wl-delete-modal')).show();
}
