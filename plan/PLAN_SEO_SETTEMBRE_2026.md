# Piano SEO, dati strutturati, performance e accessibilità — settembre 2026

## Scopo e criterio di priorità

Questo piano deriva dall'analisi del frontend Astro e dei dati Drupal realmente
usati in build, non da una checklist generica. Le priorità seguono questo
ordine: (1) rendere il contenuto utile disponibile nell'HTML iniziale, (2)
eliminare errori/ambiguità che possono compromettere il crawling, (3) ridurre
il lavoro sul percorso critico, (4) consolidare la qualità con test e misure.

Legenda: **P0** blocca o limita direttamente indicizzazione/fruizione;
**P1** ha alto impatto e basso-medio costo; **P2** è un miglioramento da
programmare dopo la baseline. Nessuna modifica proposta implica fetch di
contenuti dal browser: il modello SSG attuale va conservato.

## Architettura analizzata

### Generazione e dati

- Astro 6 produce un sito statico (`output: 'static'`, `trailingSlash:
  'never'`); solo `src/pages/api/altcha.ts` e
  `src/pages/api/modulo_contatti.ts` sono SSR. Nginx serve il client statico e
  inoltra solo `/api/*` a Node.
- Durante la build il layer `src/lib/api/drupal/` legge JSON:API Drupal. Il
  client pagina le risposte e limita a quattro le richieste concorrenti;
  `store.ts` conserva una Promise per collezione ed evita rifetch per ogni
  pagina generata. È una buona base per contenuti prerenderizzati.
- I tipi di contenuto sono `canto`, `autore`, `evento`, `traduzione` e
  `pagina`. I mapper li rendono tipi TypeScript indipendenti dal CMS. Le
  relazioni significative sono autore testo/musica, canto-evento, canto
  originale-traduzione, immagine/media e collegamenti esterni.
- Le tassonomie esposte sono `lingue`, `periodi`, `tags` e
  `localizzazioni`; `tematiche` al momento arricchisce soprattutto canti ed
  eventi, ma non ha una propria rotta pubblica. Il piano deve decidere
  esplicitamente se renderla navigabile oppure trattarla solo come metadata.
- Le pagine editoriali Drupal sono una catch-all (`[...percorso].astro`) con
  `PaginaDetailView` e Paragraphs `testo`, `citazione`, `immagine`, `card` e
  `griglia`. L'HTML ricco viene emesso con `set:html`: le regole di redazione
  devono quindi essere parte della qualità SEO e a11y.

### Template e percorsi pubblici

| Famiglia | Route/template | Funzione attuale |
| --- | --- | --- |
| Home | `src/pages/index.astro` | Landing con contenuti più visti, calendario e tassonomie; è l'unica pagina che emette `WebSite` e `Organization`. |
| Dettagli | `components/detail/{Canto,Autore,Evento,Traduzione,Pagina}DetailView.astro` | Titolo, breadcrumb, metadata, JSON-LD, corpo del contenuto; Canto/Autore/Evento sono riusati dalla preview. |
| Hub | `canti`, `autori`, `eventi`, `traduzioni`, `periodi`, `tags`, `lingue`, `localizzazioni` | Pagine di ingresso, in gran parte con `CollectionPage`, breadcrumb e `ItemList`. |
| Elenchi/ricerca | `*/elenco.astro`, `autori/[slug]/canti.astro`, `periodi/[slug]/contenuti.astro`, `cerca.astro` | Ricerca/filtro client-side con `PagefindList`; alcuni sono volutamente `noindex`. |
| Tassonomie | `tags/[slug]`, `lingue/[slug]`, `localizzazioni/[slug]`, `periodi/[slug]` | I primi tre mostrano Pagefind lato client; periodo ha invece una prima selezione SSR e un elenco completo separato. |
| Archivio | `archivio/calendario-cantato/*`, `archivio/mappa-cantata.astro`, `canzonieri` | Calendario, mappa Leaflet e PDF generati a build. |
| Informazioni | `[...percorso].astro`, `informazioni/contatti.astro` | Pagine redazionali e contatto; il template catch-all deve preservare canonical, h1 e gerarchia dell'HTML Drupal. |

