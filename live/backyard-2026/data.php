<?php
// F-77/F-50 (14.09.2026): generell live-datatjener for Treni Løpsdag, treni.no/live/<løp>/.
// Alt løpsspesifikt ligger i lop.json ved siden av fila (skrevet av veileder/lopsagent.py):
//   {"slug","navn","event","etapper":{uid:navn},"referanse":{"event":id,"etapper":{uid:navn}},
//    "type":"etapper"|"backyard","runde_km":6.7,"klubb":"Tromsø Løpeklubb","klubb_ekstra":{"klubb":[ed,...]}}
// Kilde: EQ Timings åpne JSON-API. Cache i fil (45 s passeringer, 15 min startliste/oppsett).
//   ?visning=oversikt&etappe=<uid>    → stasjoner m/ antall, leder-liste, siste passeringer, målgang
//   ?visning=resultater&etappe=<uid>  → full resultatliste (eller etappe=alle)
//   ?ed=<id,id>                       → passeringer for gitte EtappeDeltaker-id-er
//   ?q=<søk>  ?klubb=<navn>           → søk i startlista / klubbvisning
//   ?arr=<referanse-event>            → fjorårets løp, kun til test av visningen
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

$LOP = json_decode((string) @file_get_contents(__DIR__ . '/lop.json'), true) ?: [];
if (!$LOP || empty($LOP['event'])) { http_response_code(500); echo '{"feil":"lop.json mangler"}'; exit; }
$REF = $LOP['referanse'] ?? [];
$er_ref = isset($_GET['arr']) && !empty($REF['event']) && (string) $_GET['arr'] === (string) $REF['event'];
define('EVENT', $er_ref ? (int) $REF['event'] : (int) $LOP['event']);
$etapper_raa = $er_ref ? ($REF['etapper'] ?? []) : ($LOP['etapper'] ?? []);
$ETAPPER = [];
foreach ($etapper_raa as $k => $v) {
    if (is_array($v)) { $ETAPPER[(int) ($v['uid'] ?? 0)] = (string) ($v['navn'] ?? ''); }   // liste [{uid,navn}] fra lop.json
    else { $ETAPPER[(int) $k] = (string) $v; }                                           // {uid: navn}
}
define('ETAPPER', $ETAPPER);
define('SLUG', preg_replace('~[^a-z0-9_-]~', '', (string) ($LOP['slug'] ?? basename(__DIR__))));
define('BACKYARD', ($LOP['type'] ?? '') === 'backyard');
const EQ = 'https://live.eqtiming.com/api/';
$CACHE = sys_get_temp_dir() . '/treni_live_' . SLUG . '_' . EVENT . '_';

