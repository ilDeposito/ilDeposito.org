# leaflet-map

Mappa interattiva basata su [Leaflet](https://leafletjs.com/) con tile OpenStreetMap.

## Props

| Prop         | Tipo    | Obbligatorio | Default | Descrizione                          |
|--------------|---------|:---:|---------|--------------------------------------|
| `lat`        | number  | ✓ | —       | Latitudine del centro della mappa    |
| `lon`        | number  | ✓ | —       | Longitudine del centro della mappa   |
| `zoom`       | integer |   | `13`    | Livello di zoom iniziale (0–18)      |
| `attributes` | Attribute |  | —       | Attributi HTML aggiuntivi sul `<div>`|

## Utilizzo

```twig
{{ include('ildeposito:leaflet-map', {
  lat: 41.9028,
  lon: 12.4964,
  zoom: 12,
}, with_context = false) }}
```

## Note

- Leaflet JS e CSS vengono caricati da CDN (unpkg.com, versione 1.9.4) tramite `ildeposito/leaflet`.
- L'altezza del contenitore è `350px` di default; sovrascrivibile con classi BS5 (es. `style="min-height:500px"`) via `attributes`.
- La libreria `ildeposito/leaflet` è dichiarata in `ildeposito.libraries.yml` e caricata automaticamente tramite `libraryOverrides` nel component.yml.