`BaseLayout.astro` centralizza lingua italiana, font preload, favicon, RSS,
skip link, header/footer, analytics di produzione e `SEO.astro`. I testi SEO
sono centralizzati in `src/config/pages.yaml`; la combinazione per le schede
dei contenuti è in `src/lib/seo.js`. La sitemap è prodotta da
`@astrojs/sitemap`, esclude ricerca/404 e ha `lastmod` solo per canti, autori
ed eventi. `robots.txt` blocca ricerca e API.

### Stato positivo da preservare

- Canonical assoluto, title, description, Open Graph/Twitter e JSON-LD sono
  centralizzati invece di essere duplicati nei template.
- La sitemap, RSS, robots e pagine 404 `noindex` esistono già.
- Immagini Astro usano AVIF/WebP, dimensioni, `sizes`, lazy loading e,
  nell'hero/LCP, `fetchpriority`; Leaflet e Pagefind sono importati in modo
  dinamico quando il componente è usato.
- Semantica già solida: landmark `main`, skip link, nav con label,
  breadcrumb, dialog nativi, stati ARIA nelle componenti interattive e
  `prefers-reduced-motion` globale.
- Lo schema esistente è più ricco della media: `MusicComposition`, `Lyrics`,
  `Person`/`MusicGroup` + `ProfilePage`, `Event`, `VideoObject`, PDF,
  `DefinedTerm(Set)`, `ItemList`, `CollectionPage` e `BreadcrumbList` con
  identificatori coerenti (`#composition`, `#autore`, `#evento`).

## Rilievi e proposte

### 1. SEO

| Priorità | Intervento | Motivazione e file coinvolti | Criterio di accettazione |
| --- | --- | --- | --- |
| P0 | Rendere server-side la prima pagina di risultati delle tassonomie indicizzabili e lasciare Pagefind come enhancement/filtro. | `tags/[slug].astro`, `lingue/[slug].astro`, `localizzazioni/[slug].astro` oggi emettono h1 e un custom element, ma i link ai contenuti sono creati dal browser. Sfruttare i dati già in `tassonomie.ts`/store per renderizzare 10–20 `CantoCard`/`EventCard` con link, anno, autore/luogo e un link progressivo a “tutti i risultati”. | Con JavaScript disabilitato ogni pagina di termine mostra testo descrittivo, numero di contenuti e almeno 10 link crawlable; Pagefind conserva filtro/ordinamento quando JS è disponibile. |
| P0 | Separare nettamente pagine-hub indicizzabili da pagine di utilità duplicate. | Gli elenchi completi Pagefind e `autori/[slug]/canti` sono già `noindex`; verificare che tutti gli URL filtrati/query (`/cerca?q=`, stato UI e possibili link Pagefind) non entrino in sitemap né producano canonical concorrenti. Per `periodi/[slug]/contenuti`, se resta `noindex`, collegarlo come utilità; se deve posizionarsi, convertirlo nella pagina SSR canonica del periodo. | Sitemap contiene solo URL 200, canonici, indicizzabili e non duplicati; nessun URL con query in sitemap/canonical. |
| P1 | Estendere `lastmod` della sitemap a traduzioni, pagine Drupal e tassonomie che hanno una data, e aggiungere test di completezza. | `astro.config.mjs` oggi legge `changed` solo per tre content type. `pagina` e `traduzione` possono cambiare senza segnale in sitemap; un cambio di relazione/tassonomia può richiedere la ripubblicazione delle pagine termine. | `sitemap-index.xml` è valido; ogni URL dinamico ha un `lastmod` affidabile oppure non ne dichiara uno, mai una data di build fittizia. |
| P1 | Formalizzare il contratto editoriale SEO in Drupal. | Nei mapper esistono testo, capoverso, informazioni, fonti, date, immagini e link, ma non un campo per title/description/OG per singolo nodo. Aggiungere campi opzionali “SEO title”, “meta description” e “immagine sociale”, con fallback ai template YAML; validare descrizione sensata e alternativa per immagini. | Ogni scheda può avere metadata unici senza dover manipolare il testo visibile; fallback invariato per contenuti esistenti. |
| P1 | Correggere e verificare l'head centralizzato. | In `BaseLayout.astro` le props `musicMusicians` e `musicReleaseDate` sono passate due volte a `SEO`; rimuovere il duplicato e aggiungere un test/build che fallisca su attributi duplicati. In `SEO.astro`, distinguere `noindex, follow` da `noindex, nofollow`: i percorsi di servizio possono mantenere il passaggio di PageRank ai link interni. | Nessun warning Astro; una pagina noindex emette la direttiva scelta intenzionalmente e non blocca per errore i link interni. |
| P2 | Migliorare l'internal linking editoriale e l'informazione visibile. | Ogni dettaglio ha breadcrumb e relazioni, ma le pagine termine “lingua/localizzazione/tag” non offrono introduzione redazionale, né i contenuti legati sono sempre visibili senza JS. Creare una descrizione per ciascun termine importante e collegamenti tra periodo, luogo, tema, autori e canti correlati. Valutare una pagina hub `tematiche` solo se esistono descrizioni e un numero sufficiente di contenuti per evitare thin content. | Per i termini strategici ci sono testo originale, collegamenti contestuali e almeno una selezione editoriale SSR; non si indicizzano pagine quasi vuote. |

