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

- `DEPLOY_HOST` wordt lokaal geladen uit `.env.local` (bevat `DEPLOY_HOST=avpvh.nl`, wat dezelfde server is als `rechtspreker.nl`). Dit bestand is opgenomen in `.gitignore` zodat het lokaal blijft en nooit op GitHub terechtkomt.
- **Deployen**: regel: de server krijgt alléén wat nodig is om een lid te
  bedienen — geen tests, geen dev-tooling, geen secrets. Daarom deployen we
  via `git archive` (die `.gitattributes`' `export-ignore` respecteert —
  zie dat bestand voor wat precies wordt uitgesloten: `tests/`, `.github/`,
  `bin/`, `docs/`, `*.md`, dotfiles, `composer.json`/`.lock`), **niet** een
  rsync van de working tree:
  ```
  REMOTE_DIR=/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-bookkeeping
  TMP=/tmp/avbk-deploy-$(date +%s)
  ssh "$DEPLOY_HOST" "mkdir -p $TMP" \
    && git archive HEAD | ssh "$DEPLOY_HOST" "tar -x -C $TMP" \
    && ssh "$DEPLOY_HOST" "rsync -a --delete --exclude=vendor --exclude=output $TMP/ $REMOTE_DIR/ && rm -rf $TMP"
  ```
  `vendor/` en `output/` staan niet in git (zie `.gitignore`) maar zijn wel
  nodig op productie resp. gegenereerd materiaal dat er mag blijven staan —
  vandaar de `--exclude` erop, zodat `--delete` ze met rust laat. Wijzigt
  `vendor/` (composer-afhankelijkheden), sync die dan apart:
  `rsync -a --delete vendor/ "$DEPLOY_HOST":$REMOTE_DIR/vendor/`.
  Check vóór elke deploy dat de archive schoon is:
  `git archive HEAD | tar -t | grep -E '\.md$|\.sh$|\.env|^\.|(^|/)test'`
  moet niets teruggeven. Dit is de live WordPress-install, gedraaid in
  Docker (webserver-container `scripts-wordpress-pvh-1`).
  Voor een losse bugfix aan één bestand (tussen volledige deploys in) mag
  je nog steeds gericht rsyncen, bijv.
  `rsync -av includes/class-db.php "$DEPLOY_HOST":$REMOTE_DIR/ --relative` —
  maar doe daarna op een logisch moment alsnog een volledige archive-deploy,
  anders ontstaat weer drift tussen wat git denkt dat er staat en wat er
  werkelijk staat (zoals ontdekt tijdens deze sessie: 129 bestanden op
  productie die niet in git zaten, waaronder `.env.local` en `.claude/`).
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
