/* Treni-banner nederst på alle sider (Odd 12.09.2026), ikke på skjemasidene for registrering. */
(function () {
  if (document.querySelector('.treni-banner, .banner')) { return; }
  var en = /^\/en\//.test(location.pathname) || (document.documentElement.lang || '').toLowerCase().indexOf('en') === 0;
  var kilde = (location.pathname.replace(/^\/|\/$/g, '') || 'forside').replace(/[^a-z0-9_-]/gi, '_');
  var a = document.createElement('a');
  a.className = 'treni-banner';
  a.href = (en ? '/en/join.php' : '/bli-testloper.php') + '?kilde=' + encodeURIComponent(kilde);
  a.target = '_blank'; a.rel = 'noopener';
  a.innerHTML = '<span class="tb-venstre"><span class="tb-merke">Treni<b>.</b></span><span class="tb-tekst">'
    + (en ? 'The coach who sees every session. A running plan every week, a real coach behind it.' : 'Treneren som ser hver økt. Løpeplan hver uke, ekte trener bak.')
    + '</span></span><span class="tb-cta">' + (en ? 'Try Treni for free →' : 'Prøv Treni gratis →') + '</span>';
  var st = document.createElement('style');
  st.textContent = '.treni-banner{position:sticky;bottom:0;z-index:50;display:flex;align-items:center;gap:.8rem;margin:.5rem auto 0;max-width:1100px;background:linear-gradient(120deg,hsl(160 28% 9%),hsl(152 62% 20%));color:#fff;text-decoration:none;padding:.65rem 1rem;border-radius:14px 14px 0 0;box-shadow:0 -6px 24px rgba(0,0,0,.25);font-family:inherit}'
    + '.treni-banner .tb-venstre{display:flex;flex-direction:column;gap:.1rem;min-width:0;flex:1}.treni-banner .tb-merke{font-weight:800;font-size:1.2rem}.treni-banner .tb-merke b{color:hsl(84 80% 60%)}.treni-banner .tb-tekst{font-size:.8rem;line-height:1.25;opacity:.92}'
    + '.treni-banner .tb-cta{flex:0 0 auto;background:hsl(84 80% 60%);color:hsl(160 28% 9%);font-weight:800;font-size:1rem;padding:.75rem 1.1rem;border-radius:99px;white-space:nowrap;box-shadow:0 4px 14px rgba(0,0,0,.25)}'
    + '@media (max-width:420px){.treni-banner .tb-tekst{font-size:.74rem}.treni-banner .tb-cta{font-size:.92rem;padding:.7rem .9rem}}';
  document.head.appendChild(st);
  document.body.appendChild(a);
})();
