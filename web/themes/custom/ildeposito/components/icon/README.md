# Componente SDC: Icon

Renderizza un'icona SVG inline attingendo dallo sprite `assets/icons/sprite.svg`.
Il colore segue `currentColor` e si controlla con le classi Bootstrap `text-*`.

---

## Props

| Prop         | Tipo      | Obbligatorio | Default | Descrizione                                      |
|--------------|-----------|--------------|---------|--------------------------------------------------|
| `name`       | `string`  | ✅           | —       | ID del `<symbol>` nello sprite (es. `play`) **senza** `-fill` |
| `type`       | `string`  | ✗            | `fill`  | Variante sprite: `fill` (default, aggiunge `-fill` al nome e usa `sprite_fill.svg`), `regular` (nome invariato, usa `sprite_regular.svg`) |
| `class`      | `array`   | ✗            | `[]`    | Classi CSS aggiuntive (colore, dimensione, ecc.) |
| `size`       | `string`  | ✗            | —       | Valore CSS `font-size` (es. `24px`, `1.5rem`)    |
| `attributes` | Attribute | ✗            | —       | Attributi HTML extra (`role`, `aria-label`, ecc.) |

---


## Utilizzo base

```twig
{# Variante fill (default): usa sprite_fill.svg e aggiunge -fill al nome #}
{{ include('ildeposito:icon', { name: 'play' }, with_context = false) }}

{# Variante regular: usa sprite_regular.svg, nome invariato #}
{{ include('ildeposito:icon', { name: 'play', type: 'regular' }, with_context = false) }}
```


## Colore via classi Bootstrap `text-*`

```twig
{{ include('ildeposito:icon', {
  name: 'music-notes',
  class: ['text-primary'],
}, with_context = false) }}

{{ include('ildeposito:icon', {
  name: 'music-notes',
  type: 'regular',
  class: ['text-primary'],
}, with_context = false) }}
```

## Dimensione via classi Bootstrap `fs-*`

| Classe | `font-size` |
|--------|-------------|
| `fs-1` | 2.5rem      |
| `fs-2` | 2rem        |
| `fs-3` | 1.75rem     |
| `fs-4` | 1.5rem      |
| `fs-5` | 1.25rem     |
| `fs-6` | 1rem        |

```twig
{{ include('ildeposito:icon', {
  name: 'headphones',
  class: ['text-secondary', 'fs-3'],
}, with_context = false) }}
```

## Dimensione arbitraria via prop `size`

Alternativa a `fs-*` per grandezze specifiche non coperte dalla scala Bootstrap:

```twig
{{ include('ildeposito:icon', {
  name: 'guitar',
  class: ['text-accent'],
  size: '48px',
}, with_context = false) }}
```

```twig
{{ include('ildeposito:icon', {
  name: 'user',
  size: '1.25rem',
}, with_context = false) }}
```

## Con attributi extra (accessibilità)

Quando l'icona ha significato semantico (non è decorativa), passa `aria-label` e rimuovi `aria-hidden`:

```twig
{{ include('ildeposito:icon', {
  name: 'user',
  class: ['text-dark'],
  size: '24px',
  attributes: create_attribute({ 'role': 'img', 'aria-label': 'Profilo utente', 'aria-hidden': 'false' }),
}, with_context = false) }}
```

---

## ID disponibili nello sprite

Elenca sempre il nome **senza** `-fill`: il componente aggiunge `-fill` automaticamente se `type` è `fill` (default).

| ID                   | Descrizione              |
|----------------------|--------------------------|
| `pause`              | Pausa                    |
| `play`               | Play                     |
| `headphones`         | Cuffie                   |
| `music-note`         | Nota musicale            |
| `music-note-simple`  | Nota musicale (semplice) |
| `music-notes`        | Note musicali            |
| `music-notes-simple` | Note musicali (semplice) |
| `guitar`             | Chitarra                 |
| `video`              | Video                    |
| `youtube-logo`       | YouTube                  |
| `ildeposito`         | Logo ilDeposito          |
| `facebook-logo`      | Facebook                 |
| `list`               | Lista                    |
| `books`              | Libri                    |
| `chat-text`          | Chat                     |
| `magnifying-glass`   | Ricerca                  |
| `file-pdf`           | PDF                      |
| `quotes`             | Citazioni                |
| `user`               | Utente                   |
| `user-list`          | Lista utenti             |
| `tag`                | Tag                      |
| `calendar-dots`      | Calendario               |
| `calendar-star`      | Calendario (stella)      |
| `share-network`      | Condividi                |
| `download-simple`    | Scarica                  |
