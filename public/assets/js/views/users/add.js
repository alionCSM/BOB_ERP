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
