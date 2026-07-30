# Audit & Refactoring Schema.org + Open Graph — ilDeposito.org

**Data:** 2026-07-30  
**Progetto:** ilDeposito.org (Drupal 11 headless + Astro 6)  
**Dominio:** Canti di protesta politica e sociale italiani

---

## FASE 1: GAP ANALYSIS TECNICO & ANALISI SEMANTICA DEL DOMINIO

### 1.1 Gap Analysis Tecnico

#### ✅ **Punti di Forza dell'Implementazione Attuale**

1. **Uso del pattern `@graph`** — Il JSON-LD viene correttamente organizzato con `@graph` per collegare entità multiple
2. **Riconciliazione entità via `@id`** — Ottimo uso di `@id` stabili per collegare:
   - Canti → Autori (`#composition` ↔ `#autore`)
   - Canti → Eventi (`#composition` ↔ `#evento`)
3. **Relazioni semantiche corrette** — `about`/`subjectOf` tra canti ed eventi, `translationOfWork` per traduzioni
4. **Separazione delle concerns** — `schema.js` (generazione), `seo.js` (utility), `SEO.astro` (rendering)
5. **Dati strutturati per tassonomie** — Uso di `DefinedTermSet`/`DefinedTerm` per vocabolari controllati

#### ⚠️ **Problemi Critici Identificati**

##### **1. Open Graph Type Mismatch**
```
❌ CANTI:
   Schema.org: MusicComposition
   OG Type:    music.song        ← ERRATO (music.song è per singoli commerciali)
   
✅ CORREZIONE:
   OG Type:    music.composition  ← Corretto per composizioni musicali generiche
```

##### **2. Proprietà Schema.org Mancanti o Deprecate**

**CANTI (`MusicComposition`):**
```javascript
✅ Presenti: name, url, inLanguage, lyrics, author, composer, lyricist, 
            dateCreated, genre, keywords, temporalCoverage, about, image
❌ Mancanti:
   - musicCompositionForm: "canzone", "inno", "ballata"
   - musicalKey: tonalità musicale (se disponibile)
   - iswcCode: codice ISWC internazionale (se applicabile)
```

**AUTORI (`Person`/`MusicGroup`):**
```javascript
✅ Presenti: name, givenName, familyName, birthDate, deathDate, nationality,
            colleague, sameAs, image, description
❌ Mancanti:
   - alumniOf: istituzione di formazione
   - memberOf: gruppi/collettivi (per Person)
   - member: membri (per MusicGroup)
   - award: premi e riconoscimenti
```

**EVENTI (`Event`):**
```javascript
✅ Presenti: name, url, startDate, endDate, location, organizer, description,
            image, eventStatus, genre, keywords, about
❌ Mancanti:
   - eventAttendanceMode: https://schema.org/OfflineEventAttendanceMode
   - previousStartDate: per eventi riprogrammati (se applicabile)
⚠️  DEPRECATO:
   - Non c'è "historicalEvent" in schema.org, ma Event è sufficiente
```

**TRADUZIONI (`CreativeWork`):**
```javascript
✅ Presenti: name, url, inLanguage, translationOfWork
❌ Mancanti:
   - @type più specifico: Use "CreativeWork" va bene, ma potrebbe essere 
     "MusicComposition" se è una traduzione di un canto
   - translator: chi ha tradotto (se disponibile nei dati Drupal)
```

##### **3. Gestione URL e Immagini**

**✅ CORRETTO:**
- Canonical URL costruiti correttamente con `buildCanonical(Astro)`
- `og:url` e `url` Schema.org allineati
- Immagini assolute via `resolveOgImage()`

**⚠️ DA VERIFICARE:**
- Fallback immagini: alcuni content type potrebbero non avere immagine OG
- `og:image:secure_url` non presente (HTTP/HTTPS distinction — opzionale ma raccomandato)

##### **4. Proprietà Open Graph Incomplete**

**EVENTI:**
```html
❌ Mancanti proprietà music.event (namespace Facebook):
   - music:musician: link agli autori dei canti collegati
```

**AUTORI (profile):**
```html
✅ Presenti: profile:first_name, profile:last_name, profile:username
❌ Potenzialmente utili:
   - og:profile:gender (se disponibile e appropriato)
```

##### **5. Tassonomie: Integrazione nei Nodi**

**✅ CORRETTO:**
- Le tassonomie sono integrate come array di `DefinedTerm` in `about`
- Struttura: `buildTaxonomyTermEntity()` genera entità DefinedTerm con `inDefinedTermSet`

**⚠️ MIGLIORABILE:**
- Le tematiche vanno in `genre` (✅) e in `about` (✅)
- I tag vanno in `keywords` (✅) e in `about` (✅)
- I periodi vanno in `temporalCoverage` (✅) e in `about` (✅)
- Le lingue vanno in `inLanguage` (✅) e in `about` (✅)
- **RACCOMANDAZIONE:** Considerare `category` come proprietà aggiuntiva per i termini più rilevanti

##### **6. VideoObject per Canti**

**✅ OTTIMO:**
- VideoObject completo con `thumbnailUrl`, `embedUrl`, `contentUrl`, `uploadDate`
- Relazione `recordedAs` → `MusicRecording` → `recordingOf` → `MusicComposition`

**⚠️ NOTA:**
- `uploadDate` è approssimata con `dataCreazione` del canto — corretto per mancanza di dati YouTube API

---

### 1.2 Analisi Semantica del Dominio

#### **Modello Concettuale del Dominio: Canti di Protesta**

```
┌─────────────────────────────────────────────────────────────────┐
│  DOMINIO: Archivio Canti di Protesta Politica e Sociale        │
└─────────────────────────────────────────────────────────────────┘
         │
         ├─ CANTO (MusicComposition)
         │   ├─ Testo + Accordi + Audio/Video
         │   ├─ Metadati: anno, lingua, fonte
         │   ├─ Relazioni:
         │   │   ├─ composer/lyricist → AUTORE
         │   │   ├─ about → EVENTO (canto scritto SULL'evento storico)
         │   │   ├─ about → TASSONOMIE (tematiche, tag, periodi, lingue)
         │   │   └─ recordedAs → MusicRecording (video YouTube)
         │   └─ Output: PDF testo/accordi (associatedMedia)
         │
         ├─ AUTORE (Person | MusicGroup)
         │   ├─ Dati anagrafici: nome, cognome, nascita, morte
         │   ├─ Nazionalità (Country)
         │   ├─ Collegamenti esterni (sameAs)
         │   ├─ Colleghi (colleague)
         │   └─ Opere (hasPart → ItemList di MusicComposition)
         │
         ├─ EVENTO (Event)
         │   ├─ Evento storico commemorato
         │   ├─ Data, luogo, coordinate geografiche
         │   ├─ Relazioni:
         │   │   ├─ subjectOf → CANTO (inverso di about)
         │   │   └─ about → TASSONOMIE (periodi, tag, tematiche)
         │   └─ Organizzatore: ilDeposito.org
         │
         ├─ TRADUZIONE (CreativeWork → MusicComposition?)
         │   ├─ translationOfWork → CANTO originale
         │   ├─ translator → AUTORE traduttore (se disponibile)
         │   └─ inLanguage → lingua di destinazione
         │
         └─ TASSONOMIE (DefinedTermSet → DefinedTerm)
             ├─ Lingue: it, en, fr, es, dialecti...
             ├─ Localizzazioni: paesi/regioni di origine
             ├─ Periodi: epoche storiche (Resistenza, '68, ...)
             ├─ Tags: parole chiave libere
             └─ Tematiche: categorie tematiche (lavoro, antifascismo, ...)
```

#### **Tipi Schema.org Raccomandati per il Dominio**

| Content Type | Schema.org @type Attuale | Valutazione | Raccomandazione |
|--------------|--------------------------|-------------|-----------------|
| **Canto** | `MusicComposition` | ✅ **OTTIMO** | Mantenere — è il tipo semanticamente più corretto per composizioni musicali non commerciali |
| **Autore** | `Person` / `MusicGroup` | ✅ **OTTIMO** | Mantenere — distinzione basata su `autore.nome` (presenza = Person) |
| **Evento** | `Event` | ✅ **BUONO** | Mantenere — sufficiente per eventi storici. Non serve sottotipo `HistoricalEvent` (non esiste) |
| **Traduzione** | `CreativeWork` | ⚠️ **MIGLIORABILE** | Considerare `MusicComposition` con `translationOfWork` per coerenza |
| **Tassonomie** | `DefinedTerm` | ✅ **OTTIMO** | Perfetto per vocabolari controllati |

