# Foto’s uploaden naar een persoonlijke map

Op `/foto-delen/` krijgen ingelogde gebruikers met een bevestigde aanvraag hun
persoonlijke Drive-link, een bestandskiezer en een Google Photos-knop. Een
bevestigingslink zonder aanmelding toont alleen de maplink. Het uploaden maakt
geen nieuwe persoonlijke map aan.

De bestemming komt uitsluitend uit de bevestigde aanvraag van de huidige
WordPress-gebruiker. De Drive-serviceaccount van avpvh-gallery schrijft de
bestanden; de map moet rechtstreeks onder de jubileum-uploadmap staan.
Bestanden gaan via een tijdelijk bestand naar Drive, buiten de mediabibliotheek.
JPG, PNG, GIF, WebP, HEIC, HEIF, AVIF, TIFF en BMP zijn toegestaan, maximaal
25 MB per foto (of de lagere PHP-uploadlimiet). Zowel inhoud als extensie worden
gecontroleerd; SVG, documenten en video’s zijn uitgesloten.

## Google Photos instellen

De Picker gebruikt de bestaande Google-client uit avpvh-members:
`avpvh_oauth_google_client_id` en `avpvh_oauth_google_client_secret`.

1. Schakel **Google Photos Picker API** (`photospicker.googleapis.com`) in het
   Google Cloud-project van die client in.
2. Configureer het toestemmingsscherm voor
   `https://www.googleapis.com/auth/photospicker.mediaitems.readonly`, inclusief
   eventuele testgebruikers of verificatie die Google voor het project vraagt.
3. De bestaande redirect-URI blijft
   `/wp-json/avpvh/v1/oauth/google/callback` op de website. Gewone Google-login
   houdt zijn oorspronkelijke afhandeling; uitsluitend Picker-states worden
   onderschept.

De keuze opent in een apart tabblad, met `/autoclose` voor terugkeer na het
kiezen. Op mobiel kan de gebruiker ook handmatig teruggaan en op **Controleer
selectie** tikken. De voortgang hervat bij terugkeer of verversen in hetzelfde
tabblad. Een selectie bevat maximaal 100 bestanden; video’s en andere
niet-ondersteunde bestanden worden overgeslagen.

Toestemming en geselecteerde bestanden zijn gekoppeld aan dezelfde gebruiker
en dezelfde WordPress-aanmelding. De browser ontvangt geen Google-access-token
of download-URL. Het tijdelijke token verloopt na maximaal één uur en wordt bij
afronden of annuleren samen met de Picker-sessie verwijderd. Herhaalde imports
binnen dezelfde selectie maken geen extra kopie. Google kan het formaat van een
foto bij downloaden veranderen; de opgeslagen extensie volgt het echte formaat.
Google’s originele download verwijdert locatiegegevens uit de metadata.

## Controleren

`php tests/test-photo-upload.php` controleert onder andere bestandstypen,
eigenaarschap, nonce, sessiebinding, PKCE, paginering, poll-intervallen,
download-hosts, herhaalde imports en opruimen van tijdelijke bestanden/tokens.

Controleer daarnaast in een mobiele browser de bestandskiezer en een echte
Google Photos-toestemming: kies een foto, bevestig bij Google, keer terug en
controleer de kopie in de eigen uploadmap. De laatste stap vereist een Google
account met Photos; een serviceaccount kan deze toestemming niet geven.

Google-documentatie: [instellen](https://developers.google.com/photos/overview/configure-your-app),
[selectie](https://developers.google.com/photos/picker/guides/picking-experience),
[downloaden](https://developers.google.com/photos/picker/guides/media-items).
