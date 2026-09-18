<?php
// F-49 (Odd 11.09.2026): live-data for Skjervøy 20:1000 2026, hentet fra EQ Timings
// åpne JSON-API (live.eqtiming.com/74108) og servert enkelt til treni.no/live/.
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
define('ETAPPER', EVENT === 74108 ? [306458 => '20:1000 konkurranse', 306460 => '10:500 konkurranse'] : (EVENT === 73977 ? [300955 => '20:1000 konkurranse', 300957 => '10:500 konkurranse'] : [290000 => '20:1000 konkurranse']));
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
    return cachet('event', 900, function () {
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
    return cachet('deltakere', 900, function () {
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
            $ut[] = ['ed' => (string) ($it['EtappeDeltakerUID'] ?? ''), 'sek' => $sek, 'tid' => fmt($sek),
                'plass' => (int) ($it['Plassering']['Total'] ?? 0), 'plass_kl' => (int) ($it['Plassering']['Klasse'] ?? 0),
                'tempo' => (float) ($it['Splitt']['Tempo'] ?? 0), 'klokke' => substr((string) ($it['PasseringsTid'] ?? ''), 11, 8),
                'status' => (string) ($it['StatusTekst'] ?? '')];
        }
        return $ut;
    }) ?? [];
}
function med_navn(array $rad, array $delt): array {
    $d = $delt[$rad['ed']] ?? [];
    return $rad + ['navn' => $d['navn'] ?? '?', 'startnr' => $d['startnr'] ?? 0, 'klasse' => $d['klasse'] ?? '', 'klubb' => $d['klubb'] ?? ''];
}

$opp = oppsett(); $delt = deltakere();
$ut = ['hentet' => date('c'), 'lop' => $opp['navn']];

if (isset($_GET['q'])) {
    $q = mb_strtolower(trim((string) $_GET['q']));
    $treff = [];
    if ($q !== '') {
        foreach ($delt as $ed => $d) {
            if (mb_strpos(mb_strtolower($d['navn']), $q) !== false || (ctype_digit($q) && (string) $d['startnr'] === $q)) {
                $treff[] = ['ed' => $ed] + $d;
                if (count($treff) >= 12) { break; }
            }
        }
    }
    $ut['treff'] = $treff;
    echo json_encode($ut, JSON_UNESCAPED_UNICODE); exit;
}

if (isset($_GET['ed'])) {
    $ids = array_values(array_filter(array_map('trim', explode(',', (string) $_GET['ed'])), 'ctype_digit'));
    $ids = array_slice($ids, 0, 12);
    $ut['lopere'] = [];
    foreach ($ids as $ed) {
        $d = $delt[$ed] ?? null;
        if (!$d) { continue; }
        $etappe = $opp['etapper'][$d['etappe']] ?? null;
        if (!$etappe) { continue; }
        $pass = [];
        foreach ($etappe['stasjoner'] as $s) {
            if ($s['km'] <= 0 || $s['navn'] === 'Mål' || $s['navn'] === 'FV') { continue; }   // mål kommer som egen rad, FV er støy
            foreach (passeringer($d['etappe'], $s['uid']) as $r) {
                if ($r['ed'] === $ed) { $pass[] = ['stasjon' => $s['navn'], 'km' => $s['km']] + $r; break; }
            }
        }
        $maal = null;
        foreach (passeringer($d['etappe'], 0) as $r) { if ($r['ed'] === $ed) { $maal = $r; break; } }
        $ut['lopere'][] = ['ed' => $ed] + $d + ['etappe_navn' => $etappe['navn'], 'etappe_km' => $etappe['km'], 'passeringer' => $pass, 'maal' => $maal];
    }
    echo json_encode($ut, JSON_UNESCAPED_UNICODE); exit;
}

$etappe = (int) ($_GET['etappe'] ?? array_key_first(ETAPPER));
if (!isset(ETAPPER[$etappe])) { $etappe = array_key_first(ETAPPER); }
$e = $opp['etapper'][$etappe] ?? ['navn' => ETAPPER[$etappe], 'km' => 0, 'stasjoner' => []];
$stasjoner = []; $alle = []; $leder = []; $lederstasjon = null;
$paameldt = 0; foreach ($delt as $d) { if ($d['etappe'] === $etappe) { $paameldt++; } }
foreach ($e['stasjoner'] as $s) {
    if ($s['km'] <= 0 || $s['navn'] === 'FV') { continue; }
    $p = passeringer($etappe, $s['uid']);
    $stasjoner[] = ['navn' => $s['navn'], 'km' => $s['km'], 'antall' => count($p)];
    foreach ($p as $r) { $alle[] = ['stasjon' => $s['navn'], 'km' => $s['km']] + $r; }
    if ($p) { $lederstasjon = $s; $leder = $p; }
}
$maal = passeringer($etappe, 0);
usort($alle, fn($a, $b) => strcmp($b['klokke'], $a['klokke']));
usort($leder, fn($a, $b) => $a['sek'] <=> $b['sek']);
usort($maal, fn($a, $b) => $a['sek'] <=> $b['sek']);
$ut += ['etappe' => ['uid' => $etappe, 'navn' => $e['navn'], 'km' => $e['km'], 'paameldt' => $paameldt,
                     'start' => (function () use ($delt, $etappe) { foreach ($delt as $d) { if ($d['etappe'] === $etappe && $d['start']) { return substr($d['start'], 0, 5); } } return ''; })()],
        'stasjoner' => $stasjoner,
        'leder' => ['stasjon' => $lederstasjon['navn'] ?? '', 'km' => $lederstasjon['km'] ?? 0, 'liste' => array_map(fn($r) => med_navn($r, $delt), array_slice($leder, 0, 15))],
        'maal' => ['antall' => count($maal), 'liste' => array_map(fn($r) => med_navn($r, $delt), array_slice($maal, 0, 20))],
        'siste' => array_map(fn($r) => med_navn($r, $delt), array_slice($alle, 0, 25))];
echo json_encode($ut, JSON_UNESCAPED_UNICODE);
