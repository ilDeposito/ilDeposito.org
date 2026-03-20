# GitHub Codespaces - Piano di configurazione

## Obiettivo

Permettere lo sviluppo di ilDeposito.org direttamente da GitHub Codespaces,
senza necessita' di setup locale. Il Codespace avvia l'intero stack Docker
(MariaDB, PHP, Nginx, Solr, Memcached) e installa Drupal automaticamente.

## Approccio

Riutilizzare il `docker-compose.yml` esistente con un overlay specifico per
Codespaces tramite `devcontainer.json`. Questo evita duplicazione e mantiene
allineamento con l'infrastruttura gia' testata (Wodby stack).

## Architettura dei servizi in Codespaces

```
┌─────────────────────────────────────────────────┐
│  GitHub Codespace                               │
│                                                 │
│  ┌──────────┐  ┌──────────┐  ┌──────────────┐  │
│  │ PHP 8.5  │  │  Nginx   │  │  MariaDB     │  │
│  │ (Wodby)  │←─│  :80     │  │  11.8        │  │
│  │ Dev      │  │          │  │              │  │
│  │ Container│  └──────────┘  └──────────────┘  │
│  │          │                                   │
│  │ Composer │  ┌──────────┐  ┌──────────────┐  │
│  │ Drush    │  │  Solr 9  │  │  Memcached   │  │
│  │ Node.js  │  │  :8983   │  │              │  │
│  │ npm      │  │          │  │              │  │
│  └──────────┘  └──────────┘  └──────────────┘  │
│                ┌──────────┐                     │
│                │Zookeeper │                     │
│                └──────────┘                     │
└─────────────────────────────────────────────────┘

Porte esposte:
  80   → Drupal (Nginx)  → auto-forward con apertura browser
  8983 → Solr Admin UI   → disponibile su richiesta
```

## File creati

### `.devcontainer/devcontainer.json`

Configurazione principale del dev container:
- Usa il container `php` (Wodby drupal-php) come ambiente di sviluppo
- Compone `docker-compose.yml` base + overlay `docker-compose.codespace.yml`
- Utente remoto: `wodby` (UID 1000)
- Estensioni VS Code: Intelephense, Twig, EditorConfig
- Port forwarding automatico per Nginx (80) e Solr (8983)

### `.devcontainer/docker-compose.codespace.yml`

Overlay Docker Compose che adatta il base per Codespaces:

| Servizio   | Modifica                                                        |
|------------|-----------------------------------------------------------------|
| `php`      | Rimuove mount `../backup`, aggiunge `COMPOSER_MEMORY_LIMIT=-1`  |
| `nginx`    | Espone porta 80, rimuove label Caddy, solo rete `internal`      |
| `mariadb`  | Named volume per `mariadb-init` (directory potrebbe non esistere)|
| `crond`    | Disabilitato (`profiles: [donotstart]`)                         |
| `solr`     | Espone porta 8983                                               |
| `caddy` (network) | Override da `external: true` a bridge locale              |

### `.devcontainer/post-create.sh`

Script automatico post-creazione (basato su `script/reset.sh`):

1. `composer install`
2. Attende MariaDB pronto (timeout 60s)
3. `drush si minimal` con admin/admin
4. Imposta UUID sito per compatibilita' config
5. `drush cim` (importa configurazioni)
6. `drush updb` (aggiorna database)
7. `drush ildeposito:create-default-media`
8. `drush locale:check` + `drush locale:update`
9. `drush cim` (seconda importazione post-traduzioni)
10. `drush search-api:index`
11. `drush cr` (cache rebuild)
12. `npm install` + `npm run production` (build tema)

### `web/sites/default/settings.codespace.php`

Settings Drupal per ambiente Codespace:
- Connessione DB: `mariadb:3306`, credenziali `drupal/drupal`
- Include `settings.dev.php` (debug, twig debug, cache disabilitata)
- Configurazione Memcached
- Trusted host pattern: `\.app\.github\.dev$`

## File modificati

### `web/sites/default/settings.php`

Aggiunto check per variabile `CODESPACES` (settata automaticamente da GitHub)
tra il check `ILDEPOSITO_ENV` (produzione) e `IS_DDEV_PROJECT` (locale):

```php
if (isset($_SERVER['ILDEPOSITO_ENV'])) {
    include __DIR__ . '/settings.live.php';
} elseif (getenv('CODESPACES') === 'true') {
    include __DIR__ . '/settings.codespace.php';
} else {
    // blocco DDEV invariato
}
```

Modifica backward-compatible: non cambia nulla per prod, stage o DDEV.

## Come usare

1. Pushare il branch `feat/codespace-settings` su GitHub
2. Su github.com, cliccare **Code > Codespaces > Create codespace on feat/codespace-settings**
3. Attendere il build del container e l'esecuzione di `post-create.sh` (~5 min)
4. Drupal sara' accessibile dalla tab **Ports** sulla porta 80
5. Terminale disponibile direttamente nel container PHP con `composer`, `drush`, `npm`

## Verifica

- [ ] Tutti i servizi partono (`docker compose ps`)
- [ ] Nginx risponde sulla porta forwardata
- [ ] `drush status` mostra Drupal bootstrappato
- [ ] `npm run production` compila il tema senza errori
- [ ] Solr admin accessibile su porta 8983

## Note

- Il file `.env` contiene solo tag immagini Docker e credenziali dev (no secrets)
- L'utente Wodby (`wodby`, UID 1000) e' compatibile con Codespaces
- Node.js e' incluso nell'immagine Wodby PHP dev
- Il crond e' disabilitato in Codespaces (non necessario per sviluppo)
