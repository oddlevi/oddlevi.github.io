<?php
$er_coach = (($_POST['rolle'] ?? $_GET['rolle'] ?? '') === 'coach');
// Odd 07.09 (Lola): samme flyt som det norske skjemaet: personlig kode,
// velkomst-e-post på engelsk og redirect rett til min side (B-57: klokka er
// steg 1). bli-testloper.php gjør behandlingen og returnerer hit for HTML.
$TRENI_EN = true;
$sendt = false; $feil = "";
require dirname(__DIR__) . "/bli-testloper.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $er_coach ? 'Join as a running coach. Treni' : 'Get a running plan every week, reviewed by a coach. Treni' ?></title>
<meta name="description" content="Register interest to become a Treni test runner, coach-led running guidance built on the runs from your watch (Strava or Intervals.icu).">
<meta name="robots" content="noindex">
<meta property="og:title" content="Become a Treni test runner">
<meta property="og:description" content="Coach-led running guidance built on the runs from your watch. Register interest and you get your own page right away, and your plan once your history is in.">
<meta property="og:type" content="website">
<meta property="og:url" content="https://treni.no/en/join.php">
<meta property="og:image" content="https://treni.no/bilder/og.jpg">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" type="image/png" href="/icon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="../stil.css?v=30">
</head>
<body>
<div class="bakteppe" aria-hidden="true"></div>
<?php
// Revisjon 11.09 (D-151): målløpet fra løpskalenderen (lop/dato/til/s) må følge
// med når løperen bytter språk, ellers mister hun løpet ved bytte til norsk.
$ml_qs = $maal_lop ? http_build_query(["lop" => $maal_lop['navn'], "dato" => $maal_lop['dato'],
                                       "til" => $maal_lop['pamelding'], "s" => $ml_sig]) : "";
$no_qs = implode("&", array_filter([$er_coach ? "rolle=coach" : "", $ml_qs]));
?>
<nav class="sprakvalg" aria-label="Language"><a href="/bli-testloper.php<?= $no_qs ? '?' . htmlspecialchars($no_qs) : '' ?>">NO</a><span aria-current="page">EN</span></nav>
<main>

<header class="hero smal">
  <p class="kicker reveal"><a href="index.html" style="color:inherit">treni.no</a> · <?= $er_coach ? 'for running coaches' : 'become a test runner' ?></p>
  <h1 class="reveal" style="font-size:clamp(2rem,6vw,3rem)"><?= $er_coach ? 'Join as a running coach' : 'Get a running plan every week, reviewed by a coach' ?></h1>
  <?php if (!$er_coach): ?>
  <p class="reveal" style="font-size:1.15rem;line-height:1.5;max-width:38rem;margin:.6rem 0 0"><b>Treni is an online running coach.</b> We read every run you do, tell you whether the week was easy enough, and build next week's plan on what you actually did. A real coach reviews the plan and answers you. Free during the test period.</p>
  <?php endif; ?>
</header>

<?php if ($sendt): ?>
<section>
  <div class="kort">
    <h3 style="margin-top:0">Thank you<?php if (!empty($navn)) echo ", " . htmlspecialchars(explode(" ", $navn)[0]); ?>! 🏃</h3>
    <?php if ($er_coach): ?>
    <p>We have your message. Treni for coaches is rolled out one coach at a time, so we will
    email you about a coach account, how your athletes connect, and what it costs.</p>
    <?php else: ?>
    <p>You're in. Step 1 of 4 is creating an Intervals.icu account and connecting Treni, and your page takes you through it one step at a time.
    Then you answer the questions, and then we build your plan on what you have actually run.
    (The same link has been sent to your email.)</p>
    <?php if (isset($vl_kode)): ?>
    <p style="margin:1.2rem 0"><a href="https://min.treni.no/?t=<?= htmlspecialchars($vl_kode) ?>&amp;lang=en"
       style="display:inline-block; background:hsl(152 62% 20%); color:#fff;
       padding:.85rem 1.6rem; border-radius:12px; font-weight:700; font-size:1.05rem;
       text-decoration:none; box-shadow:0 2px 8px hsl(152 62% 20% / .3)">To your page, connect your watch →</a></p>
    <?php endif; ?>
    <p>Garmin, Polar, Suunto, Coros and Wahoo connect via Intervals.icu, which is free. It takes a few
    minutes. Your plan arrives once your answers and your history are in, and your coach follows up from there.</p>
    <?php endif; ?>
    <p style="margin-bottom:0"><a href="index.html">← Back to the front page</a></p>
  </div>
