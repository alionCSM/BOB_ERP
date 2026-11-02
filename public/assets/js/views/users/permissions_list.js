// La riga si apre cliccandola. Prima era un onclick nell'attributo, che la
// CSP di BOB blocca: cliccare una riga non faceva niente.
document.addEventListener('click', function (e) {
    // non dalle celle dei comandi, e non da un link o un bottone dentro
    if (e.target.closest('[data-niente-riga]') || e.target.closest('a, button')) {
        return;
    }
    const riga = e.target.closest('tr[data-href]');
    if (riga) {
        window.location = riga.dataset.href;
    }
});

document.getElementById("pm-search").addEventListener("input", function () {
    const term = this.value.toLowerCase();
    let count = 0;
    document.querySelectorAll("#pm-table tbody tr").forEach(r => {
        const show = r.textContent.toLowerCase().includes(term);
        r.style.display = show ? "" : "none";
        if (show) count++;
    });
    document.getElementById("pm-count").textContent = count + (count === 1 ? " utente" : " utenti");
});
