# Agent-instructies voor deze repo

Deze instructies gelden voor elke AI-coding-tool die in deze repo werkt
(Claude Code, Codex, Cursor, of wat dan ook) — niet alleen voor Claude.

## Geen echte namen in code, comments of commit messages

Dit is een ledenadministratie/boekhoudsysteem met echte persoonsgegevens
(leden, penningmeester, betalingen, contributies). Zet nergens in de repo
een echte naam van een lid of ander persoon — niet in code, comments/
docblocks, commit messages, of losse notitie-/scratchbestanden die in de
repo terechtkomen.

- Illustratieve voorbeelden (bijv. bij naam-matching-logica in
  `AVBK_Matcher`) moeten duidelijk verzonnen zijn — gebruik generieke
  placeholders zoals "Anna", "Bram", "Cas", "Piet Jansen".
- Een debug-notitie over een specifiek incident ("betaling van [naam]
  klopt niet") hoort niet met een echte naam in de repo terecht te komen —
  hou 'm lokaal buiten git, of anonimiseer 'm eerst tot iets als "lid X".
- Bij twijfel: geen namen. Ledengegevens horen in de database (met de
  normale toegangsbeperkingen), niet in git-geschiedenis die ooit gedeeld
  of gepusht kan worden.

## Versiebump bij elke PR

Elke pull request die naar `main` gemerged wordt, bevat een versiebump
van de `Version:`-header in `avpvh-bookkeeping.php` — als onderdeel van
diezelfde PR, niet achteraf los. Patch/minor/major naar inschatting van
de omvang van de wijziging.