</section>
<?php else: ?>
<section>
  <?php if ($er_coach): ?>
  <p>Treni does the work between sessions for you as a coach: it reads every run your athletes
  log on their watch (Strava or Intervals.icu), writes the weekly plan by the methodology, and you
  approve, adjust and send it in your own name. Your athletes get their own page, you get the overview. We are taking on a
  few coaches this autumn. Tell us briefly about you and your athletes, and we'll be in touch.</p>
  <?php else: ?>
  <p>Most runners go too hard on their easy days. That is the most common reason progress stalls,
  and the most common reason injuries turn up.</p>
  <p><b>What you get:</b> a plan that is yours, a straight answer on whether you ran easy enough or
  too hard, a coach who replies, and a plan that survives real life.</p>
  <p>No waitlist. You get your own page right away, and your plan as soon as your watch is connected
  and your history is in. Tell us a bit about yourself, and you are up and running in five minutes.
  Are you a running coach with your own athletes? Choose that below.</p>
  <?php endif; ?>
  <?php if ($feil): ?><p class="skjema-feil"><?php echo htmlspecialchars($feil); ?></p><?php endif; ?>
  <?php if ($maal_lop): ?>
  <div style="border:2px solid hsl(var(--primary) / .45); border-radius:var(--radius);
       padding:.8rem 1.1rem; margin:0 0 1.1rem; background:hsl(var(--primary) / .06)">
    🏁 <b>You want to train towards: <?= htmlspecialchars($maal_lop['navn']) ?></b><?=
      $maal_lop['dato'] ? ' · ' . htmlspecialchars(substr($maal_lop['dato'], 8, 2) . '.' . substr($maal_lop['dato'], 5, 2)) : '' ?>
    <span class="liten" style="display:block; margin-top:.2rem">Your plan is built towards race day, and
    the organiser's registration link is waiting on your page.</span>
  </div>
  <?php endif; ?>
  <form method="post" action="join.php" class="skjema"
        data-kladd="skjema1" data-en="1"
        data-kladd-ferdig="<?= $sendt ? '1' : '0' ?>"
        onsubmit="var b=this.querySelector('button[type=submit]');if(b.disabled){return false;}b.disabled=true;b.textContent='Sending …';">
    <?php if ($maal_lop): // Revisjon 11.09 (D-151): same hidden race fields as bli-testloper.php ?>
    <input type="hidden" name="lop" value="<?= htmlspecialchars($maal_lop['navn']) ?>">
    <input type="hidden" name="dato" value="<?= htmlspecialchars($maal_lop['dato']) ?>">
    <input type="hidden" name="til" value="<?= htmlspecialchars($maal_lop['pamelding']) ?>">
    <input type="hidden" name="s" value="<?= htmlspecialchars($ml_sig) ?>">
    <?php endif; ?>
    <fieldset class="sprak-felt" style="margin-bottom:.9rem">
      <legend>I am signing up as</legend>
      <label class="radio"><input type="radio" name="rolle" value="loper" <?= $er_coach ? '' : 'checked' ?>> A runner</label>
      <label class="radio"><input type="radio" name="rolle" value="coach" <?= $er_coach ? 'checked' : '' ?>> A running coach with my own athletes</label>
    </fieldset>
    <label>Name
      <input type="text" name="navn" required autocomplete="name"
             value="<?php echo htmlspecialchars($_POST["navn"] ?? ""); ?>">
    </label>
    <label>Email
      <input type="email" name="epost" required autocomplete="email"
             value="<?php echo htmlspecialchars($_POST["epost"] ?? ""); ?>">
    </label>
    <label>Mobile <span class="valgfritt">(optional, so we can reach you if something gets stuck)</span>
      <input type="tel" name="mobil" autocomplete="tel" placeholder="e.g. +47 900 00 000"
             value="<?php echo htmlspecialchars($_POST["mobil"] ?? ""); ?>">
    </label>
    <?php // Telegram field removed 13.09.2026 (Odd): all runner communication happens in the chat on My page. ?>
    <fieldset class="sprak-felt">
      <legend>Which language do you want your guidance in?</legend>
      <label class="radio"><input type="radio" name="sprak" value="engelsk" checked> English</label>
      <label class="radio"><input type="radio" name="sprak" value="norsk"> Norwegian</label>
      <label class="radio"><input type="radio" name="sprak" value="annet"> Other:
        <input type="text" name="sprak_annet" oninput="this.closest('fieldset').querySelector('input[value=annet]').checked = this.value.trim() !== ''" placeholder="write here" style="width:9rem"></label>
      <p style="margin:.4rem 0 0; font-size:.88rem; opacity:.8">Guidance comes in Norwegian or English. If you write another language we note it, but the guidance will be in English.</p>
    </fieldset>
    <label>Who told you about Treni? <span class="valgfritt">(optional, a person, social media, your club …)</span>
      <input type="text" name="kilde" placeholder="e.g. a friend, Instagram, my running club"
             value="<?php echo htmlspecialchars(mb_substr(trim($_POST["kilde"] ?? $_GET["kilde"] ?? ""), 0, 200)); ?>">
    </label>
    <label><?= $er_coach ? 'A bit about you and your athletes' : 'A bit about your running' ?> <span class="valgfritt"><?= $er_coach ? '(how many athletes, level, club, and what you want Treni to take off your plate)' : '(optional: what do you want to achieve?)' ?></span>
      <textarea name="om" rows="4"><?php echo htmlspecialchars($_POST["om"] ?? ""); ?></textarea>
    </label>
    <?php if (empty($er_coach)): ?>
    <fieldset class="sprak-felt" style="margin-top:.9rem" id="klokke-felt">
      <legend>Heart-rate watch</legend>
      <label class="radio"><input type="checkbox" name="klokke" value="ja" required <?= (($_POST["klokke"] ?? "") === "ja") ? "checked" : "" ?>> I have a heart-rate watch that connects to Intervals.icu (Garmin, Polar, Suunto, Coros or Wahoo)</label>
      <span class="valgfritt" style="display:block;margin-top:.35rem">Treni builds your plan on the sessions from your watch. Without one we cannot build a plan.</span>
    </fieldset>
    <?php endif; ?>
    <label class="krukke" aria-hidden="true">Website
      <input type="text" name="nettside" tabindex="-1" autocomplete="off">
    </label>
    <?php treni_spamvern_felt(true); ?>
    <button type="submit" class="btn btn-primar">Get started and connect your watch</button>
  </form>
  <script>
  // Revision 10.09 (item 11): choosing "coach" inside the form left the watch
  // checkbox required, and the browser refused to submit. The server only
  // requires a watch for runners, so the field follows the radio.
  (function () {
    var f = document.getElementById('klokke-felt'); if (!f) { return; }
    var b = f.querySelector('input[name=klokke]');
    document.querySelectorAll('input[name=rolle]').forEach(function (r) {
      r.addEventListener('change', function () {
        var coach = document.querySelector('input[name=rolle]:checked').value === 'coach';
        f.hidden = coach;
        if (b) { b.required = !coach; }
      });
    });
  })();
  </script>
  <script src="../kladd.js?v=2" defer></script>
  <p class="liten" style="margin-top:1.5rem">Prefer email? Write directly to
  <a href="mailto:hei@treni.no?subject=I%20want%20to%20test%20Treni">hei@treni.no</a>.
  Your details are used only to reply to you.
  <a href="privacy.html">read the privacy policy</a>.</p>
</section>
<?php endif; ?>

<footer>
  <nav aria-label="Footer">
    <a href="index.html">Home</a>
    <a href="../stotte.html#en-t">Support &amp; contact</a>
    <a href="privacy.html">Privacy</a>
  </nav>
  <p>PAULSEN UTVIKLING · org no 938 158 614 · Norway ·
     <a href="mailto:hei@treni.no">hei@treni.no</a></p>
  <p>Powered by Strava. This service is not affiliated with or endorsed by Strava.</p>
</footer>

</main>
</body>
</html>
