/* Besøk på treni.no – telles i Trenis egen database (app.treni.no/api/v1/besok).
   Ingen informasjonskapsler, ingen IP, ingen ID som følger personen. Respekterer «ikke spor meg».
   Klikk videre telles på [data-treni-klikk], lenker til butikkene (/g/?til=), app.treni.no og e-post. */
(function () {
  if (navigator.doNotTrack === '1') return;
  var URL_ = 'https://app.treni.no/api/v1/besok';
  var q = new URLSearchParams(location.search);
  function send(d) {
    var b = JSON.stringify(Object.assign({ side: location.pathname, henviser: document.referrer || null,
      utm_source: q.get('utm_source'), utm_campaign: q.get('utm_campaign'), bredde: window.innerWidth,
      sprak: navigator.language }, d));
    try {
      fetch(URL_, { method: 'POST', body: b, headers: { 'Content-Type': 'application/json' }, keepalive: true, mode: 'cors' }).catch(function () {});
    } catch (e) {}
  }
  function maal(a) {
    if (a.getAttribute('data-treni-klikk')) return a.getAttribute('data-treni-klikk');
    var h = a.getAttribute('href') || '';
    var m = h.match(/\/g\/\?[^#]*til=(ios|android)/);
    if (m) return m[1] === 'ios' ? 'appstore' : 'play';
    if (h.indexOf('app.treni.no') !== -1) return 'app';
    if (h.indexOf('mailto:') === 0) return 'epost';
    return null;
  }
  send({ type: 'side' });
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a, [data-treni-klikk]');
    if (!a) return;
    var t = maal(a);
    if (t) send({ type: 'klikk', maal: t });
  });
})();
