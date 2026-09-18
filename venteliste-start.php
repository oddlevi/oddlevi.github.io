<?php
// Skjema 2 (Odds bestilling 19.08): samme spørsmål som Telegram-onboardingen
// + km/uke. Personlig kode-lenke per påmeldingsrad; svarene lagres i
// venteliste.svar_json, Odd+Claude varsles i Telegram, og onboarding-vakta
// leser svarene inn i løperens rad. B-57 (Odd 10.09): skjemaet er steg 3 av 4 (B-63),
// etter at klokka er koblet og øktene er inne; planen bygges når svarene og
// historikken er inne.
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
    treni_begrens_post(["maal" => 600, "rekorder" => 600, "alternativ" => 400,
                        "styrke" => 400, "alternativ_hva" => 200], 200);
    foreach ($_POST as $vs_k => $vs_v) {
        $vs_fl = in_array($vs_k, ["maal", "rekorder", "alternativ", "styrke"], true);
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
    $svar = [
        "maal"      => mb_substr(trim($_POST["maal"] ?? ""), 0, 2500),
        "alder"     => (int) ($_POST["alder"] ?? 0),
        "puls"      => trim($_POST["puls"] ?? "") === "" ? null : (int) $_POST["puls"],
        "beste_tid" => mb_substr(trim($_POST["beste_tid"] ?? ""), 0, 120),
        "km_uke"    => (float) str_replace(",", ".", $_POST["km_uke"] ?? "0"),
        // B6-vernet (Odd 22.08, Silvia-caset): langtur foreskrives aldri over
        // lengste økt siste 30 dager +10 %, generatoren leser dette feltet.
        "lengste_30d" => ($_POST["lengste_30d"] ?? "") !== ""
            ? (float) str_replace(",", ".", $_POST["lengste_30d"]) : null,
        "start_km"  => (float) str_replace(",", ".", $_POST["start_km"] ?? "0"),
        "underlag"  => in_array($_POST["underlag"] ?? "", ["vei", "terreng", "begge"], true)
                       ? $_POST["underlag"] : "begge",
        "lop_navn"  => mb_substr(trim($_POST["lop_navn"] ?? ""), 0, 120),
        "lop_km"    => trim($_POST["lop_km"] ?? "") === "" ? null : (float) str_replace(",", ".", $_POST["lop_km"]),
        "lop_type"  => in_array($_POST["lop_type"] ?? "", ["flatt", "motbakke", "opp_og_ned", "fjell"], true)
                       ? $_POST["lop_type"] : null,
        "lop_hm_opp" => trim($_POST["lop_hm_opp"] ?? "") === "" ? null : (int) $_POST["lop_hm_opp"],
        "lop_hm_ned" => trim($_POST["lop_hm_ned"] ?? "") === "" ? null : (int) $_POST["lop_hm_ned"],
        "rekorder"  => mb_substr(trim($_POST["rekorder"] ?? ""), 0, 600),
        "volum_onske" => in_array($_POST["volum_onske"] ?? "", ["oke", "stabil", "usikker"], true)
                         ? $_POST["volum_onske"] : "stabil",
        "alternativ" => mb_substr(trim($_POST["alternativ"] ?? ""), 0, 400),
        "styrke"     => mb_substr(trim($_POST["styrke"] ?? ""), 0, 400),
        // «Økter per uke» var tvetydig (Odd 04.09, Trond-caset): han svarte 2
        // og mente to LØPEøkter, styrke og fotball kom i tillegg, men planen
        // leste 2 som alt han gjør. Fra nå telles de tre hver for seg, og
        // annen trening må si HVA det er: fotball belaster helt annerledes
        // enn svømming. Fritekstfeltene over beholdes, de gir mer enn tallet.
        "n_okter" => trim($_POST["n_okter"] ?? "") === "" ? null
            : max(1, min(14, (int) $_POST["n_okter"])),
        "n_styrke" => trim($_POST["n_styrke"] ?? "") === "" ? null
            : max(0, min(7, (int) $_POST["n_styrke"])),
        "n_alternativ" => trim($_POST["n_alternativ"] ?? "") === "" ? null
            : max(0, min(14, (int) $_POST["n_alternativ"])),
        "alternativ_hva" => mb_substr(trim($_POST["alternativ_hva"] ?? ""), 0, 120),
        // 90-dagersbildet (Eiriks regelverk §2 til 5, Odds go 23.08): historikk og
        // kontekst, grunnlaget for nivåklassifisering og korridor i planene.
        "siste_90d" => in_array($_POST["siste_90d"] ?? "", ["jevn", "ujevn", "opphold", "mer_for"], true)
                       ? $_POST["siste_90d"] : "jevn",
        "km_uke_90d" => trim($_POST["km_uke_90d"] ?? "") === "" ? null
            : (float) str_replace(",", ".", $_POST["km_uke_90d"]),
        "lengste_90d" => trim($_POST["lengste_90d"] ?? "") === "" ? null
            : (float) str_replace(",", ".", $_POST["lengste_90d"]),
        "toppuke_aar" => trim($_POST["toppuke_aar"] ?? "") === "" ? null
            : (float) str_replace(",", ".", $_POST["toppuke_aar"]),
        // TIMER per uke (Eirik 04.09 20:25): «Det må også ved onboarding
        // spørres om timer trening per uke når det kommer til de som skal
        // følge fjellmetodikk. Andre ligger f.eks. mellom 12-18 timer per
        // uke.» Fjellmodellens volumklasse V1. V4 og fjellnivå 1 til 5 leser TIMER,
        // ikke km, og uten klokke leses hele gruppa som V0, «bygg timer før
        // noe annet», uansett hvor mye de trener. To felt fordi de svarer på
        // hver sin regel: totaltimer setter volumklassen (§11, all
        // utholdenhet), løpte timer setter nivået (S-29, kun løping).
        // Timer spørres nå i TRE deler (Eirik 04.09 23:31: «Styrke som eget
        // felt. Løping + alternativ (aerob trening) + styrke»). Styrken skilles
        // ut fordi utholdenhetstotalen, den som setter volumklassen, måler
        // aerob trening, og den loggede siden av det tallet har aldri talt
        // styrkeøkter med. Sto styrken i totalen for dem som oppgir tallene
        // selv, ble løpere med og uten klokke målt med hver sin målestokk.
        // timer_uke beholdes for dem som svarte før endringen.
        "timer_uke" => trim($_POST["timer_uke"] ?? "") === "" ? null
            : (float) str_replace(",", ".", $_POST["timer_uke"]),
        "timer_alt_uke" => trim($_POST["timer_alt_uke"] ?? "") === "" ? null
            : (float) str_replace(",", ".", $_POST["timer_alt_uke"]),
        "timer_lop_uke" => trim($_POST["timer_lop_uke"] ?? "") === "" ? null
            : (float) str_replace(",", ".", $_POST["timer_lop_uke"]),
        "timer_styrke_uke" => trim($_POST["timer_styrke_uke"] ?? "") === "" ? null
            : (float) str_replace(",", ".", $_POST["timer_styrke_uke"]),
        // Revisjon 10.09 (punkt 2): samme verdisett som onboarding_felt.py og
        // svar_endre.php (mye/en_del/lite/ingen). «litt» og «nei» fra gamle svar
        // oversettes ved import (venteliste_inn.py).
        "fjellvane" => in_array($_POST["fjellvane"] ?? "", ["mye", "en_del", "lite", "ingen"], true)
                       ? $_POST["fjellvane"] : null,
        "lop_prioritet" => in_array($_POST["lop_prioritet"] ?? "", ["A", "B", "C", "D"], true)
                           ? $_POST["lop_prioritet"] : null,
        // «Uka di» (Odd 08.09, S-87): de fem spørsmålene porten på min side
        // stilte ETTER planen, flyttet hit så første plan kjenner rammene.
        // Samme nøkler og verdier som onboarding_felt.py / svar_endre.php.
        "hviledag_dag" => (function () {
            $lov = ["ingen", "mandag", "tirsdag", "onsdag", "torsdag", "fredag", "lordag", "sondag"];
            $d = array_values(array_intersect((array) ($_POST["hviledag"] ?? []), $lov));
            if (in_array("ingen", $d, true)) { $d = ["ingen"]; }
            return $d ? implode(",", $d) : null;
        })(),
        "molle" => in_array($_POST["molle"] ?? "", ["1", "0"], true) ? $_POST["molle"] : null,
        "langtur_maks_km" => trim($_POST["langtur_maks_km"] ?? "") === "" ? null
            : (float) str_replace(",", ".", $_POST["langtur_maks_km"]),
        "langtur_sted" => in_array($_POST["langtur_sted"] ?? "", ["vei", "terreng", "begge"], true)
                          ? $_POST["langtur_sted"] : null,
        "korte_sted" => in_array($_POST["korte_sted"] ?? "", ["vei", "terreng", "begge"], true)
                        ? $_POST["korte_sted"] : null,
    ];
    // Helsesamtykke (personvern 04.09.2026, GDPR art. 9): eget, uttrykkelig
    // steg. Uten avkrysning lagres ingenting, og svarene bevares i skjemaet.
    $helse_ok = (($_POST["samtykke_helse"] ?? "") === "ja");
    if ($helse_ok) {
        $svar["samtykke_helse"] = ["tidspunkt" => date("c"), "versjon" => "2026-09-04"];
    }
    if ($svar["maal"] === "" || $svar["alder"] < 10 || $svar["alder"] > 99
        || $svar["km_uke"] <= 0 || $svar["start_km"] <= 0) {
        $feil = t_("Fyll inn mål, alder, kilometer per uke og ønsket start-kilometer.", "Fill in your goal, age, kilometres per week and your desired starting kilometres.");
    } elseif ($svar["hviledag_dag"] === null || $svar["molle"] === null || $svar["langtur_maks_km"] === null
              || $svar["langtur_sted"] === null || $svar["korte_sted"] === null) {
        // Lola 08.09: «Keep having errors... I did fill up this section». Meldingen
        // sa bare «punkt 4», så løperen måtte gjette hvilket felt som manglet.
        $mangler = [];
        if ($svar["hviledag_dag"] === null)   { $mangler[] = t_("faste fridager", "fixed rest days"); }
        if ($svar["molle"] === null)          { $mangler[] = t_("om mølle er et alternativ", "whether a treadmill is an option"); }
        if ($svar["langtur_maks_km"] === null) { $mangler[] = t_("tak for langturen", "cap for the long run"); }
        if ($svar["langtur_sted"] === null)   { $mangler[] = t_("hvor du tar langturen", "where you do the long run"); }
        if ($svar["korte_sted"] === null)     { $mangler[] = t_("hvor du tar de korte øktene", "where you do the short sessions"); }
        $feil = t_("Punkt 4 «Uka di» mangler: ", "Section 4 \"Your week\" is missing: ") . implode(", ", $mangler) . ".";
    } elseif ($svar["puls"] === null || $svar["beste_tid"] === "" || $svar["rekorder"] === ""
              || $svar["alternativ"] === "" || $svar["styrke"] === "" || $svar["timer_styrke_uke"] === null
              || $svar["fjellvane"] === null) {
        // Revisjon 10.09 (punkt 1/24): skjema 2 krever det samme som porten på min
        // side (onboarding_felt.py, alle 16 obligatoriske fra 06.09). Før sto sju av
        // dem som valgfrie her, og porten låste siden til løperen svarte en gang til.
        $mangler = [];
        if ($svar["puls"] === null)             { $mangler[] = t_("høyeste målte puls", "highest measured heart rate"); }
        if ($svar["beste_tid"] === "")          { $mangler[] = t_("beste tid siste halvår", "best time in the last six months"); }
        if ($svar["rekorder"] === "")           { $mangler[] = t_("personlige rekorder", "personal records"); }
        if ($svar["timer_styrke_uke"] === null) { $mangler[] = t_("timer styrke per uke", "hours of strength per week"); }
        if ($svar["styrke"] === "")             { $mangler[] = t_("hva du gjør i styrketreningen", "what you do in your strength training"); }
        if ($svar["alternativ"] === "")         { $mangler[] = t_("annen trening", "other training"); }
        if ($svar["fjellvane"] === null)        { $mangler[] = t_("om du er vant til høydemeter", "whether you are used to elevation"); }
        $feil = t_("Noen felt mangler: ", "Some fields are missing: ") . implode(", ", $mangler)
              . t_(". Skriv «ingen» eller 0 der det ikke gjelder deg.", ". Write \"none\" or 0 where it does not apply to you.");
    } elseif ($svar["lop_navn"] !== "" && $svar["lop_prioritet"] === null) {
        // 79a (Eirik 14.09.2026, S-159): «treni bør alltid spørre løperen om å
        // rangere løpene sine etter a/b/c/d». Et løp uten bokstav ble til 14.09
        // regnet som A i ventelistegeneratoren og C i plan-AI-en. Nå kreves
        // bokstaven når et løp er oppgitt; nettleseren stopper det først (JS under).
        $feil = t_("Punkt 5: du har oppgitt et løp, si også hvor viktig det er for deg (A, B, C eller D). Da vet planen hvor mye den skal bøye seg for løpet.",
                   "Section 5: you have named a race, so tell us how important it is to you (A, B, C or D). Then the plan knows how much to bend around it.");
    } elseif (!$helse_ok) {
        $feil = t_("Vi trenger et ja til å behandle helseopplysninger (punkt 6 nederst) før vi kan lage planen din. Svarene dine er tatt vare på i skjemaet.", "We need your yes to processing health data (section 6 at the bottom) before we can build your plan. Your answers are kept in the form.");
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
        $vl_sam = t_('📝 Svarene dine er mottatt. Neste steg står på siden din. ', '📝 Your answers are in. The next step is on your page. ')
                . t_('Du svarte: Mål: ', 'You answered: Goal: ') . $svar['maal']
                . t_(' · Alder: ', ' · Age: ') . $svar['alder']
                . ' · ' . $svar['km_uke'] . t_(' km/uke', ' km/week')
                . ($svar['n_okter'] !== null ? ' · ' . $svar['n_okter'] . t_(' løpeøkter/uke', ' runs/week') : '')
                . ($svar['n_styrke'] !== null ? ' · ' . $svar['n_styrke'] . t_(' styrkeøkter/uke', ' strength sessions/week') : '')
                . ($svar['n_alternativ'] !== null ? ' · ' . $svar['n_alternativ'] . t_(' økter annen trening/uke', ' other sessions/week')
                    . ($svar['alternativ_hva'] !== '' ? ' (' . $svar['alternativ_hva'] . ')' : '') : '')
                . t_(' · underlag: ', ' · surface: ') . $svar['underlag']
                . t_(' · puls: ', ' · heart rate: ') . ($svar['puls'] ?? t_('ukjent', 'unknown'))
                . ($svar['beste_tid'] !== '' ? t_(' · beste tid: ', ' · best time: ') . $svar['beste_tid'] : '')
                . ($svar['lop_navn'] !== '' ? t_(' · målløp: ', ' · goal race: ') . $svar['lop_navn'] : '')
                . t_(' · siste 90 dager: ', ' · last 90 days: ') . $svar['siste_90d']
                . ($svar['toppuke_aar'] !== null ? t_(' · toppuke: ', ' · peak week: ') . $svar['toppuke_aar'] . ' km' : '')
                . ($svar['fjellvane'] !== null ? t_(' · fjellvane: ', ' · mountain experience: ') . $svar['fjellvane'] : '') . '.';
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
                        "text" => "📝 Skjema 2 levert (S-87): " . $rad["navn"]
                                . " (id " . $rad["id"] . ")\n"
                                . "Side: https://min.treni.no/venteliste.php?t=" . $kode . "\n"
                                . "Mål: " . $svar["maal"] . "\n"
                                . "Alder: " . $svar["alder"]
                                . " · km/uke: " . $svar["km_uke"]
                                . " · underlag: " . $svar["underlag"] . "\n"
                                . "Økter: " . ($svar["n_okter"] !== null ? $svar["n_okter"] . " løp" : "løp ikke oppgitt")
                                . " · " . ($svar["n_styrke"] !== null ? $svar["n_styrke"] . " styrke" : "styrke ikke oppgitt")
                                . " · " . ($svar["n_alternativ"] !== null ? $svar["n_alternativ"] . " annen trening" : "annen trening ikke oppgitt")
                                . ($svar["alternativ_hva"] !== "" ? " (" . $svar["alternativ_hva"] . ")" : "") . "\n"
                                . "Puls: " . ($svar["puls"] ?? "ukjent")
                                . " · beste tid: " . ($svar["beste_tid"] ?: "ingen") . "\n"
                                . ($svar["lop_navn"] !== "" ? "Målløp: " . $svar["lop_navn"]
                                    . " (" . ($svar["lop_km"] ?? "?") . " km, " . ($svar["lop_type"] ?? "?")
                                    . ", +" . ($svar["lop_hm_opp"] ?? "?") . "/−" . ($svar["lop_hm_ned"] ?? "?") . " hm)\n" : "")
                                . ($svar["rekorder"] !== "" ? "Rekorder: " . $svar["rekorder"] . "\n" : "")
                                . "Siste 90 d: " . $svar["siste_90d"]
                                . ($svar["km_uke_90d"] !== null ? " · " . $svar["km_uke_90d"] . " km/u" : "")
                                . ($svar["lengste_90d"] !== null ? " · lengste " . $svar["lengste_90d"] . " km" : "")
                                . ($svar["timer_lop_uke"] !== null ? " · " . $svar["timer_lop_uke"] . " t løping/uke" : "")
                                . ($svar["timer_alt_uke"] !== null ? " + " . $svar["timer_alt_uke"] . " t alternativ (aerob)" : "")
                                . ($svar["timer_styrke_uke"] !== null ? " + " . $svar["timer_styrke_uke"] . " t styrke" : "")
                                . ($svar["timer_uke"] !== null ? " (oppgitt totalt: " . $svar["timer_uke"] . " t)" : "")
                                . ($svar["toppuke_aar"] !== null ? " · toppuke " . $svar["toppuke_aar"] . " km" : "")
                                . ($svar["fjellvane"] !== null ? " · fjellvane: " . $svar["fjellvane"] : "")
                                . ($svar["lop_prioritet"] !== null ? " · løp-prioritet: " . $svar["lop_prioritet"] : "") . "\n\n"
                                . "Klokke: " . ($klokke_koblet ? "koblet (Intervals)" : "IKKE koblet ennå") . ". "
                                . "Onboarding-vakta leser svarene inn og bygger planen når historikken er inne (B-57)."]),
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
  <?php // F-36 (Odd 10.09): kvitteringen er én skjerm med én knapp. Siden din sier resten. ?>
  <?php if ($klokke_koblet): ?>
  <p><?= t_('Svarene dine er mottatt. Nå bygger vi planen din på historikken fra klokka, og siden din viser fremdriften.', 'Your answers are in. Now we build your plan on the history from your watch, and your page shows the progress.') ?></p>
  <?php else: ?>
  <p><?= t_('Svarene dine er mottatt. Ett steg igjen: koble klokka. Siden din viser deg hvordan.', 'Your answers are in. One step left: connect your watch. Your page shows you how.') ?></p>
  <?php endif; ?>
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
     <?= t_('✅ Takk! Steg 3 av 4: spørsmålene.', '✅ Thanks! Step 3 of 4: the questions.') ?></p>
  <?php endif; ?>
  <h1 style="font-size:clamp(1.6rem,5vw,2.2rem)"><?= t_('Velkommen, ', 'Welcome, ') ?><?= htmlspecialchars(mb_convert_case(explode(" ", trim((string) $rad["navn"]))[0], MB_CASE_TITLE, "UTF-8")) ?>!</h1>
  <?php // F-36 (Odd 10.09): én seksjon per skjerm, så ingressen er én setning. ?>
  <?php if ($klokke_koblet): ?>
  <?php // F-38 (Odd 10.09, punkt 2): $klokke_koblet er samme oppslag som kvitteringen bruker. ?>
  <p><?= t_('Klokka er koblet, og øktene dine kommer inn. Steg 3 av 4 er spørsmålene, i tre deler.', 'Your watch is connected, and your activities are coming in. Step 3 of 4 is the questions, in three parts.') ?></p>
  <?php else: ?>
  <p><?= t_('Spørsmålene er rammene for planen din, i tre deler. Klokka kobler du på siden din etterpå.', 'The questions are the frames for your plan, in three parts. You connect the watch on your page afterwards.') ?></p>
  <?php endif; ?>

  <?php if ($feil): ?><p class="skjema-feil"><?= htmlspecialchars($feil) ?></p><?php endif; ?>
  <?php // Revisjon 03.09: svarene bevares ved valideringsfeil (før ble alt tømt)
  $val = fn(string $n): string => htmlspecialchars((string) ($_POST[$n] ?? ""));
  $sel = fn(string $n, string $v, string $std = ""): string => (($_POST[$n] ?? $std) === $v) ? " selected" : ""; ?>

  <form method="post" style="display:grid; gap:1.1rem; margin-top:1.2rem"
        data-kladd="skjema2" data-en="<?= $EN ? '1' : '0' ?>"
        data-kladd-ferdig="<?= $sendt ? '1' : '0' ?>"
        data-sender="<?= $EN ? 'Sending …' : 'Sender …' ?>"
        data-send="<?= $EN ? 'Submit' : 'Send inn' ?>">
    <input type="hidden" name="k" value="<?= htmlspecialchars($kode) ?>">
    <input type="text" name="nettside" value="" style="display:none" tabindex="-1" autocomplete="off">

    <fieldset class="vl-kort">
      <legend><?= t_('1 · Om deg og målet ditt 🎯', '1 · About you and your goal 🎯') ?></legend>
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
      <label><?= t_('Alder', 'Age') ?>
        <input type="number" name="alder" min="10" max="99" required value="<?= $val('alder') ?>"></label>
    </fieldset>

    <fieldset class="vl-kort">
      <legend><?= t_('2 · Puls og tider 🫀', '2 · Heart rate and times 🫀') ?></legend>
      <label><?= t_('Høyeste puls du har målt i konkurranse eller hard økt siste halvår', 'Highest heart rate you have measured in a race or hard session in the last six months') ?>
        <input type="number" name="puls" min="120" max="230" required placeholder="<?= t_('f.eks. 185', 'e.g. 185') ?>" value="<?= $val('puls') ?>">
        <span class="vl-hint"><?= t_('Har du ikke målt, bruk det høyeste tallet klokka har vist deg på en hard økt. Treneren ser på tallet før sonene settes, og målt puls fra klokka teller mer enn tallet her.', 'If you have not measured it, use the highest number your watch has shown you in a hard session. Your coach checks the number before the zones are set, and measured heart rate from your watch counts more than the number here.') ?></span></label>
      <label><?= t_('Beste tid siste halvår, med distanse', 'Best time in the last six months, with distance') ?> <span class="vl-hint"><?= t_('ingen løp? Skriv «ingen»', 'no races? Write "none"') ?></span>
        <input type="text" name="beste_tid" required maxlength="120" placeholder="<?= t_('F.eks. 10 km på 52:30', 'e.g. 10 km in 52:30') ?>" value="<?= $val('beste_tid') ?>"></label>
      <label><?= t_('Personlige rekorder, flate løp og motbakke/fjelløp', 'Personal records, flat races and uphill/mountain races') ?> <span class="vl-hint"><?= t_('ingen? Skriv «ingen»', 'none? Write "none"') ?></span>
        <textarea name="rekorder" rows="2" required placeholder="<?= t_('F.eks.: 5 km 21:30 · 10 km 45:10 · Storheia Opp 58:20', 'e.g. 5 km 21:30 · 10 km 45:10 · Storheia Uphill 58:20') ?>"><?= $val('rekorder') ?></textarea></label>
    </fieldset>

    <fieldset class="vl-kort">
      <legend><?= t_('3 · Treningsuka di 👟', '3 · Your training week 👟') ?></legend>
      <div class="vl-to">
        <label><?= t_('Kilometer i en vanlig uke', 'Kilometres in a normal week') ?>
          <input type="number" name="km_uke" min="1" max="200" step="0.5" required value="<?= $val('km_uke') ?>"></label>
        <label><?= t_('Hva er det lengste du har løpt de siste 30 dagene? (km)', 'Longest run in the last 30 days (km)') ?>
          <input type="number" name="lengste_30d" min="0" max="200" step="0.5"
                 placeholder="<?= t_('f.eks. 12', 'e.g. 12') ?>" value="<?= $val('lengste_30d') ?>"></label>
        <label><?= t_('Minst så mange km vil jeg starte med i uke 1', 'I want to start week 1 with at least this many km') ?>
          <input type="number" name="start_km" min="1" max="200" step="0.5" required value="<?= $val('start_km') ?>"></label>
      </div>
      <label><?= t_('Hvor løper du mest?', 'Where do you run most?') ?>
        <select name="underlag">
          <option value="terreng"<?= $sel('underlag', 'terreng', 'begge') ?>><?= t_('Mest terreng og fjell', 'Mostly trails and mountains') ?></option>
          <option value="vei"<?= $sel('underlag', 'vei', 'begge') ?>><?= t_('Mest vei og asfalt', 'Mostly road and asphalt') ?></option>
          <option value="begge"<?= $sel('underlag', 'begge', 'begge') ?>><?= t_('Begge deler', 'Both') ?></option>
        </select></label>
      <label><?= t_('Hvordan tenker du om løpemengden fremover?', 'How do you feel about your running volume going forward?') ?>
        <select name="volum_onske">
          <option value="stabil"<?= $sel('volum_onske', 'stabil', 'stabil') ?>><?= t_('Jeg ligger på et volum som passer meg nå', 'My current volume suits me') ?></option>
          <option value="oke"<?= $sel('volum_onske', 'oke', 'stabil') ?>><?= t_('Jeg ønsker å øke løpemengden gradvis', 'I want to increase my volume gradually') ?></option>
          <option value="usikker"<?= $sel('volum_onske', 'usikker', 'stabil') ?>><?= t_('Usikker, ta det opp med treneren', 'Unsure, discuss with the coach') ?></option>
        </select></label>
      <div class="vl-to">
        <label><?= t_('Løpeøkter per uke', 'Running sessions per week') ?>
          <input type="number" name="n_okter" min="1" max="14" step="1"
                 placeholder="<?= t_('f.eks. 3', 'e.g. 3') ?>" value="<?= $val('n_okter') ?>">
          <span class="vl-hint"><?= t_('Bare løpingen. Styrke og annen trening føres i feltene ved siden av, så de kommer i tillegg og ikke i stedet for.', 'Running only. Strength and other training go in the fields next to this, so they count in addition, not instead.') ?></span></label>
        <label><?= t_('Styrkeøkter per uke', 'Strength sessions per week') ?>
          <input type="number" name="n_styrke" min="0" max="7" step="1"
                 placeholder="<?= t_('0 om du ikke trener styrke', '0 if you don\'t do strength') ?>" value="<?= $val('n_styrke') ?>">
          <span class="vl-hint"><?= t_('Kommer i tillegg til løpeøktene, ikke i stedet for.', 'In addition to the running sessions, not instead of them.') ?></span></label>
      </div>
      <div class="vl-to">
        <label><?= t_('Annen trening, antall økter per uke', 'Other training, sessions per week') ?>
          <input type="number" name="n_alternativ" min="0" max="14" step="1"
                 placeholder="<?= t_('0 om du ikke har noe', '0 if none') ?>" value="<?= $val('n_alternativ') ?>">
          <span class="vl-hint"><?= t_('Alt annet enn løping og styrke.', 'Everything except running and strength.') ?></span></label>
        <label><?= t_('Hva er den andre treningen?', 'What is the other training?') ?>
          <input type="text" name="alternativ_hva" maxlength="120"
                 placeholder="<?= t_('F.eks, fotballtrening, sykkel, svømming', 'e.g. football practice, cycling, swimming') ?>" value="<?= $val('alternativ_hva') ?>">
          <span class="vl-hint"><?= t_('Vi trenger å vite hva det er: fotball belaster kroppen helt annerledes enn svømming.', 'We need to know what it is: football loads the body very differently from swimming.') ?></span></label>
      </div>
      <label><?= t_('Driver du med annen trening ved siden av løpinga? Utdyp.', 'Do you do other training alongside your running? Tell us more.') ?> <span class="vl-hint"><?= t_('ingen? Skriv «ingen»', 'none? Write "none"') ?></span>
        <textarea name="alternativ" rows="2" required placeholder="<?= t_('F.eks.: sykkel 1×/uke, ski om vinteren, fotball på mandager …', 'e.g. cycling once a week, skiing in winter, football on Mondays …') ?>"><?= $val('alternativ') ?></textarea></label>
      <label><?= t_('Hva gjør du i styrketreningen?', 'What do you do in your strength training?') ?> <span class="vl-hint"><?= t_('ingen styrke? Skriv «ingen»', 'no strength training? Write "none"') ?></span>
        <textarea name="styrke" rows="2" required placeholder="<?= t_('F.eks.: knebøy, utfall, legghev, mest overkropp …', 'e.g. squats, lunges, calf raises, mostly upper body …') ?>"><?= $val('styrke') ?></textarea></label>
      <label><?= t_('Hvordan har treningen din vært de siste 3 månedene?', 'How has your training been over the last 3 months?') ?>
        <select name="siste_90d">
          <option value="jevn"<?= $sel('siste_90d', 'jevn', 'jevn') ?>><?= t_('Jevn, trent omtrent som nå hele perioden', 'Steady, trained about like now the whole period') ?></option>
          <option value="ujevn"<?= $sel('siste_90d', 'ujevn', 'jevn') ?>><?= t_('Ujevn, litt av og på', 'Uneven, on and off') ?></option>
          <option value="opphold"<?= $sel('siste_90d', 'opphold', 'jevn') ?>><?= t_('Opphold, pause eller svært lite trening', 'Break, a pause or very little training') ?></option>
          <option value="mer_for"<?= $sel('siste_90d', 'mer_for', 'jevn') ?>><?= t_('Jeg trente MER før enn jeg gjør nå', 'I trained MORE before than I do now') ?></option>
        </select></label>
      <div class="vl-to">
        <label><?= t_('Typisk ukevolum siste 3 måneder (km, valgfritt)', 'Typical weekly volume last 3 months (km, optional)') ?>
          <input type="number" name="km_uke_90d" min="0" max="250" step="0.5"
                 placeholder="<?= t_('Omtrent som nå? La stå tomt', 'About like now? Leave blank') ?>" value="<?= $val('km_uke_90d') ?>"></label>
        <label><?= t_('Lengste tur siste 3 måneder (km, valgfritt)', 'Longest run last 3 months (km, optional)') ?>
          <input type="number" name="lengste_90d" min="0" max="200" step="0.5"
                 placeholder="<?= t_('f.eks. 18', 'e.g. 18') ?>" value="<?= $val('lengste_90d') ?>"></label>
      </div>
      <div class="vl-to">
        <label><?= t_('Timer LØPING per uke i en vanlig uke (valgfritt)', 'Hours of RUNNING per week in a normal week (optional)') ?>
          <input type="number" name="timer_lop_uke" min="0" max="30" step="0.5"
                 placeholder="<?= t_('f.eks. 5', 'e.g. 5') ?>" value="<?= $val('timer_lop_uke') ?>">
          <span class="vl-hint"><?= t_('Bare løpingen. Det er løpetimene som avgjør hvor mye kroppen tåler av harde økter. Løper du i fjellet, sier timene mer om belastningen enn kilometerne gjør: en bratt time er ikke det samme som en flat time.', 'Running only. Running hours decide how much hard training your body can take. In the mountains, hours say more about the load than kilometres do: a steep hour is not a flat hour.') ?></span></label>
        <label><?= t_('Timer alternativ trening per uke (valgfritt)', 'Hours of other endurance training per week (optional)') ?>
          <input type="number" name="timer_alt_uke" min="0" max="30" step="0.5"
                 placeholder="<?= t_('f.eks. 4', 'e.g. 4') ?>" value="<?= $val('timer_alt_uke') ?>">
          <span class="vl-hint"><?= t_('Annen kondisjonstrening, ski, sykkel, svømming, gåturer i fjellet, roing. Alt som gir pust og puls uten å være løping. Styrke skal IKKE med her, den har sitt eget felt under.', 'Other endurance training, skiing, cycling, swimming, mountain hiking, rowing. Anything that gets you breathing without being running. Strength does NOT go here; it has its own field below.') ?></span></label>
      </div>
      <div class="vl-to">
        <label><?= t_('Timer styrke per uke', 'Hours of strength per week') ?>
          <input type="number" name="timer_styrke_uke" min="0" max="20" step="0.5" required
                 placeholder="<?= t_('0 om du ikke trener styrke', '0 if you do not do strength') ?>" value="<?= $val('timer_styrke_uke') ?>">
          <span class="vl-hint"><?= t_('0 er et godt svar. Styrkeøktene dine, målt i timer. De telles for seg, styrke bygger kroppen din, men belaster den på en annen måte enn løping og kondisjon, og skal derfor ikke blandes inn i utholdenhetstimene.', 'Your strength sessions, in hours. Counted separately, strength builds your body but loads it differently from running and endurance, so it is not mixed into the endurance hours.') ?></span></label>
      </div>
      <div class="vl-to">
        <label><?= t_('Mest du har løpt i én uke det siste året (km, valgfritt)', 'Most you have run in one week in the last year (km, optional)') ?>
          <input type="number" name="toppuke_aar" min="0" max="300" step="0.5"
                 placeholder="<?= t_('f.eks. 45', 'e.g. 45') ?>" value="<?= $val('toppuke_aar') ?>"></label>
        <label><?= t_('Er du vant til høydemeter og nedoverløping?', 'Are you used to elevation and downhill running?') ?>
          <select name="fjellvane" required>
            <option value=""><?= t_('Velg …', 'Choose …') ?></option>
            <option value="mye"<?= $sel('fjellvane', 'mye', '') ?>><?= t_('Mye, fast del av treningen min', 'A lot, a regular part of my training') ?></option>
            <option value="en_del"<?= $sel('fjellvane', 'en_del', '') ?>><?= t_('En del, jevnlig', 'Some, regularly') ?></option>
            <option value="lite"<?= $sel('fjellvane', 'lite', '') ?>><?= t_('Lite, av og til', 'Little, now and then') ?></option>
            <option value="ingen"<?= $sel('fjellvane', 'ingen', '') ?>><?= t_('Ingen, mest flatt', 'None, mostly flat') ?></option>
          </select></label>
      </div>
      <span class="vl-hint"><?= t_('Planen starter på volumet kroppen din er vant til, og bygges trygt derfra. De siste 3 månedene forteller oss hva kroppen din faktisk tåler nå: derfor spør vi.', 'The plan starts at the volume your body is used to and builds safely from there. The last 3 months tell us what your body can actually handle now, that is why we ask.') ?></span>
    </fieldset>

    <fieldset class="vl-kort" id="uka-di">
      <legend><?= t_('4 · Uka di 📅', '4 · Your week 📅') ?></legend>
      <p class="vl-hint" style="margin:0 0 .9rem"><?= t_('Rammer for planen: den legger aldri en løpeøkt på fridagene dine, og langturen går aldri over taket ditt.', 'Frames for the plan: it never puts a run on your rest days, and the long run never exceeds your cap.') ?></p>
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
      <div class="vl-to">
        <label><?= t_('Er mølle et alternativ for deg?', 'Is a treadmill an option for you?') ?>
          <select name="molle" required>
            <option value=""<?= $sel('molle', '', '') ?>><?= t_('Velg …', 'Choose …') ?></option>
            <option value="1"<?= $sel('molle', '1', '') ?>><?= t_('Ja', 'Yes') ?></option>
            <option value="0"<?= $sel('molle', '0', '') ?>><?= t_('Nei', 'No') ?></option>
          </select></label>
        <label><?= t_('Tak for langturen (km)', 'Cap for the long run (km)') ?> <span class="vl-hint"><?= t_('planen går aldri over dette', 'the plan never exceeds this') ?></span>
          <input type="number" name="langtur_maks_km" min="3" max="100" step="1" required placeholder="<?= t_('f.eks. 20', 'e.g. 20') ?>" value="<?= htmlspecialchars((string) ($_POST['langtur_maks_km'] ?? '')) ?>"></label>
      </div>
      <div class="vl-to" style="margin-top:.8rem">
        <label><?= t_('Hvor tar du helst langturen?', 'Where do you prefer the long run?') ?>
          <select name="langtur_sted" required>
            <option value=""<?= $sel('langtur_sted', '', '') ?>><?= t_('Velg …', 'Choose …') ?></option>
            <option value="vei"<?= $sel('langtur_sted', 'vei', '') ?>><?= t_('Vei og asfalt', 'Road and asphalt') ?></option>
            <option value="terreng"<?= $sel('langtur_sted', 'terreng', '') ?>><?= t_('Terreng og fjell', 'Trails and mountains') ?></option>
            <option value="begge"<?= $sel('langtur_sted', 'begge', '') ?>><?= t_('Begge deler', 'Both') ?></option>
          </select></label>
        <label><?= t_('Hvor tar du helst de korte øktene?', 'Where do you prefer the short sessions?') ?>
          <select name="korte_sted" required>
            <option value=""<?= $sel('korte_sted', '', '') ?>><?= t_('Velg …', 'Choose …') ?></option>
            <option value="vei"<?= $sel('korte_sted', 'vei', '') ?>><?= t_('Vei og asfalt', 'Road and asphalt') ?></option>
            <option value="terreng"<?= $sel('korte_sted', 'terreng', '') ?>><?= t_('Terreng og fjell', 'Trails and mountains') ?></option>
            <option value="begge"<?= $sel('korte_sted', 'begge', '') ?>><?= t_('Begge deler', 'Both') ?></option>
          </select></label>
      </div>
    </fieldset>

    <fieldset class="vl-kort">
      <legend><?= t_('5 · Løpet du sikter mot 🏁', '5 · The race you are aiming for 🏁') ?> <span class="vl-hint"><?= t_('(valgfritt, jo mer vi vet, jo bedre)', '(optional, the more we know, the better)') ?></span></legend>
      <label><?= t_('Løpets navn og dato', 'Race name and date') ?>
        <input type="text" name="lop_navn" placeholder="<?= t_('F.eks. Tromsø Skyrace, 8. august 2027', 'e.g. Tromsø Skyrace, 8 August 2027') ?>" value="<?= $val('lop_navn') ?>"></label>
      <div class="vl-to">
        <label><?= t_('Lengde (km)', 'Distance (km)') ?>
          <input type="number" name="lop_km" min="1" max="300" step="0.1" value="<?= $val('lop_km') ?>"></label>
        <label><?= t_('Type løp', 'Type of race') ?>
          <select name="lop_type">
            <option value=""><?= t_('Velg …', 'Choose …') ?></option>
            <option value="flatt"<?= $sel('lop_type', 'flatt', '') ?>><?= t_('Flatt (vei/bane)', 'Flat (road/track)') ?></option>
            <option value="motbakke"<?= $sel('lop_type', 'motbakke', '') ?>><?= t_('Motbakke (bare opp)', 'Uphill only') ?></option>
            <option value="opp_og_ned"<?= $sel('lop_type', 'opp_og_ned', '') ?>><?= t_('Opp og ned', 'Up and down') ?></option>
            <option value="fjell"<?= $sel('lop_type', 'fjell', '') ?>><?= t_('Fjelløp / skyrace / ultra', 'Mountain race / skyrace / ultra') ?></option>
          </select></label>
      </div>
      <div class="vl-to">
        <label><?= t_('Høydemeter opp', 'Elevation gain (m)') ?>
          <input type="number" name="lop_hm_opp" min="0" max="20000" value="<?= $val('lop_hm_opp') ?>"></label>
        <label><?= t_('Høydemeter ned', 'Elevation loss (m)') ?>
          <input type="number" name="lop_hm_ned" min="0" max="20000" value="<?= $val('lop_hm_ned') ?>"></label>
      </div>
      <label><?= t_('Hvor viktig er dette løpet for deg?', 'How important is this race to you?') ?> <span class="vl-hint"><?= t_('(må velges når du har oppgitt et løp)', '(required when you have named a race)') ?></span>
        <select name="lop_prioritet" id="lop_prioritet">
          <option value=""><?= t_('Velg …', 'Choose …') ?></option>
          <option value="A"<?= $sel('lop_prioritet', 'A', '') ?>><?= t_('A, hovedmålet mitt, her vil jeg prestere maksimalt', 'A, my main goal, I want to perform at my best') ?></option>
          <option value="B"<?= $sel('lop_prioritet', 'B', '') ?>><?= t_('B, viktig delmål på veien', 'B, an important stepping stone') ?></option>
          <option value="C"<?= $sel('lop_prioritet', 'C', '') ?>><?= t_('C, vil gjøre det bra, men ikke hovedmålet', 'C, want to do well, but not the main goal') ?></option>
          <option value="D"<?= $sel('lop_prioritet', 'D', '') ?>><?= t_('D, testløp / del av treningen', 'D, test race / part of training') ?></option>
        </select></label>
    </fieldset>

    <fieldset class="vl-kort" style="border:1.5px solid hsl(148 15% 84%)" id="helsesamtykke">
      <legend><?= t_('6 · Samtykke til helseopplysninger 🔏', '6 · Consent to health data 🔏') ?></legend>
      <label style="display:grid; grid-template-columns:auto 1fr; gap:.6rem; align-items:start; font-weight:400">
        <input type="checkbox" name="samtykke_helse" value="ja" required
               style="width:1.25rem; height:1.25rem; margin-top:.15rem"<?= (($_POST["samtykke_helse"] ?? "") === "ja") ? " checked" : "" ?>>
