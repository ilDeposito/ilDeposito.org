# Piano SEO — ilDeposito.org (Frontend Astro)

## Contesto

Il frontend Astro di ilDeposito.org ha già una buona base SEO: componente `SEO.astro` centralizzato, schema.org per canti/autori/eventi, sitemap, canonical automatici, `robots.txt`. Questa analisi identifica le lacune e propone interventi concreti per massimizzare la visibilità organica di un archivio di canti di protesta con ~migliaia di pagine statiche.

---

## 1. Title e Description — Ottimizzazione per SERP

### 1.1 Parametro MAX_TITLE_LEN

**File:** `src/lib/seo.js`

**Attuale:** `MAX_TITLE_LEN = 47` → titolo totale max ~64 chars
**Proposta:** `MAX_TITLE_LEN = 53` → titolo totale max ~70 chars

Il suffisso ` | ilDeposito.org` è 17 chars. Con 53 chars di contenuto si arriva a ~70, accettabile per Google desktop (mostra ~60 chars ma indicizza tutto).

### 1.2 Funzioni helper per title e description

Per gestire i campi opzionali in modo pulito, creare funzioni helper in `src/lib/seo.js`. Ogni funzione compone il testo progressivamente in base ai dati disponibili.

```js
// ── Title helpers ──────────────────────────────────────

export function buildCantoTitle(canto) {
  // "Bella Ciao — Testo e accordi" oppure "Bella Ciao — Testo"
  const parti = [canto.titolo, '—', 'Testo'];
  if (canto.accordi) parti.push('e accordi');
  return parti.join(' ');
}

export function buildAutoreTitle(autore) {
  // "Fabrizio De André — Canti e biografia"
  return `${autore.titolo} — Canti e biografia`;
}

export function buildEventoTitle(evento) {
  // "Strage di Piazza Fontana (1969)" oppure "Strage di Piazza Fontana"
  if (evento.dataEvento) {
    const anno = new Date(evento.dataEvento).getUTCFullYear();
    return `${evento.titolo} (${anno})`;
  }
  return evento.titolo;
}

export function buildTraduzioneTitle(traduzione) {
  // "Bella Ciao — Traduzione in francese" oppure "Bella Ciao"
  const lingua = traduzione.lingue?.[0]?.titolo;
  return lingua
    ? `${traduzione.titolo} — Traduzione in ${lingua}`
    : traduzione.titolo;
}

// ── Description helpers ────────────────────────────────

export function buildCantoDescription(canto) {
  // Composizione progressiva: "Testo[, accordi] di {titolo}[ ({anno})].[ {capoverso}]"
  const risorse = ['Testo'];
  if (canto.accordi) risorse.push('accordi');

  let desc = `${risorse.join(', ')} di ${canto.titolo}`;
  if (canto.anno) desc += ` (${canto.anno})`;
  desc += '.';

  // Appende il capoverso (o informazioni) come riempitivo
  const extra = canto.capoverso || stripHtml(canto.informazioni || '');
  if (extra) desc += ` ${extra}`;

  return desc;  // buildDescription() troncherà a MAX_DESC_LEN
}

export function buildAutoreDescription(autore) {
  // Se ha informazioni proprie, usarle come base
  if (autore.informazioni) return stripHtml(autore.informazioni);

  // Altrimenti composizione: "Biografia e canti di {nome}[, da {luogo}]."
  let desc = `Biografia e canti di ${autore.titolo}`;
  if (autore.localizzazioni?.length > 0) {
    desc += `, da ${autore.localizzazioni[0].titolo}`;
  }
  desc += '. Nell\'archivio di ilDeposito.org.';
  return desc;
}

export function buildEventoDescription(evento) {
  // Se ha informazioni proprie, usarle
  if (evento.informazioni) return stripHtml(evento.informazioni);

  // Composizione: "{titolo}[, {data}][ — {luogo}]. Evento storico…"
  let desc = evento.titolo;
  if (evento.dataEvento) {
    const d = new Date(evento.dataEvento);
    desc += `, ${d.toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' })}`;
  }
  if (evento.localizzazioni?.length > 0) {
    desc += ` — ${evento.localizzazioni[0].titolo}`;
  }
  desc += '. Evento storico nell\'archivio di ilDeposito.org.';
  return desc;
}

