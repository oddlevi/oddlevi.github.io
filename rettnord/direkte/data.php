<?php
// treni.no/rettnord/direkte/data.php: direktedata for Rett Nords løp fra RaceResult (RaceTracker).
// F-216 (Odd 18.09.2026: «lage klart en liveside - følg løpet»). Første løp: Tour de Ørnes 19.09.2026.
//
// Leser config for eventet (nøkkel, server, konkurranser, lister), henter ALLE publiserte lister og
// tolker dem etter feltnavn, ikke faste kolonner, fordi RaceTracker setter opp listene forskjellig fra
// løp til løp (Kua Ultra 2023: per kjønn, 2025: per distanse, TDØ: ukjent til lørdag). Ingen lister ennå
// gir status «venter». Hurtigbuffer 40 sekunder, og forrige svar serveres om RaceResult er nede.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$LOP = [
    'tour-de-ornes-2026' => ['event' => '404481', 'navn' => 'Tour de Ørnes 2026', 'dato' => '2026-09-19',
                             'start' => '11:00', 'sted' => 'Ørnes', 'url' => 'https://racetracker.no/events/2026/tour-de-ornes/'],
    // kontrolløp for tolkeren: et ferdig løp med tre distanser (Kua Ultra 2025)
    'kua-ultra-2025' => ['event' => '320109', 'navn' => 'Kua Ultra 2025', 'dato' => '2025-06-07',
                         'start' => '08:00', 'sted' => 'Storvika', 'url' => 'https://racetracker.no/events/2025/kua-ultra/'],
    'kua-ultra-2026' => ['event' => '371743', 'navn' => 'Kua Ultra 2026', 'dato' => '2026-06-06',
                         'start' => '08:00', 'sted' => 'Storvika', 'url' => 'https://racetracker.no/events/2026/kua-ultra/'],
];
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['lop'] ?? 'tour-de-ornes-2026')));
if (!isset($LOP[$slug])) { http_response_code(404); exit(json_encode(['feil' => 'ukjent løp'])); }
$L = $LOP[$slug];
$EVENT = $L['event'];
$CACHE = sys_get_temp_dir() . '/treni_rn_direkte_' . $EVENT . '.json';
$TTL = 40;