#### **Relazioni Semantiche: Mappatura Attuale vs. Ideale**

| Relazione | Implementazione Attuale | Valutazione | Note |
|-----------|-------------------------|-------------|------|
| Canto → Autore | `author`, `composer`, `lyricist` | ✅ PERFETTO | Distinzione precisa ruoli |
| Canto → Evento | `about` (Canto) + `subjectOf` (Evento) | ✅ PERFETTO | Bidirezionale con @id |
| Canto → Tassonomie | `about` (array DefinedTerm) | ✅ PERFETTO | + `genre`, `keywords`, `temporalCoverage` |
| Autore → Canti | `hasPart` → `ItemList` | ✅ OTTIMO | Cap 50 per performance |
| Autore → Autore | `colleague` | ✅ BUONO | Solo per Person, non MusicGroup (corretto) |
| Evento → Canti | `subjectOf` (array MusicComposition) | ✅ PERFETTO | Inverso di `about` |
| Traduzione → Canto | `translationOfWork` | ✅ PERFETTO | - |

---

## FASE 2: MAPPATURA TIPI DI CONTENUTO → SCHEMA.ORG + OPEN GRAPH

### 2.1 CANTI

#### **Schema.org Type:** `MusicComposition` ✅

**Proprietà Obbligatorie/Raccomandate:**

| Proprietà | Stato | Implementazione Attuale | Raccomandazione |
|-----------|-------|-------------------------|-----------------|
| `@id` | ✅ | `{url}#composition` | Mantenere |
| `name` | ✅ | `canto.titolo` | Mantenere |
| `url` | ✅ | `/canti/{slug}` | Mantenere |
| `inLanguage` | ✅ | `linguaToIso(canto.lingue[0])` | Mantenere |
| `lyrics` | ✅ | Oggetto `CreativeWork` con `text` | Mantenere |
| `author` | ✅ | Array di Person/MusicGroup | Mantenere |
| `composer` | ✅ | `canto.autoriMusica` | Mantenere |
| `lyricist` | ✅ | `canto.autoriTesto` | Mantenere |
| `dateCreated` | ✅ | `canto.anno` | Mantenere |
| `datePublished` | ✅ | `canto.dataCreazione` | Mantenere |
| `dateModified` | ✅ | `canto.dataModifica` | Mantenere |
| `description` | ✅ | `canto.informazioni` o `canto.capoverso` | Mantenere |
| `image` | ✅ | Da OG image (autore) | Mantenere |
| `publisher` | ✅ | Organization ilDeposito.org | Mantenere |
| `genre` | ✅ | `canto.tematiche` | Mantenere |
| `keywords` | ✅ | `canto.tags` (comma-separated) | Mantenere |
| `temporalCoverage` | ✅ | `canto.periodi` | Mantenere |
| `about` | ✅ | Eventi + Tassonomie | Mantenere |
| `recordedAs` | ✅ | MusicRecording → VideoObject | Mantenere |
| `associatedMedia` | ✅ | PDF testo/accordi | Mantenere |
| `alternateName` | ✅ | `canto.altriTitoli` | Mantenere |
| `isPartOf` | ✅ | CreativeWork ilDeposito | Mantenere |
| **`musicCompositionForm`** | ❌ | — | **AGGIUNGERE**: "canzone", "inno", "ballata" (se classificabile) |
| **`musicalKey`** | ❌ | — | **AGGIUNGERE**: tonalità (es. "C major") se disponibile in `canto.informazioni` |

#### **Open Graph Type:** ❌ `music.song` → ✅ **`music.composition`**

**Motivazione:** `music.song` è specifico per brani commerciali con artista/album/release date nel contesto dell'industria musicale. Per un archivio culturale di canti di protesta, `music.composition` (o generico `music`) è più appropriato.

**Proprietà OG Raccomandate:**

```html
<!-- Meta tag esistenti (corretti) -->
<meta property="og:title" content="{title}" />
<meta property="og:description" content="{description}" />
<meta property="og:url" content="{canonical}" />
<meta property="og:site_name" content="ilDeposito.org" />
<meta property="og:locale" content="it_IT" />
<meta property="og:image" content="{ogImage}" />
<meta property="og:image:width" content="1200" />
<meta property="og:image:height" content="630" />
<meta property="og:image:alt" content="{ogImageAlt}" />

<!-- CORREZIONE: cambiare tipo -->
<meta property="og:type" content="music.composition" />

<!-- AGGIUNGERE: proprietà music namespace -->
<meta property="music:musician" content="{autore_url}" />  <!-- Per ogni autore -->
<meta property="music:release_date" content="{canto.anno}" />
<meta property="music:duration" content="{durata_secondi}" />  <!-- Se disponibile da video -->

<!-- OPZIONALE: structured properties Facebook deprecate ma ancora supportate -->
<meta property="article:published_time" content="{canto.dataCreazione}" />
<meta property="article:modified_time" content="{canto.dataModifica}" />
<meta property="article:section" content="Canti di protesta" />
<meta property="article:tag" content="{tag}" />  <!-- Per ogni tag -->
```

---

### 2.2 AUTORI

#### **Schema.org Type:** `Person` | `MusicGroup` ✅

**Distinzione:** `autore.nome` presente → `Person`, altrimenti → `MusicGroup`

**Proprietà Person:**

| Proprietà | Stato | Implementazione | Raccomandazione |
|-----------|-------|-----------------|-----------------|
| `@id` | ✅ | `{url}#autore` | Mantenere |
| `name` | ✅ | `autore.titolo` | Mantenere |
| `givenName` | ✅ | `autore.nome` | Mantenere |
| `familyName` | ✅ | `autore.cognome` | Mantenere |
| `url` | ✅ | `/autori/{slug}` | Mantenere |
| `birthDate` | ✅ | `autore.annoNascita` | Mantenere |
| `deathDate` | ✅ | `autore.annoMorte` | Mantenere |
| `nationality` | ✅ | Country (localizzazioni[0]) | Mantenere |
| `colleague` | ✅ | `autore.autoriCorrelati` | Mantenere |
| `sameAs` | ✅ | `autore.links` | Mantenere |
| `image` | ✅ | `autore.immagine` | Mantenere |
| `description` | ✅ | `stripHtml(autore.informazioni)` | Mantenere |
| `about` | ✅ | Tassonomie (localizzazioni, periodi) | Mantenere |
| **`alumniOf`** | ❌ | — | **AGGIUNGERE** se disponibile in `autore.informazioni` |
| **`memberOf`** | ❌ | — | **AGGIUNGERE**: gruppi/collettivi di appartenenza |
| **`award`** | ❌ | — | **AGGIUNGERE** se disponibile |
| **`jobTitle`** | ❌ | — | **CONSIDERARE**: "cantautore", "musicista", etc. |

**Proprietà MusicGroup:**

| Proprietà | Stato | Note |
|-----------|-------|------|
| `@id`, `name`, `url`, `image`, `description`, `sameAs`, `about` | ✅ | Come Person, senza dati anagrafici |
| **`member`** | ❌ | **AGGIUNGERE**: membri del gruppo (array di Person) |
| **`genre`** | ❌ | **CONSIDERARE**: genere musicale del gruppo |

**ProfilePage Wrapper:**

| Proprietà | Stato | Implementazione |
|-----------|-------|-----------------|
| `@type` | ✅ | `ProfilePage` |
| `mainEntity` | ✅ | Person/MusicGroup (senza @context) |
| `hasPart` | ✅ | ItemList di MusicComposition (cap 50) |
| `datePublished` | ✅ | `autore.dataCreazione` |
| `dateModified` | ✅ | `autore.dataModifica` |

#### **Open Graph Type:** `profile` ✅

**Proprietà OG:**

```html
<!-- Esistenti (corretti) -->
<meta property="og:type" content="profile" />
<meta property="profile:first_name" content="{autore.nome}" />
<meta property="profile:last_name" content="{autore.cognome}" />
<meta property="profile:username" content="{autore.slug}" />

<!-- CONSIDERARE AGGIUNGERE (se applicabile): -->
<meta property="profile:gender" content="{gender}" />  <!-- Se disponibile e appropriato -->
```

---

### 2.3 EVENTI

#### **Schema.org Type:** `Event` ✅

**Proprietà:**

