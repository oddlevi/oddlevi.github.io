<?php
// Delt spamvern for treni.no-skjemaene (bli-testloper, en/join, samarbeid).
// GJENOPPBYGD 28.08.2026 etter deploy-uhell — KILDEN ER DETTE REPOET; endringer
// gjøres aldri kun på serveren (rsync --delete visker dem ut).
// Lag: honningkrukke (i skjemaene) → tidsfelle → IP-brems → Cloudflare Turnstile.
// Turnstile-nøklene ligger UTENFOR docroot: domains/treni.no/turnstile_config.php.
// Prinsipp: fail-open — er Cloudflare nede, slipper mennesker gjennom; de andre
// lagene står uansett.

function treni_spamvern_ok(): string {
    // 1) Tidsfelle: skjema levert under 4 sekunder etter rendering = bot
    $t0 = (int) ($_POST["t0"] ?? 0);
    if ($t0 > 0 && time() - $t0 < 4) {
        return "Det gikk litt fort — prøv å sende inn en gang til.";
    }
    // 2) IP-brems: maks 3 innsendinger per 10 min per IP (bak Cloudflare-proxy
    //    er CF-Connecting-IP den ekte adressen)
    $ip = $_SERVER["HTTP_CF_CONNECTING_IP"] ?? ($_SERVER["REMOTE_ADDR"] ?? "");
    $dir = dirname(__DIR__) . "/skjema_ratelimit";
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $fil = $dir . "/" . md5($ip);
    $tider = is_readable($fil)
        ? array_map("intval", file($fil, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        : [];
    $tider = array_values(array_filter($tider, fn($t) => $t > time() - 600));
    if (count($tider) >= 3) {
        return "For mange forsøk på kort tid — vent noen minutter og prøv igjen.";
    }
    $tider[] = time();
    @file_put_contents($fil, implode("\n", $tider));
    // 3) Cloudflare Turnstile (usynlig menneskesjekk)
    $cfg = @include dirname(__DIR__) . "/turnstile_config.php";
    if (!is_array($cfg) || empty($cfg["secret"])) {
        return "";   // nøkler mangler (f.eks. lokalt) → de andre lagene får stå alene
    }
    $token = (string) ($_POST["cf-turnstile-response"] ?? "");
    if ($token === "") {
        // Klienten meldte at Turnstile-scriptet ikke lastet → fail-open
        return ($_POST["ts_nede"] ?? "") === "1" ? ""
             : "Sikkerhetssjekken mangler — last siden på nytt og prøv igjen.";
    }
    $svar = @file_get_contents(
        "https://challenges.cloudflare.com/turnstile/v0/siteverify", false,
        stream_context_create(["http" => [
            "method" => "POST",
            "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
            "content" => http_build_query([
                "secret" => $cfg["secret"], "response" => $token, "remoteip" => $ip]),
            "timeout" => 8]]));
    if ($svar === false) {
        return "";   // siteverify nede → fail-open
    }
    $d = json_decode($svar, true);
    return !empty($d["success"]) ? ""
         : "Sikkerhetssjekken godkjente ikke innsendingen — prøv igjen.";
}

/** Skjules i skjemaet: tidsfelle-stempel, nedetids-flagg og Turnstile-widgeten.
 *  MERK: script-URL-en MÅ ha /v0/ — uten den svarer Cloudflare 404 (lærdom 27.08). */
function treni_spamvern_felt(): void {
    $cfg = @include dirname(__DIR__) . "/turnstile_config.php";
    echo '<input type="hidden" name="t0" value="' . time() . '">' . "\n";
    echo '<input type="hidden" name="ts_nede" value="0" class="ts-nede">' . "\n";
    if (is_array($cfg) && !empty($cfg["sitekey"])) {
        echo '<div class="cf-turnstile" data-sitekey="'
           . htmlspecialchars($cfg["sitekey"], ENT_QUOTES) . '" data-theme="auto"></div>' . "\n";
        echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer '
           . 'onerror="document.querySelectorAll(\'.ts-nede\').forEach(function(e){e.value=\'1\'})">'
           . '</script>' . "\n";
    }
}
