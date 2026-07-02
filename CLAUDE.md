# ilDeposito.org

Archivio online di canti di protesta politica e sociale italiani.

## Architettura

Monorepo con due applicazioni indipendenti: **Drupal 11** (backend/CMS) espone contenuti via **JSON:API**, **Astro 6** (frontend) li consuma a build time. Rendering **ibrido**: `output: 'static'` prerenderizza tutte le pagine a build time (SSG), ma l'adapter Node abilita alcuni endpoint server on-demand (SSR) per il form contatti — vedi [Rendering ibrido](#rendering-ibrido-ssg--ssr-on-demand).

| Componente | Path | Stack | Ruolo |
|---|---|---|---|
| **Backend** | `backend/` | Drupal 11 + PHP 8.3 + Radix 6 (Bootstrap 5) | CMS, JSON:API, admin |
| **Frontend** | `frontend/` | Astro 6 + Tailwind v4 + DaisyUI v5 + Node adapter | Sito pubblico (SSG + SSR on-demand) |

Il frontend raggiunge il backend tramite la rete Docker interna (`drupal-api` alias nginx) in staging/prod, oppure via `http://ildeposito11.ddev.site` in locale.

## Ambienti

### Locale — DDEV

Gestito da `./local.sh` nella root del progetto.

```bash
./local.sh up          # Avvia DDEV → Drupal + Astro dev server
./local.sh stop        # Arresta
./local.sh restart     # Riavvia
./local.sh build       # Build statica Astro con progresso % sui nodi
./local.sh allinea     # (WIP) Allinea DB da produzione a locale
```

**DDEV** (`.ddev/config.yaml`):
- PHP 8.3, Nginx-FPM, MariaDB 10.11, Composer 2
- Docroot: `backend/web`
- Composer root: `backend/`
- Corepack abilitato

**Servizi DDEV aggiuntivi** (`.ddev/docker-compose.*.yaml`):
- **Astro dev server** — porta 4321 interna, esposta come HTTPS su porta 4322
- **Astro static** — container nginx che serve `frontend/dist/` come `frontend.ildeposito11.ddev.site`

**URL locali:**
- Drupal: `https://ildeposito11.ddev.site`
- Frontend dev (Astro): `https://ildeposito11.ddev.site:4322`
- Frontend statico (build): `https://frontend.ildeposito11.ddev.site`

**Comandi DDEV utili:**
```bash
ddev drush cr               # Cache rebuild
ddev drush uli              # Login link admin
ddev ssh                    # Shell nel container web
```

### Staging e Produzione — Docker Compose + Caddy

Gestiti da `./ildeposito.sh` nella root del progetto. Legge `.env` per determinare l'ambiente (`ENV=stage|prod`).

```bash
./ildeposito.sh up                # Avvia ambiente (pull + up -d)
./ildeposito.sh down              # Rimuovi containers e reti
./ildeposito.sh stop              # Arresta
./ildeposito.sh restart           # Riavvia
./ildeposito.sh build-frontend    # Build Astro zero-downtime
./ildeposito.sh drush <args>      # Esegui drush nel container php
./ildeposito.sh composer <args>   # Esegui composer nel container php
./ildeposito.sh shell [servizio]  # Shell (default: php)
./ildeposito.sh logs [servizio]   # Log
./ildeposito.sh ps                # Lista container
```

**Composizione Docker** (`compose.yml` nella root):
```
compose.yml (root)
├── backend/compose.yml          # Servizi base: mariadb, php, crond, nginx
├── backend/compose.${ENV}.yml   # Override per ambiente (nomi container, labels Caddy, basic auth)
└── frontend/compose.${ENV}.yml  # Builder Astro + nginx statico
```

**Servizi backend** (stack Wodby):

| Servizio | Immagine | Note |
|---|---|---|
| mariadb | `wodby/mariadb` | DB credentials in `backend/.env` |
| php | `wodby/drupal-php` | APCu 64M, SameSite Strict, xhprof/spx disabilitati |
| crond | `wodby/drupal-php` | `drush cron` ogni ora |
| nginx | `wodby/nginx` | Alias di rete `drupal-api`, reverse proxy Caddy |

**Servizi frontend**:

| Servizio | Immagine | Note |
|---|---|---|
| astro-builder | Node 22-alpine (custom Dockerfile) | Profile `build` — esegue solo su `build-frontend` |
| frontend-api | node:22-alpine | Server SSR Astro (`server/entry.mjs`, porta 4321) — serve gli endpoint `/api/*` |
| frontend-web | macbre/nginx-brotli | Serve i file statici da `current/client`, proxy di `/api/` verso `frontend-api` |

