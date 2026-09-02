<?php
// Løpegruppe-oversikten (Odds bestilling 02.09.2026): alle løpegrupper i
// Norge på ett sted — duplisert fra løpskalenderen (lop.php). Data i
// lopegrupper.json (kuratert via research; oppdateres ved tips/endringer).
// SEO-side med CTA mot testløper-skjemaet, som lop.php.
$grupper = [];
$fil = __DIR__ . '/lopegrupper.json';
if (is_readable($fil)) {
    $grupper = json_decode((string) file_get_contents($fil), true) ?: [];
}
$FYLKE_NN = ['Nordland' => true, 'Troms' => true, 'Finnmark' => true];
$per_fylke = [];
$fylker = [];
$typer = ['klubb' => 'Klubb', 'lavterskel' => 'Lavterskel', 'butikk' => 'Butikkgruppe'];
foreach ($grupper as $g) {
    $per_fylke[$g['fylke'] ?? 'Annet'][] = $g;
    $fylker[$g['fylke'] ?? 'Annet'] = true;
}
$fylker = array_keys($fylker);
sort($fylker);
ksort($per_fylke);
foreach ($per_fylke as &$liste) {
    usort($liste, fn($a, $b) => [$a['sted'], $a['navn']] <=> [$b['sted'], $b['navn']]);
}
unset($liste);
function e10(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="no">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Løpegrupper i Norge — finn en løpegruppe nær deg | Treni</title>
<meta name="description" content="Oversikt over løpegrupper og løpeklubber i hele Norge: organiserte klubber, lavterskelgrupper og butikkgrupper, med lenke til hver gruppe. Finn løpegruppa nær deg.">
<meta property="og:title" content="Løpegrupper i Norge — finn en løpegruppe nær deg">
<meta property="og:description" content="Løpeklubber, lavterskelgrupper og butikkgrupper i hele landet, samlet på ett sted.">
<meta property="og:type" content="website">
<meta property="og:url" content="https://treni.no/lopegrupper.php">
<link rel="canonical" href="https://treni.no/lopegrupper.php">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🏃</text></svg>">
<link rel="stylesheet" href="stil.css?v=19">
<style>
.gr-rad { display:flex; gap:1rem; align-items:center; padding:.7rem 0;
  border-bottom:1px solid hsl(var(--border)); flex-wrap:wrap; }
.gr-info { flex:1; min-width:14rem; }
.gr-knapp { display:inline-block; padding:.4rem 1.1rem; border-radius:999px;
  border:1.5px solid hsl(var(--primary)); color:hsl(var(--primary));
  font-weight:650; font-size:.9rem; text-decoration:none; white-space:nowrap; }
.gr-knapp:hover { background:hsl(var(--primary)); color:hsl(var(--primary-fg)); }
.gr-type { font-size:.72rem; font-weight:700; text-transform:uppercase;
  letter-spacing:.04em; padding:.1rem .55rem; border-radius:999px;
  border:1px solid hsl(var(--border)); color:hsl(var(--muted-fg)); }
.fylke-tittel { font-family:var(--font-serif); font-size:1.35rem; margin:1.8rem 0 .3rem; }
.cta-strip { border:2px solid hsl(var(--accent) / .5); border-radius:var(--radius);
  padding:1rem 1.2rem; margin:1.6rem 0; background:hsl(var(--accent) / .07); }
</style>
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<main>

<header class="hero smal">
  <p class="kicker reveal"><a href="index.html" style="color:inherit">treni.no</a> · løpegrupper</p>
  <h1 class="reveal" style="font-size:clamp(1.8rem,5vw,2.7rem)">Løpegrupper i Norge —<br>finn gruppa nær deg</h1>
  <p class="ingress reveal">Løpeklubber, lavterskelgrupper og butikkgrupper i hele landet,
  samlet på ett sted. Å løpe sammen med andre er den enkleste måten å holde treningen i
  gang på. Finn gruppa di, møt opp og bli med! 🏃</p>
</header>

<section>
<?php if (!$grupper): ?>
  <div class="kort"><p style="margin:0">Oversikten fylles i løpet av dagen — kom tilbake litt senere,
  eller <a href="mailto:hei@treni.no">send oss en e-post</a>.</p></div>
<?php else: ?>
  <div style="display:flex; gap:.6rem; margin:.2rem 0 1rem; flex-wrap:wrap">
    <button class="gr-filter aktiv" data-region="alle" style="padding:.45rem 1.2rem; border-radius:999px; border:1.5px solid hsl(var(--primary)); background:hsl(var(--primary)); color:hsl(var(--primary-fg)); font-weight:650; cursor:pointer">Hele Norge</button>
    <button class="gr-filter" data-region="nn" style="padding:.45rem 1.2rem; border-radius:999px; border:1.5px solid hsl(var(--border)); background:transparent; color:hsl(var(--fg)); font-weight:650; cursor:pointer">Nord-Norge</button>
    <select id="fylke-valg" style="padding:.45rem 1rem; border-radius:999px; border:1.5px solid hsl(var(--border));
        background:transparent; color:hsl(var(--fg)); font-weight:650; cursor:pointer; font:inherit">
      <option value="">Velg fylke …</option>
      <?php foreach ($fylker as $fy): ?>
      <option value="<?= e10($fy) ?>"><?= e10($fy) ?></option>
      <?php endforeach; ?>
    </select>
    <input id="gr-sok" type="search" placeholder="Søk sted eller navn …"
      style="padding:.45rem 1rem; border-radius:999px; border:1.5px solid hsl(var(--border));
      background:transparent; color:hsl(var(--fg)); font:inherit; min-width:12rem">
  </div>
  <div class="cta-strip">
    <b>🎯 Har du gruppa, men mangler planen?</b> Treni bygger treningsplanen din uke for uke —
    med en ekte trener på laget og skadefri fremgang som mål.
    <a href="bli-testloper.php"><b>Bli testløper →</b></a>
  </div>
  <?php foreach ($per_fylke as $fylke => $liste): ?>
  <h2 class="fylke-tittel"><?= e10($fylke) ?> <span class="liten" style="font-family:var(--font-sans)">· <?= count($liste) ?> grupper</span></h2>
  <?php foreach ($liste as $g): ?>
  <div class="gr-rad" data-nn="<?= isset($FYLKE_NN[$g['fylke'] ?? '']) ? 1 : 0 ?>"
       data-fylke="<?= e10($g['fylke'] ?? '') ?>"
       data-sok="<?= e10(mb_strtolower(($g['navn'] ?? '') . ' ' . ($g['sted'] ?? ''))) ?>">
    <div class="gr-info">
      <b><?= e10($g['navn']) ?></b>
      <span class="gr-type"><?= e10($typer[$g['type'] ?? ''] ?? 'Gruppe') ?></span>
      <br><span class="liten">📍 <?= e10($g['sted']) ?><?= !empty($g['om']) ? ' · ' . e10($g['om']) : '' ?></span>
    </div>
    <?php if (!empty($g['strava'])): ?>
      <a class="liten" href="<?= e10($g['strava']) ?>" rel="noopener nofollow" target="_blank" style="white-space:nowrap">Strava →</a>
    <?php endif; ?>
    <?php if (!empty($g['lenke'])): ?>
      <a class="gr-knapp" href="<?= e10($g['lenke']) ?>" rel="noopener nofollow" target="_blank">Til gruppa →</a>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endforeach; ?>
  <div class="cta-strip" style="margin-top:2rem">
    <b>🏃 Vil du ha en plan å ta med til gruppetreningen?</b> Treni leser treningen din fra
    Strava og gir deg ukeplan og oppfølging — trenerledet, skadefritt først.
    <a href="bli-testloper.php"><b>Sett meg på ventelista →</b></a>
  </div>
  <p class="liten" style="margin-top:1.4rem">Oversikten er samlet fra klubbenes og gruppenes
  egne sider og oppdateres jevnlig. Mangler gruppa di, eller stemmer ikke noe?
  <a href="mailto:hei@treni.no?subject=L%C3%B8pegruppe%20som%20mangler">Tips oss</a>,
  så retter vi det. Treni er ikke tilknyttet gruppene — oppmøte og medlemskap skjer hos hver enkelt.</p>
<?php endif; ?>
<script>
document.querySelectorAll('.gr-filter').forEach(function (kn) {
  kn.addEventListener('click', function () {
    var fv = document.getElementById('fylke-valg');
    if (fv) { fv.value = ''; }
    settFilter(kn.dataset.region, '', sokeord());
  });
});
var fylkeVelger = document.getElementById('fylke-valg');
if (fylkeVelger) {
  fylkeVelger.addEventListener('change', function () {
    settFilter(fylkeVelger.value ? 'alle' : aktivRegion(), fylkeVelger.value, sokeord());
  });
}
var sok = document.getElementById('gr-sok');
if (sok) {
  sok.addEventListener('input', function () {
    settFilter(aktivRegion(), fylkeVelger ? fylkeVelger.value : '', sokeord());
  });
}
function sokeord() { return sok ? sok.value.trim().toLowerCase() : ''; }
function aktivRegion() {
  var a = document.querySelector('.gr-filter.aktiv');
  return a ? a.dataset.region : 'alle';
}
function settFilter(region, fylke, ord) {
  document.querySelectorAll('.gr-filter').forEach(function (x) {
    var aktiv = x.dataset.region === region;
    x.classList.toggle('aktiv', aktiv);
    x.style.background = aktiv ? 'hsl(var(--primary))' : 'transparent';
    x.style.color = aktiv ? 'hsl(var(--primary-fg))' : 'hsl(var(--fg))';
    x.style.borderColor = aktiv ? 'hsl(var(--primary))' : 'hsl(var(--border))';
  });
  var nn = region === 'nn';
  document.querySelectorAll('.gr-rad').forEach(function (r) {
    var ok = (!nn || r.dataset.nn === '1') && (!fylke || r.dataset.fylke === fylke)
          && (!ord || r.dataset.sok.indexOf(ord) !== -1);
    r.style.display = ok ? '' : 'none';
  });
  document.querySelectorAll('.fylke-tittel').forEach(function (m) {
    var e = m.nextElementSibling, synlig = false;
    while (e && e.classList.contains('gr-rad')) {
      if (e.style.display !== 'none') { synlig = true; break; }
      e = e.nextElementSibling;
    }
    m.style.display = synlig ? '' : 'none';
  });
}
</script>
</section>

<section aria-labelledby="om-gr-t" style="max-width:46rem">
  <h2 id="om-gr-t" style="font-size:1.3rem">Om løpegruppe-oversikten</h2>
  <p class="liten">Oversikten samler <b>løpeklubber, lavterskelgrupper og butikkdrevne
  løpegrupper i hele Norge</b>, med eget filter for Nord-Norge og fylkesvalg. Gruppene
  er hentet fra klubbenes egne sider, Kondis, friidrettskretsene og gruppenes åpne
  Facebook-sider.</p>
  <h3 style="font-size:1.05rem; margin:1.1rem 0 .3rem">Hva er forskjellen på typene?</h3>
  <p class="liten"><b>Klubb</b> er organiserte idrettslag med faste treninger og medlemskap.
  <b>Lavterskel</b> er åpne grupper der du bare møter opp, ofte gratis. <b>Butikkgruppe</b>
  er faste fellesøkter i regi av en sportsbutikk, vanligvis åpne for alle.</p>
  <h3 style="font-size:1.05rem; margin:1.1rem 0 .3rem">Hvordan kommer jeg i gang i en løpegruppe?</h3>
  <p class="liten">Sjekk gruppas side for treningstider, møt opp og si hei. De aller fleste
  grupper har flere temponivåer, og ingen forventer at du er rask. Vil du ha en plan for
  treningen rundt fellesøktene, er det akkurat det <a href="index.html">Treni</a> gjør:
  en trenerledet treningsveileder som leser treningen din fra Strava.
  <a href="bli-testloper.php">Bli testløper →</a></p>
  <h3 style="font-size:1.05rem; margin:1.1rem 0 .3rem">Mangler gruppa di?</h3>
  <p class="liten"><a href="mailto:hei@treni.no?subject=L%C3%B8pegruppe%20som%20mangler">Send
  oss en e-post</a> med navn og lenke, så legger vi den inn.</p>
</section>

<script type="application/ld+json">
{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[
 {"@type":"Question","name":"Hvordan finner jeg en løpegruppe nær meg?",
  "acceptedAnswer":{"@type":"Answer","text":"Bruk fylkesfilteret eller søkefeltet i oversikten. Hver gruppe har lenke til sin egen side med treningstider og oppmøtested."}},
 {"@type":"Question","name":"Koster det noe å være med i en løpegruppe?",
  "acceptedAnswer":{"@type":"Answer","text":"Lavterskelgrupper og butikkgrupper er som regel gratis og åpne for alle. Klubber har vanligvis et medlemskap, men de fleste lar deg prøve noen treninger først."}},
 {"@type":"Question","name":"Må jeg være rask for å bli med?",
  "acceptedAnswer":{"@type":"Answer","text":"Nei. De aller fleste løpegrupper har flere temponivåer, og lavterskelgruppene er laget nettopp for at alle skal kunne være med."}}]}
</script>

<footer>
  <nav aria-label="Bunnmeny">
    <a href="index.html">Forsiden</a>
    <a href="lop.php">Løpskalenderen</a>
    <a href="min-side.html">Min side</a>
    <a href="stotte.html">Støtte og kontakt</a>
    <a href="personvern.html">Personvern</a>
  </nav>
  <p>PAULSEN UTVIKLING · org.nr 938 158 614 · Norge ·
     <a href="mailto:hei@treni.no">hei@treni.no</a></p>
  <p>Powered by Strava — this service is not affiliated with or endorsed by Strava.</p>
</footer>

</main>
</body>
</html>
