# ilDeposito Contatti

Gestisce le entità custom `ildeposito_contatto` create dalle submission del form contatti sul frontend Astro via JSON:API.

## Entity type

**Machine name:** `ildeposito_contatto`  
**Bundle principale:** `modulo_contatti` (campi: `field_nome`, `field_email`, `field_messaggio`)  
**Bundle aggiuntivo:** `segnalazione_canti` (aggiunge `field_canto`)

I campi sono configurabili da UI tramite Field UI (`/admin/structure/ildeposito-contatto-types`).

## Flusso di salvataggio

Al `presave` di ogni nuova entità, `IldepositoContattiHooks::entityPresave`:
- forza `status = 'nuova'` (non sovrascrivibile dal client JSON:API)
- cattura l'IP del client lato server (`ip_address`)

## Notifiche email

All'insert di un `modulo_contatti`, `IldepositoContattiHooks::entityInsert` invia automaticamente un'email di notifica a tutti i destinatari configurati.

**Oggetto:**
```
[Contatti] Messaggio ricevuto da {nome} ({email})
```

**Corpo:**
```
Nome: {nome}
Email: {email}
Messaggio:
{messaggio}
```

### Configurare i destinatari

I destinatari sono letti dallo **State** Drupal, non dalla configurazione, in modo che non finiscano nel config export e possano variare per ambiente.

**Impostare via Drush:**
```bash
ddev drush state:set contatti_destinatari "info@ildeposito.org,altro@esempio.it"
# oppure in remoto:
./ildeposito.sh drush state:set contatti_destinatari "info@ildeposito.org"
```

**Verificare il valore corrente:**
```bash
ddev drush state:get contatti_destinatari
```

**Rimuovere:**
```bash
ddev drush state:delete contatti_destinatari
```

Lo stato accetta una stringa di indirizzi email separati da virgola. Gli indirizzi non validi vengono ignorati silenziosamente. Se lo stato è vuoto o non impostato, nessuna email viene inviata.

### SMTP

L'invio usa il sistema mail di Drupal core (`MailManagerInterface`). In staging/produzione, il trasporto SMTP è configurato via variabili d'ambiente in `settings.remote.php`:

| Variabile     | Descrizione                        |
|---------------|------------------------------------|
| `SMTP_HOST`   | Host SMTP                          |
| `SMTP_PORT`   | Porta (default: 587)               |
| `SMTP_USER`   | Utente SMTP                        |
| `SMTP_PASS`   | Password SMTP                      |
| `MAIL_FROM`   | Indirizzo mittente (es. `noreply@ildeposito.org`) |

Il mittente viene sovrascritto dall'hook `mail_alter` nel modulo `ildeposito_utils` tramite la variabile d'ambiente `MAIL_FROM`.

In locale (DDEV), le email vengono intercettate da **Mailpit** (`https://ildeposito11.ddev.site:8026`).

## Permessi

| Permesso                          | Descrizione                               |
|-----------------------------------|-------------------------------------------|
| `administer ildeposito contatti`  | Accesso completo: lista, modifica, elimina |
| `view ildeposito contatti`        | Sola lettura                               |