Basic auth Caddy: **stage** protegge sia backend (`admin-stage`) sia frontend (`stage`); **prod** protegge solo il backend (`admin`), il frontend (`www`) è pubblico. Sul backend le label Caddy includono `header_up -Authorization` per non inoltrare l'header al modulo `basic_auth` di Drupal (che altrimenti risponderebbe 403).

**Reti Docker**:
- `internal` — comunicazione interna servizi
- `caddy` — rete esterna per reverse proxy Caddy (TLS automatico)
- `ildeposito-${ENV}-internal` — rete condivisa backend↔frontend (nginx espone alias `drupal-api`)

**Deploy zero-downtime del frontend**:
Il builder (`docker-entrypoint.sh`) genera in `releases/$TIMESTAMP`, crea un symlink `current` → ultima release, e mantiene le ultime 7 release. Nginx serve da `current`.

**URL staging**:
- Backend: `https://admin-stage.ildeposito.org` (basic auth)
- Frontend: `https://stage.ildeposito.org` (basic auth)

**Configurazione ambiente** (`.env.example`):
```
ENV=stage                              # stage | prod
COMPOSE_PROJECT_NAME=ildeposito-stage
BASIC_AUTH_USER=...
BASIC_AUTH_HASH=...                    # Hash bcrypt per Caddy
```

### CI/CD — GitHub Actions

**`.github/workflows/deploy-stage.yml`**: deploy automatico su push a `main` (self-hosted runner).

Steps: backup DB → git pull → `./ildeposito.sh up` → composer install (no-dev) → drush updatedb/cim/cr → build frontend.

### Drupal settings per ambiente

`settings.php` rileva l'ambiente e include il file corretto:

| Ambiente | File | DB Host | Cache |
|---|---|---|---|
| DDEV | `settings.ddev.php` + `settings.dev.php` | `db` (DDEV) | Disabilitata |
| Staging | `settings.stage.php` | `mariadb` | Memcached |
| Produzione | `settings.prod.php` | `mariadb` | Memcached |
| Codespaces | `settings.codespace.php` | `db` | Memcached |

Config sync: `sites/default/config/`

## Backend — Drupal 11

### Path principali

```
backend/
├── composer.json                        # drupal/core ^11, drush ^13, radix ^6
├── .env                                 # Tag immagini Wodby (MariaDB, PHP, Nginx)
├── Makefile                             # make drush, make composer, make shell
├── compose.yml / compose.stage.yml      # Stack Docker
├── mariadb-init/                        # SQL di inizializzazione
├── script/
│   ├── reset.sh                         # Reset completo locale (DDEV)
│   ├── allinea.sh                       # Sync DB produzione → locale
│   └── deploy.sh                        # Deploy produzione (legacy)
├── drush/sites/live.site.yml            # Alias Drush per produzione
└── web/
    ├── modules/custom/
    │   └── migrando/                    # Migrazioni da sistema legacy
    ├── themes/custom/
    │   └── ildeposito/                  # Tema custom (Radix 6 / Bootstrap 5)
    └── sites/default/
        ├── settings.php                 # Entry point + env detection
        ├── settings.{dev,stage,prod,codespace,ddev}.php
        └── config/                      # ~300 file YAML config export
```

### Vocabolario del dominio

**Content type:** `canto`, `autore`, `evento`, `traduzione`, `pagina`

**Tassonomie:** `lingue`, `localizzazioni`, `periodi`, `tags`, `tematiche`

**Media:** audio, document, image, remote_video, video

### Moduli custom

**`migrando/`** — Migrazione dati dal sistema legacy. 17+ configurazioni migrate (autori, canti, eventi, traduzioni, tassonomie, statistiche, media). Plugin custom per process (Encode, Timestamp, DateSubstr, GetTerm, GetNode) e source (Utenti). Dati sorgente in `files/*.json`.

### Moduli contrib principali

`admin_toolbar`, `geofield` (coordinate eventi), `migrate_plus`, `migrate_tools`, `security_review`

### Tema ildeposito

- Base: **Radix 6** (Bootstrap 5.3)
- Build: **Laravel Mix** (Webpack) con Biome (linting) e Stylelint
- Componenti SDC in `components/`
- Template overrides in `templates/`
- Preprocess split in `includes/*.theme`
- `ildeposito.theme` — solo auto-loader degli includes, NON aggiungere hook qui

### Convenzioni PHP

- Sempre `declare(strict_types=1);`
- Typed properties, readonly dove possibile, match expressions, enums
- Hook OOP con attributi `#[Hook]` (Drupal 11.1+)
- PHPStan installato — non introdurre nuovi errori