### 2. Schema.org e dati strutturati

| Priorità | Intervento | Motivazione e file coinvolti | Criterio di accettazione |
| --- | --- | --- | --- |
| P0 | Collegare ogni `WebPage`/`CollectionPage` al `WebSite` e all'entità principale tramite `@id` condivisi. | `buildWebSiteSchema`/`buildOrganizationSchema` sono emessi solo in home; le altre pagine hanno entità corrette ma non sempre `isPartOf: { @id: site#website }`, `mainEntity` o un `WebPage` identificabile. Introdurre un builder comune `buildPageGraph` e farlo usare da layout/dettagli/hub. | Per ogni URL il grafo contiene una pagina (`WebPage`, `CollectionPage` o `ProfilePage`) con URL e `isPartOf`; la sua main entity punta allo stesso `@id` del dettaglio. |
| P0 | Riparare la semantica dell'organizzazione. | `buildOrganizationSchema()` non ha `@id`, mentre il `publisher` del sito e dei canti è ricreato inline; non può quindi essere riconciliato con l'organizzazione della home. Definire `site#organization`, usarlo come `publisher`/`copyrightHolder`, e sostituire il logo favicon con un asset logo quadrato, stabile e idoneo. | Una sola identità dell'organizzazione nel grafo, tutti i riferimenti usano `@id`; il validator Schema.org non segnala proprietà/URL incoerenti. |
| P1 | Aumentare la fedeltà, non la quantità, dei dati musicali. | `MusicComposition` è appropriato, ma forma musicale è dedotta da testo libero (`deduceMusicCompositionForm`): trattarla come valore editoriale controllato o ometterla quando non certa. Aggiungere in Drupal, dove il dato è verificabile, anno/data di composizione, arrangiatore/interprete/registrazione, fonte bibliografica strutturata e traduttore. Non inventare performer, offer o organizer. | I dati strutturati corrispondono sempre a dati visibili/verificabili; test unitari per campi facoltativi e assenti. |
| P1 | Modellare meglio eventi e luoghi storici. | `Event` usa giustamente `EventCompleted`; per eventi con sola data storica la data ISO deve essere completa prima di emetterla. `address: loc.titolo` è un fallback debole: il CMS dovrebbe fornire `Place` normalizzato (nome, comune/paese, coordinate, indirizzo se noto). Applicare `sameAs` a tutti i link esterni validi, non solo al primo. | Rich Results/Schema Validator non mostra date malformate; coordinate e luogo sono coerenti tra pagina evento e canto correlato. |
| P1 | Allineare tassonomie e grafi alle pagine effettive. | Tags, lingue, periodi e localizzazioni usano bene `DefinedTerm(Set)`. `tematiche` viene però emessa in `about` con `termSetPath` `/tematiche` senza una route pubblica: o creare l'hub/termini indicizzabili, o emettere il set senza URL e senza `@id` che punta a pagine inesistenti. | Nessun `url`/`@id` schema restituisce 404; ogni DefinedTerm pubblico ha una pagina canonical. |
| P2 | Verificare il markup su HTML generato, non solo sui builder. | I test esistenti (`tests/schema.test.mjs`) verificano oggetti JS. Aggiungere snapshot/parse di una pagina costruita per ciascuna famiglia (home, canto, autore, evento, traduzione, termine, pagina editoriale) e validazione JSON-LD con JSON Schema locale più controllo periodico nei validator esterni. | CI rileva JSON non parsabile, `@id` duplicati, URL relativi e riferimenti interrotti prima del deploy. |

### 3. Performance

