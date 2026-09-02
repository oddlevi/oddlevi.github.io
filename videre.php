<?php
// Mellomside før ekstern påmelding (Odds idé 31.08.2026): alle påmeldings- og
// løpsside-lenker i løpskalenderen går via denne — ett enkelt spørsmål om
// Treni skal lage treningsplanen mot løpet, med onboarding som hovedvalg og
// «gå videre til påmelding» i liten skrift nederst.
// Åpen redirect-vern: mål-URL-en er HMAC-signert av lop.php (nøkkel =
// videre_secret i dashbord_config, aldri eksponert) — ugyldig signatur sendes
// til kalenderen i stedet.
$til = (string) ($_GET['til'] ?? '');
$sig = (string) ($_GET['s'] ?? '');
$lop = mb_substr(trim((string) ($_GET['lop'] ?? '')), 0, 90);
$dato = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['dato'] ?? '')) ? $_GET['dato'] : '';
$gyldig = false;
$cfg_sti = dirname(__DIR__) . "/dashbord_config.php";
if ($til !== '' && is_readable($cfg_sti)) {
    $konfig = include $cfg_sti;
    if (is_array($konfig) && preg_match('#^https?://#i', $til)) {
        $nokkel9 = (string) ($konfig['videre_secret'] ?? $konfig['db_pass'] ?? '');
        $fasit = substr(hash_hmac('sha256', $til, $nokkel9), 0, 16);
        $gyldig = hash_equals($fasit, $sig);
    }
}
if (!$gyldig) { header('Location: /lop.php'); exit; }
function e9(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
$MND9 = [1 => 'januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli',
         'august', 'september', 'oktober', 'november', 'desember'];
$dato_pen = $dato ? ((int) substr($dato, 8, 2)) . '. ' . $MND9[(int) substr($dato, 5, 2)] : '';
?>
<!DOCTYPE html>
<html lang="no">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $lop ? e9($lop) . ' — ' : '' ?>tren riktig mot løpet | Treni</title>
<link rel="stylesheet" href="stil.css?v=19">
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<main>
<header class="hero smal" style="padding-bottom:.5rem">
  <p class="kicker"><a href="lop.php" style="color:inherit">← løpskalenderen</a></p>
  <h1 style="font-size:clamp(1.7rem,5vw,2.4rem)">
    <?= $lop ? e9($lop) : 'Løpet ditt' ?><?= $dato_pen ? ' · ' . e9($dato_pen) : '' ?></h1>
</header>

<section style="max-width:36rem">
  <div style="border:2px solid hsl(var(--primary) / .45); border-radius:var(--radius);
       padding:1.6rem 1.5rem; background:hsl(var(--primary) / .06)">
    <p style="font-size:1.15rem; font-weight:650; margin:0 0 .5rem">
      Skal vi hjelpe deg å trene riktig — og mest mulig optimalt — mot løpet? 🏃</p>
    <p style="margin:0 0 1.1rem">Treni lager treningsplanen <b>sammen med deg</b>:
      uke for uke frem mot <?= $lop ? e9($lop) : 'løpsdagen' ?>, tilpasset nivået ditt,
      lest rett fra Strava — med en ekte trener på laget og skadefri fremgang som mål.
      Gratis i testperioden.</p>
    <a href="bli-testloper.php?lop=<?= urlencode($lop) ?><?= $dato ? '&dato=' . e9($dato) : '' ?>&til=<?= urlencode($til) ?>&s=<?= e9($sig) ?>"
       style="display:inline-block; padding:.8rem 1.6rem; border-radius:999px;
       background:hsl(var(--primary)); color:hsl(var(--primary-fg)); font-weight:700;
       font-size:1.05rem; text-decoration:none">✅ Ja takk — opprett Treni-treningsplan</a>
    <p class="liten" style="margin:.8rem 0 0; color:hsl(var(--muted-fg, var(--fg)))">
      2 minutter å komme i gang · ingen betaling · du kan melde deg på løpet etterpå.</p>
  </div>
  <p class="liten" style="margin:1.6rem 0 0; text-align:center">
    <a href="<?= e9($til) ?>" target="_blank" rel="noopener nofollow"
       style="color:inherit; opacity:.75">Nei takk — gå videre til påmeldingen hos arrangøren →</a></p>
</section>
</main>
</body>
</html>