export function buildTraduzioneDescription(traduzione) {
  // Se ha informazioni proprie, usarle
  if (traduzione.informazioni) return stripHtml(traduzione.informazioni);

  // Composizione: "Traduzione[ in {lingua}] di {cantoOriginale||titolo}…"
  const parti = ['Traduzione'];
  if (traduzione.lingue?.length > 0) {
    parti.push(`in ${traduzione.lingue[0].titolo}`);
  }
  const nome = traduzione.cantoOriginale?.titolo || traduzione.titolo;
  parti.push(`di ${nome}.`);
  parti.push('Testo completo nell\'archivio di ilDeposito.org.');
  return parti.join(' ');
}
```

### 1.3 Template title e description per ogni tipo di pagina

> **Campi nullable**: `anno`, `capoverso`, `accordi`, `informazioni`, `lingue[]`, `autoriTesto[]`, `autoriMusica[]`, `localizzazioni[]`, `periodi[]`, `dataEvento`, `cantoOriginale`, `immagine`, `annoNascita`, `annoMorte` sono tutti opzionali. I template sotto mostrano l'output in base a cosa è disponibile.

#### Homepage (`index.astro`)

| Campo | Attuale | Proposta |
|---|---|---|
| title | `Home` | `Canti di protesta politica e sociale` |
| description | OK (già buona) | `Archivio online di canti di protesta politica e sociale: testi, accordi, traduzioni, autori e eventi storici.` |

**Note:** "Home" nel title spreca keyword preziose. La homepage deve posizionarsi per la query brand + keyword primaria.

---

#### Canti — Dettaglio (`canti/[slug].astro`)

Usare `buildCantoTitle(canto)` e `buildCantoDescription(canto)`.

| Scenario | Title risultante | Description risultante |
|---|---|---|
| Con accordi + anno + capoverso | `Bella Ciao — Testo e accordi \| ilDeposito.org` | `Testo, accordi di Bella Ciao (1944). La mattina mi son svegliato…` |
| Senza accordi, con anno | `Bella Ciao — Testo \| ilDeposito.org` | `Testo di Bella Ciao (1944). La mattina mi son svegliato…` |
| Senza accordi, senza anno, con informazioni | `Bella Ciao — Testo \| ilDeposito.org` | `Testo di Bella Ciao. Canto della resistenza partigiana…` |
| Solo titolo e testo | `Bella Ciao — Testo \| ilDeposito.org` | `Testo di Bella Ciao.` |

- **og:type:** `article` (invariato)
- **Canti senza autore:** omettere l'autore dal title e dallo schema (non usare "Anonimo")

#### Canti — Elenco (`canti/index.astro`)

| Campo | Attuale | Proposta |
|---|---|---|
| title | `I canti dell'archivio` | `Canti di protesta — Archivio completo` |
| description | `La pagina di accesso all'archivio dei canti` | `Esplora l'archivio completo dei canti di protesta politica e sociale: testi, accordi, traduzioni e informazioni storiche.` |

---

#### Autori — Dettaglio (`autori/[slug].astro`)

Usare `buildAutoreTitle(autore)` e `buildAutoreDescription(autore)`.

| Scenario | Title risultante | Description risultante |
|---|---|---|
| Con informazioni | `De André — Canti e biografia \| ilDeposito.org` | `{informazioni troncate a 155 chars}` |
| Senza informazioni, con localizzazione | `De André — Canti e biografia \| ilDeposito.org` | `Biografia e canti di De André, da Genova. Nell'archivio di ilDeposito.org.` |
| Senza informazioni né localizzazione | `De André — Canti e biografia \| ilDeposito.org` | `Biografia e canti di De André. Nell'archivio di ilDeposito.org.` |

- **og:type:** `profile` (invariato)

#### Autori — Elenco (`autori/index.astro`)

| Campo | Attuale | Proposta |
|---|---|---|
| title | `Gli autori dell'archivio` | `Autori e compositori — Archivio` |
| description | `I compositori e autori dei canti dell'archivio` | `Gli autori e compositori dei canti di protesta dell'archivio: biografie, discografie e opere.` |

---

#### Eventi — Dettaglio (`eventi/[slug].astro`)

