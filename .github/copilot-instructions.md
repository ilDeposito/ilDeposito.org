# GitHub Copilot instructions for ilDeposito.org

## Project overview

This repository is a monorepo for ilDeposito.org, an online archive of Italian songs of political and social protest.

- Backend: Drupal 11 in the `backend/` directory
- Frontend: Astro 6 in the `frontend/` directory
- Content is exposed by Drupal via JSON:API and consumed by Astro at build time
- The frontend uses a hybrid rendering model: static pages are prerendered, while selected SSR endpoints remain server-rendered on demand

## Architecture

### Backend (Drupal 11)

- Main code lives in `backend/web/`
- Prefer SDC components over Twig partials
- Use Bootstrap 5 utilities before custom CSS
- Keep custom CSS scoped with BEM-style class names
- Put complex logic in `includes/*.theme` files, not in Twig
- Avoid modifying Radix base templates; use theme overrides in `backend/web/themes/custom/ildeposito/templates/` only
- Follow Drupal 11 conventions:
  - Always use `declare(strict_types=1);`
  - Prefer typed properties and readonly where appropriate
  - Use `match` expressions and enums where appropriate
  - Use Drupal 11 hook attributes like `#[Hook]`
  - Do not introduce PHPStan errors

### Frontend (Astro 6)

- Main code lives in `frontend/src/`
- Use Astro components for mostly static UI
- Keep data fetching logic in `frontend/src/lib/api/drupal/` with one file per content type
- Follow the mapper pattern: JSON:API response -> TypeScript interfaces in `types.ts`
- Keep pages in `frontend/src/pages/` aligned with the site URL structure
- Use vanilla web components for client-side interactivity; avoid frontend frameworks
- Follow the project styling stack:
  - Tailwind v4
  - DaisyUI v5
  - Local fonts via `@fontsource`
  - Phosphor icons
- **SEO & Structured Data (Schema.org / JSON-LD):**
  - Implement conservative refactoring: integrate existing props and structures without breaking current fetch/routing logic
  - Respect domain-specific semantics (`MusicComposition`, `Person`, `MusicGroup`, `HistoricalEvent`, `DefinedTerm`, `CollectionPage`)
  - Use `@graph` arrays for JSON-LD script blocks to cleanly link nodes and taxonomy terms (`about`, `genre`, `category`, `author`)
  - Ensure Open Graph (`og:*`) and Twitter metadata match the entity types and absolute domain URLs

## Token Efficiency & Response Behavior

- **Be concise and direct:** Prefer minimal text explanations. Output code directly without conversational filler, intros, or summaries at the end.
- **Refactoring style:** Refactor and extend existing code in place. Do NOT rewrite whole files or architectures unless explicitly requested.
- **Explain non-obvious choices in a single sentence or inline code comment.**

## Development conventions

- Prefer code changes over long explanations
- Do not use inline styles
- Do not use jQuery
- Keep changes aligned with the existing architecture and conventions

## Environment and deployment

- Local development is managed through `./local.sh` and DDEV
- Staging/production are managed through `./ildeposito.sh` and Docker Compose
- Respect the existing environment-specific configuration for Drupal and Astro
- Do not introduce breaking changes in the SSR API endpoints or deployment flow

## Important paths

- Backend config: `backend/web/sites/default/`
- Custom Drupal theme: `backend/web/themes/custom/ildeposito/`
- Astro pages: `frontend/src/pages/`
- Astro API layer: `frontend/src/lib/api/drupal/`