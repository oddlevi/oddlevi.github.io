# Bilder til Treni Løpsdag-sidene

Legg bildet her, og bruk filnavnet `<klubb>_<løp>_<år>.jpg`, med små bokstaver og
bindestrek i stedet for mellomrom. Eksempel:

    northern-runners_ruskamaraton_2026.jpg

**Krav til bildet**
- Liggende, minst 1600 px bredt. 2000 px er bedre, siden bildet også brukes som
  delebilde på Facebook og Instagram.
- Under 500 kB. Er det større, komprimer det først.
- JPG for foto, PNG bare når bildet har tekst eller grafikk.
- Bildet må være klubbens eget, arrangørens med avtale, eller vårt eget.
  Aldri et bilde vi ikke vet hvem eier.

**Slik kommer det ut på nett**
Filene i denne mappa deployes til `treni.no/bilder/lopsdag/` og brukes på
klubbsidene under treni.no/<klubb>/<løp>-<år>/.

    scp -i ~/.ssh/hostinger_treni -P 65002 bilder/lopsdag/<fil> \
        u853815691@145.14.153.137:domains/treni.no/public_html/bilder/lopsdag/

Si fra til Claude når bildet ligger her, så legges det inn på sida og som
delebilde (og:image), slik at det vises når lenken deles på Facebook.
