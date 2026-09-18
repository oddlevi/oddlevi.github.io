/* Bruksstatistikk for Rett Nord-sidene (F-216, Odd 18.09.2026). Ingen IP lagres, bare en daglig
   saltet hash på serveren (lopsdag/data.php?logg=). Hendelser: besok, seksjon, klikk, sok, hele, dybde, tid.
   Les tallene med: python3 veileder/rettnord_tall.py */
(function () {
  var SIDE = document.body.getAttribute('data-side') || 'rettnord';
  var URL = document.body.getAttribute('data-spor') || '/lopsdag/data.php?logg=';
  var start = Date.now(), sett = {}, dybde = 0;
  function send(hendelse, detalj) {
    try {
      var b = new Blob([JSON.stringify({lop: 'rettnord', side: SIDE, detalj: (detalj || '').slice(0, 80), ref: (document.referrer || '').slice(0, 120)})], {type: 'application/json'});
      navigator.sendBeacon(URL + hendelse, b);
    } catch (e) {}
  }
  send('besok');
  // seksjoner: første gang hver seksjon er 40 % synlig
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (x) {
        var id = x.target.id;
        if (x.isIntersecting && id && !sett[id]) { sett[id] = 1; send('seksjon', id); }
      });
    }, {threshold: 0.4});
    document.querySelectorAll('section[id], header[id], div.neste').forEach(function (s) { io.observe(s); });
  }
  // klikk på lenker ut, nedlastinger og knapper
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest && ev.target.closest('a[href], summary');
    if (!a) return;
    if (a.tagName === 'SUMMARY') {
      var t = (a.textContent || '').trim().slice(0, 40), h3 = a.closest('section') ? a.closest('section').id : '';
      send(a.closest('details') && a.closest('details').open ? 'lukk' : 'hele', h3 + ' ' + t);
      return;
    }
    var href = a.getAttribute('href') || '', hva = a.getAttribute('data-spor');
    if (!hva) {
      if (/racetracker/.test(href)) hva = 'pamelding';
      else if (/\.ics$/.test(href)) hva = 'kalender';
      else if (/strava/.test(href)) hva = 'strava';
      else if (/^mailto:/.test(href)) hva = 'epost';
      else if (/^tel:/.test(href)) hva = 'telefon';
      else if (/instagram/.test(href)) hva = 'instagram';
      else if (/resultater/.test(href) && !/^#/.test(href)) hva = 'til_resultater';
      else if (/treni\.no\/?$/.test(href)) hva = 'til_treni';
      else if (/eqtiming|raceresult/.test(href)) hva = 'tidtaker';
      else if (/^#/.test(href)) hva = 'anker ' + href.slice(1);
      else if (/^\.\.\//.test(href) || /^\.\//.test(href)) hva = 'nav ' + href;
      else return;
    }
    send('klikk', hva);
  }, true);
  // søk: siste søkeord når feltet forlates eller etter 1,5 s ro
  var sist = '', tm;
  document.querySelectorAll('input[type=search]').forEach(function (inn) {
    var logg = function () { var q = inn.value.trim(); if (q.length >= 2 && q !== sist) { sist = q; send('sok', q); } };
    inn.addEventListener('input', function () { clearTimeout(tm); tm = setTimeout(logg, 1500); });
    inn.addEventListener('blur', logg);
    inn.addEventListener('keydown', function (e) { if (e.key === 'Enter') logg(); });
  });
  if (location.hash.indexOf('#sok=') === 0) { send('sok', 'fra hovedsiden: ' + decodeURIComponent(location.hash.slice(5))); }
  // dybde og tid ved avslutning
  window.addEventListener('scroll', function () {
    var h = document.documentElement.scrollHeight - innerHeight;
    if (h > 0) dybde = Math.max(dybde, Math.min(100, Math.round((scrollY / h) * 100)));
  }, {passive: true});
  var ferdig = false;
  function slutt() {
    if (ferdig) return; ferdig = true;
    var h0 = document.documentElement.scrollHeight - innerHeight; if (h0 > 0) dybde = Math.max(dybde, Math.min(100, Math.round((scrollY / h0) * 100)));
    var d = dybde >= 90 ? '100' : dybde >= 75 ? '75' : dybde >= 50 ? '50' : dybde >= 25 ? '25' : '0';
    send('dybde', d);
    send('tid', String(Math.round((Date.now() - start) / 1000)));
  }
  window.addEventListener('pagehide', slutt);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') slutt(); });
})();
