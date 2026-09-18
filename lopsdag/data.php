<?php
// F-83 (Odd 13.09.2026): generell datakilde for Treni Løpsdag-sider.
// Ett løp per konfigurasjonsfil i lopsdag/lop/<slug>.json:
//   {"kilde":"raceresult","event":"374352","navn":"Ruskamaraton Lapland",
//    "dato":"2026-09-12","sted":"Levi, Finland"}
//   ?lop=<slug>&visning=klubb&klubb=<navn>   → klubbens løpere + kontekst (det sidene bruker)
//   ?lop=<slug>&visning=resultater           → alle deltakere
//   ?lop=<slug>&visning=oppsett              → øvelser, klasser, klubber
// Race Result krever Referer mot arrangørsiden, ellers svarer de 404. Nøkkelen
// hentes ALLTID fra konfigurasjonen, den byttes av arrangøren.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('Access-Control-Allow-Origin: *');

$slug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($_GET['lop'] ?? '')));
// Odd 14.09.2026: «Legg inn telleren i malen og levi siden.» Løpsdag-sidene hadde
// ingen besøkstelling, så vi visste ikke om noen åpnet Levi-siden i det hele tatt.
// Samme mønster som Skjervøy-loggen: ingen IP lagres, bare en daglig saltet hash
// så unike kan telles, og fila ligger utenfor public_html.
if (isset($_GET['logg'])) {
    $h = substr(hash('sha256', date('Y-m-d') . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 12);
    $inn = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $rad = ['ts' => date('c'), 'bes' => $h,
            'hendelse' => mb_substr((string) $_GET['logg'], 0, 20),
            'mobil' => (bool) preg_match('/Mobi|Android|iPhone/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))];
    // Rett Nord (F-216, Odd 18.09.2026: «tracker/statistikk på besøk og bruk, sånn at man ser hva folk
    // gjør på siden»): side og detalj bærer hendelsene (seksjon, klikk, sok, hele, dybde, tid).
    foreach (['lop', 'klubb', 'ref', 'side', 'detalj'] as $k) {
        if (isset($inn[$k]) && is_scalar($inn[$k])) { $rad[$k] = mb_substr((string) $inn[$k], 0, 120); }
    }
    @file_put_contents(dirname(__DIR__, 2) . '/lopsdag_logg.jsonl',
                       json_encode($rad, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    echo '{"ok":true}'; exit;
}

if ($slug === '') { http_response_code(400); exit(json_encode(['feil' => 'mangler ?lop='])); }
$kfil = __DIR__ . '/lop/' . $slug . '.json';
if (!is_readable($kfil)) { http_response_code(404); exit(json_encode(['feil' => 'ukjent løp: ' . $slug])); }
$LOP = json_decode((string) file_get_contents($kfil), true) ?: [];
if (($LOP['kilde'] ?? '') !== 'raceresult' || empty($LOP['event'])) {
    http_response_code(501); exit(json_encode(['feil' => 'bare raceresult er støttet foreløpig']));
}
define('EVENT', (string) $LOP['event']);
define('KONFIG', 'https://my.raceresult.com/' . EVENT . '/results/config?lang=fi&sanitize=true');
define('REFERER', 'https://my.raceresult.com/' . EVENT . '/results?lang=fi');
$CACHE = sys_get_temp_dir() . '/treni_lopsdag_' . EVENT . '_';

function hent(string $url): ?string {
    $ctx = stream_context_create(['http' => ['timeout' => 25,
        'header' => "User-Agent: Mozilla/5.0 (treni.no)\r\nAccept: application/json\r\nReferer: " . REFERER . "\r\n"]]);
    $s = @file_get_contents($url, false, $ctx);
    return $s === false ? null : $s;
}
function cachet(string $nokkel, int $ttl, callable $lag) {
    global $CACHE;
    $f = $CACHE . md5($nokkel) . '.json';
    if (is_readable($f) && time() - filemtime($f) < $ttl) {
        $d = json_decode((string) file_get_contents($f), true);
        if ($d !== null) { return $d; }
    }
    $d = $lag();
    if ($d !== null) { @file_put_contents($f, json_encode($d), LOCK_EX); }
    elseif (is_readable($f)) { $d = json_decode((string) file_get_contents($f), true); }   // kilden nede: server det gamle
    return $d;
}
function konfig(): array {
    return cachet('konfig', 3600, function () {
        $d = json_decode((string) hent(KONFIG), true);
        return is_array($d) && !empty($d['key']) ? $d : null;
    }) ?: [];
}
function tekst(string $s): string {
    // «{FI:Puolimaraton|EN:Halfmarathon}» og «{en:Females|fi:Naiset}» → norsk der vi kan
    if (!str_contains($s, '{')) { return $s; }
    $s = preg_replace('/\{[^}]*\}/', '', $s) ?: $s;
    return trim($s) !== '' ? trim($s) : $s;
}
function oversett(string $s): string {
    $kart = ['Puolimaraton' => 'Halvmaraton', 'Halfmarathon' => 'Halvmaraton', 'Kymppi' => '10 km',
             'Ten' => '10 km', 'Naiset' => 'Kvinner', 'Females' => 'Kvinner', 'Miehet' => 'Menn',
             'Males' => 'Menn', 'Nuorten Ruskajuoksu' => 'Ungdomsløp', 'Ruskamaraton' => 'Maraton',
             'Trailrun 16km' => 'Trailrun 16 km'];
    if (preg_match_all('/(?:FI|fi|EN|en):([^|}]+)/', $s, $m)) {
        foreach ($m[1] as $del) { if (isset($kart[trim($del)])) { return $kart[trim($del)]; } }
        return trim($m[1][0]);
    }
    return $kart[trim($s)] ?? $s;
}
function rader(): array {
    return cachet('resultater', 600, function () {
        $k = konfig();
        if (empty($k['key'])) { return null; }
        $server = $k['server'] ?? 'my.raceresult.com';
        $url = 'https://' . $server . '/' . EVENT . '/results/list?' . http_build_query([
            'key' => $k['key'], 'listname' => 'Online|Final', 'page' => 'results',
            'contest' => '0', 'r' => 'all', 'l' => '0', 'openedGroups' => '{}', 'term' => '']);
        $d = json_decode((string) hent($url), true);
        if (!is_array($d) || empty($d['data'])) { return null; }
        $ut = [];
        $flat = function ($x, array $sti) use (&$flat, &$ut) {
            if (isset($x[0]) && is_array($x[0]) && !is_array($x[0][0] ?? null)) {
                foreach ($x as $r) {
                    if (!is_array($r) || count($r) < 9) { continue; }
                    $ut[] = [
                        'nr' => (string) $r[0],
                        'id' => (string) $r[1],
                        'plass' => (int) rtrim((string) $r[2], '.'),
                        'plass_tekst' => (string) $r[2],
                        'navn' => (string) $r[3],
                        'klasse' => (string) $r[4],
                        'land' => preg_match('#flags/([A-Z]{2})\.svg#', (string) $r[5], $mm) ? $mm[1] : '',
                        'klubb' => (string) $r[6],
                        'tid' => (string) ($r[8] !== '' ? $r[8] : $r[7]),
                        'brutto' => (string) $r[7],
                        'ovelse' => oversett($sti[0] ?? ''),
                        'kjonn' => oversett($sti[1] ?? ''),
                        'klassegruppe' => oversett($sti[2] ?? ''),
                    ];
                }
                return;
            }
            foreach ($x as $k2 => $v) {
                if (is_array($v)) { $flat($v, array_merge($sti, [preg_replace('/^#\d+_/', '', (string) $k2)])); }
            }
        };
        $flat($d['data'], []);
        global $LOP;
        return ['hentet' => date('c'), 'lop' => (string) ($LOP['navn'] ?? ''),
                'dato' => (string) ($LOP['dato'] ?? ''), 'sted' => (string) ($LOP['sted'] ?? ''),
                'antall' => count($ut), 'rader' => $ut];
    });
}
function tid_sek(string $t): int {
    $d = array_map('intval', explode(':', $t));
    if (count($d) === 3) { return $d[0] * 3600 + $d[1] * 60 + $d[2]; }
    if (count($d) === 2) { return $d[0] * 60 + $d[1]; }
    return PHP_INT_MAX;
}
$v = (string) ($_GET['visning'] ?? 'resultater');
$d = rader();
if ($d === null) { http_response_code(503); echo json_encode(['feil' => 'kilden svarer ikke']); exit; }
// Odd 14.09.2026: arrangøren har ikke alltid riktig navn i påmeldingen (i Levi sto
// «Olga Olga»). «navnefiks» i konfigurasjonen retter et navn på startnummer, og
// rettelsen gjøres her, før alle visninger, så navnet er det samme overalt.
// Bare på klubbens eget ord, aldri gjettet. («navn» er løpets navn, ikke rør det.)
if (!empty($LOP['navnefiks']) && is_array($LOP['navnefiks'])) {
    $navnfiks = [];
    foreach ($LOP['navnefiks'] as $nr_n => $navn_n) { $navnfiks[(string) $nr_n] = (string) $navn_n; }
    foreach ($d['rader'] as &$r_n) {
        if (isset($navnfiks[(string) $r_n['nr']])) { $r_n['navn'] = $navnfiks[(string) $r_n['nr']]; }
    }
    unset($r_n);
}
if ($v === 'oppsett') {
    $ov = []; $kl = []; $klubb = [];
    foreach ($d['rader'] as $r) {
        $ov[$r['ovelse']] = ($ov[$r['ovelse']] ?? 0) + 1;
        if ($r['klasse'] !== '') { $kl[$r['klasse']] = ($kl[$r['klasse']] ?? 0) + 1; }
        if ($r['klubb'] !== '') { $klubb[$r['klubb']] = ($klubb[$r['klubb']] ?? 0) + 1; }
    }
    arsort($klubb); ksort($kl);
    echo json_encode(['lop' => $d['lop'], 'dato' => $d['dato'], 'sted' => $d['sted'],
        'hentet' => $d['hentet'], 'antall' => $d['antall'], 'ovelser' => $ov,
        'klasser' => $kl, 'klubber' => array_slice($klubb, 0, 60, true)], JSON_UNESCAPED_UNICODE);
    exit;
}
// B-127 (Odd 18.09.2026): Northern Runners har bedt om at løpsdataene ikke vises. Sidene
// står, tallene er skjult, og klubben kan be om å få dem åpnet via hei@treni.no.
if ($v === 'klubb' && mb_strtolower(trim((string) ($_GET['klubb'] ?? ''))) === 'northern runners') {
    echo json_encode(['skjult' => true, 'kontakt' => 'hei@treni.no',
                      'melding' => 'Løpsdataene på denne siden er skjult etter ønske fra klubben. Ta kontakt med hei@treni.no for å åpne dem.'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}
if ($v === 'klubb') {
    // F-81 del 2 (Odd 13.09): én klubb med kontekst, så klubbsidene slipper å
    // laste alle 1 897. Gir klubbens løpere + feltstørrelse og raskeste tid per
    // øvelse og kjønn, slik at plasseringene kan settes i sammenheng.
    $sok = mb_strtolower(trim((string) ($_GET['klubb'] ?? 'Northern Runners')));
    // Odd 13.09: klubbnavnet er stavet feil i påmeldingene («Nothern Runners»,
    // «Northens runners»). Vi normaliserer og godtar inntil to tegns avvik, men
    // aldri et annet klubbnavn: «Northern Trail Runners» ligger fem tegn unna og
    // faller utenfor, og et søk uten «trail» slipper aldri inn en trail-klubb.
    $norm = static function (string $x): string { return preg_replace('/[^a-z]/', '', mb_strtolower($x)) ?? ''; };
    $mal = $norm($sok);
    $sok_trail = str_contains($mal, 'trail');
    // Odd 13.09, del 2: «er det andre løpegrupper som ligner på navnet og løperne
    // er fra Norge, er det mest sannsynlig en del av gruppa». Derfor to porter:
    // stavefeil (inntil to tegn) godtas uansett land, mens et mer avvikende navn
    // bare godtas når løperen er norsk. «Northern Trail Runners» er finsk og faller
    // dermed ut, mens «Nothern Runners» og «Northens runners» kommer med.
    $stamme = substr($mal, 0, 8);
    $passer = static function (array $r) use ($norm, $mal, $stamme, $sok_trail): bool {
        $n = $norm((string) $r['klubb']);
        if ($n === '' || $mal === '') { return false; }
        if (str_contains($n, $mal) || levenshtein($n, $mal) <= 2) { return true; }
        if (($r['land'] ?? '') !== 'NO') { return false; }
        if ($stamme !== '' && levenshtein(substr($n, 0, strlen($stamme)), $stamme) > 2) { return false; }
        return levenshtein($n, $mal) <= 8;
    };
    // Odd 13.09: noen lar klubbfeltet stå tomt i påmeldingen. Klubben bekrefter
    // hvem det gjelder, og startnummeret føres i konfigurasjonen som «ekstra».
    // Aldri gjett: en løper legges bare til når klubben har sagt at hen hører til.
    $ekstra = [];
    foreach (($LOP['ekstra'][$sok] ?? $LOP['ekstra'][trim((string) ($_GET['klubb'] ?? ''))] ?? []) as $nr_e) {
        $ekstra[(string) $nr_e] = true;
    }
    $mine = []; $ctx = [];
    foreach ($d['rader'] as $r) {
        $n1 = $r['ovelse']; $n2k = $r['kjonn'];
        $c = &$ctx[$n1][$n2k];
        $c['felt'] = ($c['felt'] ?? 0) + 1;
        $sek = tid_sek($r['tid']);
        if ($sek < PHP_INT_MAX && (!isset($c['best_sek']) || $sek < $c['best_sek'])) {
            $c['best_sek'] = $sek; $c['best_tid'] = $r['tid']; $c['best_navn'] = $r['navn'];
        }
        unset($c);
        if (($sok !== '' && $passer($r)) || isset($ekstra[(string) $r['nr']])) {
            $r['sek'] = $sek;
            $mine[] = $r;
        }
    }
    // Odd 13.09: øvelser uten tidtaking (her Ruskareissu, 6,5 km powerwalk) finnes
    // ikke i resultatlista. Deltakerne føres manuelt i konfigurasjonen, med navn og
    // øvelse, og vises uten tid. Bekreftet av klubben, aldri gjettet.
    foreach (($LOP['manuelle'][$_GET['klubb'] ?? ''] ?? []) as $m) {
        $mine[] = ['nr' => '', 'id' => '', 'plass' => 0, 'plass_tekst' => '',
                   'navn' => (string) ($m['navn'] ?? ''), 'klasse' => (string) ($m['klasse'] ?? ''),
                   'land' => 'NO', 'klubb' => (string) ($_GET['klubb'] ?? ''),
                   'tid' => '', 'brutto' => '', 'ovelse' => (string) ($m['ovelse'] ?? ''),
                   'kjonn' => (string) ($m['kjonn'] ?? ''), 'klassegruppe' => '',
                   'uten_tid' => true, 'sek' => PHP_INT_MAX];
    }
    usort($mine, function ($a, $b) { return $a['sek'] <=> $b['sek']; });
    // Odd 13.09: «få fram at NR var den største løpegruppen på arrangementet».
    // Klubbene telles med samme normalisering, slik at stavefeil ikke splitter
    // en klubb i flere og gir feil rangering.
    $tell = [];
    foreach ($d['rader'] as $r2) {
        $k2 = trim((string) $r2['klubb']);
        if ($k2 === '') { continue; }
        $nk = $norm($k2);
        // Noen har fylt inn postnummer eller tall i klubbfeltet («96200» hadde 37
        // treff og ville toppet lista). Et klubbnavn må ha minst fire bokstaver.
        if (strlen($nk) < 4) { continue; }
        if (!isset($tell[$nk])) { $tell[$nk] = ['navn' => $k2, 'antall' => 0]; }
        $tell[$nk]['antall']++;
    }
    // slå sammen klubber som er samme navn med inntil to tegns avvik
    $samlet = [];
    foreach ($tell as $nk => $v) {
        $treff = null;
        foreach ($samlet as $sk => $sv) { if (levenshtein($nk, $sk) <= 2) { $treff = $sk; break; } }
        if ($treff !== null) {
            $samlet[$treff]['antall'] += $v['antall'];
            if ($v['antall'] > $tell[$treff]['antall']) { $samlet[$treff]['navn'] = $v['navn']; }
        } else {
            $samlet[$nk] = $v;
        }
    }
    // bekreftede ekstra-løpere skal telle med for klubben, ellers sier merket
    // «størst i løpet» ett tall og lista et annet
    if ($ekstra) {
        $nk_klubb = $norm((string) ($_GET['klubb'] ?? ''));
        $manuelle_n = count($LOP['manuelle'][$_GET['klubb'] ?? ''] ?? []);
        foreach ($samlet as $sk => $sv) {
            if (levenshtein($sk, $nk_klubb) <= 2) { $samlet[$sk]['antall'] += count($ekstra) + $manuelle_n; break; }
        }
    }
    usort($samlet, function ($a, $b) { return $b['antall'] <=> $a['antall']; });
    $topp = array_slice(array_values($samlet), 0, 5);
    $per = [];
    foreach ($mine as $r) { $per[$r['ovelse']] = ($per[$r['ovelse']] ?? 0) + 1; }
    echo json_encode(['lop' => $d['lop'], 'dato' => $d['dato'], 'sted' => $d['sted'],
        'hentet' => $d['hentet'], 'felt_totalt' => $d['antall'], 'klubb' => $_GET['klubb'] ?? 'Northern Runners',
        'antall' => count($mine), 'per_ovelse' => $per, 'kontekst' => $ctx,
        'topp_klubber' => $topp, 'rader' => $mine],
        JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode($d, JSON_UNESCAPED_UNICODE);
