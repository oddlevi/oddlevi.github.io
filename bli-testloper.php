<?php
// Interesseskjema for testløpere. Sender e-post til treneren — ingenting lagres
// på serveren. Honningkrukke («nettside»-feltet) stopper enkle spam-boter.
$sendt = false;
$feil = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $navn = trim($_POST["navn"] ?? "");
    $epost = trim($_POST["epost"] ?? "");
    $om = trim($_POST["om"] ?? "");
    $tg = trim($_POST["telegram"] ?? "");
    $krukke = trim($_POST["nettside"] ?? "");
    if ($krukke !== "") {
        $sendt = true; // bot — lat som alt gikk bra
    } elseif ($navn === "" || !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
        $feil = "Fyll inn navn og en gyldig e-postadresse.";
    } else {
        $tekst = "Ny testløper-interesse fra treni.no\n\n"
               . "Navn: " . $navn . "\n"
               . "E-post: " . $epost . "\n"
               . ($tg !== "" ? "Telegram: " . $tg . "\n" : "") . "\n"
               . "Om løpingen:\n" . ($om !== "" ? $om : "(ikke utfylt)") . "\n";
        $hode = "From: Treni <hei@treni.no>\r\n"
              . "Reply-To: " . str_replace(["\r", "\n"], "", $epost) . "\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n";
        $epost_ok = mail("hei@treni.no",
                         "=?UTF-8?B?" . base64_encode("Testløper-interesse: " . $navn) . "?=",
                         $tekst, $hode, "-fhei@treni.no");
        $cfg_sti = dirname(__DIR__) . "/dashbord_config.php";
        $konfig = is_readable($cfg_sti) ? (include $cfg_sti) : null;
        // Ventelista (vises i trenerens dashbord) — beste forsøk, stopper aldri skjemaet
        if (is_array($konfig)) {
            try {
                $pdo = new PDO(
                    "mysql:host={$konfig['db_host']};dbname={$konfig['db_name']};charset=utf8mb4",
                    $konfig['db_user'], $konfig['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec("CREATE TABLE IF NOT EXISTS venteliste (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    opprettet TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    navn VARCHAR(120) NOT NULL,
                    epost VARCHAR(190) NOT NULL,
                    telegram VARCHAR(120) NOT NULL DEFAULT '',
                    om TEXT,
                    status VARCHAR(20) NOT NULL DEFAULT 'venter'
                ) CHARACTER SET utf8mb4");
                $pdo->prepare("INSERT INTO venteliste (navn, epost, telegram, om) VALUES (?,?,?,?)")
                    ->execute([$navn, $epost, $tg, $om]);
            } catch (Throwable $e) { /* varsling under går uansett */ }
        }
        // Telegram til treneren — garantert kanal uansett e-postlevering
        $tg_ok = false;
        if ($konfig !== null) {
            if (defined("TRENI_BOT_TOKEN")) {
                $svar = @file_get_contents(
                    "https://api.telegram.org/bot" . TRENI_BOT_TOKEN . "/sendMessage",
                    false,
                    stream_context_create(["http" => [
                        "method" => "POST",
                        "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
                        "content" => http_build_query([
                            "chat_id" => TRENI_TRENER_CHAT,
                            "text" => "🏃 " . $tekst]),
                        "timeout" => 8]]));
                $tg_ok = $svar !== false && strpos($svar, '"ok":true') !== false;
            }
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
<title>Bli testløper — Treni</title>
<meta name="description" content="Meld interesse for å bli testløper hos Treni — trenerledet løpsveiledning bygget på dine egne Strava-data.">
<meta name="robots" content="noindex">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🏃</text></svg>">
<link rel="stylesheet" href="stil.css?v=6">
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<main>

<header class="hero smal">
  <p class="kicker reveal"><a href="index.html" style="color:inherit">treni.no</a> · bli testløper</p>
  <h1 class="reveal" style="font-size:clamp(2rem,6vw,3rem)">Bli testløper</h1>
</header>

<?php if ($sendt): ?>
<section>
  <div class="kort">
    <h3 style="margin-top:0">Takk<?php if (!empty($navn)) echo ", " . htmlspecialchars(explode(" ", $navn)[0]); ?>! 🏃</h3>
    <p>Du står nå på lista. Vi tar inn noen få testløpere om gangen, så treneren
    faktisk rekker å se hver enkelt — når det åpner seg en plass, får du en
    <b>personlig invitasjon</b> fra treneren med Telegram-lenken som starter
    oppstarten din.</p>
    <p style="margin-bottom:0"><a href="index.html">← Tilbake til forsiden</a></p>
  </div>
</section>
<?php else: ?>
<section>
  <p>Vi tar inn noen få testløpere om gangen, så treneren faktisk rekker å se deg.
  Fortell kort om deg selv, så tar vi kontakt.</p>
  <?php if ($feil): ?><p class="skjema-feil"><?php echo htmlspecialchars($feil); ?></p><?php endif; ?>
  <form method="post" action="bli-testloper.php" class="skjema">
    <label>Navn
      <input type="text" name="navn" required autocomplete="name"
             value="<?php echo htmlspecialchars($_POST["navn"] ?? ""); ?>">
    </label>
    <label>E-post
      <input type="email" name="epost" required autocomplete="email"
             value="<?php echo htmlspecialchars($_POST["epost"] ?? ""); ?>">
    </label>
    <label>Telegram <span class="valgfritt">(valgfritt — brukernavn eller mobilnummeret Telegram-kontoen din bruker, så kan invitasjonen komme dit)</span>
      <input type="text" name="telegram" autocomplete="tel" placeholder="@brukernavn eller mobilnummer"
             value="<?php echo htmlspecialchars($_POST["telegram"] ?? ""); ?>">
    </label>
    <label>Litt om løpingen din <span class="valgfritt">(valgfritt — f.eks. hvor mye du løper, og hva du vil oppnå)</span>
      <textarea name="om" rows="4"><?php echo htmlspecialchars($_POST["om"] ?? ""); ?></textarea>
    </label>
    <label class="krukke" aria-hidden="true">Nettside
      <input type="text" name="nettside" tabindex="-1" autocomplete="off">
    </label>
    <button type="submit" class="btn btn-primar">Meld interesse</button>
  </form>
  <p class="liten" style="margin-top:1.5rem">Foretrekker du e-post? Skriv direkte til
  <a href="mailto:hei@treni.no?subject=Jeg%20vil%20teste%20Treni">hei@treni.no</a>.
  Opplysningene brukes kun til å svare deg —
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