// ?visning=deltakere: påmeldte fra tidtakerens deltakerliste (til «Min liste» på direktesiden, Odd 18.09)
if (($_GET['visning'] ?? '') === 'deltakere') {
    $DC = sys_get_temp_dir() . '/treni_rn_deltakere_' . $EVENT . '.json';
    if (is_readable($DC) && time() - filemtime($DC) < 600) { readfile($DC); exit; }
    $ref = 'https://my.raceresult.com/' . $EVENT . '/participants?lang=nb';
    $ut = ['lop' => $L['navn'], 'hentet' => date('c'), 'deltakere' => []];
    foreach (['participants/config?lang=nb&sanitize=true', 'RRPublish/data/config?page=participants&noVisitor=1'] as $cu) {
        $cfg = json_decode((string) hent('https://my.raceresult.com/' . $EVENT . '/' . $cu, $ref), true);
        if (!is_array($cfg) || empty($cfg['key'])) { continue; }
        $srv = $cfg['server'] ?? 'my.raceresult.com';
        $api = str_starts_with($cu, 'RRPublish') ? 'RRPublish/data' : 'participants';
        $lister = is_array($cfg['lists'] ?? null) ? $cfg['lists'] : ($cfg['Tab']['Config']['Lists'] ?? []);
        foreach ($lister as $l) {
            $navn = is_array($l) ? ($l['Name'] ?? '') : (string) $l;
            if ($navn === '') { continue; }
            $u = 'https://' . $srv . '/' . $EVENT . '/' . $api . '/list?' . http_build_query(['key' => $cfg['key'], 'listname' => $navn, 'page' => 'participants', 'contest' => '0', 'r' => 'all', 'l' => '0']);
            $d = json_decode((string) hent($u, $ref), true);
            if (!is_array($d) || empty($d['data']) || empty($d['list']['Fields'])) { continue; }
            $df = $d['DataFields'] ?? null;
            $labels = array_map(fn($f) => (string) ($f['Label'] ?: $f['Expression']), $d['list']['Fields']);
            $go = function ($x, $sti) use (&$go, &$ut, $df, $labels) {
                if (isset($x[0]) && is_array($x[0]) && !is_array($x[0][0] ?? null)) {
                    foreach ($x as $r) {
                        $rec = $df && count($df) === count($r) ? array_combine($df, $r) : array_combine($labels, array_slice($r, -count($labels)));
                        $n = felt($rec, ['FLNAME', 'DisplayNameBib', 'Navn', 'Navb', 'Name']);
                        if ($n === '') { $n = trim(felt($rec, ['FIRSTNAME']) . ' ' . felt($rec, ['LASTNAME'])); }
                        if ($n === '') { continue; }
                        $ut['deltakere'][] = ['navn' => preg_replace('/\s*\[\d+\]\s*$/', '', $n), 'klubb' => felt($rec, ['CLUB', 'Klubb', 'Klubb/Lag']),
                                              'fodt' => felt($rec, ['YEAR', 'F.år', 'Fødselsår']), 'kjonn' => felt($rec, ['GenderMF', 'Kjønn']),
                                              'gruppe' => preg_replace('/^#\d+_/', '', (string) ($sti[0] ?? ''))];
                    }
                    return;
                }
                foreach ($x as $k => $v) { if (is_array($v)) { $go($v, array_merge($sti, [(string) $k])); } }
            };
            $go($d['data'], []);
        }
        if ($ut['deltakere']) { break; }
    }
    usort($ut['deltakere'], fn($a, $b) => strcasecmp($a['navn'], $b['navn']));
    $ut['antall'] = count($ut['deltakere']);
    $json = json_encode($ut, JSON_UNESCAPED_UNICODE);
    if ($ut['antall'] > 0) { @file_put_contents($DC, $json, LOCK_EX); }
    echo $json; exit;
}

if (is_readable($CACHE) && time() - filemtime($CACHE) < $TTL) {
    readfile($CACHE); exit;
}

function hent(string $url, string $ref): ?string {
    $ctx = stream_context_create(['http' => ['timeout' => 20,
        'header' => "User-Agent: Mozilla/5.0 (treni.no direkte)\r\nAccept: application/json\r\nReferer: $ref\r\n"]]);
    $s = @file_get_contents($url, false, $ctx);
    return $s === false ? null : $s;
}
function tid_sek(string $t): int {
    if (!preg_match('/^\d/', $t)) { return PHP_INT_MAX; }
    $d = array_map('floatval', explode(':', str_replace(',', '.', $t)));
    $s = 0; foreach ($d as $x) { $s = $s * 60 + $x; }
    return (int) $s;
}
function felt(array $rec, array $navn): string {
    foreach ($navn as $n) {
        foreach ($rec as $k => $v) {
            if (strcasecmp((string) $k, $n) === 0 && is_scalar($v) && trim((string) $v) !== '') { return trim((string) $v); }
        }
    }
    return '';
}

// RaceResult har to grensesnitt: det gamle RRPublish (Kua 2023 til 2025) og det nye «results» (TDØ, Kua 2026).
// Prøv begge i rekkefølge, bruk det første som gir rader.
$ref = 'https://my.raceresult.com/' . $EVENT . '/results?lang=nb';
$ut = ['lop' => $L['navn'], 'dato' => $L['dato'], 'start' => $L['start'], 'sted' => $L['sted'], 'url' => $L['url'],
       'hentet' => date('c'), 'status' => 'venter', 'over' => false, 'konkurranser' => [], 'antall' => 0, 'api' => '', 'lister' => []];
