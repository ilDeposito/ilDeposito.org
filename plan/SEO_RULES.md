# SEO Rules — ilDeposito.org (Frontend Astro)

Riferimento dei pattern SEO implementati su ogni tipo di pagina.
Ogni title viene troncato a **53 caratteri** e concatenato con ` | ilDeposito.org` da `buildTitle()`.
Ogni description viene troncata a **155 caratteri** da `buildDescription()`.

---

## Infrastruttura comune

| Componente | File | Ruolo |
|---|---|---|
| `buildTitle()` | `src/lib/seo.js` | Tronca a 53 chars + suffisso ` \| ilDeposito.org` |
| `buildDescription()` | `src/lib/seo.js` | Strip HTML + tronca a 155 chars |
| `buildCanonical()` | `src/lib/seo.js` | URL da `Astro.url.pathname` + `Astro.site`, senza trailing slash |
| `linguaToIso()` | `src/lib/seo.js` | Mapping nome lingua italiano → codice BCP 47 |
| `resolveOgImage()` | `src/lib/seo.js` | Path relativo → URL assoluto, default `/og-default.jpg` |
| `SEO.astro` | `src/components/base/SEO.astro` | Render di title, meta, OG, Twitter Card, JSON-LD (`@graph`) |
| `BaseLayout.astro` | `src/layouts/BaseLayout.astro` | Wrapper che chiama `buildTitle`, `buildDescription`, `SEO.astro` |

### Meta tag globali (BaseLayout `<head>`)

```html
<html lang="it">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#9E1B1B">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="alternate" type="application/rss+xml" title="ilDeposito.org — Nuovi canti" href="/rss.xml">
```

### Sitemap

Generata da `@astrojs/sitemap`. Escluse: `/cerca`, `/404`.

### JSON-LD — Struttura `@graph`

Tutti gli schema JSON-LD di una pagina vengono combinati in un **singolo** blocco `<script type="application/ld+json">` con struttura `@graph`. Il `@context` viene dichiarato una sola volta a livello root.

```json
{
  "@context": "https://schema.org",
  "@graph": [
    { "@type": "MusicComposition", ... },
    { "@type": "BreadcrumbList", ... }
  ]
}
```

I singoli schema (restituiti da `schema.js`) includono `@context` per retrocompatibilità, ma `SEO.astro` lo rimuove prima di inserirli nel `@graph`.

---

## OG Image — Catena di fallback

Modulo: `src/lib/og-image.js`. Le immagini vengono scaricate e cachate in `public/uploads/{categoria}/` da `src/lib/api/drupal/assets.ts`.

| Content type | Fallback 1 | Fallback 2 | Fallback 3 |
|---|---|---|---|
| **Autore** | Foto dell'autore | Foto del primo periodo collegato | Foto random tra i periodi |
| **Canto** | Foto del primo autore | Foto del primo periodo collegato | Foto random tra i periodi |
| **Evento** | Foto dell'evento | Foto del primo periodo collegato | Foto random tra i periodi |
| **Periodo** | Foto del periodo | Foto random tra i periodi | — |
| **Tassonomie, listing, statiche** | Foto random tra i periodi | Default `/og-default.jpg` | — |

---

## 1. Homepage

**File:** `src/pages/index.astro`

| Campo | Valore |
|---|---|
| title | `Canti di protesta politica e sociale` |
| description | `Archivio online di canti di protesta politica e sociale: testi, accordi, traduzioni, autori e eventi storici.` |
| og:type | `website` (default) |
| og:image | `/og-default.jpg` |
| JSON-LD | `WebSite` (con SearchAction) + `Organization` (con sameAs social) + `BreadcrumbList` |

---

## 2. Canto — Dettaglio

**File:** `src/pages/canti/[slug].astro`
**Helper:** `buildCantoTitle(canto)`, `buildCantoDescription(canto)`

### Title (condizionale)

```
{titolo} — Testo e accordi     ← se canto.accordi è presente
{titolo} — Testo               ← se canto.accordi è null
```

### Description (composizione progressiva)

