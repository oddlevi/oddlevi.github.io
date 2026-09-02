<?php
require_once __DIR__ . "/spamvern.php";
// Interesseskjema for testløpere. Sender e-post til treneren — ingenting lagres
// på serveren. Honningkrukke («nettside»-feltet) stopper enkle spam-boter.
$sendt = false;
$feil = "";
// Målløp fra løpskalenderen (Odds idé 31.08): kommer løperen via
// videre.php, følger løpet med (signert påmeldings-URL — samme HMAC som
// videre.php, nøkkel = db-passordet) og lagres på venteliste-raden.
$ml_kilde = $_SERVER["REQUEST_METHOD"] === "POST" ? $_POST : $_GET;
$maal_lop = null;
$ml_navn = mb_substr(trim($ml_kilde["lop"] ?? ""), 0, 90);
$ml_dato = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($ml_kilde["dato"] ?? "")) ? $ml_kilde["dato"] : "";
$ml_til = (string) ($ml_kilde["til"] ?? "");
$ml_sig = (string) ($ml_kilde["s"] ?? "");
if ($ml_navn !== "" && $ml_til !== "" && preg_match('#^https?://#i', $ml_til)) {
    $ml_cfg = dirname(__DIR__) . "/dashbord_config.php";
    $ml_konfig = is_readable($ml_cfg) ? (include $ml_cfg) : null;
    if (is_array($ml_konfig)
        && hash_equals(substr(hash_hmac('sha256', $ml_til, (string) $ml_konfig['db_pass']), 0, 16), $ml_sig)) {
        $maal_lop = ["navn" => $ml_navn, "dato" => $ml_dato, "pamelding" => $ml_til,
                     "pameldt" => false, "via" => "lopskalender"];
    }
}
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $navn = trim($_POST["navn"] ?? "");
    $epost = trim($_POST["epost"] ?? "");
    $om = trim($_POST["om"] ?? "");
    $kilde = mb_substr(trim($_POST["kilde"] ?? ""), 0, 200);
    $tg = trim($_POST["telegram"] ?? "");
    $mobil = trim($_POST["mobil"] ?? "");
    $sprak_valg = $_POST["sprak"] ?? "norsk";
    $sprak = $sprak_valg === "annet"
        ? (trim($_POST["sprak_annet"] ?? "") ?: "annet")
        : (in_array($sprak_valg, ["norsk", "engelsk"], true) ? $sprak_valg : "norsk");
    $krukke = trim($_POST["nettside"] ?? "");
    if ($krukke !== "") {
        $sendt = true; // bot — lat som alt gikk bra
    } elseif (($vern = treni_spamvern_ok()) !== "") {
        $feil = $vern;
    } elseif ($navn === "" || !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
        $feil = "Fyll inn navn og en gyldig e-postadresse.";
    } else {
        $vl_kode_ny = bin2hex(random_bytes(16));   // ventelistekoden lages her så trener-e-posten får lenken
        $tekst = "Ny testløper-interesse fra treni.no\n\n"
               . ($maal_lop ? "🏁 KOM VIA LØPSKALENDEREN, vil trene mot: "
                  . $maal_lop["navn"] . ($maal_lop["dato"] ? " (" . $maal_lop["dato"] . ")" : "")
                  . "\nPåmeldingslenke: " . $maal_lop["pamelding"] . "\n\n" : "")
               . "Navn: " . $navn . "\n"
               . "E-post: " . $epost . "\n"
               . ($tg !== "" ? "Telegram: " . $tg . "\n" : "")
               . "Språk: " . $sprak . "\n\n"
               . "Ventelistesiden (klar når løperen har svart på spørsmålene): "
               . "https://min.treni.no/venteliste.php?t=" . $vl_kode_ny . "\n\n"
               . ($kilde !== "" ? "Tipset av: " . $kilde . "\n\n" : "")
               . "Om løpingen:\n" . ($om !== "" ? $om : "(ikke utfylt)") . "\n";
        // Gmail-røret (Odds beslutning 20.08): DKIM-signert, innboks + Sendt-arkiv.
        require_once dirname(__DIR__) . "/epost_smtp.php";
        $epost_ok = treni_epost_send("hei@treni.no",
                                     "Testløper-interesse: " . $navn, $tekst);
        if (!$epost_ok) {   // nødfallback: usignert server-mail (kan spamme)
            $hode = "From: Treni <hei@treni.no>\r\n"
                  . "Reply-To: " . str_replace(["\r", "\n"], "", $epost) . "\r\n"
                  . "Content-Type: text/plain; charset=UTF-8\r\n";
            $epost_ok = mail("hei@treni.no",
                             "=?UTF-8?B?" . base64_encode("Testløper-interesse: " . $navn) . "?=",
                             $tekst, $hode, "-fhei@treni.no");
        }
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
                    mobil VARCHAR(32) NOT NULL DEFAULT '',
                    om TEXT,
                    status VARCHAR(20) NOT NULL DEFAULT 'venter'
                ) CHARACTER SET utf8mb4");
                $vl_kode = $vl_kode_ny;
                try { $pdo->exec("ALTER TABLE venteliste ADD COLUMN maal_lop TEXT NULL"); }
                catch (Throwable $e9) { /* finnes alt */ }
                $pdo->prepare("INSERT INTO venteliste (navn, epost, telegram, om, sprak, kilde, mobil, kode, maal_lop) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$navn, $epost, $tg, $om, $sprak, $kilde !== "" ? $kilde : null, $mobil, $vl_kode,
                               $maal_lop ? json_encode($maal_lop, JSON_UNESCAPED_UNICODE) : null]);
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
                // Egen melding: ferdig invitasjon (kopier/videresend hele til leaden)
                $fornavn = explode(" ", $navn)[0];
                $invitasjon = ($sprak === "engelsk")
                    ? "Hi " . $fornavn . "! Great that you want to try Treni 🏃\n\nEverything happens in Telegram — a messaging app just like WhatsApp, only with better support for smart bots. Tap the link at the bottom and the bot guides you through in a few minutes (connects Strava and asks a few questions about your running).\n\nPS: If you are new to Telegram, some spam from unknown foreign numbers may appear at first — sadly common for fresh accounts, and nothing to do with Treni. Quick fix: Settings → Privacy → Phone Number → Nobody. With us, everything happens in a private group with just you, the coach and the training bot.\n\nHere is your link: https://t.me/VeilederenAIBot?start=verve_odd_en"
                    : "Hei " . $fornavn . "! Så gøy at du vil prøve Treni 🏃\n\nAlt skjer i Telegram — en meldingsapp helt lik WhatsApp, bare med bedre støtte for smarte boter. Trykk på lenken nederst, så guider boten deg gjennom på noen minutter (kobler Strava og stiller noen spørsmål om løpingen din).\n\nPS: Er du ny på Telegram, kan det dukke opp spam fra ukjente utenlandske numre i starten — det er dessverre vanlig for ferske kontoer og har ingenting med Treni å gjøre. Raskt fikset: Innstillinger → Personvern → Telefonnummer → «Ingen». Hos oss skjer alt i en privat gruppe med bare deg, treneren og treningsboten.\n\nHer er lenken din: https://t.me/VeilederenAIBot?start=verve_odd";
                @file_get_contents(
                    "https://api.telegram.org/bot" . TRENI_BOT_TOKEN . "/sendMessage",
                    false,
                    stream_context_create(["http" => [
                        "method" => "POST",
                        "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
                        "content" => http_build_query([
                            "chat_id" => TRENI_TRENER_CHAT,
                            "text" => "📨 Klar til å videresende:\n\n" . $invitasjon]),
                        "timeout" => 8]]));
            }
        }
        // Velkomst-e-post til løperen (Odds bestilling 19.08): umiddelbar
        // tilgang til venteliste-Min side via personlig skjemalenke.
        if (isset($vl_kode)) {
            $fornavn2 = explode(" ", $navn)[0];
            $lenke = "https://treni.no/venteliste-start.php?k=" . $vl_kode;
            if ($sprak === "engelsk") {
                $emne2 = "Welcome to Treni, " . $fornavn2 . "!";
                $brev = "Hi " . $fornavn2 . "!\n\n"
                      . "Thanks for your interest in Treni!\n\n"
                      . "First, a quick note on why there is a waitlist: Treni reads "
                      . "your training automatically through Strava - that is how the "
                      . "advisor sees your sessions and adjusts your plan every week. "
                      . "Strava caps how many runners a service like ours can connect, "
                      . "and our first ten spots are taken. We have applied for more, "
                      . "and we will let you know as soon as your spot opens. You are in line.\n\n"
                      . "But we have already made something for you: answer a few quick "
                      . "questions, and the advisor builds your heart-rate zones and a "
                      . "training plan from your numbers. You also get full access to "
                      . "the e-book Training for Mountain Running by our coach Eirik "
                      . "Haugsnes (physiotherapist and skyrunner). You were sent straight "
                      . "to the questions when you signed up. To continue later, use this link:\n\n"
                      . $lenke . "\n\n"
                      . "When your spot opens, you connect Strava in two minutes - and "
                      . "get full weekly guidance with a coach in the loop.\n\n"
                      . "Best,\nOdd Levi and Eirik at Treni";
            } else {
                $emne2 = "Velkommen til Treni, " . $fornavn2 . "!";
                $brev = "Hei " . $fornavn2 . "!\n\n"
                      . "Takk for interessen for Treni!\n\n"
                      . "Først en kort forklaring på hvorfor det er venteliste: Treni "
                      . "leser treningen din automatisk gjennom Strava - det er sånn "
                      . "veilederen ser øktene dine og justerer planen hver uke. Strava "
                      . "setter et tak på hvor mange løpere en tjeneste som vår kan "
                      . "koble til, og de ti første plassene våre er fylt. Vi har søkt "
                      . "Strava om flere plasser, og vi sier fra så snart plassen din "
                      . "åpner. Du står i køen.\n\n"
                      . "Men vi har laget noe til deg allerede nå: svar på noen kjappe "
                      . "spørsmål, så bygger veilederen pulssonene dine og en "
                      . "treningsplan ut fra tallene dine. Du får også full tilgang til "
                      . "e-boka «Trening for fjelløping», skrevet av treneren vår Eirik "
                      . "Haugsnes (fysioterapeut og skyrunner). Du ble sendt rett til "
                      . "spørsmålene da du meldte deg. Vil du fortsette senere, bruker "
                      . "du denne lenken:\n\n"
                      . $lenke . "\n\n"
                      . "Når plassen din åpner, kobler du Strava på to minutter - og "
                      . "får full ukentlig veiledning med trener i loopen.\n\n"
                      . "Hilsen Odd Levi og Eirik i Treni";
            }
            // Gmail-røret: DKIM → innboks (server-mail() gikk i søppelpost, 20.08)
            $velkomst_ok = treni_epost_send($epost, $emne2, $brev);
            if (!$velkomst_ok) {   // nødfallback så velkomsten aldri uteblir helt
                @mail($epost,
                      "=?UTF-8?B?" . base64_encode($emne2) . "?=",
                      $brev,
                      "From: Treni <hei@treni.no>\r\nContent-Type: text/plain; charset=UTF-8\r\n",
                      "-fhei@treni.no");
            }
        }
        if (isset($vl_kode) && $mobil !== "" && defined("TRENI_BOT_TOKEN")) {
            $fornavn3 = explode(" ", $navn)[0];
            $lenke3 = "https://treni.no/venteliste-start.php?k=" . $vl_kode;
            $sms = ($sprak === "engelsk")   // (ikke lenger i varselet, 03.09: løperen sendes rett til spørsmålene)
                ? "Hi " . $fornavn3 . "! Odd Levi from Treni here - thanks for signing up! "
                  . "While you wait for your Strava spot we made you a personal page: answer "
                  . "a few quick questions and you get heart-rate zones, a training plan and "
                  . "our mountain-running e-book. Start here: " . $lenke3
                  . " (same link is in your email). Best, Odd Levi, Treni"
                : "Hei " . $fornavn3 . "! Odd Levi fra Treni her - takk for interessen! "
                  . "Mens du venter på Strava-plassen har vi laget en egen side til deg: "
                  . "svar på noen kjappe spørsmål, så får du pulssoner, treningsplan og "
                  . "e-boka vår om fjelløping. Kom i gang her: " . $lenke3
                  . " (samme lenke ligger i e-posten din). Mvh Odd Levi, Treni";
            @file_get_contents(
                "https://api.telegram.org/bot" . TRENI_BOT_TOKEN . "/sendMessage",
                false, stream_context_create(["http" => [
                    "method" => "POST",
                    "header" => "Content-Type: application/x-www-form-urlencoded
",
                    "content" => http_build_query([
                        "chat_id" => defined("TRENI_ODD_CHAT") ? TRENI_ODD_CHAT : TRENI_TRENER_CHAT,
                        "text" => "🆕 Ny påmelding: " . $navn . " (" . $mobil . ")
🚦 Status: venter (sendt rett til spørsmålene, ikke svart ennå)
✉️ Kommunisert: velkomst-e-post sendt til " . $epost . (!empty($velkomst_ok) ? " ✓ (Gmail-røret)" : " (usikker leveranse!)") . "
📝 Om løpingen: " . mb_substr($om !== "" ? $om : "(ikke utfylt)", 0, 300)]),
                    "timeout" => 8]]));
        }
        $sendt = $epost_ok || $tg_ok;
        if (!$sendt) {
            $feil = "Noe gikk galt hos oss. Send gjerne en e-post direkte i stedet.";
        }
        // Rett til spørsmålene (Odds «husk» 20.08): infoen er lagret på
        // ventelista og velkomst-e-post/SMS er sendt — i stedet for takk-sida
        // sendes løperen DIREKTE til sin personlige spørsmålsside, så svarene
        // kommer i samme flyt (flere svarer når spørsmålene dukker opp med en
        // gang; Eirik G.-caset). E-post/SMS-lenken består som fallback.
        if ($sendt && isset($vl_kode)) {
            header("Location: /venteliste-start.php?k=" . $vl_kode . "&ny=1");
            exit;
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
<link rel="stylesheet" href="stil.css?v=12">
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<nav class="sprakvalg" aria-label="Språk"><span aria-current="page">NO</span><a href="/en/join.php">EN</a></nav>
<main>

<header class="hero smal">
  <p class="kicker reveal"><a href="index.html" style="color:inherit">treni.no</a> · bli testløper</p>
  <h1 class="reveal" style="font-size:clamp(2rem,6vw,3rem)">Bli testløper</h1>
</header>

<?php if ($sendt): ?>
<section>
  <div class="kort">
    <h3 style="margin-top:0">Takk<?php if (!empty($navn)) echo ", " . htmlspecialchars(explode(" ", $navn)[0]); ?>! 🏃</h3>
    <p>Du står nå på lista — og du trenger ikke vente på plassen for å komme
    i gang: svar på noen kjappe spørsmål, så bygger veilederen pulssonene dine
    og en treningsplan med en gang. Du får også full tilgang til e-boka
    «Trening for fjelløping». (Samme lenke er sendt deg på e-post.)</p>
    <?php if (isset($vl_kode)): ?>
    <p style="margin:1.2rem 0"><a href="https://treni.no/venteliste-start.php?k=<?= htmlspecialchars($vl_kode) ?>"
       style="display:inline-block; background:hsl(152 62% 20%); color:#fff;
       padding:.85rem 1.6rem; border-radius:12px; font-weight:700; font-size:1.05rem;
       text-decoration:none; box-shadow:0 2px 8px hsl(152 62% 20% / .3)">Kom i gang nå →</a></p>
    <?php endif; ?>
    <p>Når det åpner seg en plass, får du en <b>personlig invitasjon</b> fra
    treneren med Telegram-lenken som starter den fulle oppstarten din.</p>
    <p style="margin-bottom:0"><a href="index.html">← Tilbake til forsiden</a></p>
  </div>
</section>
<?php else: ?>
<section>
  <p>Vi tar inn noen få testløpere om gangen, så treneren faktisk rekker å se deg.
  Fortell kort om deg selv, så tar vi kontakt.</p>
  <?php if ($feil): ?><p class="skjema-feil"><?php echo htmlspecialchars($feil); ?></p><?php endif; ?>
  <?php if ($maal_lop): ?>
  <div style="border:2px solid hsl(var(--primary) / .45); border-radius:var(--radius);
       padding:.8rem 1.1rem; margin:0 0 1.1rem; background:hsl(var(--primary) / .06)">
    🏁 <b>Du vil trene mot: <?= htmlspecialchars($maal_lop['navn']) ?></b><?=
      $maal_lop['dato'] ? ' · ' . htmlspecialchars(substr($maal_lop['dato'], 8, 2) . '.' . substr($maal_lop['dato'], 5, 2)) : '' ?>
    <span class="liten" style="display:block; margin-top:.2rem">Planen bygges mot løpsdagen —
    påmeldingslenken til arrangøren får du på din side.</span>
  </div>
  <?php endif; ?>
  <form method="post" action="bli-testloper.php" class="skjema">
    <?php if ($maal_lop): ?>
    <input type="hidden" name="lop" value="<?= htmlspecialchars($maal_lop['navn']) ?>">
    <input type="hidden" name="dato" value="<?= htmlspecialchars($maal_lop['dato']) ?>">
    <input type="hidden" name="til" value="<?= htmlspecialchars($maal_lop['pamelding']) ?>">
    <input type="hidden" name="s" value="<?= htmlspecialchars($ml_sig) ?>">
    <?php endif; ?>
    <label>Navn
      <input type="text" name="navn" required autocomplete="name"
             value="<?php echo htmlspecialchars($_POST["navn"] ?? ""); ?>">
    </label>
    <label>E-post
      <input type="email" name="epost" required autocomplete="email"
             value="<?php echo htmlspecialchars($_POST["epost"] ?? ""); ?>">
    </label>
    <label>Mobil <span class="valgfritt">(valgfritt — så kan vi sende deg invitasjonen på SMS)</span>
      <input type="tel" name="mobil" autocomplete="tel" placeholder="f.eks. 900 00 000"
             value="<?php echo htmlspecialchars($_POST["mobil"] ?? ""); ?>">
    </label>
    <label>Telegram <span class="valgfritt">(valgfritt — brukernavn eller mobilnummeret Telegram-kontoen din bruker, så kan invitasjonen komme dit)</span>
      <input type="text" name="telegram" autocomplete="tel" placeholder="@brukernavn eller mobilnummer"
             value="<?php echo htmlspecialchars($_POST["telegram"] ?? ""); ?>">
    </label>
    <fieldset class="sprak-felt">
      <legend>Hvilket språk vil du ha veiledningen på?</legend>
      <label class="radio"><input type="radio" name="sprak" value="norsk" checked> Norsk</label>
      <label class="radio"><input type="radio" name="sprak" value="engelsk"> Engelsk</label>
      <label class="radio"><input type="radio" name="sprak" value="annet"> Annet:
        <input type="text" name="sprak_annet" oninput="this.closest('fieldset').querySelector('input[value=annet]').checked = this.value.trim() !== ''" placeholder="skriv her" style="width:9rem"></label>
    </fieldset>
    <label>Hvem tipset deg om Treni? <span class="valgfritt">(valgfritt — en person, sosiale medier, klubben …)</span>
      <input type="text" name="kilde" value="<?= htmlspecialchars(mb_substr(trim($_GET["kilde"] ?? ""), 0, 200)) ?>" placeholder="f.eks. en venn, Facebook, Tromsø Løpeklubb"
             value="<?php echo htmlspecialchars($_POST["kilde"] ?? ""); ?>">
    </label>
    <label>Litt om løpingen din <span class="valgfritt">(valgfritt — f.eks. hvor mye du løper, og hva du vil oppnå)</span>
      <textarea name="om" rows="4"><?php echo htmlspecialchars($_POST["om"] ?? ""); ?></textarea>
    </label>
    <label class="krukke" aria-hidden="true">Nettside
      <input type="text" name="nettside" tabindex="-1" autocomplete="off">
    </label>
    <?php treni_spamvern_felt(); ?>
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
