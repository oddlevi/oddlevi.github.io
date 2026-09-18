<?php
// Samtykke til å være med i Trenis innhold (A-40, revisjon 10.09, bygget 11.09.2026).
// Løpere som vil nevnes med navn, bilde eller sitat i innlegg og storyer sier ja her.
// Lagres i MySQL (innhold_samtykke) og varsles til hei@treni.no. Trekkes tilbake ved
// e-post til hei@treni.no, se personvern.html.
require_once __DIR__ . "/spamvern.php";
$sendt = false; $feil = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    treni_begrens_post(["navn" => 120, "epost" => 190, "kommentar" => 1000]);
    $navn = treni_ren($_POST["navn"] ?? "", 120);
    $epost = mb_strtolower(treni_ren($_POST["epost"] ?? "", 190));
    $kommentar = treni_ren($_POST["kommentar"] ?? "", 1000, true);
    $valg = array_values(array_intersect(["navn", "bilde", "sitat", "resultat"], (array) ($_POST["hva"] ?? [])));
    if ($navn === "" || !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
        $feil = "Fyll inn navn og en gyldig e-postadresse.";
    } elseif (!$valg) {
        $feil = "Kryss av for minst én ting du sier ja til.";
    } elseif (empty($_POST["bekreft"])) {
        $feil = "Du må bekrefte at du har lest hva samtykket betyr.";
    } elseif (($vern = treni_spamvern_ok()) !== "") {
        $feil = $vern;
    } else {
        $cfg_sti = dirname(__DIR__) . "/dashbord_config.php";
        $konfig = is_readable($cfg_sti) ? (include $cfg_sti) : null;
        $lagret = false;
        if (is_array($konfig)) {
            try {
                $pdo = new PDO("mysql:host=" . $konfig["db_host"] . ";dbname=" . $konfig["db_name"] . ";charset=utf8mb4",
                               $konfig["db_user"], $konfig["db_pass"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec("CREATE TABLE IF NOT EXISTS innhold_samtykke (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    opprettet DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    navn VARCHAR(120) NOT NULL, epost VARCHAR(190) NOT NULL,
                    hva VARCHAR(80) NOT NULL, kommentar TEXT,
                    ip VARCHAR(64) NOT NULL DEFAULT '', trukket DATETIME NULL
                ) CHARACTER SET utf8mb4");
                $pdo->prepare("INSERT INTO innhold_samtykke (navn, epost, hva, kommentar, ip) VALUES (?,?,?,?,?)")
                    ->execute([$navn, $epost, implode(",", $valg), $kommentar, treni_ip()]);
                $lagret = true;
            } catch (Throwable $e) { $lagret = false; }
        }
        require_once __DIR__ . "/epost_smtp.php";
        $hva_tekst = implode(", ", array_map(fn($v) => ["navn" => "navn", "bilde" => "bilde", "sitat" => "sitat", "resultat" => "løpsresultat"][$v], $valg));
        $ok1 = treni_epost_send("hei@treni.no", "📸 Innholdssamtykke: " . $navn,
            "Navn: " . $navn . "\nE-post: " . $epost . "\nSier ja til: " . $hva_tekst . "\n"
            . ($kommentar !== "" ? "Kommentar: " . $kommentar . "\n" : "")
            . "Lagret i databasen: " . ($lagret ? "ja" : "NEI, bare denne e-posten") . "\n" . date("Y-m-d H:i"));
        treni_epost_send($epost, "Takk, samtykket ditt er registrert hos Treni",
            "Hei " . $navn . "!\n\nDu har sagt ja til at Treni kan bruke: " . $hva_tekst . " i innlegg og storyer på Instagram og Facebook.\n\n"
            . "Vi spør deg alltid før et konkret innlegg går ut, og du kan trekke samtykket når som helst ved å svare på denne e-posten.\n\n"
            . "Hilsen Odd Levi og Eirik i Treni\nhei@treni.no");
        $sendt = $ok1 || $lagret;
        if (!$sendt) { $feil = "Noe gikk galt hos oss. Send gjerne en e-post til hei@treni.no i stedet."; }
    }
}
?>
<!DOCTYPE html>
<html lang="no">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bli med i innholdet. Treni</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="stylesheet" href="stil.css?v=30">
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<main class="skjema-side" style="max-width:640px; margin:0 auto; padding:2rem 1.2rem">
  <h1>Vil du være med i innholdet vårt?</h1>
  <p>Vi deler ekte løpere og ekte økter på Instagram og Facebook: «fire av våre går Skjervøy», bilder fra fellesøkter, sitater fra
  det dere skriver til oss. Ingen nevnes uten å ha sagt ja først. Her sier du ja på forhånd, og vi spør deg likevel før hvert
  konkrete innlegg.</p>
  <?php if ($sendt): ?>
    <p class="ok" style="padding:1rem; border-radius:12px; background:rgba(120,200,120,.15)"><b>Takk, <?= htmlspecialchars($navn) ?>!</b> Samtykket er registrert, og du får en bekreftelse på e-post. Du kan trekke det når som helst ved å skrive til hei@treni.no.</p>
  <?php else: ?>
  <?php if ($feil): ?><p class="feil" style="padding:.8rem 1rem; border-radius:12px; background:rgba(220,80,80,.15)"><?= htmlspecialchars($feil) ?></p><?php endif; ?>
  <form method="post" class="skjema">
    <label>Navn <input type="text" name="navn" required autocomplete="name" value="<?= htmlspecialchars($_POST["navn"] ?? "") ?>"></label>
    <label>E-post <input type="email" name="epost" required autocomplete="email" value="<?= htmlspecialchars($_POST["epost"] ?? "") ?>"></label>
    <fieldset class="sprak-felt">
      <legend>Jeg sier ja til at Treni kan bruke</legend>
      <?php $v = (array) ($_POST["hva"] ?? []); foreach (["navn" => "fornavnet mitt (aldri etternavn uten at jeg sier det)", "bilde" => "bilder av meg fra økter og løp", "sitat" => "sitater fra det jeg skriver til treneren (anonymisert hvis jeg ber om det)", "resultat" => "løpsresultatene mine (løp, tid, plassering)"] as $k => $t): ?>
      <label class="radio"><input type="checkbox" name="hva[]" value="<?= $k ?>" <?= in_array($k, $v, true) ? "checked" : "" ?>> <?= $t ?></label>
      <?php endforeach; ?>
    </fieldset>
    <label>Kommentar <span class="valgfritt">(valgfritt: noe vi skal vite, for eksempel «ikke bilder av barna mine»)</span>
      <textarea name="kommentar" rows="3"><?= htmlspecialchars($_POST["kommentar"] ?? "") ?></textarea></label>
    <label class="radio"><input type="checkbox" name="bekreft" value="1" required> Jeg har lest dette: samtykket gjelder Trenis egne kanaler, vi spør før hvert innlegg, og jeg kan trekke det når som helst ved å skrive til hei@treni.no. Se <a href="personvern.html">personvernerklæringen</a>.</label>
    <?php treni_spamvern_felt(); ?>
    <button type="submit" class="btn">Ja, jeg er med</button>
  </form>
  <?php endif; ?>
</main>
</body>
</html>
