<?php
require_once __DIR__ . "/spamvern.php";
// Samarbeidsskjema for løpegrupper, klubber og arrangører. Lagres i
// samarbeid-tabellen (vises i trenerens dashbord, fanen «Samarbeid») og
// varsles på e-post + Telegram. Honningkrukke («nettside») stopper boter.
// Odd 30.10.2026: nytt innhold i forsidas design (ny/side.css), takk-e-post med lenker til appen til den som skrev.
$sendt = false;
$feil = "";
$TYPER = [
    "lopegruppe" => "Løpegruppe eller klubb",
    "arrangor"   => "Løpsarrangør",
    "annet"      => "Annet",
];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Feltgrenser + rensing (sikkerhetstest 03.09)
    treni_begrens_post(["melding" => 3000, "navn" => 120, "organisasjon" => 160,
                        "epost" => 190, "mobil" => 32]);
    $navn = treni_ren($_POST["navn"] ?? "", 120);
    $org = treni_ren($_POST["organisasjon"] ?? "", 160);
    $type = array_key_exists($_POST["type"] ?? "", $TYPER) ? $_POST["type"] : "annet";
    $epost = treni_ren($_POST["epost"] ?? "", 190);
    $mobil = treni_ren($_POST["mobil"] ?? "", 32);
    $melding = treni_ren($_POST["melding"] ?? "", 3000, true);
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
        $epost_ok = treni_epost_send("hei@treni.no", "Samarbeid: " . $org, $tekst, $epost);
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
        if ($sendt) {
            // Kort svar til den som skrev, med lenker til appen via treni.no/g (Odd 30.10.2026: «gjør det samme» som /arrangor/).
            // Bare Gmail-røret – ingen usignert reserve.
            $svar_tekst = "Hei $navn,\n\ntakk for at dere vil samarbeide med Treni. Vi svarer deg på denne e-posten så snart vi kan.\n\n"
                        . "Treni lager uka di ut fra det du faktisk løper, og bygger den på nytt når livet skjer. Prøv selv:\n"
                        . "Google Play: https://treni.no/g/?til=android&k=epost-samarbeid\n"
                        . "App Store: https://treni.no/g/?til=ios&k=epost-samarbeid\n\n"
                        . "Hilsen Treni\nhei@treni.no\n";
            try { treni_epost_send($epost, "Takk for meldingen om samarbeid med Treni", $svar_tekst); } catch (Throwable $e) { }
        }
        if (!$sendt) {
            $feil = "Noe gikk galt hos oss. Send gjerne en e-post direkte i stedet.";
        }
    }
}
?>
<!doctype html>
<html lang="nb">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Samarbeid med Treni</title>
<meta name="description" content="Løpegrupper, klubber og arrangører: fortell hva dere ser for dere, så tar vi en prat.">
<meta name="robots" content="noindex">
<meta property="og:title" content="Samarbeid med Treni">
<meta property="og:description" content="Løpegrupper, klubber og arrangører: fortell hva dere ser for dere, så tar vi en prat.">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Treni">
<meta property="og:url" content="https://treni.no/samarbeid.php">
<meta property="og:image" content="https://treni.no/ny/bilder/deling.jpg">
<link rel="icon" href="/favicon.ico">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;800&display=swap">
<link rel="stylesheet" href="/ny/side.css?v=3">
</head>
<body>

<header><div class="c topp">
  <a class="logo" href="/" aria-label="Treni">Tren<i aria-hidden="true">ı</i></a>
  <nav class="meny" aria-label="Hovedmeny"><a href="/lop.php">Løpskalender</a><a href="/lopegrupper.php">Løpegrupper</a><a class="liten" href="/#last">Last ned</a></nav>
</div></header>

<main>
<section class="c sidehode">
  <span class="etikett">Samarbeid</span>
<?php if ($sendt): ?>
  <h1>Takk<?php if (!empty($navn)) echo ", " . htmlspecialchars(explode(" ", $navn)[0]); ?>!</h1>
  <p class="lead">Vi har fått henvendelsen<?php if (!empty($org)) echo " fra <b>" . htmlspecialchars($org) . "</b>"; ?>, og tar kontakt på e-post<?php if (!empty($mobil)) echo " eller mobil"; ?> så snart vi kan.</p>
  <p style="margin:24px 0 72px"><a class="k kant" href="/">Til forsiden</a></p>
