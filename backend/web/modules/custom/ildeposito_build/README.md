# ilDeposito Build

Modulo Drupal custom che consente ai redattori di pubblicare i contenuti sul sito pubblico (Astro SSG) direttamente dall'interfaccia admin, senza accesso a GitHub.

## Funzionamento

Il sito pubblico è generato staticamente da Astro. Quando un redattore modifica un canto, un autore o un evento in Drupal, le modifiche non sono visibili finché Astro non viene ricompilato. Questo modulo espone un pulsante "Pubblica contenuti" nella toolbar admin che avvia il workflow GitHub Actions di build e ne monitora l'esito in tempo reale.

## Componenti

### Toolbar (`IldepositoBuildHooks`)
Aggiunge il pulsante "Pubblica contenuti" nella barra admin superiore. Visibile solo negli ambienti `stage` e `prod` e solo agli utenti con il permesso `trigger frontend build`.

### Form con Batch API (`BuildFrontendForm`)
Gestisce l'intera sequenza di pubblicazione:
1. Chiama l'API GitHub per avviare il `workflow_dispatch`
2. Aspetta che GitHub registri la run (fase "waiting")
3. Fa polling ogni 3 secondi fino al completamento (fase "polling")
4. Mostra la barra di avanzamento con il tempo trascorso
5. Notifica l'esito finale (successo / fallimento / annullato)

Timeout massimo: 120 poll × 3 sec = 6 minuti. Durata stimata usata per la barra di avanzamento: 180 secondi.

### GitHub Workflow Client (`GitHubWorkflowClient`)
Gestisce tutta la comunicazione con GitHub API v3:
- Genera un JWT firmato RS256 con la chiave privata della GitHub App
- Scambia il JWT con un installation access token (TTL ~1h)
- Trigger: `POST /repos/{repo}/actions/workflows/{workflow}/dispatches`
- Discovery run: `GET /repos/{repo}/actions/workflows/{workflow}/runs`
- Polling run: `GET /repos/{repo}/actions/runs/{run_id}`

Repo target: `ilDeposito/ilDeposito.org`, branch `main`.

### Access Check (`BuildAccessCheck`)
Blocca l'accesso alla route `/admin/pubblica-contenuti` se la variabile d'ambiente `ILDEPOSITO_ENV` non è `stage` o `prod`. In locale (DDEV) il pulsante non compare mai.

### JSON:API Write Firewall (`JsonApiWriteFirewall`)
Event subscriber che blocca tutte le richieste di scrittura (`POST`, `PATCH`, `DELETE`) a JSON:API, con whitelist per tipo di risorsa e metodo. Il firewall agisce a priorità 28 (prima della maggior parte dei controller) e risponde `405 Method Not Allowed`.

La whitelist è configurata nel codice in `JsonApiWriteFirewall::ALLOWED_WRITES`.

## Workflow attivati

Il form espone due pulsanti, ognuno agganciato a un workflow diverso in base all'ambiente (`ILDEPOSITO_ENV`):

| Ambiente | Pubblica contenuti | Pubblica contenuti + PDF |
|---|---|---|
| Stage | `build-frontend-content-stage.yml` | `build-frontend-stage.yml` |
| Produzione | `build-frontend-content-prod.yml` | `build-frontend-prod.yml` |

"Pubblica contenuti" salta la rigenerazione PDF (`SKIP_PDF=1`, vedi `docker-entrypoint.sh`), quindi è più veloce; "Pubblica contenuti + PDF" rigenera anche i PDF dei canti modificati.

## Configurazione

### 1. GitHub App

Il modulo si autentica tramite **GitHub App** (non personal access token), così le credenziali sono per-installazione e revocabili senza toccare account personali.

In `settings.stage.php` / `settings.prod.php`:

```php
$settings['ildeposito_build_github_app_id'] = '123456';
$settings['ildeposito_build_github_installation_id'] = '78901234';
// Opzionale: percorso custom alla chiave privata.
// Default: DRUPAL_ROOT . '/../private/github-app.pem'
$settings['ildeposito_build_github_private_key'] = '/percorso/assoluto/github-app.pem';
```

### 2. Chiave privata

Posizionare il file `.pem` scaricato dalla GitHub App in `backend/private/github-app.pem` (fuori dal docroot, non versionato).

### 3. Permesso Drupal

Assegnare `trigger frontend build` al ruolo che deve poter pubblicare (es. "Editor").

## Verifica configurazione

Se la GitHub App non è configurata, il form mostra un avviso e blocca il pulsante di invio. Per verificare:

```
/admin/pubblica-contenuti
```

## Note per Drupal 12

- Il modulo dipende da `drupal:toolbar`, che in Drupal 12 sarà sostituito dal modulo `navigation`. L'hook `hook_toolbar()` e la dipendenza dovranno essere adattati.
- `BuildFrontendForm::processBuild()` usa `\Drupal::service()` statico perché la Batch API non supporta ancora DI-aware callbacks. Da refactorizzare quando Drupal 12 lo consentirà.
