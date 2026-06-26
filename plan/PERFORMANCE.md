# Performance & Web Vitals — ilDeposito.org (Frontend Astro)

Linee guida e stato delle ottimizzazioni per PageSpeed Insights, Core Web Vitals e performance generale.

---

## Architettura attuale

Il frontend è un sito **statico SSG** (Astro 6). Tutto l'HTML è pre-renderizzato a build time. Non c'è SSR, non c'è hydration framework. Il JS client-side è limitato a web components vanilla (~200 righe totali).

---

## Ottimizzazioni implementate

### Icone — SVG inline (`@phosphor-icons/core`)

**Prima:** font Phosphor completo (~270KB font woff2 + ~200KB CSS con 1.531 classi).
**Dopo:** ~28 icone importate come SVG raw via componente `Icon.astro`. Zero font, zero CSS aggiuntivo.

- Componente: `src/components/base/Icon.astro`
- Pacchetto: `@phosphor-icons/core` (solo SVG, no font)
- Icone fill: suffisso `-fill` nel nome (es. `facebook-logo-fill`)
- In JS client-side (`PagefindList.astro`): icone inline come costanti `ICO.*`

**Aggiungere una nuova icona:**
1. Aggiungere l'import in `Icon.astro` dal path `@phosphor-icons/core/assets/{regular|fill}/{name}.svg?raw`
2. Aggiungere la entry nella mappa `icons`
3. Usare `<Icon name="nome-icona" class="text-xl" />`

### Immagini — Astro Image + WebP

Le immagini nei componenti usano `<Image>` di `astro:assets` con URL remoti Drupal.
Astro le ottimizza automaticamente (resize, conversione WebP, caching).

| Uso | Funzione | Output |
|---|---|---|
| `<Image>` in componenti | `getImageUrl(relativeUrl)` | URL assoluto Drupal → Astro ottimizza |
| OG meta tags | `getAutoreImageUrl()`, `getEventoImageUrl()`, ecc. | Download in `public/uploads/` (path locale) |

- `image.domains` in `astro.config.mjs` abilita il dominio Drupal
- Formato: `format="webp"`, `quality={75-80}`
- Dimensioni: `width`/`height` a 2x del display (es. 160x160 per display 80x80)
- `loading="eager"` + `fetchpriority="high"` solo per LCP element
- Tutto il resto: `loading="lazy"` (default)

### Font — Solo Latin + `font-display: swap`

| Font | Peso | Uso |
|---|---|---|
| Source Sans 3 | 400, 400-italic, 600 | Body, UI (`font-sans`) |
| Bitter | 400, 400-italic, 700 | Titoli, heading (`font-serif`) |
| IBM Plex Mono | 400 | Testi canti (`font-mono`) |

- Import: `@fontsource/{font}/latin-{weight}.css` (solo subset latin)
- `font-display: swap` incluso automaticamente nei file `latin-*.css`
- Preload in `BaseLayout.astro` per Source Sans 400 e Bitter 400 (i due font critici)

### ShareModal — `<template>` + clone

Il dialog di condivisione (con QR code SVG ~5.5KB) è wrappato in `<template data-share-template>`.
Non viene inserito nel DOM attivo fino al primo click. `share-button.js` clona il template on-demand.

### SearchModal — Script inline minificato

Lo script `is:inline` in `Header.astro` (necessario per il dynamic import di Pagefind) è minificato manualmente (~1KB vs ~2.5KB originale).

### JSON-LD — Blocco unico `@graph`

Tutti gli schema di una pagina sono combinati in un singolo `<script type="application/ld+json">` con `@graph`. Vedi `plan/SEO_RULES.md` per i dettagli.

### `content-visibility: auto`

Applicato alle sezioni below-the-fold per differire il rendering:
- Sezione "Scheda canto" in `canti/[slug].astro`
- Sezione "Informazioni" in `autori/[slug].astro`
- `<footer>` globale in `Footer.astro`

---

## Regole per nuovi sviluppi

### Immagini