Usare `buildEventoTitle(evento)` e `buildEventoDescription(evento)`.

| Scenario | Title risultante | Description risultante |
|---|---|---|
| Con data + localizzazione + informazioni | `Strage di P. Fontana (1969) \| ilDeposito.org` | `{informazioni troncate a 155 chars}` |
| Con data + localizzazione, senza informazioni | `Strage di P. Fontana (1969) \| ilDeposito.org` | `Strage di Piazza Fontana, 12 dicembre 1969 — Milano. Evento storico…` |
| Senza data, con localizzazione | `Strage di Piazza Fontana \| ilDeposito.org` | `Strage di Piazza Fontana — Milano. Evento storico…` |
| Solo titolo | `Strage di Piazza Fontana \| ilDeposito.org` | `Strage di Piazza Fontana. Evento storico nell'archivio di ilDeposito.org.` |

- **og:type:** `article` (invariato)
- **Note:** "dashboard" nell'elenco eventi va eliminato (gergo tecnico)

#### Eventi — Elenco (`eventi/index.astro`)

| Campo | Attuale | Proposta |
|---|---|---|
| title | `Gli eventi dell'archivio` | `Eventi storici — Archivio` |
| description | `La dashboard degli eventi dell'archivio` | `Gli eventi storici legati ai canti di protesta: date, luoghi e canti collegati nell'archivio di ilDeposito.org.` |

---

#### Traduzioni — Dettaglio (`traduzioni/[slug].astro`)

Usare `buildTraduzioneTitle(traduzione)` e `buildTraduzioneDescription(traduzione)`.

| Scenario | Title risultante | Description risultante |
|---|---|---|
| Con lingua + canto originale + informazioni | `Bella Ciao — Traduzione in francese \| ilDeposito.org` | `{informazioni troncate}` |
| Con lingua + canto originale, senza informazioni | `Bella Ciao — Traduzione in francese \| ilDeposito.org` | `Traduzione in francese di Bella Ciao. Testo completo nell'archivio…` |
| Con lingua, senza canto originale | `Bella Ciao — Traduzione in francese \| ilDeposito.org` | `Traduzione in francese di Bella Ciao. Testo completo…` |
| Senza lingua né canto originale | `Bella Ciao \| ilDeposito.org` | `Traduzione di Bella Ciao. Testo completo nell'archivio…` |

- **og:type:** `article` (invariato)

#### Traduzioni — Elenco (`traduzioni/index.astro`)

| Campo | Attuale | Proposta |
|---|---|---|
| title | `Le traduzioni dell'archivio` | `Traduzioni dei canti — Archivio` |
| description | `L'elenco completo delle traduzioni dell'archivio` | `Le traduzioni dei canti di protesta in tutte le lingue: testi tradotti, lingue originali e versioni alternative.` |

---

#### Tassonomie — Lingue

**Index (`lingue/index.astro`):**

| Campo | Attuale | Proposta |
|---|---|---|
| title | `Lingue` | `Canti per lingua` |
| description | `Le lingue dei canti e delle traduzioni dell'archivio` | `Esplora i canti di protesta per lingua: italiano, francese, inglese, spagnolo e altre lingue nell'archivio.` |

**Detail (`lingue/[slug].astro`):**

| Campo | Attuale | Proposta |
|---|---|---|
| title | `{titolo}` (es. "Italiano") | `Canti in {titolo}` |
| description | `I contenuti in {titolo} dell'archivio` | `I canti di protesta e le traduzioni in {titolo} nell'archivio di ilDeposito.org.` |

---

#### Tassonomie — Localizzazioni

**Index (`localizzazioni/index.astro`):**

| Campo | Attuale | Proposta |
|---|---|---|
| title | `Localizzazioni` | `Luoghi — Autori e eventi per luogo` |
| description | `Le localizzazioni per gli autori e gli eventi dell'archivio` | `Autori e eventi storici per luogo di origine: esplora l'archivio di ilDeposito.org per località.` |

**Detail (`localizzazioni/[slug].astro`):**

