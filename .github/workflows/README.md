# Workflow GitHub Actions

La pipeline di rilascio è raccolta in due workflow. Entrambi vengono eseguiti
sul runner self-hosted e usano `ildeposito.sh` per le operazioni effettive: i
file YAML scelgono soltanto ambiente, operazione e commit/tag da distribuire.

| Workflow | Ambiente | Avvio | Operazioni disponibili |
| --- | --- | --- | --- |
| `stage.yml` | Stage | Manuale | `deploy`, `content`, `full`, `pdf` |
| `prod.yml` | Produzione | Push di un tag `v*` o manuale | `deploy`, `content`, `full`, `pdf`, `redirect` |

Le operazioni di ciascun ambiente sono serializzate: una nuova operazione
attende la conclusione di quella già in corso. È intenzionale, perché due
deploy simultanei sullo stesso ambiente potrebbero interferire con database,
container e modalità manutenzione.

## Flusso abituale

Il flusso previsto parte sempre da `main`, che deve essere pulito e già
pubblicato su `origin/main`.

1. Eseguire il deploy di `main` su stage:

   ```sh
   ./deploy.sh stage
   ```

   Lo script verifica che il checkout locale coincida con `origin/main`, avvia
   `stage.yml`, resta in attesa e mostra le macro-fasi del deploy: preparazione
   e dipendenze, aggiornamento Drupal e generazione del sito.

2. Verificare il risultato su stage.

3. Creare il rilascio:

   ```sh
   ./deploy.sh prod
   ```

   Lo script mostra gli ultimi tre tag, richiede una versione con editing da
   tastiera, prepara le note con i commit dall'ultimo rilascio e le apre in
   `nano`. In `nano`, salva con `Ctrl+O`, premi `Invio` per confermare il
   nome del file, poi esci con `Ctrl+X`. Dopo la
   conferma crea il tag annotato e la GitHub Release; il push del tag avvia
   automaticamente `prod.yml` e lo script attende anche questo deploy.

`prod` richiede che l’ultima run di `stage.yml` per il commit attuale di
`main` sia conclusa con successo. Anche `prod.yml` esegue lo stesso controllo,
quindi un tag non può aggirarlo. Per usare gli script dal computer locale basta
autenticare una volta GitHub CLI con `gh auth login`.

Durante un deploy completo il terminale mostra solo queste macro-fasi:

1. **Prepara deploy e dipendenze**: controllo di Drupal, backup del database,
   checkout del commit o tag, avvio dei container e `composer install`.
2. **Aggiorna Drupal e indice di ricerca**: modalità manutenzione,
   aggiornamenti del database e della configurazione, cache e indice Search
   API.
3. **Genera sito e PDF** (e, in produzione, i redirect): build del frontend e
   pubblicazione degli artefatti.

I log completi restano disponibili solo nella pagina della run su GitHub, se
servono per analizzare un errore.

## Operazioni di stage

`deploy` sincronizza lo stage con il commit corrente di `main`, aggiorna le
dipendenze e Drupal, ricostruisce il frontend e, se serve, l'indice di ricerca.

`content` ricostruisce soltanto il frontend dei contenuti, senza PDF. `full`
ricostruisce frontend e PDF; `pdf` rigenera solo i PDF. Queste tre operazioni
non cambiano il commit distribuito né importano dati di produzione.

Si possono avviare anche dall'interfaccia GitHub, nella pagina **Actions**,
aprendo **Stage**, scegliendo **Run workflow** e selezionando l'operazione. Da
terminale, per esempio:

```sh
gh workflow run stage.yml --ref main -f operation=content
```

## Operazioni di produzione

Un push di un tag che inizia con `v` avvia sempre `deploy`: il workflow
distribuisce esattamente quel tag. È il percorso usato da `./deploy.sh prod`.

L'avvio manuale di `prod.yml` serve alle operazioni di manutenzione. Per un
deploy manuale occorre selezionare come ref il tag da distribuire e fornire lo
stesso valore nel campo `release_tag`; lo script del server rifiuta valori non
coerenti. Esempio:

```sh
gh workflow run prod.yml --ref v1.2.3 \
  -f operation=deploy \
  -f release_tag=v1.2.3
```

Per `content`, `full`, `pdf` e `redirect` non è richiesto un tag: l'operazione
agisce sul checkout già distribuito in produzione. `redirect` rigenera e
pubblica i redirect; è usata anche dal pulsante Drupal corrispondente.

## Integrazione con Drupal

I pulsanti amministrativi Drupal continuano a usare questi stessi workflow:

- le ricostruzioni del frontend passano `operation=content` o `operation=full`;
- la pubblicazione dei redirect passa `operation=redirect` a `prod.yml`.

Il campo facoltativo `source` identifica l'origine nei log (`GitHub` per gli
avvii manuali, `backend` per Drupal). Se si rinominano i file workflow, occorre
aggiornare anche `GitHubWorkflowClient` nel modulo Drupal.

## Dove vive la logica

`stage.yml` e `prod.yml` restano volutamente piccoli. La logica condizionale è
in `ildeposito.sh`, con il comando `pipeline`; la procedura locale interattiva
è in `deploy.sh`. Prima di modificare la pipeline, aggiornare entrambi gli
ambienti e verificare che il runner self-hosted mantenga le directory di lavoro
indicate nei workflow.