## Frontend — Astro 6

### Path principali

```
frontend/
├── astro.config.mjs                     # output static + adapter Node, site www.ildeposito.org, sitemap, PDF generator
├── package.json                         # Astro 6.4, Tailwind v4, DaisyUI v5, Node >= 22.12
├── tsconfig.json                        # extends astro/tsconfigs/strict
├── pagefind.toml                        # force_language = "it"
├── Dockerfile                           # Node 22-alpine (stage/prod builder)
├── docker-entrypoint.sh                 # Build con release versionata
├── compose.stage.yml                    # Builder + frontend-api (SSR) + frontend-web (nginx)
├── nginx.conf                           # Serving statico + proxy /api/ → frontend-api
├── nginx-ratelimit.conf                 # Zone rate limit (html, api, pdf)
├── nginx-security-headers.conf          # Security headers condivisi
├── .env                                 # DRUPAL_API_URL
└── src/
    ├── pages/                           # Route file-based (SSG)
    │   ├── index.astro, 404.astro, cerca.astro, rss.xml.js
    │   ├── [...percorso].astro          # Catch-all fallback
    │   ├── canti/, autori/, eventi/     # Contenuti principali (index + elenco + [slug])
    │   ├── traduzioni/, lingue/, localizzazioni/, periodi/, tags/
    │   └── calendario-cantato/          # index + [giorno].astro
    ├── components/
    │   ├── base/                        # Header, Footer, EventBar, SEO
    │   └── ui/                          # CantoCard, AuthorHeader, Breadcrumb, EventCard,
    │                                    #   MonthCalendar, PagefindList, ShareModal
    ├── layouts/
    │   └── BaseLayout.astro             # Layout master (data-theme="ildeposito")
    ├── lib/
    │   ├── api/drupal/                  # Client JSON:API + data fetchers per content type
    │   │   ├── client.ts                # fetchJsonApi, fetchAllJsonApi (paginazione automatica)
    │   │   ├── mappers.ts               # JSON:API response → interfacce frontend
    │   │   ├── resolvers.ts             # buildIncludedMap, resolveRefs, extractSlug
    │   │   ├── assets.ts                # URL immagini da media/file Drupal
    │   │   ├── canti.ts, autori.ts, eventi.ts, traduzioni.ts, tassonomie.ts, informazioni.ts
    │   │   └── index.ts                 # Re-export unificato
    │   ├── api/types.ts                 # Interfacce TypeScript (backend-agnostic)
    │   ├── schema.js                    # JSON-LD structured data (WebSite, Person, MusicComposition, Event)
    │   ├── seo.js                       # buildTitle, buildDescription, buildCanonical, stripHtml
    │   ├── generate-pdf.js              # Export PDF per canto (A4, QR code, branding)
    │   └── generate-qrcode.js           # QR code SVG con logo ilDeposito
    ├── integrations/
    │   └── pdf-generator.js             # Hook astro:build:done — genera PDF per ogni canto
    ├── scripts/                         # Client-side JS (web components)
    │   ├── event-carousel.js, image-carousel.js
    │   ├── event-map.js, events-map.js  # Leaflet + MarkerCluster
    │   ├── nav-hamburger.js, search-modal.js
    │   ├── share-button.js, song-lyrics.js
    │   └── filter-list.js
    ├── styles/
    │   └── main.css                     # Tailwind v4 + DaisyUI v5 + design tokens + fonts
    └── assets/
```

### Comandi

```bash
cd frontend
npm run dev          # Dev server (porta 4321)
npm run build        # Build statica + Pagefind
npm run preview      # Preview della build
```

### Rendering ibrido (SSG + SSR on-demand)

`astro.config.mjs` usa `output: 'static'` con l'adapter `@astrojs/node` (`mode: 'standalone'`). Tutte le pagine sono **prerenderizzate a build time** (SSG); solo gli endpoint che dichiarano `export const prerender = false` girano lato server (SSR) a runtime:

- `src/pages/api/altcha.ts` — challenge ALTCHA (captcha proof-of-work)
- `src/pages/api/modulo_contatti.ts` — submit del form contatti verso Drupal JSON:API

**Infrastruttura stage/prod:**
- `frontend-web` (nginx) serve i file statici da `current/client` e fa da reverse proxy di `/api/*` verso `frontend-api`
- `frontend-api` (Node, porta 4321) esegue `server/entry.mjs` per gli endpoint SSR
- nginx passa `X-Forwarded-Proto` al Node; `security.checkOrigin` è **disabilitato** perché dietro proxy il Node adapter costruisce sempre `url.origin` come `http://` — l'anti-spam è delegato al rate limit nginx (`/api/`, zona `api`)

