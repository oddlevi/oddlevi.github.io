<?php declare(strict_types=1); // Odd 13.09: «broren min forsto ikke hva produktet er». Én side, tre setninger, ett bilde.
// Odd 13.09 kl. 02:5x: «Før de kommer videre må vi be om mobilnummer». Porten: mobilnummer → hei@treni.no + logg → cookie → /arrangor/
require_once dirname(__DIR__) . "/spamvern.php";
$feil = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    treni_begrens_post(["mobil" => 30, "navn" => 120, "nettside" => 200]);
    $mobil = preg_replace('/[^0-9+]/', '', (string) ($_POST["mobil"] ?? ""));
    $navn = treni_ren($_POST["navn"] ?? "", 120);
    if (($_POST["nettside"] ?? "") !== "") { $feil = "Noe gikk galt."; }                 // honningkrukke
    elseif (treni_rategrense("td_mobil_" . treni_ip(), 8, 3600)) { $feil = "For mange forsøk. Prøv igjen om en time."; }
    elseif (!preg_match('/^(\+47|0047)?[49]\d{7}$/', $mobil)) { $feil = "Skriv et norsk mobilnummer, åtte siffer."; }
    else {
        $rad = ["ts" => date("c"), "type" => "mobil_port", "navn" => $navn, "mobil" => $mobil, "ip" => treni_ip(), "ref" => (string) ($_SERVER["HTTP_REFERER"] ?? "")];
        @file_put_contents(dirname(__DIR__, 2) . "/arrangor_henvendelser.jsonl", json_encode($rad, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        $kropp = "Ny arrangør-interesse (treni.no/direkte)\n\nNavn: " . ($navn !== "" ? $navn : "(ikke oppgitt)") . "\nMobil: $mobil\n\nPersonen gikk videre til treni.no/arrangor.\n";
        $ok = false;
        try { require_once dirname(__DIR__, 2) . "/epost_smtp.php"; if (function_exists("treni_epost_send")) { $ok = treni_epost_send("hei@treni.no", "Arrangør-interesse: $mobil", $kropp); } } catch (Throwable $e) { $ok = false; }
        if (!$ok) { @mail("hei@treni.no", "Arrangør-interesse: $mobil", $kropp, "From: hei@treni.no"); }
        // Odd 13.09: også til Odd på Telegram, samme rør som bli-testloper.php
        try {
            $cfg_sti = dirname(__DIR__, 2) . "/dashbord_config.php";
            $konfig = is_readable($cfg_sti) ? (include $cfg_sti) : null;
            if (defined("TRENI_BOT_TOKEN") && defined("TRENI_ODD_CHAT")) {
                @file_get_contents("https://api.telegram.org/bot" . TRENI_BOT_TOKEN . "/sendMessage", false, stream_context_create(["http" => [
                    "method" => "POST", "header" => "Content-Type: application/x-www-form-urlencoded\r\n", "timeout" => 8,
                    "content" => http_build_query(["chat_id" => TRENI_ODD_CHAT,
                        "text" => "📞 Arrangør-interesse fra treni.no/direkte\n\nNavn: " . ($navn !== "" ? $navn : "(ikke oppgitt)") . "\nMobil: " . $mobil . "\n\nGikk videre til arrangørstegene."])]]));
            }
        } catch (Throwable $e) { /* Telegram er tillegg, e-post og logg er alt sendt */ }
        setcookie("td_port", "1", ["expires" => time() + 180 * 86400, "path" => "/", "secure" => true, "httponly" => true, "samesite" => "Lax"]);
        header("Location: /arrangor/"); exit;
    }
}
if (isset($_GET["nullstill"])) {   // fjerner merket i nettleseren, så porten vises igjen (Odd 13.09)
    setcookie("td_port", "", ["expires" => time() - 3600, "path" => "/", "secure" => true, "httponly" => true, "samesite" => "Lax"]);
    header("Location: /direkte/#port"); exit;
}
$har_port = !empty($_COOKIE["td_port"]);
?>
<!DOCTYPE html>
<html lang="nb">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Treni Direkte · følg løperne live</title>
<meta name="description" content="Treni Direkte er en side der publikum følger løperne live på mobilen mens løpet pågår, laget for arrangøren.">
<meta property="og:title" content="For arrangører: liveoppdatering fra løpet ditt">
<meta property="og:site_name" content="treni.no">
<meta property="og:description" content="Treni Direkte er en side der publikum følger løperne live på mobilen mens løpet pågår, laget for arrangøren.">
<meta property="og:image" content="https://treni.no/direkte/og.jpg?v=1">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:url" content="https://treni.no/direkte/">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="stylesheet" href="/stil.css?v=30">
<style>
  .enkel { max-width: 40rem; margin: 0 auto; }
  .stor { font-family: var(--font-serif); font-size: clamp(1.35rem, 4.6vw, 1.9rem); line-height: 1.3; margin: 0 0 1.1rem; text-wrap: balance; }
  .tre { display: grid; gap: .8rem; margin: 1.4rem 0; }
  .tre div { background: hsl(var(--surface)); border: 1px solid hsl(var(--border)); border-radius: 14px; padding: 1rem 1.1rem; }
  .tre b { display: block; font-size: .78rem; letter-spacing: .08em; text-transform: uppercase; color: hsl(var(--accent)); margin-bottom: .3rem; }
  .tre p { margin: 0; font-size: 1.02rem; line-height: 1.5; }
  .skjerm { display: block; width: min(100%, 280px); max-height: 300px; object-fit: cover; object-position: top; margin: 1.2rem auto 0; border-radius: 14px; border: 1px solid hsl(var(--border)); box-shadow: var(--shadow-md); }
  .knapp { display: block; text-align: center; margin: 1.6rem auto 0; max-width: 28rem; padding: 1rem 1.4rem; border-radius: 999px; background: var(--gradient-cta); color: hsl(var(--primary-fg)); font-weight: 700; font-size: 1.1rem; text-decoration: none; box-shadow: var(--shadow-cta); }
  .liten { text-align: center; margin: .8rem 0 0; font-size: .9rem; color: hsl(var(--muted-fg)); }
  .port { margin-top: 1.4rem; padding: 1.1rem 1.2rem 1rem; border-radius: 16px; background: hsl(var(--surface)); border: 2px solid hsl(var(--primary)); }
  .port label { display: block; font-weight: 600; margin: .7rem 0 .3rem; }
  .port input { width: 100%; padding: .8rem .9rem; border: 1px solid hsl(var(--border)); border-radius: 10px; background: hsl(var(--bg)); color: hsl(var(--fg)); font: inherit; font-size: 1.05rem; }
  .port .knapp { margin-top: 1rem; }
  .feil { background: hsl(0 70% 95%); color: hsl(0 60% 30%); padding: .7rem 1rem; border-radius: 8px; margin: 0 0 .6rem; }
  .def { padding: 1.1rem 1.2rem; border-radius: 16px; background: hsl(var(--surface)); border-left: 5px solid hsl(var(--accent)); box-shadow: var(--shadow-sm); }
  .def .stor { color: hsl(var(--primary)); }
  .svar { font-size: 1.12rem; line-height: 1.5; margin: 0 0 1.1rem; padding: .9rem 1rem; border-left: 4px solid hsl(var(--accent)); background: hsl(var(--surface)); border-radius: 8px; }
  .svar b { display: inline; color: hsl(var(--primary)); }
  .problem, .losning { border-radius: 16px; padding: 1.2rem 1.2rem 1rem; margin-bottom: 1rem; }
  .problem { background: hsl(var(--surface-2)); border: 1px solid hsl(var(--border)); }
  .losning { background: hsl(var(--surface)); border: 2px solid hsl(var(--primary)); padding-bottom: 1.1rem; }
  .problem > b, .losning > b { display: block; font-size: .78rem; letter-spacing: .08em; text-transform: uppercase; color: hsl(var(--accent)); margin-bottom: .5rem; }
  .problem p, .losning p { margin: 0 0 .6rem; font-size: 1.05rem; line-height: 1.55; }
  .idag { list-style: none; margin: .2rem 0 .2rem; padding: 0; display: grid; gap: .55rem; }
  .idag li { display: flex; gap: .7rem; align-items: flex-start; background: none; border: none; padding: 0; box-shadow: none; }
  .idag li span { flex: none; width: 2rem; text-align: center; font-size: 1.3rem; line-height: 1.4; }
  .idag li div { font-size: 1rem; line-height: 1.5; color: hsl(var(--fg)); } .idag li div b { display: inline; }
  .slik { list-style: none; margin: .4rem 0 0; padding: 0; display: grid; gap: .6rem; }
  .slik li { display: flex; gap: .8rem; align-items: flex-start; background: none; border: none; padding: 0; box-shadow: none; }
  .slik li span { flex: none; width: 2rem; height: 2rem; border-radius: 50%; background: hsl(var(--primary)); color: hsl(var(--primary-fg)); font-weight: 800; display: flex; align-items: center; justify-content: center; }
  .slik li div { font-size: 1.02rem; line-height: 1.5; } .slik li div b { display: inline; }
</style>
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<main>
<header class="hero smal" style="padding-bottom:.2rem">
  <p class="kicker"><a href="/" style="color:inherit">treni.no</a> · Hva er Treni Direkte?</p>
  <h1 style="font-size:clamp(1.8rem,6vw,2.8rem)">Følg løperne live, mens løpet pågår.</h1>
</header>
<section class="enkel">
  <div class="def">
    <p class="stor" style="margin:0">Treni Direkte er en side der publikum følger løperne live på mobilen mens løpet pågår, laget for arrangøren.</p>
  </div>
  <p class="kicker" style="margin:1.4rem 0 .3rem">Hvorfor</p>
  <p style="margin:0 0 1.1rem;font-size:1.05rem;line-height:1.5">Noen du er glad i løper et løp. Du vil følge med. I dag må du vente på resultatlista, eller lete blant hundrevis av navn hos tidtakeren.</p>
  <p class="kicker" style="margin:1.4rem 0 .3rem">Første løp</p>
  <p style="margin:0 0 1.1rem;font-size:1.05rem;line-height:1.5">Terrengløpet Skjervøy 20:1000 fikk livesiden 12. september 2026. 227 personer fulgte med. 1 288 søk etter løpere. 95 prosent på mobil.</p>
  <?php if ($har_port): ?>
  <a class="knapp" href="/arrangor/">Arrangerer du et løp? Slik gjør vi det →</a>
  <?php else: ?>
  <form method="post" action="/direkte/" class="port" id="port">
    <p class="kicker" style="margin:0 0 .4rem">Arrangerer du et løp?</p>
    <p style="margin:0 0 .8rem;font-size:1.02rem;line-height:1.5">Legg igjen mobilnummeret ditt, så viser vi deg hvordan vi gjør det, steg for steg.</p>
    <?php if ($feil !== ""): ?><p class="feil"><?= htmlspecialchars($feil) ?></p><?php endif; ?>
    <label for="mobil">Mobilnummer</label>
    <input id="mobil" name="mobil" type="tel" inputmode="tel" autocomplete="tel" required placeholder="F.eks. 900 00 000" value="<?= htmlspecialchars($_POST["mobil"] ?? "") ?>">
    <label for="navn">Navn <span style="font-weight:400;color:hsl(var(--muted-fg))">(valgfritt)</span></label>
    <input id="navn" name="navn" autocomplete="name" value="<?= htmlspecialchars($_POST["navn"] ?? "") ?>">
    <input name="nettside" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
    <button type="submit" class="knapp" style="border:none;cursor:pointer;width:100%;font:inherit;font-weight:700;font-size:1.1rem">Slik gjør vi det →</button>
    <p class="liten" style="margin-top:.6rem">Vi bruker nummeret bare til å ta kontakt om løpet ditt. <a href="/personvern.html">Personvern</a>.</p>
  </form>
  <?php endif; ?>
  <p class="liten">Er du løper? <a href="/bli-testloper.php">Få løpeplan hver uke, sett over av en trener ›</a></p>
</section>
</main>
</body>
</html>
