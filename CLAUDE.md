# ilDeposito.org

Archivio online di canti di protesta politica e sociale italiani.

## Architettura

Monorepo con due applicazioni indipendenti:

| Componente | Path | Stack | Ruolo |
|---|---|---|---|
| **Backend** | `backend/` | Drupal 11 + PHP 8.3 | CMS, JSON:API, admin |
| **Frontend** | `frontend/` | Astro 6 (SSG) + Tailwind v4 + DaisyUI v5 | Sito pubblico statico |

Il frontend consuma il backend via **Drupal JSON:API** (`/jsonapi/node/canto`, ecc.) a build time (output: `static`). Non c'è SSR.

## Ambiente locale

### `./local.sh` — Script unico di gestione

```bash
./local.sh up          # Avvia DDEV (Drupal + MariaDB + Solr + Memcached)
./local.sh stop        # Arresta l'ambiente
./local.sh restart     # Riavvia l'ambiente
./local.sh build       # Build statica Astro con progresso % sui nodi
./local.sh allinea     # (WIP) Allinea DB da produzione a locale
```

### DDEV

```bash
ddev drush cr               # Cache rebuild Drupal
ddev drush uli              # Login link admin
```

- **URL Drupal:** https://ildeposito11.ddev.site
- **URL Frontend statico:** https://frontend.ildeposito11.ddev.site (nginx che serve `frontend/dist/`)
- **URL Frontend dev:** https://ildeposito11.ddev.site:4322 (Astro dev server, porta esposta via DDEV)
- **DB:** MariaDB 10.11 (`drupal` / `drupal` / `drupal`)
- **PHP:** 8.3 (dentro container DDEV)
- **Servizi extra:** Solr, Memcached, phpMyAdmin

### Docker Compose locale (alternativa a DDEV)

Il file `backend/.env` configura i tag delle immagini Docker4Drupal (Wodby). Usare `make up` / `make down` da `backend/`.

## Backend — Drupal 11

### Path principali

```
backend/
├── composer.json
├── Makefile                          # make drush, make composer, make shell
├── web/
│   ├── modules/custom/
│   │   ├── ildeposito_raw/           # Dati grezzi entità → JSON:API / cache
│   │   ├── ildeposito_utils/         # Utility, Twig extensions, plugin
│   │   └── migrando/                 # Migrazioni (import dati)
│   └── sites/default/settings.php
└── drush/
```

### Vocabolario del dominio

| Content type | Tassonomie |
|---|---|
| `canto`, `autore`, `evento`, `traduzione`, `pagina` | `lingue`, `localizzazioni`, `periodi`, `tags`, `tematiche` |

### Convenzioni PHP

- Sempre `declare(strict_types=1);`
- Typed properties, readonly dove possibile, match expressions, enums
- PHPStan installato — non introdurre nuovi errori
- Hook OOP con attributi `#[Hook]` (Drupal 11.1+)

## Frontend — Astro 6

### Path principali

```
frontend/
├── src/
│   ├── pages/                        # Route file-based
│   │   ├── canti/, autori/, eventi/, traduzioni/
│   │   ├── lingue/, localizzazioni/, periodi/, tags/
│   │   ├── calendario-cantato/
│   │   ├── cerca.astro               # Ricerca (Pagefind)
│   │   └── index.astro
│   ├── components/
│   │   ├── base/                     # Componenti base
│   │   └── ui/                       # Componenti UI
│   ├── layouts/BaseLayout.astro
│   ├── lib/
│   │   ├── api/drupal/               # Client JSON:API + data fetchers
│   │   │   ├── client.ts             # fetchJsonApi, fetchAllJsonApi
│   │   │   ├── mappers.ts            # JSON:API → interfacce frontend
│   │   │   ├── canti.ts, autori.ts, eventi.ts, traduzioni.ts, ...
│   │   │   └── assets.ts, resolvers.ts
│   │   ├── api/types.ts              # Interfacce TypeScript (backend-agnostic)
│   │   ├── schema.js                 # JSON-LD structured data
│   │   ├── seo.js
│   │   ├── generate-pdf.js
│   │   └── generate-qrcode.js
│   ├── integrations/                 # Astro integrations custom
│   ├── scripts/                      # Client-side JS
│   ├── styles/main.css               # Tailwind v4 + DaisyUI + design tokens
│   └── assets/
├── astro.config.mjs
├── pagefind.toml                     # Ricerca statica (force_language = "it")
└── tsconfig.json                     # extends astro/tsconfigs/strict
```

### Comandi

```bash
cd frontend
npm run dev          # Dev server (porta 4321)
npm run build        # Build statica + Pagefind
npm run preview      # Preview della build
```

### Convenzioni frontend

- **Tailwind v4** con `@theme` per design tokens — usare utility classes prima di CSS custom
- **DaisyUI v5** per componenti UI (tema custom "ildeposito" definito in `main.css`)
- **Font locali** via @fontsource: Bitter (serif, titoli), Source Sans 3 (sans, body), IBM Plex Mono (mono)
- **Icone:** Phosphor Icons (`@phosphor-icons/web`) — `<i class="ph ph-music-note"></i>`
- **TypeScript strict** — interfacce in `src/lib/api/types.ts` sono backend-agnostic
- **Pagefind** per ricerca full-text statica (italiano)

### Env

```
DRUPAL_API_URL=http://ildeposito11.ddev.site   # In locale punta al DDEV
```

## Regole di sviluppo

### Generali

- Codice prima delle spiegazioni, no preamboli
- Commenti: spiegare il PERCHÉ, non il COSA
- No inline styles, no jQuery — vanilla JS o Bootstrap 5 JS (backend) / framework JS (frontend)

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

1. Componenti Astro (`.astro`) per tutto ciò che è statico
2. Data fetching in `src/lib/api/drupal/` — ogni file per content type
3. Mapper pattern: JSON:API response → interfacce TypeScript pulite (`mappers.ts`)
4. Pagine in `src/pages/` seguono la struttura URL del sito

## Struttura Git

- Branch principale: `main`
- Monorepo: `backend/` e `frontend/` nella stessa repo
- `.gitignore` esclude: `.ddev/`, `.vscode/`, `.devcontainer/`, `*.code-workspace`, `.DS_Store`
