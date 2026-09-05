<?php
// treni.no/fb — landingssiden for lenka i Facebook-innleggene. Speiler
// treni.no/ig; se den for hvorfor sidene finnes.
//
// Odd 05.09.2026, etter den første tallgjennomgangen: vi kunne se at et
// innlegg ga profilbesøk, men ikke om noen faktisk kom videre til oss.
// Halve trakta manglet. Denne sida er den manglende halvdelen — hvert
// besøk logges, og lenkene videre bærer med seg hvor besøket kom fra, slik
// at ventelistepåmeldinger kan spores tilbake til innlegget som utløste dem
// (bli-testloper.php leser «kilde» fra adressen og lagrer den i basen).
//
// ?p=<jobb-id> settes i bio-lenka når vi vil måle ett bestemt innlegg,
// f.eks. treni.no/ig?p=karJ_uke37.
date_default_timezone_set('Europe/Oslo');

$post = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($_GET['p'] ?? ''));
$post = mb_substr($post, 0, 40);

// Logg besøket. Fila ligger UTENFOR public_html, så den aldri kan hentes
// av andre. Vi lagrer ingen IP og ingen identifiserende data — bare når,
// hvorfra og hvilket innlegg, som er alt vi trenger for å telle.
$logg = dirname(__DIR__, 2) . '/fb_besok.jsonl';
@file_put_contents($logg, json_encode([
    'ts'       => date('c'),
    'post'     => $post,
    'henvist'  => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 120),
    'mobil'    => (bool) preg_match('/Mobile|Android|iPhone/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

$kilde = 'Facebook' . ($post !== '' ? " ({$post})" : '');
$til   = 'https://treni.no/bli-testloper.php?kilde=' . rawurlencode($kilde);
?>
<!DOCTYPE html>
<html lang="no">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Treni — for deg som løper i nord</title>
<meta name="description" content="En ekte trener og en motor som ser hver økt. Finn neste løp i nord, eller sett deg på ventelista.">
<meta name="robots" content="noindex">
<meta property="og:title" content="Treni — for deg som løper i nord">
<meta property="og:description" content="En ekte trener. En som ser hver økt, og aldri sover.">
<meta property="og:image" content="https://treni.no/bilder/og.jpg">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" type="image/png" href="/icon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="/stil.css">
<style>
  .ig-valg { display:grid; gap:.9rem; max-width:30rem; margin:1.6rem auto 0 }
  .ig-kort {
    display:block; padding:1.05rem 1.2rem; border-radius:var(--radius);
    border:1px solid hsl(var(--border)); background:hsl(var(--surface));
    color:inherit; text-decoration:none; box-shadow:var(--shadow-sm);
    transition:transform .15s ease, box-shadow .15s ease;
  }
  .ig-kort:hover, .ig-kort:focus-visible { transform:translateY(-2px); box-shadow:var(--shadow-md) }
  .ig-kort b { display:block; font-size:1.05rem; margin-bottom:.2rem }
  .ig-kort span { color:hsl(var(--muted-fg)); font-size:.94rem; line-height:1.45 }
  .ig-kort.primar {
    background:var(--gradient-cta); border-color:transparent;
    color:hsl(var(--primary-fg)); box-shadow:var(--shadow-cta);
  }
  .ig-kort.primar span { color:hsl(var(--primary-fg) / .82) }
  .ig-bunn { text-align:center; margin-top:1.8rem; color:hsl(var(--muted-fg)); font-size:.9rem }
</style>
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<main>

<header class="hero smal">
  <p class="kicker reveal"><a href="/index.html" style="color:inherit">treni.no</a></p>
  <h1 class="reveal" style="font-size:clamp(2rem,6vw,3rem)">Velkommen fra Facebook</h1>
  <p class="reveal" style="max-width:34rem; margin-inline:auto">
    Treni er en treningsveileder for løpere, med en ekte trener bak rådene og en
    motor som leser hver eneste økt. Vi holder til i nord.
  </p>
</header>

<section class="ig-valg">
  <a class="ig-kort primar" href="<?= htmlspecialchars($til) ?>">
    <b>Sett meg på ventelista →</b>
    <span>Vi tar inn løpere i puljer. Du får plass når det er din tur, og en plan
          bygget for deg av en trener.</span>
  </a>
  <a class="ig-kort" href="/lop.php">
    <b>Løpskalenderen</b>
    <span>Over 400 løp i Norge, med påmelding. Filtrer på landsdel og finn ditt neste.</span>
  </a>
  <a class="ig-kort" href="/lopegrupper.php">
    <b>Løpegrupper</b>
    <span>Finn noen å løpe med der du bor.</span>
  </a>
  <a class="ig-kort" href="/index.html">
    <b>Hva er Treni?</b>
    <span>Hele historien, og hvordan veiledningen faktisk fungerer.</span>
  </a>
</section>

<p class="ig-bunn">Spørsmål? Send oss en melding, eller skriv til
   <a href="mailto:hei@treni.no">hei@treni.no</a></p>

<footer>
  <nav aria-label="Bunnmeny">
    <a href="/index.html">Hjem</a>
    <a href="/stotte.html">Støtte og kontakt</a>
    <a href="/personvern.html">Personvern</a>
  </nav>
  <p>PAULSEN UTVIKLING · org.nr 938 158 614 · Norge ·
     <a href="mailto:hei@treni.no">hei@treni.no</a></p>
  <p>Powered by Strava — this service is not affiliated with or endorsed by Strava.</p>
</footer>

</main>
</body>
</html>
