/*
 * QR attestati operaio.
 *
 * Genera client-side (libreria qrcodejs) il QR dell'URL pubblico
 *   {ATTESTATO_URL}/attestati/{uid}
 * (la stessa pagina del tesserino: docs.csmontaggi.it/attestati/...).
 *
 * Uso nei template:
 *   - <div data-qr-render="UID"></div>          → QR renderizzato inline
 *   - <button data-qr-uid="UID" data-qr-name="Nome Cognome">  → download PNG
 *
 * Richiede window._attestatoUrl (settato in layout/base.html.twig) e
 * qrcode.min.js (cdnjs) caricato prima di questo file.
 */
(function () {
    'use strict';

    function attestatoUrl(uid) {
        var base = window._attestatoUrl || 'https://docs.csmontaggi.it';
        return base.replace(/\/+$/, '') + '/attestati/' + uid;
    }

    function makeCanvas(uid, size, cb) {
        var holder = document.createElement('div');
        holder.style.position = 'fixed';
        holder.style.left = '-9999px';
        document.body.appendChild(holder);
        /* global QRCode */
        new QRCode(holder, {
            text: attestatoUrl(uid),
            width: size,
            height: size,
            correctLevel: QRCode.CorrectLevel.M
        });
        // qrcodejs disegna il canvas in modo sincrono, ma diamo un tick
        // per sicurezza (l'<img> interno viene popolato async)
        setTimeout(function () {
            var canvas = holder.querySelector('canvas');
            cb(canvas);
            holder.remove();
        }, 60);
    }

    function download(uid, name) {
        if (typeof QRCode === 'undefined') {
            alert('Libreria QR non caricata. Ricarica la pagina.');
            return;
        }
        makeCanvas(uid, 512, function (canvas) {
            if (!canvas) { alert('Errore nella generazione del QR.'); return; }

            // quiet zone: margine bianco attorno, serve agli scanner
            var m = 40;
            var out = document.createElement('canvas');
            out.width  = canvas.width  + m * 2;
            out.height = canvas.height + m * 2;
            var ctx = out.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, out.width, out.height);
            ctx.drawImage(canvas, m, m);

            var slug = (name || uid).toLowerCase()
                .normalize('NFD').replace(/[̀-ͯ]/g, '')
                .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            var a = document.createElement('a');
            a.href = out.toDataURL('image/png');
            a.download = 'qr-attestati-' + slug + '.png';
            document.body.appendChild(a);
            a.click();
            a.remove();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // render inline (profilo operaio)
        if (typeof QRCode !== 'undefined') {
            document.querySelectorAll('[data-qr-render]').forEach(function (el) {
                new QRCode(el, {
                    text: attestatoUrl(el.getAttribute('data-qr-render')),
                    width: 120,
                    height: 120,
                    correctLevel: QRCode.CorrectLevel.M
                });
            });
        }
    });

    // download delegato: funziona anche sulle righe ricaricate via AJAX
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-qr-uid]');
        if (!btn) return;
        e.preventDefault();
        download(btn.getAttribute('data-qr-uid'), btn.getAttribute('data-qr-name') || '');
    });

    window.WorkerQR = { download: download, url: attestatoUrl };
})();
