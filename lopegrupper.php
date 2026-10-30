<?php
// Løpegruppe-oversikten (Odds bestilling 02.09.2026): alle løpegrupper i
// Norge på ett sted — duplisert fra løpskalenderen (lop.php). Data i
// lopegrupper.json (kuratert via research; oppdateres ved tips/endringer).
// SEO-side. Odd 30.10.2026: samme profil og design som forsida (ny/side.css),
// tekstene etter budskapet for Treni, knappene til butikkene via /g med k=lopegrupper.
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
// Lenkene til gruppene går via mellomsiden videre.php (Odd 30.10.2026, som løpskalenderen):
// «skal Treni lage uka di rundt fellesøktene?» før gruppas side. HMAC-signert mål-URL hindrer åpen redirect.
$konfig = [];
$cfg_sti = dirname(__DIR__) . "/dashbord_config.php";
if (is_readable($cfg_sti)) {
    $k10 = include $cfg_sti;
    if (is_array($k10)) { $konfig = $k10; }
}
$via10 = function (string $url, array $g) use ($konfig): string {
    $nokkel = (string) ($konfig['videre_secret'] ?? $konfig['db_pass'] ?? '');
    if ($nokkel === '') { return $url; }   // uten nøkkel: rett til gruppa, som før
    return 'videre.php?type=gruppe&til=' . urlencode($url) . '&s=' . substr(hash_hmac('sha256', $url, $nokkel), 0, 16)
         . '&gruppe=' . urlencode($g['navn'] ?? '') . '&sted=' . urlencode($g['sted'] ?? '');
};
// Knappene til butikkene, som på forsida. Klikket logges i treni.no/g med kanalen.
function butikkknapper10(string $k): string {
    return '<div class="knapper">'
        . '<a class="k hoved" href="/g/?til=android&amp;k=' . e10($k) . '" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M5 3.5v17l9-8.5-9-8.5z"/><path d="M14 12l3.5-3.3L20 10.2v3.6l-2.5 1.5z"/></svg><span><small>Last ned på</small>Google Play</span></a>'
        . '<a class="k kant" href="/g/?til=ios&amp;k=' . e10($k) . '" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.4 12.6c0-2.4 2-3.6 2.1-3.7-1.1-1.7-2.9-1.9-3.5-1.9-1.5-.2-2.9.9-3.7.9-.8 0-1.9-.9-3.2-.8-1.6 0-3.1 1-4 2.4-1.7 3-.4 7.3 1.2 9.7.8 1.2 1.8 2.5 3 2.4 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.2.8 1.3 0 2.2-1.2 3-2.4.9-1.4 1.3-2.7 1.3-2.8-.1 0-2.5-1-2.5-3.8zM14 5.4c.7-.8 1.1-1.9 1-3-1 0-2.1.7-2.8 1.5-.6.7-1.2 1.8-1 2.9 1 .1 2.1-.6 2.8-1.4z"/></svg><span><small>Last ned i</small>App Store</span></a>'
        . '</div>';
}
?>
<!doctype html>
<html lang="nb">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Løpegrupper i Norge · finn en løpegruppe nær deg | Treni</title>
<meta name="description" content="Oversikt over løpegrupper og løpeklubber i hele Norge: organiserte klubber, lavterskelgrupper og butikkgrupper, med lenke til hver gruppe. Finn løpegruppa nær deg.">
<meta property="og:title" content="Løpegrupper i Norge · finn en løpegruppe nær deg">
<meta property="og:description" content="Løpeklubber, lavterskelgrupper og butikkgrupper i hele landet, samlet på ett sted.">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Treni">
<meta property="og:image" content="https://treni.no/ny/bilder/deling.jpg">
<meta property="og:url" content="https://treni.no/lopegrupper.php">
<meta property="og:locale" content="nb_NO">
<link rel="canonical" href="https://treni.no/lopegrupper.php">
<link rel="icon" href="/favicon.ico">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;800&display=swap">
<link rel="stylesheet" href="/ny/side.css?v=2">
</head>
<body>

