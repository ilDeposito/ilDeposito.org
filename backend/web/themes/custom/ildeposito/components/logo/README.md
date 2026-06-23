# Componente SDC: Logo

Questo componente fornisce il logo SVG de ilDeposito, personalizzabile in colore e dimensione.

## Utilizzo in Twig

Richiama il componente nel tuo template Twig del tema:

```twig
{% include 'ildeposito:logo' with {
  class_color: 'text-primary',
  size: '48px',
} %}
```

- `class_color`: aggiunge una classe CSS per il colore (es: `text-primary`, `text-danger`)
- `size`: imposta la dimensione del logo (es: `48px`, `2em`, `100%`)
- `attributes`: array di attributi HTML extra (opzionale)

## Esempi

Logo blu grande:
```twig
{% include 'ildeposito:logo' with { class_color: 'text-primary', size: '96px' } %}
```

Logo rosso piccolo:
```twig
{% include 'ildeposito:logo' with { class_color: 'text-danger', size: '32px' } %}
```

Logo responsive:
```twig
{% include 'ildeposito:logo' with { size: '100%' } %}
```

## Note
- Il colore viene gestito tramite la proprietà CSS `color` (usa classi Bootstrap o custom).
- La dimensione viene gestita tramite gli attributi SVG `width` e `height`.
