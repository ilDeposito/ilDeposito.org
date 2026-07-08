# Backend — Drupal 11

## Architettura

Drupal 11 + PHP 8.3 in modalità **headless**: espone i contenuti via **JSON:API**, consumati dal frontend Astro a build time. Il backend è ad uso esclusivo degli editor — l'accesso anonimo alle rotte admin è bloccato da un firewall custom (`ildeposito_utils`), lasciando pubblici solo `/jsonapi`, `/api/*`, `/system/files` e le rotte di login.

Tema: **Radix 6** (Bootstrap 5.3), build con Laravel Mix. Cache: **Redis** (bin render/page/dynamic_page/lock/flood/cache-tags; il bin `form` resta su DB).

## Path principali

```
backend/
├── web/modules/custom/     # moduli custom (vedi sotto)
├── web/themes/custom/ildeposito/   # tema Radix 6, SDC + preprocess in includes/*.theme
└── web/sites/default/settings.{php,dev,stage,prod,ddev,codespace}.php
```

Vocabolario: content type `canto`, `autore`, `evento`, `traduzione`, `pagina`; tassonomie `lingue`, `localizzazioni`, `periodi`, `tags`, `tematiche`.

## Moduli custom

### `ildeposito_build`
Pulsante "Pubblica contenuti" per editor: triggera e monitora una build GitHub Actions del frontend Astro (i contenuti modificati in Drupal non sono visibili finché il sito statico non viene ribuildato).

- `GitHubWorkflowClient` — si autentica come GitHub App (JWT + installation token), lancia `workflow_dispatch` e ne segue lo stato via REST API
- `BuildFrontendForm` (`/admin/pubblica-contenuti`) — batch Drupal che triggera e polla la build (max 120×3s)
- `BuildAccessCheck` — limita la rotta/voce toolbar a `ILDEPOSITO_ENV` in `stage|prod|local`
- Hook: `hook_runtime_requirements` (mostra git ref/commit corrente in stato admin), `hook_toolbar` (voce "Pubblica contenuti")

### `ildeposito_contatti`
Entità content fieldable custom (`ildeposito_contatto`, bundle configurabili) per le submission del form contatti dal frontend Astro via JSON:API.

- `hook_entity_presave` forza `status = 'nuova'` e cattura `ip_address` server-side (il client non può settarli)
- `hook_entity_insert` accoda una notifica (non invio inline, per non rallentare la risposta JSON:API)
- `NotificaContattoWorker` (QueueWorker, anche su cron ogni 30s come fallback) invia l'email ai destinatari in `State` (`contatti_destinatari`)
- `ContattiQueueTerminateSubscriber` — drena la coda su `kernel.terminate`, dopo l'invio della risposta
- `JsonApiWriteOverride` — forza `jsonapi.settings.read_only = FALSE` a runtime per registrare le rotte di scrittura; il blocco effettivo per anonimi è delegato al firewall di `ildeposito_utils`
- Nessuna route custom: submission via `POST /jsonapi/ildeposito_contatto/{bundle}` (basic auth, ruolo "api")

### `ildeposito_redirects`
Redirect da URL legacy (vecchio Drupal 8) + report periodici dei 404 catturati da nginx.

- `RedirectsForm` (`/admin/config/search/redirects`) — coppie `/old|/new` in `State`, con validazione anti open-redirect
- `RedirectsApiController` — `GET /api/redirects.json` (pubblico, cache 300s), consumato a build time da Astro (`generate-redirects.mjs`) per generare le regole nginx
- `Report404Command` (drush `ildeposito:report-404`, alias `ir404`) — parsa `/var/log/frontend-nginx/404.log` (volume condiviso con nginx), invia report via email, tronca il log solo dopo invio riuscito

### `ildeposito_stats`
Importa/riconcilia statistiche di visualizzazione da un'istanza self-hosted **Umami**, associandole a nodi/termini Drupal.

- `UmamiClient` — login su Umami self-hosted, fetch pageviews per path/finestra temporale
- `EntityUrlMatcher` — risolve un URL frontend a un nodo (canto/autore/evento/traduzione/pagina) o termine tassonomia via `path_alias`, con cache in-memory
- Drush: `ildeposito:umami-stats` (`ius`, diagnostico, sola lettura), `ildeposito:umami-sync` (`iusync`, calcola i delta ma **la scrittura su `field_visualizzazioni_*` è attualmente disattivata/commentata** — solo calcolo e stampa)

### `ildeposito_utils`
Utility trasversali per il setup headless: dashboard editor, firewall di accesso, piccoli aggiustamenti UX editoriali, processor Search API per QA editoriale.

- `DashboardController` (`/dashboard`) — dashboard admin-style dal menu "staff", riusa i theme hook `admin_page`/`admin_block_content` (styling Claro/Gin)
- `AnonymousLoginRedirect` (event subscriber, `kernel.request`) — redirige gli anonimi a `/user/login` per qualunque path non sotto `/jsonapi`, `/api/`, `/system/files`, `/user/login|password|reset`, o derivati image style — il backend è editor-only, il pubblico passa da JSON:API/Astro
- `JsonApiWriteFirewall` (event subscriber) — blocca con 401 le richieste JSON:API non-GET di utenti anonimi; l'autorizzazione per utenti autenticati resta agli access handler per-entità
- Hook: monospace su widget `field_canto_testo`/`field_canto_accordi` (allineamento accordi), titolo nodo `autore` auto-calcolato da nome+cognome, nasconde link quick-create Media/Taxonomy dal menu "Crea" per il ruolo staff
- `ContenutiStaff` (Search API processor) — calcola `field_contenuti_staff` ("con X"/"senza X") per facet di QA editoriale (Facets + Better Exposed Filters), non esposto al frontend

### `migrando`
Migrazione one-time dal vecchio Drupal 8 monolitico (canti, autori, eventi, media, tassonomie, utenti, traduzioni), via `migrate` + `migrate_plus` + `migrate_tools`. 14 configurazioni di migrazione sorgenti da JSON/XML in `files/`. Plugin custom: source `Utenti` (XML legacy), process `GetNode`, `GetTerm`, `FilePath`/`FileSubstr`, `DateSubstr`, `Timestamp`, `Encode`, `GetMigrationId`. Stile di codice più datato rispetto agli altri moduli (no `strict_types`, plugin ad annotazioni) — scaffolding di migrazione, non logica runtime corrente.

## ⚠️ Nota: `ildeposito_raw` mancante

Il modulo `ildeposito_raw` (servizio `RawEntityManager`, usato per fornire dati "raw" delle entità ai componenti header) **non esiste più in `modules/custom`**, ma è ancora richiamato attivamente da `web/themes/custom/ildeposito/includes/page.theme` (`\Drupal::service('ildeposito_raw.manager')->getRawData($entity)`) e documentato nel README del componente SDC `content-header-canto`. Risulta installato nel dump DB (`mariadb-init/backup.sql`, `ildeposito_raw.settings`), ma assente dal codice e dalla vendor — verificare se va ripristinato o se `page.theme` va aggiornato per rimuovere la dipendenza, altrimenti le pagine nodo/termine rischiano un fatal error a runtime.
