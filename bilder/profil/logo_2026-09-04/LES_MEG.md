# Treni-logoen — vedtatt 04.09.2026

Odd valgte `logo_R_stor` denne kvelden. Alt annet er arkivert i
`../utkast/arkiv/`. Denne mappa er fasiten; ta filer herfra, ikke fra utkast.

## Merket
Ordmerket **Treni** i hvitt på en grønn sirkel, med en lime prikk over i-en.
Prikken ERSTATTER i-ens egen firkantprikk — den ligger ikke ved siden av.
Runde 4 hadde den feilen: prikken lå på gjettede koordinater over n-en, og
merket fikk to prikker. Her er i-prikkens boks målt i den rendrede teksten
og sirkelen lagt oppå, så løftet 10 px for å gi luft ned til stammen.

Stor forbokstav kom fra Eirik 04.09: «Kanskje ha stor bokstav først i logoen?»

Vi gikk fra T-monogram til ordmerke fordi Odd påpekte at «T er gjerne
assosiert med turist». DNTs røde T er varemerkebeskyttet og betyr «her går
løypa» i norske fjell — samme domene som oss. Et ord låner ingen betydning
fra noen.

## Farger
| | verdi |
|---|---|
| Bunn, topp av forløpningen | `#144A2C` (hsl 152 62 % 20 %) |
| Bunn, bunn av forløpningen | `#0C2C1A` (hsl 152 62 % 12 %) |
| Lime aksent (prikken) | `#96D25A` |
| Ordet | `#FFFFFF` |

Lime-tonen er målt fra innleggene våre, så logoen og innholdet i feeden
snakker samme språk.

## Skrift
Helvetica Neue 800, sperring −12 på rundmerket og −8 på det brede.

## Hva du bruker hvor
| Mappe | Bruk |
|---|---|
| `rund/` | Profilbilder (Instagram, Facebook, LinkedIn), app-ikon, alt kvadratisk. 180 px er profilbildet, 40 px er rutenettet i feeden. |
| `bred/` | Toppen av nettsider, Facebook-omslag, e-postsignatur, presentasjoner. Inneholder også «TRENERLEDET LØPING». |
| `ikoner/` | `favicon.ico` (16/32/48/64 i én fil), `apple-touch-icon.png` (180), `icon-192`/`icon-512` for PWA-manifestet. |
| `kilde/` | Vektorene (`.svg`) og `bygg_logo.py` som bygger hele settet på nytt. Trenger du en størrelse som ikke ligger her, kjør skriptet. |

## Merk
Under ca. 40 px blir ordet uleselig og merket leses som en grønn sirkel med
en lime prikk. Det er akseptert — prikken er det som overlever, og den er
vår. Trenger vi et eget lite-format-merke senere, er `logo_P_stigning` i
arkivet utgangspunktet.

## Prikkløs i — les dette før du rører merket

Ordet skrives med **prikkløs i** (U+0131, «ı»): `Trenı`. Lime-sirkelen er da
den ENESTE prikken, og ingenting må dekkes.

Slik var det ikke først. Fontens egen hvite firkantprikk lå under, og
lime-sirkelen DEKKET den. Det holdt så lenge Helvetica Neue var tilgjengelig,
men i Arial og alle andre fallback-fonter sitter i-prikken litt annerledes, og
den hvite firkanten stakk ut nederst til høyre for sirkelen. Odd fant det
04.09 i SVG-filene. Nå finnes prikken ikke i det hele tatt, og merket er
verifisert likt i Helvetica Neue, Arial og systemfonten.

Skriver du ordet på nytt et sted: bruk `Trenı`, ikke `Treni`.