| Proprietà | Stato | Implementazione | Raccomandazione |
|-----------|-------|-----------------|-----------------|
| `@id` | ✅ | `{url}#evento` | Mantenere |
| `name` | ✅ | `evento.titolo` | Mantenere |
| `url` | ✅ | `/eventi/{slug}` | Mantenere |
| `startDate` | ✅ | `evento.dataEvento` (ISO day) | Mantenere |
| `endDate` | ✅ | `evento.dataEvento` (stesso giorno) | Mantenere |
| `location` | ✅ | Place con geo se disponibile | Mantenere |
| `description` | ✅ | `stripHtml(evento.informazioni)` | Mantenere |
| `image` | ✅ | `evento.immagine` | Mantenere |
| `eventStatus` | ✅ | `EventScheduled` | Mantenere |
| `organizer` | ✅ | Organization ilDeposito | Mantenere |
| `genre` | ✅ | `evento.tematiche` | Mantenere |
| `keywords` | ✅ | `evento.tags` | Mantenere |
| `about` | ✅ | Tassonomie (periodi, tag, tematiche) | Mantenere |
| `subjectOf` | ✅ | Array di MusicComposition | Mantenere |
| **`eventAttendanceMode`** | ❌ | — | **AGGIUNGERE**: `OfflineEventAttendanceMode` |
| **`previousStartDate`** | ❌ | — | Solo se evento riprogrammato (raro) |

#### **Open Graph Type:** `event` ✅ (ma namespace limitato)

**Proprietà OG:**

```html
<!-- Esistenti (corretti) -->
<meta property="og:type" content="event" />
<meta property="event:start_time" content="{evento.dataEvento ISO}" />
<meta property="event:end_time" content="{evento.dataEvento ISO}" />
<meta property="event:location" content="{localizzazione}" />
<meta property="event:timezone" content="Europe/Rome" />

<!-- CONSIDERARE (namespace music per eventi musicali — Facebook extension): -->
<meta property="music:musician" content="{autore_canto_url}" />  <!-- Per ogni autore di canti collegati -->
```

**NOTA:** Facebook non ha un tipo OG dedicato per "evento storico" — `event` generico è appropriato.

---

### 2.4 TRADUZIONI

#### **Schema.org Type:** `CreativeWork` ⚠️ → **RACCOMANDATO:** `MusicComposition`

**Motivazione:** Una traduzione di un canto è ancora una composizione musicale, non un generico lavoro creativo. Usare `MusicComposition` con `translationOfWork` migliora la coerenza semantica.

**Proprietà Attuali:**

| Proprietà | Stato | Implementazione |
|-----------|-------|-----------------|
| `name` | ✅ | `traduzione.titolo` |
| `url` | ✅ | `/traduzioni/{slug}` |
| `inLanguage` | ✅ | Lingua di destinazione |
| `translationOfWork` | ✅ | MusicComposition originale (con @id) |
| `description` | ✅ | `traduzione.informazioni` |
| `datePublished` | ✅ | `traduzione.dataCreazione` |
| `dateModified` | ✅ | `traduzione.dataModifica` |

**Proprietà Mancanti:**

| Proprietà | Raccomandazione |
|-----------|-----------------|
| **`translator`** | **AGGIUNGERE**: Person/Organization (se disponibile in Drupal) |
| **`@type`** | **MODIFICARE** da `CreativeWork` a `MusicComposition` |
| **`lyrics`** | **AGGIUNGERE**: come per i canti (testo tradotto) |

#### **Open Graph Type:** `article` ⚠️ → **RACCOMANDATO:** `music.composition`

**Correzione:** Allineare con i canti per coerenza — le traduzioni sono ancora composizioni musicali.

---

### 2.5 TASSONOMIE

#### **Schema.org Type:** `DefinedTermSet` (indice) + `DefinedTerm` (termine) ✅

**Implementazione Attuale: OTTIMA**

| Vocabolario | URL Indice | URL Termine | Note |
|-------------|-----------|-------------|------|
| **Lingue** | `/lingue` | `/lingue/{slug}` | ✅ |
| **Localizzazioni** | `/localizzazioni` | `/localizzazioni/{slug}` | ✅ |
| **Periodi** | `/periodi` | `/periodi/{slug}` | ✅ + immagine + descrizione |
| **Tags** | `/tags` | `/tags/{slug}` | ✅ |
| **Tematiche** | `/tematiche` | ❌ No pagina termine | ⚠️ Solo indice `/tematiche` |

**Proprietà DefinedTerm:**

```javascript
{
  "@type": "DefinedTerm",
  "name": "Resistenza",
  "description": "Periodo della Resistenza italiana 1943-1945",
  "url": "https://www.ildeposito.org/periodi/resistenza",
  "inDefinedTermSet": {
    "@type": "DefinedTermSet",
    "name": "Periodi",
    "url": "https://www.ildeposito.org/periodi"
  }
}
```

**Integrazione nei Nodi (Canti/Autori/Eventi):**

✅ **CORRETTO:** Le tassonomie sono aggiunte come array in `about` dei nodi principali, creando relazioni semantiche forti.

**Open Graph Type per Tassonomie:** Implicito `website` (default) — corretto, non servono tipi specifici.

---

## FASE 3: MAPPATURA TASSONOMIE & RELAZIONI SEMANTICHE NEI NODI

### 3.1 Struttura Tassonomie: DefinedTermSet → DefinedTerm

**Pattern Attuale (OTTIMO):**

```javascript
// Pagina indice tassonomia: /periodi
{
  "@type": "DefinedTermSet",
  "name": "Periodi",
  "description": "Periodi storici dell'archivio ilDeposito",
  "url": "https://www.ildeposito.org/periodi"
}

// Pagina termine singolo: /periodi/resistenza
{
  "@type": "DefinedTerm",
  "name": "Resistenza",
  "description": "Periodo della Resistenza italiana 1943-1945",
  "url": "https://www.ildeposito.org/periodi/resistenza",
  "inDefinedTermSet": {
    "@type": "DefinedTermSet",
    "name": "Periodi",
    "url": "https://www.ildeposito.org/periodi"
  }
}
```

**Raccomandazione:** Mantenere questa struttura — è semanticamente corretta e ben implementata.

---

### 3.2 Integrazione Tassonomie nei Template dei Nodi

#### **Implementazione Attuale: `buildTaxonomyTermEntity()`**

```javascript
// Genera DefinedTerm per ogni termine, usato in array `about`
function buildTaxonomyTermEntity(term, siteUrl, termSetName, termSetPath, termPath) {
  return {
    "@type": "DefinedTerm",
    "name": term.titolo,
    "url": termPath ? `${siteUrl}${termPath}` : undefined,
    "inDefinedTermSet": {
      "@type": "DefinedTermSet",
      "name": termSetName,
      "url": termSetPath ? `${siteUrl}${termSetPath}` : undefined
    }
  };
}
```

**✅ FORZA:** Ogni termine è un'entità strutturata con link bidirezionale al suo vocabolario.

**⚠️ LIMITE:** `termPath` è `undefined` per tematiche (non hanno pagina di dettaglio) — accettabile, ma limita la navigabilità semantica.

---

### 3.3 Relazioni Semantiche: Distribuzione Proprietà

**CANTI:**

| Tassonomia | Proprietà Schema.org | Valore | Note |
|------------|---------------------|---------|------|
| **Tematiche** | `genre` | Array di stringhe | ✅ Corretto |
| **Tematiche** | `about` | Array di DefinedTerm | ✅ Corretto |
| **Tags** | `keywords` | Stringa comma-separated | ✅ Corretto |
| **Tags** | `about` | Array di DefinedTerm | ✅ Corretto |
| **Periodi** | `temporalCoverage` | Array di stringhe | ✅ Corretto |
| **Periodi** | `about` | Array di DefinedTerm | ✅ Corretto |
| **Lingue** | `inLanguage` | Codice ISO BCP 47 | ✅ Corretto (solo prima lingua) |
| **Lingue** | `about` | Array di DefinedTerm | ✅ Corretto (tutte le lingue) |

**RACCOMANDAZIONE AGGIUNTIVA:**

Considerare l'uso di `category` per le tassonomie più strutturali (tematiche, periodi):

```javascript
// Oltre a `about` e `genre`, aggiungere:
if (canto.tematiche?.length > 0) {
  schema.category = canto.tematiche.map(t => t.titolo);
}
```

**Motivazione:** `category` è una proprietà di `CreativeWork` semanticamente forte per classificazioni gerarchiche.

---

**AUTORI:**

