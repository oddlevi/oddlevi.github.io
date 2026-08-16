<?php
// Mottak av spørsmål/treningsinfo fra G15-sida. Lagres UTENFOR docroot.
// Personvern: kun det løperen selv skriver + tidspunkt. Ingen IP, ingen cookies.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /g15/'); exit; }
if (!empty($_POST['nettside'])) { header('Location: /g15/?takk=1'); exit; } // honningkrukke: bots fylles ut, mennesker ser den ikke
$navn = mb_substr(trim($_POST['navn'] ?? ''), 0, 60);
$melding = mb_substr(trim($_POST['melding'] ?? ''), 0, 2000);
if ($melding !== '') {
    $rad = json_encode([
        'tid' => date('c'),
        'navn' => $navn,
        'melding' => $melding,
    ], JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents(__DIR__ . '/../../g15_svar.jsonl', $rad, FILE_APPEND | LOCK_EX);
}
header('Location: /g15/?takk=1');