1. **Mai mettere immagini in `public/` per rendering** — usare sempre `<Image>` con URL remoto o import da `src/assets/`
2. **`public/uploads/`** è riservato alle OG images (servono URL statici per i meta tag)
3. **Sempre `format="webp"`** e `quality` esplicita (75-80)
4. **Sempre `width`/`height` espliciti** a 2x del display effettivo
5. **`loading="lazy"`** di default; `loading="eager"` solo per above-the-fold
6. **`fetchpriority="high"`** solo sull'elemento LCP della pagina

### Icone

1. **Usare `<Icon name="..." />`** nei componenti Astro
2. **Nel JS client-side** (`is:inline`): usare costanti SVG inline (vedi pattern in `PagefindList.astro`)
3. **Mai aggiungere font di icone** — sempre SVG inline
4. **Nuova icona:** aggiungere import + entry in `Icon.astro`

### Font

1. **Solo subset `latin`** — non importare `400.css` (include tutti i subset), usare `latin-400.css`
2. **Non aggiungere nuovi font** senza valutare l'impatto — ogni font aggiunge ~10-20KB
3. **`font-display: swap`** è garantito dai file `latin-*.css` di fontsource

### JavaScript

1. **Zero framework di hydration** — solo web components vanilla
2. **`is:inline` solo quando necessario** (es. Pagefind) — tutto il resto usa moduli Astro (bundled/tree-shaken)
3. **Lazy load librerie pesanti** — Leaflet (~146KB) e MarkerCluster (~34KB) sono code-split e caricati solo nelle pagine mappa

### CSS

1. **Un singolo CSS bundle** (`BaseLayout.css`) — Tailwind v4 fa tree-shaking automatico delle utility non usate
2. **Non aggiungere CSS custom** se esiste una utility Tailwind o un componente DaisyUI equivalente
3. **`content-visibility: auto`** per sezioni below-the-fold con contenuto significativo

### Structured Data

1. **Ogni schema** restituito da `schema.js` deve includere `@context: 'https://schema.org'` (per retrocompatibilità), ma `SEO.astro` lo rimuove nel `@graph`
2. **Passare tutti gli schema come array** via prop `jsonLd` di `BaseLayout`
3. **Non creare blocchi `<script type="application/ld+json">` manuali** — usare sempre il flusso `schema.js` → `BaseLayout` → `SEO.astro`

---

## Metriche di riferimento (pre-ottimizzazione)

| Asset | Prima | Dopo (stimato) |
|---|---|---|
| CSS bundle | 213KB | ~15KB (senza Phosphor) |
| Font totali | ~800KB (tutti i subset + Phosphor) | ~80KB (solo latin) |
| Icone font (Phosphor) | ~270KB woff2 + SVG fallback | 0 (SVG inline ~8KB totali) |
| Immagini autori | ~32KB JPG 400x300 per 80x80 display | ~5KB WebP 160x160 |
| ShareModal HTML | ~5.5KB (QR SVG) nel DOM iniziale | 0 (in `<template>`, clonato on-click) |
| SearchModal inline JS | ~2.5KB | ~1KB |

---

## Cose da monitorare

- **LCP (Largest Contentful Paint):** l'H1 (font Bitter 700) è tipicamente l'LCP element nelle pagine canti. Il preload di Bitter in `<head>` è critico.
- **CLS (Cumulative Layout Shift):** il preload dei font e le dimensioni esplicite sulle immagini prevengono shift. L'header sticky ha altezza fissa.
- **FID/INP:** il JS è minimo. L'unico rischio è il lazy load di Pagefind al primo input nella search bar.
- **TTFB:** sito statico servito da nginx/Caddy → TTFB eccellente per definizione.

---

## Possibili ottimizzazioni future

- **CSS splitting per route** — attualmente un singolo CSS. Le pagine senza mappa caricano regole Leaflet (~15KB) inutilmente. Valutare `@import` condizionale o dynamic import del CSS Leaflet.
- **`<Picture>` con AVIF** — Astro supporta `<Picture>` per servire AVIF ai browser compatibili (risparmio ~30% vs WebP). Richiede più tempo di build.
- **Preconnect al dominio Drupal** — se le immagini vengono servite dal dominio Drupal in produzione, aggiungere `<link rel="preconnect">`.
- **Service Worker** — per caching offline delle pagine visitate. Utile per un archivio consultato ripetutamente.
