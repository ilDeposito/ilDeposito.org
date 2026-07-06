# Piano SEO 2 — ilDeposito.org

## Contesto

Il `PLAN_SEO.md` esistente (già implementato: schema.js, pages.yaml, `MAX_TITLE_LEN`, filtro sitemap corrispondono a quanto lì proposto) ha coperto title/description/schema.org di base. Questo piano copre un secondo round di funzionalità SEO non ancora affrontate: differenziazione robots.txt per ambiente, analisi AI-bot crawling, completamento schema.org con `sameAs`, e soprattutto un sistema — oggi del tutto assente — di gestione redirect legacy e reporting 404, in vista della migrazione dal vecchio sito Drupal 8 monolitico al nuovo stack.

**Decisioni architetturali confermate con l'utente** (vincolanti per il piano):
1. Redirect: pagina admin Drupal con textarea (`vecchio|nuovo` per riga), storage in **State** Drupal (non Configuration/non esportato), consumata dal frontend ad ogni build.
2. Report 404: **drush command** schedulato estendendo il `crond` esistente, invio via `MailManagerInterface` (SMTP già configurato).
3. Fallback 301→Home: **solo** pattern legacy Drupal riconoscibili (`/node/*`, `/taxonomy/term/*`, `/user/*` incluso login/reset password, ecc.) — i path davvero sconosciuti restano 404 reali, altrimenti il report 404 non avrebbe nulla da segnalare.
4. Destinatari report 404: **lista dedicata separata** in State (non la stessa di `contatti_destinatari`).

---

## Task 1 — Robots.txt

### 1a. Produzione — regole aggiuntive

```
User-agent: *
Allow: /
Disallow: /cerca
Disallow: /api/

Sitemap: https://www.ildeposito.org/sitemap-index.xml
```
- `Disallow: /cerca`: già `noindex`+escluso da sitemap, ma il blocco esplicito del crawl risparmia crawl budget su combinazioni infinite di query string.
- `Disallow: /api/`: endpoint SSR (altcha, contatti), zero valore SEO.
- Niente `Crawl-delay` (non standard, ignorato da Google dal 2019; il rate-limit è già gestito da nginx).

### 1b. Stage → `Disallow: /` globale

Oggi `robots.txt` è statico in `frontend/public/robots.txt`, identico in stage e prod; l'unica difesa dello stage è Basic Auth Caddy + header `X-Robots-Tag` (a livello Caddy, non Astro). `ENV` è già passato al builder ma mai letto nel codice Astro.

**Sostituire `frontend/public/robots.txt` con endpoint prerenderizzato** `frontend/src/pages/robots.txt.ts` (stesso pattern di `rss.xml.js`, nessun `prerender = false`: con `output: 'static'` gli endpoint sono prerenderizzati by default, quindi `process.env.ENV` è leggibile al build time):

```ts
export async function GET() {
  const isStage = process.env.ENV === 'stage';
  const body = isStage
    ? 'User-agent: *\nDisallow: /\n'
    : [
        'User-agent: *', 'Allow: /', 'Disallow: /cerca', 'Disallow: /api/', '',
        'Sitemap: https://www.ildeposito.org/sitemap-index.xml', '',
      ].join('\n');
  return new Response(body, { headers: { 'Content-Type': 'text/plain' } });
}
```

**File**: creare `frontend/src/pages/robots.txt.ts`, rimuovere `frontend/public/robots.txt` (evita collisione tra copia statica e output generato — verificare con build reale che non ci sia doppia scrittura).

### 1c. Analisi llm.txt / AI crawler — proposta

Stato attuale (verificato): `llms.txt` (convenzione community, llmstxt.org) non è uno standard IETF/W3C, nessun provider LLM maggiore lo consuma pubblicamente per search/training. Il meccanismo realmente rispettato dai bot AI è **robots.txt con `User-agent` dedicati**.

**Proposta**: non implementare `llms.txt` ora (basso ROI per un archivio culturale, non un prodotto/documentazione dove la curation dichiarata ha senso — rivalutare tra 12 mesi). Aggiungere invece in robots.txt regole esplicite (dichiarative, coincidono col default ma esplicitano la scelta editoriale) per i bot AI noti, dato che la missione del sito (diffusione dei canti di protesta) è coerente con l'essere trovati/citati da AI:
```
User-agent: GPTBot
Allow: /
User-agent: ChatGPT-User
Allow: /
User-agent: ClaudeBot
Allow: /
User-agent: anthropic-ai
Allow: /
User-agent: Google-Extended
Allow: /
User-agent: CCBot
Allow: /
User-agent: PerplexityBot
Allow: /
```
Se in futuro servisse bloccare il training per ragioni di copyright sui testi, la stessa struttura si inverte con `Disallow: /` per user-agent specifici.

