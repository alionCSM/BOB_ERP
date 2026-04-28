    // Funzione per filtrare la tabella mentre si digita
    document.getElementById("search-mezzo").addEventListener("input", function () {
        const filtro = this.value.toLowerCase();
        document.querySelectorAll("#mezziTable tbody tr").forEach(riga => {
            riga.style.display = riga.textContent.toLowerCase().includes(filtro) ? "" : "none";
        });
    });
