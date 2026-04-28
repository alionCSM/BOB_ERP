        function switchLang(lang, btn) {
            document.querySelectorAll('.lang-section').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.lang-tab').forEach(el => el.classList.remove('active'));
            document.getElementById('lang-' + lang).classList.add('active');
            btn.classList.add('active');
        }
