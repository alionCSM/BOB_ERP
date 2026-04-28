    const photoInput = document.getElementById("profile-photo");
    if (photoInput) {
        photoInput.addEventListener("change", function (event) {
            const file = event.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = () => {
                document.getElementById("profile-preview").src = reader.result;
            };
            reader.readAsDataURL(file);
        });
    }


    document.addEventListener("DOMContentLoaded", function() {
        const urlParams = new URLSearchParams(window.location.search);
        const tabFromUrl = urlParams.get("tab");

        let activeTab = tabFromUrl || localStorage.getItem("activeTab") || "personal-info";

        document.querySelectorAll(".tab-content").forEach(c => c.classList.add("hidden"));
        document.querySelectorAll(".tab-link").forEach(t => { t.classList.remove("active-tab"); t.classList.remove("active"); });

        const activeContent = document.getElementById(activeTab);
        const activeLink = document.querySelector(`.tab-link[data-tab="${activeTab}"]`);

        if (activeContent && activeLink) {
            activeContent.classList.remove("hidden");
            activeLink.classList.add("active-tab");
        } else {
            localStorage.removeItem("activeTab");
            document.getElementById("personal-info").classList.remove("hidden");
            document.querySelector('.tab-link[data-tab="personal-info"]').classList.add("active-tab");
        }

        document.querySelectorAll(".tab-link").forEach(tab => {
            tab.addEventListener("click", function(event) {
                event.preventDefault();
                document.querySelectorAll(".tab-content").forEach(c => c.classList.add("hidden"));
                document.querySelectorAll(".tab-link").forEach(t => { t.classList.remove("active-tab"); t.classList.remove("active"); });
                let selectedTab = this.dataset.tab;
                const content = document.getElementById(selectedTab);
                if (!content) return;

                content.classList.remove("hidden");
                this.classList.add("active-tab");
                localStorage.setItem("activeTab", selectedTab);
                const url = new URL(window.location);
                url.searchParams.set("tab", selectedTab);
                window.history.replaceState({}, '', url);
            });
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        const cfInput = document.querySelector('input[name="fiscal_code"]');
        if (cfInput) {
            cfInput.addEventListener('input', function () {
                this.value = this.value.toUpperCase();
            });
        }
    });

    function validateForm(form) {
        let isValid = true;
        const requiredFields = form.querySelectorAll('[required]');

        requiredFields.forEach(function(field) {
            if (!field.value.trim()) {
                field.classList.add('border-red-500');
                isValid = false;
            } else {
                field.classList.remove('border-red-500');
            }
        });

        const emailField = form.querySelector('input[type="email"]');
        if (emailField && emailField.value.trim() && !validateEmail(emailField.value)) {
            emailField.classList.add('border-red-500');
            isValid = false;
        } else if (emailField) {
            emailField.classList.remove('border-red-500');
        }

        const cfField = form.querySelector('input[name="fiscal_code"]');
        if (cfField && cfField.value.trim() && cfField.value.length !== 16) {
            cfField.classList.add('border-red-500');
            isValid = false;
        } else if (cfField) {
            cfField.classList.remove('border-red-500');
        }

        const phoneField = form.querySelector('input[name="phone"]');
        if (phoneField && phoneField.value.trim() && !validatePhoneNumber(phoneField.value)) {
            phoneField.classList.add('border-red-500');
            isValid = false;
        } else if (phoneField) {
            phoneField.classList.remove('border-red-500');
        }

        return isValid;
    }

    function validateEmail(email) {
        const emailPattern = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        return emailPattern.test(email);
    }

    function validatePhoneNumber(phone) {
        const phonePattern = /^[0-9\s\+\-\(\)]{7,15}$/;
        return phonePattern.test(phone);
    }

    document.querySelectorAll('form.needs-validation').forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!validateForm(form)) {
                event.preventDefault();
                alert('Per favore completa tutti i campi obbligatori correttamente.');
            }
        });
    });

    function openImageModal(imageUrl, fullName) {
        const imgElement = document.getElementById('preview-image');
        const nameElement = document.getElementById('preview-worker-name');

        imgElement.src = imageUrl;
        nameElement.textContent = fullName;

        const modal = tailwind.Modal.getOrCreateInstance(
            document.querySelector('#image-preview-modal')
        );
        modal.show();
    }

    if (document.getElementById('worker-search')) {
        new TomSelect('#worker-search', {
            valueField: 'id',
            labelField: 'text',
            searchField: 'text',
            maxItems: 1,
            loadThrottle: 300,
            load: function(query, callback) {
                if (query.length < 2) return callback();
                fetch('/users/search-workers?q=' + encodeURIComponent(query))
                    .then(r => r.json())
                    .then(callback)
                    .catch(() => callback());
            },
            onChange(value) {
                if (value) {
                    const option = this.options[value];
                    const uid = option ? (option.uid || '') : '';
                    const url = new URL('/users/' + value + '/edit', window.location.origin);
                    if (uid) url.searchParams.set('uid', uid);
                    window.location.href = url.toString();
                }
            }
        });
    }
