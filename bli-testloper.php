<?php
// Odd 07.09: samme skjema for løpere og løpecoacher (?rolle=coach fra forsiden)
$er_coach = (($_POST['rolle'] ?? $_GET['rolle'] ?? '') === 'coach');
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
        && hash_equals(substr(hash_hmac('sha256', $ml_til, (string) ($ml_konfig['videre_secret'] ?? $ml_konfig['db_pass'] ?? '')), 0, 16), $ml_sig)) {
        $maal_lop = ["navn" => $ml_navn, "dato" => $ml_dato, "pamelding" => $ml_til,
                     "pameldt" => false, "via" => "lopskalender"];
    }
}
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Feltgrenser + rensing (sikkerhetstest 03.09): ingen HTML/kontrolltegn når
    // innholdet går videre i e-post, Telegram og databasen.
    treni_begrens_post(["om" => 2000, "navn" => 120, "epost" => 190, "kilde" => 200,
                        "telegram" => 120, "mobil" => 32, "sprak_annet" => 40]);
    $navn = treni_ren($_POST["navn"] ?? "", 120);
    $epost = treni_ren($_POST["epost"] ?? "", 190);
    $om = treni_ren($_POST["om"] ?? "", 2000, true);
    if ($er_coach) { $om = "[Løpecoach] " . $om; }
    $kilde = treni_ren($_POST["kilde"] ?? "", 200);
    $tg = treni_ren($_POST["telegram"] ?? "", 120);
    $mobil = treni_ren($_POST["mobil"] ?? "", 32);
    $sprak_valg = $_POST["sprak"] ?? "norsk";
    $sprak = $sprak_valg === "annet"
        ? (treni_ren($_POST["sprak_annet"] ?? "", 40) ?: "annet")
        : (in_array($sprak_valg, ["norsk", "engelsk"], true) ? $sprak_valg : "norsk");
    // Revisjon 10.09 (punkt 39): engelsk e-post og engelsk skjema 2 når løperen
    // valgte engelsk, eller kom via det engelske skjemaet uten å velge norsk
    // («Other»). Før fulgte e-posten sprak === "engelsk" og lenkene $TRENI_EN,
    // så «Norwegian» på det engelske skjemaet ga norsk e-post og engelsk skjema.
    $lang_en = ($sprak === "engelsk") || (!empty($TRENI_EN) && $sprak !== "norsk");
    $krukke = trim($_POST["nettside"] ?? "");
    if ($krukke !== "") {
        $sendt = true; // bot — lat som alt gikk bra
    } elseif (($vern = treni_spamvern_ok()) !== "") {
        $feil = $vern;
    } elseif ($navn === "" || !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
        $feil = !empty($TRENI_EN) ? "Please fill in your name and a valid email address." : "Fyll inn navn og en gyldig e-postadresse.";
    } elseif (strlen(preg_replace('/\D/', '', (string) ($_POST["mobil"] ?? ""))) < 8) {
        // B-130 (Odd 19.09.2026): mobil er obligatorisk, så vi kan nå løperen når e-posten ikke åpnes
        $feil = !empty($TRENI_EN) ? "Please fill in a mobile number (at least 8 digits)." : "Fyll inn mobilnummer (minst 8 siffer).";
    } elseif (!$er_coach && (($_POST["klokke"] ?? "") !== "ja")) {
        // Odd 08.09 (S-87): bare løpere med klokke slippes inn — planen bygges på øktene.
        $feil = !empty($TRENI_EN) ? "Treni needs a heart-rate watch that connects to Intervals.icu (Garmin, Polar, Suunto, Coros or Wahoo). Tick the box if you have one."
                                  : "Treni trenger en pulsklokke som kan kobles til Intervals.icu (Garmin, Polar, Suunto, Coros eller Wahoo). Kryss av om du har det.";
    } else {
        $vl_kode_ny = bin2hex(random_bytes(16));   // ventelistekoden lages her så trener-e-posten får lenken
        // Serverside-dedup (24.08-vernet, gjenoppbygd 03.09 etter at det var
        // borte fra fila): samme e-post med aktiv rad siste 30 dager GJENBRUKER
        // raden og koden: ingen ny rad, ingen nye e-poster, bare et
        // kort 🔁-varsel til Odd+Claude (Ørjan-caset: tre rader, tre lenker).
        $cfg_sti = dirname(__DIR__) . "/dashbord_config.php";
        $konfig = is_readable($cfg_sti) ? (include $cfg_sti) : null;
        $pdo = null;
        $eksisterende = null;
        if (is_array($konfig)) {
            try {
                $pdo = new PDO(
                    "mysql:host={$konfig['db_host']};dbname={$konfig['db_name']};charset=utf8mb4",
                    $konfig['db_user'], $konfig['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $st_d = $pdo->prepare("SELECT id, kode FROM venteliste WHERE LOWER(epost) = LOWER(?)
                    AND status IN ('venter', 'minside', 'invitert')
                    AND opprettet > NOW() - INTERVAL 30 DAY ORDER BY id DESC LIMIT 1");
                $st_d->execute([$epost]);
                $eksisterende = $st_d->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($eksisterende && empty($eksisterende["kode"])) {
                    $pdo->prepare("UPDATE venteliste SET kode = ? WHERE id = ?")
                        ->execute([$vl_kode_ny, $eksisterende["id"]]);
                    $eksisterende["kode"] = $vl_kode_ny;
                }
            } catch (Throwable $e_d) { $eksisterende = null; }
        }
        if ($eksisterende) {
            $vl_kode = $eksisterende["kode"];
            if (defined("TRENI_BOT_TOKEN")) {
                @file_get_contents(
                    "https://api.telegram.org/bot" . TRENI_BOT_TOKEN . "/sendMessage",
                    false, stream_context_create(["http" => [
                        "method" => "POST",
                        "timeout" => 5,
                        "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
                        "content" => http_build_query([
                            "chat_id" => defined("TRENI_ODD_CHAT") ? TRENI_ODD_CHAT : TRENI_TRENER_CHAT,   // S-87: drift, ikke trener
                            "text" => "🔁 Gjentatt påmelding fra " . $navn . " (" . $epost . ").\n"
                                    . "Bruker eksisterende rad id " . $eksisterende["id"]
                                    . ", ingen nye e-poster sendt.\n"
                                    . "Side: https://min.treni.no/?t=" . $vl_kode]),
                        "timeout" => 8]]));
            }
            // B-57: rett til min side (klokkeguiden er steg 1), ikke til skjema 2
            header("Location: https://min.treni.no/?t=" . $vl_kode . ($lang_en ? "&lang=en" : "&lang=no"));
            exit;
        }
        $tekst = "Ny testløper-interesse fra treni.no\n\n"
               . ($maal_lop ? "🏁 KOM VIA LØPSKALENDEREN, vil trene mot: "
                  . $maal_lop["navn"] . ($maal_lop["dato"] ? " (" . $maal_lop["dato"] . ")" : "")
                  . "\nPåmeldingslenke: " . $maal_lop["pamelding"] . "\n\n" : "")
               . "Navn: " . $navn . "\n"
               . "E-post: " . $epost . "\n"
               . ($tg !== "" ? "Telegram: " . $tg . "\n" : "")
               . "Språk: " . $sprak . "\n\n"
               . "Løperens side (steg 1 av 4 er Intervals-konto og kobling, steg 2 klokka; vakta lager siden innen et par minutter): "
               . "https://min.treni.no/?t=" . $vl_kode_ny . "\n\n"
               . ($kilde !== "" ? "Tipset av: " . $kilde . "\n\n" : "")
               . "Om løpingen:\n" . ($om !== "" ? $om : "(ikke utfylt)") . "\n";
        // Gmail-røret (Odds beslutning 20.08): DKIM-signert, innboks + Sendt-arkiv.
        require_once dirname(__DIR__) . "/epost_smtp.php";
        $epost_ok = treni_epost_send("hei@treni.no",
                                     ($er_coach ? "Løpecoach-interesse: " : "Testløper-interesse: ") . $navn, $tekst);
        if (!$epost_ok) {   // nødfallback: usignert server-mail (kan spamme)
            $hode = "From: Treni <hei@treni.no>\r\n"
                  . "Reply-To: " . str_replace(["\r", "\n"], "", $epost) . "\r\n"
                  . "Content-Type: text/plain; charset=UTF-8\r\n";
            $epost_ok = mail("hei@treni.no",
                             "=?UTF-8?B?" . base64_encode(($er_coach ? "Løpecoach-interesse: " : "Testløper-interesse: ") . $navn) . "?=",
                             $tekst, $hode, "-fhei@treni.no");
        }
        // Ventelista (vises i trenerens dashbord) — beste forsøk, stopper aldri skjemaet
        if ($pdo !== null) {
            try {
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
        // Telegram til Odd+Claude, garantert kanal uansett e-postlevering.
        // Revisjon 10.09 (punkt 45): gikk til trenertråden. S-87 og systemkartet
        // sier at bare Odd+Claude varsles før planen finnes.
        $tg_ok = false;
        if ($konfig !== null) {
            if (defined("TRENI_BOT_TOKEN")) {
                $svar = @file_get_contents(
                    "https://api.telegram.org/bot" . TRENI_BOT_TOKEN . "/sendMessage",
                    false,
                    stream_context_create(["http" => [
                        "method" => "POST",
                        "timeout" => 5,
                        "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
                        "content" => http_build_query([
                            "chat_id" => defined("TRENI_ODD_CHAT") ? TRENI_ODD_CHAT : TRENI_TRENER_CHAT,
                            "text" => "🏃 " . $tekst]),
                        "timeout" => 8]]));
                $tg_ok = $svar !== false && strpos($svar, '"ok":true') !== false;
                // «📨 Klar til å videresende»-meldingen er fjernet (Odd 06.09): løperen får
                // veien inn via e-posten og ventelistesiden, treneren trenger ikke kopiere noe.
            }
        }
        // Velkomst-e-post til løperen (Odds bestilling 19.08): umiddelbar
        // tilgang til venteliste-Min side via personlig skjemalenke.
        if (isset($vl_kode)) {
            $fornavn2 = explode(" ", $navn)[0];
            // B-57 (Odd 10.09): neste steg er klokka, ikke spørsmålene. Lenken går til
            // min side, der klokkeguiden er første og eneste steg. Skjema 2-lenka
            // sendes først når øktene er inne (onboarding_vakt.py).
            $lenke = "https://min.treni.no/?t=" . $vl_kode . ($lang_en ? "&lang=en" : "&lang=no");
            // F-38 (Odd 10.09, punkt 4 og 8): luft i e-posten. Tom linje rundt lista,
            // ett punkt per linje med tom linje mellom, korte avsnitt, lenka alene,
            // hilsen alene nederst. Løpecoacher får ingen side og ingen steg (vakta
            // hopper over dem): kort takk, Odd tar kontakt.
            if ($er_coach && $lang_en) {
                $emne2 = "Thanks for your interest in Treni, " . $fornavn2;
                $brev = "Hi " . $fornavn2 . "!\n\n"
                      . "Thanks for getting in touch about Treni for running coaches.\n\n"
                      . "We take coaches in one at a time, so Odd Levi will email you to talk about your runners and what you need.\n\n"
                      . "Until then, reply to this email if you have questions.\n\n"
                      . "Best,\nOdd Levi and Eirik at Treni";
            } elseif ($er_coach) {
                $emne2 = "Takk for interessen for Treni, " . $fornavn2;
                $brev = "Hei " . $fornavn2 . "!\n\n"
                      . "Takk for at du tok kontakt om Treni for løpecoacher.\n\n"
                      . "Vi tar inn coacher én om gangen, så Odd Levi sender deg en e-post for å snakke om løperne dine og hva du trenger.\n\n"
                      . "Svar gjerne på denne e-posten om du lurer på noe i mellomtiden.\n\n"
                      . "Hilsen Odd Levi og Eirik i Treni";
            } elseif ($lang_en) {
                $emne2 = "Welcome to Treni, " . $fornavn2 . ". Step 1 of 4: create an Intervals account and connect Treni";
                $brev = "Hi " . $fornavn2 . "!\n\n"
                      . "Thanks for signing up for Treni!\n\n"
                      . "Treni reads your training automatically from your watch. That is how the advisor sees your sessions and adjusts your plan every week.\n\n"
                      . "Your plan is built on what you have actually run, not on what you remember.\n\n"
                      . "There are four steps, and your page takes you through them one at a time:\n\n"
                      . "1. Create a free account at Intervals.icu and connect Treni to it.\n\n"
                      . "2. Connect your watch (Garmin, Polar, Suunto, Coros or Wahoo) directly to Intervals and download one year of history. Not via Strava: Intervals is not allowed to pass Strava activities on to us.\n\n"
                      . "3. Answer the questions about you and your training, 5 to 10 minutes. You get the link when your activities are in.\n\n"
                      . "4. We build your plan on your history and your answers, and it adjusts itself week by week from what you actually run.\n\n"
                      . "Start with step 1 on your personal page:\n\n"
                      . $lenke . "\n\n"
                      . "Best,\nOdd Levi and Eirik at Treni";
            } else {
                $emne2 = "Velkommen til Treni, " . $fornavn2 . ". Steg 1 av 4: lag Intervals-konto og koble Treni";
                $brev = "Hei " . $fornavn2 . "!\n\n"
                      . "Takk for at du meldte deg på Treni!\n\n"
                      . "Treni leser treningen din automatisk fra klokka. Det er sånn veilederen ser øktene dine og justerer planen hver uke.\n\n"
                      . "Planen din bygges på det du faktisk har løpt, ikke på det du husker.\n\n"
                      . "Det er fire steg, og siden din tar deg gjennom dem ett om gangen:\n\n"
                      . "1. Lag en gratis konto hos Intervals.icu og koble Treni til den.\n\n"
                      . "2. Koble klokka (Garmin, Polar, Suunto, Coros eller Wahoo) direkte til Intervals og hent ett år historikk. Ikke via Strava: Intervals får ikke sende Strava-økter videre til oss.\n\n"
                      . "3. Svar på spørsmålene om deg og treningen din, 5 til 10 minutter. Lenken får du når øktene dine er inne.\n\n"
                      . "4. Vi bygger planen din på historikken og svarene dine, og den justerer seg uke for uke etter det du faktisk løper.\n\n"
                      . "Start med steg 1 på din personlige side:\n\n"
                      . $lenke . "\n\n"
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
        // Revisjon 10.09 (punkt 45): statusvarselet gikk bare når mobil var oppgitt,
        // så Odd+Claude fikk ingenting om løpere uten mobil. Nå alltid. SMS sendes
        // ikke (B-61, Odd 10.09): purringen går automatisk på e-post fra vakta.
        if (isset($vl_kode) && defined("TRENI_BOT_TOKEN")) {
            @file_get_contents(
                "https://api.telegram.org/bot" . TRENI_BOT_TOKEN . "/sendMessage",
                false, stream_context_create(["http" => [
                    "method" => "POST",
                    "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
                    "content" => http_build_query([
                        "chat_id" => defined("TRENI_ODD_CHAT") ? TRENI_ODD_CHAT : TRENI_TRENER_CHAT,
                        "text" => "🆕 Ny påmelding: " . $navn . ($mobil !== "" ? " (" . $mobil . ")" : " (uten mobil)") . "\n"
                                . "🌐 Språk: " . $sprak . ($lang_en ? " (engelsk e-post og side)" : "") . "\n"
                                . "🚦 Status: steg 1 av 4, Intervals-konto og kobling (B-57/B-63). Vakta lager min side innen et par minutter, "
                                . "purrer på e-post etter 1, 3 og 10 dager (B-61), og sender skjema 2-lenka når øktene er inne.\n"
                                . "✉️ Kommunisert: velkomst-e-post sendt til " . $epost . (!empty($velkomst_ok) ? " ✓ (Gmail-røret)" : " (usikker leveranse!)") . "\n"
                                . "📝 Om løpingen: " . mb_substr($om !== "" ? $om : "(ikke utfylt)", 0, 300)]),
                    "timeout" => 8]]));
        }
        $sendt = $epost_ok || $tg_ok;
        if (!$sendt) {
            $feil = !empty($TRENI_EN) ? "Something went wrong on our end. Please email us directly instead." : "Noe gikk galt hos oss. Send gjerne en e-post direkte i stedet.";
        }
        // Rett videre (Odds «husk» 20.08, B-57 10.09): raden er lagret og
        // velkomst-e-posten sendt. Løperen sendes DIREKTE til min side med koden
        // sin, der steg 1 er klokkeguiden. Til vakta har laget siden (et par
        // minutter) viser min side en «siden din lages»-side som oppdaterer seg
        // selv. E-postlenken består som fallback. Løpecoacher får takk-sida.
        if ($sendt && isset($vl_kode) && !$er_coach) {
            header("Location: https://min.treni.no/?t=" . $vl_kode . ($lang_en ? "&lang=en" : "&lang=no"));
            exit;
        }
    }
}
// Engelsk skjema (en/join.php) bruker denne fila bare til behandlingen (Odd 07.09, Lola):
// koden, velkomst-e-posten og redirecten til min side er felles.
if (!empty($TRENI_EN)) { return; }
?>
<!DOCTYPE html>
<html lang="no">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $er_coach ? 'Bli med som løpecoach. Treni' : 'Få løpeplan hver uke, bygget på øktene dine. Treni' ?></title>
<meta name="description" content="Kom i gang som testløper hos Treni, trenerledet løpsveiledning bygget på øktene fra klokka di (Strava eller Intervals.icu).">
<meta name="robots" content="noindex">
<meta property="og:title" content="Bli testløper hos Treni">
<meta property="og:description" content="Trenerledet løpsveiledning bygget på øktene fra klokka di. Kom i gang, så får du egen side med en gang, og planen når historikken din er inne.">
<meta property="og:type" content="website">
<meta property="og:url" content="https://treni.no/bli-testloper.php">
<meta property="og:image" content="https://treni.no/bilder/og.jpg">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" type="image/png" href="/icon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="stil.css?v=30">
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<nav class="sprakvalg" aria-label="Språk"><span aria-current="page">NO</span><a href="/en/join.php<?php
  // D-151 (11.09): språkbytte beholder målløpet fra løpskalenderen (lop/dato/til/s), som join.php gjør motsatt vei.
  $q_en = array_filter(['rolle' => $er_coach ? 'coach' : '', 'lop' => $_GET['lop'] ?? '', 'dato' => $_GET['dato'] ?? '', 'til' => $_GET['til'] ?? '', 's' => $_GET['s'] ?? ''], 'strlen');
  echo $q_en ? '?' . htmlspecialchars(http_build_query($q_en), ENT_QUOTES, 'UTF-8') : ''; ?>">EN</a></nav>
<main>

<header class="hero smal">
  <p class="kicker reveal"><a href="index.html" style="color:inherit">treni.no</a> · <?= $er_coach ? 'for løpecoacher' : 'bli testløper' ?></p>
  <h1 class="reveal" style="font-size:clamp(2rem,6vw,3rem)"><?= $er_coach ? 'Bli med som løpecoach' : 'Få løpeplan hver uke, bygget på øktene dine' ?></h1>
  <?php if (!$er_coach): // Odd 12.09: én konkret setning om hva Treni er, rett under overskriften ?>
  <p class="reveal" style="font-size:1.15rem;line-height:1.5;max-width:38rem;margin:.6rem 0 0"><b>Treni ser hver økt fra klokka di og planlegger treningsuka di etter hva du har gjort.</b> Gratis i testperioden.</p>
  <?php endif; ?>
</header>

<?php if ($sendt): ?>
<section>
  <div class="kort">
    <h3 style="margin-top:0">Takk<?php if (!empty($navn)) echo ", " . htmlspecialchars(explode(" ", $navn)[0]); ?>! 🏃</h3>
    <?php if ($er_coach): ?>
    <p>Vi har fått meldingen din. Treni for løpecoacher tas inn trener for trener, så vi tar
    kontakt på e-post om trenerkonto, hvordan løperne dine kobles på, og hva det koster.</p>
    <p style="margin-bottom:0"><a href="index.html">← Tilbake til forsiden</a></p>
    <?php else: ?>
    <p>Du er meldt inn. Steg 1 av 4 er å lage konto hos Intervals.icu og koble Treni, og siden din tar deg gjennom det ett steg om gangen.
    Så svarer du på spørsmålene, og så bygger vi planen din på det du faktisk har løpt.
    (Samme lenke er sendt deg på e-post.)</p>
    <?php endif; ?>
    <?php if (isset($vl_kode) && !$er_coach): ?>
    <p style="margin:1.2rem 0"><a href="https://min.treni.no/?t=<?= htmlspecialchars($vl_kode) ?>&amp;lang=no"
       style="display:inline-block; background:hsl(152 62% 20%); color:#fff;
       padding:.85rem 1.6rem; border-radius:12px; font-weight:700; font-size:1.05rem;
       text-decoration:none; box-shadow:0 2px 8px hsl(152 62% 20% / .3)">Til siden din, koble klokka →</a></p>
    <?php endif; ?>
    <?php if (!$er_coach): ?>
    <p>Garmin, Polar, Suunto, Coros og Wahoo kobles via Intervals.icu, som er gratis. Det tar noen
    minutter. Planen kommer når svarene og historikken er inne, og den justerer seg uke for uke etter det du faktisk løper.</p>
    <p style="margin-bottom:0"><a href="index.html">← Tilbake til forsiden</a></p>
    <?php endif; ?>
  </div>
</section>
<?php else: ?>
<section>
  <?php if ($er_coach): ?>
  <p>Treni gjør jobben mellom øktene for deg som trener: leser hver økt løperne dine logger på klokka (Strava eller Intervals.icu),
  skriver ukeplanen etter metodikken, og du godkjenner, justerer og sender i ditt navn. Løperne får
  sin egen side, du får oversikten. Vi tar inn noen få trenere i høst. Fortell kort om deg og løperne
  dine, så tar vi kontakt.</p>
  <?php else: ?>
  <p>Treni - Løp rolig, hemmeligheten alle raske løpere kjenner.</p>
  <p class="liten">Er du løpecoach med egne løpere, velg det under.</p>
  <?php endif; ?>
  <?php if ($feil): ?><p class="skjema-feil"><?php echo htmlspecialchars($feil); ?></p><?php endif; ?>
  <?php if ($maal_lop): ?>
  <div style="border:2px solid hsl(var(--primary) / .45); border-radius:var(--radius);
       padding:.8rem 1.1rem; margin:0 0 1.1rem; background:hsl(var(--primary) / .06)">
    🏁 <b>Du vil trene mot: <?= htmlspecialchars($maal_lop['navn']) ?></b><?=
      $maal_lop['dato'] ? ' · ' . htmlspecialchars(substr($maal_lop['dato'], 8, 2) . '.' . substr($maal_lop['dato'], 5, 2)) : '' ?>
    <span class="liten" style="display:block; margin-top:.2rem">Planen bygges mot løpsdagen, og
    påmeldingslenken til arrangøren får du på din side.</span>
  </div>
  <?php endif; ?>
  <form method="post" action="bli-testloper.php" class="skjema"
        data-kladd="skjema1" data-en="0"
        data-kladd-ferdig="<?= $sendt ? '1' : '0' ?>"
        onsubmit="var b=this.querySelector('button[type=submit]');if(b.disabled){return false;}b.disabled=true;b.textContent='Sender …';">
    <?php if ($maal_lop): ?>
    <input type="hidden" name="lop" value="<?= htmlspecialchars($maal_lop['navn']) ?>">
    <input type="hidden" name="dato" value="<?= htmlspecialchars($maal_lop['dato']) ?>">
    <input type="hidden" name="til" value="<?= htmlspecialchars($maal_lop['pamelding']) ?>">
    <input type="hidden" name="s" value="<?= htmlspecialchars($ml_sig) ?>">
    <?php endif; ?>
    <fieldset class="sprak-felt" style="margin-bottom:.9rem">
      <legend>Jeg melder meg som</legend>
      <label class="radio"><input type="radio" name="rolle" value="loper" <?= $er_coach ? '' : 'checked' ?>> Løper</label>
      <label class="radio"><input type="radio" name="rolle" value="coach" <?= $er_coach ? 'checked' : '' ?>> Løpecoach med egne løpere</label>
    </fieldset>
    <label>Navn
      <input type="text" name="navn" required autocomplete="name"
             value="<?php echo htmlspecialchars($_POST["navn"] ?? ""); ?>">
    </label>
    <label>E-post
      <input type="email" name="epost" required autocomplete="email"
             value="<?php echo htmlspecialchars($_POST["epost"] ?? ""); ?>">
    </label>
    <label>Mobil <span class="valgfritt">(så vi kan nå deg om noe stopper opp)</span>
      <input type="tel" name="mobil" required minlength="8" autocomplete="tel" placeholder="f.eks. 900 00 000"
             value="<?php echo htmlspecialchars($_POST["mobil"] ?? ""); ?>">
    </label>
    <?php // Telegram-feltet fjernet 13.09.2026 (Odd): all løperkommunikasjon skjer i chatten på min side.
       // Backend-koden og databasekolonnen står urørt, så eldre rader og trenerregistrering virker som før. ?>
    <fieldset class="sprak-felt">
      <legend>Hvilket språk vil du ha veiledningen på?</legend>
      <label class="radio"><input type="radio" name="sprak" value="norsk" checked> Norsk</label>
      <label class="radio"><input type="radio" name="sprak" value="engelsk"> Engelsk</label>
      <label class="radio"><input type="radio" name="sprak" value="annet"> Annet:
        <input type="text" name="sprak_annet" oninput="this.closest('fieldset').querySelector('input[value=annet]').checked = this.value.trim() !== ''" placeholder="skriv her" style="width:9rem"></label>
      <p style="margin:.4rem 0 0; font-size:.88rem; opacity:.8">Veiledningen kommer på norsk eller engelsk. Skriver du et annet språk, noterer vi det, men veiledningen blir på norsk.</p>
    </fieldset>
    <label>Hvem tipset deg om Treni? <span class="valgfritt">(valgfritt, en person, sosiale medier, klubben …)</span>
      <input type="text" name="kilde" placeholder="f.eks. en venn, Facebook, Tromsø Løpeklubb"
             value="<?= htmlspecialchars(mb_substr(trim($_POST["kilde"] ?? $_GET["kilde"] ?? ""), 0, 200)) ?>">
    </label>
    <label><?= $er_coach ? 'Litt om deg og løperne dine' : 'Litt om løpingen din' ?> <span class="valgfritt"><?= $er_coach ? '(hvor mange løpere, nivå, klubb, og hva du vil at Treni skal ta av jobben)' : '(valgfritt: hva vil du oppnå?)' ?></span>
      <textarea name="om" rows="4"><?php echo htmlspecialchars($_POST["om"] ?? ""); ?></textarea>
    </label>
    <?php if (!$er_coach): ?>
    <fieldset class="sprak-felt" style="margin-top:.9rem" id="klokke-felt">
      <legend>Pulsklokke</legend>
      <label class="radio"><input type="checkbox" name="klokke" value="ja" required <?= (($_POST["klokke"] ?? "") === "ja") ? "checked" : "" ?>> Jeg har en pulsklokke som kan kobles til Intervals.icu (Garmin, Polar, Suunto, Coros eller Wahoo)</label>
      <span class="valgfritt" style="display:block;margin-top:.35rem">Treni bygger planen på øktene fra klokka di. Uten klokke kan vi ikke lage plan.</span>
    </fieldset>
    <?php endif; ?>
    <label class="krukke" aria-hidden="true">Nettside
      <input type="text" name="nettside" tabindex="-1" autocomplete="off">
    </label>
    <?php treni_spamvern_felt(); ?>
    <button type="submit" class="btn btn-primar">Kom i gang og koble klokka</button>
  </form>
  <script>
  // Revisjon 10.09 (punkt 11): velges «Løpecoach» inne i skjemaet, sto
  // klokke-avkrysningen igjen med required, og nettleseren nektet å sende.
  // Serveren krever bare klokke for løpere (linje ~50), så feltet følger radioen.
  (function () {
    var f = document.getElementById('klokke-felt'); if (!f) { return; }
    var b = f.querySelector('input[name=klokke]');
    document.querySelectorAll('input[name=rolle]').forEach(function (r) {
      r.addEventListener('change', function () {
        var coach = document.querySelector('input[name=rolle]:checked').value === 'coach';
        f.hidden = coach;
        if (b) { b.required = !coach; }
      });
    });
  })();
  </script>
  <script src="kladd.js?v=2" defer></script>
  <p class="liten" style="margin-top:1.5rem">Foretrekker du e-post? Skriv direkte til
  <a href="mailto:hei@treni.no?subject=Jeg%20vil%20teste%20Treni">hei@treni.no</a>.
  Opplysningene brukes kun til å svare deg.
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
  <p>Powered by Strava. This service is not affiliated with or endorsed by Strava.</p>
</footer>

</main>
</body>
</html>
