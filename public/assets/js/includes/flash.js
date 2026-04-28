        const toast = document.getElementById('toast-success');
        if (toast) {
            setTimeout(() => {
                toast.style.opacity = '1';
                toast.style.transform = 'translateX(-50%) scale(1)';
            }, 100);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(-50%) scale(0.95)';
            }, 3000);

            setTimeout(() => {
                toast.remove();
            }, 3500);
        }

        const toastError = document.getElementById('toast-error');
        if (toastError) {
            setTimeout(() => {
                toastError.style.opacity = '1';
                toastError.style.transform = 'translateX(-50%) scale(1)';
            }, 100);

            setTimeout(() => {
                toastError.style.opacity = '0';
                toastError.style.transform = 'translateX(-50%) scale(0.95)';
            }, 4000);

            setTimeout(() => {
                toastError.remove();
            }, 4500);
        }

        const toastInfo = document.getElementById('toast-info');
        if (toastInfo) {
            setTimeout(() => {
                toastInfo.style.opacity = '1';
                toastInfo.style.transform = 'translateX(-50%) scale(1)';
            }, 100);

            setTimeout(() => {
                toastInfo.style.opacity = '0';
                toastInfo.style.transform = 'translateX(-50%) scale(0.95)';
            }, 3500);

            setTimeout(() => {
                toastInfo.remove();
            }, 4000);
        }
