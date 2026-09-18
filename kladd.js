/* Treni: kladdlagring for skjemaene (Lola 09.09.2026).
 *
 * «I filled it like 6 times from scratch 😢», skjemaene husket ingenting.
 * Feilet en validering, slo ratesperren inn, eller lukket hun sida, var alt
 * borte. Nå lagres hvert svar på løperens EGEN maskin mens hun skriver.
 * Ingenting sendes noe sted før hun trykker send.
 *
 * Brukes ved å sette data-kladd="<navn>" på skjemaet. Norsk er standard,
 * engelsk slås på med data-en="1".
 *
 * Lagres ALDRI: samtykker (skal krysses av bevisst hver gang, GDPR art. 9),
 * honningfellen, og skjulte felt.
 */
(function () {
  "use strict";
  var skjemaer = document.querySelectorAll("form[data-kladd]");
  if (!skjemaer.length) { return; }

  Array.prototype.forEach.call(skjemaer, function (f) {
    var EN = f.dataset.en === "1";
    var kode = (location.search.match(/[?&]k=([A-Za-z0-9_-]+)/) || [])[1] || "";
    var NOKKEL = "treni_kladd_" + f.dataset.kladd + (kode ? "_" + kode : "");

    var HOPP = ["nettside", "k", "kode"];
    function hoppOver(e) {
      return !e.name || e.type === "hidden" || e.type === "password" ||
             HOPP.indexOf(e.name) > -1 || /samtykke/i.test(e.name);
    }

    function felt() { return f.querySelectorAll("input, select, textarea"); }

    function lagre() {
      var d = {};
      Array.prototype.forEach.call(felt(), function (e) {
        if (hoppOver(e)) { return; }
        if (e.type === "checkbox") {
          if (e.checked) { (d[e.name] = d[e.name] || []).push(e.value); }
        } else if (e.type === "radio") {
          if (e.checked) { d[e.name] = e.value; }
        } else if (e.value) {
          d[e.name] = e.value;
        }
      });
      try {
        localStorage.setItem(NOKKEL, JSON.stringify({ d: d, t: Date.now() }));
        return true;
      } catch (err) { return false; }
    }

    function fyll() {
      var raa;
      try { raa = JSON.parse(localStorage.getItem(NOKKEL) || "null"); }
      catch (err) { return false; }
      if (!raa || !raa.d || !Object.keys(raa.d).length) { return false; }
      // Kladd eldre enn 30 dager er trolig ikke lenger aktuell.
      if (raa.t && Date.now() - raa.t > 30 * 864e5) {
        try { localStorage.removeItem(NOKKEL); } catch (err) {}
        return false;
      }
      var d = raa.d, traff = 0;
      Array.prototype.forEach.call(felt(), function (e) {
        if (hoppOver(e) || !(e.name in d)) { return; }
        var v = d[e.name];
        if (e.type === "checkbox") {
          e.checked = Array.isArray(v) && v.indexOf(e.value) > -1;
          if (e.checked) { traff++; }
        } else if (e.type === "radio") {
          if (e.value === v) { e.checked = true; traff++; }
        } else if (!e.value) {
          e.value = v; traff++;
        }
        e.dispatchEvent(new Event("change", { bubbles: true }));
      });
      return traff > 0;
    }

    function tomKladd() {
      try { localStorage.removeItem(NOKKEL); } catch (err) {}
    }

    /* --- Lagre-knapp + status ------------------------------------------- */
    var send = f.querySelector('button[type="submit"], input[type="submit"]');
    var rad = document.createElement("div");
    rad.style.cssText = "display:flex;flex-wrap:wrap;align-items:center;gap:.7rem;margin:.7rem 0 0";

    var knapp = document.createElement("button");
    knapp.type = "button";
    knapp.textContent = EN ? "💾 Save and continue later" : "💾 Lagre og fortsett senere";
    knapp.style.cssText = "background:none;border:1.5px solid hsl(var(--border));" +
      "color:inherit;border-radius:999px;padding:.5rem .95rem;font:inherit;" +
      "font-size:.9rem;cursor:pointer";

    var status = document.createElement("span");
    status.setAttribute("role", "status");
    status.style.cssText = "font-size:.85rem;color:hsl(var(--muted-fg))";
    status.textContent = EN ? "Saved on this device as you type."
                            : "Lagres på denne enheten mens du skriver.";

    var tid;
    function meld(tekst) {
      status.textContent = tekst;
      clearTimeout(tid);
      tid = setTimeout(function () {
        status.textContent = EN ? "Saved on this device as you type."
                                : "Lagres på denne enheten mens du skriver.";
      }, 4000);
    }

    knapp.addEventListener("click", function () {
      meld(lagre()
        ? (EN ? "✅ Saved. Come back to this link any time."
              : "✅ Lagret. Kom tilbake til denne lenka når du vil.")
        : (EN ? "Could not save here (private browsing?). Keep the tab open."
              : "Fikk ikke lagret her (privat modus?). La fanen stå åpen."));
    });

    rad.appendChild(knapp);
    rad.appendChild(status);
    // Odd 09.09: lagre-knappen står OVER send-knappen. Den er et mellomsteg,
    // og skal leses før man bestemmer seg for å sende.
    if (send && send.parentNode) {
      rad.style.margin = "0 0 .9rem";
      send.parentNode.insertBefore(rad, send);
    } else {
      f.appendChild(rad);
    }

    /* --- Fyll inn det som ble skrevet sist ------------------------------ */
    if (fyll()) {
      var info = document.createElement("p");
      info.style.cssText = "margin:0 0 1.1rem;padding:.65rem .85rem;border-radius:10px;" +
        "background:color-mix(in srgb, hsl(152 62% 40%) 14%, transparent);" +
        "font-size:.93rem;line-height:1.5";
      info.textContent = EN
        ? "↩️ We filled in what you wrote last time. Check it over and finish."
        : "↩️ Vi har fylt inn det du skrev sist. Se over og gjør ferdig.";
      f.insertBefore(info, f.firstChild);
    }

    f.addEventListener("input", lagre);
    f.addEventListener("change", lagre);
    // Ved bytte av fane eller lukking: ta en siste kopi.
    window.addEventListener("pagehide", lagre);

    // Gikk innsendingen gjennom, er kladden ikke lenger nødvendig.
    if (f.dataset.kladdFerdig === "1") { tomKladd(); }
    f.tomTreniKladd = tomKladd;
    // F-36 (10.09): veiviseren i skjema 2 lagrer ved hvert skjermskifte.
    f.lagreTreniKladd = lagre;
  });
})();
