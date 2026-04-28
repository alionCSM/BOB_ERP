    // Filtro ricerca testuale
    document.getElementById("search-rental").addEventListener("input", function () {
        const filtro = this.value.toLowerCase();
        document.querySelectorAll("#rentalTable tbody tr").forEach(riga => {
            riga.style.display = riga.textContent.toLowerCase().includes(filtro) ? "" : "none";
        });
    });

    // Ordina attivi prima
    document.getElementById("filter-active").addEventListener("click", function () {
        const tbody = document.querySelector("#rentalTable tbody");
        Array.from(tbody.querySelectorAll("tr"))
            .sort((a, b) => parseInt(b.querySelector(".attivi-count").textContent)
                - parseInt(a.querySelector(".attivi-count").textContent))
            .forEach(riga => tbody.appendChild(riga));
    });

    document.querySelectorAll('[data-edit-url]').forEach(link => {
        link.addEventListener('click', function () {
            const url = this.getAttribute('data-edit-url');
            document.getElementById('confirmModificaBtn').setAttribute('href', url);
        });
    });
