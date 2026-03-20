# ilDeposito — Copilot Instructions

Archive of Italian social/protest songs. Stack: **Drupal 11 + Radix 6 (Bootstrap 5.3) + SDC + Laravel Mix**, running in DDEV.

---

## Response Guidelines

- **Code first** — implementations over descriptions, no preambles.
- **Assume expertise** — Drupal 11 / Radix 6 / BS5 / Twig / SDC.
- **Show only changed code blocks**, always with full file paths.
- **Comments explain WHY**, not what.

---

## Build, Lint & Dev Commands

All frontend commands run from `web/themes/custom/ildeposito/`:

```bash
npm run dev          # Single build (development)
npm run watch        # Watch mode
npm run production   # Minified production build

npm run biome:check  # Lint + format JS (Biome, writes fixes)
npm run stylint      # Lint SCSS (Stylelint)
npm run stylint-fix  # Fix SCSS lint issues
```

**Build outputs** (never edit directly): `build/css/` and `build/js/`
- SCSS entry: `src/scss/main.style.scss` → `build/css/main.style.css`
- JS entry: `src/js/main.script.js` → `build/js/main.script.js`
- Component assets: each `components/**/*.scss` and `components/**/_*.js` compile in-place (output strips leading `_`)

**DDEV** is the dev environment:

```bash
ddev start / ddev stop
ddev drush cr          # Clear Drupal caches
ddev drush uli         # One-time login link
ddev drush ildeposito:raw-cache-warm   # (alias: ircw) Warm ildeposito_raw cache
ddev drush ildeposito:raw-cache-stats  # (alias: ircs) Cache stats
```

---

## Domain Vocabulary

- **Node types:** `canto`, `artista`, `raccolta`
- **Taxonomies:** `genere`, `periodo_storico`, `area_geografica`

---

## Theme Architecture

```
web/themes/custom/ildeposito/
├── components/          # SDC components (source of truth for UI)
├── templates/           # Twig overrides (never touch Radix base templates)
├── includes/            # Preprocess split: *.theme files auto-loaded
├── src/
│   ├── scss/            # _variables.scss (tokens), _bootstrap.scss
│   ├── js/              # main.script.js, overrides/ (Drupal core JS overrides)
│   └── assets/          # images/, icons/, videos/, fonts/ → copied to build/
├── build/               # Compiled assets — do not edit
└── ildeposito.libraries.yml
```

`ildeposito.theme` auto-includes every `includes/*.theme` file. Add preprocess functions in the relevant file there, not in `ildeposito.theme` directly.

The theme overrides several core Drupal JS files via `libraries-extend` in `ildeposito.info.yml` (ajax, checkbox, dialog, dialog.ajax, message, progress, text, validate). Overrides live in `src/js/overrides/`.

### Theme Regions

Defined in `ildeposito.info.yml` — use these names when placing blocks or wiring templates:

| Region key | Label |
|---|---|
| `navbar_branding` | Navbar branding |
| `navbar_left` | Navbar left |
| `navbar_right` | Navbar right |
| `header` | Header |
| `content` | Content |
| `page_bottom` | Page bottom |
| `footer` | Footer |

### Asset Pipeline

`webpack.mix.js` copies static assets from `src/assets/` → `build/assets/` (images, icons, videos) and `src/assets/fonts/` → `build/fonts/`. The SVG sprite used by `ildeposito:icon` lives in `src/assets/icons/sprite_fill.svg`.

---

## Frontend Rules (Critical)

1. **SDC first** — prefer SDC components over Twig partials.
2. **Bootstrap utilities first** — use BS5 classes before writing custom CSS/SCSS.
3. **BEM for custom CSS** — scoped to the component (e.g. `.card-canto__title`).
4. **Logic in preprocess** — never put formatting/business logic in Twig.
5. **Template overrides** — only in `ildeposito/templates/`, never in Radix base.
6. **Icons** — use the `ildeposito:icon` SDC component with the SVG sprite (see below). Bootstrap Icons class syntax (`<i class="bi bi-*">`) is a fallback for inline/non-component contexts only.

**Anti-patterns:** ❌ inline styles ❌ jQuery ❌ custom CSS when BS5 utility exists ❌ logic in Twig

---

## SDC Components

**Path:** `web/themes/custom/ildeposito/components/[component-name]/`

| File | Notes |
|---|---|
| `[component-name].component.yml` | Metadata + JSON schema props |
| `[component-name].twig` | Template — include `{{ attributes }}` on root when exposed |
| `[component-name].scss` | Optional, component-scoped only — compiles to `.css` in-place |
| `_[component-name].js` | Optional — must use `Drupal.behaviors` + `once()` — compiles to `.js` (no underscore) |
| `README.md` | Document props and usage |

Namespace configured: `ildeposito:` — usage:
```twig
{{ include('ildeposito:card-canto', { title: label, url: path }, with_context = false) }}
```

