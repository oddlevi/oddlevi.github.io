<?php
declare(strict_types=1);
// Odd 13.09: mobilnummer-porten på /direkte/ må være passert før stegene vises
if (empty($_COOKIE["td_port"]) && $_SERVER["REQUEST_METHOD"] !== "POST" && !isset($_GET["apen"])) { header("Location: /direkte/#port"); exit; }
// F-56 (Odd 12.09.2026): landingsside for arrangører, steg for steg. Ett budskap, ett eksempel, enkel forklaring per steg,
// stor grønn Neste-knapp. Siste steg er kontaktskjema → hei@treni.no (samme rør som bli-testloper.php).
require_once dirname(__DIR__) . "/spamvern.php";
$sendt = false; $feil = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    treni_begrens_post(["navn" => 120, "lop" => 160, "dato" => 40, "epost" => 190, "melding" => 1500]);
    $sv = treni_spamvern_ok();
    $navn = treni_ren($_POST["navn"] ?? "", 120); $lop = treni_ren($_POST["lop"] ?? "", 160);
    $dato = treni_ren($_POST["dato"] ?? "", 40); $epost = treni_ren($_POST["epost"] ?? "", 190);
    $melding = treni_ren($_POST["melding"] ?? "", 1500, true);
    if ($sv !== "") { $feil = $sv; }
    elseif ($navn === "" || $lop === "" || !filter_var($epost, FILTER_VALIDATE_EMAIL)) { $feil = "Fyll inn navn, løp og en gyldig e-postadresse."; }
    else {
        $rad = ["ts" => date("c"), "navn" => $navn, "lop" => $lop, "dato" => $dato, "epost" => $epost, "melding" => $melding, "ip" => treni_ip()];
        @file_put_contents(dirname(__DIR__, 2) . "/arrangor_henvendelser.jsonl", json_encode($rad, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        $kropp = "Ny henvendelse fra arrangør (treni.no/arrangor)\n\nNavn: $navn\nLøp: $lop\nDato: $dato\nE-post: $epost\n\n$melding\n";
        $ok = false;
        try { require_once dirname(__DIR__, 2) . "/epost_smtp.php"; if (function_exists("treni_epost_send")) { $ok = treni_epost_send("hei@treni.no", "Arrangør: $lop", $kropp); } } catch (Throwable $e) { $ok = false; }
        if (!$ok) { @mail("hei@treni.no", "Arrangør: $lop", $kropp, "From: hei@treni.no"); }
        $sendt = true;
    }
}
$steg = [
  ["kicker" => "Steg 1 av 6", "tittel" => "Få flere til å følge med på løpet ditt.",
   "tall" => [["227", "personer fulgte løpet live"], ["760", "visninger på løpsdagen"], ["95 %", "på mobil"], ["1 288", "søk etter navn"]],
   "eksempel" => "Terrengløpet Skjervøy 20:1000 fikk 227 ekstra personer som fulgte løpet på treni.no/skjervoy 12. september 2026. De søkte opp løpere over 1 200 ganger.",
   "forklaring" => "Vi lager en egen live-side for løpet ditt, rett fra tidtakingen du allerede har. Alle løperne, klassene og passeringene på toppene, med km. Siden har din logo og din påmeldingslenke.",
   "lenke" => ["https://treni.no/skjervoy/", "Se live-siden fra Skjervøy"], "bilder" => ["/arrangor/live_skjervoy.png"], "skjerm" => true],
  ["kicker" => "Steg 2 av 6", "tittel" => "Vi forteller løperne hvordan de bør disponere løpet sitt.",
   "eksempel" => "Før Skjervøy la vi ut snittpasseringer på hver topp for måltidene 1:45, 2:00, 2:15 og 2:30, regnet fra fjorårets resultater. Innlegget nådde over 500 kontoer og ble lagret og delt.",
   "forklaring" => "Vi regner ut hvor løperne bør ligge på hver post for å nå tiden sin, og legger det ut med deg som samarbeidspartner på Instagram og Facebook. Løpet ditt får innhold, løperne får trygghet.",
   "bilder" => ["https://treni.no/bilder/ig/karR_1.png?v=4", "https://treni.no/bilder/ig/karR_2.png?v=4", "https://treni.no/bilder/ig/karR_3.png?v=4", "https://treni.no/bilder/ig/karR_4.png?v=4", "https://treni.no/bilder/ig/karR_5.png?v=4", "https://treni.no/bilder/ig/karR_6.png?v=4", "https://treni.no/bilder/ig/karR_7_ig.png?v=4", "https://treni.no/bilder/ig/karR_8_ig.png?v=4"],
   "bildetekst" => "Hele karusellen, 8 bilder. Trykk på et bilde for å se det stort, bla med pilene."],
  ["kicker" => "Steg 3 av 6", "tittel" => "Løpeklubbene vil se sine egne.",
   "eksempel" => "Tromsø Løpeklubb stilte med 13 løpere på Skjervøy. De fikk en egen visning med bare sine, delt i klubbens Spond-gruppe før start.",
   "forklaring" => "Hver klubb kan søke seg fram og følge sine løpere post for post. Det gjør klubbene til ambassadører for løpet ditt.",
   "bilder" => ["/arrangor/klubb_skjervoy.png"], "skjerm" => true],
  ["kicker" => "Steg 4 av 6", "tittel" => "Vi lager resultatinnlegget etter løpet.",
   "eksempel" => "Dagen etter: vinnere, snittpasseringer, hvor løpet ble avgjort, klubbenes plasseringer. Passeringene ligger på live-siden til neste år.",
   "forklaring" => "Vi lager resultatinnlegget og tagger deg. Løpere som brukte Treni får sin egen analyse post for post rett etter mål.",
   "bilder" => ["https://treni.no/bilder/ig/innlegg_treni_direkte_12sep4A.jpg", "https://treni.no/bilder/ig/innlegg_tlk_12sep5.jpg"],
   ],
  ["kicker" => "Steg 5 av 6", "tittel" => "Løperne får svar rett etter mål.",
   "eksempel" => "Skjemaet ble laget dagen før ut fra løperens beste år. 50 minutter etter mål lå analysen i chatten på løperens egen side.",
   "forklaring" => "Dette er kjernen i Treni: en trener som ser hver økt og hvert løp. Løpere som bruker Treni får dette i hvert løp vi dekker.",
   "analyse_html" => true],
  ["kicker" => "Steg 6 av 6", "tittel" => "Vi trenger tre ting fra deg.",
   "eksempel" => "1. Åpen tidtaking, for eksempel EQ Timing, Race Result eller Sportstiming.\n2. Noen bilder vi kan bruke.\n3. At dere deler live-siden i deres kanaler.",
   "forklaring" => "Treni er en løpeveileder fra Tromsø med trener i loopen. Vi dekker løp fordi det er der løperne er. Du får publikum og innhold, vi får vise hva vi gjør. Hva det innebærer for deg, tar vi i en prat."],
];
?><!DOCTYPE html>
<html lang="nb">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Treni Direkte · for arrangører</title>
<meta name="description" content="Live-side for publikum, pacing-innlegg før løpet, analyse til løperne og resultatinnlegg etter. Seks steg, ett spørsmål.">
<meta property="og:title" content="Få flere til å følge med på løpet ditt.">
<meta property="og:description" content="Treni Direkte: 227 fulgte Skjervøy 20:1000 live på treni.no, 760 visninger, 95 prosent på mobil. Live-side, pacing før løpet, analyse til løperne og resultatinnlegg etter. Seks steg, ett spørsmål.">
<meta property="og:image" content="https://treni.no/arrangor/og.jpg?v=3">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta property="og:url" content="https://treni.no/arrangor/">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="stylesheet" href="/stil.css?v=30">
<style>
  .steg { display:none; }
  .steg.aktiv { display:block; animation: inn .45s var(--ease-ut) both; }
  @keyframes inn { from { opacity:0; transform: translateY(14px); } to { opacity:1; transform:none; } }
  .stegkort { max-width: 40rem; margin: 0 auto; padding: clamp(1.4rem, 4vw, 2.4rem); background: hsl(var(--surface)); border: 1px solid hsl(var(--border)); border-radius: var(--radius); box-shadow: var(--shadow-md); }
  .stegkort h2 { font-family: var(--font-serif); font-size: clamp(1.6rem, 4.6vw, 2.3rem); line-height: 1.15; margin: .3rem 0 1rem; text-wrap: balance; }
  .eksempel { background: hsl(var(--bg)); border-left: 4px solid hsl(var(--accent)); padding: .9rem 1rem; border-radius: 8px; margin: 1rem 0; font-size: 1.02rem; line-height: 1.5; }
  .eksempel b { display:block; font-size:.78rem; letter-spacing:.08em; text-transform: uppercase; color: hsl(var(--muted-fg)); margin-bottom:.3rem; }
  .forklaring { font-size: 1.05rem; line-height: 1.55; color: hsl(var(--fg)); }
  .neste { display:block; width:100%; margin-top:1.4rem; padding: 1.1rem 1.4rem; font-size: 1.25rem; font-weight: 700; color: hsl(var(--primary-fg)); background: var(--gradient-cta); border: none; border-radius: 999px; box-shadow: var(--shadow-cta); cursor: pointer; transition: transform .2s var(--ease-ut), box-shadow .2s; }
  .neste:hover { transform: translateY(-2px); box-shadow: var(--shadow-cta-hover); }
  .neste:focus-visible { outline: 3px solid hsl(var(--accent)); outline-offset: 3px; }
  .tilbake { background:none; border:none; color: hsl(var(--muted-fg)); font-size:.95rem; cursor:pointer; padding:.6rem 0; margin-top:.6rem; }
  .prikker { display:flex; gap:.45rem; justify-content:center; margin: 1.2rem 0 .4rem; }
  .prikker span { width:10px; height:10px; border-radius:50%; background: hsl(var(--border)); transition: background .3s, transform .3s; }
  .prikker span.na { background: hsl(var(--accent)); transform: scale(1.25); }
  .lenke { display:inline-block; margin-top:.6rem; font-weight:600; }
  .bilder { display:flex; gap:.6rem; margin-top:.8rem; }
  .bilder img { flex:1 1 0; min-width:0; max-width:48%; border-radius:10px; border:1px solid hsl(var(--border)); box-shadow: var(--shadow-sm); }
  .bilder img:only-child { max-width: 60%; margin: 0 auto; }
  .bilder.skjerm img { max-height: 340px; object-fit: cover; object-position: top; }
  .bilder.skjerm { position: relative; }
  .bilder.skjerm::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 70px; background: linear-gradient(to bottom, transparent, hsl(var(--bg))); pointer-events: none; border-radius: 0 0 10px 10px; }
  .bilder img { cursor: zoom-in; }
  .tall { display: grid; grid-template-columns: repeat(2, 1fr); gap: .7rem; margin: .3rem 0 1.1rem; }
  .tall div { background: var(--gradient-cta); color: hsl(var(--primary-fg)); border-radius: 14px; padding: 1rem 1rem .9rem; box-shadow: var(--shadow-cta); }
  .tall b { display: block; font-family: var(--font-sans); font-weight: 800; font-size: clamp(2.3rem, 9vw, 3.2rem); line-height: 1; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
  .tall span { display: block; margin-top: .4rem; font-size: .9rem; font-weight: 600; opacity: .92; line-height: 1.3; }
  .verdi { font-size: .8rem; letter-spacing: .1em; text-transform: uppercase; font-weight: 700; color: hsl(var(--accent)); margin: .2rem 0 .5rem; }
  .analyse { margin: 1rem 0 0; }
  .analyse summary { cursor: pointer; font-weight: 700; color: hsl(var(--primary)); padding: .6rem .9rem; border: 1.5px solid hsl(var(--primary)); border-radius: 999px; display: inline-block; list-style: none; }
  .analyse summary::-webkit-details-marker { display: none; }
  .analyse .boble { margin-top: .2rem; background: hsl(var(--surface-2)); border: 1px solid hsl(var(--border)); border-radius: 16px 16px 16px 4px; padding: .9rem 1rem; font-size: .95rem; line-height: 1.5; }
  .analyse .meta { margin-top: .6rem; font-size: .78rem; color: hsl(var(--muted-fg)); }
  .analyse .boble p { margin: 0 0 .7rem; }
  .analyse .a-topp { font-weight: 700; }
  .analyse .a-mellom { margin: 1rem 0 .4rem; font-size: .78rem; letter-spacing: .08em; text-transform: uppercase; color: hsl(var(--muted-fg)); font-weight: 700; }
  .analyse .a-tab { width: 100%; border-collapse: collapse; font-size: .92rem; font-variant-numeric: tabular-nums; }
  .analyse .a-tab th { text-align: left; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: hsl(var(--muted-fg)); padding: .2rem .4rem .35rem 0; border-bottom: 1px solid hsl(var(--border)); }
  .analyse .a-tab td { padding: .38rem .4rem .38rem 0; border-bottom: 1px solid hsl(var(--border)); }
  .analyse .a-tab td small { color: hsl(var(--muted-fg)); }
  .analyse .a-tab td.god { color: hsl(var(--primary)); font-weight: 600; }
  .analyse ol { margin: 0 0 .7rem 1.2rem; padding: 0; list-style: decimal; }
  .analyse ol li { margin: 0 0 .45rem; padding: 0; background: none; border: none; box-shadow: none; border-radius: 0; display: list-item; font-size: inherit; }
  .analyse ol li b { display: inline; margin: 0; font-size: inherit; }
  .analyse .boble p b { display: inline; }
  .analyse .a-tab td:last-child, .analyse .a-tab th:last-child { white-space: nowrap; text-align: right; }
  .analyse .a-tab td:nth-child(2) { white-space: nowrap; }
  .analyse .a-tab td:first-child small { display: block; line-height: 1.1; }
  .analyse .a-tab td, .analyse .a-tab th { padding-right: .5rem; }
  .bilder.stripe { overflow-x: auto; scroll-snap-type: x mandatory; padding-bottom: .4rem; -webkit-overflow-scrolling: touch; }
  .bilder.stripe img { flex: 0 0 62%; max-width: 62%; scroll-snap-align: start; }
  #lys { border: 0; padding: 0; background: transparent; max-width: 100vw; max-height: 100vh; width: 100vw; height: 100vh; }
  #lys::backdrop { background: rgba(8, 18, 12, .92); }
  #lys .ramme { position: relative; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
  #lys img { max-width: min(94vw, 720px); max-height: 88vh; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,.5); }
  #lys button { position: absolute; border: none; background: rgba(255,255,255,.92); color: #123; width: 3rem; height: 3rem; border-radius: 50%; font-size: 1.5rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; }
  #lys .forrige { left: .8rem; top: 50%; transform: translateY(-50%); } #lys .neste-b { right: .8rem; top: 50%; transform: translateY(-50%); } #lys .lukk { right: .8rem; top: .8rem; }
  #lys .teller { position: absolute; bottom: 1rem; left: 50%; transform: translateX(-50%); color: #fff; font-weight: 600; font-size: .95rem; background: rgba(0,0,0,.45); padding: .3rem .8rem; border-radius: 999px; }
  form label { display:block; font-weight:600; margin: .9rem 0 .3rem; }
  form input, form textarea { width:100%; padding:.75rem .9rem; border:1px solid hsl(var(--border)); border-radius:10px; background: hsl(var(--bg)); color: hsl(var(--fg)); font: inherit; }
  .feil { background: hsl(0 70% 95%); color: hsl(0 60% 30%); padding:.7rem 1rem; border-radius:8px; }
  @media (prefers-reduced-motion: reduce) { .steg.aktiv { animation:none; } .neste:hover { transform:none; } }
</style>
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<main>
<header class="hero smal" style="padding-bottom:0">
  <p class="kicker"><a href="/" style="color:inherit">treni.no</a> · <a href="/direkte/" style="color:inherit">Treni Direkte</a></p>
  <h1 class="skjult-tittel" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Treni Direkte for arrangører</h1>
</header>

<section>
<?php if ($sendt): ?>
  <div class="stegkort">
    <h2>Takk, <?= htmlspecialchars(explode(" ", $navn)[0]) ?>!</h2>
    <p class="forklaring">Vi har fått henvendelsen om <b><?= htmlspecialchars($lop) ?></b>. Odd Levi svarer på e-post innen et par dager med et forslag til hvordan vi dekker løpet.</p>
    <a class="lenke" href="/">← Til forsiden</a>
  </div>
<?php else: ?>
  <div class="prikker" id="prikker" aria-hidden="true"><?php for ($i = 0; $i <= count($steg); $i++): ?><span<?= $i === 0 ? ' class="na"' : '' ?>></span><?php endfor; ?></div>
  <?php foreach ($steg as $i => $s): ?>
  <div class="stegkort steg<?= $i === 0 ? ' aktiv' : '' ?>" data-steg="<?= $i ?>">
    <p class="kicker" style="margin:0"><?= htmlspecialchars($s["kicker"]) ?></p>
    <h2><?= htmlspecialchars($s["tittel"]) ?></h2>
    <?php if (!empty($s["tall"])): ?><p class="verdi">Verdien for deg som arrangør</p><div class="tall"><?php foreach ($s["tall"] as $t): ?><div><b><?= htmlspecialchars($t[0]) ?></b><span><?= htmlspecialchars($t[1]) ?></span></div><?php endforeach; ?></div><?php endif; ?>
    <div class="eksempel"><b><?= $i === count($steg) - 1 ? "Det vi trenger" : "Eksempel" ?></b><?= nl2br(htmlspecialchars($s["eksempel"])) ?><?php if (!empty($s["lenke"])): ?><br><a class="lenke" href="<?= htmlspecialchars($s["lenke"][0]) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($s["lenke"][1]) ?> ↗</a><?php endif; ?>
      <?php if (!empty($s["bilder"])): ?><div class="bilder<?= count($s["bilder"]) > 2 ? ' stripe' : '' ?><?= !empty($s["skjerm"]) ? ' skjerm' : '' ?>"><?php foreach ($s["bilder"] as $bi => $b): ?><img src="<?= htmlspecialchars($b) ?>" alt="Bilde <?= $bi + 1 ?> av <?= count($s["bilder"]) ?>" loading="lazy" data-stor="<?= htmlspecialchars($b) ?>" tabindex="0"><?php endforeach; ?></div>
      <?php if (!empty($s["bildetekst"])): ?><p style="margin:.5rem 0 0; font-size:.85rem; color:hsl(var(--muted-fg))">🔍 <?= htmlspecialchars($s["bildetekst"]) ?></p><?php endif; ?><?php endif; ?></div>
    <?php if (!empty($s["analyse_html"])): ?>
    <div class="analyse"><div class="boble">
      <p class="a-topp">🏁 Skjervøy 20:1000 i dag</p>
      <p><b>1:40:10.</b> Du lå i tetgruppa hele veien til Lailafjellet, og åpningen var nøyaktig slik vi snakket om: 11:09 ved Stussnes, fem sekunder foran ditt beste år, ikke ett sekund for hardt.</p>
      <p class="a-mellom">Post for post mot 2024-løpet ditt (1:36:02)</p>
      <table class="a-tab"><thead><tr><th>Post</th><th>Tid</th><th>Mot 2024</th></tr></thead><tbody>
        <tr><td>Stussnes <small>2,2 km</small></td><td>11:09</td><td class="god">5 s foran</td></tr>
        <tr><td>Skattørfjellet <small>5,7 km</small></td><td>29:31</td><td class="god">7 s foran</td></tr>
        <tr><td>Lailafjellet <small>10,7 km</small></td><td>55:57</td><td>1:58 bak</td></tr>
        <tr><td>Trollet <small>16,3 km</small></td><td>1:25:15</td><td>3:25 bak</td></tr>
        <tr><td>SIK-huset <small>18,4 km</small></td><td>1:35:38</td><td>4:06 bak</td></tr>
        <tr><td><b>Mål</b> <small>19,7 km</small></td><td><b>1:40:10</b></td><td>4:08 bak</td></tr>
      </tbody></table>
      <p class="a-mellom">Der løpet ble avgjort</p>
      <p>Skjemaet var 1:38. De tre som lå ved siden av deg på Lailafjellet endte på 1:38:10 til 1:39:08, så det er dem det er naturlig å måle mot. Forskjellen ligger på én strekning:</p>
      <ol>
        <li><b>Lailafjellet til Trollet.</b> Du brukte 29:18, de brukte 28:00 til 29:00, og du selv brukte 27:50 i 2024. Der ligger ett til halvannet minutt.</li>
        <li><b>Nedløpet og oppløpet</b> var som i 2024, på sekundet. Beina var gode nedover, som ventet etter 88 % rolig tid inn mot løpet.</li>
      </ol>
      <p class="a-mellom">Hva det betyr</p>
      <p>Formen holdt hele veien. Det som skiller 1:40 fra 1:38 i dag var fjellstrekningen fra Lailafjellet, ikke åpningen og ikke beina. Det er den mest trenbare biten av alle, og den ligger inne i høstplanen din med to uker på 82 og 96 km i terreng før VM-uka.</p>
      <div class="meta">Treni · 12.09 kl. 15:00 · navn og plassering er tatt ut av hensyn til løperen</div>
    </div></div>
    <?php endif; ?>
    <p class="forklaring"><?= htmlspecialchars($s["forklaring"]) ?></p>
    <button type="button" class="neste" data-neste><?= $i === count($steg) - 1 ? "Ja, la oss snakke om løpet mitt" : "Neste" ?> →</button>
    <?php if ($i > 0): ?><button type="button" class="tilbake" data-tilbake>← Tilbake</button><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <div class="stegkort steg" data-steg="<?= count($steg) ?>">
    <p class="kicker" style="margin:0">Siste steg</p>
    <h2>Fortell oss om løpet.</h2>
    <?php if ($feil !== ""): ?><p class="feil"><?= htmlspecialchars($feil) ?></p><?php endif; ?>
    <form method="post" action="/arrangor/">
      <label for="navn">Navnet ditt</label><input id="navn" name="navn" required autocomplete="name" value="<?= htmlspecialchars($_POST["navn"] ?? "") ?>">
      <label for="lop">Løpet</label><input id="lop" name="lop" required placeholder="F.eks. Tromsø Fjellmaraton" value="<?= htmlspecialchars($_POST["lop"] ?? "") ?>">
      <label for="dato">Dato (om du vet den)</label><input id="dato" name="dato" placeholder="F.eks. 3. oktober 2026" value="<?= htmlspecialchars($_POST["dato"] ?? "") ?>">
      <label for="epost">E-post</label><input id="epost" name="epost" type="email" required autocomplete="email" value="<?= htmlspecialchars($_POST["epost"] ?? "") ?>">
      <label for="melding">Noe vi bør vite? Tidtaking, antall deltakere, hva dere ønsker</label><textarea id="melding" name="melding" rows="4"><?= htmlspecialchars($_POST["melding"] ?? "") ?></textarea>
      <div style="margin-top:1rem"><?php treni_spamvern_felt(); ?></div>
      <button type="submit" class="neste">Send →</button>
      <button type="button" class="tilbake" data-tilbake>← Tilbake</button>
    </form>
    <p style="font-size:.85rem;color:hsl(var(--muted-fg));margin-top:1rem">Vi bruker opplysningene bare til å svare deg. <a href="/personvern.html">Personvern</a>.</p>
  </div>
<?php endif; ?>
</section>
</main>
<dialog id="lys" aria-label="Bilde"><div class="ramme"><button type="button" class="forrige" aria-label="Forrige">‹</button><img src="" alt=""><button type="button" class="neste-b" aria-label="Neste">›</button><button type="button" class="lukk" aria-label="Lukk">✕</button><span class="teller"></span></div></dialog>
<script>
(function () {
  // Odd 13.09: trykk på et bilde viser det stort, med pil fram og tilbake i samme steg
  var lys = document.getElementById('lys'), lysImg = lys.querySelector('img'), teller = lys.querySelector('.teller');
  var liste = [], idx = 0;
  function vis(i) { idx = (i + liste.length) % liste.length; lysImg.src = liste[idx]; teller.textContent = (idx + 1) + ' / ' + liste.length; lys.querySelector('.forrige').hidden = lys.querySelector('.neste-b').hidden = liste.length < 2; }
  document.addEventListener('click', function (ev) {
    var im = ev.target.closest('.bilder img[data-stor]');
    if (!im) { return; }
    liste = Array.prototype.slice.call(im.parentElement.querySelectorAll('img[data-stor]')).map(function (x) { return x.dataset.stor; });
    vis(liste.indexOf(im.dataset.stor)); try { lys.showModal(); } catch (e) { window.open(im.dataset.stor, '_blank'); }
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && ev.target.matches && ev.target.matches('.bilder img[data-stor]')) { ev.target.click(); } });
  lys.querySelector('.forrige').addEventListener('click', function () { vis(idx - 1); });
  lys.querySelector('.neste-b').addEventListener('click', function () { vis(idx + 1); });
  lys.querySelector('.lukk').addEventListener('click', function () { lys.close(); });
  lys.addEventListener('click', function (ev) { if (ev.target === lys || ev.target.classList.contains('ramme')) { lys.close(); } });
  lys.addEventListener('keydown', function (ev) { if (ev.key === 'ArrowLeft') { vis(idx - 1); } if (ev.key === 'ArrowRight') { vis(idx + 1); } });
  var sx = 0; lys.addEventListener('touchstart', function (ev) { sx = ev.touches[0].clientX; }, {passive: true});
  lys.addEventListener('touchend', function (ev) { var dx = ev.changedTouches[0].clientX - sx; if (Math.abs(dx) > 40) { vis(dx < 0 ? idx + 1 : idx - 1); } }, {passive: true});
})();
(function () {
  var kort = Array.prototype.slice.call(document.querySelectorAll('.steg'));
  var prikker = document.querySelectorAll('#prikker span');
  if (!kort.length) { return; }
  var na = <?= $feil !== "" ? count($steg) : 0 ?>;
  function vis(i) {
    na = Math.max(0, Math.min(kort.length - 1, i));
    kort.forEach(function (k, j) { k.classList.toggle('aktiv', j === na); });
    prikker.forEach(function (p, j) { p.classList.toggle('na', j === na); });
    try { history.replaceState(null, '', '#steg' + (na + 1)); } catch (e) {}
    window.scrollTo({top: 0, behavior: 'smooth'});
    var f = kort[na].querySelector('input'); if (f && na === kort.length - 1) { setTimeout(function () { f.focus(); }, 350); }
  }
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-neste]')) { vis(na + 1); }
    else if (ev.target.closest('[data-tilbake]')) { vis(na - 1); }
  });
  var m = (location.hash || '').match(/steg(\d+)/); if (m) { vis(parseInt(m[1], 10) - 1); } else { vis(na); }
})();
</script>
</body>
</html>
