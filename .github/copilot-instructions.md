# ilDeposito.org — istruzioni per GitHub Copilot

Archivio online di canti di protesta politica e sociale italiani.

## Architettura

Monorepo con due applicazioni indipendenti:

- **`backend/`** — Drupal 11 + PHP 8.3, headless: espone contenuti via JSON:API
- **`frontend/`** — Astro 6 + Tailwind v4 + DaisyUI v5, li consuma a build time

Rendering ibrido: `output: 'static'` prerenderizza tutte le pagine (SSG), ma l'adapter Node abilita alcuni endpoint server on-demand (SSR) solo per il form contatti (`src/pages/api/*.ts` con `export const prerender = false`).

## Vocabolario del dominio

- **Content type:** `canto`, `autore`, `evento`, `traduzione`, `pagina`
- **Tassonomie:** `lingue`, `localizzazioni`, `periodi`, `tags`, `tematiche`

## Regole generali

- Codice prima delle spiegazioni, no preamboli
- Commenti: spiegare il PERCHÉ, non il COSA
- No inline styles, no jQuery

## Backend — Drupal 11 (`backend/web/`)

Backend interamente **headless**: non esiste alcun tema custom né rendering pubblico lato Drupal. Gli editor autenticati lavorano con **Gin** (tema admin contrib, sia `default` che `admin` theme) — niente Twig/SDC/CSS da scrivere per il pubblico, il pubblico tocca solo `/jsonapi`, `/api/*`, `/system/files`. Un firewall custom (`ildeposito_utils`) blocca gli anonimi da ogni altra rotta.

Convenzioni PHP:
- Sempre `declare(strict_types=1);`
- Typed properties, readonly dove possibile, match expressions, enums
- Hook OOP con attributi `#[Hook]` (Drupal 11.1+)
- PHPStan installato — non introdurre nuovi errori

Moduli custom (`web/modules/custom/`):
- **`ildeposito_build`** — pulsante "Pubblica contenuti", triggera/monitora build GitHub Actions del frontend Astro
- **`ildeposito_contatti`** — entità custom per submission del form contatti (scritte via JSON:API)
- **`ildeposito_redirects`** — redirect da URL legacy + report 404
- **`ildeposito_stats`** — sync statistiche di visualizzazione da Umami self-hosted
- **`ildeposito_utils`** — firewall accesso headless, dashboard editor, utility editoriali varie
- **`migrando`** — migrazione one-time da Drupal 8 legacy; stile più datato (no `strict_types`), non prenderlo come riferimento per nuovo codice

## Frontend — Astro 6 (`frontend/src/`)

1. Componenti `.astro` per tutto ciò che è statico
2. Data fetching in `src/lib/api/drupal/` — un file per content type (`canti.ts`, `autori.ts`, `eventi.ts`, ...)
3. Pattern **mapper**: risposta JSON:API → interfacce TypeScript pulite e backend-agnostic (`types.ts`, `mappers.ts`)
4. Pagine in `src/pages/` seguono la struttura URL del sito
5. Web components vanilla per interattività client-side — **nessun framework di hydration**
6. TypeScript strict (estende `astro/tsconfigs/strict`)

Convenzioni stile:
- **Tailwind v4** con `@theme` per design tokens — utility classes prima di CSS custom
- **DaisyUI v5** — tema custom `ildeposito` (via `data-theme` in `BaseLayout.astro`)
- **Icone:** Phosphor Icons (`<i class="ph ph-music-note"></i>`)
- **Pagefind** per ricerca full-text statica (italiano)

## Anti-pattern da evitare

- ❌ Inline styles
- ❌ jQuery
- ❌ CSS custom quando esiste già una utility Tailwind
- ❌ Codice/rendering per un tema Drupal pubblico (non esiste più — il backend è headless)
- ❌ Framework di hydration lato frontend (React/Vue/Svelte islands)
