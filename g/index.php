<?php
// treni.no/g — knappene til App Store og Google Play går hit. Klikket logges, og besøket sendes videre
// til butikken med kanalen merket.
//
// Odd 30.10.2026 (plan for innhold i Treni 2.0, del 7): klikk per kanal er hovedtallet, butikkenes egne
// kampanjetall er kontroll. Kanalen kommer fra forsida (?fra=ig, ?fra=fb-<jobb>, ?fra=guide-01 …), ellers «web».
//
// ?til=ios|android  ?k=<kampanje>
date_default_timezone_set('Europe/Oslo');

$til = (($_GET['til'] ?? '') === 'android') ? 'android' : 'ios';
$k   = substr(preg_replace('/[^a-z0-9_.-]/', '', strtolower((string) ($_GET['k'] ?? ''))), 0, 40);
if ($k === '') {
    $k = 'web';
}

// Logg klikket. Fila ligger UTENFOR public_html, som ig_besok.jsonl. Ingen IP og ingen identifiserende
// data – bare når, hvilken butikk og hvilken kanal.
$logg = dirname(__DIR__, 2) . '/butikk_klikk.jsonl';
@file_put_contents($logg, json_encode([
    'ts'    => date('c'),
    'til'   => $til,
    'k'     => $k,
    'mobil' => (bool) preg_match('/Mobile|Android|iPhone/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

// Apples kampanjelenke med leverandørnøkkelen (pt) fra Trenis App Store Connect (App Analytics → Campaigns →
// «Generate a Campaign Link», hentet 30.10.2026). Apple viser en kampanje først ved fem nedlastinger.
const APPLE_PT = '129644944';

if ($til === 'android') {
    $url = 'https://play.google.com/store/apps/details?id=no.treni.app'
         . '&utm_source=treni.no&utm_medium=lenke&utm_campaign=' . rawurlencode($k);
} else {
    $url = 'https://apps.apple.com/app/apple-store/id6825074710?pt=' . APPLE_PT . '&ct=' . rawurlencode($k) . '&mt=8';
}

header('Cache-Control: no-store');
header('Location: ' . $url, true, 302);