| Campo | Attuale | Proposta |
|---|---|---|
| title | `{titolo}` (es. "Milano") | `{titolo} — Autori e eventi` |
| description | `I contenuti dell'archivio localizzati in {titolo}` | `Gli autori e gli eventi storici legati a {titolo} nell'archivio di ilDeposito.org.` |

---

#### Tassonomie — Tags

**Index (`tags/index.astro`):**

| Campo | Attuale | Proposta |
|---|---|---|
| title | `Tags` | `Argomenti — Canti per tema` |
| description | `L'elenco dei tag con cui sono catalogati i contenuti` | `Esplora i canti di protesta per argomento: lavoro, guerra, resistenza, emigrazione e altri temi.` |

**Detail (`tags/[slug].astro`):**

| Campo | Attuale | Proposta |
|---|---|---|
| title | `{titolo}` (es. "Resistenza") | `{titolo} — Canti e eventi` |
| description | `I contenuti catalogati con {titolo}` | `I canti di protesta e gli eventi storici sul tema "{titolo}" nell'archivio di ilDeposito.org.` |

---

#### Tassonomie — Periodi

**Index (`periodi/index.astro`):**

| Campo | Attuale | Proposta |
|---|---|---|
| title | `Periodi` | `Periodi storici — Archivio per epoca` |
| description | `L'elenco dei periodi storici in cui sono divisi i contenuti` | `Esplora i canti di protesta per periodo storico: Risorgimento, Grande Guerra, Resistenza, anni '60-'70 e oltre.` |

**Detail (`periodi/[slug].astro`):**

| Campo | Attuale | Proposta |
|---|---|---|
| title | `{titolo}` (es. "Resistenza") | `{titolo} — Canti, autori e eventi` |
| description | `I contenuti del periodo {titolo}` | `I canti, gli autori e gli eventi storici del periodo "{titolo}" nell'archivio di ilDeposito.org.` |

---

#### Calendario Cantato

**Index (`calendario-cantato/index.astro`):**

| Campo | Attuale | Proposta |
|---|---|---|
| title | `Calendario cantato` | `Calendario cantato — Anniversari storici` |
| description | `Gli anniversari degli eventi storici, giorno per giorno` | `Il calendario cantato di ilDeposito.org: gli anniversari degli eventi storici legati ai canti di protesta, giorno per giorno.` |

**Giorno (`calendario-cantato/[giorno].astro`):**

| Campo | Attuale | Proposta |
|---|---|---|
| title | `Calendario cantato: {day} {month}` | `{day} {month} — Calendario cantato` |
| description | `Gli eventi storici del {day} {month}` | `Gli eventi storici del {day} {month} nell'archivio di ilDeposito.org: anniversari, ricorrenze e canti collegati.` |

---

#### Pagine statiche (catch-all `[...percorso].astro`)

| Campo | Attuale | Proposta |
|---|---|---|
| title | `{pagina.titolo}` | `{pagina.titolo}` (OK, dipende dal contenuto CMS) |
| description | `{pagina.testo}` (testo HTML grezzo!) | `{stripHtml(pagina.testo)}` con troncamento a 155 chars (già gestito da buildDescription, ma verificare che il testo arrivi pulito) |

---

#### Pagina Cerca (`cerca.astro`)

| Campo | Attuale | Proposta |
|---|---|---|
| title | `Cerca nell'archivio` | OK (noindex, non rilevante per SEO) |
| noindex | `true` | OK |

#### Pagina 404 (`404.astro`)

| Campo | Attuale | Proposta |
|---|---|---|
| title | `Pagina non trovata` | OK |
| noindex | `true` | OK |

---

## 2. Schema.org — Dati Strutturati

### 2.1 Problemi attuali

1. **`inLanguage` usa nomi invece di codici ISO** — `"Italiano"` invece di `"it"`. Google richiede codici BCP 47.
2. **MusicComposition incompleto** — manca `lyrics`, `musicalKey` (se disponibile), `isPartOf` (archivio).
3. **Person schema minimale** — manca `birthDate`, `deathDate`, `birthPlace`, `nationality`.
4. **Event schema** — manca `description`, `about` (collegamento ai canti), `eventAttendanceMode` non necessario per eventi storici.
5. **Nessuno schema su pagine listing e tassonomie** — `buildCollectionPageSchema` e `buildWebPageSchema` esistono ma non vengono usati.
6. **Traduzioni senza schema** — nessun JSON-LD.
7. **Breadcrumb schema** — URL items mancanti (solo `name`, non `item` con URL completo).

