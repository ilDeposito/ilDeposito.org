# Frontend — Astro 6

## Architettura

Sito pubblico decoupled: **Astro 6** con `output: 'static'` (SSG) e adapter `@astrojs/node` (`mode: 'standalone'`). Tutte le pagine sono prerenderizzate a build time; solo due endpoint girano lato server on-demand (SSR), dichiarando `export const prerender = false`:

- `src/pages/api/altcha.ts` — challenge ALTCHA (captcha proof-of-work)
- `src/pages/api/modulo_contatti.ts` — submit del form contatti verso Drupal JSON:API (scrittura)

Il resto del sito consuma dati **una tantum a build time** dal backend Drupal via JSON:API — nessuna chiamata client-side ai contenuti.

Stack: Tailwind v4, DaisyUI v5 (tema custom `ildeposito`), TypeScript strict, nessun framework di hydration (web components vanilla).

## Infrastruttura stage/prod

- `frontend-web` (nginx) serve i file statici da `current/client` e fa da reverse proxy di `/api/*` verso `frontend-api`
- `frontend-api` (Node, porta 4321) esegue `server/entry.mjs`, unico responsabile degli endpoint SSR
- Deploy zero-downtime: il builder genera in `releases/$TIMESTAMP`, symlink `current` → ultima release, mantiene le ultime 7
- `security.checkOrigin` disabilitato in `astro.config.mjs`: dietro il proxy nginx il Node adapter costruisce sempre `url.origin` come `http://` (non legge `X-Forwarded-Proto`), quindi l'anti-spam è delegato al rate limit nginx sulla zona `api`

## Data layer (`src/lib/api/drupal/`)

Client JSON:API verso Drupal, un file per content type + moduli di supporto:

| File | Ruolo |
|---|---|
| `client.ts` | `fetchJsonApi` / `fetchAllJsonApi` (paginazione automatica via link `next`), gate di concorrenza globale a 4 richieste simultanee verso Drupal (oltre satura il pool PHP-FPM → 502) |
| `store.ts` | Cache in-memory (una Promise per content type) di tutte le collezioni raw, "riscaldate" in parallelo al primo accesso (`triggerWarmAll`) — evita rifetch ripetuti durante la build statica |
| `mappers.ts` | JSON:API response → interfacce TypeScript pulite (`types.ts`, backend-agnostic) |
| `resolvers.ts` | `buildIncludedMap`, `resolveRefs`, `extractSlug` — risoluzione relazioni JSON:API tramite `included` |
| `assets.ts` / `media.ts` | URL immagini/media da entità Drupal `media`/`file` |
| `canti.ts`, `autori.ts`, `eventi.ts`, `traduzioni.ts`, `tassonomie.ts`, `pagine.ts`, `informazioni.ts` | Fetcher + mapping per singolo content type |
| `index.ts` | Re-export unificato |

**Slug caching**: Drupal richiede UUID ma gli URL usano slug — cache slug→UUID per evitare query N+1.

**Paginazione**: `page[limit]` è cappato da Drupal a 50 indipendentemente dal valore richiesto; `fetchAllJsonApi` legge il passo reale dall'offset del link `next` e lancia più pagine in parallelo (wave da 4) preservando l'ordine.

## Pagine (`src/pages/`)

Route file-based, SSG:

- `index.astro`, `404.astro`, `cerca.astro` (ricerca Pagefind), `rss.xml.js`, `robots.txt.ts`
- `[...percorso].astro` — catch-all fallback
- `canti/`, `autori/`, `eventi/` — content type principali (index + elenco + `[slug]`)
- `traduzioni/`, `lingue/`, `localizzazioni/`, `periodi/`, `tags/` — tassonomie
- `calendario-cantato/` — index + `[giorno].astro`
- `informazioni/` — pagine editoriali statiche
- `api/` — i due endpoint SSR (vedi sopra)

## Componenti

- `components/base/` — Header, HeaderWatermark, Footer, EventBar, SEO
- `components/ui/` — CantoCard, AuthorHeader, Breadcrumb, EventCard, MonthCalendar, MoreLikeThis, PagefindList, SegnalazioneModal, ShareModal, Icon

## Script client-side (`src/scripts/`)

Web components vanilla, nessun framework: `event-carousel.js`, `image-carousel.js`, `event-map.js` / `events-map.js` (Leaflet + MarkerCluster), `nav-hamburger.js`, `search-modal.js`, `share-button.js`, `song-lyrics.js`, `youtube-player.js`.

## Integrazioni build-time (`src/integrations/`)

- `pdf-generator.js` + `pdf-runner.js` + `pdf-worker.js` — hook `astro:build:done`, genera un PDF per ogni canto (A4, QR code, branding) in worker separati
- `csp-hashes.js` — genera hash CSP per script inline in build

## Altri moduli lib

- `schema.js` — JSON-LD structured data (WebSite, Person, MusicComposition, Event)
- `seo.js` — `buildTitle`, `buildDescription`, `buildCanonical`, `stripHtml`
- `generate-pdf.js` / `generate-qrcode.js` — export PDF/QR per canto

## Build output

- `client/` — HTML statico + asset per tutte le pagine
- `server/entry.mjs` — server Node per gli endpoint SSR
- `client/pdf/canti/{slug}.pdf` — PDF individuali
- `client/pagefind/` — indice di ricerca full-text (italiano, `force_language = "it"`)

## Env

```
DRUPAL_API_URL=http://ildeposito11.ddev.site   # locale (DDEV)
DRUPAL_API_URL=http://drupal-api:80            # stage/prod (rete Docker interna)
```

Solo runtime SSR (`frontend-api`, non usate a build time): `DRUPAL_API_USER`, `DRUPAL_API_PASS` (auth JSON:API in scrittura), `ALTCHA_HMAC_KEY`.

## Comandi

```bash
cd frontend
npm run dev          # dev server, porta 4321
npm run build        # build statica + Pagefind
npm run preview      # preview della build
```
