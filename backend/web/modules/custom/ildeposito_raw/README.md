# Modulo ildeposito_raw

Questo modulo fornisce dati raw delle entità Drupal da utilizzare nei template Twig, ottimizzato per Drupal 11 e PHP 8.4.

## Utilizzo dei template

Il modulo espone un tema Twig chiamato `ildeposito_raw`. I dati raw dell'entità sono disponibili nella variabile `data` e l'entità stessa nella variabile `entity`.

### Override dei template

Puoi creare template personalizzati per bundle e view mode semplicemente aggiungendo file Twig nella cartella `templates` del modulo o del tema custom, seguendo questa convenzione:

- `ildeposito-raw.html.twig` (template di default per tutte le entità)
- `ildeposito-raw__BUNDLE.html.twig` (per un bundle specifico, es: `article`)
- `ildeposito-raw__BUNDLE__VIEWMODE.html.twig` (per un bundle e una modalità di visualizzazione specifici, es: `article` e `teaser`)

**Esempi:**

- `ildeposito-raw.html.twig` → tutte le entità
- `ildeposito-raw__article.html.twig` → tutti i nodi bundle `article`
- `ildeposito-raw__article__teaser.html.twig` → nodi bundle `article` in modalità `teaser`

> **Nota:** Non usare mai il tipo entità (`node`, `taxonomy_term`, ecc.) nel nome del template: il prefisso è sempre `ildeposito_raw`.

## Variabili disponibili nei template

- `entity`: l'entità Drupal
- `data`: array dei dati raw generati dal modulo

## Caching

Il modulo integra i dati raw con il sistema di caching di Drupal:
- Imposta correttamente tag, contesti e max-age
- Invalida la cache su update e delete delle entità

## Best practice
- Segui la SCD (Single Component Directory) per i componenti
- Non modificare moduli o temi contrib
- Aggiorna questa documentazione se aggiungi nuove funzionalità

---

Per domande o personalizzazioni avanzate, consulta la documentazione inline nei file PHP o chiedi al team di sviluppo.