<?php if ($EN): ?>
        <span><b>Yes, Treni may process health data about me.</b> This covers heart rate
          from Strava and what I tell you about injuries, illness and my body. The data is
          used only for my training guidance, seen only by my coach and Eirik Haugsnes and
          Odd Levi Paulsen, and sent as extracts to Anthropic to phrase the advice. I can
          withdraw consent at any time by writing to
          <a href="mailto:hei@treni.no">hei@treni.no</a> or in my group.
          <span class="vl-hint" style="display:block; margin-top:.4rem">Heart rate, injuries and
          illness are health data. The law requires a separate yes from you before we can use
          them. Read the <a href="en/privacy.html" target="_blank" rel="noopener">privacy policy</a>.</span>
        </span>
<?php else: ?>
        <span><b>Ja, Treni kan behandle helseopplysninger om meg.</b> Det gjelder puls fra
          Strava og det jeg selv forteller om skader, sykdom og kroppen min. Opplysningene
          brukes bare til treningsveiledningen min, sees bare av treneren min og
          Eirik Haugsnes og Odd Levi Paulsen, og sendes som utdrag til Anthropic for å lage rådtekst. Jeg kan
          trekke samtykket når som helst ved å skrive til
          <a href="mailto:hei@treni.no">hei@treni.no</a> eller i gruppa mi.
          <span class="vl-hint" style="display:block; margin-top:.4rem">Puls, skader og sykdom
          er helseopplysninger. Loven krever et eget ja fra deg før vi kan bruke dem. Les
          <a href="personvern.html" target="_blank" rel="noopener">personvernerklæringen</a>.</span>
        </span>
