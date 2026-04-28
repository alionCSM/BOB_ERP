// Auto-fill username from email
document.getElementById('email').addEventListener('blur', function() {
    const usernameField = document.getElementById('username');
    if (usernameField.value === '' && this.value !== '') {
        usernameField.value = this.value.toLowerCase().trim();
    }
});
