/**
 * Attività tab – edit modal population + lightbox for row/modal photos.
 */
(function () {
    'use strict';

    /* ─── Lightbox state ─────────────────────────────────────────────────── */
    var lbPhotos = [];
    var lbIndex  = 0;

    var lightbox = document.getElementById('attivita-lightbox');
    var lbImg    = document.getElementById('att-lb-img');
    var lbCap    = document.getElementById('att-lb-caption');
    var lbClose  = document.getElementById('att-lb-close');
    var lbPrev   = document.getElementById('att-lb-prev');
    var lbNext   = document.getElementById('att-lb-next');

    function openLightbox(photos, idx) {
        if (!lightbox || !photos.length) return;
        lbPhotos = photos;
        lbIndex  = Math.max(0, Math.min(idx, photos.length - 1));
        showFrame();
        lightbox.classList.add('active');
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        if (!lightbox) return;
        lightbox.classList.remove('active');
        lightbox.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function showFrame() {
        var p = lbPhotos[lbIndex];
        if (!p) return;
        if (lbImg) { lbImg.src = p.serve_url; lbImg.alt = p.file_name; }
        if (lbCap) {
            lbCap.textContent = p.categoria + '  ·  ' + p.file_name +
                '  (' + (lbIndex + 1) + '/' + lbPhotos.length + ')';
        }
        if (lbPrev) lbPrev.style.visibility = lbIndex > 0 ? '' : 'hidden';
        if (lbNext) lbNext.style.visibility = lbIndex < lbPhotos.length - 1 ? '' : 'hidden';
    }

    if (lbClose) lbClose.addEventListener('click', closeLightbox);
    if (lbPrev)  lbPrev.addEventListener('click',  function () { if (lbIndex > 0) { lbIndex--; showFrame(); } });
    if (lbNext)  lbNext.addEventListener('click',  function () { if (lbIndex < lbPhotos.length - 1) { lbIndex++; showFrame(); } });
    if (lightbox) lightbox.addEventListener('click', function (e) { if (e.target === lightbox) closeLightbox(); });

    document.addEventListener('keydown', function (e) {
        if (!lightbox || !lightbox.classList.contains('active')) return;
        if (e.key === 'Escape')     closeLightbox();
        if (e.key === 'ArrowLeft')  { if (lbIndex > 0) { lbIndex--; showFrame(); } }
        if (e.key === 'ArrowRight') { if (lbIndex < lbPhotos.length - 1) { lbIndex++; showFrame(); } }
    });

    /* ─── Photo grid builder ─────────────────────────────────────────────── */
    function buildGrid(el, photos) {
        el.innerHTML = '';
        if (!photos.length) return;
        photos.forEach(function (p, idx) {
            var item = document.createElement('div');
            item.className = 'att-photo-item';

            var img = document.createElement('img');
            img.src = p.serve_url;
            img.alt = p.file_name;
            img.loading = 'lazy';
            item.appendChild(img);

            item.addEventListener('click', function () { openLightbox(photos, idx); });
            el.appendChild(item);
        });
    }

    /* ─── Populate three photo grids when opening edit modal ─────────────── */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.edit-attivita-btn');
        if (!btn) return;

        var d = btn.dataset;

        // Text fields
        document.getElementById('attivita-id').value           = d.id           || '';
        document.getElementById('attivita-data').value         = d.data         || '';
        document.getElementById('attivita-attivita').value     = d.attivita     || '';
        document.getElementById('attivita-persone').value      = d.persone      || '';
        document.getElementById('attivita-tempo').value        = d.tempo        || '';
        document.getElementById('attivita-quantita').value     = d.quantita     || '';
        document.getElementById('attivita-giuomo').value       = d.giuomo       || '';
        document.getElementById('attivita-attrezzature').value = d.attrezzature || '';
        document.getElementById('attivita-problemi').value     = d.problemi     || '';
        document.getElementById('attivita-soluzioni').value    = d.soluzioni    || '';
        document.getElementById('attivita-note').value         = d.note         || '';

        var titleEl = document.getElementById('attivita-modal-title');
        if (titleEl) titleEl.textContent = 'Modifica Attività';

        // Photo grids — split by categoria
        var allPhotos = [];
        try { allPhotos = JSON.parse(d.photos || '[]'); } catch (_) {}

        ['problemi', 'soluzioni', 'info'].forEach(function (cat) {
            var grid = document.getElementById('att-grid-' + cat);
            if (!grid) return;
            buildGrid(grid, allPhotos.filter(function (p) { return p.categoria === cat; }));
        });
    });

    /* ─── Lightbox from table-row photo button ───────────────────────────── */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.open-att-lightbox');
        if (!btn) return;
        var photos = [];
        try { photos = JSON.parse(btn.dataset.photos || '[]'); } catch (_) {}
        openLightbox(photos, 0);
    });

    /* ─── Reset modal on close ───────────────────────────────────────────── */
    var modal = document.getElementById('attivita-modal');
    if (modal) {
        modal.addEventListener('hide.tw.modal', function () {
            var form = modal.querySelector('form');
            if (form) form.reset();
            document.getElementById('attivita-id').value = '';
            var titleEl = document.getElementById('attivita-modal-title');
            if (titleEl) titleEl.textContent = 'Aggiungi / Modifica Attività';
            // Clear photo grids
            ['problemi', 'soluzioni', 'info'].forEach(function (cat) {
                var g = document.getElementById('att-grid-' + cat);
                if (g) g.innerHTML = '';
            });
        });
    }

}());
