# ilDeposito.org

## Project Overview & Stack

- Headless monorepo: Drupal 11/PHP 8.3 (`backend/`) + Astro 6 (`frontend/`).
- Drupal JSON:API; Astro SSG con SSR solo per `/api/*`.
- Frontend: Node >=22.12, TypeScript strict, Tailwind v4, DaisyUI v5, Pagefind, Playwright.
- Backend: Composer/Drush 13, MariaDB, Redis; DDEV locale, Docker Compose stage/prod.
- Config principali: `.ddev/config.yaml`, `compose.yml`, `backend/composer.json`, `frontend/astro.config.mjs`.

## Context Discipline

`CODEX.md` is the default operational context. Read only the smallest relevant
document below when the task needs detail; do not load `CLAUDE.md` by default
(it is the long-form reference for other assistants and duplicates much of this
file).

| Need | Read on demand |
|---|---|
| Astro routes, data layer, SSR, build output | `docs/frontend.md` |
| Drupal modules, firewall, editor workflows | `docs/backend.md` |
| Local/stage/prod operations and infrastructure | the relevant section of `CLAUDE.md` |
| A package command or dependency | the closest `package.json` or `composer.json` |

- Search narrowly first (`rtk rg --files <area>`), then open only matched files.
- Inspect existing scripts/tests before running a build; verify only the affected
  area unless a full build is required.
- Keep replies and code comments concise; do not restate repository context.

## Key File Structure

```text
backend/
  web/modules/custom/       # moduli runtime
  web/themes/custom/        # tema admin
  web/sites/default/        # settings + config YAML
  composer.json, Makefile, compose*.yml
frontend/
  src/pages/                # route Astro file-based; `api/` = SSR
  src/components/           # base/, detail/, paragraphs/, ui/
  src/lib/api/drupal/       # client JSON:API, mapper, fetcher per tipo
  src/layouts/, styles/, scripts/, integrations/
  package.json, astro.config.mjs, tests/
docs/                       # documentazione tecnica
local.sh                    # workflow DDEV locale
ildeposito.sh               # workflow stage/prod
```

## Code Style & Conventions

- PHP: `declare(strict_types=1)`, classi `final`, tipi; preferire `readonly`.
- Drupal: hook OOP `#[Hook]`, DI nel costruttore, errori espliciti con contesto.
- Tema: logica in `includes/*.theme`, non Twig; SDC e utility Bootstrap prima di CSS.
- CSS Drupal BEM scoped; JS Drupal con behaviors + `once()`.
- Astro per UI statica; interattività JS/Web Components vanilla, senza hydration framework.
- TypeScript strict: tipi backend-agnostic in `src/lib/api/types.ts`; JSON:API passa dai mapper.
- Aggiungere fetcher per content type in `src/lib/api/drupal/`; rispettare cache e limite di 4 fetch.
- Tailwind prima di CSS custom; usare DaisyUI `ildeposito` e token esistenti.
- Commenti solo per spiegare il perché; nessun inline style o jQuery.

## Essential Commands

```bash
# install e avvio locale
ddev start
ddev composer install
cd frontend && npm ci && npm run dev

# avvio completo Drupal + Astro
./local.sh up

# build e controlli frontend
cd frontend && npm run build
cd frontend && npm run preview
cd frontend && node --test tests/schema.test.mjs
cd frontend && npm run test:e2e

# Drupal locale
ddev drush cr
ddev drush status

# stage/prod
./ildeposito.sh up
./ildeposito.sh build-frontend
```

## Constraints

- Non committare `.env`, segreti o output/cache (`dist/`, `.astro/`, `vendor/`, `node_modules/`).
- Non cambiare API URL, proxy/rate limit nginx, sicurezza SSR o limite fetch senza valutare deploy.
- Non rimuovere Pagefind, sitemap, PDF/CSP o adapter Node senza aggiornare l'infrastruttura.
- Non mettere logica in Twig, hook in `ildeposito.theme`, override nei temi Radix base.
- Non bypassare mapper/tipi né introdurre fetch client-side dei contenuti.
- Non modificare config Drupal esportata o migration legacy in massa senza rollback.