| Tassonomia | Proprietà Schema.org | Implementazione |
|------------|---------------------|-----------------|
| **Localizzazioni** | `nationality` (Person) | ✅ Country (prima localizzazione) |
| **Localizzazioni** | `about` | ✅ Array di DefinedTerm (tutte) |
| **Periodi** | `about` | ✅ Array di DefinedTerm |

✅ **CORRETTO** — Le localizzazioni hanno doppia funzione: nazionalità specifica e classificazione generale.

---

**EVENTI:**

| Tassonomia | Proprietà Schema.org | Implementazione |
|------------|---------------------|-----------------|
| **Tematiche** | `genre` | ✅ Array di stringhe |
| **Tematiche** | `about` | ✅ Array di DefinedTerm |
| **Tags** | `keywords` | ✅ Stringa comma-separated |
| **Tags** | `about` | ✅ Array di DefinedTerm |
| **Periodi** | `about` | ✅ Array di DefinedTerm |
| **Localizzazioni** | `location.name` | ✅ Stringa (prima località) |

✅ **CORRETTO** — Gli eventi non hanno `temporalCoverage` (hanno `startDate`/`endDate`).

---

### 3.4 Grafo delle Relazioni: Esempio Completo

**Scenario:** Pagina del canto "Bella Ciao"

```json
{
  "@context": "https://schema.org",
  "@graph": [
    // 1. Il canto (nodo principale)
    {
      "@type": "MusicComposition",
      "@id": "https://www.ildeposito.org/canti/bella-ciao#composition",
      "name": "Bella Ciao",
      "url": "https://www.ildeposito.org/canti/bella-ciao",
      "inLanguage": "it",
      "dateCreated": "1944",
      
      // Autori (con @id per riconciliazione)
      "author": [{
        "@type": "MusicGroup",
        "@id": "https://www.ildeposito.org/autori/anonimo#autore",
        "name": "Anonimo"
      }],
      
      // Eventi collegati (about)
      "about": [
        {
          "@type": "Event",
          "@id": "https://www.ildeposito.org/eventi/25-aprile-1945#evento",
          "name": "Liberazione d'Italia - 25 aprile 1945"
        },
        // Tassonomie come DefinedTerm
        {
          "@type": "DefinedTerm",
          "name": "Resistenza",
          "url": "https://www.ildeposito.org/periodi/resistenza",
          "inDefinedTermSet": {
            "@type": "DefinedTermSet",
            "name": "Periodi",
            "url": "https://www.ildeposito.org/periodi"
          }
        },
        {
          "@type": "DefinedTerm",
          "name": "Antifascismo",
          "inDefinedTermSet": {
            "@type": "DefinedTermSet",
            "name": "Tematiche",
            "url": "https://www.ildeposito.org/tematiche"
          }
        }
      ],
      
      // Proprietà dedicate per tassonomie
      "genre": ["Antifascismo", "Resistenza"],
      "keywords": "resistenza, partigiani, libertà",
      "temporalCoverage": ["Resistenza", "Seconda Guerra Mondiale"],
      
      // Video YouTube come recording
      "recordedAs": {
        "@type": "MusicRecording",
        "@id": "https://www.ildeposito.org/canti/bella-ciao#recording",
        "video": {
          "@type": "VideoObject",
          "name": "Bella Ciao",
          "thumbnailUrl": "https://i.ytimg.com/vi/4CI3lhyNKfo/hqdefault.jpg",
          "embedUrl": "https://www.youtube.com/embed/4CI3lhyNKfo"
        }
      }
    },
    
    // 2. Breadcrumb
    {
      "@type": "BreadcrumbList",
      "itemListElement": [
        {"@type": "ListItem", "position": 1, "name": "Home", "item": "https://www.ildeposito.org"},
        {"@type": "ListItem", "position": 2, "name": "Canti", "item": "https://www.ildeposito.org/canti"},
        {"@type": "ListItem", "position": 3, "name": "Bella Ciao"}
      ]
    }
  ]
}
```

**✅ PUNTI DI FORZA:**
- Riconciliazione entità via `@id` condivisi
- Doppia classificazione: proprietà specifiche (`genre`, `keywords`) + entità strutturate (`about`)
- Relazioni bidirezionali (Canto.about ↔ Event.subjectOf)

---

## FASE 4: REFACTORING CONSERVATIVO DEL CODICE ASTRO

### 4.1 Principi del Refactoring

1. ✅ **Conservare** la struttura esistente: funzioni in `schema.js`, utility in `seo.js`, rendering in `SEO.astro`
2. ✅ **Mantenere** il pattern `@graph` per organizzare entità multiple
3. ✅ **Preservare** gli `@id` esistenti e le relazioni bidirezionali
4. ✅ **Integrare** solo le proprietà mancanti identificate nell'audit
5. ✅ **Correggere** i tipi Open Graph disallineati

---

### 4.2 Modifiche a `schema.js`

#### **4.2.1 Aggiungere Helper per Proprietà Opzionali**

```javascript
// Aggiungere in cima al file, dopo gli import
/**
 * Aggiunge una proprietà a uno schema solo se il valore è valido.
 * @param {object} schema - Oggetto schema da modificare
 * @param {string} key - Chiave della proprietà
 * @param {*} value - Valore da aggiungere
 */
function addIfValid(schema, key, value) {
  if (value != null && value !== '' && (!Array.isArray(value) || value.length > 0)) {
    schema[key] = value;
  }
}

/**
 * Estrae la tonalità musicale da una stringa di testo.
 * Pattern: "Tonalità: Do maggiore" o "Key: C major"
 * @param {string} text - Testo da analizzare
 * @returns {string|null} - Tonalità in formato standard (es. "C major")
 */
function extractMusicalKey(text) {
  if (!text) return null;
  const patterns = [
    /Tonalità:\s*([A-G][b#]?\s*(?:maggiore|minore))/i,
    /Key:\s*([A-G][b#]?\s*(?:major|minor))/i,
  ];
  for (const pattern of patterns) {
    const match = text.match(pattern);
    if (match) return match[1].trim();
  }
  return null;
}

/**
 * Deduce la forma musicale dal contesto (testo, titolo, informazioni).
 * @param {object} canto - Oggetto canto
 * @returns {string|null} - Forma musicale (es. "canzone", "inno", "ballata")
 */
function deduceMusicCompositionForm(canto) {
  const text = `${canto.titolo} ${canto.informazioni || ''}`.toLowerCase();
  if (/\binno\b/i.test(text)) return 'Inno';
  if (/\bballata\b/i.test(text)) return 'Ballata';
  if (/\bmarcia\b/i.test(text)) return 'Marcia';
  if (/\bcoro\b/i.test(text)) return 'Coro';
  // Default per canti di protesta
  return 'Canzone';
}
```

#### **4.2.2 Modificare `buildCreativeWorkSchema`**

