# Content Header Canto

Sotto-componente di `content-header` per i nodi di tipo **canto**. Mostra i metadati principali del canto (autori, periodo, anno, lingua).

## Props

- `entity` (object, obbligatorio): raw data dell'entità restituiti da `ildeposito_raw.manager->getRawData()`

### Campi renderizzati da `entity`

| Campo | Descrizione |
|---|---|
| `field_autori_testo` | Autori del testo (entity reference, linkato) |
| `field_autori_musica` | Autori della musica (entity reference, linkato) |
| `field_periodo` | Periodo storico (taxonomy reference, linkato) |
| `field_anno` | Anno |
| `field_lingua` | Lingua (taxonomy reference) |

## Esempio d'uso

```twig
{{ include('ildeposito:content-header-canto', { entity: entity }, with_context = false) }}
```

Viene incluso automaticamente da `ildeposito:content-header` quando `bundle == 'canto'`.
