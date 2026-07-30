# Guida Test & Validazione — Schema.org & Open Graph

**Versione:** 1.0  
**Aggiornato:** 2026-07-30

---

## 📋 Quick Start

```bash
# 1. Build frontend locale
cd frontend
npm run build

# 2. Verifica che non ci siano errori TypeScript/Astro
# 3. Testa su URL di staging prima del deploy produzione
```

---

## 🧪 Suite di Test Completa

### **FASE 1: Validazione Schema.org**

#### **Strumenti:**

| Tool | URL | Note |
|------|-----|------|
| Google Rich Results Test | https://search.google.com/test/rich-results | ⭐ Principale — mostra come Google vede il markup |
| Schema.org Validator | https://validator.schema.org/ | Validazione spec ufficiale |
| Google Search Console | https://search.google.com/search-console | Post-deploy: errori Schema.org indicizzati |

#### **Test Case 1.1: Pagina Canto**

**URL Test:** `https://stage.ildeposito.org/canti/bella-ciao`

**Checklist Validazione:**

```
✅ @type: MusicComposition
✅ @id: presente e formato corretto (#composition)
✅ name: titolo canto
✅ url: URL assoluto corretto
✅ inLanguage: codice ISO BCP 47 (es. "it")
✅ lyrics: presente con @type CreativeWork
✅ author: array di Person/MusicGroup con @id
✅ composer: presente se autori musica
✅ lyricist: presente se autori testo
✅ dateCreated: anno canto (es. "1944")
✅ datePublished: data creazione nodo
✅ dateModified: data modifica nodo
✅ genre: array tematiche
✅ category: array tematiche (duplicato per rafforzare)
✅ keywords: stringa comma-separated tag
✅ temporalCoverage: array periodi
✅ about: array misto Eventi + DefinedTerm
✅ recordedAs: MusicRecording → VideoObject (se video presente)
✅ associatedMedia: DigitalDocument PDF
✅ musicCompositionForm: "Canzone", "Inno", etc. (se riconosciuto)
```

**Rich Results Attesi:**
- ✅ Google potrebbe mostrare "Music" rich result
- ✅ Knowledge Graph potrebbe collegare autori/eventi

**Errori da Ignorare:**
- ⚠️ `offers` missing → Corretto per archivio non commerciale
- ⚠️ `performer` missing → Corretto per composizione storica

---

#### **Test Case 1.2: Pagina Autore**

**URL Test:** `https://stage.ildeposito.org/autori/fabrizio-de-andre`

**Checklist Validazione:**

```
✅ ProfilePage @type presente (wrapper)
✅ mainEntity: Person o MusicGroup
✅ Person @id: formato #autore
✅ name: titolo autore
✅ givenName: nome (solo Person)
✅ familyName: cognome (solo Person)
✅ birthDate: anno nascita (solo Person)
✅ deathDate: anno morte (solo Person)
✅ nationality: Country (solo Person)
✅ colleague: autori correlati (solo Person)
✅ sameAs: array link esterni
✅ about: array DefinedTerm (localizzazioni, periodi)
✅ hasPart: ItemList di MusicComposition (max 50)
```

**Rich Results Attesi:**
- ✅ Knowledge Panel biografico (se autore noto)
- ✅ Opere collegate nel knowledge graph

---

#### **Test Case 1.3: Pagina Evento**

**URL Test:** `https://stage.ildeposito.org/eventi/25-aprile-1945`

**Checklist Validazione:**

```
✅ @type: Event
✅ @id: formato #evento
✅ name: titolo evento
✅ url: URL assoluto
✅ startDate: data ISO (YYYY-MM-DD)
✅ endDate: stesso giorno di startDate
✅ location: Place con name e address
✅ location.geo: GeoCoordinates (se coordinate disponibili)
✅ eventStatus: EventScheduled
✅ eventAttendanceMode: OfflineEventAttendanceMode
✅ organizer: Organization ilDeposito.org
✅ description: informazioni evento
✅ genre: array tematiche
✅ category: array tematiche
✅ keywords: stringa tag
✅ about: array DefinedTerm (periodi, tag, tematiche)
✅ subjectOf: array MusicComposition (canti collegati)
```

**Rich Results Attesi:**
- ✅ Event rich snippet con data/luogo
- ⚠️ Non apparirà in "Eventi prossimi" (sono eventi storici passati)

---

#### **Test Case 1.4: Pagina Traduzione**

**URL Test:** `https://stage.ildeposito.org/traduzioni/...`

**Checklist Validazione:**

```
✅ @type: MusicComposition (non più CreativeWork)
✅ name: titolo traduzione
✅ url: URL assoluto
✅ inLanguage: lingua destinazione
✅ translationOfWork: MusicComposition originale con @id
✅ lyrics: testo tradotto (CreativeWork)
✅ description: informazioni traduzione
✅ datePublished: data creazione
✅ dateModified: data modifica
```

---