```
Testo, accordi di {titolo} di {autore1} e {autore2} ({anno}). {capoverso}   ← tutti i campi presenti
Testo, accordi di {titolo} di {autore} ({anno}). {capoverso}                ← un solo autore
Testo di {titolo} ({anno}). {capoverso}                                      ← senza accordi, senza autori
Testo di {titolo} di {autore}.                                               ← senza accordi, senza anno, senza capoverso
Testo di {titolo}.                                                           ← solo titolo
```

Logica: `risorse = ['Testo'] + (accordi ? ['accordi'])` → `risorse.join(', ') di {titolo}` + `di {autori deduplicati}` + `(anno)` + `.` + `capoverso || stripHtml(informazioni)`

| Campo | Valore |
|---|---|
| og:type | `article` |
| og:image | Catena: foto primo autore → periodo → random periodo |
| article:section | `Canti di protesta` |
| article:tag | `canto.tags.map(t => t.titolo)` |
| JSON-LD | `MusicComposition` + `BreadcrumbList` |
| `lang` su `<pre>` | `linguaToIso(canto.lingue[0].titolo)` |
| pagefindType | `canti` |

### Schema MusicComposition (condizionale)

```json
{
  "@type": "MusicComposition",
  "name": "sempre",
  "url": "sempre",
  "inLanguage": "codice ISO (sempre, default 'it')",
  "isPartOf": "sempre (link all'archivio)",
  "description": "se canto.capoverso",
  "lyrics": "se canto.testo → CreativeWork con text (troncato a fine verso entro 500 chars, con …)",
  "author": "se autori > 0 → array Person con name + url",
  "lyricist": "se autoriTesto > 0 → array Person",
  "composer": "se autoriMusica > 0 → array Person",
  "dateCreated": "se canto.anno",
  "genre": "se canto.tematiche > 0 → array titoli",
  "subjectOf": "se canto.videoUrl → VideoObject con url e name"
}
```

---

## 3. Canto — Elenco

**File:** `src/pages/canti/index.astro`

| Campo | Valore |
|---|---|
| title | `Canti di protesta — Archivio completo` |
| description | `Esplora l'archivio completo dei canti di protesta politica e sociale: testi, accordi, traduzioni e informazioni storiche.` |
| og:type | `website` (default) |
| JSON-LD | `CollectionPage` + `BreadcrumbList` |

---

## 4. Autore — Dettaglio (profilo)

**File:** `src/pages/autori/[slug].astro`
**Helper:** `buildAutoreTitle(autore)`, `buildAutoreDescription(autore)`

### Title

```
{titolo} — Canti e biografia   ← sempre
```

### Description (condizionale)

`buildAutoreDescription(autore, numCanti)` — il secondo parametro è il numero totale di canti.

```
{stripHtml(informazioni)} 73 canti nell'archivio.                                                   ← con informazioni e canti
{stripHtml(informazioni)}                                                                            ← con informazioni, 0 canti
Biografia e canti di {titolo}, da {localizzazioni[0]}. 73 canti con testo e accordi nell'archivio…   ← senza informazioni, con loc e canti
Biografia e canti di {titolo}. 73 canti con testo e accordi nell'archivio di ilDeposito.org.         ← senza informazioni, senza loc, con canti
Biografia e canti di {titolo}, da {localizzazioni[0]}. Nell'archivio di ilDeposito.org.              ← senza informazioni, con loc, 0 canti
Biografia e canti di {titolo}. Nell'archivio di ilDeposito.org.                                      ← solo titolo
```

| Campo | Valore |
|---|---|
| og:type | `profile` |
| og:image | Catena: foto autore → periodo → random periodo |
| JSON-LD | `Person` + `BreadcrumbList` |
| pagefindType | `autori` |

### Schema Person (condizionale)

```json
{
  "@type": "Person",
  "name": "sempre",
  "url": "sempre",
  "image": "se ogImagePath (path locale risolto)",
  "description": "se autore.informazioni → stripHtml, max 200 chars",
  "birthDate": "se autore.annoNascita",
  "deathDate": "se autore.annoMorte",
  "birthPlace": "se autore.localizzazioni > 0 → Place con name"
}
```

