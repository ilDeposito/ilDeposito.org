# Component: testi

Visualizza una lista di testi o un singolo testo.

## Props
- `data` (object/array): Dati da visualizzare. Può essere un array di stringhe o un singolo valore.

## Esempio di utilizzo

```twig
{{ include('ildeposito:testi', { data: dati_testo }, with_context = false) }}
```

## Output
- Ogni elemento viene visualizzato in un blocco `.testi__item`.
- Se `data` non è un array, viene visualizzato come singolo elemento.