| Priorità | Intervento | Motivazione e file coinvolti | Criterio di accettazione |
| --- | --- | --- | --- |
| P0 | Misurare Web Vitals reali e definire budget prima di ottimizzare. | La configurazione ha analytics di produzione ma non una soglia tecnica. Misurare per home, canto lungo, autore con immagine, evento con mappa, termine e ricerca: LCP, INP, CLS, TTFB, JS trasferito e HTML. | Dashboard RUM segmentata per template/dispositivo; budget iniziali: LCP p75 ≤2,5 s, INP p75 ≤200 ms, CLS p75 ≤0,1, JS iniziale ≤150 KB gzip (da calibrare dopo baseline). |
| P1 | Evitare il preload indiscriminato di quattro font. | `BaseLayout.astro` precarica pesi 400/600 Source Sans e 400/700 Bitter in ogni pagina, pur non essendo tutti certamente above-the-fold. Mantenere solo i file effettivamente LCP (normalmente Source Sans 400 e Bitter 700), impostare `font-display: swap`/metric override se necessario e caricare gli altri al bisogno. | Nessun FOIT; riduzione di byte e richieste high-priority senza regressione CLS misurabile. |
| P1 | Stabilire una policy LCP per immagini e responsive images. | Le immagini componenti usano già `Image`, formati moderni, `width`/`height`; diverse card però hanno due eager e più immagini con `fetchpriority`, mentre l'immagine che costituisce davvero LCP dipende dal template. Emettere `preloadImage` e `fetchpriority=high` per una sola immagine candidata; tutte le altre lazy. | Un solo candidato LCP prioritario per pagina, nessun warning di preload inutilizzato, CLS immagini ~0. |
| P1 | Conservare lazy import, ma rinviare anche l'inizializzazione. | Leaflet/marker cluster vengono caricati dinamicamente, buona scelta; inizializzarli con `IntersectionObserver` quando la mappa è vicina al viewport, con fallback testuale/link alla mappa. Per Pagefind, mostrare risultati SSR sulle pagine indicizzabili e scaricare motore/indice solo all'interazione. | Le pagine senza mappa non includono Leaflet; su pagina mappa il bundle parte vicino al viewport; una tassonomia resta utile prima del download di Pagefind. |
| P1 | Ridurre costo e fragilità della build senza peggiorare il frontend. | Il fetch con cache, paginazione e gate a 4 va mantenuto. Profilare però build Astro, trasformazioni Sharp e generazione PDF per canto: eseguire i PDF in job/artefatto separato quando il contenuto non è cambiato, mantenendo cache hash e URL stabili. | Build contenuti e PDF hanno tempi/risorse registrati; una modifica CSS non rigenera inutilmente tutti i PDF. |
| P2 | Ottimizzare delivery HTTP dopo audit dell'infrastruttura. | Verificare Nginx/CDN per cache immutable degli asset hashati, `Cache-Control` appropriato per HTML, Brotli, HTTP/2 o HTTP/3 e CDN delle immagini già trasformate. Non cambiare proxy/API senza piano di deploy. | Header verificati con test di integrazione; repeat visit scarica solo HTML e asset modificati. |

### 4. Accessibilità

