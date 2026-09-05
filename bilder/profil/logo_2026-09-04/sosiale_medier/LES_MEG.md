# Til Instagram og Facebook

| Fil | Last opp som |
|---|---|
| `instagram_profil_1080.png` | Profilbilde på Instagram (@treni.norge) |
| `facebook_profil_1080.png` | Profilbilde på Facebook-siden (Treni.no) — identisk fil, eget navn så du ikke er i tvil |
| `facebook_omslag_1640x624.png` | Omslagsbilde på Facebook-siden |
| `instagram_profil_320.png` | Reserve hvis en flate nekter store filer |
| `treni_kvadrat.svg`, `treni_omslag.svg` | Vektorkildene |

## Hvorfor egne filer, og ikke bare rundmerket

Begge flatene beskjærer profilbildet til en **sirkel** selv. Laster vi opp det
runde merket med gjennomsiktige hjørner, beskjærer de en sirkel inni en
sirkel, og kantutjevningen fra to nedskaleringer legger seg oppå hverandre.

Derfor er profilbildene her **fullflate kvadrater**: den grønne forløpningen
dekker hele ruta, og ordet ligger godt innenfor den innskrevne sirkelen.
Ytterste punkt i låsningen er 348 px fra midten, trygg sone er 410. Ingen
alfakanal, så ingen flate kan gjøre hjørnene svarte.

Rundmerket i `../rund/` er fortsatt riktig alle andre steder — app-ikon,
favicon, nettsider, alt som ikke beskjærer selv.

## Omslaget

Bygget for Facebooks 1640 × 624. Mobil beskjærer sidene, så både ordet,
stigningen og undertittelen ligger innenfor de midterste 1280 pikslene og er
loddrett midtstilt.

Prikken er **målt** på dette formatet (i-prikkens boks: x 998–1026, y 187–210),
ikke skalert fra rundmerket. Første forsøk gjettet, og den hvite firkanten
stakk ut nederst til høyre — samme feil som logorunde 4.

## Bygg på nytt
`veileder/skript/logo_sosiale.py`

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
