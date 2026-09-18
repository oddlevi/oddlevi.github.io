<?php
// Bruksstatistikk for treni.no/tromsokarusellen (Odd 17.09.2026: «full statistikk
// på sidene for å tracke verdien og markedsføringen»). Samme mønster som
// Skjervøy-siden: ingen IP lagres, bare en daglig saltet hash, så «unik» betyr
// unik per dag. Fila ligger UTENFOR public_html.
//
// Hendelser: besok, sok (uten søkeordet, bare lengde og treff), treff (noen fant
// seg selv), ut (klikk til treni.no), dybde (hvor langt ned på sida).
header('Content-Type: application/json; charset=utf-8');
$h = substr(hash('sha256', date('Y-m-d') . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')
                 . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 12);
$inn = json_decode((string) file_get_contents('php://input'), true) ?: [];
$ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$rad = ['ts' => date('c'), 'bes' => $h,
        'hendelse' => mb_substr((string) ($_GET['logg'] ?? 'besok'), 0, 20),
        'side' => mb_substr((string) ($inn['side'] ?? ''), 0, 20),
        'mobil' => (bool) preg_match('/Mobi|Android|iPhone/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
        'ref' => $ref ? parse_url($ref, PHP_URL_HOST) : ''];
// Søkeordet lagres ALDRI: det er navnet til et menneske. Bare lengden og om det ga treff.
foreach (['lengde', 'treff', 'dybde', 'mal', 'kilde'] as $k) {
    if (isset($inn[$k])) { $rad[$k] = is_scalar($inn[$k]) ? mb_substr((string) $inn[$k], 0, 60) : $inn[$k]; }
}
@file_put_contents(dirname(__DIR__, 2) . '/tk_logg.jsonl',
                   json_encode($rad, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
echo '{"ok":true}';
