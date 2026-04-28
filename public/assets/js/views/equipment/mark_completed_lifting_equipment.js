    function toggleRow(idx) {
        const chk = document.querySelector(`input[name="selected[]"][value="${idx}"]`);
        const qf  = document.getElementById(`quantita_${idx}`);
        const df  = document.getElementById(`datafine_${idx}`);
        qf.disabled      = !chk.checked;
        df.disabled      = !chk.checked;
        qf.required      = chk.checked;
        df.required      = chk.checked;
    }
    // Al submit riabilita tutti i campi necessari
    document.querySelector("form").addEventListener("submit", () => {
        document.querySelectorAll('input[name="selected[]"]').forEach(chk => {
            const idx = chk.value;
            if (chk.checked) {
                document.getElementById(`quantita_${idx}`).disabled = false;
                document.getElementById(`datafine_${idx}`).disabled = false;
            }
        });
    });
