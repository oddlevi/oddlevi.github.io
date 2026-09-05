#!/usr/bin/env python3
"""Runde 5: stor forbokstav (Eirik 04.09 20:30) — og en prikk som treffer i-en.

Eirik: «Kanskje ha stor bokstav først i logoen ?» Denne runden lager «Treni»
ved siden av «treni», ellers helt likt, så de kan sammenlignes rett opp mot
hverandre.

Underveis kom en feil for en dag: i runde 4 var lime-prikken plassert på
gjettede koordinater (cx=700), og den landet over N-EN. I-en beholdt sin egen
hvite firkantprikk ved siden av. Merket hadde altså to prikker, og aksenten
satt på feil bokstav. Her måles i-prikkens faktiske boks i den rendrede
teksten (skript/../tittle-målingen), og sirkelen legges nøyaktig oppå den, med
radius som dekker firkanten uten å berøre stammen under.

Målte bokser (Helvetica Neue 800):
  rund 1024, «treni»: x 756-798, y 396-430   → sirkel (777, 413) r 38
  rund 1024, «Treni»: x 781-823, y 396-430   → sirkel (802, 413) r 38
  bred 1600, «treni»: x 558-584, y 111-132   → sirkel (571, 122) r 23
  bred 1600, «Treni»: x 588-614, y 111-132   → sirkel (601, 122) r 23
"""
import colorsys
import pathlib
import subprocess

UT = (pathlib.Path(__file__).resolve().parents[2] / "treni-side" / "bilder"
      / "profil" / "utkast")
CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
LIME = "#96D25A"
SKRIFT = "Helvetica Neue, Helvetica, Arial, sans-serif"


def _hsl(h, s, l):
    r, g, b = colorsys.hls_to_rgb(h / 360, l, s)
    return "#%02X%02X%02X" % (round(r * 255), round(g * 255), round(b * 255))


BUNN, BUNN_MORK = _hsl(152, 0.62, 0.20), _hsl(152, 0.62, 0.12)
S = 1024

# (ord, prikk rund, prikk bred) — målt, ikke gjettet.
# Prikken løftes 10 px på rundmerket og 6 px på det brede (Odd 04.09: «der
# prikken må litt opp, noen pixel»). Sentrert på i-prikkens boks lå sirkelen
# med underkant 451, bare 4 px over stammetoppen på 455 — den klemte. Løftet
# gir 14 px luft og dekker fortsatt firkanten helt: verste hjørne (781, 430)
# ligger 34,2 px fra det nye senteret, innenfor radius 38.
VARIANTER = {
    "liten": ("treni", (777, 403, 38), (571, 116, 23)),
    "stor":  ("Treni", (802, 403, 38), (601, 116, 23)),
}


def rund(ord_, prikk):
    cx, cy, r = prikk
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {S} {S}" width="{S}" height="{S}">
  <defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="{BUNN}"/><stop offset="1" stop-color="{BUNN_MORK}"/></linearGradient>
  <clipPath id="c"><circle cx="512" cy="512" r="512"/></clipPath></defs>
  <g clip-path="url(#c)"><rect width="{S}" height="{S}" fill="url(#g)"/>
    <text x="512" y="610" font-family="{SKRIFT}" font-size="300" font-weight="800"
          letter-spacing="-12" fill="#fff" text-anchor="middle">{ord_}</text>
    <circle cx="{cx}" cy="{cy}" r="{r}" fill="{LIME}"/>
  </g></svg>'''


def bred(ord_, prikk):
    cx, cy, r = prikk
    B, H = 1600, 420
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {B} {H}" width="{B}" height="{H}">
  <rect width="{B}" height="{H}" fill="{BUNN}"/>
  <text x="240" y="240" font-family="{SKRIFT}" font-size="180" font-weight="800"
        letter-spacing="-8" fill="#fff">{ord_}</text>
  <circle cx="{cx}" cy="{cy}" r="{r}" fill="{LIME}"/>
  <path d="M 246 292 C 340 292 380 268 452 268 C 530 268 566 244 620 244"
        fill="none" stroke="{LIME}" stroke-width="20" stroke-linecap="round"/>
  <text x="700" y="240" font-family="{SKRIFT}" font-size="52" font-weight="500"
        letter-spacing="6" fill="{LIME}">TRENERLEDET LØPING</text>
</svg>'''


def _skyt(svg, sti_svg, sti_png, b, h):
    sti_svg.write_text(svg, encoding="utf-8")
    subprocess.run([CHROME, "--headless", "--disable-gpu", f"--screenshot={sti_png}",
                    f"--window-size={b},{h}", "--hide-scrollbars",
                    "--default-background-color=00000000", f"file://{sti_svg}"],
                   capture_output=True, timeout=90)


def lagre():
    from PIL import Image
    UT.mkdir(parents=True, exist_ok=True)
    for navn, (ord_, p_rund, p_bred) in VARIANTER.items():
        stor = UT / f"logo_R_{navn}_1024.png"
        _skyt(rund(ord_, p_rund), UT / f"logo_R_{navn}.svg", stor, S, S)
        k = Image.open(stor).convert("RGBA")
        for px in (180, 40, 16):
            k.resize((px, px), Image.LANCZOS).save(UT / f"logo_R_{navn}_{px}.png")
        _skyt(bred(ord_, p_bred), UT / f"logo_R_{navn}_bred.svg",
              UT / f"logo_R_{navn}_bred.png", 1600, 420)
        print("laget", navn)

    # Sammenligningsark: de to rundmerkene ved siden av hverandre, i tre
    # størrelser hver — 180 px er profilbildet, 40 px er rutenettet.
    # Merkene har gjennomsiktige hjørner; de MÅ komponeres på arkbakgrunnen,
    # ellers blir hjørnene svarte i RGB-konverteringen og arket ser ut som en
    # feil i selve logoen.
    ark = Image.new("RGB", (1180, 700), (245, 245, 243))
    for i, navn in enumerate(("liten", "stor")):
        x = 90 + i * 560
        for kilde, str_, pos in ((f"logo_R_{navn}_1024.png", 380, (x, 60)),
                                 (f"logo_R_{navn}_180.png", 180, (x, 490)),
                                 (f"logo_R_{navn}_40.png", 40, (x + 260, 560))):
            k = Image.open(UT / kilde).convert("RGBA").resize((str_, str_), Image.LANCZOS)
            ark.paste(k, pos, k)
    ark.save(UT / "sammenligning_stor_liten.png")
    print("laget sammenligningsark")


if __name__ == "__main__":
    lagre()