```javascript
export function buildCreativeWorkSchema(canto, siteUrl, ogImagePath, eventi = []) {
  const url = `${siteUrl}/canti/${canto.slug}`;

  const schema = {
    '@context': 'https://schema.org',
    '@type': 'MusicComposition',
    '@id': `${url}#composition`,
    name: canto.titolo,
    url,
    inLanguage: linguaToIso(canto.lingue?.[0]?.titolo),
    isPartOf: {
      '@type': 'CreativeWork',
      name: 'ilDeposito.org — Archivio canti di protesta',
      url: siteUrl,
    },
  };

  // Description
  const descrizione = stripHtml(canto.informazioni) || canto.capoverso;
  if (descrizione) {
    schema.description = truncate(descrizione, 300);
  }

  if (ogImagePath) {
    schema.image = `${siteUrl}${ogImagePath}`;
  }

  if (canto.altriTitoli) {
    schema.alternateName = canto.altriTitoli;
  }

  schema.publisher = {
    '@type': 'Organization',
    name: 'ilDeposito.org',
    url: siteUrl,
    logo: `${siteUrl}/favicon.svg`,
  };

  // Lyrics
  if (canto.testo) {
    let lyricsText = canto.testo;
    if (lyricsText.length > 500) {
      const cutAt = lyricsText.lastIndexOf('\n', 500);
      lyricsText = (cutAt > 100 ? lyricsText.substring(0, cutAt) : lyricsText.substring(0, 500)).trimEnd() + '…';
    }
    schema.lyrics = {
      '@type': 'CreativeWork',
      text: lyricsText,
      inLanguage: linguaToIso(canto.lingue?.[0]?.titolo),
    };
  }

  // Authors (deduplicated)
  const autori = [...(canto.autoriTesto ?? []), ...(canto.autoriMusica ?? [])].filter(
    (a, i, arr) => arr.findIndex((x) => x.slug === a.slug) === i
  );

  const toEntityRef = (a) => ({
    '@type': a.isPersona ? 'Person' : 'MusicGroup',
    '@id': `${siteUrl}/autori/${a.slug}#autore`,
    name: a.titolo,
    url: `${siteUrl}/autori/${a.slug}`,
  });

  if (autori.length > 0) {
    schema.author = autori.map(toEntityRef);

    if (canto.autoriTesto?.length > 0) {
      schema.lyricist = canto.autoriTesto.map(toEntityRef);
    }
    if (canto.autoriMusica?.length > 0) {
      schema.composer = canto.autoriMusica.map(toEntityRef);
    }
  }

  // Dates
  if (canto.anno) {
    schema.dateCreated = String(canto.anno);
  }
  if (canto.dataCreazione) schema.datePublished = canto.dataCreazione;
  if (canto.dataModifica) schema.dateModified = canto.dataModifica;

  // === NUOVE PROPRIETÀ ===
  
  // musicCompositionForm: deduzione automatica
  const form = deduceMusicCompositionForm(canto);
  if (form) {
    schema.musicCompositionForm = form;
  }

  // musicalKey: estrazione da informazioni
  const key = extractMusicalKey(canto.informazioni);
  if (key) {
    schema.musicalKey = key;
  }

  // === FINE NUOVE PROPRIETÀ ===

  // Taxonomies
  const taxonomyAbout = [];

  // Genre (tematiche)
  if (canto.tematiche?.length > 0) {
    schema.genre = canto.tematiche.map((t) => t.titolo);
    // AGGIUNTA: anche in category per rafforzare classificazione
    schema.category = canto.tematiche.map((t) => t.titolo);
    
    taxonomyAbout.push(
      ...canto.tematiche
        .map((t) => buildTaxonomyTermEntity(t, siteUrl, 'Tematiche', '/tematiche', undefined))
        .filter(Boolean),
    );
  }

  // Keywords (tags)
  if (canto.tags?.length > 0) {
    schema.keywords = canto.tags.map((t) => t.titolo).join(', ');
    taxonomyAbout.push(
      ...canto.tags
        .map((t) => buildTaxonomyTermEntity(t, siteUrl, 'Tag', '/tags', `/tags/${t.slug}`))
        .filter(Boolean),
    );
  }

  // temporalCoverage (periodi)
  if (canto.periodi?.length > 0) {
    schema.temporalCoverage = canto.periodi.map((p) => p.titolo);
    taxonomyAbout.push(
      ...canto.periodi
        .map((p) => buildTaxonomyTermEntity(p, siteUrl, 'Periodi', '/periodi', `/periodi/${p.slug}`))
        .filter(Boolean),
    );
  }

  // Lingue (tutte in about)
  if (canto.lingue?.length > 0) {
    taxonomyAbout.push(
      ...canto.lingue
        .map((lingua) => buildTaxonomyTermEntity(lingua, siteUrl, 'Lingue', '/lingue', `/lingue/${lingua.slug}`))
        .filter(Boolean),
    );
  }

  const aboutItems = [];

  // Eventi (about)
  if (eventi.length > 0) {
    aboutItems.push(...eventi.map((e) => {
      const eventSchema = {
        '@type': 'Event',
        '@id': `${siteUrl}/eventi/${e.slug}#evento`,
        name: e.titolo,
        url: `${siteUrl}/eventi/${e.slug}`,
        eventStatus: 'https://schema.org/EventScheduled',
      };

      if (e.dataEvento) {
        const giorno = new Date(e.dataEvento).toISOString().split('T')[0];
        eventSchema.startDate = giorno;
        eventSchema.endDate = giorno;
      }

      if (e.informazioni) {
        eventSchema.description = stripHtml(e.informazioni).substring(0, 200);
      }

      if (e.immagine) {
        eventSchema.image = e.immagine.startsWith('http') ? e.immagine : `${siteUrl}${e.immagine}`;
      }

      if (e.localizzazioni?.length > 0) {
        const loc = e.localizzazioni[0];
        eventSchema.location = {
          '@type': 'Place',
          name: loc.titolo,
          address: loc.titolo,
        };

        if (e.latitude != null && e.longitude != null) {
          eventSchema.location.geo = {
            '@type': 'GeoCoordinates',
            latitude: e.latitude,
            longitude: e.longitude,
          };
        }
      }

      return eventSchema;
    }));
  }

  if (taxonomyAbout.length > 0) {
    aboutItems.push(...taxonomyAbout);
  }

  if (aboutItems.length > 0) {
    schema.about = aboutItems;
  }

  // VideoObject
  if (canto.videoUrl) {
    const videoId = extractYouTubeVideoId(canto.videoUrl);

    if (videoId) {
      const video = {
        '@type': 'VideoObject',
        name: canto.titolo,
        description: `Video del canto ${canto.titolo} su ilDeposito.org`,
        thumbnailUrl: `https://i.ytimg.com/vi/${videoId}/hqdefault.jpg`,
        embedUrl: `https://www.youtube.com/embed/${videoId}`,
        contentUrl: `https://www.youtube.com/watch?v=${videoId}`,
        url: canto.videoUrl,
      };

      const uploadDate = canto.dataCreazione || canto.dataModifica || (canto.anno ? String(canto.anno) : null);
      if (uploadDate) video.uploadDate = uploadDate;

      schema.recordedAs = {
        '@type': 'MusicRecording',
        '@id': `${url}#recording`,
        name: canto.titolo,
        recordingOf: { '@id': `${url}#composition` },
        video,
      };
    }
  }

  // PDF associatedMedia
  schema.associatedMedia = {
    '@type': 'DigitalDocument',
    name: `Testo${canto.accordi ? ' e accordi' : ''} di "${canto.titolo}" (PDF)`,
    url: `${siteUrl}/pdf/canti/ildeposito-${canto.slug}.pdf`,
    encodingFormat: 'application/pdf',
  };

  return schema;
}
```

**MODIFICHE APPLICATE:**
- ✅ Aggiunta `musicCompositionForm` (deduzione automatica)
- ✅ Aggiunta `musicalKey` (estrazione da testo)
- ✅ Aggiunta `category` (duplica tematiche per rafforzare classificazione)
- ✅ Mantenuto tutto il resto invariato

---

#### **4.2.3 Modificare `buildPersonSchema` e `buildProfilePageSchema`**

```javascript
export function buildPersonSchema(autore, siteUrl, ogImagePath) {
  const isPersona = Boolean(autore.nome);
  const url = `${siteUrl}/autori/${autore.slug}`;

  const schema = {
    '@context': 'https://schema.org',
    '@type': isPersona ? 'Person' : 'MusicGroup',
    '@id': `${url}#autore`,
    name: autore.titolo,
    url,
  };

  if (isPersona) {
    schema.givenName = autore.nome;
    schema.familyName = autore.cognome;
  }

  if (ogImagePath) {
    schema.image = `${siteUrl}${ogImagePath}`;
  }

  if (autore.informazioni) {
    schema.description = stripHtml(autore.informazioni).substring(0, 200);
  }

  if (isPersona) {
    if (autore.annoNascita) schema.birthDate = String(autore.annoNascita);
    if (autore.annoMorte) schema.deathDate = String(autore.annoMorte);

    if (autore.localizzazioni?.length > 0) {
      schema.nationality = {
        '@type': 'Country',
        name: autore.localizzazioni[0].titolo,
      };
    }

    if (autore.autoriCorrelati?.length > 0) {
      schema.colleague = autore.autoriCorrelati.map((c) => ({
        '@type': c.isPersona ? 'Person' : 'MusicGroup',
        '@id': `${siteUrl}/autori/${c.slug}#autore`,
        name: c.titolo,
        url: `${siteUrl}/autori/${c.slug}`,
      }));
    }

    // === NUOVE PROPRIETÀ PERSON ===
    
    // jobTitle: deduzione da contesto
    if (autore.informazioni) {
      const text = autore.informazioni.toLowerCase();
      if (/cantautore/i.test(text)) {
        schema.jobTitle = 'Cantautore';
      } else if (/musicista/i.test(text)) {
        schema.jobTitle = 'Musicista';
      } else if (/compositore/i.test(text)) {
        schema.jobTitle = 'Compositore';
      } else if (/paroliere/i.test(text)) {
        schema.jobTitle = 'Paroliere';
      }
    }
    
    // === FINE NUOVE PROPRIETÀ ===
  } else {
    // === NUOVE PROPRIETÀ MUSICGROUP ===
    
    // genre: deduzione da informazioni o tematiche collegate
    if (autore.informazioni) {
      const text = autore.informazioni.toLowerCase();
      const genres = [];
      if (/folk/i.test(text)) genres.push('Folk');
      if (/rock/i.test(text)) genres.push('Rock');
      if (/cantautorale/i.test(text)) genres.push('Cantautorato');
      if (/tradizionale/i.test(text)) genres.push('Musica tradizionale');
      if (genres.length > 0) {
        schema.genre = genres;
      }
    }
    
    // NOTA: `member` richiederebbe dati strutturati in Drupal — non implementabile ora
    
    // === FINE NUOVE PROPRIETÀ ===
  }

  const taxonomyAbout = [];

  if (autore.localizzazioni?.length > 0) {
    taxonomyAbout.push(
      ...autore.localizzazioni
        .map((loc) => buildTaxonomyTermEntity(loc, siteUrl, 'Localizzazioni', '/localizzazioni', `/localizzazioni/${loc.slug}`))
        .filter(Boolean),
    );
  }

  if (autore.periodi?.length > 0) {
    taxonomyAbout.push(
      ...autore.periodi
        .map((periodo) => buildTaxonomyTermEntity(periodo, siteUrl, 'Periodi', '/periodi', `/periodi/${periodo.slug}`))
        .filter(Boolean),
    );
  }

  if (taxonomyAbout.length > 0) {
    schema.about = taxonomyAbout;
  }

  const sameAs = (autore.links ?? []).map((l) => l.uri).filter(Boolean);
  if (sameAs.length > 0) schema.sameAs = sameAs;

  return schema;
}

