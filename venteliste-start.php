<?php
// Skjema 2 (Odds bestilling 19.08): personlig kode-lenke per påmeldingsrad; svarene
// lagres i venteliste.svar_json, Odd+Claude varsles i Telegram, og onboarding-vakta
// leser svarene inn i løperens rad.
// Onboarding v3 (B-130, Odd 19.09.2026, F-246 del A): to vinduer med bare det motoren
// trenger for den første uka. Vindu 1 «Deg»: alder, høyde, vekt, puls. Vindu 2
// «Løpinga di»: mål, underlag, beste tid, km/uke, økter/uke, lengste siste måned,
// siste 90 dager, faste fridager, helsesamtykke. Alt annet spørres på min side
// etterpå, ett spørsmål om dagen. Planen bygges på svarene med en gang («foreløpig
// plan»), klokka kobles fra planen og planen bygges om når historikken er inne.
// Revisjon 03.09: serveren står i UTC. «Svarene dine er mottatt»-innslaget
// fikk UTC-klokkeslett (Trond: 07:32 i stedet for 09:32).
date_default_timezone_set('Europe/Oslo');
// Engelsk (Odd 07.09, Lola): språket følger venteliste.sprak; ?lang=en/no overstyrer.
$EN = false;
function t_(string $no, string $en): string { global $EN; return $EN ? $en : $no; }
$cfg_sti = dirname(__DIR__) . "/dashbord_config.php";
$konfig = is_readable($cfg_sti) ? (include $cfg_sti) : null;
$kode = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET["k"] ?? $_POST["k"] ?? "");
$rad = null; $sendt = false; $feil = "";
if (($_GET["lang"] ?? "") === "en") { $EN = true; }

