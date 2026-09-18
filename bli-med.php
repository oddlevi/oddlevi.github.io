<?php
// bli-med.html var den gamle web-først-onboardingen (Strava-OAuth først, så
// Telegram). Fra B-57 (10.09.2026) er klokka steg 1 på min side, og all
// påmelding går via bli-testloper.php (norsk) og en/join.php (engelsk).
// Gamle vervelenker (?v=<løper-id>&en=1) sendes videre med ververen i
// «kilde»-feltet, så ververen krediteres som før (A-6, 11.09.2026).
$v = preg_replace('/[^a-z0-9_\-]/i', '', (string) ($_GET['v'] ?? ''));
$en = !empty($_GET['en']);
$q = $v !== '' ? '?kilde=' . rawurlencode('verv:' . $v) : '';
header('Location: https://treni.no/' . ($en ? 'en/join.php' : 'bli-testloper.php') . $q, true, 301);
exit;