</section>
<?php else: ?>
  <h1>Vil dere samarbeide <em>med Treni?</em></h1>
  <p class="lead">Treni er en treningsveileder for løpere. Appen lager uka di ut fra det du faktisk løper, og bygger den på nytt
  når livet skjer – med reglene til trener Eirik Haugsnes.</p>
</section>

<section class="c">
  <div class="to-kort">
    <div>
      <span class="etikett">Løpegrupper og klubber</span>
      <b>Smartere trening mellom fellesøktene</b>
      <p>Har dere en gruppe eller klubb, og vil at medlemmene skal trene godt også mellom fellesøktene? Fortell oss om dere.</p>
    </div>
    <div>
      <span class="etikett">Arrangører</span>
      <b>Deltakere som trener mot løpet ditt</b>
      <p>Treni lager ukeplanen tilpasset formen og løpet løperen sikter mot. Fortell oss om løpet, så finner vi ut hva vi kan få til.</p>
    </div>
  </div>

  <?php if ($feil): ?><p class="skjema-feil"><?php echo htmlspecialchars($feil); ?></p><?php endif; ?>
  <form method="post" action="/samarbeid.php" class="skjema">
    <label for="navn">Kontaktperson</label>
    <input id="navn" type="text" name="navn" required autocomplete="name" value="<?php echo htmlspecialchars($_POST["navn"] ?? ""); ?>">
    <label for="organisasjon">Løpegruppe, klubb eller arrangement</label>
    <input id="organisasjon" type="text" name="organisasjon" required autocomplete="organization" placeholder="F.eks. Tromsø Løpeklubb" value="<?php echo htmlspecialchars($_POST["organisasjon"] ?? ""); ?>">
    <fieldset>
      <legend>Hvem er dere?</legend>
      <?php foreach ($TYPER as $verdi => $navn_t): ?>
      <label class="valg"><input type="radio" name="type" value="<?php echo $verdi; ?>" <?php echo ($_POST["type"] ?? "lopegruppe") === $verdi ? "checked" : ""; ?>> <?php echo $navn_t; ?></label>
      <?php endforeach; ?>
    </fieldset>
    <label for="epost">E-post</label>
    <input id="epost" type="email" name="epost" required autocomplete="email" value="<?php echo htmlspecialchars($_POST["epost"] ?? ""); ?>">
    <label for="mobil">Mobil <span class="valgfritt">(valgfritt)</span></label>
    <input id="mobil" type="tel" name="mobil" autocomplete="tel" placeholder="F.eks. 900 00 000" value="<?php echo htmlspecialchars($_POST["mobil"] ?? ""); ?>">
    <label for="melding">Hva ser dere for dere? <span class="valgfritt">(valgfritt – fellesøkter, et løp dere arrangerer, antall medlemmer …)</span></label>
    <textarea id="melding" name="melding" rows="4"><?php echo htmlspecialchars($_POST["melding"] ?? ""); ?></textarea>
    <label class="krukke" aria-hidden="true">Nettside <input type="text" name="nettside" tabindex="-1" autocomplete="off"></label>
    <div style="margin-top:14px"><?php treni_spamvern_felt(); ?></div>
    <button type="submit" class="k hoved">Send henvendelsen</button>
  </form>
  <p class="liten" style="margin:16px 0 72px">Foretrekker du e-post? Skriv til
  <a href="mailto:hei@treni.no?subject=Samarbeid%20med%20Treni">hei@treni.no</a>. Vi bruker opplysningene bare til å svare dere
  (<a href="/personvern.html">personvern</a>).</p>
</section>
<?php endif; ?>
</main>

<footer><div class="c">
  <span>Treni · <a href="mailto:hei@treni.no">hei@treni.no</a></span>
  <nav aria-label="Bunnmeny"><a href="/">Forsiden</a><a href="/lop.php">Løpskalender</a><a href="/lopegrupper.php">Løpegrupper</a><a href="/personvern.html">Personvern</a><a href="/vilkar.html">Vilkår</a></nav>
</div></footer>
</body>
</html>