<header><div class="c topp">
  <a class="logo" href="/" aria-label="Treni">Tren<i aria-hidden="true">ı</i></a>
  <nav class="meny" aria-label="Hovedmeny"><a href="/lop.php">Løpskalender</a><a href="/lopegrupper.php" aria-current="page">Løpegrupper</a><a class="liten" href="#last">Last ned</a></nav>
</div></header>

<main>
<section class="c sidehode">
  <span class="etikett">Løpegrupper</span>
  <h1>Finn gruppa <em>nær deg.</em></h1>
  <p class="lead">Løpeklubber, lavterskelgrupper og butikkgrupper i hele Norge, samlet på ett sted.
  Å løpe sammen med andre er den enkleste måten å holde treningen i gang på.</p>
</section>

<section class="c">
<?php if (!$grupper): ?>
  <p class="lead">Oversikten fylles i løpet av dagen. Kom tilbake litt senere, eller
  <a href="mailto:hei@treni.no">send oss en e-post</a>.</p>
<?php else: ?>
  <div class="filtre" role="group" aria-label="Filtrer løpegruppene">
    <button type="button" class="pille gr-filter" data-region="alle" aria-pressed="true">Hele Norge</button>
    <button type="button" class="pille gr-filter" data-region="nn" aria-pressed="false">Nord-Norge</button>
    <label class="liten" for="fylke-valg" style="position:absolute;left:-9999px">Fylke</label>
    <select id="fylke-valg">
      <option value="">Velg fylke …</option>
      <?php foreach ($fylker as $fy): ?>
      <option value="<?= e10($fy) ?>"><?= e10($fy) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="liten" for="gr-sok" style="position:absolute;left:-9999px">Søk</label>
    <input id="gr-sok" type="search" placeholder="Søk sted eller navn …">
  </div>

  <div class="treni-boks">
    <div>
      <b>Har du gruppa, men mangler planen?</b>
      <p>Treni lager uka di ut fra det du faktisk løper, og bygger den på nytt når livet skjer. Fellesøktene passer rett inn.</p>
    </div>
    <?= butikkknapper10('lopegrupper') ?>
  </div>

  <?php foreach ($per_fylke as $fylke => $liste): ?>
  <h2 class="gruppetittel fylke-tittel"><?= e10($fylke) ?> <span><?= count($liste) ?> <?= count($liste) === 1 ? 'gruppe' : 'grupper' ?></span></h2>
  <div class="liste">
  <?php foreach ($liste as $g): ?>
  <div class="rad uten-dato gr-rad" data-nn="<?= isset($FYLKE_NN[$g['fylke'] ?? '']) ? 1 : 0 ?>"
       data-fylke="<?= e10($g['fylke'] ?? '') ?>"
       data-sok="<?= e10(mb_strtolower(($g['navn'] ?? '') . ' ' . ($g['sted'] ?? ''))) ?>">
    <div>
      <b><?= e10($g['navn']) ?></b><span class="merke"><?= e10($typer[$g['type'] ?? ''] ?? 'Gruppe') ?></span>
      <span class="sted"><?= e10($g['sted']) ?><?= !empty($g['om']) ? ' · ' . e10($g['om']) : '' ?></span>
    </div>
    <div class="lenker">
      <?php if (!empty($g['strava'])): ?>
        <a class="tekstlenke" href="<?= e10($via10($g['strava'], $g)) ?>" rel="nofollow">Strava-klubben</a>
      <?php endif; ?>
      <?php if (!empty($g['lenke'])): ?>
        <a class="k kant smal" href="<?= e10($via10($g['lenke'], $g)) ?>" rel="nofollow">Til gruppa →</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <p class="liten" style="margin-top:32px">Oversikten er samlet fra klubbenes og gruppenes egne sider og oppdateres jevnlig.
  Mangler gruppa di, eller stemmer ikke noe? <a href="mailto:hei@treni.no?subject=L%C3%B8pegruppe%20som%20mangler">Tips oss</a>,
  så retter vi det. Treni er ikke tilknyttet gruppene. Oppmøte og medlemskap skjer hos hver enkelt.</p>
<?php endif; ?>
</section>

