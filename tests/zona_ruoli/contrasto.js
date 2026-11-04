// Controllo del contrasto della Zone: si carica nella pagina e si lancia
// __audit(). Per ogni sezione elenca testi poco leggibili (contrasto < 3),
// sfondi e bordi scuri rimasti nel tema chiaro.
window.__lum = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(',').map(Number); if (p.length > 3 && p[3] === 0) return null; const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(p[0]) + 0.7152 * f(p[1]) + 0.0722 * f(p[2]); };
window.__scan = (root) => {
  const lum = __lum, chiaro = document.documentElement.getAttribute('data-theme') === 'light';
  const bgOf = (e) => { while (e) { const l = lum(getComputedStyle(e).backgroundColor); if (l !== null) return l; e = e.parentElement; } return chiaro ? 1 : 0; };
  const probl = [];
  for (const e of (root || document.body).querySelectorAll('*')) {
    const r = e.getBoundingClientRect(); if (!r.width || !r.height) continue;
    const c = getComputedStyle(e); if (c.visibility === 'hidden' || c.display === 'none' || e.closest('.hidden')) continue;
    const nome = (e.id ? '#' + e.id : '') + (typeof e.className === 'string' && e.className ? '.' + e.className.trim().split(/\s+/).join('.') : '') || e.tagName.toLowerCase();
    if (chiaro) {
      const bl = lum(c.backgroundColor), sotto = bgOf(e.parentElement);
      if (bl !== null && bl < 0.05 && sotto > 0.6 && !/primary|badge|send|acc-aggiungi|invia/.test(nome)) probl.push('sfondo scuro: ' + nome + ' ' + c.backgroundColor);
      ['Top', 'Left'].forEach(s => { if (c['border' + s + 'Style'] !== 'none' && parseFloat(c['border' + s + 'Width']) > 0) { const l = lum(c['border' + s + 'Color']); if (l !== null && l < 0.04 && bgOf(e) > 0.6) probl.push('bordo scuro: ' + nome + ' ' + c['border' + s + 'Color']); } });
    }
    if ([...e.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) {
      const tl = lum(c.color), fondo = bgOf(e);
      if (tl !== null) { const cr = (Math.max(tl, fondo) + 0.05) / (Math.min(tl, fondo) + 0.05); if (cr < 3) probl.push('testo (' + cr.toFixed(1) + '): ' + nome + ' "' + e.textContent.trim().slice(0, 30) + '" ' + c.color); }
    }
  }
  return [...new Set(probl)];
};
window.__audit = async () => {
  const ris = {}, attesa = ms => new Promise(r => setTimeout(r, ms));
  for (const v of [...document.querySelectorAll('.bz-nav-item[data-view]')]) { v.click(); await attesa(900); ris[v.dataset.view] = __scan(); }
  document.querySelector('.bz-nav-item[data-view=attivita]')?.click(); await attesa(600);
  const card = document.querySelector('.bz-card'); if (card) { card.click(); await attesa(900); ris['dettaglio attivita'] = __scan(document.getElementById('bz-detail-wrap')); }
  for (const [nome, bottone, modale] of [['nuova attivita', '#btn-new-task', '#task-modal'], ['chi accede', '#btn-accessi', '#accessi-modal']]) {
    const b = document.querySelector(bottone); if (!b) continue; b.click(); await attesa(1200);
    ris[nome] = __scan(document.querySelector(modale)); document.querySelector(modale)?.classList.add('hidden');
  }
  return ris;
};
