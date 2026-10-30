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
                $sett9 = [];
                foreach (($radar['lop_alle'] ?? $radar['lop_nn'] ?? []) as $l) {
                    if (substr($l['dato'], 0, 10) < date('Y-m-d')) { continue; }
                    // Revisjon 10.09: radaren leverer råe navn. Trim doble
                    // mellomrom, hopp over testarrangementer, merk AVLYST og
                    // ta bort dubletter (samme navn og dato).
                    $l['navn'] = trim(preg_replace('/\s+/u', ' ', (string) ($l['navn'] ?? '')));
                    if ($l['navn'] === '' || preg_match('/^TEST\b|\bTEST\s*[-:]/u', $l['navn'])) { continue; }
                    if (preg_match('/\bAVLYST\b/iu', $l['navn'])) {
                        $l['navn'] = trim(preg_replace('/\s*[-:·]?\s*AVLYST\s*$/iu', '', $l['navn'])) . ' (avlyst)';
                        $l['pamelding'] = '';
                    }
                    $n9 = mb_strtolower($l['navn']) . '|' . substr($l['dato'], 0, 10);
                    if (isset($sett9[$n9])) { continue; }
                    $sett9[$n9] = true;
                    $lop[] = $l;
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
// Knappene til butikkene, som på forsida. Klikket logges i treni.no/g med kanalen.
// Odd 30.10.2026: samme profil og design som forsida (ny/side.css), tekstene etter budskapet for Treni.
function butikkknapper9(string $k): string {
    return '<div class="knapper">'
        . '<a class="k hoved" href="/g/?til=android&amp;k=' . e9($k) . '" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M5 3.5v17l9-8.5-9-8.5z"/><path d="M14 12l3.5-3.3L20 10.2v3.6l-2.5 1.5z"/></svg><span><small>Last ned på</small>Google Play</span></a>'
        . '<a class="k kant" href="/g/?til=ios&amp;k=' . e9($k) . '" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.4 12.6c0-2.4 2-3.6 2.1-3.7-1.1-1.7-2.9-1.9-3.5-1.9-1.5-.2-2.9.9-3.7.9-.8 0-1.9-.9-3.2-.8-1.6 0-3.1 1-4 2.4-1.7 3-.4 7.3 1.2 9.7.8 1.2 1.8 2.5 3 2.4 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.2.8 1.3 0 2.2-1.2 3-2.4.9-1.4 1.3-2.7 1.3-2.8-.1 0-2.5-1-2.5-3.8zM14 5.4c.7-.8 1.1-1.9 1-3-1 0-2.1.7-2.8 1.5-.6.7-1.2 1.8-1 2.9 1 .1 2.1-.6 2.8-1.4z"/></svg><span><small>Last ned i</small>App Store</span></a>'
        . '</div>';
}
?>
<!doctype html>
<html lang="nb">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Løpskalender Norge <?= date('Y') ?>: alle løp med påmelding | Treni</title>
<meta name="description" content="Komplett oversikt over kommende løp i hele Norge: gateløp, terrengløp og fjelløp med dato, sted og påmeldingslenke. Oppdateres daglig.">
<meta property="og:title" content="Løpskalender Norge · alle løp med påmelding">
<meta property="og:description" content="Gateløp, terrengløp og fjelløp i hele landet, dato, sted og påmelding, samlet på ett sted. Oppdateres daglig.">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Treni">
<meta property="og:image" content="https://treni.no/ny/bilder/deling.jpg">
<meta property="og:url" content="https://treni.no/lop.php">
<meta property="og:locale" content="nb_NO">
<link rel="canonical" href="https://treni.no/lop.php">
<link rel="icon" href="/favicon.ico">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;800&display=swap">
<link rel="stylesheet" href="/ny/side.css?v=1">
<script type="application/ld+json">
<?= json_encode(["@context" => "https://schema.org", "@graph" => array_map(fn($l) => [
    "@type" => "SportsEvent",
    "name" => $l["navn"],
    "description" => $l["navn"] . (!empty($l["by"]) ? " i " . $l["by"] : " i Norge")
                     . " " . substr($l["dato"], 0, 10) . ". Dato, sted og påmelding i Trenis løpskalender.",
    "startDate" => $l["dato"],
    "endDate" => $l["dato"],
    "eventStatus" => "https://schema.org/EventScheduled",
    "eventAttendanceMode" => "https://schema.org/OfflineEventAttendanceMode",
    "image" => "https://treni.no/ny/bilder/deling.jpg",
    "sport" => "Løping",
    "location" => ["@type" => "Place", "name" => ($l["by"] ?? '') ?: "Norge",
                   "address" => ["@type" => "PostalAddress", "addressCountry" => "NO"]],
    "organizer" => ["@type" => "Organization",
                    "name" => $l["arrangor"] ?? ("Arrangøren av " . $l["navn"]),
                    "url" => ($l["pamelding"] ?? '') ?: (($l["live"] ?? '') ?: "https://treni.no/lop.php")],
    "url" => ($l["pamelding"] ?? '') ?: ($l["live"] ?? ''),
], array_slice($lop, 0, 50))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>
</head>
<body>

<header><div class="c topp">
  <a class="logo" href="/" aria-label="Treni">Tren<i aria-hidden="true">ı</i></a>
  <nav class="meny" aria-label="Hovedmeny"><a href="/lop.php" aria-current="page">Løpskalender</a><a href="/lopegrupper.php">Løpegrupper</a><a class="liten" href="#last">Last ned</a></nav>
</div></header>

<main>
<section class="c sidehode">
  <span class="etikett">Løpskalender</span>
  <h1>Alle løp i Norge,<br><em>på ett sted.</em></h1>
  <p class="lead">Gateløp, terrengløp og fjelløp med dato, sted og påmelding, hentet fra tidtakersystemet EQ Timing<?= $sist ? ' og oppdatert ' . e9((string) (int) substr($sist, 8, 2)) . '.' . e9((string) (int) substr($sist, 5, 2)) : '' ?>.
  Fant du løpet ditt? Da vet du hva du skal trene mot.</p>
</section>

<section class="c">
<?php if (!$lop): ?>
  <p class="lead">Kalenderen fylles i løpet av dagen. Kom tilbake litt senere, eller
  <a href="mailto:hei@treni.no">send oss en e-post</a>.</p>
<?php else: ?>
  <div class="filtre" role="group" aria-label="Filtrer løpene">
    <button type="button" class="pille lop-filter" data-region="alle" aria-pressed="true">Hele Norge</button>
    <button type="button" class="pille lop-filter" data-region="nn" aria-pressed="false">Nord-Norge</button>
    <label for="fylke-valg" style="position:absolute;left:-9999px">Fylke</label>
    <select id="fylke-valg">
      <option value="">Velg fylke …</option>
      <?php foreach ($fylker as $fy): ?>
      <option value="<?= e9($fy) ?>"><?= e9($fy) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="treni-boks">
    <div>
      <b>Meldt deg på et løp?</b>
      <p>Treni lager uka di mot løpet, ut fra det du faktisk løper, og bygger den på nytt når livet skjer.</p>
    </div>
    <?= butikkknapper9('lop') ?>
  </div>

  <?php foreach ($per_mnd as $mnd => $lopene): ?>
  <h2 class="gruppetittel mnd-tittel"><?= $MND[(int) substr($mnd, 5, 2)] ?> <?= substr($mnd, 0, 4) ?> <span><?= count($lopene) ?> løp</span></h2>
  <div class="liste">
  <?php foreach ($lopene as $l): ?>
  <div class="rad lop-rad" data-nn="<?= !empty($l['nn']) ? 1 : 0 ?>" data-fylke="<?= e9($l['fylke'] ?? '') ?>">
    <div class="dato"><b><?= (int) substr($l['dato'], 8, 2) ?>.</b><small><?= strtolower(substr($MND[(int) substr($l['dato'], 5, 2)], 0, 3)) ?></small></div>
    <div>
      <b><?= e9($l['navn']) ?></b>
      <?php if (!empty($l['by'])): ?><span class="sted"><?= e9($l['by']) ?></span><?php endif; ?>
    </div>
    <div class="lenker">
    <?php if (!empty($l['pamelding'])): ?>
      <a class="k kant smal" href="<?= e9($via9($l['pamelding'], $l)) ?>">Påmelding →</a>
    <?php elseif (!empty($l['live'])): ?>
      <a class="tekstlenke" href="<?= e9($via9($l['live'], $l)) ?>">Løpssiden →</a>
    <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <p class="liten" style="margin-top:32px">Kalenderen dekker arrangementer med påmelding eller tidtaking hos EQ Timing de neste
  18 månedene, og oppdateres hver dag. Mangler et løp? <a href="mailto:hei@treni.no?subject=L%C3%B8p%20som%20mangler%20i%20kalenderen">Tips oss</a>,
  så legger vi det inn. Treni er ikke tilknyttet EQ Timing. Påmelding skjer hos arrangøren.</p>
<?php endif; ?>
</section>

<section class="c om" aria-labelledby="om-kal-t">
  <h2 id="om-kal-t">Om løpskalenderen</h2>
  <p>Kalenderen samler kommende gateløp, terrengløp, motbakkeløp og fjelløp i hele Norge, med fylkesvalg og eget filter for
  Nord-Norge. Løpene hentes fra EQ Timing, som har påmelding og tidtaking for de fleste norske mosjonsløp, og lista
  oppdateres hver morgen.</p>
  <h3>Hvordan melder jeg meg på et løp?</h3>
  <p>Trykk «Påmelding» ved løpet. Du sendes videre til arrangørens påmeldingsside. Påmelding og betaling skjer hos
  arrangøren, ikke hos Treni.</p>
  <h3>Hvordan trener jeg riktig mot et løp?</h3>
  <p>Gradvis oppbygging, mest rolig trening og en plan som tåler at uka ikke går som planlagt. Det er det
  <a href="/">Treni</a> gjør: uka di bygges ut fra det du faktisk løper, mot løpet du sikter mot, med reglene til trener
  Eirik Haugsnes.</p>
  <h3>Mangler et løp i kalenderen?</h3>
  <p>Kalenderen dekker løp med påmelding hos EQ Timing. Arrangerer du et løp som mangler, eller vet om et?
  <a href="mailto:hei@treni.no">Send oss en e-post</a>, så legger vi det inn.</p>
</section>

<section class="slutt" id="last"><div class="glod"></div><div class="c" style="position:relative">
  <h2>Neste uke starter på søndag</h2>
  <p>Last ned Treni, fortell hvor du står og hvilket løp du sikter mot. Veilederen i appen er KI, bygd på Eiriks regler.</p>
  <?= butikkknapper9('lop') ?>
</div></section>
</main>

<footer><div class="c">
  <span>Treni · <a href="mailto:hei@treni.no">hei@treni.no</a></span>
  <nav aria-label="Bunnmeny"><a href="/">Forsiden</a><a href="/lopegrupper.php">Løpegrupper</a><a href="/arrangor/">Løpegrupper og arrangører</a><a href="/personvern.html">Personvern</a><a href="/vilkar.html">Vilkår</a></nav>
</div></footer>

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
  var a = document.querySelector('.lop-filter[aria-pressed="true"]');
  return a ? a.dataset.region : 'alle';
}
function settFilter(region, fylke) {
  document.querySelectorAll('.lop-filter').forEach(function (x) {
    x.setAttribute('aria-pressed', x.dataset.region === region ? 'true' : 'false');
  });
  var nn = region === 'nn';
  document.querySelectorAll('.lop-rad').forEach(function (r) {
    var ok = (!nn || r.dataset.nn === '1') && (!fylke || r.dataset.fylke === fylke);
    r.style.display = ok ? '' : 'none';
  });
  document.querySelectorAll('.mnd-tittel').forEach(function (m) {
    var liste = m.nextElementSibling, synlig = false;
    if (liste) {
      liste.querySelectorAll('.lop-rad').forEach(function (r) { if (r.style.display !== 'none') { synlig = true; } });
      liste.style.display = synlig ? '' : 'none';
    }
    m.style.display = synlig ? '' : 'none';
  });
}
</script>

<script type="application/ld+json">
{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[
 {"@type":"Question","name":"Hvordan melder jeg meg på et løp?",
  "acceptedAnswer":{"@type":"Answer","text":"Bruk påmeldingslenken ved løpet i kalenderen. Den sender deg videre til arrangørens påmeldingsside. Påmelding og betaling skjer hos arrangøren."}},
 {"@type":"Question","name":"Hvilke løp dekker kalenderen?",
  "acceptedAnswer":{"@type":"Answer","text":"Gateløp, terrengløp, motbakkeløp og fjelløp i hele Norge de neste 18 månedene, med fylkesvalg og eget filter for Nord-Norge, hentet fra EQ Timing og oppdatert hver dag."}},
 {"@type":"Question","name":"Hvordan trener jeg riktig mot et løp?",
  "acceptedAnswer":{"@type":"Answer","text":"Gradvis oppbygging, mest rolig trening og en plan som tåler at uka ikke går som planlagt. Treni bygger uka ut fra det du faktisk løper, mot løpet du sikter mot."}}]}
</script>
</body>
</html>
