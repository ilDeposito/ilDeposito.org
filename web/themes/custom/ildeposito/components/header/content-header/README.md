# Content Header component

Header per pagine con titolo, descrizione e immagine.

## Props
- `page_title` (string, obbligatorio): Titolo della pagina
- `page_description` (string, obbligatorio): Descrizione breve
- `image_url` (string, obbligatorio): URL dell'immagine

## Esempio d'uso

```twig
{{ include('ildeposito:content-header', {
  page_title: 'Archivio Canti',
  page_description: 'Tutti i canti popolari e sociali italiani.',
  image_url: '/path/to/image.jpg'
}, with_context = false) }}
```
