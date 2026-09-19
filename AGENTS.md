# AGENTS.md — ilDeposito.org

Monorepo: `backend/` Drupal 11 + PHP 8.3 (headless CMS, JSON:API) → `frontend/` Astro 7.3 (SSG at build time). No client-side content fetching.

- Detail on demand: `docs/frontend.md` (routes, data layer, SSR), `docs/backend.md` (custom modules, firewall), `CODEX.md` (ops), `CLAUDE.md` (long reference). Read closest `package.json` / `composer.json` before running commands.
- Context graph: prefer `graft ask "<question>" --source` / `graft callers <symbol>` / `graft skeleton <file>` over broad grep. After big code changes run `graft build`.

## Shell + permissions (`opencode.json`, `RTK.md`)

- Prefix shell commands with `rtk` (e.g. `rtk git status`, `rtk ddev drush status`). `rtk proxy` only when output must not be filtered.
- `bash` defaults to ask; allowed: `graft*`, `rtk *`, `ddev*`, `git`, `ls`, read-only `pwd/uname/date/which/command -v/cat/head/tail/wc`, `./local.sh*`, `./ildeposito.sh*`. `git commit` / `git push` are denied — never commit/push.
- Commands outside the working directory always require authorization (`external_directory: ask`): stay in the repo root, never set `workdir` elsewhere or touch outside paths without asking first.

## Environments — never mix stacks

| Context | Stack | Wrapper only |
|---|---|---|
| Local | DDEV (PHP/MariaDB/Redis/nginx, docroot `backend/web`) | `./local.sh …` + `ddev …` |
| Stage/prod (server, `.env` `ENV=stage\|prod`) | Docker4Drupal/Wodby Compose + Caddy | `./ildeposito.sh …` |

- Local: never `docker compose`, `docker`, `make`, `ildeposito.sh`, or host `php/composer/drush/node/npm`. All PHP commands and proofs run inside DDEV (host PHP version differs): `ddev exec php -l …`, `ddev exec php -r …`, `ddev drush php:eval …`, never bare `php`. Example: `ddev drush cr`, `ddev composer install`, `ddev exec --dir /var/www/html/frontend npm run build`.
- Stage/prod: never DDEV or bare `docker compose`; always `./ildeposito.sh drush|composer|build-frontend|up …`.
- Single repo-root `.env` (Astro `vite.envDir: '../'`). `DRUPAL_API_URL=http://ildeposito11.ddev.site` local, `http://drupal-api:80` stage/prod (hardcoded in compose). `DRUPAL_API_USER/PASS`, `ALTCHA_HMAC_KEY` are SSR-runtime only (`frontend-api`), not build-time.

## Commands

```bash
./local.sh up | build | canzonieri | linkcheck | outdated | upgrade <backend|frontend> | allinea <stage|prod>
./ildeposito.sh up | build-frontend | drush <args> | composer <args> | migrate | deploy --ref <ref> | pipeline <deploy|content|full|pdf|redirect>
./deploy.sh stage   # deploy main→stage (requires clean main == origin/main)
./deploy.sh prod    # requires successful stage run on same SHA, then tags v* + Release
# frontend (run via ddev exec --dir /var/www/html/frontend locally)
npm run build            # astro build && pagefind --site dist/client
npm run build:content    # SKIP_PDF=1, faster content-only build
npm run test:unit        # node --test tests/chords.test.mjs (+ tests/schema.test.mjs)
npm run test:e2e         # playwright test
```

- `./local.sh build` counts published nodes, wipes Vite cache, preserves `dist/client/pdf/canzonieri` across the build (Vite empties `dist/`), writes `dist/.build-complete`, restarts astro-node. Run `./local.sh canzonieri` after build to regenerate songbooks.
- Deploy: `stage.yml`/`prod.yml` are thin; real logic is `ildeposito.sh pipeline`. Prod deploys only `v*` tags descended from `main`, only after green stage on same SHA. Don't rename workflows without updating `GitHubWorkflowClient` in `ildeposito_build`.

## Frontend gotchas (`frontend/src/`)

- Hybrid: `output: 'static'` + Node adapter standalone. Only `src/pages/api/*` (`altcha.ts`, `modulo_contatti.ts`, `export const prerender = false`) run SSR on `frontend-api:4321`; nginx `frontend-web` serves `current/client` and proxies `/api/*`. `security.checkOrigin` is off (adapter sees `http://` behind proxy); anti-spam is nginx rate-limit — don't touch API URL, proxy, rate-limit, SSR auth without deploy review.
- Data layer `src/lib/api/drupal/`: one file per content type → `mappers.ts` → backend-agnostic `types.ts`. Never bypass mappers/types, never fetch content client-side. `fetchAllJsonApi` follows `next` links; Drupal caps `page[limit]` at 50; global concurrency gate is 4 (matches `build.concurrency: 4` in `astro.config.mjs`).
- Build integrations generate per-canto PDFs (`client/pdf/canti/`), Pagefind index (`force_language = "it"`), sitemap lastmod, CSP hashes. Don't remove Pagefind, sitemap, PDF, CSP, or Node adapter without infra changes.
- Style: Tailwind v4 `@theme` tokens + DaisyUI `ildeposito` theme (`data-theme` in `BaseLayout`), fonts via fontsource (Bitter/Source Sans 3/Plex Mono), Phosphor icons, `.astro` for static UI, vanilla web components in `src/scripts/` (no hydration framework), no inline styles/jQuery.

## Backend gotchas (`backend/web/`)

- Editor-only: `ildeposito_utils` firewall (`AnonymousLoginRedirect`, `JsonApiWriteFirewall`) blocks anon admin paths; public surface is `/jsonapi`, `/api/*`, `/system/files`, login only.
- PHP: `declare(strict_types=1)`, `final` classes, `readonly`, `match`/enums, OOP `#[Hook]` + constructor DI. Theme `ildeposito`: logic in `includes/*.theme` (never Twig, never add hooks to `ildeposito.theme`), SDC first, Bootstrap utilities before custom CSS, BEM scoped, JS via `Drupal.behaviors` + `once()`.
- Config sync `sites/default/config/`; Redis covers all bins except `form` (stays DB). Don't bulk-edit exported config or legacy `migrando/` migrations without rollback plan. `migrando` is one-time legacy scaffolding (old style, no strict types).
- Stage/prod `build-frontend full|content|pdf|canzonieri` + `build-redirect` (prod only) write versioned `releases/$TIMESTAMP`, symlink `current`, keep 7; nginx single-file bind mounts need container recreate (not reload) when conf changes — handled in `ildeposito.sh`, don't shortcut it.