function hent(string $url): ?string {
    $ctx = stream_context_create(['http' => ['timeout' => 12, 'header' => "User-Agent: Mozilla/5.0 (treni.no live)\r\nAccept: application/json\r\n"]]);
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
    elseif (is_readable($f)) { $d = json_decode((string) file_get_contents($f), true); }   // EQ nede: server det gamle
    return $d;
}
function oppsett(): array {
    global $LOP;
    return cachet('event_' . count(ETAPPER), 900, function () use ($LOP) {
        $ev = json_decode((string) hent(EQ . 'Event/' . EVENT), true) ?: [];
        $ut = ['navn' => $ev['Navn'] ?? ($LOP['navn'] ?? SLUG), 'dato' => (string) ($ev['Dato'] ?? ''), 'sted' => (string) ($ev['Sted'] ?? ''), 'etapper' => []];
        foreach (ETAPPER as $uid => $navn) {
            $st = [];
            foreach ($ev['StasjonsOppsett'] ?? [] as $k => $s) {
                if ((int) ($s['EtappeUID'] ?? 0) === $uid) { $st[] = ['uid' => (int) $k, 'navn' => (string) $s['Navn'], 'km' => (float) ($s['Km'] ?? 0)]; }
            }
            usort($st, fn($a, $b) => $a['km'] <=> $b['km']);
            $ut['etapper'][$uid] = ['uid' => $uid, 'navn' => $navn, 'km' => (float) ($ev['Etapper'][$uid]['Km'] ?? 0), 'stasjoner' => $st];
        }
        return $ut;
    }) ?: ['navn' => $LOP['navn'] ?? SLUG, 'etapper' => []];
}
function deltakere(): array {
    // ed-uid → {navn, startnr, klasse, klubb, etappe, start}
    return cachet('deltakere_' . count(ETAPPER), 900, function () {
        $c = json_decode((string) hent(EQ . 'Contestants/' . EVENT), true) ?: [];
        $ut = [];
        foreach ($c as $d) {
            $u = $d['Utover'] ?? [];
            foreach ($d['EtappeDeltaker'] ?? [] as $eduid => $e) {
                $et = (int) ($e['Etappe']['UID'] ?? 0);
                if (!isset(ETAPPER[$et])) { continue; }
                $ut[(string) $eduid] = ['navn' => trim(($u['Fornavn'] ?? '') . ' ' . ($u['Etternavn'] ?? '')),
                    'startnr' => (int) ($d['Startnummer'] ?? 0), 'klasse' => (string) ($d['Klasse']['Navn'] ?? ''),
                    'klubb' => (string) ($d['Klubbnavn'] ?? $u['Klubbnavn'] ?? ''), 'etappe' => $et,
                    'start' => (string) ($e['StarttidFormatert'] ?? ''),
                    'status' => !empty($e['DNS']) ? 'DNS' : (!empty($e['DNF']) ? 'DNF' : (!empty($e['DSQ']) ? 'DSQ' : ''))];
            }
        }
        return $ut;
    }) ?: [];
}
function tidsek(int $ms): int { return intdiv($ms, 1000); }
function fmt(int $s): string { return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60); }
function er_post(array $s): bool {
    // en tellende post: har km, er ikke FV (støy hos EQ), ikke start-/kontrollpunkt uten km
    return $s['km'] > 0 && $s['navn'] !== 'FV' && !preg_match('/^(start|kontrollpunkt)/i', $s['navn']);
}
function passeringer(int $etappe, int $stasjon): array {
    // liste over passeringer på én stasjon (0 = mål/totalt), 45 s cache
    return cachet("pass_{$etappe}_{$stasjon}", 45, function () use ($etappe, $stasjon) {
        $url = EQ . "Result/Total/" . EVENT . "/{$etappe}?justTimeData=true&count=1500&startAt=1&query=&round=1&passes=false" . ($stasjon ? "&station={$stasjon}" : '');
        $raw = hent($url);
        if ($raw === null) { return null; }
        $d = json_decode($raw, true) ?: [];
        $ut = [];
        foreach ($d['Items'] ?? [] as $it) {
            $sek = tidsek((int) ($it['AkkumulertTid'] ?? 0));
            if ($sek <= 0) { continue; }   // brutt/ikke startet: ingen tid, ingen plass i lista
            if (!$stasjon && (string) ($it['StatusTekst'] ?? '') !== 'TIME') { continue; }   // målgang: bare fullførte (DNF har tid fra siste post)
            $ut[] = ['ed' => (string) ($it['EtappeDeltakerUID'] ?? ''), 'sek' => $sek, 'tid' => fmt($sek),
                'plass' => (int) ($it['Plassering']['Total'] ?? 0), 'plass_kl' => (int) ($it['Plassering']['Klasse'] ?? 0),
                'tempo' => (float) ($it['Splitt']['Tempo'] ?? 0), 'klokke' => substr((string) ($it['PasseringsTid'] ?? ''), 11, 8),
                'bak' => tidsek((int) ($it['Diff']['Total'] ?? 0)),
                'status' => (string) ($it['StatusTekst'] ?? '')];
        }
        return $ut;
    }) ?? [];
}
function kjede(int $etappe, array $etappe_opp): array {
    // EQ har brikkefeil (Skjervøy 12.09: målgang uten mellomposter). En passering teller bare
    // når løperen også har passert ALLE tidligere poster. [stasjons-uid => sett av gyldige ed], 0 => gyldige i mål.
    $ok = null; $ut = [];
    foreach ($etappe_opp['stasjoner'] ?? [] as $s) {
        if (!er_post($s)) { continue; }
        $her = [];
        foreach (passeringer($etappe, $s['uid']) as $r) {
            if ($ok === null || isset($ok[$r['ed']])) { $her[$r['ed']] = true; }
        }
        $ut[$s['uid']] = $her; $ok = $her;
    }
    $ut[0] = $ok ?? [];
    return $ut;
}
function med_navn(array $rad, array $delt): array {
    $d = $delt[$rad['ed']] ?? [];
    return $rad + ['navn' => $d['navn'] ?? '?', 'startnr' => $d['startnr'] ?? 0, 'klasse' => $d['klasse'] ?? '', 'klubb' => $d['klubb'] ?? ''];
}

