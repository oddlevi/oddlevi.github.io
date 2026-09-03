<?php
declare(strict_types=1);
// Signert Strava-state for web-først-onboarding (bli-med.html), sikkerhetstest
// 03.09 funn 2: state = «wv_<verver>_<nonce>(_en).<utløp>.<hmac32>», signert
// med minside_secret (samme som strava_kode.php verifiserer mot). Utløp 15 min.
// Uten gyldig signatur lagrer min.treni.no/strava_kode.php aldri koden.
require_once __DIR__ . "/spamvern.php";
header("Content-Type: application/json");
header("Cache-Control: no-store");
if (treni_rategrense("stravastate|" . treni_ip(), 20, 3600)) {
    treni_sikkerhet_logg("strava_state: rategrense (20/t) nådd");
    http_response_code(429);
    header("Retry-After: 900");
    exit('{"feil":"for mange forsøk"}');
}
$cfg = @include dirname(__DIR__) . "/dashbord_config.php";
$secret = is_array($cfg) ? (string) ($cfg["minside_secret"] ?? "") : "";
if ($secret === "") {
    http_response_code(503);
    exit('{"feil":"ikke konfigurert"}');
}
$verver = strtolower(preg_replace('/[^a-z0-9_]/', '', (string) ($_GET["v"] ?? ""))) ?: "odd";
$verver = substr($verver, 0, 40);
$en = ($_GET["en"] ?? "") === "1";
$nonce = bin2hex(random_bytes(6));
$nyttelast = "wv_" . $verver . "_" . $nonce . ($en ? "_en" : "");
$utlop = time() + 15 * 60;
$sig = substr(hash_hmac("sha256", $nyttelast . "." . $utlop, $secret), 0, 32);
echo json_encode(["state" => $nyttelast . "." . $utlop . "." . $sig, "nonce" => $nonce]);