### Verifica Task 1
- `ENV=stage npm run build && cat dist/robots.txt` → `Disallow: /`; `ENV=prod npm run build && cat dist/robots.txt` → regole complete.
- Confermare che `frontend/public/robots.txt` non esista più.

---

## Task 2 — Sitemap

**Verificato sul build reale**: già completa e corretta. `@astrojs/sitemap` in `frontend/astro.config.mjs` con `filter: (page) => !page.includes('/cerca') && !page.includes('/404')`, 3166 URL generati, copertura esaustiva di canti/autori/eventi/traduzioni/lingue/localizzazioni/periodi/tags/calendario-cantato/pagine statiche via `getStaticPaths()` + `fetchAllJsonApi` paginato.

**Nessun nuovo sviluppo necessario.** Micro-migliorie valutate e scartate:
- `lastmod` da campo `changed` Drupal: richiederebbe fetchare un campo mai usato oggi e costruire una mappa `url→changed` accoppiata fragilmente a `store.ts`; un valore inaccurato è peggio che ometterlo (Google può iniziare a ignorare il campo sull'intero dominio). **Skip.**
- `priority`/`changefreq`: Google li ignora dichiaratamente da anni. **Skip.**

### Verifica Task 2
- `npm run build`, ispezionare `dist/client/sitemap-index.xml` e conteggio URL per content-type.

---

## Task 3 — Schema.org

### 3a. `field_links[0].uri` → `sameAs` (autore, esteso a evento)

Campo Drupal `field_links` (link standard, cardinalità -1, `[{uri, title, options}]` via JSON:API) esiste su `autore` ed `evento` ma non è mai stato integrato nel frontend (non fetchato in `store.ts`, non in `types.ts`, non mappato, non usato in `schema.js`).

**File 1 — `frontend/src/lib/api/drupal/store.ts`**, `fetchAllAutoriRaw()` (e analogamente `fetchAllEventiRaw()`): aggiungere `field_links` alla sparse-fieldset richiesta. Nessun `include` necessario (campo Link semplice, non entity reference).

**File 2 — `frontend/src/lib/api/types.ts`**: nuovo tipo condiviso + campo:
```ts
export interface LinkRef { uri: string; title: string | null; }
// AutoreDetail e EventoDetail: aggiungere `links: LinkRef[];`
```

**File 3 — `frontend/src/lib/api/drupal/mappers.ts`**: helper condiviso
```ts
function mapLinks(raw: any[] | undefined): LinkRef[] {
  if (!Array.isArray(raw)) return [];
  return raw
    .filter((l) => typeof l?.uri === 'string' && l.uri.startsWith('http'))
    .map((l) => ({ uri: l.uri, title: l.title || null }));
}
```
usato in `mapAutoreDetail`/`mapEventoDetail` come `links: mapLinks(a.field_links)`. Il filtro `startsWith('http')` scarta link interni Drupal (`internal:/node/123`), non validi come `sameAs` (schema.org richiede un URL assoluto).

**File 4 — `frontend/src/lib/schema.js`**: in `buildPersonSchema` e `buildEventSchema`, dopo i campi esistenti:
```js
if (autore.links?.[0]?.uri) schema.sameAs = [autore.links[0].uri];
```
(array, coerente con `buildOrganizationSchema` esistente.)

**Nessuna modifica a `autori/[slug].astro`/`eventi/[slug].astro`**: le pagine passano già l'intero oggetto mappato alle funzioni `build*Schema`, quindi il nuovo campo è automaticamente disponibile (verificato leggendo le firme in `schema.js`).

Best practice: `sameAs` con un singolo URL Wikipedia è pienamente conforme alle linee guida schema.org/Google (che raccomandano pagine autorevoli — Wikipedia, Wikidata, profili social ufficiali). L'affidabilità dipende dalla convenzione editoriale "il primo link è sempre Wikipedia" — da comunicare/verificare coi redattori, non enforceable a livello di schema Drupal (il campo non ha un sottotipo dedicato).

### 3b. Analisi arricchimento schema per tipo

**MusicComposition (canto)** — già solido. Miglioria concreta: `field_altri_titoli` (cardinalità 1, stringa) → `alternateName`, utile per varianti di titolo di canti popolari (es. varianti regionali di "Bella Ciao"). Stesso pattern fetch→type→mapper→schema del punto 3a.

**Person (autore)** — oltre a `sameAs`: `field_autori_correlati` (autore→autore) **non ha una proprietà schema.org onesta** (`colleague` implica un rapporto professionale non necessariamente vero: potrebbero essere autore-testo e autore-musica dello stesso canto, non "colleghi"). **Raccomandazione: non forzarlo nello schema strutturato**, lasciarlo solo come link editoriale in UI.

**Event (eventi storici, non fisici/futuri)** — problema semantico da correggere:
- `eventAttendanceMode: OfflineEventAttendanceMode` è pensato per eventi futuri con modalità di partecipazione — **privo di senso per un fatto storico concluso (es. una strage del 1969). Rimuovere.**
- `eventStatus`/`endDate`: schema.org non ha un tipo `HistoricalEvent` dedicato; `Event` resta la scelta più vicina ma aggiungere questi campi richiederebbe modellazione contenuti Drupal non esistente oggi (`field_data_evento` è singola data). **Fuori scope, segnalare come richiesta futura di modellazione contenuti.**
- Aggiungere `sameAs` da `field_links` (3a) è il miglioramento concreto più solido.
- Valutare se `field_descrizione_social` (string_long, probabilmente pensato per OG/social sharing) sia una `description` più efficace di `field_informazioni` quando quest'ultimo è assente/lungo — verificare con redattori prima di cambiare la fonte di default.

**CreativeWork (traduzione)** — minimale ma corretto. Piccola aggiunta a basso rischio: popolare `description` da `traduzione.informazioni` (campo già disponibile in `TraduzioneDetail`, oggi non usato in `buildTranslationSchema`):
```js
if (traduzione.informazioni) schema.description = stripHtml(traduzione.informazioni).substring(0, 200);
```

### Verifica Task 3
- DDEV: popolare `field_links` su un nodo autore di test, build frontend (`content` mode, senza PDF), ispezionare `<script type="application/ld+json">` generato per `/autori/<slug>` e cercare `sameAs`.
- Validare con Google Rich Results Test / schema.org validator sull'HTML generato.

### Rischi Task 3
- Minimi: aggiunte additive a fetch/tipi/funzioni pure, impatto trascurabile su build time. Nessun impatto su cache Redis (nuovo campo in un fieldset esistente, invalidazione naturale al primo render post-deploy).

---

## Task 4 — Redirect e 404

### 4.1 Modulo Drupal `ildeposito_redirects`

```
backend/web/modules/custom/ildeposito_redirects/
├── ildeposito_redirects.info.yml
├── ildeposito_redirects.routing.yml
├── ildeposito_redirects.permissions.yml
└── src/
    ├── Form/RedirectsForm.php          # FormBase (storage State, non Config)
    ├── Controller/RedirectsApiController.php  # espone JSON pubblico in lettura
    └── Commands/Report404Commands.php  # drush command per il report
```

**Routing**:
```yaml
ildeposito_redirects.form:
  path: '/admin/config/search/redirects'
  defaults: { _form: '...RedirectsForm', _title: 'Redirect' }
  requirements: { _permission: 'administer ildeposito redirects' }
ildeposito_redirects.api:
  path: '/api/redirects.json'
  defaults: { _controller: '...RedirectsApiController::list' }
  requirements: { _access: 'TRUE' }
  methods: [GET]
```
Nota: `/api/redirects.json` vive sul dominio Drupal (`admin-${ENV}.ildeposito.org`), non collide col namespace `/api/` del frontend (dominio/servizio nginx separato) — documentarlo esplicitamente nel commit per evitare confusione futura.

**`RedirectsForm`** (pattern `FormBase`, come l'esistente `BuildFrontendForm`): textarea con un redirect per riga nel formato `/vecchio|/nuovo` (delimitatore `|`, non ` - `: gli slug contengono trattini, il pipe è un carattere RFC 3986 riservato, zero ambiguità di parsing). Validazione:
- ogni riga non vuota/non commento (`#`) deve avere esattamente un `|`;
- `from` deve iniziare con `/`;
- `to` deve essere un path relativo o un URL assoluto su `ildeposito.org` (**anti open-redirect** — barriera di sicurezza necessaria dato che l'endpoint è pubblico e il form potrebbe essere usato senza attenzione).

Storage: `state.set('ildeposito_redirects.raw', $textarea)` (per ripopolare il form) + `state.set('ildeposito_redirects.parsed', [{from, to}, ...])` (consumato dal controller). Messaggio post-submit: "le modifiche saranno visibili alla prossima pubblicazione contenuti" — coerente con l'esistente flusso "Pubblica contenuti" (`ildeposito_build`), che già richiede un trigger esplicito per portare in produzione qualunque contenuto Drupal.

**`RedirectsApiController::list()`**: `JsonResponse(['redirects' => state.get('ildeposito_redirects.parsed', [])])`, cache `public, max-age=300`.

### 4.2 Consumo lato Astro/nginx — generazione a build time (raccomandato)

Due opzioni valutate:
- **Opzione A (scelta)**: script in `frontend/docker-entrypoint.sh`, dopo `astro build`, fetcha `http://drupal-api:80/api/redirects.json` e genera un file nginx con blocchi `location = /vecchio { return 301 https://$host/nuovo; }`. **Il file va scritto in `$BUILD_DIR/_redirects.conf` (root della release, non dentro `client/`)** per non esporlo come asset statico scaricabile — l'`include` di nginx usa un path assoluto indipendente da `root` (che punta a `current/client`), quindi `include /usr/share/nginx/html/current/_redirects.conf;` in `frontend/nginx.conf` funziona a prescindere da dove nella release si trovi il file. Lo script deve **sempre** scrivere il file (anche vuoto/con solo un commento) per evitare che un `include` su file mancante rompa l'avvio nginx.
  Serve un **reload nginx post-build** (nginx non rilegge gli `include` a runtime): aggiungere in `ildeposito.sh::cmd_build_frontend`, con log esplicito di successo/fallimento (mai silenziato):
  ```bash
  ${COMPOSE} exec -T frontend-web nginx -t && ${COMPOSE} exec -T frontend-web nginx -s reload
  ```
  `nginx -s reload` è zero-downtime (nuovi worker con config aggiornata, vecchi chiusi con grace period) — coerente con la filosofia zero-downtime esistente del deploy frontend.
  **Pro**: 301 reale a livello nginx (il più corretto per SEO, zero overhead applicativo), nessuna dipendenza runtime da Drupal per servire le pagine pubbliche (garanzia esistente da preservare: oggi il frontend buildato è indipendente da Drupal a runtime, tranne `/api/contatti`/`/api/altcha`).
  **Contro**: i redirect vanno live solo alla build successiva — accettabile, stesso comportamento di qualunque altro contenuto Drupal sul sito.
- **Opzione B (scartata)**: endpoint SSR Node con lookup a runtime. Introdurrebbe una dipendenza da Drupal vivo per servire redirect/404 — un vettore di fragilità in più proprio sul path (backlink legacy) più critico per SEO. Scartata per non rompere la garanzia di resilienza esistente.

### 4.3 Query param mapping (`ricerca?key=valore` → `cerca?q=valore`)

Non richiede Drupal: riga statica fissa in `frontend/nginx.conf`, prima della `location /` generica:
```nginx
location = /ricerca {
    if ($arg_key) { return 301 /cerca?q=$arg_key; }
    return 301 /cerca;
}
```

### 4.4 Pattern legacy Drupal → 301 Home

In `frontend/nginx.conf`, prima della `location /` generica:
```nginx
location ~ ^/(node|taxonomy/term|user|comment|admin)(/|$) {
    return 301 /;
}
location ~ ^/sites/default/files(/|$) {
    return 301 /;
}
```
`/user/*` copre login/logout/register/password-reset del vecchio sito. **Attenzione**: `/rss.xml` NON va incluso in questa lista — il sito ha già un endpoint nuovo e funzionante a quel path (`frontend/src/pages/rss.xml.js`), includerlo romperebbe il feed attuale.

Implementazione in **nginx, non Astro**: con l'output ibrido attuale, un middleware Astro intercetterebbe solo richieste instradate a `frontend-api` (cioè solo `/api/*`); le pagine pubbliche sono file statici (`try_files`) — nginx è l'unico livello che vede davvero tutte le richieste prima di un 404.

### 4.5 Log 404 dedicato, volume condiviso, drush command, estensione crond

**Log format dedicato** in `frontend/nginx.conf`:
```nginx
log_format notfound '$time_iso8601 $request_uri';
location / {
    # ... esistente (try_files, rate limit) ...
    error_page 404 = @log404;
}
location @log404 {
    access_log /var/log/nginx/404.log notfound;
    try_files /404.html =404;
}
```
Da coordinare con l'esistente `error_page 404 /404.html;` (va sostituito da questo blocco, verificando che il flusso resti: 404 → log → serve comunque `404.html` con status 404 reale).

**Volume condiviso** tra i due stack Compose separati (nuovo pattern — finora solo la rete Docker era condivisa in questo modo):
- `frontend/compose.stage.yml`/`compose.prod.yml`, servizio `frontend-web`: nuovo volume `ildeposito_404_log:/var/log/nginx` (read-write).
- `backend/compose.yml`, servizi `php`/`crond`: stesso volume montato `ildeposito_404_log:/var/log/frontend-nginx:ro` (read-only).
- Nome esplicito `ildeposito-${ENV}-404-log`, `external: true` in entrambi i compose file.
- Creazione una tantum in `ildeposito.sh::cmd_up`, prima del primo `up` (pattern identico alla rete `ildeposito-${ENV}-internal` già creata così):
  ```bash
  docker volume inspect "ildeposito-${ENV}-404-log" &>/dev/null \
      || docker volume create "ildeposito-${ENV}-404-log"
  ```
  **Attenzione in fase di implementazione**: il volume deve esistere prima che *entrambi* gli stack partano (non solo uno), altrimenti Docker Compose potrebbe crearlo come volume locale non condiviso in uno dei due per errore di ordinamento.

**Drush command** (`Report404Commands.php`, nello stesso modulo `ildeposito_redirects` — concettualmente la stessa storia "gestione URL legacy"):
- Legge `/var/log/frontend-nginx/404.log`, raggruppa per URI, ordina per frequenza.
- Destinatari: nuova chiave State **dedicata** `report_404_destinatari` (mai `contatti_destinatari`), validati con `filter_var(..., FILTER_VALIDATE_EMAIL)`.
- Invio via `MailManagerInterface->mail(module: 'ildeposito_redirects', key: 'report_404', ...)` — riusa SMTP già configurato (`settings.remote.php`) e il `hook_mail_alter` esistente in `ildeposito_utils` che forza il mittente corretto per qualunque mail Drupal.
- **Tronca il log dopo l'invio** (`file_put_contents($logPath, '')`) per evitare doppio conteggio al report successivo.

**Scheduling**: estendere il `CRONTAB` del servizio `crond` esistente in `backend/compose.yml` (preferito a `hook_cron` con throttling: più esplicito, cadenza visibile a colpo d'occhio nel compose file):
```yaml
environment:
  CRONTAB: |
    0 * * * * drush -r /var/www/html/web cron
    0 */6 * * * drush -r /var/www/html/web ildeposito:report-404
```

### Verifica Task 4 (DDEV)
1. Submit form `/admin/config/search/redirects` con una riga di test, `curl http://ildeposito11.ddev.site/api/redirects.json` → verificare payload.
2. Simulare la generazione build-time del redirect nginx, avviare nginx locale con l'`include`, `curl -I http://localhost/vecchia-pagina` → atteso `301` con `Location` corretto.
3. `curl -I http://localhost/node/123` → atteso `301` a `/`.
4. Generare un 404 reale, verificare l'entry in `/var/log/nginx/404.log` nel container, poi `ddev drush ildeposito:report-404` e verificare l'email (Mailpit in locale).

### Rischi Task 4 (i più alti del piano)
- `nginx -s reload` post-build introduce un nuovo passo di deploy: se `nginx -t` fallisce su un redirect malformato, va loggato esplicitamente, mai silenziato (comportamento sicuro di default: nginx resta sulla config precedente, ma va monitorato).
- Validazione anti-open-redirect nel form **e** una seconda barriera nello script di generazione nginx (non fidarsi solo della validazione PHP) — un redirect verso un dominio esterno arbitrario sarebbe uno strumento di phishing se il form venisse compromesso o usato con disattenzione.
- Volume condiviso tra due stack Compose: pattern nuovo per il progetto, testare con cura l'ordine di creazione.
- Nessun impatto su cache Redis (il log 404 è puro I/O nginx; le nuove chiavi State non passano dalla cache di rendering Drupal).

---

## Task 5 — SEO Contenuti (`pages.yaml`)

Analisi delle 68 chiavi esistenti (sistema di template già maturo, con condizionali `{?var}` e plurali `{count|sing|plur}` — verificato in `frontend/src/lib/pages.ts`/`seo.js`).

**Aree di miglioramento concrete:**
1. `home.metaTitle` usa `-` invece di `—` (incoerenza stilistica rispetto al resto del file) e non contiene "italiani"/"archivio online" — keyword ad alto intento su query generiche. Proposta: `"ilDeposito.org — Canti di protesta italiani: archivio online"` (resta sotto i 53 char di troncamento).
2. Ripetizione di "nell'archivio di ilDeposito.org" in 8+ chiavi (`autori.detail`, `eventi.detail`, `traduzioni.detail`, `tags.term`, `periodi.term`, `localizzazioni.term`): rinforza il brand ma consuma spazio prezioso nei 155 char senza informazione specifica. Su pagine con poco altro contenuto (es. tag con pochi risultati), sostituirlo con un dettaglio concreto (es. titoli rappresentativi) richiederebbe passare dati aggiuntivi a `getPageMeta()` — **iterazione futura, non urgente**.
3. `canti.detail.metaDescription` collassa a `"Testo di {titolo}."` (molto corta) quando `accordi`, `extra` e `autori` sono tutti assenti (canti tradizionali anonimi). Verificare quanti nodi `canto` reali cadono in questo caso (query Drupal: `field_autori_testo`+`field_autori_musica`+`field_capoverso` tutti vuoti) — se sono una quota rilevante, aggiungere un fallback tipo `"Testo di {titolo}, canto della tradizione popolare italiana."`.
4. `calendario.index`/`calendario.giorno`: già solidi (buon esempio di long-tail per query stagionali/anniversario) — nessuna modifica.
5. Pluralizzazione `{count|sing|plur}` usata solo in `autori.detail` — verificare se `eventi.detail`/`tags.term` hanno già un conteggio disponibile nei dati passati a `getPageMeta` per estendere il pattern (richiede controllo dei rispettivi `.astro`, non ancora fatto).

**Nessun nuovo sviluppo architetturale**: modifiche testuali dentro `pages.yaml`, basso rischio, nessun impatto su build time (file già caricato via `@rollup/plugin-yaml` a build time).

### Verifica Task 5
- Script rapido che chiama `getPageMeta()` per ogni chiave con dati di esempio, verificando che nessun output superi i limiti di troncamento in modo da tagliare a metà parola.
- Google Search Console (post-deploy) per confronto CTR pre/post su un orizzonte di alcune settimane.

---

## Rischi trasversali e ordine di implementazione

1. **Build time**: impatto trascurabile per Task 3 (nuovi campi JSON:API) e Task 4 (fetch `/api/redirects.json` in `docker-entrypoint.sh`), dato il gate di concorrenza esistente (`GLOBAL_CONCURRENCY = 4`).
2. **Deploy zero-downtime**: unico punto di novità operativa è `nginx -s reload` post-build (Task 4) — loggare sempre esito, mai silenziare.
3. **Sicurezza**: il nuovo endpoint pubblico `/api/redirects.json` e la validazione anti-open-redirect (Task 4.1) sono i punti che richiedono più attenzione in code review.
4. **Ordine consigliato**: Task 1 (isolato, basso rischio) → Task 3 (isolato, basso rischio) → Task 2 (nessuna azione, solo verifica) → Task 5 (solo testi) → Task 4 (il più complesso, tocca infrastruttura Docker/nginx su due stack — testare a fondo in DDEV/stage prima di prod).

## File critici coinvolti

- `frontend/astro.config.mjs`, `frontend/src/pages/robots.txt.ts` (nuovo)
- `frontend/src/lib/api/drupal/store.ts`, `frontend/src/lib/api/types.ts`, `frontend/src/lib/api/drupal/mappers.ts`, `frontend/src/lib/schema.js`
- `frontend/nginx.conf`, `frontend/docker-entrypoint.sh`
- `frontend/compose.stage.yml` / `compose.prod.yml`, `backend/compose.yml`, `compose.yml` (root)
- `backend/web/modules/custom/ildeposito_redirects/` (nuovo modulo: `Form/RedirectsForm.php`, `Controller/RedirectsApiController.php`, `Commands/Report404Commands.php`)
- `ildeposito.sh`
- `frontend/src/config/pages.yaml`
