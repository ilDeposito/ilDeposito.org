# YouTube Mini Player

Mini player YouTube con toggle Play/Pause e icona gestita da `ildeposito:icon`.

## Props

- `youtube_url` (string, required): URL del video YouTube (`watch`, `youtu.be`, `shorts`, `embed`).
- `title` (string, optional): titolo del player per accessibilita.

## Uso

```twig
{{ include('ildeposito:youtube-mini-player', {
  youtube_url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
  title: 'Esempio video'
}, with_context = false) }}
```

## Note

- Il video viene inizializzato con `enablejsapi=1` per poter usare `playVideo`/`pauseVideo` via `postMessage`.
- Al primo click parte subito il video (autoplay), i click successivi eseguono toggle play/pause.
