<?php
declare(strict_types=1);
// Løpegrupper og arrangører (lenket fra forsida). Skrevet om 29.10.2026 (Odd 17:09 «6 skriv om», T2-63 fase H): den gamle
// stegsida «Treni Direkte» var fra Treni 1-tiden (min side, «trener i loopen», analyse i chatten) og lå bak mobilnummer-porten
// på /direkte/. Det er uklart om det tilbudet står, så sida er kort og nøytral: hva Treni er, og et skjema som går til
// hei@treni.no (samme rør som før). Den gamle teksten ligger i git-historikken (b146e2d).
require_once dirname(__DIR__) . "/spamvern.php";
$sendt = false; $feil = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    treni_begrens_post(["navn" => 120, "lop" => 160, "dato" => 40, "epost" => 190, "melding" => 1500]);
    $sv = treni_spamvern_ok();
    $navn = treni_ren($_POST["navn"] ?? "", 120); $lop = treni_ren($_POST["lop"] ?? "", 160);
    $dato = treni_ren($_POST["dato"] ?? "", 40); $epost = treni_ren($_POST["epost"] ?? "", 190);
    $melding = treni_ren($_POST["melding"] ?? "", 1500, true);
    if ($sv !== "") { $feil = $sv; }
    elseif ($navn === "" || $lop === "" || !filter_var($epost, FILTER_VALIDATE_EMAIL)) { $feil = "Fyll inn navn, gruppe eller løp, og en gyldig e-postadresse."; }
    else {
        $rad = ["ts" => date("c"), "navn" => $navn, "lop" => $lop, "dato" => $dato, "epost" => $epost, "melding" => $melding, "ip" => treni_ip()];
        @file_put_contents(dirname(__DIR__, 2) . "/arrangor_henvendelser.jsonl", json_encode($rad, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        $kropp = "Ny henvendelse fra løpegruppe eller arrangør (treni.no/arrangor)\n\nNavn: $navn\nGruppe eller løp: $lop\nDato: $dato\nE-post: $epost\n\n$melding\n";
        $ok = false;
        try { require_once dirname(__DIR__, 2) . "/epost_smtp.php"; if (function_exists("treni_epost_send")) { $ok = treni_epost_send("hei@treni.no", "Løpegruppe/arrangør: $lop", $kropp, $epost); } } catch (Throwable $e) { $ok = false; }
        if (!$ok) { @mail("hei@treni.no", "Løpegruppe/arrangør: $lop", $kropp, "From: hei@treni.no\r\nReply-To: " . $epost); }
        $sendt = true;
    }
}
?><!doctype html>
<html lang="nb">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Løpegrupper og arrangører · Treni</title>
<meta name="description" content="Har du en løpegruppe, en klubb eller et løp og vil samarbeide med Treni? Fortell oss om det.">
<link rel="icon" href="/favicon.ico">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;800&display=swap">
<style>
/* Trenis egne tokens, som forsida (treni2/design/tokens.css). */
:root{
  --bg:hsl(44 45% 97%);--surface:hsl(0 0% 100%);--fg:hsl(160 28% 9%);--muted:hsl(158 10% 36%);
  --border:hsl(148 15% 84%);--primary:hsl(152 62% 20%);--primary-fg:hsl(44 45% 97%);--accent:hsl(84 70% 52%);
  --rod:hsl(8 70% 44%);--rod-soft:hsl(8 70% 94%);--r:12px;
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --bg:hsl(162 24% 5%);--surface:hsl(160 18% 9%);--fg:hsl(42 30% 94%);--muted:hsl(150 8% 62%);
  --border:hsl(156 12% 17%);--primary:hsl(84 70% 52%);--primary-fg:hsl(162 24% 5%);--rod:hsl(8 80% 60%);--rod-soft:hsl(8 40% 16%);color-scheme:dark}}
