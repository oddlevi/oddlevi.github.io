<?php
declare(strict_types=1);
// F-61 (Odd 13.09.2026): Instagram-webhook. Meta kaller denne i det en melding eller kommentar
// kommer til @treni.norge. Vi svarer NYE kontakter med ett førstesvar innen sekunder, logger alt i
// domains/treni.no/ig_innboks.jsonl og varsler Odd+Claude på Telegram. Vakta ig_dm_vakt.py er reserve.
$cfg = @include dirname(__DIR__) . "/ig_webhook_config.php";
if (!is_array($cfg)) { http_response_code(500); exit("mangler config"); }
if (($_GET["t"] ?? "") !== $cfg["url_token"]) { http_response_code(403); exit("nei"); }
$LOGG = dirname(__DIR__) . "/ig_innboks.jsonl";
$STATE = dirname(__DIR__) . "/ig_webhook_state.json";
$API = "https://graph.instagram.com/v23.0";

// 1) Verifisering (GET)
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    if (($_GET["hub_mode"] ?? "") === "subscribe" && ($_GET["hub_verify_token"] ?? "") === $cfg["verify_token"]) {
        header("Content-Type: text/plain"); exit((string) ($_GET["hub_challenge"] ?? ""));
    }
    http_response_code(403); exit("feil verify_token");
}