// buildProfilePageSchema: INVARIATO (già ottimo)
```

**MODIFICHE APPLICATE:**
- ✅ Aggiunta `jobTitle` per Person (deduzione automatica)
- ✅ Aggiunta `genre` per MusicGroup (deduzione automatica)
- ✅ Nota su `member` e `memberOf` (richiedono dati strutturati in Drupal — feature futura)

---

#### **4.2.4 Modificare `buildEventSchema`**

```javascript
export function buildEventSchema(evento, siteUrl, ogImagePath) {
  const url = `${siteUrl}/eventi/${evento.slug}`;

  const schema = {
    '@context': 'https://schema.org',
    '@type': 'Event',
    '@id': `${url}#evento`,
    name: evento.titolo,
    url,
    eventStatus: 'https://schema.org/EventScheduled',
    // === NUOVA PROPRIETÀ ===
    eventAttendanceMode: 'https://schema.org/OfflineEventAttendanceMode',
    // === FINE NUOVA PROPRIETÀ ===
  };

  if (evento.informazioni) {
    schema.description = stripHtml(evento.informazioni).substring(0, 200);
  }

  schema.organizer = {
    '@type': 'Organization',
    name: 'ilDeposito.org',
    url: siteUrl,
    logo: `${siteUrl}/favicon.svg`,
  };

  const taxonomyAbout = [];

  if (evento.tematiche?.length > 0) {
    schema.genre = evento.tematiche.map((t) => t.titolo);
    // AGGIUNTA: anche category per coerenza con canti
    schema.category = evento.tematiche.map((t) => t.titolo);
    
    taxonomyAbout.push(
      ...evento.tematiche
        .map((t) => buildTaxonomyTermEntity(t, siteUrl, 'Tematiche', '/tematiche', undefined))
        .filter(Boolean),
    );
  }

  if (evento.tags?.length > 0) {
    schema.keywords = evento.tags.map((t) => t.titolo).join(', ');
    taxonomyAbout.push(
      ...evento.tags
        .map((t) => buildTaxonomyTermEntity(t, siteUrl, 'Tag', '/tags', `/tags/${t.slug}`))
        .filter(Boolean),
    );
  }

  if (evento.periodi?.length > 0) {
    taxonomyAbout.push(
      ...evento.periodi
        .map((periodo) => buildTaxonomyTermEntity(periodo, siteUrl, 'Periodi', '/periodi', `/periodi/${periodo.slug}`))
        .filter(Boolean),
    );
  }

  if (taxonomyAbout.length > 0) {
    schema.about = taxonomyAbout;
  }

  if (evento.dataEvento) {
    const giorno = new Date(evento.dataEvento).toISOString().split('T')[0];
    schema.startDate = giorno;
    schema.endDate = giorno;
  }

  if (ogImagePath) {
    schema.image = `${siteUrl}${ogImagePath}`;
  }

  if (evento.localizzazioni?.length > 0) {
    const loc = evento.localizzazioni[0];
    schema.location = {
      '@type': 'Place',
      name: loc.titolo,
      address: loc.titolo,
    };
    if (evento.latitude != null && evento.longitude != null) {
      schema.location.geo = {
        '@type': 'GeoCoordinates',
        latitude: evento.latitude,
        longitude: evento.longitude,
      };
    }
  }

  if (evento.cantiCollegati?.length > 0) {
    schema.subjectOf = evento.cantiCollegati.map((c) => ({
      '@type': 'MusicComposition',
      '@id': `${siteUrl}/canti/${c.slug}#composition`,
      name: c.titolo,
      url: `${siteUrl}/canti/${c.slug}`,
    }));
  }

  if (evento.links?.[0]?.uri) schema.sameAs = [evento.links[0].uri];

  if (evento.dataCreazione) schema.datePublished = evento.dataCreazione;
  if (evento.dataModifica) schema.dateModified = evento.dataModifica;

  return schema;
}
```

**MODIFICHE APPLICATE:**
- ✅ Aggiunta `eventAttendanceMode` (OfflineEventAttendanceMode per eventi storici)
- ✅ Aggiunta `category` (duplica tematiche per coerenza)

---

#### **4.2.5 Modificare `buildTranslationSchema`**

```javascript
export function buildTranslationSchema(traduzione, siteUrl) {
  const schema = {
    '@context': 'https://schema.org',
    // === MODIFICA: da CreativeWork a MusicComposition ===
    '@type': 'MusicComposition',
    // === FINE MODIFICA ===
    name: traduzione.titolo,
    url: `${siteUrl}/traduzioni/${traduzione.slug}`,
    inLanguage: linguaToIso(traduzione.lingue?.[0]?.titolo),
  };

  if (traduzione.cantoOriginale) {
    schema.translationOfWork = {
      '@type': 'MusicComposition',
      '@id': `${siteUrl}/canti/${traduzione.cantoOriginale.slug}#composition`,
      name: traduzione.cantoOriginale.titolo,
      url: `${siteUrl}/canti/${traduzione.cantoOriginale.slug}`,
      inLanguage: linguaToIso(traduzione.cantoOriginale.lingue?.[0]?.titolo),
    };
  }

  if (traduzione.informazioni) {
    schema.description = stripHtml(traduzione.informazioni).substring(0, 200);
  }

  // === NUOVE PROPRIETÀ ===
  
  // lyrics: testo tradotto
  if (traduzione.testo) {
    schema.lyrics = {
      '@type': 'CreativeWork',
      text: traduzione.testo.length > 500 
        ? traduzione.testo.substring(0, 500).trimEnd() + '…' 
        : traduzione.testo,
      inLanguage: linguaToIso(traduzione.lingue?.[0]?.titolo),
    };
  }

  // translator: NOTA — richiede dati in Drupal (campo "traduttore" non presente)
  // Se in futuro viene aggiunto:
  // if (traduzione.traduttore) {
  //   schema.translator = {
  //     '@type': 'Person',
  //     name: traduzione.traduttore.titolo,
  //     url: `${siteUrl}/autori/${traduzione.traduttore.slug}`
  //   };
  // }
  
  // === FINE NUOVE PROPRIETÀ ===

  if (traduzione.dataCreazione) schema.datePublished = traduzione.dataCreazione;
  if (traduzione.dataModifica) schema.dateModified = traduzione.dataModifica;

  return schema;
}
```

**MODIFICHE APPLICATE:**
- ✅ Cambiato `@type` da `CreativeWork` a `MusicComposition`
- ✅ Aggiunta proprietà `lyrics` con il testo tradotto
- ✅ Nota su `translator` (campo non presente in Drupal — feature futura)

---

### 4.3 Modifiche a `SEO.astro`

**File:** `frontend/src/components/base/SEO.astro`

```astro
---
interface Props {
  title: string;
  description: string;
  canonical: string;
  ogType?: string;
  ogImage?: string;
  ogImageAlt?: string;
  noindex?: boolean;
  publishedTime?: string;
  modifiedTime?: string;  // AGGIUNTA
  articleSection?: string;
  articleTags?: string[];
  profileFirstName?: string;
  profileLastName?: string;
  profileUsername?: string;
  eventStartTime?: string;
  eventEndTime?: string;
  eventLocation?: string;
  eventTimezone?: string;
  musicMusicians?: string[];  // AGGIUNTA per canti/eventi
  musicReleaseDate?: string;  // AGGIUNTA per canti
  jsonLd?: object | object[];
}