// Bruksstatistikk (hvem følges, klubber, søk, filter, besøk). Ingen IP lagres, bare en daglig
// saltet hash for å telle unike. Samme fil som Treni Løpsdag-sidene (lopsdag_tall.py leser den).
if (isset($_GET['logg'])) {
    $h = substr(hash('sha256', date('Y-m-d') . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 12);
    $inn = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $rad = ['ts' => date('c'), 'bes' => $h, 'lop' => SLUG, 'hendelse' => mb_substr((string) $_GET['logg'], 0, 20),
            'mobil' => (bool) preg_match('/Mobi|Android|iPhone/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))];
    foreach (['ed', 'navn', 'klubb', 'klasse', 'q', 'antall', 'visning', 'etappe', 'klasser', 'ref', 'mål', 'kilde'] as $k) {
        if (isset($inn[$k])) { $rad[$k] = is_scalar($inn[$k]) ? mb_substr((string) $inn[$k], 0, 120) : $inn[$k]; }
    }
    @file_put_contents(dirname(__DIR__, 3) . '/lopsdag_logg.jsonl', json_encode($rad, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    echo '{"ok":true}'; exit;
}
$opp = oppsett(); $delt = deltakere();
$ut = ['hentet' => date('c'), 'lop' => $opp['navn'], 'slug' => SLUG, 'type' => BACKYARD ? 'backyard' : 'etapper', 'runde_km' => (float) ($LOP['runde_km'] ?? 0)];

if (isset($_GET['q'])) {
    $q = mb_strtolower(trim((string) $_GET['q']));
    $treff = [];
    if ($q !== '') {
        foreach ($delt as $ed => $d) {
            if (mb_strpos(mb_strtolower($d['navn']), $q) !== false || (ctype_digit($q) && (string) $d['startnr'] === $q)
                    || ($d['klubb'] !== '' && mb_strpos(mb_strtolower($d['klubb']), $q) !== false)) {
                $treff[] = ['ed' => $ed] + $d;
                if (count($treff) >= 40) { break; }
            }
        }
    }
    usort($treff, fn($a, $b) => strcmp($a['navn'], $b['navn']));
    $ut['treff'] = $treff;
    echo json_encode($ut, JSON_UNESCAPED_UNICODE); exit;
}

// Odd 14.09 («se deltakere»): hele startlista, sortert på navn, med klasse- og klubbtall
if (($_GET['visning'] ?? '') === 'deltakere') {
    $rader = []; $klasser = []; $klubber = [];
    foreach ($delt as $ed => $d) {
        $rader[] = ['ed' => (string) $ed, 'navn' => $d['navn'], 'startnr' => $d['startnr'], 'klasse' => $d['klasse'],
                    'klubb' => $d['klubb'], 'etappe' => $d['etappe'], 'etappe_navn' => ETAPPER[$d['etappe']] ?? '', 'status' => $d['status']];
        $klasser[$d['klasse']] = ($klasser[$d['klasse']] ?? 0) + 1;
        if ($d['klubb'] !== '') { $klubber[$d['klubb']] = ($klubber[$d['klubb']] ?? 0) + 1; }
    }
    usort($rader, fn($a, $b) => strcoll($a['navn'], $b['navn']));
    ksort($klasser, SORT_NATURAL); arsort($klubber);
    $ut += ['antall' => count($rader),
            'klasser' => array_map(fn($k, $n) => ['navn' => $k, 'antall' => $n], array_keys($klasser), $klasser),
            'klubber' => array_map(fn($k, $n) => ['navn' => $k, 'antall' => $n], array_keys($klubber), $klubber),
            'rader' => $rader];
    echo json_encode($ut, JSON_UNESCAPED_UNICODE); exit;
}

function resultater_raa(int $etappe): array {
    return cachet("res_{$etappe}", 45, function () use ($etappe) {
        $url = EQ . "Result/Total/" . EVENT . "/{$etappe}?justTimeData=true&count=1500&startAt=1&query=&round=1&passes=false";
        $raw = hent($url);
        if ($raw === null) { return null; }
        $ut = [];
        foreach ((json_decode($raw, true) ?: [])['Items'] ?? [] as $it) {
            $ut[(string) ($it['EtappeDeltakerUID'] ?? '')] = [
                'sek' => tidsek((int) ($it['AkkumulertTid'] ?? 0)),
                'plass' => (int) ($it['Plassering']['Total'] ?? 0), 'plass_kl' => (int) ($it['Plassering']['Klasse'] ?? 0),
                'plass_kj' => (int) ($it['Plassering']['Kjonn'] ?? 0),
                'bak' => tidsek((int) ($it['Diff']['Total'] ?? 0)), 'bak_kl' => tidsek((int) ($it['Diff']['Klasse'] ?? 0)),
                'tempo' => (float) ($it['Splitt']['TotalTempo'] ?? 0),
                'status' => (string) ($it['StatusTekst'] ?? '')];
        }
        return $ut;
    }) ?? [];
}
function resultat_etappe(int $etappe, array $opp, array $delt): array {
    $e = $opp['etapper'][$etappe] ?? ['navn' => ETAPPER[$etappe], 'km' => 0, 'stasjoner' => []];
    $kj = kjede($etappe, $e);
    $raa = resultater_raa($etappe);
    $poster = []; $mellom = [];
    foreach ($e['stasjoner'] as $s) {
        if (!er_post($s) || $s['km'] >= $e['km'] - 0.05) { continue; }   // mål er egen kolonne
        $poster[] = ['navn' => $s['navn'], 'km' => $s['km']];
        foreach (passeringer($etappe, $s['uid']) as $r) {
            if (isset($kj[$s['uid']][$r['ed']])) { $mellom[$r['ed']][] = ['tid' => $r['tid'], 'sek' => $r['sek'], 'plass' => $r['plass']]; }
            else { $mellom[$r['ed']][] = null; }
        }
        foreach ($delt as $ed => $d) { if ($d['etappe'] === $etappe && count($mellom[$ed] ?? []) < count($poster)) { $mellom[$ed][] = null; } }
    }
    $rader = []; $klasser = []; $klubber = [];
    foreach ($delt as $ed => $d) {
        if ($d['etappe'] !== $etappe) { continue; }
        $r = $raa[$ed] ?? [];
        $i_maal = !empty($r) && $r['status'] === 'TIME' && $r['sek'] > 0 && isset($kj[0][$ed]);
        $status = $i_maal ? 'TIME' : ($d['status'] ?: (($r['status'] ?? '') === 'TIME' ? 'DNF' : ($r['status'] ?? '')));
        $runder = count(array_filter($mellom[$ed] ?? [])) + ($i_maal ? 1 : 0);
        if ($status === '' && $runder > 0) { $status = 'UNDERVEIS'; }
        if ($status === '') { $status = 'DNS'; }
        $kjonn = preg_match('/kvinner|jenter|women|damer/i', $d['klasse']) ? 'K' : (preg_match('/menn|gutter|men|herrer/i', $d['klasse']) ? 'M' : '');
        $rader[] = ['ed' => (string) $ed, 'navn' => $d['navn'], 'startnr' => $d['startnr'], 'klasse' => $d['klasse'], 'klubb' => $d['klubb'], 'kjonn' => $kjonn,
            'status' => $status, 'sek' => $i_maal ? $r['sek'] : 0, 'tid' => $i_maal ? fmt($r['sek']) : '',
            'plass' => $i_maal ? $r['plass'] : 0, 'plass_kl' => $i_maal ? $r['plass_kl'] : 0, 'plass_kj' => $i_maal ? $r['plass_kj'] : 0,
            'bak' => $i_maal ? $r['bak'] : 0, 'bak_kl' => $i_maal ? $r['bak_kl'] : 0, 'tempo' => $i_maal ? $r['tempo'] : 0,
            'runder' => $runder,
            'mellom' => array_slice(array_pad($mellom[$ed] ?? [], count($poster), null), 0, count($poster))];
        $klasser[$d['klasse']] = ($klasser[$d['klasse']] ?? 0) + 1;
        if ($d['klubb'] !== '') { $klubber[$d['klubb']] = ($klubber[$d['klubb']] ?? 0) + 1; }
    }
    if (BACKYARD) {
        // Backyard: plassering = flest runder, deretter kortest tid på siste fullførte runde (siste passering)
        usort($rader, function ($a, $b) {
            if ($a['runder'] !== $b['runder']) { return $b['runder'] <=> $a['runder']; }
            $sa = end($a['mellom']) ?: null; $sb = end($b['mellom']) ?: null;
            $ta = $a['sek'] ?: (is_array($sa) ? $sa['sek'] : PHP_INT_MAX); $tb = $b['sek'] ?: (is_array($sb) ? $sb['sek'] : PHP_INT_MAX);
            return $ta <=> $tb ?: strcmp($a['navn'], $b['navn']);
        });
        $pl = 0; $pl_kl = [];
        foreach ($rader as $i => $r) {
            if ($r['runder'] <= 0) { continue; }
            $pl++; $pl_kl[$r['klasse']] = ($pl_kl[$r['klasse']] ?? 0) + 1;
            $rader[$i]['plass'] = $pl; $rader[$i]['plass_kl'] = $pl_kl[$r['klasse']];
        }
    } else {
        $gyldige = array_values(array_filter($rader, fn($r) => $r['status'] === 'TIME'));
        usort($gyldige, fn($a, $b) => $a['sek'] <=> $b['sek']);
        $pl = 0; $pl_kl = []; $pl_kj = []; $leder = $gyldige[0]['sek'] ?? 0; $leder_kl = [];
        $idx = []; foreach ($rader as $i => $r) { $idx[$r['ed']] = $i; }
        foreach ($gyldige as $g) {
            $pl++; $pl_kl[$g['klasse']] = ($pl_kl[$g['klasse']] ?? 0) + 1; $pl_kj[$g['kjonn']] = ($pl_kj[$g['kjonn']] ?? 0) + 1;
            $leder_kl[$g['klasse']] = $leder_kl[$g['klasse']] ?? $g['sek'];
            $i = $idx[$g['ed']];
            $rader[$i]['plass'] = $pl; $rader[$i]['plass_kl'] = $pl_kl[$g['klasse']]; $rader[$i]['plass_kj'] = $pl_kj[$g['kjonn']] ?? 0;
            $rader[$i]['bak'] = $g['sek'] - $leder; $rader[$i]['bak_kl'] = $g['sek'] - $leder_kl[$g['klasse']];
            $rader[$i]['tempo'] = $e['km'] > 0 ? round($g['sek'] / 60 / $e['km'], 3) : 0;
        }
        $rang = ['TIME' => 0, 'UNDERVEIS' => 1, 'DNF' => 2, 'DSQ' => 3, 'DNS' => 4];
        usort($rader, function ($a, $b) use ($rang) {
            if ($a['status'] !== $b['status']) { return ($rang[$a['status']] ?? 9) <=> ($rang[$b['status']] ?? 9); }
            if ($a['status'] === 'TIME') { return $a['sek'] <=> $b['sek']; }
            return strcmp($a['navn'], $b['navn']);
        });
    }
    ksort($klasser, SORT_NATURAL); ksort($klubber, SORT_NATURAL | SORT_FLAG_CASE);
    return ['etappe' => ['uid' => $etappe, 'navn' => $e['navn'], 'km' => $e['km'], 'paameldt' => count($rader),
                         'fullfort' => count(array_filter($rader, fn($r) => $r['status'] === 'TIME')), 'dato' => substr((string) ($opp['dato'] ?? ''), 0, 10)],
            'poster' => $poster,
            'klasser' => array_map(fn($k, $n) => ['navn' => $k, 'antall' => $n], array_keys($klasser), $klasser),
            'klubber' => array_map(fn($k, $n) => ['navn' => $k, 'antall' => $n], array_keys($klubber), $klubber),
            'rader' => $rader];
}
if (($_GET['visning'] ?? '') === 'resultater') {
    if (($_GET['etappe'] ?? '') === 'alle') {
        $rader = []; $klasser = []; $klubber = []; $poster = []; $etapper = [];
        foreach (ETAPPER as $uid => $navn) {
            $r = resultat_etappe($uid, $opp, $delt);
            $etapper[] = $r['etappe']; $poster[$uid] = $r['poster'];
            foreach ($r['rader'] as $x) { $x['etappe'] = $uid; $x['etappe_navn'] = $navn; $rader[] = $x; }
            foreach ($r['klasser'] as $k) { $klasser[$k['navn']] = ($klasser[$k['navn']] ?? 0) + $k['antall']; }
            foreach ($r['klubber'] as $k) { $klubber[$k['navn']] = ($klubber[$k['navn']] ?? 0) + $k['antall']; }
        }
        usort($rader, fn($a, $b) => strcmp($a['navn'], $b['navn']));
        ksort($klasser, SORT_NATURAL); ksort($klubber, SORT_NATURAL | SORT_FLAG_CASE);
        $ut += ['alle' => true, 'etapper' => $etapper, 'poster_per_etappe' => $poster,
                'etappe' => ['uid' => 'alle', 'navn' => 'alle løperne', 'km' => 0, 'paameldt' => count($rader),
                             'fullfort' => count(array_filter($rader, fn($r) => $r['status'] === 'TIME'))],
                'poster' => [],
                'klasser' => array_map(fn($k, $n) => ['navn' => $k, 'antall' => $n], array_keys($klasser), $klasser),
                'klubber' => array_map(fn($k, $n) => ['navn' => $k, 'antall' => $n], array_keys($klubber), $klubber),
                'rader' => $rader];
        echo json_encode($ut, JSON_UNESCAPED_UNICODE); exit;
    }
    $etappe = (int) ($_GET['etappe'] ?? array_key_first(ETAPPER));
    if (!isset(ETAPPER[$etappe])) { $etappe = array_key_first(ETAPPER); }
    $ut += resultat_etappe($etappe, $opp, $delt);
    echo json_encode($ut, JSON_UNESCAPED_UNICODE); exit;
}

function loper_status(string $ed, array $d, array $opp): ?array {
    $etappe = $opp['etapper'][$d['etappe']] ?? null;
    if (!$etappe) { return null; }
    $pass = [];
    $kj = kjede($d['etappe'], $etappe);
    foreach ($etappe['stasjoner'] as $s) {
        if (!er_post($s) || $s['navn'] === 'Mål') { continue; }   // mål kommer som egen rad
        if (!isset($kj[$s['uid']][$ed])) { continue; }
        foreach (passeringer($d['etappe'], $s['uid']) as $r) {
            if ($r['ed'] === $ed) { $pass[] = ['stasjon' => $s['navn'], 'km' => $s['km']] + $r; break; }
        }
    }
    $maal = null;
    if (isset($kj[0][$ed])) {
        foreach (passeringer($d['etappe'], 0) as $r) { if ($r['ed'] === $ed) { $maal = $r; break; } }
    }
    return ['ed' => $ed] + $d + ['etappe_navn' => $etappe['navn'], 'etappe_km' => $etappe['km'], 'passeringer' => $pass, 'maal' => $maal,
                                 'runder' => count($pass) + ($maal ? 1 : 0)];
}
if (isset($_GET['ed'])) {
    $ids = array_values(array_filter(array_map('trim', explode(',', (string) $_GET['ed'])), 'ctype_digit'));
    $ids = array_slice($ids, 0, 12);
    $ut['lopere'] = [];
    foreach ($ids as $ed) {
        $d = $delt[$ed] ?? null;
        if ($d && ($l = loper_status($ed, $d, $opp))) { $ut['lopere'][] = $l; }
    }
    echo json_encode($ut, JSON_UNESCAPED_UNICODE); exit;
}
if (isset($_GET['klubb'])) {
    $kq = mb_strtolower(trim((string) $_GET['klubb']));
    $ut['lopere'] = [];
    $EKSTRA = [];
    foreach ($LOP['klubb_ekstra'] ?? [] as $kn => $eds) { $EKSTRA[mb_strtolower((string) $kn)] = array_map('strval', (array) $eds); }
    if ($kq !== '') {
        foreach ($delt as $ed => $d) {
            $er_ekstra = in_array((string) $ed, $EKSTRA[$kq] ?? [], true);
            if ($er_ekstra || ($d['klubb'] !== '' && mb_strpos(mb_strtolower($d['klubb']), $kq) !== false)) {
                if ($l = loper_status((string) $ed, $d, $opp)) { $ut['lopere'][] = $l; }
                if (count($ut['lopere']) >= 60) { break; }
            }
        }
    }
    usort($ut['lopere'], function ($a, $b) {
        if ($a['etappe'] !== $b['etappe']) { return $a['etappe'] <=> $b['etappe']; }
        $ka = $a['maal'] ? 99 : count($a['passeringer']); $kb = $b['maal'] ? 99 : count($b['passeringer']);
        if ($ka !== $kb) { return $kb <=> $ka; }
        $ta = $a['maal']['sek'] ?? ($a['passeringer'] ? end($a['passeringer'])['sek'] : PHP_INT_MAX);
        $tb = $b['maal']['sek'] ?? ($b['passeringer'] ? end($b['passeringer'])['sek'] : PHP_INT_MAX);
        return $ta <=> $tb ?: strcmp($a['navn'], $b['navn']);
    });
    echo json_encode($ut, JSON_UNESCAPED_UNICODE); exit;
}

$etappe = (int) ($_GET['etappe'] ?? array_key_first(ETAPPER));
if (!isset(ETAPPER[$etappe])) { $etappe = array_key_first(ETAPPER); }
$e = $opp['etapper'][$etappe] ?? ['navn' => ETAPPER[$etappe], 'km' => 0, 'stasjoner' => []];
$stasjoner = []; $alle = []; $leder = []; $lederstasjon = null;
$paameldt = 0; $klasser = [];
$kj = kjede($etappe, $e);
foreach ($delt as $d) { if ($d['etappe'] === $etappe) { $paameldt++; $klasser[$d['klasse']] = ($klasser[$d['klasse']] ?? 0) + 1; } }
$valgte = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['klasser'] ?? ''))), fn($k) => $k !== '' && isset($klasser[$k])));
$i_valg = function (array $r) use ($valgte, $delt): bool { return !$valgte || in_array($delt[$r['ed']]['klasse'] ?? '', $valgte, true); };
foreach ($e['stasjoner'] as $s) {
    if (!er_post($s)) { continue; }
    $p = array_values(array_filter(passeringer($etappe, $s['uid']), fn($r) => $i_valg($r) && isset($kj[$s['uid']][$r['ed']])));
    $stasjoner[] = ['navn' => $s['navn'], 'km' => $s['km'], 'antall' => count($p)];
    foreach ($p as $r) { $alle[] = ['stasjon' => $s['navn'], 'km' => $s['km']] + $r; }
    if ($p) { $lederstasjon = $s; $leder = $p; }
}
$maal = array_values(array_filter(passeringer($etappe, 0), fn($r) => $i_valg($r) && isset($kj[0][$r['ed']])));
usort($alle, fn($a, $b) => strcmp($b['klokke'], $a['klokke']));
usort($leder, fn($a, $b) => $a['sek'] <=> $b['sek']);
usort($maal, fn($a, $b) => $a['sek'] <=> $b['sek']);
ksort($klasser, SORT_NATURAL);
$kl_ut = []; foreach ($klasser as $kn => $ka) { $kl_ut[] = ['navn' => $kn, 'antall' => $ka]; }
$ut += ['klasser' => $kl_ut, 'valgte' => $valgte];
if (BACKYARD) {
    // Backyard: hvem er igjen etter hver runde, og rundetid mot timen for lederne
    $igjen = []; $forrige = null;
    foreach ($stasjoner as $i => $s) { $igjen[] = ['runde' => $i + 1, 'navn' => $s['navn'], 'km' => $s['km'], 'igjen' => $s['antall']]; }
    $ut['backyard'] = ['runder_fullfort' => count(array_filter($stasjoner, fn($s) => $s['antall'] > 0)), 'igjen' => $igjen, 'runde_km' => (float) ($LOP['runde_km'] ?? 6.7)];
}
$ut += ['etappe' => ['uid' => $etappe, 'navn' => $e['navn'], 'km' => $e['km'], 'paameldt' => $valgte ? array_sum(array_map(fn($k) => $klasser[$k], $valgte)) : $paameldt,
                     'start' => (function () use ($delt, $etappe) { foreach ($delt as $d) { if ($d['etappe'] === $etappe && $d['start']) { return substr($d['start'], 0, 5); } } return ''; })()],
        'stasjoner' => $stasjoner,
        'leder' => ['stasjon' => $lederstasjon['navn'] ?? '', 'km' => $lederstasjon['km'] ?? 0, 'liste' => array_map(fn($r) => med_navn($r, $delt), array_slice($leder, 0, 15))],
        'maal' => ['antall' => count($maal), 'liste' => array_map(fn($r) => med_navn($r, $delt), array_slice($maal, 0, 20))],
        'siste' => array_map(fn($r) => med_navn($r, $delt), array_slice($alle, 0, 25))];
echo json_encode($ut, JSON_UNESCAPED_UNICODE);
