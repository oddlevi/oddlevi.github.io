<?php
declare(strict_types=1);
// treni.no — all e-post fra PHP går via Gmail SMTP med hei@treni.no-kontoen
// (Odds beslutning 20.08.2026). Google signerer med treni.no-DKIM → innboks,
// ikke søppelpost — og kopien arkiveres automatisk i hei@ sin Sendt-mappe.
// PHP mail() rett fra webserveren brukes KUN som nødfallback (usignert).
// App-passordet ligger i ../epost_hei_apppassord.txt (utenfor docroot, 600).

function treni_epost_send(string $til, string $emne, string $kropp): bool
{
    $passordfil = __DIR__ . '/epost_hei_apppassord.txt';
    if (!is_readable($passordfil)) {
        return false;
    }
    $passord = trim((string) file_get_contents($passordfil));
    $konto = 'hei@treni.no';

    $s = @stream_socket_client(
        'ssl://smtp.gmail.com:465', $errno, $errstr, 20,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['SNI_enabled' => true]])
    );
    if ($s === false) {
        return false;
    }
    stream_set_timeout($s, 20);

    $les = function () use ($s): string {
        $svar = '';
        while (($linje = fgets($s, 1024)) !== false) {
            $svar .= $linje;
            // siste linje i et SMTP-svar har mellomrom etter koden (250 ), ikke bindestrek (250-)
            if (strlen($linje) < 4 || $linje[3] !== '-') {
                break;
            }
        }
        return $svar;
    };
    $si = function (string $cmd) use ($s, $les): string {
        fwrite($s, $cmd . "\r\n");
        return $les();
    };
    $ok = function (string $svar, string $kode): bool {
        return strncmp($svar, $kode, strlen($kode)) === 0;
    };

    try {
        if (!$ok($les(), '220')) return false;
        if (!$ok($si('EHLO treni.no'), '250')) return false;
        if (!$ok($si('AUTH LOGIN'), '334')) return false;
        if (!$ok($si(base64_encode($konto)), '334')) return false;
        if (!$ok($si(base64_encode($passord)), '235')) return false;
        if (!$ok($si("MAIL FROM:<{$konto}>"), '250')) return false;
        if (!$ok($si("RCPT TO:<{$til}>"), '250')) return false;
        if (!$ok($si('DATA'), '354')) return false;

        $emne_b64 = '=?UTF-8?B?' . base64_encode($emne) . '?=';
        $melding = "From: Treni <{$konto}>\r\n"
                 . "To: <{$til}>\r\n"
                 . "Subject: {$emne_b64}\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: base64\r\n"
                 . "\r\n"
                 . chunk_split(base64_encode($kropp));
        // punktum alene på linje avslutter DATA — kroppen er base64, så trygt
        fwrite($s, $melding . "\r\n.\r\n");
        if (!$ok($les(), '250')) return false;
        $si('QUIT');
        return true;
    } finally {
        fclose($s);
    }
}