const {
  title,
  description,
  canonical,
  ogType = 'website',
  ogImage,
  ogImageAlt,
  noindex = false,
  publishedTime,
  modifiedTime,
  articleSection,
  articleTags,
  profileFirstName,
  profileLastName,
  profileUsername,
  eventStartTime,
  eventEndTime,
  eventLocation,
  eventTimezone,
  musicMusicians,
  musicReleaseDate,
  jsonLd,
} = Astro.props;

const jsonLdRaw = jsonLd ? (Array.isArray(jsonLd) ? jsonLd : [jsonLd]) : [];
const jsonLdGraph = jsonLdRaw.length > 0
  ? { '@context': 'https://schema.org', '@graph': jsonLdRaw.map(({ '@context': _, ...rest }) => rest) }
  : null;

// Assicura che l'immagine OG sia assoluta e generi og:image:secure_url se HTTPS
const ogImageAbsolute = ogImage;
const ogImageSecure = ogImage?.startsWith('https://') ? ogImage : undefined;
---

<title>{title}</title>
<meta name="description" content={description} />
<link rel="canonical" href={canonical} />

{noindex && <meta name="robots" content="noindex, nofollow" />}

<!-- Open Graph -->
<meta property="og:title" content={title} />
<meta property="og:description" content={description} />
<meta property="og:url" content={canonical} />
<meta property="og:type" content={ogType} />
<meta property="og:site_name" content="ilDeposito.org" />
<meta property="og:locale" content="it_IT" />
{ogImageAbsolute && <meta property="og:image" content={ogImageAbsolute} />}
{ogImageSecure && <meta property="og:image:secure_url" content={ogImageSecure} />}
{ogImageAbsolute && <meta property="og:image:width" content="1200" />}
{ogImageAbsolute && <meta property="og:image:height" content="630" />}
{ogImageAlt && <meta property="og:image:alt" content={ogImageAlt} />}

<!-- Twitter Card -->
<meta name="twitter:card" content={ogImage ? 'summary_large_image' : 'summary'} />
<meta name="twitter:title" content={title} />
<meta name="twitter:description" content={description} />
{ogImage && <meta name="twitter:image" content={ogImage} />}

<!-- Article meta -->
{publishedTime && <meta property="article:published_time" content={publishedTime} />}
{modifiedTime && <meta property="article:modified_time" content={modifiedTime} />}
{articleSection && <meta property="article:section" content={articleSection} />}
{articleTags?.map((tag) => <meta property="article:tag" content={tag} />)}

<!-- Profile meta -->
{profileFirstName && <meta property="profile:first_name" content={profileFirstName} />}
{profileLastName && <meta property="profile:last_name" content={profileLastName} />}
{profileUsername && <meta property="profile:username" content={profileUsername} />}

<!-- Event meta -->
{eventStartTime && <meta property="event:start_time" content={eventStartTime} />}
{eventEndTime && <meta property="event:end_time" content={eventEndTime} />}
{eventLocation && <meta property="event:location" content={eventLocation} />}
{eventTimezone && <meta property="event:timezone" content={eventTimezone} />}

<!-- Music meta (AGGIUNTA per music.composition) -->
{musicMusicians?.map((musicianUrl) => <meta property="music:musician" content={musicianUrl} />)}
{musicReleaseDate && <meta property="music:release_date" content={musicReleaseDate} />}

<!-- JSON-LD -->
{jsonLdGraph && (
  <script type="application/ld+json" set:html={JSON.stringify(jsonLdGraph)} />
)}
```

**MODIFICHE APPLICATE:**
- ✅ Aggiunta `modifiedTime` per `article:modified_time`
- ✅ Aggiunta `musicMusicians` per `music:musician` (array)
- ✅ Aggiunta `musicReleaseDate` per `music:release_date`
- ✅ Aggiunta `og:image:secure_url` se HTTPS

---

### 4.4 Modifiche alle Pagine Astro (Template)

#### **4.4.1 Pagina Canto: `[slug].astro`**

```astro
---
// ... import invariati ...

// ... getStaticPaths() invariato ...

const { canto, eventi } = Astro.props as { canto: CantoDetail; eventi: EventoForCanto[] };

// ... logica esistente invariata ...

const siteUrl = Astro.site?.href?.replace(/\/$/, '') || 'https://www.ildeposito.org';
const breadcrumbItems = [
  { label: 'Home', href: '/' },
  { label: 'Canti', href: '/canti' },
  { label: canto.titolo },
];

// === NUOVE PROPS PER MUSIC NAMESPACE ===
const musicMusicians = autoriUnici.map((a) => `${siteUrl}/autori/${a.slug}`);
const musicReleaseDate = canto.anno ? String(canto.anno) : undefined;
// === FINE NUOVE PROPS ===

const jsonLd = [
  buildCreativeWorkSchema(canto, siteUrl, ogImage, eventi),
  buildBreadcrumbSchema(breadcrumbItems.map((item) => ({ name: item.label, url: item.href ? `${siteUrl}${item.href}` : undefined }))),
];
---
<BaseLayout
  title={buildCantoTitle(canto)}
  pagefindTitle={canto.titolo}
  description={buildCantoDescription(canto)}
  pagefindType="canti"
  ogType="music.composition"  <!-- MODIFICA: da music.song -->
  ogImage={ogImage}
  ogImageAlt={ogImage ? `Immagine per ${canto.titolo}` : undefined}
  articleSection="Canti di protesta"
  articleTags={canto.tags.map((t) => t.titolo)}
  publishedTime={canto.dataCreazione}  <!-- Esistente -->
  modifiedTime={canto.dataModifica}  <!-- AGGIUNTA -->
  musicMusicians={musicMusicians}  <!-- AGGIUNTA -->
  musicReleaseDate={musicReleaseDate}  <!-- AGGIUNTA -->
  jsonLd={jsonLd}
>
  <!-- ... resto del template invariato ... -->
</BaseLayout>
```

**MODIFICHE APPLICATE:**
- ✅ Cambiato `ogType` da `"music.song"` a `"music.composition"`
- ✅ Aggiunta `modifiedTime={canto.dataModifica}`
- ✅ Aggiunta `musicMusicians` e `musicReleaseDate`

---

#### **4.4.2 Pagina Autore: `[slug].astro`**

```astro
---
// ... import invariati ...

// ... getStaticPaths() e logica invariati ...

// ... jsonLd invariato (buildProfilePageSchema già gestisce le nuove prop) ...
---
<BaseLayout
  title={buildAutoreTitle(autore)}
  description={buildAutoreDescription(autore, tuttiCanti.length)}
  pagefindType="autori"
  ogType="profile"
  ogImage={ogImage}
  ogImageAlt={ogImage ? `Foto di ${autore.titolo}` : undefined}
  profileFirstName={autore.nome || undefined}
  profileLastName={autore.cognome || undefined}
  profileUsername={autore.slug}
  publishedTime={autore.dataCreazione}  <!-- Se esiste in types.ts -->
  modifiedTime={autore.dataModifica}  <!-- AGGIUNTA -->
  jsonLd={jsonLd}
>
  <!-- ... resto invariato ... -->
</BaseLayout>
```

**MODIFICHE APPLICATE:**
- ✅ Aggiunta `modifiedTime={autore.dataModifica}`

---

#### **4.4.3 Pagina Evento: `[slug].astro`**

```astro
---
// ... import e logica invariati ...

// === NUOVE PROPS PER MUSIC NAMESPACE (eventi collegano canti) ===
const musicMusicians = evento.cantiCollegati
  ?.flatMap((c) => {
    // Nota: i cantiCollegati non hanno autori espansi — questa prop è opzionale
    // Se volessimo i musician link, dovremmo fetchare gli autori per ogni canto
    // Per ora omettiamo, o aggiungiamo solo se i dati sono disponibili
    return [];
  })
  .filter(Boolean) ?? [];
// === FINE NUOVE PROPS ===

