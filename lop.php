<?php
// Åpen løpskalender for Nord-Norge (Odds go 29.08.2026 — rekrutteringsstrategi
// punkt 2): SEO-side som viser alle kommende løp fra EQ Timing-dataene
// løpsradaren vedlikeholder (meta.lopsradar i delt MySQL, oppdatert daglig).
// CTA-er peker til testløper-skjemaet — dette er salgsflatens løpsinngang.
$lop = [];
$sist = '';
$cfg_sti = dirname(__DIR__) . "/dashbord_config.php";
if (is_readable($cfg_sti)) {
    $konfig = include $cfg_sti;
    if (is_array($konfig)) {
        try {
            $pdo = new PDO(
                "mysql:host={$konfig['db_host']};dbname={$konfig['db_name']};charset=utf8mb4",
                $konfig['db_user'], $konfig['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $rad = $pdo->query("SELECT verdi FROM meta WHERE nokkel='lopsradar'")
                ->fetch(PDO::FETCH_ASSOC);
            if ($rad) {
                $radar = json_decode($rad['verdi'], true) ?: [];
                $sist = substr((string) ($radar['sist'] ?? ''), 0, 10);
                foreach (($radar['lop_alle'] ?? $radar['lop_nn'] ?? []) as $l) {
                    if (substr($l['dato'], 0, 10) >= date('Y-m-d')) {
                        $lop[] = $l;
                    }
                }
            }
        } catch (Throwable $e) { /* siden vises med tom-melding */ }
    }
}
$MND = [1 => 'Januar', 'Februar', 'Mars', 'April', 'Mai', 'Juni', 'Juli',
        'August', 'September', 'Oktober', 'November', 'Desember'];
$per_mnd = [];
$fylker = [];
foreach ($lop as $l) {
    $per_mnd[substr($l['dato'], 0, 7)][] = $l;
    if (!empty($l['fylke'])) { $fylker[$l['fylke']] = true; }
}
$fylker = array_keys($fylker);
sort($fylker);
function e9(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
// Alle eksterne løpslenker går via mellomsiden videre.php (Odds idé 31.08):
// «skal vi lage treningsplanen mot løpet?» før arrangørens side. HMAC-signert
// mål-URL hindrer åpen redirect.
$via9 = function (string $url, array $l) use ($konfig): string {
    // Egen signeringsnøkkel (revisjon 02.09) — db-passordet gjenbrukes ikke;
    // fallback til db_pass til videre_secret finnes i config.
    $nokkel9 = (string) ($konfig['videre_secret'] ?? $konfig['db_pass'] ?? '');
    $n = $nokkel9 !== '' ? substr(hash_hmac('sha256', $url, $nokkel9), 0, 16) : '';
    return 'videre.php?til=' . urlencode($url) . '&s=' . $n
         . '&lop=' . urlencode($l['navn'] ?? '')
         . '&dato=' . urlencode(substr($l['dato'] ?? '', 0, 10));
};
?>
<!DOCTYPE html>
<html lang="no">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Løpskalender Norge <?= date('Y') ?> — alle løp med påmelding | Treni</title>
<meta name="description" content="Komplett oversikt over kommende løp i hele Norge: gateløp, terrengløp og fjelløp med dato, sted og påmeldingslenke. Oppdateres daglig.">
<meta property="og:title" content="Løpskalender Norge — alle løp med påmelding">
<meta property="og:description" content="Gateløp, terrengløp og fjelløp i hele landet — dato, sted og påmelding, samlet på ett sted. Oppdateres daglig.">
<meta property="og:type" content="website">
<meta property="og:url" content="https://treni.no/lop.php">
<link rel="canonical" href="https://treni.no/lop.php">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🏁</text></svg>">
<link rel="stylesheet" href="stil.css?v=19">
<script type="application/ld+json">
<?= json_encode(['@context' => 'https://schema.org', '@graph' => array_map(fn($l) => [
    '@type' => 'SportsEvent',
    'name' => $l['navn'],
    'description' => $l['navn'] . ' — løp' . ($l['by'] ? ' i ' . $l['by'] : ' i Nord-Norge')
                     . ' ' . substr($l['dato'], 0, 10) . '. Dato, sted og påmelding i Trenis løpskalender.',
    'startDate' => $l['dato'],
    'endDate' => $l['dato'],
    'eventStatus' => 'https://schema.org/EventScheduled',
    'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
    'image' => 'https://treni.no/bilder/og.jpg',
    'sport' => 'Løping',
    'location' => ['@type' => 'Place', 'name' => $l['by'] ?: 'Norge',
                   'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'NO']],
    'organizer' => ['@type' => 'Organization',
                    'name' => $l['arrangor'] ?? ('Arrangøren av ' . $l['navn']),
                    'url' => $l['pamelding'] ?: ($l['live'] ?: 'https://treni.no/lop.php')],
    'url' => $l['pamelding'] ?: $l['live'],
], array_slice($lop, 0, 50))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>
<style>
.lop-rad { display:flex; gap:1rem; align-items:center; padding:.7rem 0;
  border-bottom:1px solid hsl(var(--border)); flex-wrap:wrap; }
.lop-dato { flex:0 0 3.6rem; text-align:center; line-height:1.05; }
.lop-dato b { font-size:1.35rem; font-family:var(--font-serif); }
.lop-info { flex:1; min-width:14rem; }
.lop-knapp { display:inline-block; padding:.4rem 1.1rem; border-radius:999px;
  border:1.5px solid hsl(var(--primary)); color:hsl(var(--primary));
  font-weight:650; font-size:.9rem; text-decoration:none; white-space:nowrap; }
.lop-knapp:hover { background:hsl(var(--primary)); color:hsl(var(--primary-fg)); }
.mnd-tittel { font-family:var(--font-serif); font-size:1.35rem; margin:1.8rem 0 .3rem; }
.cta-strip { border:2px solid hsl(var(--accent) / .5); border-radius:var(--radius);
  padding:1rem 1.2rem; margin:1.6rem 0; background:hsl(var(--accent) / .07); }
</style>
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<main>

<header class="hero smal">
  <p class="kicker reveal"><a href="index.html" style="color:inherit">treni.no</a> · løpskalender</p>
  <h1 class="reveal" style="font-size:clamp(1.8rem,5vw,2.7rem)">Løp i Norge —<br>alle på ett sted</h1>
  <p class="ingress reveal">Gateløp, terrengløp og fjelløp i hele Norge med dato, sted og
  påmeldingslenke — hentet automatisk fra tidtakersystemet EQ Timing<?= $sist ? ', oppdatert ' . e9(substr($sist, 8, 2)) . '.' . e9(substr($sist, 5, 2)) : '' ?>.
  Fant du løpet ditt? Da vet du hva du skal trene mot. 🏔️</p>
</header>

<section>
<?php if (!$lop): ?>
  <div class="kort"><p style="margin:0">Kalenderen fylles i løpet av dagen — kom tilbake litt senere,
  eller <a href="mailto:hei@treni.no">send oss en e-post</a>.</p></div>
<?php else: ?>
  <div style="display:flex; gap:.6rem; margin:.2rem 0 1rem">
    <button class="lop-filter aktiv" data-region="alle" style="padding:.45rem 1.2rem; border-radius:999px; border:1.5px solid hsl(var(--primary)); background:hsl(var(--primary)); color:hsl(var(--primary-fg)); font-weight:650; cursor:pointer">Hele Norge</button>
    <button class="lop-filter" data-region="nn" style="padding:.45rem 1.2rem; border-radius:999px; border:1.5px solid hsl(var(--border)); background:transparent; color:hsl(var(--fg)); font-weight:650; cursor:pointer">Nord-Norge</button>
    <select id="fylke-valg" style="padding:.45rem 1rem; border-radius:999px; border:1.5px solid hsl(var(--border));
        background:transparent; color:hsl(var(--fg)); font-weight:650; cursor:pointer; font:inherit">
      <option value="">Velg fylke …</option>
      <?php foreach ($fylker as $fy): ?>
      <option value="<?= e9($fy) ?>"><?= e9($fy) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="cta-strip">
    <b>🎯 Meldt deg på et løp?</b> Treni bygger treningsplanen din mot akkurat det løpet —
    uke for uke, med en ekte trener på laget og skadefri fremgang som mål.
    <a href="bli-testloper.php"><b>Bli testløper →</b></a>
  </div>
  <?php foreach ($per_mnd as $mnd => $lopene): ?>
  <h2 class="mnd-tittel"><?= $MND[(int) substr($mnd, 5, 2)] ?> <?= substr($mnd, 0, 4) ?></h2>
  <?php foreach ($lopene as $l): ?>
  <div class="lop-rad" data-nn="<?= !empty($l['nn']) ? 1 : 0 ?>" data-fylke="<?= e9($l['fylke'] ?? '') ?>">
    <div class="lop-dato"><b><?= (int) substr($l['dato'], 8, 2) ?>.</b><br>
      <span class="liten"><?= strtolower(substr($MND[(int) substr($l['dato'], 5, 2)], 0, 3)) ?></span></div>
    <div class="lop-info">
      <b><?= e9($l['navn']) ?></b>
      <?php if (!empty($l['by'])): ?><br><span class="liten">📍 <?= e9($l['by']) ?></span><?php endif; ?>
    </div>
    <?php if (!empty($l['pamelding'])): ?>
      <a class="lop-knapp" href="<?= e9($via9($l['pamelding'], $l)) ?>">Påmelding →</a>
    <?php elseif (!empty($l['live'])): ?>
      <a class="liten" href="<?= e9($via9($l['live'], $l)) ?>">løpsside →</a>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endforeach; ?>
  <div class="cta-strip" style="margin-top:2rem">
    <b>🏃 Vil du komme forberedt til start?</b> Treni leser treningen din fra Strava og
    gir deg ukeplan og oppfølging mot løpet ditt — trenerledet, skadefritt først.
    <a href="bli-testloper.php"><b>Sett meg på ventelista →</b></a>
  </div>
  <p class="liten" style="margin-top:1.4rem">Kalenderen dekker arrangementer med påmelding/tidtaking
  hos EQ Timing de neste 18 månedene — også neste års arrangementer, og oppdateres daglig. Mangler et løp?
  <a href="mailto:hei@treni.no?subject=L%C3%B8p%20som%20mangler%20i%20kalenderen">Tips oss</a>,
  så legger vi det inn. Treni er ikke tilknyttet EQ Timing — påmelding skjer hos arrangøren.</p>
<?php endif; ?>
<script>
document.querySelectorAll('.lop-filter').forEach(function (kn) {
  kn.addEventListener('click', function () {
    var fv = document.getElementById('fylke-valg');
    if (fv) { fv.value = ''; }   // region-knapp nullstiller fylkesvalget
    settFilter(kn.dataset.region, '');
  });
});
var fylkeVelger = document.getElementById('fylke-valg');
if (fylkeVelger) {
  fylkeVelger.addEventListener('change', function () {
    // fylkesvalg overstyrer regionknappene (aktiverer «Hele Norge»)
    settFilter(fylkeVelger.value ? 'alle' : aktivRegion(), fylkeVelger.value);
  });
}
function aktivRegion() {
  var a = document.querySelector('.lop-filter.aktiv');
  return a ? a.dataset.region : 'alle';
}
function settFilter(region, fylke) {
  document.querySelectorAll('.lop-filter').forEach(function (x) {
    var aktiv = x.dataset.region === region;
    x.classList.toggle('aktiv', aktiv);
    x.style.background = aktiv ? 'hsl(var(--primary))' : 'transparent';
    x.style.color = aktiv ? 'hsl(var(--primary-fg))' : 'hsl(var(--fg))';
    x.style.borderColor = aktiv ? 'hsl(var(--primary))' : 'hsl(var(--border))';
  });
  var nn = region === 'nn';
  document.querySelectorAll('.lop-rad').forEach(function (r) {
    var ok = (!nn || r.dataset.nn === '1') && (!fylke || r.dataset.fylke === fylke);
    r.style.display = ok ? '' : 'none';
  });
  document.querySelectorAll('.mnd-tittel').forEach(function (m) {
    var e = m.nextElementSibling, synlig = false;
    while (e && e.classList.contains('lop-rad')) {
      if (e.style.display !== 'none') { synlig = true; break; }
      e = e.nextElementSibling;
    }
    m.style.display = synlig ? '' : 'none';
  });
}
</script>
</section>

<section aria-labelledby="om-kal-t" style="max-width:46rem">
  <h2 id="om-kal-t" style="font-size:1.3rem">Om løpskalenderen</h2>
  <p class="liten">Kalenderen samler kommende <b>gateløp, terrengløp, motbakkeløp og
  fjelløp i hele Norge</b> — med eget filter for Nord-Norge, fra Helgeland via Lofoten,
  Harstad, Narvik og Tromsø til Alta, Hammerfest og Finnmark. Løpene hentes automatisk fra
  EQ Timing, som håndterer påmelding og tidtaking for de fleste norske mosjonsløp, og
  lista oppdateres hver morgen.</p>
  <h3 style="font-size:1.05rem; margin:1.1rem 0 .3rem">Hvordan melder jeg meg på et løp?</h3>
  <p class="liten">Trykk «Påmelding» ved løpet — du sendes rett til arrangørens
  påmeldingsside. Påmeldingen og betalingen skjer hos arrangøren, ikke hos Treni.</p>
  <h3 style="font-size:1.05rem; margin:1.1rem 0 .3rem">Hvordan trener jeg riktig mot et løp?</h3>
  <p class="liten">Gradvis oppbygging, riktig fordeling mellom rolig og hard trening, og
  en plan som topper formen til løpsdagen — det er akkurat det <a href="index.html">Treni</a>
  gjør: en trenerledet treningsveileder som leser treningen din fra Strava og bygger
  ukeplanen mot løpet ditt. <a href="bli-testloper.php">Bli testløper →</a></p>
  <h3 style="font-size:1.05rem; margin:1.1rem 0 .3rem">Mangler et løp i kalenderen?</h3>
  <p class="liten">Kalenderen dekker løp med påmelding hos EQ Timing. Arrangerer du et
  løp som mangler, eller vet om et? <a href="mailto:hei@treni.no">Send oss en e-post</a>,
  så legger vi det inn.</p>
</section>

<script type="application/ld+json">
{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[
 {"@type":"Question","name":"Hvordan melder jeg meg på et løp?",
  "acceptedAnswer":{"@type":"Answer","text":"Bruk påmeldingslenken ved løpet i kalenderen — den går rett til arrangørens påmeldingsside hos EQ Timing. Påmelding og betaling skjer hos arrangøren."}},
 {"@type":"Question","name":"Hvilke løp dekker kalenderen?",
  "acceptedAnswer":{"@type":"Answer","text":"Gateløp, terrengløp, motbakkeløp og fjelløp i hele Norge de neste 18 månedene — med fylkesfilter og eget Nord-Norge-filter — hentet automatisk fra EQ Timing og oppdatert daglig."}},
 {"@type":"Question","name":"Hvordan trener jeg riktig mot et løp?",
  "acceptedAnswer":{"@type":"Answer","text":"Gradvis oppbygging, mest rolig trening og en plan som topper formen til løpsdagen. Treni er en trenerledet treningsveileder som leser treningen din fra Strava og bygger ukeplanen mot løpet ditt."}}]}
</script>

<footer>
  <nav aria-label="Bunnmeny">
    <a href="index.html">Forsiden</a>
    <a href="lopegrupper.php">Løpegrupper</a>
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