#### **Test Case 1.5: Pagina Tassonomia (Periodo)**

**URL Test:** `https://stage.ildeposito.org/periodi/resistenza`

**Checklist Validazione:**

```
✅ @type: DefinedTerm
✅ name: nome termine
✅ description: descrizione periodo
✅ url: URL assoluto
✅ inDefinedTermSet: DefinedTermSet con name "Periodi" e url
✅ BreadcrumbList presente
✅ ItemList presente (canti/autori/eventi del periodo)
```

---

### **FASE 2: Validazione Open Graph**

#### **Strumenti:**

| Tool | URL | Note |
|------|-----|------|
| Facebook Sharing Debugger | https://developers.facebook.com/tools/debug/ | ⭐ Principale per preview social |
| LinkedIn Post Inspector | https://www.linkedin.com/post-inspector/ | Validazione LinkedIn |
| Twitter Card Validator | https://cards-dev.twitter.com/validator | Validazione Twitter |
| Open Graph Debugger | https://www.opengraphcheck.com/ | Tool generico |

#### **Test Case 2.1: OG Type Corretto per Canti**

**URL Test:** `https://stage.ildeposito.org/canti/bella-ciao`

**Checklist OG:**

```
✅ og:type: "music.composition" (NON "music.song")
✅ og:title: titolo canto
✅ og:description: descrizione (capoverso o informazioni)
✅ og:url: URL canonico
✅ og:image: immagine assoluta HTTPS
✅ og:image:secure_url: stesso URL se HTTPS
✅ og:image:width: 1200
✅ og:image:height: 630
✅ og:image:alt: descrizione immagine
✅ og:site_name: "ilDeposito.org"
✅ og:locale: "it_IT"
✅ article:published_time: data creazione
✅ article:modified_time: data modifica
✅ article:section: "Canti di protesta"
✅ article:tag: ogni tag (multipli)
✅ music:musician: URL autori (multipli)
✅ music:release_date: anno canto
```

**Preview Attesa:**
- ✅ Immagine grande (summary_large_image)
- ✅ Titolo + descrizione leggibili
- ✅ URL corretto senza parametri

---

#### **Test Case 2.2: OG Type Profile per Autori**

**URL Test:** `https://stage.ildeposito.org/autori/fabrizio-de-andre`

**Checklist OG:**

```
✅ og:type: "profile"
✅ og:title: nome autore
✅ og:description: biografia breve
✅ profile:first_name: nome
✅ profile:last_name: cognome
✅ profile:username: slug autore
✅ article:published_time: data creazione nodo
✅ article:modified_time: data modifica nodo
```

---

#### **Test Case 2.3: OG Type Event per Eventi**

**URL Test:** `https://stage.ildeposito.org/eventi/25-aprile-1945`

**Checklist OG:**

```
✅ og:type: "event"
✅ event:start_time: data evento ISO
✅ event:end_time: data evento ISO (stesso giorno)
✅ event:location: località
✅ event:timezone: "Europe/Rome"
✅ article:modified_time: data modifica
```

---

#### **Test Case 2.4: OG per Traduzioni**

**URL Test:** `https://stage.ildeposito.org/traduzioni/...`

**Checklist OG:**

```
✅ og:type: "music.composition" (NON "article")
✅ article:published_time: data creazione
✅ article:modified_time: data modifica
✅ article:section: "Traduzioni"
```

---

### **FASE 3: Test Funzionali Frontend**

#### **Test Case 3.1: Build Senza Errori**

```bash
cd frontend
npm run build

# ✅ Atteso: "Build successful" senza errori TypeScript
# ⚠️ Warnings accettabili: deprecation warnings di librerie terze
```

#### **Test Case 3.2: Dimensione Bundle**

```bash
du -sh dist/

# ✅ Atteso: dimensione totale ~10-15 MB (variabile, +2-3MB rispetto a prima)
# ✅ Nessun file HTML > 500 KB (indica problema di generazione JSON-LD)
```

#### **Test Case 3.3: Rendering Pagine**

```bash
# Con dev server:
npm run dev
# Visita manualmente:
# - http://localhost:4321/canti/bella-ciao
# - http://localhost:4321/autori/...
# - http://localhost:4321/eventi/...
# - http://localhost:4321/traduzioni/...

# ✅ Nessun errore console JavaScript
# ✅ JSON-LD visibile in "View Source" (non solo in DevTools)
# ✅ Meta tag OG corretti in <head>
```

#### **Test Case 3.4: Regressione Visuale**

**Checklist UI Invariata:**

```
✅ Layout pagine identico a prima
✅ Nessun elemento spostato/nascosto
✅ Interazioni JavaScript funzionanti (modal, carousel, etc.)
✅ Pagefind search funziona correttamente
```

---

### **FASE 4: Test Post-Deploy**

#### **Test Case 4.1: Google Search Console**

**Azioni (7-14 giorni dopo deploy):**