const jsonLd = [
  buildEventSchema(evento, siteUrl, ogImage),
  buildBreadcrumbSchema(breadcrumbItems.map((item) => ({ name: item.label, url: item.href ? `${siteUrl}${item.href}` : undefined }))),
];
---
<BaseLayout
  title={buildEventoTitle(evento)}
  description={buildEventoDescription(evento)}
  pagefindType="eventi"
  ogType="event"
  ogImage={ogImage}
  ogImageAlt={ogImage ? `Immagine per ${evento.titolo}` : undefined}
  publishedTime={publishedTime}
  modifiedTime={evento.dataModifica}  <!-- AGGIUNTA -->
  eventStartTime={eventStartTime}
  eventEndTime={eventEndTime}
  eventLocation={eventLocation}
  eventTimezone={eventTimezone}
  articleSection="Eventi storici"
  articleTags={evento.tags.map((t) => t.titolo)}
  musicMusicians={musicMusicians.length > 0 ? musicMusicians : undefined}  <!-- AGGIUNTA (opzionale) -->
  jsonLd={jsonLd}
  dnsPrefetch={hasCoordinate ? ['https://tile.openstreetmap.org'] : undefined}
>
  <!-- ... resto invariato ... -->
</BaseLayout>
```

**MODIFICHE APPLICATE:**
- ✅ Aggiunta `modifiedTime={evento.dataModifica}`
- ✅ Aggiunta `musicMusicians` (opzionale, richiede fetch autori — implementabile in futuro)

---

#### **4.4.4 Pagina Traduzione: `[slug].astro`**

```astro
---
// ... import invariati ...

// ... getStaticPaths() invariato ...

const { traduzione } = Astro.props as { traduzione: TraduzioneDetail };

// ... resto logica invariata ...

const jsonLd = [
  buildTranslationSchema(traduzione, siteUrl),
  buildBreadcrumbSchema(breadcrumbItems.map((item) => ({ name: item.label, url: item.href ? `${siteUrl}${item.href}` : undefined }))),
];
---
<BaseLayout
  title={buildTraduzioneTitle(traduzione)}
  description={buildTraduzioneDescription(traduzione)}
  pagefindType="traduzioni"
  ogType="music.composition"  <!-- MODIFICA: da article a music.composition -->
  publishedTime={traduzione.dataCreazione}
  modifiedTime={traduzione.dataModifica}  <!-- AGGIUNTA -->
  articleSection="Traduzioni"  <!-- Mantenuto per compatibilità -->
  jsonLd={jsonLd}
>
  <!-- ... resto invariato ... -->
</BaseLayout>
```

**MODIFICHE APPLICATE:**
- ✅ Cambiato `ogType` da `"article"` a `"music.composition"`
- ✅ Aggiunta `modifiedTime={traduzione.dataModifica}`

---

### 4.5 Riepilogo Modifiche Implementate

| File | Modifiche | Impatto |
|------|-----------|---------|
| **`schema.js`** | • Aggiunte funzioni helper: `addIfValid`, `extractMusicalKey`, `deduceMusicCompositionForm`<br>• `buildCreativeWorkSchema`: +`musicCompositionForm`, +`musicalKey`, +`category`<br>• `buildPersonSchema`: +`jobTitle` (Person), +`genre` (MusicGroup)<br>• `buildEventSchema`: +`eventAttendanceMode`, +`category`<br>• `buildTranslationSchema`: `@type` → `MusicComposition`, +`lyrics` | ✅ Miglioramento semantico senza breaking changes |
| **`SEO.astro`** | • +`modifiedTime` prop<br>• +`musicMusicians` prop (array)<br>• +`musicReleaseDate` prop<br>• +`og:image:secure_url` | ✅ Meta tag più completi |
| **`canti/[slug].astro`** | • `ogType`: `"music.song"` → `"music.composition"`<br>• +`modifiedTime`, +`musicMusicians`, +`musicReleaseDate` | ✅ Allineamento OG Type |
| **`traduzioni/[slug].astro`** | • `ogType`: `"article"` → `"music.composition"`<br>• +`modifiedTime` | ✅ Coerenza con canti |
| **`autori/[slug].astro`** | • +`modifiedTime` | ✅ Completezza metadati |
| **`eventi/[slug].astro`** | • +`modifiedTime`<br>• +`musicMusicians` (opzionale) | ✅ Completezza metadati |

---

## FASE 5: VALIDAZIONE E TEST

### 5.1 Checklist Validazione Schema.org

**Strumenti:**
- [Google Rich Results Test](https://search.google.com/test/rich-results)
- [Schema.org Validator](https://validator.schema.org/)
- [Google Search Console](https://search.google.com/search-console)

**Test da Eseguire:**

1. ✅ **Canto:** Validare `MusicComposition` con tutte le proprietà (lyrics, recordedAs, about, etc.)
2. ✅ **Autore:** Validare `Person`/`MusicGroup` e `ProfilePage` wrapper
3. ✅ **Evento:** Validare `Event` con location, eventAttendanceMode, subjectOf
4. ✅ **Traduzione:** Validare `MusicComposition` con `translationOfWork`
5. ✅ **Tassonomie:** Validare `DefinedTermSet` e `DefinedTerm`
6. ✅ **Breadcrumb:** Validare `BreadcrumbList` su tutte le pagine
7. ✅ **@graph riconciliazione:** Verificare che `@id` condivisi funzionino correttamente

---

### 5.2 Checklist Validazione Open Graph

**Strumenti:**
- [Facebook Sharing Debugger](https://developers.facebook.com/tools/debug/)
- [LinkedIn Post Inspector](https://www.linkedin.com/post-inspector/)
- [Twitter Card Validator](https://cards-dev.twitter.com/validator)

**Test da Eseguire:**

1. ✅ **Canti:** Verificare preview con `music.composition` type e musician links
2. ✅ **Autori:** Verificare preview profile con nome/cognome/username
3. ✅ **Eventi:** Verificare preview event con date/location
4. ✅ **Immagini:** Verificare dimensioni 1200×630 e `og:image:secure_url`
5. ✅ **Fallback:** Verificare immagini di default quando mancano

---

### 5.3 Monitoraggio Post-Deploy

**Metriche da Monitorare:**

1. **Google Search Console:**
   - Incremento rich results (Music, Event, Person)
   - Errori Schema.org (dovrebbero azzerarsi)
   - CTR organico su pagine canti/autori

2. **Social Sharing:**
   - Preview corretto su Facebook/Twitter/LinkedIn
   - Engagement rate su link condivisi

3. **Performance:**
   - Nessun impatto negativo su LCP/CLS (JSON-LD è non-blocking)
   - Dimensione HTML: +2-5KB per pagina (accettabile)

---

## CONCLUSIONI

### Impatto del Refactoring

**✅ PUNTI DI FORZA PRESERVATI:**
- Architettura solida con `@graph` e `@id` per riconciliazione entità
- Relazioni semantiche bidirezionali (Canto ↔ Evento, Canto ↔ Autore)
- Tassonomie come vocabolari controllati (`DefinedTermSet`/`DefinedTerm`)
- Separazione concerns (schema.js, seo.js, SEO.astro)

**🚀 MIGLIORAMENTI INTRODOTTI:**
- Open Graph Types corretti (`music.composition` invece di `music.song`)
- Proprietà Schema.org mancanti aggiunte (dove dati disponibili)
- Meta tag più completi (`article:modified_time`, `music:musician`, `og:image:secure_url`)
- Traduzioni semanticamente corrette (`MusicComposition` invece di `CreativeWork`)
- Deduzioni automatiche (`musicCompositionForm`, `musicalKey`, `jobTitle`, `genre`)

**⚙️ MODIFICHE CONSERVATIVE:**
- ✅ Nessuna breaking change alle API esistenti
- ✅ Tutte le funzioni mantengono signature originale
- ✅ Aggiunte solo proprietà opzionali
- ✅ Backward compatible con build esistenti

### Roadmap Futura

**Miglioramenti Dati (Drupal):**
1. Campo "Traduttore" per traduzioni → `translator` property
2. Campo "Membri del Gruppo" per autori collettivi → `member` property
3. Campo "Tonalità" per canti → `musicalKey` property (input strutturato)
4. Campo "Durata Video" → `music:duration` in OG

**Ottimizzazioni Tecniche:**
1. Generazione automatica immagini OG con testo sovrapposto (testo canto, nome autore)
2. Sitemap XML con `<image:image>` per ogni entità
3. Hreflang tags per traduzioni in altre lingue (quando/se disponibili)

---

**Fine Documento**