$KILDER = [
    ['RRPublish/data', 'https://my.raceresult.com/' . $EVENT . '/RRPublish/data/config?page=results&noVisitor=1'],
    ['results', 'https://my.raceresult.com/' . $EVENT . '/results/config?lang=nb&sanitize=true'],
];
$rader = []; $startet = []; $noen_cfg = false;
foreach ($KILDER as [$API, $cfgurl]) {
    $cfg = json_decode((string) hent($cfgurl, $ref), true);
    if (!is_array($cfg) || empty($cfg['key'])) { continue; }
    $noen_cfg = true;
    $server = $cfg['server'] ?? 'my.raceresult.com';
    $ut['over'] = $ut['over'] || !empty($cfg['EventOver']);
    $kontester = $cfg['contests'] ?? [];
    $lister = is_array($cfg['lists'] ?? null) ? $cfg['lists'] : [];
    if (!$lister && is_array($cfg['Tab']['Config']['Lists'] ?? null)) { $lister = $cfg['Tab']['Config']['Lists']; }   // nytt grensesnitt (TDØ)
    if (!$lister) { continue; }
    $ut['api'] = $API;
    $ut['lister'] = array_values(array_map(fn($l) => is_array($l) ? ($l['Name'] ?? '') : (string) $l, $lister));
    foreach ($lister as $l) {
        $navn = is_array($l) ? ($l['Name'] ?? '') : (string) $l;
        $contest = is_array($l) ? (string) ($l['Contest'] ?? '0') : '0';
        if ($navn === '' || (is_array($l) && ($l['Mode'] ?? '') === 'hidden' && str_starts_with($navn, 'Result Lists'))) { continue; }
        $url = 'https://' . $server . '/' . $EVENT . '/' . $API . '/list?' . http_build_query([
            'key' => $cfg['key'], 'listname' => $navn, 'page' => 'results', 'contest' => $contest, 'r' => 'all', 'l' => '0']);
        $d = json_decode((string) hent($url, $ref), true);
        if (!is_array($d) || empty($d['data']) || empty($d['list']['Fields'])) { continue; }
        $df = $d['DataFields'] ?? null;
        $labels = array_map(fn($f) => (string) ($f['Label'] ?: $f['Expression']), $d['list']['Fields']);
        $flat = function ($x, array $sti) use (&$flat, &$rader, &$startet, $df, $labels, $kontester, $contest) {
            if (isset($x[0]) && is_array($x[0]) && !is_array($x[0][0] ?? null)) {
                foreach ($x as $r) {
                    if (!is_array($r)) { continue; }
                    $rec = $df && count($df) === count($r) ? array_combine($df, $r) : array_combine($labels, array_slice($r, -count($labels)));
                    $rec2 = $rec; foreach ($labels as $i => $lab) { $rec2[$lab] = $rec2[$lab] ?? ($r[count($r) - count($labels) + $i] ?? ''); }
                    $navnfelt = felt($rec2, ['DisplayNameBib', 'DisplayName', 'Navn', 'FLNAME', 'Name']);
                    if ($navnfelt === '') { $navnfelt = trim(felt($rec2, ['FIRSTNAME', 'Firstname']) . ' ' . felt($rec2, ['LASTNAME', 'Lastname'])); }
                    $navnfelt = preg_replace('/\s*\[\d+\]\s*$/', '', $navnfelt);
                    // 19.09.2026 (Tour de Ørnes, live): «Etternavn, Fornavn» → «Fornavn Etternavn»
                    if (preg_match('/^([^,]+),\s*(.+)$/u', $navnfelt, $mn)) { $navnfelt = trim($mn[2]) . ' ' . trim($mn[1]); }
                    $tid = felt($rec2, ['Finish.CHIP', 'Finish.TIME', 'Tid', 'Time', 'Finish']);
                    // 19.09.2026: live-lista gir tid per passering ({Selector}.Label = Start/…/Finish). Bare målgang teller som tid.
                    if ($tid === '' && isset($rec2['{Selector}.CHIP'])) {
                        $lab = (string) ($rec2['{Selector}.Label'] ?? '');
                        if (preg_match('/finish|mål|maal/i', $lab)) { $tid = (string) $rec2['{Selector}.CHIP']; }
                    }
                    if ($navnfelt === '') { continue; }
                    $kont = $kontester[$contest] ?? '';
                    // 19.09.2026: gruppenøklene er «#1_19km» (konkurranse) og «#2_Male» (kjønn). Sluttlista har
                    // bare kjønn, live-lista begge. Konkurransen er første gruppe som ikke er et kjønn.
                    if ($kont === '') {
                        foreach ($sti as $s0) {
                            if (preg_match('/^#\d+_(.+)$/', $s0, $m) && !preg_match('/^(Male|Female|Menn|Kvinner|Men|Women)$/i', $m[1])) { $kont = $m[1]; break; }
                        }
                    }
                    $kj = '';
                    foreach ($sti as $s) { if (preg_match('/Kvinner|Female|Women/i', $s)) { $kj = 'K'; } elseif (preg_match('/^(#\d+_)?(Menn|Male|Men)$/i', $s)) { $kj = 'M'; } }
                    if ($tid === '' && isset($rec2['{Selector}.Label']) && (string) $rec2['{Selector}.Label'] !== '') { $startet[$navnfelt] = true; }
                    $rader[] = ['navn' => $navnfelt, 'klubb' => felt($rec2, ['CLUB', 'Klubb', 'Klubb/Lag']), 'tid' => $tid,
                                'plass' => (int) rtrim(felt($rec2, ['WithStatus([AUTORANK.p])', 'WithStatus([AUTORANK.P])', 'Tot.', 'Plass', 'Rank']), '.'),
                                'kjonn' => $kj, 'klasse' => felt($rec2, ['Klasse', 'AGEGROUP.NAME']), 'fodt' => felt($rec2, ['YEAR', 'Fødselsår', 'Født']),
                                'kontest' => $kont ?: 'Løpet', 'ferdig' => preg_match('/^\d/', $tid) === 1, 'sek' => tid_sek($tid)];
                }
                return;
            }
            foreach ($x as $k => $v) { if (is_array($v)) { $flat($v, array_merge($sti, [(string) $k])); } }
        };
        $flat($d['data'], []);
    }
    if ($rader) { break; }
}
if (!$noen_cfg) {
    if (is_readable($CACHE)) { readfile($CACHE); exit; }
    $ut['status'] = 'nede'; echo json_encode($ut, JSON_UNESCAPED_UNICODE); exit;
}

