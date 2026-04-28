    const pwdInput = document.getElementById('password');
    const bar      = document.getElementById('pwd-bar');
    const text     = document.getElementById('pwd-text');
    const btn      = document.querySelector('button[type="submit"]');
    const colors   = ['#ef4444', '#f59e0b', '#3b82f6', '#059669'];

    pwdInput.addEventListener('input', () => {
        const val = pwdInput.value;
        let score = 0;
        if (val.length >= 8)                              score++;
        if (/[a-z]/.test(val) && /[A-Z]/.test(val))      score++;
        if (/\d/.test(val))                               score++;
        if (/[^a-zA-Z0-9]/.test(val))                    score++;

        bar.style.width           = [0, 25, 50, 75, 100][score] + '%';
        bar.style.backgroundColor = score > 0 ? colors[score - 1] : 'transparent';
        btn.disabled              = score < 3;
        text.textContent          = ['', 'Password debole', 'Password media', 'Password buona', 'Password forte'][score];
    });