| Priorità | Intervento | Motivazione e file coinvolti | Criterio di accettazione |
| --- | --- | --- | --- |
| P0 | Fare un audit automatizzato e manuale per template, inclusi gli stati JavaScript. | La base è buona, ma i problemi a11y sono spesso negli stati aperto/chiuso: menu, ricerca autocomplete, filtri Pagefind, modali di share/segnalazione, consenso YouTube, tab testo/accordi, leggio e mappe. Integrare axe in Playwright e test manuali tastiera/screen reader. | Zero violazioni critiche/serie axe sulle pagine campione; ogni flusso è completabile solo con tastiera e il focus torna al trigger. |
| P0 | Rendere le liste Pagefind progressive e semanticamente complete. | Con JS assente non si vedono risultati; con JS attivo verificare annunci del numero risultati, focus dopo filtro/paginazione, `aria-current` per pagina, chiusura con Esc e trappola del focus nei drawer. Il markup SSR proposto nel P0 SEO è anche il fallback accessibile. | Navigazione e contenuti disponibili senza JS; dopo ogni azione il lettore di schermo riceve un messaggio unico e il focus resta prevedibile. |
| P1 | Correggere il modello di dialog e controlli. | I dialog nativi hanno una buona base, ma ogni backdrop usa un bottone “chiudi” visibile agli screen reader: dargli `aria-label` specifico oppure renderlo realmente decorativo. Verificare `showModal()`, Escape, chiusura, inert/focus restore per menu, share, segnalazione e consenso YouTube. | Nessun controllo con nome ambiguo; sequenza di Tab confinata al dialog e restituita all'elemento originario. |
| P1 | Proteggere la gerarchia e la qualità dell'HTML proveniente da Drupal. | `set:html` nei dettagli e nei Paragraph può introdurre h1 multipli, salti h2→h4, link “clicca qui”, immagini senza testo alternativo o tabelle senza header. Sanitizzare lato CMS e aggiungere validazione editoriale/CI del frammento: una pagina ha un solo h1 e heading in ordine. | Contenuti redazionali non introducono HTML non sicuro o errori di heading/link/alt; report per contenuto da correggere. |
| P1 | Audit contrasto e focus reale, non solo token. | `main.css` dichiara token AA e `prefers-reduced-motion`; verificare contrasto di testo opaco, placeholder, stati hover/focus e controlli su sfondi rosso/ocra con simulazione di contrasto elevato e zoom 200–400%. Rendere il focus visibile coerente anche su link-card con pseudo-elemento overlay. | WCAG 2.2 AA per testo e controlli; focus sempre visibile; nessuna perdita di funzioni a 320 CSS px e 400% zoom. |
| P2 | Fornire alternative accessibili a mappa e media. | Le mappe sono regioni etichettate; aggiungere una lista/tabella degli eventi e un link “salta la mappa”. YouTube necessita già consenso: aggiungere trascrizione/descrizione quando disponibile e distinguere “audio/video esterno” senza dipendere dall'icona. | Informazione geografica e contenuto del media disponibili senza mappa, mouse o player terzo. |

## Sequenza di attuazione proposta

1. **Sprint 1 — fondazioni (P0, 1–2 settimane):** baseline Lighthouse/RUM e axe; inventario URL sitemap/canonical; liste SSR progressive per tag, lingue e localizzazioni; correzione props duplicate e politica `noindex, follow`; verifica tastiera dei componenti interattivi.
2. **Sprint 2 — semantica e contenuto (P1, 2 settimane):** grafo pagina/sito/organizzazione unificato, tassonomia `tematiche` coerente, `lastmod` esteso, descrizioni editoriali dei termini principali e contratto campi SEO Drupal.
3. **Sprint 3 — percorso critico (P1, 1–2 settimane):** budget e policy font/LCP, init differita mappa/Pagefind, profilazione build/PDF, audit contrasto e dialog/focus.
4. **Sprint 4 — qualità continua (P2):** cache HTTP/CDN dopo test, campi editoriali musicali/luogo, validazione HTML Drupal e suite CI di rendering/schema/axe; riesame trimestrale Search Console + Web Vitals.

## Verifica prima del rilascio

- `npm run build` e controllo manuale di `dist/client` con JavaScript disattivato.
- `node --test tests/schema.test.mjs`, esteso con fixture di ogni famiglia
  pagina; parsing di tutti gli script `application/ld+json` generati.
- Crawl locale: status 200, canonical assoluto unico, title/description
  presenti, un solo h1, link interni validi, noindex coerente e sitemap/robots
  consistenti.
- Playwright + axe su desktop/mobile; test manuale con tastiera, zoom 400%,
  `prefers-reduced-motion` e almeno NVDA/VoiceOver per dialog, ricerca, filtri
  e tab.
- Lighthouse CI per le sei pagine campione e confronto con i budget; dopo
  deploy, controllo Rich Results Test/Schema Validator e Search Console per
  copertura, pagine escluse, breadcrumb e Core Web Vitals.

## Decisioni editoriali da prendere prima di implementare

1. `tematiche` deve diventare una tassonomia pubblica con pagine e
   descrizioni, oppure restare metadata non URL?
2. Le pagine “tutti i contenuti” devono essere landing SEO indicizzabili o
   strumenti `noindex`? La scelta determina canonical e rendering SSR.
3. Quali font/pesi sono effettivamente indispensabili above-the-fold dopo la
   misurazione RUM?
4. Quali fonti editoriali sono autorevoli per compositore, traduttore,
   registrazione e luogo storico? I campi Schema vanno introdotti solo per
   dati che la redazione può verificare e mantenere.