**Env aggiuntive SSR** (solo `frontend-api`, non usate a build time): `DRUPAL_API_USER`, `DRUPAL_API_PASS` (auth JSON:API write), `ALTCHA_HMAC_KEY`.

### API Client (`src/lib/api/drupal/`)

Pattern **mapper**: ogni content type ha un file dedicato che fetcha da JSON:API e passa i dati attraverso `mappers.ts` per produrre interfacce TypeScript pulite definite in `types.ts` (backend-agnostic).

**Slug caching**: Drupal richiede UUID ma gli URL usano slug — il client mantiene una cache slug→UUID per evitare query N+1.

**Paginazione**: `fetchAllJsonApi()` segue automaticamente i link `next` della risposta JSON:API.

### Convenzioni frontend

- **Tailwind v4** con `@theme` per design tokens — utility classes prima di CSS custom
- **DaisyUI v5** — tema custom `ildeposito` (attivato via `data-theme` in BaseLayout)
- **Font locali** via @fontsource: Bitter (serif, titoli), Source Sans 3 (sans, body), IBM Plex Mono (mono)
- **Icone:** Phosphor Icons (`@phosphor-icons/web`) — `<i class="ph ph-music-note"></i>`
- **TypeScript strict** — interfacce in `types.ts` sono backend-agnostic
- **Pagefind** per ricerca full-text statica (italiano, `force_language = "it"`)
- **Leaflet** per mappe eventi (OpenStreetMap, MarkerCluster)
- **Client-side JS** — web components vanilla, nessun framework di hydration
- Componenti Astro (`.astro`) per tutto ciò che è statico
- Pagine in `src/pages/` seguono la struttura URL del sito

### Design tokens

```
primary:   oklch(0.45 0.165 27)   — rosso scuro #9E1B1B
secondary: oklch(0.63 0.111 86)   — marrone dorato #A8842C
neutral:   oklch(0.35 0.025 40)   — grigio scuro
base-100:  oklch(0.96 0.013 92)   — pergamena #f4f1e8
```

### Env

```
DRUPAL_API_URL=http://ildeposito11.ddev.site   # Locale (DDEV)
DRUPAL_API_URL=http://drupal-api:80            # Stage/Prod (rete Docker interna)
```

Solo runtime SSR (`frontend-api`): `DRUPAL_API_USER`, `DRUPAL_API_PASS`, `ALTCHA_HMAC_KEY`.

### Build output

- `client/` — HTML statico pre-renderizzato per tutte le pagine + asset
- `server/entry.mjs` — server Node per gli endpoint SSR (`/api/*`)
- PDF individuali per ogni canto (`client/pdf/canti/{slug}.pdf`)
- Indice di ricerca Pagefind (`client/pagefind/`)

## Regole di sviluppo

### Generali

- Codice prima delle spiegazioni, no preamboli
- Commenti: spiegare il PERCHE, non il COSA
- No inline styles, no jQuery

### Backend (Drupal)

1. **SDC first** — preferire SDC components ai partial Twig
2. **Bootstrap utilities first** — classi BS5 prima di CSS/SCSS custom
3. **BEM per CSS custom** — scoped al componente (`.card-canto__title`)
4. **Preprocess per logica** — logica complessa nei file `includes/*.theme`, mai in Twig
5. **Template overrides** — solo in `ildeposito/templates/`, mai toccare template Radix base
6. **JS con Drupal behaviors + once():**
   ```js
   Drupal.behaviors.ilDepositoName = {
     attach(context, settings) {
       once('ildeposito-name', '.selector', context).forEach((el) => { ... });
     }
   };
   ```

### Frontend (Astro)

1. Componenti Astro (`.astro`) per tutto cio che e statico
2. Data fetching in `src/lib/api/drupal/` — un file per content type
3. Mapper pattern: JSON:API response → interfacce TypeScript pulite (`mappers.ts`)
4. Pagine in `src/pages/` seguono la struttura URL del sito
5. Web components vanilla per interattivita client-side (no framework)

## Struttura Git

- Branch principale: `main`
- Monorepo: `backend/` e `frontend/` nella stessa repo
- Deploy staging: automatico su push a `main` (GitHub Actions, self-hosted runner)
- `.gitignore` esclude: `.ddev/`, `.vscode/`, `.devcontainer/`, `.env`, `backup/`, `*.code-workspace`, `.DS_Store`