### 2.2 Interventi

#### A. Mapping lingua → codice ISO

**File:** `src/lib/schema.js` (nuovo export) o `src/lib/seo.js`

```js
const LINGUA_TO_ISO = {
  'italiano': 'it',
  'inglese': 'en',
  'francese': 'fr',
  'spagnolo': 'es',
  'tedesco': 'de',
  'portoghese': 'pt',
  'catalano': 'ca',
  'basco': 'eu',
  'greco': 'el',
  'russo': 'ru',
  'arabo': 'ar',
  'turco': 'tr',
  'yiddish': 'yi',
  'napoletano': 'nap',
  'sardo': 'sc',
  'siciliano': 'scn',
  'piemontese': 'pms',
  'friulano': 'fur',
  'ladino': 'lld',
  'occitano': 'oc',
  'romeno': 'ro',
  'polacco': 'pl',
  'ebraico': 'he',
};

export function linguaToIso(nome) {
  return LINGUA_TO_ISO[nome?.toLowerCase()] || nome || 'it';
}
```

**Note:** Questo mapping andrà popolato con tutte le lingue effettivamente presenti nel DB. Verificare con una query a Drupal o leggendo la pagina `lingue/index`.

#### B. MusicComposition (Canti) — Schema migliorato

```js
export function buildCreativeWorkSchema(canto, siteUrl) {
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'MusicComposition',
    name: canto.titolo,
    url: `${siteUrl}/canti/${canto.slug}`,
    inLanguage: linguaToIso(canto.lingue?.[0]?.titolo),
    isPartOf: {
      '@type': 'CreativeWork',
      name: 'ilDeposito.org — Archivio canti di protesta',
      url: siteUrl,
    },
  };

  if (canto.capoverso) schema.description = canto.capoverso;

  if (canto.testo) {
    schema.lyrics = {
      '@type': 'CreativeWork',
      text: canto.testo.substring(0, 500),
      inLanguage: linguaToIso(canto.lingue?.[0]?.titolo),
    };
  }

  // Autori deduplicati (omettere se assenti — canto anonimo)
  const autori = [...(canto.autoriTesto ?? []), ...(canto.autoriMusica ?? [])]
    .filter((a, i, arr) => arr.findIndex((x) => x.slug === a.slug) === i);

  if (autori.length > 0) {
    schema.author = autori.map((a) => ({
      '@type': 'Person',
      name: a.titolo,
      url: `${siteUrl}/autori/${a.slug}`,
    }));

    // Distinguere autori testo vs musica
    if (canto.autoriTesto?.length > 0) {
      schema.lyricist = canto.autoriTesto.map((a) => ({
        '@type': 'Person',
        name: a.titolo,
        url: `${siteUrl}/autori/${a.slug}`,
      }));
    }
    if (canto.autoriMusica?.length > 0) {
      schema.composer = canto.autoriMusica.map((a) => ({
        '@type': 'Person',
        name: a.titolo,
        url: `${siteUrl}/autori/${a.slug}`,
      }));
    }
  }

  if (canto.anno) schema.dateCreated = String(canto.anno);

  // Genere/tema basato sulle tematiche
  if (canto.tematiche?.length > 0) {
    schema.genre = canto.tematiche.map((t) => t.titolo);
  }

  return schema;
}
```

#### C. Person (Autori) — Schema migliorato

```js
export function buildPersonSchema(autore, siteUrl) {
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'Person',
    name: autore.titolo,
    url: `${siteUrl}/autori/${autore.slug}`,
  };

  if (autore.immagine) {
    schema.image = `${siteUrl}/uploads/autori/${autore.slug}.jpg`;
  }

  if (autore.informazioni) {
    schema.description = stripHtml(autore.informazioni).substring(0, 200);
  }

  if (autore.annoNascita) schema.birthDate = String(autore.annoNascita);
  if (autore.annoMorte) schema.deathDate = String(autore.annoMorte);

  if (autore.localizzazioni?.length > 0) {
    schema.birthPlace = {
      '@type': 'Place',
      name: autore.localizzazioni[0].titolo,
    };
  }

  return schema;
}
```

