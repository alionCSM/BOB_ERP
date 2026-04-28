self.addEventListener('push', function (event) {
    let data = {};

    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data = {
                title: 'Nuova notifica',
                body: event.data.text()
            };
        }
    }

    const title = data.title || 'BOB';
    const options = {
        body: data.body || 'Hai una nuova notifica.',
        icon: '/assets/img/logo.png',
        badge: '/assets/img/logo.png',
        data: {
            link: data.link || '/dashboard.php'
        }
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    const link = (event.notification.data && event.notification.data.link)
        ? event.notification.data.link
        : '/dashboard.php';

    event.waitUntil(clients.openWindow(link));
});
