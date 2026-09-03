<?php
// Venteliste-onboarding (Odds bestilling 19.08): samme spørsmål som
// Telegram-onboardingen + km/uke. Personlig kode-lenke per venteliste-rad;
// svarene lagres i venteliste.svar_json og treneren varsles i Telegram.
// Revisjon 03.09: serveren står i UTC — «Svarene dine er mottatt»-innslaget
// fikk UTC-klokkeslett (Trond: 07:32 i stedet for 09:32).
date_default_timezone_set('Europe/Oslo');
$cfg_sti = dirname(__DIR__) . "/dashbord_config.php";
$konfig = is_readable($cfg_sti) ? (include $cfg_sti) : null;
$kode = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET["k"] ?? $_POST["k"] ?? "");
$rad = null; $sendt = false; $feil = "";

if (is_array($konfig) && $kode !== "") {
    try {
        $pdo = new PDO(
            "mysql:host={$konfig['db_host']};dbname={$konfig['db_name']};charset=utf8mb4",
            $konfig['db_user'], $konfig['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $st = $pdo->prepare("SELECT id, navn, om, svart_at, epost, status FROM venteliste WHERE kode = ?");
        $st->execute([$kode]);
        $rad = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        // Duplikat-rader (24.08-vernet, gjenoppbygd 03.09): en lenke fra en
        // duplikatrad sendes videre til nyeste ekte rad for samme e-post.
        if ($rad && ($rad["status"] ?? "") === "duplikat") {
            $st2 = $pdo->prepare("SELECT kode FROM venteliste WHERE LOWER(epost) = LOWER(?)
                AND status IN ('venter', 'minside') AND kode IS NOT NULL AND kode <> ''
                ORDER BY id DESC LIMIT 1");
            $st2->execute([$rad["epost"]]);
            if (($kode2 = $st2->fetchColumn()) && $kode2 !== $kode) {
                header("Location: /venteliste-start.php?k=" . $kode2);
                exit;
            }
        }
    } catch (Throwable $e) { $rad = null; }
}

// Rategrense + feltgrenser (sikkerhetstest 03.09): skjemaet er bak en
// personlig kode, men koden kan lekke — maks 10 innsendinger per IP per time.
require_once __DIR__ . "/spamvern.php";
$vs_rate = false;
if ($_SERVER["REQUEST_METHOD"] === "POST" && $rad) {
    treni_begrens_post(["maal" => 600, "rekorder" => 600, "alternativ" => 400, "styrke" => 400], 200);
    foreach ($_POST as $vs_k => $vs_v) {
        $_POST[$vs_k] = treni_ren($vs_v, 2000, in_array($vs_k, ["maal", "rekorder", "alternativ", "styrke"], true));
    }
    if (treni_rategrense("vlstart|" . treni_ip(), 10, 3600)) {
        treni_sikkerhet_logg("venteliste-start: rategrense (10/t) nådd");
        http_response_code(429);
        header("Retry-After: 900");
        $vs_rate = true;
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
        // lengste økt siste 30 dager +10 % — generatoren leser dette feltet.
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
        // 90-dagersbildet (Eiriks regelverk §2–5, Odds go 23.08): historikk og
        // kontekst — grunnlaget for nivåklassifisering og korridor i planene.
        "siste_90d" => in_array($_POST["siste_90d"] ?? "", ["jevn", "ujevn", "opphold", "mer_for"], true)
                       ? $_POST["siste_90d"] : "jevn",
        "km_uke_90d" => trim($_POST["km_uke_90d"] ?? "") === "" ? null
            : (float) str_replace(",", ".", $_POST["km_uke_90d"]),
        "lengste_90d" => trim($_POST["lengste_90d"] ?? "") === "" ? null
            : (float) str_replace(",", ".", $_POST["lengste_90d"]),
        "toppuke_aar" => trim($_POST["toppuke_aar"] ?? "") === "" ? null
            : (float) str_replace(",", ".", $_POST["toppuke_aar"]),
        "fjellvane" => in_array($_POST["fjellvane"] ?? "", ["mye", "litt", "nei"], true)
                       ? $_POST["fjellvane"] : null,
        "lop_prioritet" => in_array($_POST["lop_prioritet"] ?? "", ["A", "B", "C", "D"], true)
                           ? $_POST["lop_prioritet"] : null,
    ];
    if ($svar["maal"] === "" || $svar["alder"] < 10 || $svar["alder"] > 99
        || $svar["km_uke"] <= 0 || $svar["start_km"] <= 0) {
        $feil = "Fyll inn mål, alder, kilometer per uke og ønsket start-kilometer.";
    } else {
        $pdo->prepare("UPDATE venteliste SET svar_json = ?, svart_at = NOW() WHERE kode = ?")
            ->execute([json_encode($svar, JSON_UNESCAPED_UNICODE), $kode]);
        // Status følger reisen automatisk (Odds regel 19.08) — aldri nedgrader
        // Onboarding-svar logges i «Siste endringer» på løperens side (Odd 31.08)
        $vl_fil = __DIR__ . "/../../min.treni.no/public_html/venteliste_data/{$kode}.json";
        $vl_d = @json_decode((string) @file_get_contents($vl_fil), true);
        // Odds presisering 01.09: selve SVARENE skal stå i innslaget —
        // sammendraget bygges FØR fil-sjekken, så også helt nye løpere
        // (bygges-stubben under) får det.
        $vl_sam = '📝 Svarene dine er mottatt. Soner og plan bygges fra dem. '
                . 'Du svarte: Mål: ' . $svar['maal']
                . ' · Alder: ' . $svar['alder']
                . ' · ' . $svar['km_uke'] . ' km/uke'
                . ' · underlag: ' . $svar['underlag']
                . ' · puls: ' . ($svar['puls'] ?? 'ukjent')
                . ($svar['beste_tid'] !== '' ? ' · beste tid: ' . $svar['beste_tid'] : '')
                . ($svar['lop_navn'] !== '' ? ' · målløp: ' . $svar['lop_navn'] : '')
                . ' · siste 90 dager: ' . $svar['siste_90d']
                . ($svar['toppuke_aar'] !== null ? ' · toppuke: ' . $svar['toppuke_aar'] . ' km' : '')
                . ($svar['fjellvane'] !== null ? ' · fjellvane: ' . $svar['fjellvane'] : '') . '.';
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
                        "chat_id" => TRENI_TRENER_CHAT,
                        "text" => "📝 Venteliste-onboarding fullført: " . $rad["navn"]
                                . " (id " . $rad["id"] . ")\n"
                                . "Side: https://min.treni.no/venteliste.php?t=" . $kode . "\n"
                                . "Mål: " . $svar["maal"] . "\n"
                                . "Alder: " . $svar["alder"]
                                . " · km/uke: " . $svar["km_uke"]
                                . " · underlag: " . $svar["underlag"] . "\n"
                                . "Puls: " . ($svar["puls"] ?? "ukjent")
                                . " · beste tid: " . ($svar["beste_tid"] ?: "ingen") . "\n"
                                . ($svar["lop_navn"] !== "" ? "Målløp: " . $svar["lop_navn"]
                                    . " (" . ($svar["lop_km"] ?? "?") . " km, " . ($svar["lop_type"] ?? "?")
                                    . ", +" . ($svar["lop_hm_opp"] ?? "?") . "/−" . ($svar["lop_hm_ned"] ?? "?") . " hm)\n" : "")
                                . ($svar["rekorder"] !== "" ? "Rekorder: " . $svar["rekorder"] . "\n" : "")
                                . "Siste 90 d: " . $svar["siste_90d"]
                                . ($svar["km_uke_90d"] !== null ? " · " . $svar["km_uke_90d"] . " km/u" : "")
                                . ($svar["lengste_90d"] !== null ? " · lengste " . $svar["lengste_90d"] . " km" : "")
                                . ($svar["toppuke_aar"] !== null ? " · toppuke " . $svar["toppuke_aar"] . " km" : "")
                                . ($svar["fjellvane"] !== null ? " · fjellvane: " . $svar["fjellvane"] : "")
                                . ($svar["lop_prioritet"] !== null ? " · løp-prioritet: " . $svar["lop_prioritet"] : "") . "\n\n"
                                . "Veilederen bygger nå soner + plan og sier fra når "
                                . "siden er klar."]),
                    "timeout" => 8]]));
        }
        // Løperen får siden sin UMIDDELBART (Odds UX-grep 19.08): en
        // «bygges»-payload skrives nå, veilederen overskriver med full
        // versjon (soner + plan) om få minutter — siden oppdaterer seg selv.
        $vd = __DIR__ . "/../../min.treni.no/public_html/venteliste_data";
        $pf = "$vd/{$kode}.json";
        if (is_dir($vd) && (!is_file($pf) || str_contains(@file_get_contents($pf) ?: "", '"bygges"'))) {
            @file_put_contents($pf, json_encode([
                "navn" => $rad["navn"], "plass" => 0, "bygges" => true,
                "generert" => date("Y-m-d"),
                "skjema_url" => "https://treni.no/venteliste-start.php?k={$kode}",
                "endringer" => [["dato" => date('d.m \\k\\l. H:i'),
                    "tekst" => $vl_sam]],
            ], JSON_UNESCAPED_UNICODE));
        }
        $sendt = true;
    }
}
?><!doctype html>
<html lang="nb">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Kom i gang — Treni venteliste</title>
<meta name="description" content="Svar på noen få spørsmål, så bygger vi ventelistesiden din med en plan tilpasset deg.">
<meta property="og:title" content="Kom i gang med Treni">
<meta property="og:description" content="Svar på noen få spørsmål, så bygger vi ventelistesiden din med en plan tilpasset deg.">
<meta property="og:type" content="website">
<meta property="og:url" content="https://treni.no/venteliste-start.php">
<meta property="og:image" content="https://treni.no/bilder/og.jpg">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🏃</text></svg>">
<link rel="stylesheet" href="stil.css?v=18">
<style>
  .vl-kort{background:#fff;border:1.5px solid hsl(148 15% 84%);border-radius:14px;
    padding:1.1rem 1.3rem;display:grid;gap:.9rem}
  .vl-kort legend, .vl-tittel{font-weight:700;font-size:1rem;padding:0 .3rem}
  .vl-kort label{display:grid;gap:.3rem;font-weight:600;font-size:.93rem;margin:0}
  .vl-kort input,.vl-kort textarea,.vl-kort select{width:100%;font:inherit;
    border:1.5px solid hsl(148 15% 80%);border-radius:10px;padding:.55rem .75rem;
    background:hsl(44 45% 99%)}
  .vl-kort input:focus,.vl-kort textarea:focus,.vl-kort select:focus{
    outline:2px solid hsl(152 62% 30%);outline-offset:1px}
  .vl-hint{font-weight:400;font-size:.8rem;color:hsl(158 10% 40%)}
  .vl-to{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
  @media (max-width:480px){.vl-to{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="smal" style="max-width:34rem; margin:0 auto; padding:2.5rem 1.2rem">
  <p class="kicker"><a href="index.html" style="color:inherit">treni.no</a> · venteliste</p>

<?php if (!$rad): ?>
  <h1 style="font-size:clamp(1.6rem,5vw,2.2rem)">Lenken virker ikke lenger</h1>
  <p>Denne lenken er personlig og ser ut til å være ugyldig. Skriv til
  <a href="mailto:hei@treni.no">hei@treni.no</a>, så hjelper vi deg.</p>

<?php elseif ($sendt || $rad["svart_at"]): ?>
  <h1 style="font-size:clamp(1.6rem,5vw,2.2rem)">Takk, <?= htmlspecialchars(explode(" ", $rad["navn"])[0]) ?>! 🎉</h1>
  <p>Svarene dine er mottatt, og <b>siden din er klar nå</b> — veilederen
  bygger pulssonene og treningsplanen mens du ser på, og siden oppdaterer
  seg selv når de er ferdige (få minutter):</p>
  <p style="margin:1.4rem 0"><a href="https://min.treni.no/venteliste.php?t=<?= htmlspecialchars($kode) ?>"
     style="display:inline-block; background:hsl(152 62% 20%); color:#fff;
     padding:.85rem 1.6rem; border-radius:12px; font-weight:700; font-size:1.05rem;
     text-decoration:none; box-shadow:0 2px 8px hsl(152 62% 20% / .3)">Gå til siden din →</a></p>
  <p>Der finner du allerede e-boka «Trening for fjelløping», skrevet av
  treneren vår Eirik Haugsnes.</p>

<?php else: ?>
  <?php if (isset($_GET['ny'])): // rett fra «meld interesse» (Odd 20.08) ?>
  <p style="background:color-mix(in srgb, var(--accent, hsl(84 80% 34%)) 12%, transparent);
     border-radius:10px; padding:.6rem .9rem; font-weight:650; margin-bottom:.4rem">
     ✅ Takk — du står på ventelista! Én ting til, så er alt klart:</p>
  <?php endif; ?>
  <h1 style="font-size:clamp(1.6rem,5vw,2.2rem)">Velkommen, <?= htmlspecialchars(explode(" ", $rad["navn"])[0]) ?>!</h1>
  <p>Mens du står på ventelista, lager vi en personlig side til deg — med
  pulssoner, en treningsplan bygget på tallene dine, og e-boka vår. Noen
  kjappe spørsmål først (samme som testløperne får):</p>

  <?php if ($feil): ?><p class="skjema-feil"><?= htmlspecialchars($feil) ?></p><?php endif; ?>
  <?php // Revisjon 03.09: svarene bevares ved valideringsfeil (før ble alt tømt)
  $val = fn(string $n): string => htmlspecialchars((string) ($_POST[$n] ?? ""));
  $sel = fn(string $n, string $v, string $std = ""): string => (($_POST[$n] ?? $std) === $v) ? " selected" : ""; ?>

  <form method="post" style="display:grid; gap:1.1rem; margin-top:1.2rem"
        onsubmit="var b=this.querySelector('button[type=submit]');if(b.disabled){return false;}b.disabled=true;b.textContent='Sender …';">
    <input type="hidden" name="k" value="<?= htmlspecialchars($kode) ?>">
    <input type="text" name="nettside" value="" style="display:none" tabindex="-1" autocomplete="off">

    <fieldset class="vl-kort" style="border:1.5px solid hsl(148 15% 84%)">
      <legend>1 · Om deg og målet ditt 🎯</legend>
      <label>Hva er målet ditt med løpinga?
        <textarea name="maal" rows="3" required
          placeholder="F.eks.: gjøre det bra i noen få fjelløp i året …"><?= htmlspecialchars(trim($_POST["maal"] ?? $rad["om"] ?? "")) ?></textarea></label>
      <label>Alder
        <input type="number" name="alder" min="10" max="99" required value="<?= $val('alder') ?>"></label>
    </fieldset>

    <fieldset class="vl-kort" style="border:1.5px solid hsl(148 15% 84%)">
      <legend>2 · Puls og tider 🫀</legend>
      <label>Høyeste puls du har målt i konkurranse eller hard økt siste halvår
        <input type="number" name="puls" min="120" max="230" placeholder="La stå tomt om du er usikker" value="<?= $val('puls') ?>">
        <span class="vl-hint">Usikker er helt greit — da beregner vi et startpunkt fra alderen din.</span></label>
      <label>Beste tid siste halvår — med distanse (valgfritt)
        <input type="text" name="beste_tid" placeholder="F.eks. 10 km på 52:30" value="<?= $val('beste_tid') ?>"></label>
      <label>Personlige rekorder — flate løp og motbakke/fjelløp (valgfritt)
        <textarea name="rekorder" rows="2" placeholder="F.eks.: 5 km 21:30 · 10 km 45:10 · Storheia Opp 58:20"><?= $val('rekorder') ?></textarea></label>
    </fieldset>

    <fieldset class="vl-kort" style="border:1.5px solid hsl(148 15% 84%)">
      <legend>3 · Treningsuka di 👟</legend>
      <div class="vl-to">
        <label>Kilometer i en vanlig uke
          <input type="number" name="km_uke" min="1" max="200" step="0.5" required value="<?= $val('km_uke') ?>"></label>
        <label>Hva er det lengste du har løpt de siste 30 dagene? (km)
          <input type="number" name="lengste_30d" min="0" max="200" step="0.5"
                 placeholder="f.eks. 12" value="<?= $val('lengste_30d') ?>"></label>
        <label>Minst så mange km vil jeg starte med i uke 1
          <input type="number" name="start_km" min="1" max="200" step="0.5" required value="<?= $val('start_km') ?>"></label>
      </div>
      <label>Hvor løper du mest?
        <select name="underlag">
          <option value="terreng"<?= $sel('underlag', 'terreng', 'begge') ?>>Mest terreng og fjell</option>
          <option value="vei"<?= $sel('underlag', 'vei', 'begge') ?>>Mest vei og asfalt</option>
          <option value="begge"<?= $sel('underlag', 'begge', 'begge') ?>>Begge deler</option>
        </select></label>
      <label>Hvordan tenker du om løpemengden fremover?
        <select name="volum_onske">
          <option value="stabil"<?= $sel('volum_onske', 'stabil', 'stabil') ?>>Jeg ligger på et volum som passer meg nå</option>
          <option value="oke"<?= $sel('volum_onske', 'oke', 'stabil') ?>>Jeg ønsker å øke løpemengden gradvis</option>
          <option value="usikker"<?= $sel('volum_onske', 'usikker', 'stabil') ?>>Usikker — ta det opp med treneren</option>
        </select></label>
      <label>Driver du med annen trening? Hva og hvor mye? (valgfritt)
        <textarea name="alternativ" rows="2" placeholder="F.eks.: sykkel 1×/uke, ski om vinteren, fotball …"><?= $val('alternativ') ?></textarea></label>
      <label>Trener du styrke? Hva og hvor ofte? (valgfritt)
        <textarea name="styrke" rows="2" placeholder="F.eks.: 2×/uke — knebøy, utfall, legghev …"><?= $val('styrke') ?></textarea></label>
      <label>Hvordan har treningen din vært de siste 3 månedene?
        <select name="siste_90d">
          <option value="jevn"<?= $sel('siste_90d', 'jevn', 'jevn') ?>>Jevn — trent omtrent som nå hele perioden</option>
          <option value="ujevn"<?= $sel('siste_90d', 'ujevn', 'jevn') ?>>Ujevn — litt av og på</option>
          <option value="opphold"<?= $sel('siste_90d', 'opphold', 'jevn') ?>>Opphold — pause eller svært lite trening</option>
          <option value="mer_for"<?= $sel('siste_90d', 'mer_for', 'jevn') ?>>Jeg trente MER før enn jeg gjør nå</option>
        </select></label>
      <div class="vl-to">
        <label>Typisk ukevolum siste 3 måneder (km, valgfritt)
          <input type="number" name="km_uke_90d" min="0" max="250" step="0.5"
                 placeholder="Omtrent som nå? La stå tomt" value="<?= $val('km_uke_90d') ?>"></label>
        <label>Lengste tur siste 3 måneder (km, valgfritt)
          <input type="number" name="lengste_90d" min="0" max="200" step="0.5"
                 placeholder="f.eks. 18" value="<?= $val('lengste_90d') ?>"></label>
      </div>
      <div class="vl-to">
        <label>Mest du har løpt i én uke det siste året (km, valgfritt)
          <input type="number" name="toppuke_aar" min="0" max="300" step="0.5"
                 placeholder="f.eks. 45" value="<?= $val('toppuke_aar') ?>"></label>
        <label>Er du vant til høydemeter og nedoverløping?
          <select name="fjellvane">
            <option value="">Velg …</option>
            <option value="mye"<?= $sel('fjellvane', 'mye', '') ?>>Ja — fast del av treningen min</option>
            <option value="litt"<?= $sel('fjellvane', 'litt', '') ?>>Litt — av og til</option>
            <option value="nei"<?= $sel('fjellvane', 'nei', '') ?>>Nei — mest flatt</option>
          </select></label>
      </div>
      <span class="vl-hint">Planen starter på volumet kroppen din er vant til — og bygges trygt derfra.
      De siste 3 månedene forteller oss hva kroppen din faktisk tåler nå — derfor spør vi.</span>
    </fieldset>

    <fieldset class="vl-kort" style="border:1.5px solid hsl(148 15% 84%)">
      <legend>4 · Løpet du sikter mot 🏁 <span class="vl-hint">(valgfritt — jo mer vi vet, jo bedre)</span></legend>
      <label>Løpets navn og dato
        <input type="text" name="lop_navn" placeholder="F.eks. Lofoten High 5, juni 2027" value="<?= $val('lop_navn') ?>"></label>
      <div class="vl-to">
        <label>Lengde (km)
          <input type="number" name="lop_km" min="1" max="300" step="0.1" value="<?= $val('lop_km') ?>"></label>
        <label>Type løp
          <select name="lop_type">
            <option value="">Velg …</option>
            <option value="flatt"<?= $sel('lop_type', 'flatt', '') ?>>Flatt (vei/bane)</option>
            <option value="motbakke"<?= $sel('lop_type', 'motbakke', '') ?>>Motbakke (bare opp)</option>
            <option value="opp_og_ned"<?= $sel('lop_type', 'opp_og_ned', '') ?>>Opp og ned</option>
            <option value="fjell"<?= $sel('lop_type', 'fjell', '') ?>>Fjelløp / skyrace / ultra</option>
          </select></label>
      </div>
      <div class="vl-to">
        <label>Høydemeter opp
          <input type="number" name="lop_hm_opp" min="0" max="20000" value="<?= $val('lop_hm_opp') ?>"></label>
        <label>Høydemeter ned
          <input type="number" name="lop_hm_ned" min="0" max="20000" value="<?= $val('lop_hm_ned') ?>"></label>
      </div>
      <label>Hvor viktig er dette løpet for deg?
        <select name="lop_prioritet">
          <option value="">Velg …</option>
          <option value="A"<?= $sel('lop_prioritet', 'A', '') ?>>A — hovedmålet mitt, her vil jeg prestere maksimalt</option>
          <option value="B"<?= $sel('lop_prioritet', 'B', '') ?>>B — viktig delmål på veien</option>
          <option value="C"<?= $sel('lop_prioritet', 'C', '') ?>>C — vil gjøre det bra, men ikke hovedmålet</option>
          <option value="D"<?= $sel('lop_prioritet', 'D', '') ?>>D — testløp / del av treningen</option>
        </select></label>
    </fieldset>

    <button class="btn" type="submit" style="justify-self:start">Send inn — og få siden min 🚀</button>
  </form>
  <p class="liten" style="margin-top:1rem">Svarene brukes bare til å lage
  veiledningen din, og slettes hvis du ber om det. Ingen Strava-kobling ennå —
  den kommer når plassen din åpner.</p>
<?php endif; ?>

  <footer style="margin-top:3rem" class="liten">
    <p>PAULSEN UTVIKLING · org.nr 938 158 614 · Norge ·
       <a href="mailto:hei@treni.no">hei@treni.no</a></p>
  </footer>
</main>
</body>
</html>
