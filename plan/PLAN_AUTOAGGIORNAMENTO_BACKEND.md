# Piano — autoaggiornamento backend Drupal su stage

## Obiettivo

Automatizzare l'aggiornamento delle dipendenze `drupal/*` in stage:

1. rilevare aggiornamenti Composer;
2. creare un branch `upgrade/YYYY-MM-DD`;
3. aggiornare dipendenze e database Drupal;
4. fermare la procedura e ripristinare stage se la configurazione attiva differisce dai file versionati;
5. creare una pull request con auto-merge;
6. lasciare che il push risultante su `main` avvii il deploy stage esistente.

Il trigger periodico non sarà definito nel workflow: sarà un crontab sul server.

## Stato attuale verificato

- Il workflow `.github/workflows/deploy-stage.yml` parte su ogni push a `main`.
- Sul runner stage esegue `git fetch origin main` e `git reset --hard origin/main`: dopo il merge la working copy stage torna quindi esplicitamente a `main`.
- `./ildeposito.sh allinea-prod` esiste, è limitato all'ambiente stage e ripristina database/file da prod, eseguendo poi `updatedb`, `config:import`, cache rebuild e build frontend.
- Le variabili `TELEGRAM_BOT_TOKEN` e `TELEGRAM_CHAT_ID` esistono già nell'ambiente Docker, ma serve un meccanismo esplicito per notificare anche gli errori dello script/workflow.

## Decisioni prese

- Trigger: manuale e tramite crontab esterno (non `schedule:` in GitHub Actions).
- Configurazione valida: `drush config:status` non deve riportare differenze fra configurazione attiva nel DB e YAML in `backend/web/sites/default/config`.
- In caso di config drift o di errore: tornare a `origin/main` e riallineare stage a prod tramite `./ildeposito.sh allinea-prod`.
- Aggiornamenti ammessi: tutto ciò che risulta da `composer update --with-dependencies 'drupal/*'`.
- Merge: auto-merge GitHub, senza review obbligatoria; la PR resta comunque obbligatoria e i check devono essere verdi.
- Notifiche Telegram: errori/config drift e merge riuscito su `main`.

## Prerequisiti manuali (GitHub)

### 1. Regole di `main`

In **Repository Settings → Rules → Rulesets**, modificare o creare la regola per `main`:

- mantenere **Require a pull request before merging**;
- impostare a zero o disabilitare **Required approvals**;
- richiedere il check di validazione dell'aggiornamento backend (da aggiungere con il workflow);
- consentire il metodo di merge scelto (consigliato: squash);
- in **Settings → General → Pull Requests**, abilitare **Allow auto-merge**;
- facoltativo ma consigliato: **Automatically delete head branches**.

Una regola di protezione rivolta a `main` non può richiedere review solo alle PR non `upgrade/*`: una review obbligatoria bloccherebbe anche queste PR. La garanzia per questo flusso deve quindi essere il check obbligatorio del workflow.

### 2. GitHub App di automazione

Creare e installare una GitHub App dedicata al repository, diversa da credenziali personali, con almeno:

- **Contents: Read and write** — creare e pushare i branch upgrade;
- **Pull requests: Read and write** — creare PR e abilitare auto-merge;
- **Actions: Read and write** — solo se il comando cron usa l'App anche per il `workflow_dispatch`.

Conservare app ID, installation ID e private key come secret GitHub. Il token di installazione dell'App deve essere usato per push e PR: un push fatto con il solo `GITHUB_TOKEN` di Actions non innesca altri workflow, quindi non attiverebbe il deploy stage.

### 3. Crontab

Il cron deve inviare un `workflow_dispatch` del workflow sul ref `main`; non deve eseguire direttamente aggiornamenti Composer/Drupal fuori dal workflow.

Esempio concettuale:

```sh
gh workflow run backend-update.yml --repo ilDeposito/ilDeposito.org --ref main
```

L'utente di sistema che esegue cron deve avere autenticazione non interattiva con permesso di avviare workflow (PAT fine-grained o token di GitHub App). Prima di abilitarlo, verificare manualmente il comando sul server.

## Implementazione nel repository

### 1. Workflow `.github/workflows/backend-update.yml`

Creare un workflow con:

- trigger `workflow_dispatch`;
- runner `self-hosted` e working directory stage già usata da `deploy-stage.yml`;
- `concurrency` che eviti sovrapposizioni con deploy stage e con un altro upgrade;
- checkout/sincronizzazione esplicita su `origin/main`;
- token GitHub App per autenticare push, PR e auto-merge;
- URL della run passato al comando/script per renderlo disponibile nelle notifiche;
- job o step finale che notifichi Telegram soltanto quando una PR viene effettivamente mergiata.

Il workflow deve fallire in modo visibile se il runner non è su `main`, se la worktree non è pulita oppure se `origin/main` non può essere raggiunto.

### 2. Comando `./ildeposito.sh backend-update`

Aggiungere un comando esplicitamente limitato a stage, idempotente e privo di operazioni GitHub API. Il workflow mantiene la responsabilità di branch/commit/push/PR.

Ordine operativo:

1. verificare `ENV=stage`, branch `main`, worktree pulita e HEAD identico a `origin/main`;
2. eseguire `composer outdated 'drupal/*'` in JSON, parsando in modo affidabile nome/versione installata/versione disponibile;
3. se non esistono aggiornamenti Drupal, terminare con successo e senza PR;
4. restituire al workflow la lista delle dipendenze aggiornabili;
5. eseguire `composer update --with-dependencies 'drupal/*'` tramite il container PHP;
6. eseguire `drush updb -y`;
7. eseguire `drush config:status`; se sono presenti differenze, restituire uno stato distinguibile di config drift;
8. se la config è pulita: `drush cr`, `drush cim -y`, build dei contenuti (`./ildeposito.sh build-frontend-content` oppure la modalità da confermare); 
9. restituire la lista finale dei pacchetti Drupal modificati, ricavata dal diff del lockfile, per messaggio commit e PR.

Nota: la sintassi richiesta può aggiornare anche dipendenze transitive necessarie. Il commit deve però elencare esclusivamente i pacchetti Drupal (core e contrib), una riga per pacchetto con vecchia e nuova versione.

### 3. Branch, commit, push e PR

Nel workflow, dopo il successo del comando:

1. creare `upgrade/YYYY-MM-DD` a partire da `origin/main`;
2. eseguire il comando di update;
3. verificare che i soli file attesi siano modificati (almeno `backend/composer.lock`, ed eventualmente `backend/composer.json` e file di scaffold); 
4. configurare identità Git dell'App/bot;
5. creare un commit il cui corpo contenga una riga per ogni modulo/core aggiornato, ad esempio:

   ```text
   drupal/core-recommended 11.2.0 → 11.2.1
   drupal/gin 5.0.3 → 5.0.4
   ```

6. pushare il branch con token GitHub App;
7. creare la PR verso `main`, con la stessa lista nel corpo;
8. abilitare auto-merge con il metodo definito dalle regole del repository.

## Percorso di rollback e notifiche

Per ogni errore da Composer, Drush, build o verifica config:

1. raccogliere fase fallita, output sintetico e URL della run;
2. inviare Telegram;
3. ripristinare i file con `git fetch origin main` e `git reset --hard origin/main`;
4. eseguire `./ildeposito.sh allinea-prod` per ripristinare database e file stage da prod;
5. far fallire il workflow.

Il rollback va protetto da una gestione errori che lo esegua anche con `set -e`. Se anche il rollback fallisce, Telegram deve segnalarlo separatamente come errore critico.

Per notificare il merge riuscito, usare un workflow su `pull_request` con tipo `closed`, filtrando:

- `github.event.pull_request.merged == true`;
- base branch `main`;
- head branch che inizia per `upgrade/`.

La notifica deve includere numero e URL della PR, SHA risultante su `main` e link alla run del deploy stage, se disponibile.

## Verifica prima dell'attivazione

Eseguire i seguenti scenari sul runner stage, inizialmente con cron disabilitato:

1. nessun update Drupal: nessun branch, commit o PR;
2. worktree sporca / runner non su main: workflow bloccato senza modificare DB;
3. config drift dopo `updb`: Telegram, reset Git, `allinea-prod`, nessuna PR;
4. errore Composer, Drush o build: stesso rollback e notifica;
5. update riuscito: contenuto del commit corretto, push del branch, PR e auto-merge;
6. push su main: avvio di `deploy-stage.yml`;
7. deploy concluso: checkout stage su `origin/main`, database connesso e frontend build completata;
8. Telegram: verifica di errore, config drift, merge e rollback fallito.

Solo dopo il superamento di tutti gli scenari configurare il crontab definitivo.
