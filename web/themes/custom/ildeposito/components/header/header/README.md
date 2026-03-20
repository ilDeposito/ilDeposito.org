# Componente SDC: Header

Header mobile-first per sito di ricerca canti musicali.

- Logo a sinistra (link home)
- Barra di ricerca centrata
- Hamburger menu a destra
- Bootstrap 5, Bootstrap Icons, variabili Bootstrap
- Struttura pulita, responsive, senza colori hardcoded

## Props

| Prop              | Tipo      | Obbligatorio | Default                  | Descrizione                                 |
|-------------------|-----------|--------------|--------------------------|---------------------------------------------|
| `logo_url`        | `string`  | ✅           | —                        | URL destinazione logo (home)                |
| `search_placeholder` | `string`  | ✅           | 'cerca canti, autori..'  | Testo placeholder barra di ricerca          |
| `menu_aria_label` | `string`  | ✅           | 'Apri navigazione'       | Label accessibilità hamburger menu          |
| `attributes`      | Attribute | ✗            | —                        | Attributi HTML extra per il header          |

## Esempio utilizzo

```twig
{{ include('ildeposito:header', {
  logo_url: '/',
  search_placeholder: 'cerca canti, autori..',
  menu_aria_label: 'Apri navigazione',
}, with_context = false) }}
```

## Note
- Tutti i colori e spaziature usano variabili Bootstrap.
- Il logo è uno square con icona musicale (Bootstrap Icons).
- La barra di ricerca è centrata e mobile-first.
- Il menu hamburger è a destra, accessibile.
