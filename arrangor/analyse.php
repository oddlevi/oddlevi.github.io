<?php
declare(strict_types=1);
// Odd 13.09: mobilnummer-porten på /direkte/ må være passert før stegene vises
if (empty($_COOKIE["td_port"]) && $_SERVER["REQUEST_METHOD"] !== "POST" && !isset($_GET["apen"])) { header("Location: /direkte/#port"); exit; }
// F-56 (Odd 13.09): eksempelanalysen på egen side. Navn og plassering utelatt (F-57) til løperen sier ja.
?><!DOCTYPE html>
<html lang="nb">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Analysen etter mål · Treni Direkte</title>
<meta name="description" content="Slik så analysen ut for én av Trenis løpere etter Skjervøy 20:1000, sendt 50 minutter etter mål.">
<meta property="og:title" content="Analysen etter mål · Treni">
<meta property="og:image" content="https://treni.no/arrangor/og.jpg?v=3">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="stylesheet" href="/stil.css?v=30">
<style>
  .ark { max-width: 40rem; margin: 0 auto; padding: clamp(1.2rem, 4vw, 2.2rem); background: hsl(var(--surface)); border: 1px solid hsl(var(--border)); border-radius: var(--radius); box-shadow: var(--shadow-md); }
  .analyse .boble { margin-top: .2rem; background: hsl(var(--surface-2)); border: 1px solid hsl(var(--border)); border-radius: 16px 16px 16px 4px; padding: .9rem 1rem; font-size: .95rem; line-height: 1.5; }
  .analyse .meta { margin-top: .6rem; font-size: .78rem; color: hsl(var(--muted-fg)); }
  .analyse .boble p { margin: 0 0 .7rem; }
  .analyse .a-topp { font-weight: 700; }
  .analyse .a-mellom { margin: 1rem 0 .4rem; font-size: .78rem; letter-spacing: .08em; text-transform: uppercase; color: hsl(var(--muted-fg)); font-weight: 700; }
  .analyse .a-tab { width: 100%; border-collapse: collapse; font-size: .92rem; font-variant-numeric: tabular-nums; }
  .analyse .a-tab th { text-align: left; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: hsl(var(--muted-fg)); padding: .2rem .4rem .35rem 0; border-bottom: 1px solid hsl(var(--border)); }
  .analyse .a-tab td { padding: .38rem .4rem .38rem 0; border-bottom: 1px solid hsl(var(--border)); }
  .analyse .a-tab td small { color: hsl(var(--muted-fg)); }
  .analyse .a-tab td.god { color: hsl(var(--primary)); font-weight: 600; }
  .analyse ol { margin: 0 0 .7rem 1.2rem; padding: 0; } .analyse li { margin-bottom: .45rem; }

  .hvorfor { margin-top: 1.4rem; font-size: .95rem; line-height: 1.55; }
  .hvorfor b { display: block; margin-bottom: .3rem; }
  .knapp { display: inline-block; margin-top: 1.4rem; padding: .9rem 1.4rem; border-radius: 999px; background: var(--gradient-cta); color: hsl(var(--primary-fg)); font-weight: 700; text-decoration: none; box-shadow: var(--shadow-cta); }
</style>
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<main>
<header class="hero smal" style="padding-bottom:.5rem">
  <p class="kicker"><a href="/arrangor/#steg5" style="color:inherit">‹ Tilbake til stegene</a></p>
  <h1 style="font-size:clamp(1.6rem,5vw,2.4rem)">Analysen én av løperne våre fikk, 50 minutter etter mål</h1>
  <p style="font-size:1.05rem;max-width:38rem;margin:.5rem 0 0">Skjervøy 20:1000, 12. september 2026. Skjemaet ble laget dagen før ut fra løperens beste år. Analysen kom automatisk fra tidtakingen, sett over av treneren, og landet i chatten på løperens egen side.</p>
</header>
<section>
  <div class="ark">
    <div class="analyse"><div class="boble">
      <p class="a-topp">🏁 Skjervøy 20:1000 i dag</p>
      <p><b>1:40:10.</b> Du lå i tetgruppa hele veien til Lailafjellet, og åpningen var nøyaktig slik vi snakket om: 11:09 ved Stussnes, fem sekunder foran ditt beste år, ikke ett sekund for hardt.</p>
      <p class="a-mellom">Post for post mot 2024-løpet ditt (1:36:02)</p>
      <table class="a-tab"><thead><tr><th>Post</th><th>Tid</th><th>Mot 2024</th></tr></thead><tbody>
        <tr><td>Stussnes <small>2,2 km</small></td><td>11:09</td><td class="god">5 s foran</td></tr>
        <tr><td>Skattørfjellet <small>5,7 km</small></td><td>29:31</td><td class="god">7 s foran</td></tr>
        <tr><td>Lailafjellet <small>10,7 km</small></td><td>55:57</td><td>1:58 bak</td></tr>
        <tr><td>Trollet <small>16,3 km</small></td><td>1:25:15</td><td>3:25 bak</td></tr>
        <tr><td>SIK-huset <small>18,4 km</small></td><td>1:35:38</td><td>4:06 bak</td></tr>
        <tr><td><b>Mål</b> <small>19,7 km</small></td><td><b>1:40:10</b></td><td>4:08 bak</td></tr>
      </tbody></table>
      <p class="a-mellom">Der løpet ble avgjort</p>
      <p>Skjemaet var 1:38. De tre som lå ved siden av deg på Lailafjellet endte på 1:38:10 til 1:39:08, så det er dem det er naturlig å måle mot. Forskjellen ligger på én strekning:</p>
      <ol>
        <li><b>Lailafjellet til Trollet.</b> Du brukte 29:18, de brukte 28:00 til 29:00, og du selv brukte 27:50 i 2024. Der ligger ett til halvannet minutt.</li>
        <li><b>Nedløpet og oppløpet</b> var som i 2024, på sekundet. Beina var gode nedover, som ventet etter 88 % rolig tid inn mot løpet.</li>
      </ol>
      <p class="a-mellom">Hva det betyr</p>
      <p>Formen holdt hele veien. Det som skiller 1:40 fra 1:38 i dag var fjellstrekningen fra Lailafjellet, ikke åpningen og ikke beina. Det er den mest trenbare biten av alle, og den ligger inne i høstplanen din med to uker på 82 og 96 km i terreng før VM-uka.</p>
      <div class="meta">Treni · 12.09 kl. 15:00 · navn og plassering er tatt ut av hensyn til løperen</div>
    </div></div>
    <div class="hvorfor">
      <b>Hva løperen sitter igjen med</b>
      Ikke bare en sluttid, men hvor i løypa tiden ble vunnet og tapt, målt mot eget beste år og mot dem han faktisk løp sammen med. Og ett konkret råd som alt ligger i planen hans videre.
    </div>
    <a class="knapp" href="/arrangor/#steg6">Neste steg: hva vi trenger fra deg →</a>
  </div>
</section>
</main>
</body>
</html>
