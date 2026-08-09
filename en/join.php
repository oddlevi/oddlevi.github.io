<?php
// Interest form (English) — same backend flow as bli-testloper.php: notifies the
// coach (email + Telegram) and adds the entry to the waitlist. Honeypot included.
$sendt = false;
$feil = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $navn = trim($_POST["navn"] ?? "");
    $epost = trim($_POST["epost"] ?? "");
    $om = trim($_POST["om"] ?? "");
    $kilde = mb_substr(trim($_POST["kilde"] ?? ""), 0, 200);
    $tg = trim($_POST["telegram"] ?? "");
    $sprak_valg = $_POST["sprak"] ?? "engelsk";
    $sprak = $sprak_valg === "annet"
        ? (trim($_POST["sprak_annet"] ?? "") ?: "annet")
        : (in_array($sprak_valg, ["norsk", "engelsk"], true) ? $sprak_valg : "engelsk");
    $krukke = trim($_POST["nettside"] ?? "");
    if ($krukke !== "") {
        $sendt = true; // bot — pretend all went well
    } elseif ($navn === "" || !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
        $feil = "Please fill in your name and a valid email address.";
    } else {
        $tekst = "Ny testløper-interesse fra treni.no/en (ENGELSK skjema)\n\n"
               . "Navn: " . $navn . "\n"
               . "E-post: " . $epost . "\n"
               . ($tg !== "" ? "Telegram: " . $tg . "\n" : "")
               . "Språk: " . $sprak . "\n\n"
               . "Inviter (send personlig): https://t.me/VeilederenAIBot?start="
               . ($sprak === "engelsk" ? "verve_odd_en" : "verve_odd") . "\n\n"
               . ($kilde !== "" ? "Tipset av: " . $kilde . "\n\n" : "")
               . "Om løpingen (engelsk):\n" . ($om !== "" ? $om : "(ikke utfylt)") . "\n";
        $hode = "From: Treni <hei@treni.no>\r\n"
              . "Reply-To: " . str_replace(["\r", "\n"], "", $epost) . "\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n";
        $epost_ok = mail("hei@treni.no",
                         "=?UTF-8?B?" . base64_encode("Testløper-interesse (EN): " . $navn) . "?=",
                         $tekst, $hode, "-fhei@treni.no");
        $cfg_sti = dirname(__DIR__, 2) . "/dashbord_config.php";
        $konfig = is_readable($cfg_sti) ? (include $cfg_sti) : null;
        if (is_array($konfig)) {
            try {
                $pdo = new PDO(
                    "mysql:host={$konfig['db_host']};dbname={$konfig['db_name']};charset=utf8mb4",
                    $konfig['db_user'], $konfig['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->prepare("INSERT INTO venteliste (navn, epost, telegram, om, sprak, kilde) VALUES (?,?,?,?,?,?)")
                    ->execute([$navn, $epost, $tg, $om, $sprak, $kilde !== "" ? $kilde : null]);
            } catch (Throwable $e) { /* notification below goes out regardless */ }
        }
        $tg_ok = false;
        if ($konfig !== null && defined("TRENI_BOT_TOKEN")) {
            $svar = @file_get_contents(
                "https://api.telegram.org/bot" . TRENI_BOT_TOKEN . "/sendMessage",
                false,
                stream_context_create(["http" => [
                    "method" => "POST",
                    "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
                    "content" => http_build_query([
                        "chat_id" => TRENI_TRENER_CHAT,
                        "text" => "🏃🇬🇧 " . $tekst]),
                    "timeout" => 8]]));
            $tg_ok = $svar !== false && strpos($svar, '"ok":true') !== false;
        }
        $sendt = $epost_ok || $tg_ok;
        if (!$sendt) {
            $feil = "Something went wrong on our end. Please email us directly instead.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Become a test runner — Treni</title>
<meta name="description" content="Register interest to become a Treni test runner — coach-led running guidance built on your own Strava data.">
<meta name="robots" content="noindex">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🏃</text></svg>">
<link rel="stylesheet" href="../stil.css?v=11">
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<nav class="sprakvalg" aria-label="Language"><a href="/bli-testloper.php">NO</a><span aria-current="page">EN</span></nav>
<main>

<header class="hero smal">
  <p class="kicker reveal"><a href="index.html" style="color:inherit">treni.no</a> · become a test runner</p>
  <h1 class="reveal" style="font-size:clamp(2rem,6vw,3rem)">Become a test runner</h1>
</header>

<?php if ($sendt): ?>
<section>
  <div class="kort">
    <h3 style="margin-top:0">Thank you<?php if (!empty($navn)) echo ", " . htmlspecialchars(explode(" ", $navn)[0]); ?>! 🏃</h3>
    <p>You're on the list. We take in a few test runners at a time, so the coach
    actually has time to see each one — when a spot opens up, you'll get a
    <b>personal invitation</b> from the coach with the Telegram link that starts
    your onboarding.</p>
    <p style="margin-bottom:0"><a href="index.html">← Back to the front page</a></p>
  </div>
</section>
<?php else: ?>
<section>
  <p>We take in a few test runners at a time, so the coach actually has time to see you.
  Tell us a bit about yourself, and we'll be in touch.</p>
  <?php if ($feil): ?><p class="skjema-feil"><?php echo htmlspecialchars($feil); ?></p><?php endif; ?>
  <form method="post" action="join.php" class="skjema">
    <label>Name
      <input type="text" name="navn" required autocomplete="name"
             value="<?php echo htmlspecialchars($_POST["navn"] ?? ""); ?>">
    </label>
    <label>Email
      <input type="email" name="epost" required autocomplete="email"
             value="<?php echo htmlspecialchars($_POST["epost"] ?? ""); ?>">
    </label>
    <label>Telegram <span class="valgfritt">(optional — username or the mobile number your Telegram account uses, so the invitation can go there)</span>
      <input type="text" name="telegram" autocomplete="tel" placeholder="@username or mobile number"
             value="<?php echo htmlspecialchars($_POST["telegram"] ?? ""); ?>">
    </label>
    <fieldset class="sprak-felt">
      <legend>Which language do you want your guidance in?</legend>
      <label class="radio"><input type="radio" name="sprak" value="engelsk" checked> English</label>
      <label class="radio"><input type="radio" name="sprak" value="norsk"> Norwegian</label>
      <label class="radio"><input type="radio" name="sprak" value="annet"> Other:
        <input type="text" name="sprak_annet" oninput="this.closest('fieldset').querySelector('input[value=annet]').checked = this.value.trim() !== ''" placeholder="write here" style="width:9rem"></label>
    </fieldset>
    <label>Who told you about Treni? <span class="valgfritt">(optional — a person, social media, your club …)</span>
      <input type="text" name="kilde" placeholder="e.g. a friend, Instagram, my running club"
             value="<?php echo htmlspecialchars($_POST["kilde"] ?? ""); ?>">
    </label>
    <label>A bit about your running <span class="valgfritt">(optional — e.g. how much you run, and what you want to achieve)</span>
      <textarea name="om" rows="4"><?php echo htmlspecialchars($_POST["om"] ?? ""); ?></textarea>
    </label>
    <label class="krukke" aria-hidden="true">Website
      <input type="text" name="nettside" tabindex="-1" autocomplete="off">
    </label>
    <button type="submit" class="btn btn-primar">Register interest</button>
  </form>
  <p class="liten" style="margin-top:1.5rem">Prefer email? Write directly to
  <a href="mailto:hei@treni.no?subject=I%20want%20to%20test%20Treni">hei@treni.no</a>.
  Your details are used only to reply to you —
  <a href="../personvern.html#en-t">read the privacy policy</a>.</p>
</section>
<?php endif; ?>

<footer>
  <nav aria-label="Footer">
    <a href="index.html">Home</a>
    <a href="../stotte.html#en-t">Support &amp; contact</a>
    <a href="../personvern.html#en-t">Privacy</a>
  </nav>
  <p>PAULSEN UTVIKLING · org no 938 158 614 · Norway ·
     <a href="mailto:hei@treni.no">hei@treni.no</a></p>
  <p>Powered by Strava — this service is not affiliated with or endorsed by Strava.</p>
</footer>

</main>
</body>
</html>