---

## 5. Autore — Elenco canti completo

**File:** `src/pages/autori/[slug]/canti.astro`
Generata solo se l'autore ha più di 10 canti.

| Campo | Valore |
|---|---|
| title | `Tutti i canti di {titolo}` |
| description | `L'elenco completo dei canti di {titolo} nell'archivio di ilDeposito.org: testi, accordi e informazioni.` |
| og:type | `website` (default) |
| og:image | Stessa catena dell'autore (foto autore → periodo → random) |
| JSON-LD | `CollectionPage` + `BreadcrumbList` (4 livelli: Home → Autori → {nome} → Canti) |

**Differenziazione da `autori/[slug]`:**
- Title: "Tutti i canti di…" vs "{nome} — Canti e biografia"
- Description: elenco canti vs biografia
- Schema: `CollectionPage` vs `Person`

---

## 6. Autore — Elenco (index)

**File:** `src/pages/autori/index.astro`

| Campo | Valore |
|---|---|
| title | `Autori e compositori — Archivio` |
| description | `Gli autori e compositori dei canti di protesta dell'archivio: biografie, discografie e opere.` |
| JSON-LD | `CollectionPage` + `BreadcrumbList` |

---

## 7. Evento — Dettaglio

**File:** `src/pages/eventi/[slug].astro`
**Helper:** `buildEventoTitle(evento)`, `buildEventoDescription(evento)`

### Title (condizionale)

```
{titolo} ({anno})    ← se evento.dataEvento
{titolo}             ← senza dataEvento
```

### Description (condizionale)

```
{stripHtml(informazioni)}                                                   ← se evento.informazioni
{titolo}, {data formattata} — {localizzazione}. Evento storico…              ← senza informazioni, con data e luogo
{titolo}, {data formattata}. Evento storico…                                 ← senza informazioni, con data, senza luogo
{titolo} — {localizzazione}. Evento storico…                                 ← senza informazioni, senza data, con luogo
{titolo}. Evento storico nell'archivio di ilDeposito.org.                    ← solo titolo
```

| Campo | Valore |
|---|---|
| og:type | `article` |
| og:image | Catena: foto evento → periodo → random periodo |
| article:section | `Eventi storici` |
| article:tag | `evento.tags.map(t => t.titolo)` |
| article:published_time | `evento.dataEvento` in formato `YYYY-MM-DD` (se presente) |
| JSON-LD | `Event` + `BreadcrumbList` |
| pagefindType | `eventi` |

### Schema Event (condizionale)

```json
{
  "@type": "Event",
  "name": "sempre",
  "url": "sempre",
  "eventAttendanceMode": "sempre (OfflineEventAttendanceMode)",
  "description": "se evento.informazioni → stripHtml, max 200 chars",
  "startDate": "se evento.dataEvento → YYYY-MM-DD",
  "location": "se localizzazioni > 0 → Place con name",
  "location.geo": "se latitude + longitude → GeoCoordinates",
  "location.address": "se geo presente → nome localizzazione",
  "about": "se cantiCollegati > 0 → array MusicComposition con name + url"
}
```

---

## 8. Evento — Elenco (index)

**File:** `src/pages/eventi/index.astro`

| Campo | Valore |
|---|---|
| title | `Eventi storici — Archivio` |
| description | `Gli eventi storici legati ai canti di protesta: date, luoghi e canti collegati nell'archivio di ilDeposito.org.` |
| JSON-LD | `CollectionPage` + `BreadcrumbList` |

---

## 9. Traduzione — Dettaglio

**File:** `src/pages/traduzioni/[slug].astro`
**Helper:** `buildTraduzioneTitle(traduzione)`, `buildTraduzioneDescription(traduzione)`

### Title (condizionale)

```
{titolo} — Traduzione in {lingua}    ← se traduzione.lingue[0]
{titolo}                              ← senza lingua
```

### Description (condizionale)

