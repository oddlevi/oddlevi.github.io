<?php
// treni.no/fb — landingssiden for lenka i Facebook-innleggene. Speiler
// treni.no/ig; se den for hvorfor sidene finnes.
//
// Odd 05.09.2026, etter den første tallgjennomgangen: vi kunne se at et
// innlegg ga profilbesøk, men ikke om noen faktisk kom videre til oss.
// Halve trakta manglet. Denne sida er den manglende halvdelen — hvert
// besøk logges, og lenkene videre bærer med seg hvor besøket kom fra, slik
// at ventelistepåmeldinger kan spores tilbake til innlegget som utløste dem
// (bli-testloper.php leser «kilde» fra adressen og lagrer den i basen).
//
// ?p=<jobb-id> settes i bio-lenka når vi vil måle ett bestemt innlegg,
// f.eks. treni.no/ig?p=karJ_uke37.
date_default_timezone_set('Europe/Oslo');

$post = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($_GET['p'] ?? ''));
$post = mb_substr($post, 0, 40);

// Logg besøket. Fila ligger UTENFOR public_html, så den aldri kan hentes
// av andre. Vi lagrer ingen IP og ingen identifiserende data — bare når,
// hvorfra og hvilket innlegg, som er alt vi trenger for å telle.
$logg = dirname(__DIR__, 2) . '/fb_besok.jsonl';
@file_put_contents($logg, json_encode([
    'ts'       => date('c'),
    'post'     => $post,
    'henvist'  => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 120),
    'mobil'    => (bool) preg_match('/Mobile|Android|iPhone/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

// Fra 30.10.2026 (Odd): ingen egen side lenger – besøket sendes til forsida, der knappene til App Store og
// Google Play går via treni.no/g med kanalen merket (fb, eller fb-<jobb> når ?p= er satt). Den gamle
// Treni 1-sida («Bli testløper», løpskalender, løpegrupper) ligger i git-historikken.
$fra = 'fb' . ($post !== '' ? '-' . strtolower($post) : '');
header('Cache-Control: no-store');
header('Location: https://treni.no/?fra=' . rawurlencode($fra), true, 302);
