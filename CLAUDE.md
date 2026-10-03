@AGENTS.md

# Werkwijze in deze repo

- **Nooit direct op `main` committen of pushen.** `main` is op GitHub beschermd
  (branch protection, `enforce_admins: true`) — een directe push wordt door
  GitHub geweigerd, ook voor de repo-eigenaar.
- Maak voor elke wijziging een feature branch (`feature/...` of `fix/...`),
  commit daar, push die branch, en open een pull request naar `main`.
- Reviews zijn niet verplicht (required_approving_review_count: 0) — een PR
  mag door de eigenaar zelf gemerged worden, maar moet wél als PR bestaan.
- Deploy naar productie (rsync naar de server) mag vanaf een feature branch
  vóór het mergen, zoals al gebruikelijk in deze workflow — dat is losstaand
  van de git-historie.

## Productieomgeving

- Stel lokaal `DEPLOY_HOST` in op het SSH-doel; leg de concrete loginnaam niet vast in deze repository.
- **Deployen**: rsync de gewijzigde bestanden naar
  `$DEPLOY_HOST:/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-bookkeeping/`,
  bijv.:
  ```
  rsync -av includes/class-db.php "$DEPLOY_HOST":/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-bookkeeping/ --relative
  ```
  Dit is de live WordPress-install, gedraaid in Docker
  (webserver-container `scripts-wordpress-pvh-1`).
- **Database / WP-CLI**: gebruik de daarvoor bedoelde `wpcli-pvh`
  service uit `docker-compose.yml` — **niet** `docker exec` direct op
  de webserver-container (`scripts-wordpress-pvh-1`): die heeft buiten
  de normale PHP-FPM-requestflow om geen werkende databaseverbinding
  (ontdekt tijdens deze sessie — `wp-load.php` requiren via een kale
  `docker exec php` geeft daar "Error establishing a database
  connection", ook al draait de site zelf prima).
  ```
  ssh "$DEPLOY_HOST" "docker compose -f /opt/docker/scripts/docker-compose.yml run --rm --no-deps wpcli-pvh wp <commando>"
  ```
  Bijv. `wp option get siteurl`, `wp db query "SELECT ..."`,
  `wp eval-file ...`. Laat `-it` weg — er is geen TTY beschikbaar via
  een niet-interactieve SSH-aanroep, dat geeft anders een docker-
  compose-TTY-fout. Een "Permission denied ... wpdb/" regel van een
  ander plugin (Code Snippets) in de output is onschuldig en negeerbaar.
  Alléén lezen tenzij expliciet gevraagd om iets te wijzigen — dit is
  productiedata van een echte vereniging (zie ook AGENTS.md over namen
  in query-resultaten: nooit overnemen in code, commits, of losse
  bestanden in de repo).