```
{stripHtml(informazioni)}                                                      ← se traduzione.informazioni
Traduzione in {lingua} di {cantoOriginale.titolo}. Testo completo nell'archivio…  ← senza info, con lingua e canto originale
Traduzione in {lingua} di {titolo}. Testo completo…                             ← senza info, con lingua, senza canto originale
Traduzione di {cantoOriginale.titolo}. Testo completo…                          ← senza info, senza lingua, con canto originale
Traduzione di {titolo}. Testo completo nell'archivio di ilDeposito.org.         ← solo titolo
```

| Campo | Valore |
|---|---|
| og:type | `article` |
| og:image | `/og-default.jpg` |
| JSON-LD | `CreativeWork` (con `translationOfWork`) + `BreadcrumbList` |
| `lang` su `<pre>` | `linguaToIso(traduzione.lingue[0].titolo)` |
| pagefindType | `traduzioni` |

### Schema CreativeWork (condizionale)

```json
{
  "@type": "CreativeWork",
  "name": "sempre",
  "url": "sempre",
  "inLanguage": "codice ISO della lingua della traduzione",
  "translationOfWork": "se cantoOriginale → MusicComposition con name, url, inLanguage"
}
```

---

## 10. Traduzione — Elenco (index)

**File:** `src/pages/traduzioni/index.astro`

| Campo | Valore |
|---|---|
| title | `Traduzioni dei canti — Archivio` |
| description | `Le traduzioni dei canti di protesta in tutte le lingue: testi tradotti, lingue originali e versioni alternative.` |
| JSON-LD | `CollectionPage` + `BreadcrumbList` |

---

## 11. Tassonomie — Lingue

### Index (`lingue/index.astro`)

| Campo | Valore |
|---|---|
| title | `Canti per lingua` |
| description | `Esplora i canti di protesta per lingua: italiano, francese, inglese, spagnolo e altre lingue nell'archivio.` |
| JSON-LD | `CollectionPage` + `BreadcrumbList` |

### Detail (`lingue/[slug].astro`)

| Campo | Valore |
|---|---|
| title | `Canti in {titolo}` |
| description | `I canti di protesta e le traduzioni in {titolo} nell'archivio di ilDeposito.org.` |
| og:image | Random periodo |
| JSON-LD | `WebPage` + `BreadcrumbList` (Home → Lingue → {lingua}) |

---

## 12. Tassonomie — Localizzazioni

### Index (`localizzazioni/index.astro`)

| Campo | Valore |
|---|---|
| title | `Luoghi — Autori e eventi per luogo` |
| description | `Autori e eventi storici per luogo di origine: esplora l'archivio di ilDeposito.org per località.` |
| JSON-LD | `CollectionPage` + `BreadcrumbList` |

### Detail (`localizzazioni/[slug].astro`)

| Campo | Valore |
|---|---|
| title | `{titolo} — Autori e eventi` |
| description | `Gli autori e gli eventi storici legati a {titolo} nell'archivio di ilDeposito.org.` |
| og:image | Random periodo |
| JSON-LD | `WebPage` + `BreadcrumbList` (Home → Localizzazioni → {luogo}) |

---

## 13. Tassonomie — Tags

### Index (`tags/index.astro`)

| Campo | Valore |
|---|---|
| title | `Argomenti — Canti per tema` |
| description | `Esplora i canti di protesta per argomento: lavoro, guerra, resistenza, emigrazione e altri temi.` |
| JSON-LD | `CollectionPage` + `BreadcrumbList` |

### Detail (`tags/[slug].astro`)

| Campo | Valore |
|---|---|
| title | `{titolo} — Canti e eventi` |
| description | `I canti di protesta e gli eventi storici sul tema "{titolo}" nell'archivio di ilDeposito.org.` |
| og:image | Random periodo |
| JSON-LD | `WebPage` + `BreadcrumbList` (Home → Tags → {tag}) |

---

## 14. Tassonomie — Periodi

### Index (`periodi/index.astro`)

