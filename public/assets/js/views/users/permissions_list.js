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
