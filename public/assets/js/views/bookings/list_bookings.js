// ── "Nuova Prenotazione" dropdown ──────────────────────────────────────────
document.addEventListener('click', function (e) {
    var dd = document.getElementById('newBookingDropdown');
    if (!dd) return;

    if (e.target.closest('#new-booking-btn')) {
        dd.classList.toggle('open');
        return;
    }

    if (!dd.contains(e.target)) {
        dd.classList.remove('open');
    }
});

// ── Confirm dialogs on delete forms ────────────────────────────────────────
document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
        e.preventDefault();
    }
});
