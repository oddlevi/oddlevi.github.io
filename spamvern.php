<?php
// Delt spamvern for treni.no-skjemaene (bli-testloper, en/join, samarbeid).
// GJENOPPBYGD 28.08.2026 etter deploy-uhell — KILDEN ER DETTE REPOET; endringer
// gjøres aldri kun på serveren (rsync --delete visker dem ut).
// Lag: honningkrukke (i skjemaene) → tidsfelle → IP-brems (3/10 min, 5/t, 429) →
// Cloudflare Turnstile (server-side siteverify). Feltlengder: treni_begrens_post().
// Turnstile-nøklene ligger UTENFOR docroot: domains/treni.no/turnstile_config.php.
// Prinsipp: fail-open — er Cloudflare nede, slipper mennesker gjennom; de andre
// lagene står uansett.

function treni_ip(): string {
    // Bak Cloudflare-proxy er CF-Connecting-IP den ekte adressen
    return $_SERVER["HTTP_CF_CONNECTING_IP"] ?? ($_SERVER["REMOTE_ADDR"] ?? "?");
}

/** Sikkerhetslogg utenfor docroot (domains/treni.no/sikkerhet.log): rategrenser,
 *  fail-open i Turnstile, avviste innsendinger. Aldri skjemainnhold. */
function treni_sikkerhet_logg(string $hendelse): void {
    $linje = gmdate("Y-m-d\TH:i:s\Z") . "\t" . treni_ip() . "\t" . basename($_SERVER["SCRIPT_NAME"] ?? "?")
           . "\t" . str_replace(["\r", "\n", "\t"], " ", $hendelse) . "\n";
    @file_put_contents(dirname(__DIR__) . "/sikkerhet.log", $linje, FILE_APPEND | LOCK_EX);
}

/** Filbasert rategrense (domains/treni.no/skjema_ratelimit/). true = grensen er
 *  nådd (kallet telles ikke); ellers telles kallet. */
function treni_rategrense(string $noekkel, int $maks, int $vindu_s): bool {
    $dir = dirname(__DIR__) . "/skjema_ratelimit";
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $fil = $dir . "/" . hash("sha256", $noekkel);
    $tider = is_readable($fil)
        ? array_map("intval", file($fil, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        : [];
    $tider = array_values(array_filter($tider, fn($t) => $t > time() - $vindu_s));
    if (count($tider) >= $maks) {
        return true;
    }
    $tider[] = time();
    @file_put_contents($fil, implode("\n", $tider), LOCK_EX);
    return false;
}

/** Renser skjematekst før den brukes i e-post/Telegram/DB (sikkerhetstest 03.09):
 *  HTML-tagger fjernes, kontrolltegn fjernes (linjeskift beholdes bare for
 *  flerlinjefelt), og lengden begrenses. */
function treni_ren(string $s, int $maks, bool $flerlinje = false): string {
    // Ikke strip_tags(): den spiser «<3 løping» og «a < b». Bare ekte
    // tagg-lignende sekvenser (<b>, </p>, <script …>, <!-- …) fjernes.
    $s = preg_replace('#</?[a-zA-Z!?][^>]{0,500}>#', '', $s) ?? $s;
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, "UTF-8");
    $s = $flerlinje
        ? preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', "", $s)
        : preg_replace('/[\x00-\x1F\x7F]/u', " ", $s);
    return trim(mb_substr((string) $s, 0, $maks));
}

/** Maks lengde på ALLE POST-felt (sikkerhetstest 03.09): ukjente felt kappes
 *  ved $std tegn, navngitte ved sin egen grense. Kjøres først i hver handler. */
function treni_begrens_post(array $grenser = [], int $std = 1000): void {
    foreach ($_POST as $k => $v) {
        if (!is_string($v)) {
            unset($_POST[$k]);   // ingen arrays/objekter fra skjemaene våre
            continue;
        }
        $_POST[$k] = mb_substr($v, 0, $grenser[$k] ?? $std);
    }
}

/** Rategrense per IP for skjemaene: maks 3 per 10 min OG maks 5 per time.
 *  Over grensen: 429 + Retry-After (siden rendres med feilmelding). */
function treni_skjema_rategrense(): string {
    $ip = treni_ip();
    if (treni_rategrense("skjema10|" . $ip, 3, 600) || treni_rategrense("skjema60|" . $ip, 5, 3600)) {
        treni_sikkerhet_logg("skjema: rategrense nådd (3/10 min eller 5/t)");
        http_response_code(429);
        header("Retry-After: 900");
        return "For mange forsøk på kort tid — vent noen minutter og prøv igjen.";
    }
    return "";
}

function treni_spamvern_ok(): string {
    // 1) Tidsfelle: skjema levert under 4 sekunder etter rendering = bot
    $t0 = (int) ($_POST["t0"] ?? 0);
    if ($t0 > 0 && time() - $t0 < 4) {
        treni_sikkerhet_logg("skjema: tidsfelle (< 4 s)");
        return "Det gikk litt fort — prøv å sende inn en gang til.";
    }
    // 2) IP-brems: 3 per 10 min og 5 per time (429 over grensen)
    if (($rg = treni_skjema_rategrense()) !== "") {
        return $rg;
    }
    $ip = treni_ip();
    // 3) Cloudflare Turnstile (usynlig menneskesjekk), server-side siteverify
    $cfg = @include dirname(__DIR__) . "/turnstile_config.php";
    if (!is_array($cfg) || empty($cfg["secret"])) {
        treni_sikkerhet_logg("skjema: turnstile_config mangler — kun honningkrukke/tidsfelle/rategrense");
        return "";   // nøkler mangler (f.eks. lokalt) → de andre lagene får stå alene
    }
    $token = (string) ($_POST["cf-turnstile-response"] ?? "");
    if ($token === "") {
        // Klienten meldte at Turnstile-scriptet ikke lastet. Fail-open, men
        // strammere (sikkerhetstest 03.09, funn 8): flagget er klientstyrt, så
        // maks 2 slike innsendinger per IP per time, og hver logges.
        if (($_POST["ts_nede"] ?? "") === "1") {
            if (treni_rategrense("tsnede|" . $ip, 2, 3600)) {
                treni_sikkerhet_logg("skjema: ts_nede-flagg over grensen (2/t) — avvist");
                http_response_code(429);
                header("Retry-After: 1800");
                return "Sikkerhetssjekken er utilgjengelig akkurat nå — prøv igjen om en liten stund.";
            }
            treni_sikkerhet_logg("skjema: Turnstile fail-open (ts_nede=1)");
            return "";
        }
        return "Sikkerhetssjekken mangler — last siden på nytt og prøv igjen.";
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
        treni_sikkerhet_logg("skjema: siteverify utilgjengelig — fail-open");
        return "";   // siteverify nede → fail-open
    }
    $d = json_decode($svar, true);
    if (empty($d["success"])) {
        treni_sikkerhet_logg("skjema: Turnstile avviste token (" . implode(",", (array) ($d["error-codes"] ?? [])) . ")");
        return "Sikkerhetssjekken godkjente ikke innsendingen — prøv igjen.";
    }
    return "";
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
