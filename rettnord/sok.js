(function () {
  // Levende søk på hovedsiden (Odd 18.09). Samme oppskrift som resultatsiden, data fra resultater/sok.json.
  var former = document.querySelectorAll('form.sok');
  if (!former.length) { return; }
  var data = null, liste = null, laster = null;
  function nk(s) { return (s || '').toLowerCase().normalize('NFKC').replace(/[^a-zæøå ]/g, '').replace(/\s+/g, ' ').trim(); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); }
  function hent() {
    if (laster) { return laster; }
    laster = fetch('resultater/sok.json', { cache: 'no-cache' }).then(function (r) { return r.json(); }).then(function (d) {
      data = d;
      var personer = {};
      d.rader.forEach(function (r) {
        var k = nk(r[3]); k = d.alias[k] || k;
        var p = personer[k] || (personer[k] = { navn: r[3], klubb: r[4], nk: k, klubbnk: '', rader: [] });
        if (r[4]) { p.klubb = r[4]; }
        p.klubbnk += ' ' + nk(r[4]);
        p.rader.push(r);
      });
      liste = Object.keys(personer).map(function (k) { return personer[k]; });
      liste.sort(function (a, b) { return a.navn.localeCompare(b.navn, 'nb'); });
      return liste;
    });
    return laster;
  }
  former.forEach(function (form) {
    var inn = form.querySelector('input[type=search]');
    if (!inn) { return; }
    var st = document.createElement('p'); st.className = 'status'; st.setAttribute('aria-live', 'polite');
    var ut = document.createElement('div'); ut.className = 'treff';
    form.appendChild(st); form.appendChild(ut);
    function vis() {
      var q = nk(inn.value);
      ut.innerHTML = '';
      if (q.length < 2) { st.textContent = q.length ? 'Skriv minst to bokstaver.' : ''; return; }
      if (!liste) { st.textContent = 'Henter resultatene …'; hent().then(vis); return; }
      var treff = liste.filter(function (p) { return p.nk.indexOf(q) !== -1 || p.klubbnk.indexOf(q) !== -1; });
      st.textContent = treff.length ? (treff.length + ' treff' + (treff.length > 8 ? ', viser de 8 første' : '')) : 'Ingen treff. Prøv en annen stavemåte.';
      treff.slice(0, 8).forEach(function (p) {
        var rader = p.rader.slice().sort(function (a, b) { return b[0].localeCompare(a[0]); }).map(function (r) {
          return '<tr><td>' + r[0] + '</td><td>' + esc(r[1]) + '</td><td>' + r[2] + '. plass</td><td>' + esc(r[5]) + '</td></tr>';
        }).join('');
        ut.insertAdjacentHTML('beforeend', '<div class="person"><b>' + esc(p.navn) + '</b>' + (p.klubb ? ' <span class="muted">' + esc(p.klubb) + '</span>' : '') +
          ' <span class="muted">· ' + p.rader.length + (p.rader.length === 1 ? ' fullført løp' : ' fullførte løp') + '</span><table><tbody>' + rader + '</tbody></table></div>');
      });
      if (treff.length > 8) {
        ut.insertAdjacentHTML('beforeend', '<p><a class="btn" href="resultater/#sok=' + encodeURIComponent(inn.value.trim()) + '">Alle ' + treff.length + ' treffene på resultatsiden</a></p>');
      }
      if (window.rnSpor) { try { window.rnSpor('sok', { detalj: q, ref: String(treff.length) }); } catch (e) {} }
    }
    var t;
    inn.addEventListener('input', function () { clearTimeout(t); t = setTimeout(vis, 120); });
    inn.addEventListener('focus', function () { hent(); }, { once: true });
  });
})();