if (is_array($konfig) && $kode !== "") {
    try {
        $pdo = new PDO(
            "mysql:host={$konfig['db_host']};dbname={$konfig['db_name']};charset=utf8mb4",
            $konfig['db_user'], $konfig['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $st = $pdo->prepare("SELECT id, navn, om, svart_at, epost, status, sprak FROM venteliste WHERE kode = ?");
        $st->execute([$kode]);
        $rad = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        // B-57: er klokka koblet? Samme runner_id-regel som motoren (venteliste_inn.rid_fra_navn),
        // slått opp i intervals_nokler, så ingressen og kvitteringen sier sant om steg 1.
        $klokke_koblet = false;
        if ($rad) {
            try {
                $d_r = preg_split('/\s+/', trim((string) $rad['navn'])) ?: [];
                $raa_r = count($d_r) > 1 ? ($d_r[0] . $d_r[count($d_r) - 1]) : ($d_r[0] ?? 'loper');
                $raa_r = str_replace(['æ', 'ø', 'å'], ['ae', 'o', 'aa'], mb_strtolower($raa_r));
                $raa_r = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raa_r) ?: $raa_r;
                $rid_r = preg_replace('/[^a-z0-9_]/', '', $raa_r) ?: 'loper';
                $st_n = $pdo->prepare("SELECT 1 FROM intervals_nokler WHERE runner_id = ?");
                $st_n->execute([$rid_r]);
                $klokke_koblet = (bool) $st_n->fetchColumn();
            } catch (Throwable $e_n) { $klokke_koblet = false; }
        }
        if (!isset($_GET["lang"])) { $EN = (($rad["sprak"] ?? "") === "engelsk"); }
        elseif ($_GET["lang"] === "no") { $EN = false; }
        // Duplikat-rader (24.08-vernet, gjenoppbygd 03.09): en lenke fra en
        // duplikatrad sendes videre til nyeste ekte rad for samme e-post.
        if ($rad && ($rad["status"] ?? "") === "duplikat") {
            $st2 = $pdo->prepare("SELECT kode FROM venteliste WHERE LOWER(epost) = LOWER(?)
                AND status IN ('venter', 'minside') AND kode IS NOT NULL AND kode <> ''
                ORDER BY id DESC LIMIT 1");
            $st2->execute([$rad["epost"]]);
            if (($kode2 = $st2->fetchColumn()) && $kode2 !== $kode) {
                // Revisjon 10.09 (punkt 52): ?lang= følger med, ellers mistet en
                // ?lang=no-overstyring seg på veien.
                header("Location: /venteliste-start.php?k=" . $kode2
                       . (isset($_GET["lang"]) && in_array($_GET["lang"], ["en", "no"], true) ? "&lang=" . $_GET["lang"] : ""));
                exit;
            }
        }
    } catch (Throwable $e) { $rad = null; }
}

// Rategrense + feltgrenser (sikkerhetstest 03.09): skjemaet er bak en
// personlig kode, men koden kan lekke, maks 10 innsendinger per IP per time.
require_once __DIR__ . "/spamvern.php";
$vs_rate = false;
if ($_SERVER["REQUEST_METHOD"] === "POST" && $rad) {
    treni_begrens_post(["maal" => 600], 200);
    foreach ($_POST as $vs_k => $vs_v) {
        $vs_fl = in_array($vs_k, ["maal"], true);
        // Lister (avkrysningsbokser) vaskes element for element, treni_ren tar
        // bare strenger, og et array her ville kastet TypeError (Lola 09.09).
        $_POST[$vs_k] = is_array($vs_v)
            ? array_map(fn($x) => treni_ren((string) $x, 2000, $vs_fl), $vs_v)
            : treni_ren($vs_v, 2000, $vs_fl);
    }
    if (treni_rategrense("vlstart|" . treni_ip(), 20, 3600)) {
        treni_sikkerhet_logg("venteliste-start: rategrense (20/t) nådd");
        http_response_code(429);
        header("Retry-After: 900");
        $vs_rate = true;
        // Lola 08. til 09.09.2026: sperren slo inn etter 10 forsøk og hoppet BARE
        // over lagringen. Sida rendret på nytt uten ett ord om hvorfor, så det
        // så ut som skjemaet hang. Hun prøvde seks ganger til. En sperre som
        // ikke sier fra er en feil, ikke et vern.
        $feil = t_("Du har prøvd mange ganger på kort tid, så vi har satt en kort pause "
                   . "(15 minutter). Svarene dine er lagret i nettleseren, så du mister "
                   . "ingenting: vent litt og trykk send på nytt.",
                   "You have tried many times in a short while, so we have paused the form "
                   . "for 15 minutes. Your answers are saved in your browser, so nothing is "
                   . "lost: wait a moment and press send again.");
    }
}
if ($_SERVER["REQUEST_METHOD"] === "POST" && $rad && !$vs_rate && trim($_POST["nettside"] ?? "") === "") {
    // Onboarding v3 (F-246): bare feltene den første uka trenger. Alt annet spørres
    // på min side etterpå (svar_endre.php, samme nøkler), så svar_json får bare det
    // som faktisk ble svart her. venteliste_inn.py leser alle feltene med .get().
    $tall = fn(string $n) => trim($_POST[$n] ?? "") === "" ? null : (float) str_replace(",", ".", $_POST[$n]);
    $svar = [
        "maal"      => mb_substr(trim($_POST["maal"] ?? ""), 0, 2500),
        "alder"     => (int) ($_POST["alder"] ?? 0),
        // 44a (Eirik 16.09): høyde og vekt, KMI regnes i motoren og styrer regelverket
        // for myke løpere. Vises aldri for løperen. Samme nøkler som svar_endre.php.
        "hoyde"     => trim($_POST["hoyde"] ?? "") === "" ? null : (int) $_POST["hoyde"],
        "vekt"      => $tall("vekt"),
        "puls"      => trim($_POST["puls"] ?? "") === "" ? null : (int) $_POST["puls"],
        "beste_tid" => mb_substr(trim($_POST["beste_tid"] ?? ""), 0, 120),
        "km_uke"    => (float) str_replace(",", ".", $_POST["km_uke"] ?? "0"),
        // B6-vernet (Odd 22.08, Silvia-caset): langtur foreskrives aldri over
        // lengste økt siste 30 dager +10 %, generatoren leser dette feltet.
        "lengste_30d" => $tall("lengste_30d"),
        "underlag"  => in_array($_POST["underlag"] ?? "", ["vei", "terreng", "begge"], true)
                       ? $_POST["underlag"] : "begge",
        // «Økter per uke» er LØPEøkter (Odd 04.09, Trond-caset). Styrke og annen
        // trening spørres om etter planen.
        "n_okter" => trim($_POST["n_okter"] ?? "") === "" ? null
            : max(1, min(14, (int) $_POST["n_okter"])),
        // 90-dagersbildet (Eiriks regelverk §2 til 5, Odds go 23.08): grunnlaget for
        // nivåklassifisering og korridor når historikken fra klokka mangler.
        "siste_90d" => in_array($_POST["siste_90d"] ?? "", ["jevn", "ujevn", "opphold", "mer_for"], true)
                       ? $_POST["siste_90d"] : "jevn",
        // Faste fridager: planen legger aldri en løpeøkt der. Samme nøkkel og
        // verdier som onboarding_felt.py / svar_endre.php.
        "hviledag_dag" => (function () {
            $lov = ["ingen", "mandag", "tirsdag", "onsdag", "torsdag", "fredag", "lordag", "sondag"];
            $d = array_values(array_intersect((array) ($_POST["hviledag"] ?? []), $lov));
            if (in_array("ingen", $d, true)) { $d = ["ingen"]; }
            return $d ? implode(",", $d) : null;
        })(),
    ];
    // Helsesamtykke (personvern 04.09.2026, GDPR art. 9): eget, uttrykkelig
    // steg. Uten avkrysning lagres ingenting, og svarene bevares i skjemaet.
    $helse_ok = (($_POST["samtykke_helse"] ?? "") === "ja");
    if ($helse_ok) {
        $svar["samtykke_helse"] = ["tidspunkt" => date("c"), "versjon" => "2026-09-04"];
    }
    if ($svar["alder"] < 10 || $svar["alder"] > 99 || $svar["puls"] === null) {
        $feil = t_("Vindu 1 «Deg» mangler alder eller høyeste puls.", "Window 1 \"You\" is missing age or highest heart rate.");
    } elseif ($svar["maal"] === "" || $svar["km_uke"] <= 0 || $svar["beste_tid"] === "" || $svar["hviledag_dag"] === null) {
        // Lola 08.09: si hvilket felt som mangler, ikke bare «punkt 2».
        $mangler = [];
        if ($svar["maal"] === "")            { $mangler[] = t_("målet ditt", "your goal"); }
        if ($svar["km_uke"] <= 0)            { $mangler[] = t_("kilometer i uka", "kilometres per week"); }
        if ($svar["beste_tid"] === "")       { $mangler[] = t_("beste tid siste halvår (skriv «ingen» om du ikke har løpt noe)", "best time in the last six months (write \"none\" if you have not raced)"); }
        if ($svar["hviledag_dag"] === null)  { $mangler[] = t_("faste fridager (eller «ingen fast dag»)", "fixed rest days (or \"no fixed day\")"); }
        $feil = t_("Vindu 2 «Løpinga di» mangler: ", "Window 2 \"Your running\" is missing: ") . implode(", ", $mangler) . ".";
    } elseif (!$helse_ok) {
        $feil = t_("Vi trenger et ja til å behandle helseopplysninger (nederst i vindu 2) før vi kan lage planen din. Svarene dine er tatt vare på i skjemaet.", "We need your yes to processing health data (at the bottom of window 2) before we can build your plan. Your answers are kept in the form.");
    } else {
        $pdo->prepare("UPDATE venteliste SET svar_json = ?, svart_at = NOW() WHERE kode = ?")
            ->execute([json_encode($svar, JSON_UNESCAPED_UNICODE), $kode]);
        // Status følger reisen automatisk (Odds regel 19.08), aldri nedgrader
        // Onboarding-svar logges i «Siste endringer» på løperens side (Odd 31.08)
        $vl_fil = __DIR__ . "/../../min.treni.no/public_html/venteliste_data/{$kode}.json";
        $vl_d = @json_decode((string) @file_get_contents($vl_fil), true);
        // Odds presisering 01.09: selve SVARENE skal stå i innslaget.
        // sammendraget bygges FØR fil-sjekken, så også helt nye løpere
        // (bygges-stubben under) får det.
        // Revisjon 10.09 (punkt 3 og 40): ingen plan loves her (S-87: neste steg
        // står på siden), og teksten følger løperens språk.
        // Onboarding v3: planen bygges på svarene med en gang, så kvitteringen sier det.
        $vl_sam = t_('📝 Svarene dine er lagret, og den foreløpige planen bygges nå. ', '📝 Your answers are saved, and your preliminary plan is being built now. ')
                . t_('Du svarte: Mål: ', 'You answered: Goal: ') . $svar['maal']
                . t_(' · Alder: ', ' · Age: ') . $svar['alder']
                . ' · ' . $svar['km_uke'] . t_(' km/uke', ' km/week')
                . ($svar['n_okter'] !== null ? ' · ' . $svar['n_okter'] . t_(' løpeøkter/uke', ' runs/week') : '')
                . ($svar['lengste_30d'] !== null ? t_(' · lengste siste måned: ', ' · longest last month: ') . $svar['lengste_30d'] . ' km' : '')
                . t_(' · underlag: ', ' · surface: ') . $svar['underlag']
                . t_(' · puls: ', ' · heart rate: ') . ($svar['puls'] ?? t_('ukjent', 'unknown'))
                . ($svar['beste_tid'] !== '' ? t_(' · beste tid: ', ' · best time: ') . $svar['beste_tid'] : '')
                . t_(' · siste 90 dager: ', ' · last 90 days: ') . $svar['siste_90d']
                . ($svar['hviledag_dag'] !== null ? t_(' · fridager: ', ' · rest days: ') . $svar['hviledag_dag'] : '') . '.';
        if (is_array($vl_d)) {
            $vl_d['endringer'] = $vl_d['endringer'] ?? [];
            array_unshift($vl_d['endringer'], ['dato' => date('d.m \\k\\l. H:i'),
                'tekst' => $vl_sam]);
            @file_put_contents($vl_fil, json_encode($vl_d, JSON_UNESCAPED_UNICODE));
        }
        $pdo->prepare("UPDATE venteliste SET status = 'minside' WHERE kode = ? AND status = 'venter'")
            ->execute([$kode]);
        if (defined("TRENI_BOT_TOKEN")) {
            @file_get_contents("https://api.telegram.org/bot" . TRENI_BOT_TOKEN . "/sendMessage",
                false, stream_context_create(["http" => [
                    "method" => "POST",
                    "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
                    "content" => http_build_query([
                        "chat_id" => defined("TRENI_ODD_CHAT") ? TRENI_ODD_CHAT : TRENI_TRENER_CHAT,   // S-87: drift, ikke trener
                        "text" => "📝 Skjema 2 levert (onboarding v3): " . $rad["navn"]
                                . " (id " . $rad["id"] . ")\n"
                                . "Side: https://min.treni.no/venteliste.php?t=" . $kode . "\n"
                                . "Mål: " . $svar["maal"] . "\n"
                                . "Alder: " . $svar["alder"]
                                . ($svar["hoyde"] !== null && $svar["vekt"] !== null ? " · høyde/vekt oppgitt" : " · høyde/vekt ikke oppgitt")
                                . " · km/uke: " . $svar["km_uke"]
                                . " · underlag: " . $svar["underlag"] . "\n"
                                . "Økter: " . ($svar["n_okter"] !== null ? $svar["n_okter"] . " løp/uke" : "ikke oppgitt")
                                . ($svar["lengste_30d"] !== null ? " · lengste siste måned " . $svar["lengste_30d"] . " km" : "") . "\n"
                                . "Puls: " . ($svar["puls"] ?? "ukjent")
                                . " · beste tid: " . ($svar["beste_tid"] ?: "ingen") . "\n"
                                . "Siste 90 d: " . $svar["siste_90d"]
                                . " · fridager: " . ($svar["hviledag_dag"] ?? "ingen oppgitt") . "\n\n"
                                . "Klokke: " . ($klokke_koblet ? "koblet (Intervals)" : "ikke koblet ennå") . ". "
                                . "Onboarding v3: den foreløpige planen bygges på svarene nå, resten av spørsmålene kommer på min side ett om dagen."]),
                    "timeout" => 8]]));
        }
        // Revisjon 10.09 (punkt 31): «bygges»-stubben til den pensjonerte
        // ventelistesida skrives ikke lenger. venteliste.php viser samme
        // ventebilde når fila mangler, og onboarding-vakta lager min side-token.
        $sendt = true;
    }
}
?><!doctype html>
<html lang="<?= $EN ? 'en' : 'nb' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= t_('Kom i gang · Treni', 'Get started · Treni') ?></title>
<meta name="description" content="<?= t_('Svar på noen få spørsmål, så bygger vi din egen side med en plan tilpasset deg.', 'Answer a few questions and we build your page with a plan made for you.') ?>">
<meta property="og:title" content="<?= t_('Kom i gang med Treni', 'Get started with Treni') ?>">
<meta property="og:description" content="<?= t_('Svar på noen få spørsmål, så bygger vi din egen side med en plan tilpasset deg.', 'Answer a few questions and we build your page with a plan made for you.') ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="https://treni.no/venteliste-start.php">
<meta property="og:image" content="https://treni.no/bilder/og.jpg">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" type="image/png" href="/icon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="stil.css?v=30">
<style>
  /* Kortene følger temaet (Odd 06.09: i mørk modus sto hvite kort med
     lys tekst, overskrifter og ledetekster var usynlige). */
  .vl-kort{background:hsl(var(--surface));color:hsl(var(--fg));
    border:1.5px solid hsl(var(--border));border-radius:14px;
    padding:1.1rem 1.3rem;display:grid;gap:.9rem}
  .vl-kort legend, .vl-tittel{font-weight:700;font-size:1rem;padding:0 .3rem;color:hsl(var(--fg))}
  /* Odd 07.09: overskriften lå på rammen og så ut som den sto utenfor boksen.
     nå ligger den inne i kortet som en vanlig tittel. */
  .vl-kort legend{float:left;width:100%;padding:0;margin:0 0 .15rem;font-size:1.05rem}
  .vl-kort legend + *{clear:both}
  .vl-kort label{display:grid;gap:.3rem;font-weight:600;font-size:.93rem;margin:0;color:hsl(var(--fg))}
  .vl-kort input,.vl-kort textarea,.vl-kort select{width:100%;font:inherit;color:hsl(var(--fg));
    border:1.5px solid hsl(var(--border));border-radius:10px;padding:.55rem .75rem;
    background:hsl(var(--surface-2))}
  .vl-kort input::placeholder,.vl-kort textarea::placeholder{color:hsl(var(--muted-fg))}
  .vl-kort input:focus,.vl-kort textarea:focus,.vl-kort select:focus{
    outline:2px solid hsl(var(--primary));outline-offset:1px}
  .vl-hint{font-weight:400;font-size:.8rem;color:hsl(var(--muted-fg))}
  /* Odd 07.09 (Lola): forhåndsutfylt tekst ble klippet i 3-linjers feltet, feltene
     vokser med innholdet, både ved lasting og mens løperen skriver. */
  .vl-kort textarea{overflow:hidden;resize:vertical;line-height:1.45;min-height:4.6em}
  .vl-to{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
  /* Uten denne strekkes radene i en to-kolonners boks slik at feltet i den
     kolonnen som mangler hjelpetekst havner lavere enn nabofeltet (04.09). */
  .vl-to > label{align-content:start}
  @media (max-width:480px){.vl-to{grid-template-columns:1fr}}
  .vl-sp{font-weight:600;font-size:.93rem;margin:0 0 .45rem;color:hsl(var(--fg))}
  .vl-chips{display:flex;flex-wrap:wrap;gap:.45rem;margin:0 0 1rem}
  /* Lola 08.09 kl. 22:30: «the text appears white on white». Brikkene og
     nedtrekkene hadde hardkodet lys bakgrunn mens tekstfargen fulgte temaet,
     så i mørk modus ble lys tekst stående på lys flate. Alt følger temaet nå. */
  .vl-kort .vl-chip{display:inline-flex;align-items:center;gap:0;padding:.42rem .9rem;border:1.5px solid hsl(var(--border));border-radius:999px;background:hsl(var(--surface-2));color:hsl(var(--fg));font-weight:500;font-size:.9rem;cursor:pointer;margin:0;position:relative;user-select:none}
  .vl-kort .vl-chip input{position:absolute;opacity:0;width:0;height:0;margin:0}
  .vl-kort .vl-chip:has(input:checked){background:hsl(152 62% 20%);border-color:hsl(152 62% 20%);color:#fff}
  .vl-kort .vl-chip:has(input:focus-visible){outline:2px solid hsl(152 62% 40%);outline-offset:2px}
  .vl-kort select{width:100%;padding:.6rem .7rem;border-radius:10px;font:inherit;font-weight:400}
  /* Safari/iOS tegner <option> med systemfargene sine; uten disse to ble
     valgene usynlige i mørk modus selv når selve feltet var riktig. */
  .vl-kort select option{background:hsl(var(--surface-2));color:hsl(var(--fg))}
  /* F-36 (Odd 10.09): én seksjon per skjerm. Skjulte seksjoner ligger i DOM-en
     (kladden fanger dem), men vises ikke. !important fordi .vl-kort setter
     display:grid, som ellers slår hidden-attributtet. */
  .vl-skjult{display:none !important}
  .vl-fram{margin:0 0 .4rem;font-size:.72rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:hsl(var(--muted-fg));text-align:center}
  .vl-nav{display:grid;gap:.7rem}
  .vl-stor{display:block;width:100%;box-sizing:border-box;padding:1rem 1.2rem;border-radius:999px;border:0;background:hsl(var(--primary));color:hsl(var(--primary-fg));font:inherit;font-weight:750;font-size:1.08rem;text-align:center;text-decoration:none;cursor:pointer;box-shadow:var(--shadow-cta)}
  .vl-stor:hover{filter:brightness(1.08)}
  .vl-stor:disabled{opacity:.55;cursor:default}
  .vl-tilbake{display:block;width:100%;background:none;border:0;color:hsl(var(--muted-fg));font:inherit;font-size:.9rem;font-weight:600;cursor:pointer;padding:.4rem;text-align:center}
  .vl-tilbake:hover{color:hsl(var(--fg))}
  .vl-slutt{display:grid;gap:.7rem}
  /* display:block over slår hidden-attributtet, som på fieldsetene. */
  .vl-stor[hidden],.vl-tilbake[hidden],.vl-slutt[hidden]{display:none !important}
</style>
</head>
<body>
<main class="smal" style="max-width:34rem; margin:0 auto; padding:2.5rem 1.2rem">
  <p class="kicker"><a href="index.html" style="color:inherit">treni.no</a> · <?= t_('kom i gang', 'get started') ?></p>

<?php if (!$rad): ?>
  <h1 style="font-size:clamp(1.6rem,5vw,2.2rem)"><?= t_('Lenken virker ikke lenger', 'This link no longer works') ?></h1>
  <p><?= t_('Denne lenken er personlig og ser ut til å være ugyldig. Skriv til', 'This link is personal and seems to be invalid. Write to') ?>
  <a href="mailto:hei@treni.no">hei@treni.no</a><?= t_(', så hjelper vi deg.', ' and we\'ll help you.') ?></p>

<?php elseif ($sendt || $rad["svart_at"]): ?>
  <h1 style="font-size:clamp(1.6rem,5vw,2.2rem)"><?= t_('Takk, ', 'Thanks, ') ?><?= htmlspecialchars(mb_convert_case(explode(" ", trim((string) $rad["navn"]))[0], MB_CASE_TITLE, "UTF-8")) ?>! 🎉</h1>
  <?php // F-36 (Odd 10.09): kvitteringen er én skjerm med én knapp. Onboarding v3 (F-246):
        // planen bygges på svarene med en gang, klokka kobles fra planen på min side. ?>
  <p><?= t_('Svarene dine er lagret. Planen din bygges nå, det tar et par minutter. Gå tilbake til siden din.', 'Your answers are saved. Your plan is being built now, it takes a couple of minutes. Go back to your page.') ?></p>
  <script>try { Object.keys(localStorage).forEach(function (n) {
    if (n.indexOf('treni_kladd_') === 0) { localStorage.removeItem(n); }
  }); } catch (e) {}</script>
  <p style="margin:1.4rem 0"><a href="https://min.treni.no/?t=<?= htmlspecialchars($kode) ?>&amp;lang=<?= $EN ? 'en' : 'no' ?>"
     class="vl-stor"><?= t_('Til siden din', 'To your page') ?></a></p>
  <?php // Odd 09.09: e-bok-linja er tatt ut av kvitteringen. Neste steg er klokka og
  // ingenting annet (B-45/B-46), og en flat løper skal ikke få fjelløping som første tilbud. ?>

<?php else: ?>
  <?php if (isset($_GET['ny'])): // rett fra «meld interesse» (Odd 20.08) ?>
  <p style="background:color-mix(in srgb, var(--accent, hsl(84 80% 34%)) 12%, transparent);
     border-radius:10px; padding:.6rem .9rem; font-weight:650; margin-bottom:.4rem">
     <?= t_('✅ Takk! Nå: spørsmålene.', '✅ Thanks! Now: the questions.') ?></p>
  <?php endif; ?>
  <h1 style="font-size:clamp(1.6rem,5vw,2.2rem)"><?= t_('Velkommen, ', 'Welcome, ') ?><?= htmlspecialchars(mb_convert_case(explode(" ", trim((string) $rad["navn"]))[0], MB_CASE_TITLE, "UTF-8")) ?>!</h1>
  <?php // F-36 (Odd 10.09): én seksjon per skjerm, så ingressen er én setning.
        // Onboarding v3 (F-246): to vinduer, planen bygges på svarene med en gang. ?>
  <p><?= t_('Fire minutter, så bygger vi den første uka di. To vinduer: først deg, så løpinga di.', 'Four minutes, then we build your first week. Two windows: first you, then your running.') ?></p>

  <?php if ($feil): ?><p class="skjema-feil"><?= htmlspecialchars($feil) ?></p><?php endif; ?>
  <?php // Revisjon 03.09: svarene bevares ved valideringsfeil (før ble alt tømt)
  $val = fn(string $n): string => htmlspecialchars((string) ($_POST[$n] ?? ""));
  $sel = fn(string $n, string $v, string $std = ""): string => (($_POST[$n] ?? $std) === $v) ? " selected" : ""; ?>

  <form method="post" style="display:grid; gap:1.1rem; margin-top:1.2rem"
        data-kladd="skjema2" data-en="<?= $EN ? '1' : '0' ?>"
        data-kladd-ferdig="<?= $sendt ? '1' : '0' ?>"
        data-sender="<?= $EN ? 'Sending …' : 'Sender …' ?>"
        data-send="<?= $EN ? 'Save and build my plan' : 'Lagre og lag planen min' ?>">
    <input type="hidden" name="k" value="<?= htmlspecialchars($kode) ?>">
    <input type="text" name="nettside" value="" style="display:none" tabindex="-1" autocomplete="off">

    <fieldset class="vl-kort" data-vindu="<?= t_('Deg', 'You') ?>">
      <legend><?= t_('Deg 🫀', 'You 🫀') ?></legend>
      <div class="vl-to">
        <label><?= t_('Alder', 'Age') ?>
          <input type="number" name="alder" min="10" max="99" required value="<?= $val('alder') ?>"></label>
        <label><?= t_('Høyeste puls du har målt siste halvår', 'Highest heart rate you have measured in the last six months') ?>
          <input type="number" name="puls" min="120" max="230" required placeholder="<?= t_('f.eks. 185', 'e.g. 185') ?>" value="<?= $val('puls') ?>">
          <span class="vl-hint"><?= t_('Har du ikke målt, bruk det høyeste tallet klokka har vist deg på en hard økt. Målt puls fra klokka teller mer enn tallet her.', 'If you have not measured it, use the highest number your watch has shown you in a hard session. Measured heart rate from your watch counts more than the number here.') ?></span></label>
      </div>
      <div class="vl-to">
        <label><?= t_('Høyde (cm)', 'Height (cm)') ?>
          <input type="number" name="hoyde" min="100" max="250" step="1" placeholder="<?= t_('f.eks. 178', 'e.g. 178') ?>" value="<?= $val('hoyde') ?>"></label>
        <label><?= t_('Vekt (kg)', 'Weight (kg)') ?>
          <input type="number" name="vekt" min="30" max="250" step="0.5" placeholder="<?= t_('f.eks. 74', 'e.g. 74') ?>" value="<?= $val('vekt') ?>"></label>
      </div>
      <span class="vl-hint"><?= t_('Høyde og vekt brukes bare til å velge riktig regelverk for kroppen din, og vises aldri på siden.', 'Height and weight are used only to pick the right rules for your body, and are never shown on your page.') ?></span>
    </fieldset>

    <fieldset class="vl-kort" data-vindu="<?= t_('Løpinga di', 'Your running') ?>">
      <legend><?= t_('Løpinga di 👟', 'Your running 👟') ?></legend>
      <label><?= t_('Hva er målet ditt med løpinga? Kort: hva og når.', 'What is your goal with running? Briefly: what and by when.') ?>
        <textarea name="maal" rows="3" required
          placeholder="<?= t_('F.eks.: gjøre det bra i noen få fjelløp i året …', 'e.g. do well in a few mountain races a year …') ?>"><?= htmlspecialchars(trim($_POST["maal"] ?? "")) ?></textarea></label>
      <?php // Odd 07.09 (Lola): «om løpingen» fra påmeldingen er ikke et mål, vises
            // som sitat, ikke forhåndsutfylt i målfeltet. Teksten følger uansett med
            // til treneren og motoren (venteliste.om → kommentar). ?>
      <?php if (trim((string) ($rad["om"] ?? "")) !== ""): ?>
      <span class="vl-hint" style="border-left:3px solid hsl(var(--border)); padding-left:.6rem; white-space:pre-wrap"><b><?= t_('Du skrev ved påmelding:', 'You wrote when signing up:') ?></b>
<?= htmlspecialchars(trim((string) $rad["om"])) ?>

<i><?= t_('Det tar vi med oss. Skriv målet ditt over, kort: hva vil du oppnå, og når?', 'We keep that. Write your goal above, briefly: what do you want to achieve, and by when?') ?></i></span>
      <?php endif; ?>
      <label><?= t_('Hvor løper du mest?', 'Where do you run most?') ?>
        <select name="underlag">
          <option value="terreng"<?= $sel('underlag', 'terreng', 'begge') ?>><?= t_('Mest terreng og fjell', 'Mostly trails and mountains') ?></option>
          <option value="vei"<?= $sel('underlag', 'vei', 'begge') ?>><?= t_('Mest vei og asfalt', 'Mostly road and asphalt') ?></option>
          <option value="begge"<?= $sel('underlag', 'begge', 'begge') ?>><?= t_('Begge deler', 'Both') ?></option>
        </select></label>
      <label><?= t_('Beste tid siste halvår, med distanse', 'Best time in the last six months, with distance') ?> <span class="vl-hint"><?= t_('ingen løp? Skriv «ingen»', 'no races? Write "none"') ?></span>
        <input type="text" name="beste_tid" required maxlength="120" placeholder="<?= t_('F.eks. 10 km på 52:30', 'e.g. 10 km in 52:30') ?>" value="<?= $val('beste_tid') ?>"></label>
      <div class="vl-to">
        <label><?= t_('Kilometer i en vanlig uke', 'Kilometres in a normal week') ?>
          <input type="number" name="km_uke" min="1" max="200" step="0.5" required value="<?= $val('km_uke') ?>"></label>
        <label><?= t_('Løpeøkter per uke', 'Running sessions per week') ?>
          <input type="number" name="n_okter" min="1" max="14" step="1" required
                 placeholder="<?= t_('f.eks. 3', 'e.g. 3') ?>" value="<?= $val('n_okter') ?>">
          <span class="vl-hint"><?= t_('Bare løpingen. Styrke og annen trening spør vi om etterpå.', 'Running only. We ask about strength and other training afterwards.') ?></span></label>
      </div>
      <div class="vl-to">
        <label><?= t_('Lengste tur siste 30 dager (km)', 'Longest run in the last 30 days (km)') ?>
          <input type="number" name="lengste_30d" min="0" max="200" step="0.5"
                 placeholder="<?= t_('f.eks. 12', 'e.g. 12') ?>" value="<?= $val('lengste_30d') ?>"></label>
        <label><?= t_('De siste 3 månedene', 'The last 3 months') ?>
          <select name="siste_90d">
            <option value="jevn"<?= $sel('siste_90d', 'jevn', 'jevn') ?>><?= t_('Jevn, trent omtrent som nå', 'Steady, trained about like now') ?></option>
            <option value="ujevn"<?= $sel('siste_90d', 'ujevn', 'jevn') ?>><?= t_('Ujevn, litt av og på', 'Uneven, on and off') ?></option>
            <option value="opphold"<?= $sel('siste_90d', 'opphold', 'jevn') ?>><?= t_('Opphold, pause eller svært lite', 'Break, a pause or very little') ?></option>
            <option value="mer_for"<?= $sel('siste_90d', 'mer_for', 'jevn') ?>><?= t_('Jeg trente MER før enn nå', 'I trained MORE before than now') ?></option>
          </select></label>
      </div>
      <div class="vl-sp"><?= t_('Er det faste dager du vil ha fri?', 'Are there fixed days you want off?') ?> <span class="vl-hint"><?= t_('(velg én eller flere)', '(pick one or more)') ?></span></div>
      <div class="vl-chips" id="hviledager">
          <label class="vl-chip"><input type="checkbox" name="hviledag[]" value="ingen" <?= in_array('ingen', (array) ($_POST['hviledag'] ?? []), true) ? 'checked' : '' ?>><span><?= t_('ingen fast dag', 'no fixed day') ?></span></label>
          <label class="vl-chip"><input type="checkbox" name="hviledag[]" value="mandag" <?= in_array('mandag', (array) ($_POST['hviledag'] ?? []), true) ? 'checked' : '' ?>><span><?= t_('mandag', 'Monday') ?></span></label>
          <label class="vl-chip"><input type="checkbox" name="hviledag[]" value="tirsdag" <?= in_array('tirsdag', (array) ($_POST['hviledag'] ?? []), true) ? 'checked' : '' ?>><span><?= t_('tirsdag', 'Tuesday') ?></span></label>
          <label class="vl-chip"><input type="checkbox" name="hviledag[]" value="onsdag" <?= in_array('onsdag', (array) ($_POST['hviledag'] ?? []), true) ? 'checked' : '' ?>><span><?= t_('onsdag', 'Wednesday') ?></span></label>
          <label class="vl-chip"><input type="checkbox" name="hviledag[]" value="torsdag" <?= in_array('torsdag', (array) ($_POST['hviledag'] ?? []), true) ? 'checked' : '' ?>><span><?= t_('torsdag', 'Thursday') ?></span></label>
          <label class="vl-chip"><input type="checkbox" name="hviledag[]" value="fredag" <?= in_array('fredag', (array) ($_POST['hviledag'] ?? []), true) ? 'checked' : '' ?>><span><?= t_('fredag', 'Friday') ?></span></label>
          <label class="vl-chip"><input type="checkbox" name="hviledag[]" value="lordag" <?= in_array('lordag', (array) ($_POST['hviledag'] ?? []), true) ? 'checked' : '' ?>><span><?= t_('lørdag', 'Saturday') ?></span></label>
          <label class="vl-chip"><input type="checkbox" name="hviledag[]" value="sondag" <?= in_array('sondag', (array) ($_POST['hviledag'] ?? []), true) ? 'checked' : '' ?>><span><?= t_('søndag', 'Sunday') ?></span></label>
      </div>
      <span class="vl-hint"><?= t_('Planen starter på volumet kroppen din er vant til, og bygges trygt derfra. Resten av spørsmålene kommer på siden din etterpå, ett om dagen.', 'The plan starts at the volume your body is used to and builds safely from there. The rest of the questions come on your page afterwards, one a day.') ?></span>
      <div id="helsesamtykke" style="border-top:1.5px solid hsl(var(--border)); padding-top:.9rem; margin-top:.3rem">
      <div class="vl-sp"><?= t_('Samtykke til helseopplysninger 🔏', 'Consent to health data 🔏') ?></div>
      <label style="display:grid; grid-template-columns:auto 1fr; gap:.6rem; align-items:start; font-weight:400">
        <input type="checkbox" name="samtykke_helse" value="ja" required
               style="width:1.25rem; height:1.25rem; margin-top:.15rem"<?= (($_POST["samtykke_helse"] ?? "") === "ja") ? " checked" : "" ?>>
<?php if ($EN): ?>
        <span><b>Yes, Treni may process health data about me.</b> This covers heart rate
          from my watch and what I tell you about injuries, illness and my body. The data is
          used only for my training guidance, seen only by my coach and Eirik Haugsnes and
          Odd Levi Paulsen, and sent as extracts to Anthropic to phrase the advice. I can
          withdraw consent at any time by writing to
          <a href="mailto:hei@treni.no">hei@treni.no</a> or in the chat on my page.
          <span class="vl-hint" style="display:block; margin-top:.4rem">Heart rate, injuries and
          illness are health data. The law requires a separate yes from you before we can use
          them. Read the <a href="en/privacy.html" target="_blank" rel="noopener">privacy policy</a>.</span>
        </span>
<?php else: ?>
        <span><b>Ja, Treni kan behandle helseopplysninger om meg.</b> Det gjelder puls fra
          klokka og det jeg selv forteller om skader, sykdom og kroppen min. Opplysningene
          brukes bare til treningsveiledningen min, sees bare av treneren min og
          Eirik Haugsnes og Odd Levi Paulsen, og sendes som utdrag til Anthropic for å lage rådtekst. Jeg kan
          trekke samtykket når som helst ved å skrive til
          <a href="mailto:hei@treni.no">hei@treni.no</a> eller i chatten på siden min.
          <span class="vl-hint" style="display:block; margin-top:.4rem">Puls, skader og sykdom
          er helseopplysninger. Loven krever et eget ja fra deg før vi kan bruke dem. Les
          <a href="personvern.html" target="_blank" rel="noopener">personvernerklæringen</a>.</span>
        </span>
<?php endif; ?>
      </label>
      </div>
    </fieldset>

    <button class="btn vl-stor" type="submit"><?= t_('Lagre og lag planen min', 'Save and build my plan') ?></button>
  </form>
  <script src="kladd.js?v=3" defer></script>
  <p class="liten" style="margin-top:1rem"><?= t_('Svarene brukes bare til å lage veiledningen din, og slettes hvis du ber om det. Du kan endre dem på siden din når som helst.', 'Your answers are used only to build your guidance, and deleted if you ask. You can edit them on your page at any time.') ?></p>
<?php endif; ?>

  <footer style="margin-top:3rem" class="liten">
    <p>PAULSEN UTVIKLING · org.nr 938 158 614 · <?= t_('Norge', 'Norway') ?> ·
       <a href="mailto:hei@treni.no">hei@treni.no</a></p>
  </footer>
<script>
// F-36 / B-63 (Odd 10.09.2026): «Onboardingen bør vise et og et valg i hvert
// bilde.» Onboarding v3 (F-246, 19.09): skjema 2 er to vinduer, ett fieldset per
// vindu: 1 «Deg», 2 «Løpinga di» med samtykke og send. «Neste»
// validerer bare feltene på skjermen (nettleserens egen required-sjekk på de
// synlige feltene), «Tilbake» går uten sjekk, kladden lagres ved hvert
// skjermskifte, og skjermen huskes så en tilbakevending lander samme sted.
// Serverens validering og feltene er UENDRET (onboarding_felt.py --sjekk).
// Skjemaet får novalidate FØRST HER, så uten JS er alt synlig med vanlig
// nettleser-validering.
(function () {
  var f = document.querySelector('form[data-kladd="skjema2"]');
  if (!f) { return; }
  var EN = <?= $EN ? 'true' : 'false' ?>;
  var seksjoner = Array.prototype.filter.call(f.children, function (e) {
    return e.tagName === 'FIELDSET' && e.classList.contains('vl-kort');
  });
  if (seksjoner.length < 2) { return; }
  // Ett vindu per seksjon (F-246). Skjermene er lister av seksjoner, som før.
  var skjermer = seksjoner.map(function (s) { return [s]; });
  var N = skjermer.length;
  function feltI(i) {
    var alle = [];
    skjermer[i].forEach(function (s) {
      Array.prototype.push.apply(alle, s.querySelectorAll('input, select, textarea'));
    });
    return alle;
  }
  var kode = (location.search.match(/[?&]k=([A-Za-z0-9_-]+)/) || [])[1] || '';
  // Samme prefiks som kladden, så kvitteringen rydder begge.
  var NOKKEL = 'treni_kladd_skjema2' + (kode ? '_' + kode : '') + '_skjerm';
  var aktiv = 0;
  f.noValidate = true;

  var fram = document.createElement('p');
  fram.className = 'vl-fram';
  f.insertBefore(fram, seksjoner[0]);

  var send = f.querySelector('button[type="submit"]');
  var slutt = document.createElement('div');
  slutt.className = 'vl-slutt';
  if (send) { send.parentNode.insertBefore(slutt, send); slutt.appendChild(send); }

  var nav = document.createElement('div');
  nav.className = 'vl-nav';
  var neste = document.createElement('button');
  neste.type = 'button'; neste.className = 'vl-stor';
  neste.textContent = EN ? 'Next →' : 'Neste →';
  var tilbake = document.createElement('button');
  tilbake.type = 'button'; tilbake.className = 'vl-tilbake';
  tilbake.textContent = EN ? '← Back' : '← Tilbake';
  nav.appendChild(neste);
  nav.appendChild(slutt);
  nav.appendChild(tilbake);
  f.appendChild(nav);

  function lagre() {
    if (typeof f.lagreTreniKladd === 'function') { f.lagreTreniKladd(); }
  }
  function vis(i) {
    aktiv = Math.max(0, Math.min(N - 1, i));
    skjermer.forEach(function (gruppe, j) {
      gruppe.forEach(function (s) { s.classList.toggle('vl-skjult', j !== aktiv); });
    });
    var navn = skjermer[aktiv][0].getAttribute('data-vindu') || '';
    fram.textContent = (aktiv + 1) + (EN ? ' of ' : ' av ') + N + (navn ? ' · ' + navn : '');
    tilbake.hidden = aktiv === 0;
    neste.hidden = aktiv === N - 1;
    slutt.hidden = aktiv !== N - 1;
    // Tekstfeltene vokser med innholdet, men måler 0 mens de er skjult.
    if (typeof window.treniVoks === 'function') {
      skjermer[aktiv].forEach(function (s) {
        Array.prototype.forEach.call(s.querySelectorAll('textarea'), window.treniVoks);
      });
    }
    try { localStorage.setItem(NOKKEL, String(aktiv)); } catch (e) {}
  }
  function gyldig(i) {
    var felt = feltI(i);
    for (var k = 0; k < felt.length; k++) {
      if (!felt[k].checkValidity()) { felt[k].reportValidity(); return false; }
    }
    var g = null;
    skjermer[i].forEach(function (s) { g = g || s.querySelector('#hviledager'); });
    if (g && !g.querySelector('input:checked')) {
      if (typeof g.treniVarsel === 'function') { g.treniVarsel(); }
      return false;
    }
    return true;
  }
  neste.addEventListener('click', function () {
    if (!gyldig(aktiv)) { return; }
    lagre();
    vis(aktiv + 1);
    window.scrollTo({ top: Math.max(0, f.getBoundingClientRect().top + window.pageYOffset - 24), behavior: 'auto' });
  });
  tilbake.addEventListener('click', function () {
    lagre();
    vis(aktiv - 1);
    window.scrollTo({ top: Math.max(0, f.getBoundingClientRect().top + window.pageYOffset - 24), behavior: 'auto' });
  });
  // F-38 (10.09, punkt 3): Enter i et felt utløste nettleserens innsending
  // (den «trykker» den første send-knappen selv om den er skjult), så løperen
  // ble sendt til skjerm 3 og skjemaet forsøkt sendt. Nå gjør Enter det samme
  // som «Neste» (validering av skjermen, så neste), aldri mer. På siste skjerm
  // gjør Enter ingenting: bare «Send inn»-knappen sender. Tekstfelt beholder
  // linjeskiftet sitt, og Enter på selve knappene virker som et klikk.
  f.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' || ev.isComposing) { return; }
    var t = ev.target;
    if (!t || t.tagName === 'TEXTAREA' || t.tagName === 'BUTTON' || t.tagName === 'A') { return; }
    ev.preventDefault();
    if (aktiv < N - 1) { neste.click(); }
  });
  // Ved innsending: alle skjermene sjekkes, og den første med feil vises.
  // Uten dette ville nettleseren stoppet på et skjult felt uten å si noe.
  f.addEventListener('submit', function (ev) {
    for (var i = 0; i < N; i++) {
      if (!gyldig(i)) {
        if (aktiv !== i) { vis(i); gyldig(i); }
        ev.preventDefault();
        return;
      }
    }
  });

  // Startskjerm: etter en serverfeil den første skjermen som mangler noe
  // (samtykket er sist), ellers skjermen løperen sto på sist.
  var start = 0;
  <?php if ($feil): ?>
  start = N - 1;
  for (var i = 0; i < N; i++) {
    var felt = feltI(i), ok = true;
    for (var k = 0; k < felt.length; k++) { if (!felt[k].checkValidity()) { ok = false; break; } }
    if (!ok) { start = i; break; }
  }
  <?php else: ?>
  try { var h = localStorage.getItem(NOKKEL); if (h !== null) { start = parseInt(h, 10) || 0; } } catch (e) {}
  <?php endif; ?>
  vis(start);
})();

(function(){
  var g = document.getElementById('hviledager');
  if (!g) return;
  var EN = <?= $EN ? 'true' : 'false' ?>;
  // «ingen fast dag» utelukker de andre, og omvendt
  g.addEventListener('change', function (ev) {
    var b = ev.target; if (!b || b.type !== 'checkbox') return;
    var alle = g.querySelectorAll('input');
    if (b.value === 'ingen' && b.checked) {
      alle.forEach(function (x) { if (x !== b) x.checked = false; });
    } else if (b.checked) {
      var i = g.querySelector('input[value="ingen"]'); if (i) i.checked = false;
    }
    varsel.hidden = true; g.style.outline = '';
  });
  // Lola 08.09 kl. 22:38: dette var det ENESTE feltet i punkt 4 uten
  // nettleser-validering. Alle de andre er «required», så nettleseren stoppet
  // henne der og hun rettet dem, men dagvalget slapp gjennom, og først
  // SERVEREN sa fra. Da hadde hun rettet alt nettleseren klagde på, og
  // svarte med rette «I did fill up this section». Nå stoppes den her.
  var varsel = document.createElement('p');
  varsel.hidden = true;
  varsel.style.cssText = 'margin:.35rem 0 0;font-size:.85rem;font-weight:600;color:hsl(0 65% 45%)';
  varsel.textContent = EN ? 'Pick at least one, or "no fixed day".'
                          : 'Velg minst én, eller «ingen fast dag».';
  g.parentNode.insertBefore(varsel, g.nextSibling);
  g.treniVarsel = function () {
    varsel.hidden = false;
    g.style.outline = '2px solid hsl(0 65% 45%)';
    g.style.outlineOffset = '4px';
    g.scrollIntoView({ block: 'center', behavior: 'smooth' });
  };
  var skjema = g.closest('form');
  if (skjema) {
    skjema.addEventListener('submit', function (ev) {
      if (g.querySelector('input:checked')) { return; }
      ev.preventDefault();
      varsel.hidden = false;
      g.style.outline = '2px solid hsl(0 65% 45%)';
      g.style.outlineOffset = '4px';
      g.scrollIntoView({ block: 'center', behavior: 'smooth' });
    });
  }
})();

// Lola 09.09 kl. 06:21: «Now it stuck at sending». Knappen ble deaktivert og satt
// til «Sending …» av en inline onsubmit SOM KJØRTE FØR valideringen. Stoppet
// valideringen innsendingen, satt hun igjen med en død knapp og måtte laste
// siden på nytt. Nå deaktiveres knappen SIST, bare når alt faktisk gikk gjennom,
// og slås på igjen hvis noe likevel stopper det.
(function () {
  var f = document.querySelector('form[data-sender]');
  if (!f) { return; }
  var b = f.querySelector('button[type=submit]');
  if (!b) { return; }
  function slaPa() {
    b.disabled = false;
    b.textContent = f.dataset.send || b.textContent;
  }
  f.addEventListener('submit', function (ev) {
    if (ev.defaultPrevented) { slaPa(); return; }   // en validering stoppet det
    if (b.disabled) { ev.preventDefault(); return; } // dobbeltklikk
    b.disabled = true;
    b.textContent = f.dataset.sender || 'Sender …';
    // Sikkerhetsnett: har siden ikke byttet på 12 sekunder, er noe galt.
    // Løperen skal aldri sitte fast med en død knapp.
    setTimeout(slaPa, 12000);
  });
  // Nettleserens egen «required»-sjekk stopper innsendingen uten submit-event
  f.addEventListener('invalid', slaPa, true);
})();

  // 79a (Eirik 14.09.2026): løpsbolken spørres nå på min side etter planen (F-246),
  // A/B/C/D-kravet håndheves i svar_endre.php.
  (function(){
    function voks(t){ t.style.height='auto'; t.style.height=(t.scrollHeight+4)+'px'; }
    window.treniVoks = voks;
    var alle=document.querySelectorAll('.vl-kort textarea');
    for(var i=0;i<alle.length;i++){ voks(alle[i]); alle[i].addEventListener('input',function(){voks(this);}); }
  })();
</script>
</main>
</body>
</html>