Minimal `.component.yml`:
```yaml
$schema: https://git.drupalcode.org/project/drupal/-/raw/10.1.x/core/modules/sdc/src/metadata.schema.json
name: Card Canto
status: stable
props:
  type: object
  properties:
    title: { type: string }
    url: { type: string }
  required: [title, url]
```

### Icon component

The `ildeposito:icon` component renders SVG icons from `build/assets/icons/sprite_fill.svg`. Use it instead of raw `<svg>` tags:

```twig
{{ include('ildeposito:icon', { name: 'music-note', class: ['text-primary', 'fs-4'] }) }}
```

Props: `name` (required, symbol ID in sprite), `class` (array of BS5 classes), `size` (CSS font-size string), `attributes`.

### Logo component

The `ildeposito:logo` component renders the site SVG logo. Use it for branding blocks:

```twig
{{ include('ildeposito:logo', { class_color: 'text-primary', size: '48px' }) }}
```

Props: `class_color` (BS5 text color class, default `''`), `size` (CSS size string, default `'64px'`), `attributes`.

---

## Preprocess -> SDC Data Flow

Prepare all props in a preprocess function, pass to SDC via Twig. Keep render caching correct when using user/time/permission-dependent values (add cache contexts/tags/max-age to `$variables['#cache']`).

```php
// web/themes/custom/ildeposito/includes/node.theme
function ildeposito_preprocess_node__canto(array &$variables): void {
  $node = $variables['node'];
  $variables['card_props'] = [
    'title' => $node->label(),
    'url'   => $node->toUrl()->toString(),
  ];
}
```

```twig
{# web/themes/custom/ildeposito/templates/node--canto--teaser.html.twig #}
{{ include('ildeposito:card-canto', card_props, with_context = false) }}
```

---

## Design Tokens

Key variables from `src/scss/base/_variables.scss` — reference these instead of hardcoding values:

| Token | Value | Usage |
|---|---|---|
| `$primary` | `#9E1B1B` | Brand red |
| `$secondary` | `#2F2F2F` | Dark gray |
| `$warning` / `$accent` | `#A8842C` | Accent gold |
| `$light` | `#F4F1E8` | Warm off-white |
| `$body-bg` | `#F4F1E8` | Page background |
| `$body-color` | `#1E1E1E` | Default text |
| `$font-family-base` | `"Source Sans 3", system-ui, sans-serif` | Body text |
| `$headings-font-family` | `"Bitter", serif` | All headings |
| `$font-monospace` | `"IBM Plex Mono", monospace` | Code |
| `$headings-font-weight` | `600` | |
| `$btn-border-radius` | `0` | Square buttons |
| `$input-border-radius` | `none` | Square inputs |

---

## JavaScript

Always use Drupal behaviors + `once()`. When a component JS needs `once()`, declare `core/once` as a dependency in its library entry in `ildeposito.libraries.yml`. The global `style` library does **not** include `core/once` — add it explicitly per-component.

```js
Drupal.behaviors.ilDepositoName = {
  attach(context, settings) {
    once('ildeposito-name', '.selector', context).forEach((el) => { /* … */ });
  }
};
```

Biome formats JS: single quotes, line width 120 (see `biome.json`). Run `npm run biome:check` before committing.

---

## Custom Modules

### `ildeposito_raw` (`web/modules/custom/ildeposito_raw/`)

Prepares structured raw entity data for Twig with a dedicated cache bin (`cache.ildeposito_raw`).

- Service: `ildeposito_raw.manager` implements `RawEntityManagerInterface`
- Hooks use `#[Hook]` attribute class `IldepositoRawHooks` (Drupal 11.1+ style)
- Template naming convention (never prefix with entity type):
  - `ildeposito-raw.html.twig` — all entities
  - `ildeposito-raw--[bundle].html.twig` — specific bundle
  - `ildeposito-raw--[bundle]--[view-mode].html.twig` — bundle + view mode
- Template variables: `entity` (Drupal entity), `data` (raw array from manager)

### `ildeposito_utils` (`web/modules/custom/ildeposito_utils/`)

Shared utilities. Includes a `search_api` processor plugin (`EntityTotalViews`) and Drush helpers.

### `migrando` (`web/modules/custom/migrando/`)

Migration tooling. Depends on `migrate`, `migrate_tools`, `migrate_plus`.

---

## PHP Standards

- `declare(strict_types=1);` in every custom module file.
- PHP 8.3+: typed properties, readonly, match, enums.
- Full type hints on all function params and return types.
- PHPStan is configured — introduce no new errors.
- Hook implementations in modules use `#[Hook]` attribute classes, not procedural `hook_*` functions.

---

## Drupal Core Reference

- Core API: `web/core/lib/Drupal/Core/`
- Namespace `Drupal\Core\` → `web/core/lib/Drupal/Core/`
- When looking up a service/interface, search `web/core/lib/` and `web/core/modules/` first.
