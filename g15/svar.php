<?php
// Mottak av spørsmål/treningsinfo fra G15-sida. Lagres UTENFOR docroot.
// Personvern: kun det løperen selv skriver + tidspunkt. Ingen IP, ingen cookies.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /g15/'); exit; }
if (!empty($_POST['nettside'])) { header('Location: /g15/?takk=1'); exit; } // honningkrukke: bots fylles ut, mennesker ser den ikke
require_once dirname(__DIR__) . '/spamvern.php';
treni_begrens_post(['melding' => 2000, 'navn' => 60], 200);
$navn = treni_ren($_POST['navn'] ?? '', 60);
$melding = treni_ren($_POST['melding'] ?? '', 2000, true);
// Rategrense UTEN IP (personvernløftet over): maks 30 innsendinger per time totalt.
if ($melding !== '' && treni_rategrense('g15svar', 30, 3600)) {
    treni_sikkerhet_logg('g15/svar: samlet rategrense (30/t) nådd');
    http_response_code(429);
    header('Retry-After: 900');
    exit('For mange innsendinger akkurat nå. Prøv igjen om en liten stund.');
}
if ($melding !== '') {
    $rad = json_encode([
        'tid' => date('c'),
        'navn' => $navn,
        'melding' => $melding,
    ], JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents(__DIR__ . '/../../g15_svar.jsonl', $rad, FILE_APPEND | LOCK_EX);
}
header('Location: /g15/?takk=1');