// 2) Hendelser (POST)
$raa = file_get_contents("php://input") ?: "";
$ev = json_decode($raa, true);
http_response_code(200); echo "ok";           // Meta skal ha 200 raskt, resten gjør vi etterpå
if (function_exists("fastcgi_finish_request")) { fastcgi_finish_request(); }
if (!is_array($ev)) { exit; }
// Rålogg av alt Meta sender (siste 200 rader), så vi ser format og tidspunkt selv om ingen regel treffer
$RAA = dirname(__DIR__) . "/ig_webhook_raa.jsonl";
@file_put_contents($RAA, json_encode(["t" => date("c"), "ev" => $ev], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
if (@filesize($RAA) > 400000) { $l = file($RAA); @file_put_contents($RAA, implode("", array_slice($l, -200)), LOCK_EX); }

function ig_get(string $sti, array $p, array $cfg): array {
    $p["access_token"] = $cfg["access_token"];
    $r = @file_get_contents($GLOBALS["API"] . "/" . $sti . "?" . http_build_query($p), false, stream_context_create(["http" => ["timeout" => 15]]));
    return json_decode((string) $r, true) ?: [];
}
function ig_post(string $sti, array $data, array $cfg): array {
    $data["access_token"] = $cfg["access_token"];
    $r = @file_get_contents($GLOBALS["API"] . "/" . $sti, false, stream_context_create(["http" => ["method" => "POST", "timeout" => 15,
        "header" => "Content-Type: application/json\r\n", "content" => json_encode($data)]]));
    return json_decode((string) $r, true) ?: ["raa" => (string) $r];
}
function telegram(string $tekst): void {
    $k = @include dirname(__DIR__) . "/dashbord_config.php";
    if (!defined("TRENI_BOT_TOKEN")) { return; }
    @file_get_contents("https://api.telegram.org/bot" . TRENI_BOT_TOKEN . "/sendMessage", false, stream_context_create(["http" => [
        "method" => "POST", "timeout" => 10, "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
        "content" => http_build_query(["chat_id" => "-5537179898", "text" => $tekst])]]));
}
function logg(array $rad): void {
    $rad["ts"] = date("c");
    @file_put_contents($GLOBALS["LOGG"], json_encode($rad, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
function klassifiser(string $t): string {
    $t = mb_strtolower($t);
    foreach (["arrang", "tidtak", "løpet vårt", "vårt løp", "live", "direkte", "stafett", "påmeld"] as $o) { if (str_contains($t, $o)) { return "arrangor"; } }
    foreach (["løpeplan", "plan", "trener", "testløper", "trene", "skade", "puls", "program", "coach", "veiled", "løper", "løpe", "jogg", "maraton", "halvmaraton", "5 km", "10 km", "mål", "form"] as $o) { if (str_contains($t, $o)) { return "loper"; } }
    return "annet";
}
$SVAR = [
  "arrangor" => "Hei, og takk for meldingen! Arrangerer du et løp, er dette Treni Direkte: treni.no/direkte. Skriv gjerne hvilket løp og når, så svarer Odd Levi deg her i løpet av dagen.",
  "loper" => "Hei, og takk for meldingen! Vil du ha løpeplan hver uke, bygget på øktene du faktisk løper, starter du her: treni.no/bli-testloper.php. Har du et spørsmål om treningen din, skriv det her, så svarer Odd Levi deg i løpet av dagen.",
  "annet" => "Hei, og takk for meldingen! Odd Levi svarer deg her i løpet av dagen. Arrangerer du et løp: treni.no/direkte. Vil du ha løpeplan: treni.no/bli-testloper.php.",
];
$st = json_decode((string) @file_get_contents($STATE), true) ?: ["svart" => [], "sett" => []];

foreach ((array) ($ev["entry"] ?? []) as $entry) {
    // Meldinger: ekte hendelser kommer i entry.messaging[], Metas testknapp i entry.changes[] med field=messages
    $meldinger = (array) ($entry["messaging"] ?? []);
    foreach ((array) ($entry["changes"] ?? []) as $c) {
        if (($c["field"] ?? "") === "messages" && is_array($c["value"] ?? null)) { $meldinger[] = $c["value"]; }
    }
    foreach ($meldinger as $m) {
        $msg = $m["message"] ?? null;
        if (!$msg || !empty($msg["is_echo"])) { continue; }          // våre egne meldinger kommer som ekko
        $mid = (string) ($msg["mid"] ?? "");
        if ($mid !== "" && isset($st["sett"][$mid])) { continue; }
        $fra = (string) ($m["sender"]["id"] ?? "");
        if (strlen($fra) < 10) { logg(["type" => "test", "fra" => $fra, "mid" => $mid]); continue; }  // Metas testdata, ikke ekte bruker
        $tekst = trim((string) ($msg["text"] ?? "")) ?: "(uten tekst: bilde, lyd eller vedlegg)";
        $st["sett"][$mid] = time();
        // brukernavn (én ekstra spørring, tåler å feile)
        $prof = $fra !== "" ? ig_get($fra, ["fields" => "username,name"], $cfg) : [];
        $bruker = (string) ($prof["username"] ?? $fra);
        logg(["type" => "melding", "fra" => $bruker, "igsid" => $fra, "mid" => $mid, "tekst" => $tekst]);
        // Førstesvar bare til NYE kontakter (vi har aldri skrevet i samtalen), ett per 7 dager
        $nylig = isset($st["svart"][$fra]) && time() - (int) $st["svart"][$fra] < 7 * 86400;
        $vi_har_skrevet = false;
        $conv = ig_get($cfg["ig_bruker_id"] . "/conversations", ["platform" => "instagram", "user_id" => $fra, "fields" => "messages.limit(8){from}"], $cfg);
        foreach ((array) ($conv["data"][0]["messages"]["data"] ?? []) as $mm) {
            if (($mm["from"]["username"] ?? "") === "treni.norge") { $vi_har_skrevet = true; break; }
        }
        $svar_tekst = "";
        // testkontoer får førstesvar selv om vi har skrevet før (Odd 13.09 «nullstill», for å teste webhooken)
        if (in_array($bruker, ["oddpau"], true)) { $vi_har_skrevet = false; }
        if (!$nylig && !$vi_har_skrevet && $fra !== "") {
            $kl = klassifiser($tekst); $svar_tekst = $SVAR[$kl];
            $r = ig_post($cfg["ig_bruker_id"] . "/messages", ["recipient" => ["id" => $fra], "message" => ["text" => $svar_tekst]], $cfg);
            $st["svart"][$fra] = time();
            logg(["type" => "forstesvar", "til" => $bruker, "klasse" => $kl, "svar" => $r]);
        }
        telegram("📩 Instagram-DM fra @{$bruker}:\n«" . mb_substr($tekst, 0, 500) . "»\n\n" . ($svar_tekst !== "" ? "🤖 Førstesvar sendt automatisk." : "Ingen automatikk (kjent samtale). Svar fra telefonen, eller be Claude: «svar @{$bruker}: …»"));
    }
    // Kommentarer
    foreach ((array) ($entry["changes"] ?? []) as $c) {
        if (($c["field"] ?? "") !== "comments") { continue; }
        $v = $c["value"] ?? [];
        $kid = (string) ($v["id"] ?? "");
        if ($kid === "" || isset($st["sett"]["k" . $kid])) { continue; }
        $st["sett"]["k" . $kid] = time();
        $bruker = (string) ($v["from"]["username"] ?? "?"); $tekst = (string) ($v["text"] ?? "");
        logg(["type" => "kommentar", "fra" => $bruker, "id" => $kid, "tekst" => $tekst, "media" => $v["media"]["id"] ?? ""]);
        telegram("💬 Instagram-kommentar fra @{$bruker}:\n«" . mb_substr($tekst, 0, 300) . "»");
    }
}
// rydd gamle sett-oppføringer (30 dager)
foreach ($st["sett"] as $k => $t) { if (time() - (int) $t > 30 * 86400) { unset($st["sett"][$k]); } }
@file_put_contents($STATE, json_encode($st), LOCK_EX);