<section class="c om" aria-labelledby="om-gr-t">
  <h2 id="om-gr-t">Om løpegruppene</h2>
  <p>Oversikten samler løpeklubber, lavterskelgrupper og butikkdrevne løpegrupper i hele Norge, med eget filter for
  Nord-Norge og fylkesvalg. Gruppene er hentet fra klubbenes egne sider, Kondis, friidrettskretsene og gruppenes åpne
  Facebook-sider.</p>
  <h3>Hva er forskjellen på typene?</h3>
  <p><b>Klubb</b> er organiserte idrettslag med faste treninger og medlemskap. <b>Lavterskel</b> er åpne grupper der du
  bare møter opp, ofte gratis. <b>Butikkgruppe</b> er faste fellesøkter i regi av en sportsbutikk, vanligvis åpne for alle.</p>
  <h3>Hvordan kommer jeg i gang i en løpegruppe?</h3>
  <p>Sjekk gruppas side for treningstider, møt opp og si hei. De aller fleste grupper har flere temponivåer, og ingen
  forventer at du er rask. Vil du ha en plan for treningen rundt fellesøktene, er det det <a href="/">Treni</a> gjør:
  uka di bygges ut fra det du faktisk løper, med reglene til trener Eirik Haugsnes.</p>
  <h3>Mangler gruppa di?</h3>
  <p><a href="mailto:hei@treni.no?subject=L%C3%B8pegruppe%20som%20mangler">Send oss en e-post</a> med navn og lenke,
  så legger vi den inn.</p>
</section>

<section class="slutt" id="last"><div class="glod"></div><div class="c" style="position:relative">
  <h2>Neste uke starter på søndag</h2>
  <p>Last ned Treni, fortell hvor du står, og få første uke med en gang. Veilederen i appen er KI, bygd på Eiriks regler.</p>
  <?= butikkknapper10('lopegrupper') ?>
</div></section>
</main>

<footer><div class="c">
  <span>Treni · <a href="mailto:hei@treni.no">hei@treni.no</a></span>
  <nav aria-label="Bunnmeny"><a href="/">Forsiden</a><a href="/lop.php">Løpskalender</a><a href="/arrangor/">Løpegrupper og arrangører</a><a href="/personvern.html">Personvern</a><a href="/vilkar.html">Vilkår</a></nav>
</div></footer>

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
  var a = document.querySelector('.gr-filter[aria-pressed="true"]');
  return a ? a.dataset.region : 'alle';
}
function settFilter(region, fylke, ord) {
  document.querySelectorAll('.gr-filter').forEach(function (x) {
    x.setAttribute('aria-pressed', x.dataset.region === region ? 'true' : 'false');
  });
  var nn = region === 'nn';
  document.querySelectorAll('.gr-rad').forEach(function (r) {
    var ok = (!nn || r.dataset.nn === '1') && (!fylke || r.dataset.fylke === fylke)
          && (!ord || r.dataset.sok.indexOf(ord) !== -1);
    r.style.display = ok ? '' : 'none';
  });
  document.querySelectorAll('.fylke-tittel').forEach(function (m) {
    var liste = m.nextElementSibling, synlig = false;
    if (liste) {
      liste.querySelectorAll('.gr-rad').forEach(function (r) { if (r.style.display !== 'none') { synlig = true; } });
      liste.style.display = synlig ? '' : 'none';
    }
    m.style.display = synlig ? '' : 'none';
  });
}
</script>

<script type="application/ld+json">
{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[
 {"@type":"Question","name":"Hvordan finner jeg en løpegruppe nær meg?",
  "acceptedAnswer":{"@type":"Answer","text":"Bruk fylkesfilteret eller søkefeltet i oversikten. Hver gruppe har lenke til sin egen side med treningstider og oppmøtested."}},
 {"@type":"Question","name":"Koster det noe å være med i en løpegruppe?",
  "acceptedAnswer":{"@type":"Answer","text":"Lavterskelgrupper og butikkgrupper er som regel gratis og åpne for alle. Klubber har vanligvis et medlemskap, men de fleste lar deg prøve noen treninger først."}},
 {"@type":"Question","name":"Må jeg være rask for å bli med?",
  "acceptedAnswer":{"@type":"Answer","text":"Nei. De aller fleste løpegrupper har flere temponivåer, og lavterskelgruppene er laget nettopp for at alle skal kunne være med."}}]}
</script>
</body>
</html>
