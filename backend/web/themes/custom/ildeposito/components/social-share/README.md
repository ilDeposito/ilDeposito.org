# Social Share

Pulsante di condivisione adattivo:
- **Mobile** (pointer: coarse): apre il dialog nativo del SO tramite [Web Share API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Share_API).
- **Desktop**: mostra un popover Bootstrap 5 con le opzioni social.

## Props

| Prop | Tipo | Obbligatorio | Descrizione |
|------|------|:---:|-------------|
| `url` | `string` | ✓ | URL completo del contenuto da condividere |
| `title` | `string` | | Titolo per il dialog nativo |
| `text` | `string` | | Descrizione breve per il dialog nativo |
| `class` | `array` | | Classi BS5 aggiuntive sul `<button>` |
| `attributes` | `Attribute` | | Attributi HTML aggiuntivi |

## Utilizzo

```twig
{{ include('ildeposito:social-share', {
  url: node_url,
  title: node.label(),
  text: 'Scopri questo canto su ilDeposito',
}, with_context = false) }}
```

## Note

- Il JS viene compilato da `_social-share.js` → `social-share.js` tramite Laravel Mix.
- La library `ildeposito/social-share` viene allegata automaticamente via `attach_library()` nel template.
- L'icona usa `ildeposito:icon` con `name: 'play'`; cambia il prop per usare un'icona diversa.
