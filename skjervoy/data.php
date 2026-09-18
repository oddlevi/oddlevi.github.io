<?php
// F-49 (Odd 11.09.2026): live-data for Skjervøy 20:1000 2026, hentet fra EQ Timings
// åpne JSON-API (live.eqtiming.com/74108) og servert enkelt til treni.no/skjervoy/.
// Cache i fil (45 s for passeringer, 15 min for startliste/oppsett), så hundre
// tilskuere gir like få kall mot EQ som én.
//   ?visning=oversikt&etappe=306458   → stasjoner m/ antall, leder-liste, siste passeringer, målgang
//   ?ed=15946831,16263679             → alle passeringer for gitte EtappeDeltaker-id-er
//   ?q=haug                           → søk i startlista (navn eller startnummer)
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

// ?arr=73977 gir fjorårets løp (kun til test av visningen)
define('EVENT', (isset($_GET['arr']) && in_array((string) $_GET['arr'], ['73977', '68530'], true)) ? (int) $_GET['arr'] : 74108);
define('ETAPPER', EVENT === 74108 ? [306458 => '20:1000 konkurranse', 306460 => '10:500 konkurranse', 306459 => '20:1000 trim', 306461 => '10:500 trim'] : (EVENT === 73977 ? [300955 => '20:1000 konkurranse', 300957 => '10:500 konkurranse'] : [290000 => '20:1000 konkurranse']));
const EQ = 'https://live.eqtiming.com/api/';
$CACHE = sys_get_temp_dir() . '/treni_skjervoy_' . EVENT . '_';

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
    return cachet('event_' . count(ETAPPER), 900, function () {
        $ev = json_decode((string) hent(EQ . 'Event/' . EVENT), true) ?: [];
        $ut = ['navn' => $ev['Navn'] ?? 'Skjervøy 20:1000', 'etapper' => []];
        $etapper = ETAPPER;
        if (EVENT === 68530) { $etapper = []; foreach ($ev['Etapper'] ?? [] as $k => $e) { if (str_contains((string) ($e['Navn'] ?? ''), 'konkurranse')) { $etapper[(int) $k] = (string) $e['Navn']; } } }
        foreach ($etapper as $uid => $navn) {
            $st = [];
            foreach ($ev['StasjonsOppsett'] ?? [] as $k => $s) {
                if ((int) ($s['EtappeUID'] ?? 0) === $uid) { $st[] = ['uid' => (int) $k, 'navn' => (string) $s['Navn'], 'km' => (float) ($s['Km'] ?? 0)]; }
            }
            usort($st, fn($a, $b) => $a['km'] <=> $b['km']);
            $ut['etapper'][$uid] = ['uid' => $uid, 'navn' => $navn, 'km' => (float) ($ev['Etapper'][$uid]['Km'] ?? 0), 'stasjoner' => $st];
        }
        return $ut;
    }) ?: ['navn' => 'Skjervøy 20:1000', 'etapper' => []];
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
                'bak' => tidsek((int) ($it['Diff']['Total'] ?? 0)),   // sekunder bak lederen ved denne posten (Odd 12.09)
                'status' => (string) ($it['StatusTekst'] ?? '')];
        }
        return $ut;
    }) ?? [];
}
function kjede(int $etappe, array $etappe_opp): array {
    // Odd 12.09: EQ har brikkefeil (målgang på 1:00 og 50 min i 20:1000 uten mellomposter).
    // En passering teller bare når løperen også har passert ALLE tidligere poster.
    // Returnerer [stasjons-uid => sett av gyldige ed-id], og 0 => gyldige i mål.
    $ok = null; $ut = [];
    foreach ($etappe_opp['stasjoner'] ?? [] as $s) {
        if ($s['km'] <= 0 || $s['navn'] === 'FV') { continue; }
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

// Odd 12.09: bruksstatistikk (hvem følges, klubber, søk, filter, besøk). Ingen IP lagres,
// bare en daglig saltet hash for å telle unike. Fila ligger utenfor public_html.
if (isset($_GET['logg'])) {
    $h = substr(hash('sha256', date('Y-m-d') . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 12);
    $inn = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $rad = ['ts' => date('c'), 'bes' => $h, 'hendelse' => mb_substr((string) $_GET['logg'], 0, 20),
            'mobil' => (bool) preg_match('/Mobi|Android|iPhone/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))];
    foreach (['ed', 'navn', 'klubb', 'klasse', 'q', 'antall', 'visning', 'etappe', 'klasser', 'ref', 'mål'] as $k) {
        if (isset($inn[$k])) { $rad[$k] = is_scalar($inn[$k]) ? mb_substr((string) $inn[$k], 0, 120) : $inn[$k]; }
    }
    @file_put_contents(dirname(__DIR__, 2) . '/skjervoy_logg.jsonl', json_encode($rad, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    echo '{"ok":true}'; exit;
}
$opp = oppsett(); $delt = deltakere();
$ut = ['hentet' => date('c'), 'lop' => $opp['navn']];

if (isset($_GET['q'])) {
    $q = mb_strtolower(trim((string) $_GET['q']));
    $treff = [];
    if ($q !== '') {
        foreach ($delt as $ed => $d) {
            // Odd 12.09: også klubb («Tromsø Løpeklubb» gir alle fra klubben, inntil 40)
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

// F-65 (Odd 13.09): full resultatliste, samme innhold som EQ Timings resultatvisning
//   ?visning=resultater&etappe=306458 → alle påmeldte i etappen med plass (totalt, klasse, kjønn),
//   tid, bak leder, tempo, status (TIME/DNF/DNS/DSQ) og mellomtid ved hver post.
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
    // Én etappe: alle påmeldte med status, plasser (regnet på gyldige målganger), mellomtider.
    $e = $opp['etapper'][$etappe] ?? ['navn' => ETAPPER[$etappe], 'km' => 0, 'stasjoner' => []];
    $kj = kjede($etappe, $e);
    $raa = resultater_raa($etappe);
    $poster = []; $mellom = [];
    foreach ($e['stasjoner'] as $s) {
        if ($s['km'] <= 0 || $s['navn'] === 'FV' || $s['km'] >= $e['km'] - 0.05) { continue; }   // mål er egen kolonne
        $poster[] = ['navn' => $s['navn'], 'km' => $s['km']];
        foreach (passeringer($etappe, $s['uid']) as $r) {
            if (isset($kj[$s['uid']][$r['ed']])) { $mellom[$r['ed']][] = ['tid' => $r['tid'], 'sek' => $r['sek'], 'plass' => $r['plass']]; }
            else { $mellom[$r['ed']][] = null; }
        }
        // løpere uten passering her får tomt felt, så kolonnene står rett
        foreach ($delt as $ed => $d) { if ($d['etappe'] === $etappe && count($mellom[$ed] ?? []) < count($poster)) { $mellom[$ed][] = null; } }
    }
    $rader = []; $klasser = []; $klubber = [];
    foreach ($delt as $ed => $d) {
        if ($d['etappe'] !== $etappe) { continue; }
        $r = $raa[$ed] ?? [];
        $i_maal = !empty($r) && $r['status'] === 'TIME' && $r['sek'] > 0 && isset($kj[0][$ed]);
        $status = $i_maal ? 'TIME' : ($d['status'] ?: (($r['status'] ?? '') === 'TIME' ? 'DNF' : ($r['status'] ?? '')));
        if ($status === '' && !empty($mellom[$ed]) && array_filter($mellom[$ed])) { $status = 'UNDERVEIS'; }
        if ($status === '') { $status = 'DNS'; }
        $kjonn = preg_match('/kvinner|jenter|women|damer/i', $d['klasse']) ? 'K' : (preg_match('/menn|gutter|men|herrer/i', $d['klasse']) ? 'M' : '');
        $rader[] = ['ed' => (string) $ed, 'navn' => $d['navn'], 'startnr' => $d['startnr'], 'klasse' => $d['klasse'], 'klubb' => $d['klubb'], 'kjonn' => $kjonn,
            'status' => $status, 'sek' => $i_maal ? $r['sek'] : 0, 'tid' => $i_maal ? fmt($r['sek']) : '',
            'plass' => $i_maal ? $r['plass'] : 0, 'plass_kl' => $i_maal ? $r['plass_kl'] : 0, 'plass_kj' => $i_maal ? $r['plass_kj'] : 0,
            'bak' => $i_maal ? $r['bak'] : 0, 'bak_kl' => $i_maal ? $r['bak_kl'] : 0, 'tempo' => $i_maal ? $r['tempo'] : 0,
            'mellom' => array_slice(array_pad($mellom[$ed] ?? [], count($poster), null), 0, count($poster))];
        $klasser[$d['klasse']] = ($klasser[$d['klasse']] ?? 0) + 1;
        if ($d['klubb'] !== '') { $klubber[$d['klubb']] = ($klubber[$d['klubb']] ?? 0) + 1; }
    }
    // EQ har brikkefeil (målgang uten mellomposter); plassene regnes derfor om på de gyldige målgangene
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
    ksort($klasser, SORT_NATURAL); ksort($klubber, SORT_NATURAL | SORT_FLAG_CASE);
    return ['etappe' => ['uid' => $etappe, 'navn' => $e['navn'], 'km' => $e['km'], 'paameldt' => count($rader),
                         'fullfort' => count($gyldige), 'dato' => substr((string) ($opp['dato'] ?? ''), 0, 10)],
            'poster' => $poster,
            'klasser' => array_map(fn($k, $n) => ['navn' => $k, 'antall' => $n], array_keys($klasser), $klasser),
            'klubber' => array_map(fn($k, $n) => ['navn' => $k, 'antall' => $n], array_keys($klubber), $klubber),
            'rader' => $rader];
}
if (($_GET['visning'] ?? '') === 'resultater') {
    if (($_GET['etappe'] ?? '') === 'alle') {
        // Odd 13.09: «et felt alle løpere så man kan søke på tvers av klassene»: alle etapper i én liste
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
        if ($s['km'] <= 0 || $s['navn'] === 'Mål' || $s['navn'] === 'FV') { continue; }   // mål kommer som egen rad, FV er støy
        if (!isset($kj[$s['uid']][$ed])) { continue; }
        foreach (passeringer($d['etappe'], $s['uid']) as $r) {
            if ($r['ed'] === $ed) { $pass[] = ['stasjon' => $s['navn'], 'km' => $s['km']] + $r; break; }
        }
    }
    $maal = null;
    if (isset($kj[0][$ed])) {
        foreach (passeringer($d['etappe'], 0) as $r) { if ($r['ed'] === $ed) { $maal = $r; break; } }
    }
    return ['ed' => $ed] + $d + ['etappe_navn' => $etappe['navn'], 'etappe_km' => $etappe['km'], 'passeringer' => $pass, 'maal' => $maal];
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
    // Odd 12.09: klubbvisning («Tromsø Løpeklubb» på lik linje med Trenis løpere)
    $kq = mb_strtolower(trim((string) $_GET['klubb']));
    $ut['lopere'] = [];
    // Odd 12.09: medlemmer som står uten klubb i EQ (Eirik H. er TLK-medlem, feltet er tomt)
    $EKSTRA = ['tromsø løpeklubb' => ['15946831']];
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
// Odd 12.09: klassefilter (flervalg), ?klasser=40-59 Menn,20-39 Kvinner
$valgte = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['klasser'] ?? ''))), fn($k) => $k !== '' && isset($klasser[$k])));
$i_valg = function (array $r) use ($valgte, $delt): bool { return !$valgte || in_array($delt[$r['ed']]['klasse'] ?? '', $valgte, true); };
foreach ($e['stasjoner'] as $s) {
    if ($s['km'] <= 0 || $s['navn'] === 'FV') { continue; }
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
$ut += ['etappe' => ['uid' => $etappe, 'navn' => $e['navn'], 'km' => $e['km'], 'paameldt' => $valgte ? array_sum(array_map(fn($k) => $klasser[$k], $valgte)) : $paameldt,
                     'start' => (function () use ($delt, $etappe) { foreach ($delt as $d) { if ($d['etappe'] === $etappe && $d['start']) { return substr($d['start'], 0, 5); } } return ''; })()],
        'stasjoner' => $stasjoner,
        'leder' => ['stasjon' => $lederstasjon['navn'] ?? '', 'km' => $lederstasjon['km'] ?? 0, 'liste' => array_map(fn($r) => med_navn($r, $delt), array_slice($leder, 0, 15))],
        'maal' => ['antall' => count($maal), 'liste' => array_map(fn($r) => med_navn($r, $delt), array_slice($maal, 0, 20))],
        'siste' => array_map(fn($r) => med_navn($r, $delt), array_slice($alle, 0, 25))];
echo json_encode($ut, JSON_UNESCAPED_UNICODE);