| Campo | Valore |
|---|---|
| title | `Periodi storici — Archivio per epoca` |
| description | `Esplora i canti di protesta per periodo storico: Risorgimento, Grande Guerra, Resistenza, anni '60-'70 e oltre.` |
| JSON-LD | `CollectionPage` + `BreadcrumbList` |

### Detail (`periodi/[slug].astro`)

| Campo | Valore |
|---|---|
| title | `{titolo} — Canti, autori e eventi` |
| description | `I canti, gli autori e gli eventi storici del periodo "{titolo}" nell'archivio di ilDeposito.org.` |
| og:image | Foto periodo → random periodo |
| JSON-LD | `WebPage` + `BreadcrumbList` (Home → Periodi → {periodo}) |
| pagefindType | `periodi` |

---

## 15. Calendario Cantato

### Index (`calendario-cantato/index.astro`)

| Campo | Valore |
|---|---|
| title | `Calendario cantato — Anniversari storici` |
| description | `Il calendario cantato di ilDeposito.org: gli anniversari degli eventi storici legati ai canti di protesta, giorno per giorno.` |
| JSON-LD | — |

### Giorno (`calendario-cantato/[giorno].astro`)

| Campo | Valore |
|---|---|
| title | `{giorno} {mese} — Calendario cantato` |
| description | `Gli eventi storici del {giorno} {mese} nell'archivio di ilDeposito.org: anniversari, ricorrenze e canti collegati.` |
| JSON-LD | — |

---

## 16. Pagine statiche (catch-all)

**File:** `src/pages/[...percorso].astro`

| Campo | Valore |
|---|---|
| title | `{pagina.titolo}` (da CMS) |
| description | `{pagina.testo}` (HTML stripped e troncato da `buildDescription`) |
| JSON-LD | `WebPage` + `BreadcrumbList` (Home → {titolo}) |

---

## 17. Cerca

**File:** `src/pages/cerca.astro`

| Campo | Valore |
|---|---|
| title | `Cerca nell'archivio` |
| description | `Cerca tra i canti, gli autori, gli eventi, le traduzioni e i periodi dell'archivio` |
| noindex | `true` |
| JSON-LD | — |

---

## 18. 404

**File:** `src/pages/404.astro`

| Campo | Valore |
|---|---|
| title | `Pagina non trovata` |
| description | `La pagina richiesta non esiste` |
| noindex | `true` |
| JSON-LD | — |

---

## Riepilogo Schema.org per tipo di pagina

| Pagina | Schema principale | BreadcrumbList | Note |
|---|---|---|---|
| Homepage | `WebSite` + `Organization` | 1 livello | SearchAction, sameAs social |
| Canto detail | `MusicComposition` | 3 livelli | lyrics, lyricist, composer, genre condizionali |
| Autore detail | `Person` | 3 livelli | birthDate, deathDate, birthPlace condizionali |
| Autore canti | `CollectionPage` | 4 livelli | Solo se > 10 canti |
| Evento detail | `Event` | 3 livelli | location, geo, about condizionali |
| Traduzione detail | `CreativeWork` | 3 livelli | translationOfWork condizionale |
| Listing (canti, autori, eventi, traduzioni) | `CollectionPage` | 2 livelli | |
| Tassonomia index | `CollectionPage` | 2 livelli | |
| Tassonomia detail | `WebPage` | 3 livelli | |
| Pagine statiche | `WebPage` | 2 livelli | |
| Cerca, 404 | — | — | noindex |

---

## Open Graph — Riepilogo per tipo

| Pagina | og:type | og:image | article:section | article:tag |
|---|---|---|---|---|
| Homepage | `website` | default | — | — |
| Canto | `article` | autore→periodo→random | `Canti di protesta` | tags del canto |
| Autore | `profile` | autore→periodo→random | — | — |
| Autore canti | `website` | autore→periodo→random | — | — |
| Evento | `article` | evento→periodo→random | `Eventi storici` | tags dell'evento |
| Traduzione | `article` | default | — | — |
| Listing/tassonomie | `website` | random periodo o default | — | — |
| Cerca, 404 | `website` | default | — | — |
