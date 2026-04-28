            document.getElementById('passwordForm').addEventListener('submit', function(e) {
                e.preventDefault();
                grecaptcha.ready(function() {
                    grecaptcha.execute('<?= htmlspecialchars($siteKey) ?>', {action: 'login'}).then(function(t) {
                        document.getElementById('recaptchaToken').value = t;
                        document.getElementById('passwordForm').submit();
                    });
                });
            });
