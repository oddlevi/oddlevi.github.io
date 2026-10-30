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
// Knappene til butikkene, som på forsida. Klikket logges i treni.no/g med kanalen «lop-videre».
// Odd 30.10.2026: samme profil og design som forsida (ny/side.css). «Gratis i testperioden» og
// «ingen betaling» er tatt ut – Treni koster etter prøvemåneden.
function butikkknapper8(string $k): string {
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
<meta name="robots" content="noindex,nofollow">
<title><?= $lop ? e9($lop) . ' · ' : '' ?>tren riktig mot løpet | Treni</title>
<link rel="icon" href="/favicon.ico">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;800&display=swap">
<link rel="stylesheet" href="/ny/side.css?v=1">
</head>
<body>

<header><div class="c topp">
  <a class="logo" href="/" aria-label="Treni">Tren<i aria-hidden="true">ı</i></a>
  <nav class="meny" aria-label="Hovedmeny"><a href="/lop.php">Løpskalender</a><a href="/lopegrupper.php">Løpegrupper</a></nav>
</div></header>

<main>
<section class="c sidehode">
  <a class="etikett" href="/lop.php" style="text-decoration:none">← Løpskalenderen</a>
  <h1><?= $lop ? e9($lop) : 'Løpet ditt' ?><?= $dato_pen ? '<em> · ' . e9($dato_pen) . '</em>' : '' ?></h1>
</section>

<section class="c">
  <div class="treni-boks" style="margin-top:0">
    <div>
      <b>Skal Treni lage uka di mot <?= $lop ? e9($lop) : 'løpet' ?>?</b>
      <p>Treni lager uka di ut fra det du faktisk løper, og bygger den på nytt når livet skjer. Én måned gratis.
      Du kan melde deg på løpet etterpå.</p>
    </div>
    <?= butikkknapper8('lop-videre') ?>
  </div>
  <p style="margin:28px 0 72px">
    <a class="k kant" href="<?= e9($til) ?>" target="_blank" rel="noopener nofollow">Nei takk, gå videre til påmeldingen hos arrangøren →</a></p>
</section>
</main>

<footer><div class="c">
  <span>Treni · <a href="mailto:hei@treni.no">hei@treni.no</a></span>
  <nav aria-label="Bunnmeny"><a href="/">Forsiden</a><a href="/lop.php">Løpskalender</a><a href="/lopegrupper.php">Løpegrupper</a><a href="/personvern.html">Personvern</a></nav>
</div></footer>
</body>
</html>