// 19.09.2026: samme løper kan komme fra både sluttlista (uten konkurranse) og live-lista (med).
// Én rad per navn: den med kjent konkurranse vinner, ellers den første.
$kjent = array_values($kontester ?? []);
$valgt = [];
foreach ($rader as $r) {
    $nk = mb_strtolower($r['navn']);
    if (!isset($valgt[$nk]) || (!in_array($valgt[$nk]['kontest'], $kjent, true) && in_array($r['kontest'], $kjent, true))) { $valgt[$nk] = $r; }
}
$rader = array_values($valgt);
// gruppér per konkurranse, sorter på tid, regn plass selv når lista ikke gir den
$per = [];
foreach ($rader as $r) { if ($r['ferdig']) { $per[$r['kontest']][] = $r; } }
foreach ($per as $k => &$liste) {
    usort($liste, fn($a, $b) => $a['sek'] <=> $b['sek']);
    $n = 0; $sett = [];
    foreach ($liste as &$r) {
        $nk = mb_strtolower($r['navn']);
        if (isset($sett[$nk])) { $r['dupl'] = true; continue; }
        $sett[$nk] = true; $r['plass'] = ++$n; unset($r['sek']);
    }
    unset($r);
    $liste = array_values(array_filter($liste, fn($r) => empty($r['dupl'])));
}
unset($liste);
$ut['konkurranser'] = $per;
$ut['antall'] = array_sum(array_map('count', $per));
$ut['startet'] = count($startet ?? []);   // 19.09.2026: live-lista viser startpasseringer før noen er i mål
$ut['status'] = $ut['antall'] > 0 ? ($ut['over'] ? 'ferdig' : 'pågår') : ($ut['startet'] > 0 ? 'pågår' : 'venter');
$json = json_encode($ut, JSON_UNESCAPED_UNICODE);
@file_put_contents($CACHE, $json, LOCK_EX);
echo $json;
