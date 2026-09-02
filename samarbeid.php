<?php
require_once __DIR__ . "/spamvern.php";
// Samarbeidsskjema for løpegrupper, klubber og arrangører. Lagres i
// samarbeid-tabellen (vises i trenerens dashbord, fanen «Samarbeid») og
// varsles på e-post + Telegram. Honningkrukke («nettside») stopper boter.
$sendt = false;
$feil = "";
$TYPER = [
    "lopegruppe" => "Løpegruppe eller klubb",
    "arrangor"   => "Løpsarrangør",
    "annet"      => "Annet",
];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $navn = trim($_POST["navn"] ?? "");
    $org = trim($_POST["organisasjon"] ?? "");
    $type = array_key_exists($_POST["type"] ?? "", $TYPER) ? $_POST["type"] : "annet";
    $epost = trim($_POST["epost"] ?? "");
    $mobil = trim($_POST["mobil"] ?? "");
    $melding = trim($_POST["melding"] ?? "");
    $krukke = trim($_POST["nettside"] ?? "");
    if ($krukke !== "") {
        $sendt = true; // bot — lat som alt gikk bra
    } elseif (($vern = treni_spamvern_ok()) !== "") {
        $feil = $vern;
    } elseif ($navn === "" || $org === "" || !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
        $feil = "Fyll inn navn, organisasjon og en gyldig e-postadresse.";
    } else {
        $tekst = "Ny samarbeidshenvendelse fra treni.no\n\n"
               . "Kontaktperson: " . $navn . "\n"
               . "Organisasjon: " . $org . "\n"
               . "Type: " . $TYPER[$type] . "\n"
               . "E-post: " . $epost . "\n"
               . ($mobil !== "" ? "Mobil: " . $mobil . "\n" : "")
               . "\nHva de ser for seg:\n" . ($melding !== "" ? $melding : "(ikke utfylt)") . "\n";
        // Gmail-røret (Odds beslutning 20.08): DKIM-signert, innboks + Sendt-arkiv.
        require_once dirname(__DIR__) . "/epost_smtp.php";
        $epost_ok = treni_epost_send("hei@treni.no", "Samarbeid: " . $org, $tekst);
        if (!$epost_ok) {   // nødfallback: usignert server-mail (kan spamme)
            $hode = "From: Treni <hei@treni.no>\r\n"
                  . "Reply-To: " . str_replace(["\r", "\n"], "", $epost) . "\r\n"
                  . "Content-Type: text/plain; charset=UTF-8\r\n";
            $epost_ok = mail("hei@treni.no",
                             "=?UTF-8?B?" . base64_encode("Samarbeid: " . $org) . "?=",
                             $tekst, $hode, "-fhei@treni.no");
        }
        $cfg_sti = dirname(__DIR__) . "/dashbord_config.php";
        $konfig = is_readable($cfg_sti) ? (include $cfg_sti) : null;
        // Dashbord-oversikten (fanen «Samarbeid») — beste forsøk, stopper aldri skjemaet
        if (is_array($konfig)) {
            try {
                $pdo = new PDO(
                    "mysql:host={$konfig['db_host']};dbname={$konfig['db_name']};charset=utf8mb4",
                    $konfig['db_user'], $konfig['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec("CREATE TABLE IF NOT EXISTS samarbeid (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    opprettet TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    navn VARCHAR(120) NOT NULL,
                    organisasjon VARCHAR(160) NOT NULL,
                    type VARCHAR(20) NOT NULL DEFAULT 'annet',
                    epost VARCHAR(190) NOT NULL,
                    mobil VARCHAR(32) NOT NULL DEFAULT '',
                    melding TEXT,
                    status VARCHAR(20) NOT NULL DEFAULT 'ny'
                ) CHARACTER SET utf8mb4");
                $pdo->prepare("INSERT INTO samarbeid (navn, organisasjon, type, epost, mobil, melding)
                               VALUES (?,?,?,?,?,?)")
                    ->execute([$navn, $org, $type, $epost, $mobil, $melding]);
            } catch (Throwable $e) { /* varsling under går uansett */ }
        }
        // Telegram til treneren — garantert kanal uansett e-postlevering
        $tg_ok = false;
        if ($konfig !== null && defined("TRENI_BOT_TOKEN")) {
            $svar = @file_get_contents(
                "https://api.telegram.org/bot" . TRENI_BOT_TOKEN . "/sendMessage",
                false,
                stream_context_create(["http" => [
                    "method" => "POST",
                    "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
                    "content" => http_build_query([
                        "chat_id" => TRENI_TRENER_CHAT,
                        "text" => "🤝 " . $tekst]),
                    "timeout" => 8]]));
            $tg_ok = $svar !== false && strpos($svar, '"ok":true') !== false;
        }
        $sendt = $epost_ok || $tg_ok;
        if (!$sendt) {
            $feil = "Noe gikk galt hos oss. Send gjerne en e-post direkte i stedet.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="no">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Samarbeid med Treni</title>
<meta name="description" content="Løpegrupper, klubber og arrangører: fortell hva dere ser for dere, så finner vi formen sammen.">
<meta name="robots" content="noindex">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🤝</text></svg>">
<link rel="stylesheet" href="stil.css?v=18">
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<main>

<header class="hero smal">
  <p class="kicker reveal"><a href="index.html" style="color:inherit">treni.no</a> · samarbeid</p>
  <h1 class="reveal" style="font-size:clamp(1.8rem,5vw,2.7rem)">Vi bygger det sammen —<br>og hjelper dine løpere</h1>
</header>

<?php if ($sendt): ?>
<section>
  <div class="kort">
    <h3 style="margin-top:0">Takk<?php if (!empty($navn)) echo ", " . htmlspecialchars(explode(" ", $navn)[0]); ?>! 🤝</h3>
    <p>Henvendelsen er mottatt — vi i Treni tar kontakt på e-post
    <?php if (!empty($mobil)) echo "eller mobil "; ?>innen kort tid, så finner vi
    formen sammen.</p>
    <p style="margin-bottom:0"><a href="index.html">← Tilbake til forsiden</a></p>
  </div>
</section>
<?php else: ?>
<section>
  <div class="to-spalter" style="margin:1.2rem 0 1.4rem">
    <div class="kort">
      <p style="font-size:1.55rem; margin:0" aria-hidden="true">👟</p>
      <h2 style="font-size:1.08rem; margin:.35rem 0 .4rem">Løpegrupper:<br>flere folk på økt</h2>
      <p style="margin:0; font-size:.95rem">Fellesøktene deres legges rett inn i medlemmenes
      ukeplaner — med tid og sted. Slik gjør vi det med Tromsø Løpeklubb i dag.</p>
    </div>
    <div class="kort">
      <p style="font-size:1.55rem; margin:0" aria-hidden="true">🏁</p>
      <h2 style="font-size:1.08rem; margin:.35rem 0 .4rem">Arrangører: deltagere som<br>trener riktig mot ditt løp</h2>
      <p style="margin:0; font-size:.95rem">Løpet legges inn som mål med distanse, høydemeter
      og terreng — og planene topper formen inn mot start.</p>
    </div>
  </div>
  <p>Vi er i pilotfasen, og døra er åpen — fra en enkel avtale om fellesøkter
  til noe større. Skriv et par setninger om hvem dere er og hva dere ønsker,
  så tar vi i Treni kontakt.</p>
  <?php if ($feil): ?><p class="skjema-feil"><?php echo htmlspecialchars($feil); ?></p><?php endif; ?>
  <form method="post" action="samarbeid.php" class="skjema">
    <label>Kontaktperson
      <input type="text" name="navn" required autocomplete="name"
             value="<?php echo htmlspecialchars($_POST["navn"] ?? ""); ?>">
    </label>
    <label>Løpegruppe, klubb eller arrangement
      <input type="text" name="organisasjon" required autocomplete="organization"
             placeholder="f.eks. Tromsø Løpeklubb"
             value="<?php echo htmlspecialchars($_POST["organisasjon"] ?? ""); ?>">
    </label>
    <fieldset class="sprak-felt">
      <legend>Hvem er dere?</legend>
      <?php foreach ($TYPER as $verdi => $navn_t): ?>
      <label class="radio"><input type="radio" name="type" value="<?php echo $verdi; ?>"
        <?php echo ($_POST["type"] ?? "lopegruppe") === $verdi ? "checked" : ""; ?>>
        <?php echo $navn_t; ?></label>
      <?php endforeach; ?>
    </fieldset>
    <label>E-post
      <input type="email" name="epost" required autocomplete="email"
             value="<?php echo htmlspecialchars($_POST["epost"] ?? ""); ?>">
    </label>
    <label>Mobil <span class="valgfritt">(valgfritt)</span>
      <input type="tel" name="mobil" autocomplete="tel" placeholder="f.eks. 900 00 000"
             value="<?php echo htmlspecialchars($_POST["mobil"] ?? ""); ?>">
    </label>
    <label>Hva ser dere for dere? <span class="valgfritt">(valgfritt — faste fellesøkter,
      et løp dere arrangerer, antall medlemmer …)</span>
      <textarea name="melding" rows="4"><?php echo htmlspecialchars($_POST["melding"] ?? ""); ?></textarea>
    </label>
    <label class="krukke" aria-hidden="true">Nettside
      <input type="text" name="nettside" tabindex="-1" autocomplete="off">
    </label>
    <?php treni_spamvern_felt(); ?>
    <button type="submit" class="btn btn-primar">Send henvendelsen</button>
  </form>
  <p class="liten" style="margin-top:1.5rem">Foretrekker du e-post? Skriv direkte til
  <a href="mailto:hei@treni.no?subject=Samarbeid%20med%20Treni">hei@treni.no</a>.
  Opplysningene brukes kun til å svare dere —
  <a href="personvern.html">les personvernerklæringen</a>.</p>
</section>
<?php endif; ?>

<footer>
  <nav aria-label="Bunnmeny">
    <a href="index.html">Hjem</a>
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
