    let currentStep = 1;
    const checkSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>';

    function updateStepper() {
        for (let s = 1; s <= 3; s++) {
            const ss  = document.getElementById('ss-' + s);
            const num = document.getElementById('ss-num-' + s);
            ss.classList.remove('active', 'done');
            if (s < currentStep) {
                ss.classList.add('done');
                num.innerHTML = checkSvg;
            } else {
                num.textContent = s;
                if (s === currentStep) ss.classList.add('active');
            }
        }
    }

    function goToStep(step) {
        if (step < 1 || step > 3) return;
        document.querySelectorAll('.step-content').forEach(el => el.classList.add('hidden'));
        document.getElementById('step-' + step).classList.remove('hidden');
        currentStep = step;
        updateStepper();
    }

    // I numeri del percorso in cima: si poteva tornare indietro cliccandoli,
    // ma il click stava in un attributo onclick e la CSP di BOB non lo fa
    // partire. (Avanti e Indietro funzionavano, perche' erano gia' qui.)
    document.querySelectorAll('[data-passo]').forEach(el => {
        el.addEventListener('click', () => goToStep(parseInt(el.dataset.passo, 10)));
    });

    // Il codice fiscale si scrive maiuscolo. Il campo lo MOSTRA maiuscolo
    // per via del CSS, ma quello che partiva era quello battuto: il
    // text-transform e' solo grafica, e l'oninput che lo convertiva davvero
    // era bloccato. Si salvavano codici fiscali minuscoli.
    document.querySelectorAll('[data-maiuscolo]').forEach(campo => {
        campo.addEventListener('input', () => {
            const dove = campo.selectionStart;
            campo.value = campo.value.toUpperCase();
            campo.setSelectionRange(dove, dove);
        });
    });

    // Il cerchio della foto apre il selettore di file.
    document.querySelectorAll('[data-apri-file]').forEach(el => {
        el.addEventListener('click', () => document.getElementById(el.dataset.apriFile)?.click());
    });

    document.querySelectorAll('.next-step').forEach(btn => {
        btn.addEventListener('click', () => goToStep(currentStep + 1));
    });
    document.querySelectorAll('.prev-step').forEach(btn => {
        btn.addEventListener('click', () => goToStep(currentStep - 1));
    });

    document.addEventListener('DOMContentLoaded', function() {
        const cs = document.getElementById('company-select');
        if (cs && !cs.tomselect) {
            new TomSelect(cs, {
                placeholder: 'Cerca azienda...',
                allowEmptyOption: false,
                maxOptions: 200,
                create: false,
                sortField: { field: 'text', direction: 'asc' }
            });
        }

        document.getElementById('profile-photo').addEventListener('change', function(e) {
            if (e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = () => { document.getElementById('profile-preview').src = reader.result; };
                reader.readAsDataURL(e.target.files[0]);
            }
        });

        goToStep(1);
    });
