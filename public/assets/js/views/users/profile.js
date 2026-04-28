// Photo preview
document.getElementById('photo-input').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('photo-preview').innerHTML =
            '<img src="' + e.target.result + '" alt="Preview" style="width:100%;height:100%;object-fit:cover;">';
        document.getElementById('photo-submit').style.display = 'inline-flex';
    };
    reader.readAsDataURL(file);
});

// Password strength meter
const pwdInput = document.getElementById('new-pwd');
const bar = document.getElementById('pwd-bar');
const text = document.getElementById('pwd-text');
const colors = ['#ef4444', '#f59e0b', '#3b82f6', '#059669'];
const labels = ['', 'Debole', 'Media', 'Buona', 'Forte'];

pwdInput.addEventListener('input', () => {
    const val = pwdInput.value;
    let score = 0;
    if (val.length >= 8) score++;
    if (/[a-z]/.test(val) && /[A-Z]/.test(val)) score++;
    if (/\d/.test(val)) score++;
    if (/[^a-zA-Z0-9]/.test(val)) score++;
    bar.style.width = [0, 25, 50, 75, 100][score] + '%';
    bar.style.backgroundColor = score > 0 ? colors[score - 1] : 'transparent';
    text.textContent = labels[score];
});

// Scroll to #password if hash present
if (window.location.hash === '#password') {
    setTimeout(() => {
        document.getElementById('password').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 300);
}