*{box-sizing:border-box}
body{margin:0;font-family:Manrope,system-ui,sans-serif;background:var(--bg);color:var(--fg);line-height:1.55}
.c{max-width:40rem;margin:0 auto;padding:0 16px}
header .c{display:flex;align-items:center;justify-content:space-between;padding-block:20px}
.logo{font-weight:800;font-size:1.5rem;color:var(--fg);text-decoration:none;letter-spacing:-.02em}
.logo i{font-style:normal;color:var(--accent)}
.etikett{font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
h1{font-size:clamp(1.9rem,6vw,2.6rem);line-height:1.15;margin:12px 0 16px;letter-spacing:-.02em}
.lead{font-size:1.1rem;color:var(--muted);margin:0 0 12px}
form{margin-top:28px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:20px}
label{display:block;font-weight:600;margin:14px 0 6px}
label:first-child{margin-top:0}
input,textarea{width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:10px;background:var(--bg);color:var(--fg);font:inherit}
.k{display:inline-block;margin-top:18px;padding:12px 22px;border:0;border-radius:999px;background:var(--primary);color:var(--primary-fg);font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
.feil{background:var(--rod-soft);color:var(--rod);padding:10px 14px;border-radius:8px}
.liten{font-size:.85rem;color:var(--muted)}
a{color:var(--primary)}
footer .c{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;padding-block:32px;font-size:.9rem;color:var(--muted)}
</style>
</head>
<body>
<header><div class="c"><a class="logo" href="/" aria-label="Treni">Tren<i aria-hidden="true">ı</i></a><a href="/">Til forsida</a></div></header>
<main class="c">
<?php if ($sendt): ?>
  <div class="etikett">Løpegrupper og arrangører</div>
  <h1>Takk, <?= htmlspecialchars(explode(" ", $navn)[0]) ?>!</h1>
  <p class="lead">Vi har fått meldingen om <b><?= htmlspecialchars($lop) ?></b>. Odd Levi svarer deg på e-post.</p>
  <p><a href="/">Til forsida</a></p>
<?php else: ?>
  <div class="etikett">Løpegrupper og arrangører</div>
  <h1>Vil du samarbeide med Treni?</h1>
  <p class="lead">Treni er en treningsveileder for løpere. Appen lager uka di ut fra det du faktisk løper, og bygger den på nytt når livet skjer – med reglene til trener Eirik Haugsnes.</p>
  <p>Har du en løpegruppe, en klubb eller et løp, og vil høre hva vi kan få til sammen? Fortell oss litt om det, så tar vi en prat.</p>
  <?php if ($feil !== ""): ?><p class="feil"><?= htmlspecialchars($feil) ?></p><?php endif; ?>
  <form method="post" action="/arrangor/">
    <label for="navn">Navnet ditt</label><input id="navn" name="navn" required autocomplete="name" value="<?= htmlspecialchars($_POST["navn"] ?? "") ?>">
    <label for="lop">Løpegruppe, klubb eller løp</label><input id="lop" name="lop" required placeholder="F.eks. Tromsø Fjellmaraton" value="<?= htmlspecialchars($_POST["lop"] ?? "") ?>">
    <label for="dato">Dato for løpet (om det gjelder et løp)</label><input id="dato" name="dato" placeholder="F.eks. 3. oktober 2027" value="<?= htmlspecialchars($_POST["dato"] ?? "") ?>">
    <label for="epost">E-post</label><input id="epost" name="epost" type="email" required autocomplete="email" value="<?= htmlspecialchars($_POST["epost"] ?? "") ?>">
    <label for="melding">Noe vi bør vite?</label><textarea id="melding" name="melding" rows="4"><?= htmlspecialchars($_POST["melding"] ?? "") ?></textarea>
    <div style="margin-top:14px"><?php treni_spamvern_felt(); ?></div>
    <button type="submit" class="k">Send</button>
  </form>
  <p class="liten" style="margin-top:14px">Vi bruker opplysningene bare til å svare deg. Du kan også skrive til <a href="mailto:hei@treni.no">hei@treni.no</a>.</p>
<?php endif; ?>
</main>
<footer><div class="c"><span>Treni · <a href="mailto:hei@treni.no">hei@treni.no</a></span><span><a href="/personvern.html">Personvern</a> · <a href="/vilkar.html">Vilkår</a></span></div></footer>
</body>
</html>