1. Accedi a https://search.google.com/search-console
2. Seleziona proprietà `ildeposito.org`
3. Vai a **"Miglioramenti"** → **"Dati strutturati"**
4. Verifica che:
   - ✅ Nuovi tipi rilevati: `MusicComposition`, `Person`, `Event`, `ProfilePage`
   - ✅ Errori diminuiti (idealmente zero)
   - ✅ Avvisi gestiti (alcuni warning sono accettabili)

5. Usa **"Controllo URL"** su pagine rappresentative:
   - Inserisci URL
   - **"Richiedi indicizzazione"** se necessario
   - Controlla che i rich results appaiano

#### **Test Case 4.2: Rich Results in SERP**

**Query Test (manuale su Google):**

```
site:ildeposito.org "Bella Ciao"
site:ildeposito.org "Fabrizio De André"
site:ildeposito.org "25 aprile 1945"
```

**Rich Results Attesi (variabili, dipende da Google):**

- ✅ Breadcrumb visibili
- ✅ Sitelinks estesi
- ⏳ Knowledge Panel per autori noti (può richiedere settimane/mesi)
- ⏳ Rich snippet musicali (sperimentale, non garantito)

#### **Test Case 4.3: Social Sharing Test**

**Procedura:**

1. Condividi un URL di canto/autore/evento su:
   - Facebook (bacheca privata o gruppo test)
   - Twitter
   - LinkedIn
   - WhatsApp (preview automatica)

2. Verifica preview:
   - ✅ Immagine corretta e di qualità
   - ✅ Titolo leggibile
   - ✅ Descrizione pertinente
   - ✅ URL pulito (no parametri tracking inutili)

---

## 🐛 Troubleshooting Comuni

### **Problema 1: Facebook Mostra Immagine Vecchia**

**Causa:** Cache CDN Facebook  
**Soluzione:**
1. Vai su https://developers.facebook.com/tools/debug/
2. Inserisci URL
3. Clicca **"Scrape Again"** per forzare refresh cache

---

### **Problema 2: Google Rich Results Test Non Trova Schema.org**

**Causa:** Possibili:
- JSON-LD non nel `<head>` o all'inizio `<body>`
- Errore sintassi JSON
- `@context` mancante

**Soluzione:**
1. **"View Page Source"** sull'URL → cerca `<script type="application/ld+json">`
2. Copia JSON in https://jsonlint.com/ → valida sintassi
3. Controlla che `@context` sia presente: `"@context": "https://schema.org"`

---

### **Problema 3: TypeScript Error su `modifiedTime`**

**Causa:** Props interface non aggiornata in file che usano `<BaseLayout>`

**Soluzione:**
Assicurati che tutti i template Astro che usano `<BaseLayout>` abbiano il tipo corretto importato:

```astro
---
// Verifica che BaseLayout.astro abbia la Props interface aggiornata
import BaseLayout from '../../layouts/BaseLayout.astro';
---
```

---

### **Problema 4: OG Image Non Appare**

**Causa:** Possibili:
- URL immagine relativo invece di assoluto
- Immagine troppo piccola (< 200×200)
- Immagine non accessibile (404, 403)

**Soluzione:**
1. Controlla che `og:image` sia URL assoluto HTTPS
2. Testa URL immagine in un browser in incognito
3. Dimensione minima raccomandata: 1200×630 px

---

## 📊 Metriche di Successo

### **KPI da Monitorare (3-6 mesi post-deploy):**

| Metrica | Baseline (pre-refactoring) | Target | Metodo Misurazione |
|---------|----------------------------|--------|---------------------|
| Errori Schema.org | X | 0 | Google Search Console |
| CTR organico canti | Y% | +5-10% | GSC / Google Analytics |
| Condivisioni social | Z/mese | +15-20% | Analytics eventi social |
| Rich results impressions | ? | Trend crescente | GSC Rich Results Report |
| Knowledge Panel apparizioni | ? | Incremento | Manual checks + Brand SERP |

### **Report Consigliati:**

**Mensile:**
- Screenshot rich results su query chiave
- Export errori Schema.org da GSC
- Analisi CTR per tipo di pagina (canti vs autori vs eventi)

**Trimestrale:**
- Confronto traffico organico pre/post refactoring
- Analisi sentiment condivisioni social (se applicabile)
- Review manuale SERP per query brand + entity

---

## 📝 Checklist Finale

Prima di chiudere il ticket di refactoring:

```
□ Build locale senza errori
□ Validazione Schema.org su 5+ URL (Google Rich Results Test)
□ Validazione OG su Facebook/LinkedIn/Twitter
□ Test regressione UI (nessun breaking change)
□ Deploy su staging e test completo
□ Documentazione aggiornata (questo file + REFACTORING_SUMMARY.md)
□ Team informato delle modifiche
□ Google Search Console monitorato (almeno 7 giorni post-deploy)
□ Primo report metriche baseline generato
```

---

**Fine Guida** — Buon test! 🚀
