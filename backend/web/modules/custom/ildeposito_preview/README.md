# ilDeposito Preview

Aggiunge, alla pagina di un nodo, il local task **"Anteprima Frontend"** che
reindirizza l'editor verso la rotta SSR `preview/{uuid}` del frontend Astro,
mostrando il rendering reale del contenuto anche se non pubblicato/in bozza.

Il link è firmato con un token **HMAC-SHA256** a scadenza breve (10 minuti):
`token = hash_hmac('sha256', "{uuid}:{expires}", $secret)`. Lo stesso secret
deve essere configurato **identico** su Drupal e sul frontend Astro, altrimenti
la verifica del token fallisce sempre (403).

## Permessi

Il local task e la rotta di redirect richiedono il permesso custom
`preview drupal content` ("Anteprima frontend del contenuto"), assegnabile
dalla pagina permessi (`/admin/people/permissions`) ai ruoli redattoriali
che devono vedere l'anteprima.

## Variabili d'ambiente da configurare

Nessuna di queste variabili va mai committata: si impostano solo nel file
`.env` nella **root del repo** (vedi `.env.example`), che sia Docker Compose
(stage/prod) sia Vite/Astro in locale (`vite.envDir` in
`frontend/astro.config.mjs`) leggono da lì — un solo posto dove impostare le
env, niente `.env` duplicati per frontend/backend. `settings.php` legge già
le sue con `getenv('...') ?: ''` — se mancano, il modulo lancia un errore
esplicito invece di generare un link rotto.

| Variabile | Dove impostarla | Letta da | Esempio |
|---|---|---|---|
| `ILDEPOSITO_PREVIEW_SECRET` | `.env` di root → servizio `php` in `backend/compose.yml` (stage/prod) o DDEV `web_environment` (locale) | `backend/web/sites/default/settings.php` → `$settings['ildeposito_preview_secret']` | `openssl rand -hex 32` |
| `ILDEPOSITO_PREVIEW_FRONTEND_URL` | `.env` di root → servizio `php` in `backend/compose.yml` (stage/prod) o DDEV `web_environment` (locale) | `settings.php` → `$settings['ildeposito_preview_frontend_url']` | `https://stage.ildeposito.org` / `https://www.ildeposito.org` |
| `ILDEPOSITO_PREVIEW_SECRET` | stesso `.env` di root → servizio `frontend-api` in `frontend/compose.stage.yml` / `frontend/compose.prod.yml` (stage/prod), oppure letto direttamente da Vite in locale (`npm run dev`) | `frontend/src/pages/preview/[uuid].astro` (via `import.meta.env` / `process.env`) | **stesso valore** usato lato Drupal per lo stesso ambiente |

**Importante:** il secret deve essere **diverso per ogni ambiente**
(dev/stage/prod), ma **identico tra Drupal e frontend all'interno dello
stesso ambiente**. Un secret condiviso tra stage e prod (o assente in uno dei
due lati) rende l'anteprima inutilizzabile o, peggio, permette di forgiare
token validi su un ambiente usando il secret di un altro.

### DDEV (locale)

**Lato Drupal**: in locale non esiste un `.env` letto da DDEV per il
container `web` — si aggiungono a `web_environment` in `.ddev/config.yaml`
(non versionato) e si rilancia `ddev restart`:

```yaml
web_environment:
    - ILDEPOSITO_PREVIEW_SECRET=un-secret-di-sviluppo-qualsiasi
    - ILDEPOSITO_PREVIEW_FRONTEND_URL=https://ildeposito11.ddev.site:4322
```

**Lato Astro (`npm run dev`)**: legge direttamente il `.env` nella root del
repo (stesso file usato da Docker Compose in stage/prod, grazie a
`vite.envDir: '../'` in `astro.config.mjs`) — basta che contenga le stesse
tre variabili (`ILDEPOSITO_PREVIEW_SECRET`, `DRUPAL_API_USER`,
`DRUPAL_API_PASS`), oltre a `DRUPAL_API_URL` (solo per uso locale: in
stage/prod è hardcoded nei compose file). Il valore di
`ILDEPOSITO_PREVIEW_SECRET` deve combaciare con quello messo in
`.ddev/config.yaml` sopra.

## Nota sui permessi JSON:API dell'utenza di servizio

Il frontend usa le stesse credenziali Basic Auth (`DRUPAL_API_USER` /
`DRUPAL_API_PASS`) già in uso per `api/modulo_contatti.ts` per leggere il
nodo — anche se non pubblicato — da `/jsonapi/node/{bundle}/{uuid}`. Perché
questa lettura non torni 403, il ruolo associato a quell'utenza deve avere il
permesso **"Bypass content access control"** (`bypass node access`): i
permessi standard di Drupal core non offrono un "view any unpublished
content" generico per i nodi (solo "view own unpublished content", inutile
per un'utenza di servizio che non è autrice dei contenuti).

Trattandosi di un'utenza esclusivamente server-to-server (mai esposta al
browser, dietro Basic Auth), il rischio è considerato accettabile — ma è
bene saperlo prima di modificarne il ruolo.