<?php endif; ?>
      </label>
    </fieldset>

    <button class="btn vl-stor" type="submit"><?= t_('Send inn', 'Submit') ?></button>
  </form>
  <script src="kladd.js?v=3" defer></script>
  <p class="liten" style="margin-top:1rem"><?= t_('Svarene brukes bare til å lage veiledningen din, og slettes hvis du ber om det. Du kan endre dem på siden din når planen er klar.', 'Your answers are used only to build your guidance, and deleted if you ask. You can edit them on your page once the plan is ready.') ?></p>
<?php endif; ?>

  <footer style="margin-top:3rem" class="liten">
    <p>PAULSEN UTVIKLING · org.nr 938 158 614 · <?= t_('Norge', 'Norway') ?> ·
       <a href="mailto:hei@treni.no">hei@treni.no</a></p>
  </footer>
<script>
// F-36 / B-63 (Odd 10.09.2026): «Onboardingen bør vise et og et valg i hvert
// bilde.» Skjema 2 er derfor tre skjermer (Odd 10.09 kl. 15:1x: «tre er bra»):
// 1 om deg og puls, 2 treningsuka og uka di, 3 løpet, samtykke og send. «Neste»
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
  // To seksjoner per skjerm: [1+2], [3+4], [5+6]. Skjermene er lister av seksjoner.
  var skjermer = [];
  for (var si = 0; si < seksjoner.length; si += 2) {
    skjermer.push(seksjoner.slice(si, si + 2));
  }
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
    fram.textContent = (aktiv + 1) + (EN ? ' of ' : ' av ') + N;
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

  // 79a (Eirik 14.09.2026): har løperen skrevet et løp, må A/B/C/D velges.
  // Nettleserens required-sjekk tar det på skjerm 3, serveren sjekker det samme.
  (function(){
    var navn = document.querySelector('input[name="lop_navn"]');
    var pri = document.getElementById('lop_prioritet');
    if (!navn || !pri) { return; }
    function oppdater(){ pri.required = navn.value.trim() !== ''; }
    navn.addEventListener('input', oppdater);
    navn.addEventListener('change', oppdater);
    oppdater();
  })();
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