**Note:** `annoNascita`, `annoMorte` e `localizzazioni` sono già disponibili in `AutoreDetail`. `informazioni` va importato con `stripHtml` da `seo.js`.

#### D. Event (Eventi) — Schema migliorato

```js
export function buildEventSchema(evento, siteUrl) {
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'Event',
    name: evento.titolo,
    url: `${siteUrl}/eventi/${evento.slug}`,
    eventAttendanceMode: 'https://schema.org/OfflineEventAttendanceMode',
  };

  if (evento.informazioni) {
    schema.description = stripHtml(evento.informazioni).substring(0, 200);
  }

  if (evento.dataEvento) {
    schema.startDate = new Date(evento.dataEvento).toISOString().split('T')[0];
  }

  if (evento.localizzazioni?.length > 0) {
    const loc = evento.localizzazioni[0];
    schema.location = {
      '@type': 'Place',
      name: loc.titolo,
    };
    if (evento.latitude != null && evento.longitude != null) {
      schema.location.geo = {
        '@type': 'GeoCoordinates',
        latitude: evento.latitude,
        longitude: evento.longitude,
      };
      schema.location.address = loc.titolo;
    }
  }

  // Collegamento ai canti correlati
  if (evento.cantiCollegati?.length > 0) {
    schema.about = evento.cantiCollegati.map((c) => ({
      '@type': 'MusicComposition',
      name: c.titolo,
      url: `${siteUrl}/canti/${c.slug}`,
    }));
  }

  return schema;
}
```

#### E. Traduzioni — Nuovo schema

Aggiungere `buildTranslationSchema` in `schema.js`:

```js
export function buildTranslationSchema(traduzione, siteUrl) {
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'CreativeWork',
    name: traduzione.titolo,
    url: `${siteUrl}/traduzioni/${traduzione.slug}`,
    inLanguage: linguaToIso(traduzione.lingue?.[0]?.titolo),
  };

  if (traduzione.cantoOriginale) {
    schema.translationOfWork = {
      '@type': 'MusicComposition',
      name: traduzione.cantoOriginale.titolo,
      url: `${siteUrl}/canti/${traduzione.cantoOriginale.slug}`,
      inLanguage: linguaToIso(traduzione.cantoOriginale.lingue?.[0]?.titolo),
    };
  }

  return schema;
}
```

Usare in `traduzioni/[slug].astro`:
```astro
jsonLd={[
  buildTranslationSchema(traduzione, siteUrl),
  buildBreadcrumbSchema(breadcrumbItems.map(…))
]}
```

#### F. Pagine listing — Aggiungere CollectionPage schema

Per tutte le pagine elenco (`canti/index`, `autori/index`, `eventi/index`, `traduzioni/index`) e le pagine tassonomia index:

```astro
jsonLd={buildCollectionPageSchema(seoTitle, seoDescription, canonical)}
```

La funzione `buildCollectionPageSchema` esiste già in `schema.js` ma non è mai usata.

#### G. Pagine tassonomia detail — Aggiungere WebPage schema

Per le pagine `lingue/[slug]`, `localizzazioni/[slug]`, `tags/[slug]`, `periodi/[slug]`:

```astro
jsonLd={[
  buildWebPageSchema(titolo, descrizione, canonical),
  buildBreadcrumbSchema(breadcrumbItems.map(…))
]}
```

#### H. Breadcrumb schema — Fix URL items

Attualmente il breadcrumb schema genera solo `name` senza URL per gli items intermedi. Correggere in tutte le pagine che lo usano, assicurandosi che ogni item (tranne l'ultimo) abbia l'URL completo:

```js
const breadcrumbSchema = buildBreadcrumbSchema([
  { name: 'Home', url: siteUrl },
  { name: 'Canti', url: `${siteUrl}/canti` },
  { name: canto.titolo },  // ultimo item: senza URL (pagina corrente)
]);
```

Verificare che le pagine `canti/[slug]`, `autori/[slug]`, `eventi/[slug]` passino le URL complete (con `siteUrl` prefix) e non percorsi relativi.

#### I. Homepage — Aggiungere BreadcrumbList

La homepage non ha breadcrumb schema. Aggiungere un BreadcrumbList con un solo item:
```js
buildBreadcrumbSchema([{ name: 'Home', url: siteUrl }])
```

---

## 3. Lingua e hreflang

### 3.1 Attributo `lang` HTML

**Attuale:** `<html lang="it">` hardcoded in BaseLayout.

**Problema:** I canti in altre lingue (francese, inglese, spagnolo, ecc.) hanno il testo in lingua straniera ma l'HTML è dichiarato come italiano. Per i motori di ricerca, il `lang` dell'HTML indica la lingua della UI/navigazione, non del contenuto — quindi `lang="it"` è corretto perché l'interfaccia è in italiano.

**Azione:** Aggiungere `lang` attribute sui blocchi di testo in lingua straniera:

```astro
<!-- In canti/[slug].astro, sezione testo -->
<pre lang={linguaToIso(canto.lingue?.[0]?.titolo)}>
  {canto.testo}
</pre>

<!-- In traduzioni/[slug].astro, sezione testo -->
<pre lang={linguaToIso(traduzione.lingue?.[0]?.titolo)}>
  {traduzione.testo}
</pre>
```

Questo è un segnale di accessibilità (screen reader) più che SEO, ma contribuisce alla qualità complessiva della pagina.

### 3.2 hreflang

**Non necessario.** Il sito ha una sola lingua per l'interfaccia (italiano). I contenuti in altre lingue (testi di canti, traduzioni) sono feature del sito, non versioni localizzate dello stesso contenuto. hreflang servirebbe solo se ci fossero versioni parallele della stessa pagina in lingue diverse (es. `/en/canti/bella-ciao` e `/it/canti/bella-ciao`).

### 3.3 Canonical URL

**Attuale:** Funziona correttamente:
- Costruito da `Astro.url.pathname` + `Astro.site`
- Rimozione trailing slash
- Protocollo HTTPS forzato dal config `site: 'https://www.ildeposito.org'`

**Verifica necessaria:** Assicurarsi che il server (Caddy) faccia redirect 301 da:
- `http://` → `https://`
- `ildeposito.org` → `www.ildeposito.org`
- URL con trailing slash → senza trailing slash

Questo è un tema infrastrutturale, non di codice Astro.

---

## 4. Open Graph — Miglioramenti

### 4.1 OG Image per content type

**Attuale:** Tutte le pagine usano `/og-default.jpg`.

**Proposta:**
- **Autori con immagine** → usare l'immagine dell'autore come `ogImage`
- **Periodi con immagine** → usare l'immagine del periodo
- **Canti con video** → estrarre thumbnail dal video YouTube come `ogImage`

Intervento in `autori/[slug].astro`:
```astro
ogImage={autore.immagine ? `/uploads/autori/${autore.slug}.jpg` : undefined}
ogImageAlt={autore.immagine ? `Foto di ${autore.titolo}` : undefined}
```

### 4.2 article:tag per contenuti taggati

Aggiungere `article:tag` nel componente `SEO.astro` per canti e eventi che hanno tags:

```astro
<!-- Nel componente SEO.astro, aggiungere prop tags -->
{tags?.map((tag) => <meta property="article:tag" content={tag} />)}
```

Modificare l'interfaccia Props di SEO.astro per accettare `tags?: string[]`.

### 4.3 article:section per categorie

Per canti: `article:section = "Canti di protesta"`
Per eventi: `article:section = "Eventi storici"`

---

## 5. Sitemap — Miglioramenti

### 5.1 Filtri aggiuntivi

**File:** `astro.config.mjs`

Attualmente filtra solo `/cerca`. Aggiungere:
```js
sitemap({
  filter: (page) =>
    !page.includes('/cerca') &&
    !page.includes('/404'),
}),
```

La pagina 404 non dovrebbe apparire nella sitemap (è già noindex, ma la rimozione dalla sitemap è best practice).

### 5.2 Priorità e changefreq

Astro sitemap non supporta nativamente `priority` e `changefreq` (deprecati da Google comunque). Non necessario.

---

## 6. RSS Feed — Miglioramenti

**File:** `src/pages/rss.xml.js`

Aggiungere `pubDate` agli items e un link al contenuto:

```js
items: canti.map((canto) => ({
  title: canto.titolo,
  link: `/canti/${canto.slug}`,
  description: canto.capoverso || '',
  pubDate: canto.dataCreazione ? new Date(canto.dataCreazione) : undefined,
})),
```

**Note:** Richiede che `getCantiRecenti` esponga un campo `dataCreazione` (created date) dal CMS. Se non disponibile, lasciare come è.

---

## 7. Meta tag aggiuntivi

### 7.1 In BaseLayout `<head>`

```html
<meta name="theme-color" content="#9E1B1B" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />
```

**Azione:** Creare `apple-touch-icon.png` (180x180px) nella directory `public/`.

### 7.2 Google Site Verification (se applicabile)

Se il sito è verificato su Google Search Console, aggiungere il meta tag di verifica nell'`<head>`.

---

## 8. Riepilogo file da modificare

| File | Intervento |
|---|---|
| `src/lib/seo.js` | MAX_TITLE_LEN → 53, aggiungere `linguaToIso()` |
| `src/lib/schema.js` | Migliorare MusicComposition, Person, Event; aggiungere `buildTranslationSchema` |
| `src/components/base/SEO.astro` | Aggiungere prop `tags?: string[]`, render `article:tag` e `article:section` |
| `src/layouts/BaseLayout.astro` | Aggiungere `theme-color`, `apple-touch-icon`; prop `tags` |
| `src/pages/index.astro` | Title → keyword primaria, description con conteggio |
| `src/pages/canti/[slug].astro` | Title template, description template, `lang` su testo, `tags` prop |
| `src/pages/canti/index.astro` | Title, description, aggiungere CollectionPage schema |
| `src/pages/autori/[slug].astro` | Title template, description template, schema arricchito, ogImage |
| `src/pages/autori/index.astro` | Title, description, aggiungere CollectionPage schema |
| `src/pages/eventi/[slug].astro` | Title template, description template, schema arricchito |
| `src/pages/eventi/index.astro` | Title, description, aggiungere CollectionPage schema |
| `src/pages/traduzioni/[slug].astro` | Title template, description template, aggiungere Translation schema + breadcrumb schema |
| `src/pages/traduzioni/index.astro` | Title, description, aggiungere CollectionPage schema |
| `src/pages/lingue/index.astro` | Title, description, aggiungere CollectionPage schema |
| `src/pages/lingue/[slug].astro` | Title, description, aggiungere WebPage + breadcrumb schema |
| `src/pages/localizzazioni/index.astro` | Title, description, aggiungere CollectionPage schema |
| `src/pages/localizzazioni/[slug].astro` | Title, description, aggiungere WebPage + breadcrumb schema |
| `src/pages/tags/index.astro` | Title, description, aggiungere CollectionPage schema |
| `src/pages/tags/[slug].astro` | Title, description, aggiungere WebPage + breadcrumb schema |
| `src/pages/periodi/index.astro` | Title, description, aggiungere CollectionPage schema |
| `src/pages/periodi/[slug].astro` | Title, description, aggiungere WebPage + breadcrumb schema |
| `src/pages/calendario-cantato/index.astro` | Title, description |
| `src/pages/calendario-cantato/[giorno].astro` | Title, description |
| `src/pages/[...percorso].astro` | Aggiungere WebPage schema |
| `astro.config.mjs` | Filtro sitemap: escludere 404 |
| `public/` | Aggiungere `apple-touch-icon.png` |

---

## 9. Verifica

1. **Build locale:** `cd frontend && npm run build` — verificare che non ci siano errori
2. **Ispeziona HTML generato:** controllare `dist/canti/bella-ciao/index.html` (o simile) per verificare title, meta description, canonical, OG tags, JSON-LD
3. **Google Rich Results Test:** testare una pagina canto, una autore e una evento su https://search.google.com/test/rich-results
4. **Schema Markup Validator:** validare JSON-LD su https://validator.schema.org
5. **Verifica canonical:** controllare che le URL canonical siano corrette (https://www.ildeposito.org/...)
6. **Verifica redirect 301:** da http a https, da non-www a www, da trailing slash a senza
7. **Dev server:** `npm run dev` — navigare le pagine e controllare i `<title>` nel tab del browser
