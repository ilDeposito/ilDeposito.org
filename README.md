<p align="center">
  <img src="logo.png" alt="ilDeposito.org" width="300">
</p>

<p align="center">
  Archivio online di canti di protesta politica e sociale.
</p>

---

## Cos'è

ilDeposito.org raccoglie e cataloga canti, autori, traduzioni ed eventi
legati alla tradizione della canzone sociale e di protesta.

## Architettura

Il progetto è un monorepo con due applicazioni indipendenti:

- **Backend** ([`backend/`](backend/)) — Drupal 11, espone i contenuti
  tramite JSON:API
- **Frontend** ([`frontend/`](frontend/)) — Astro 6, consuma le API a build
  time e genera un sito statico, con alcuni endpoint server-side on-demand
  (form contatti)

```
┌─────────────┐   JSON:API   ┌──────────────┐
│   Drupal     │ ───────────▶ │    Astro     │
│  (backend)   │              │  (frontend)  │
└─────────────┘              └──────────────┘
```

Per i dettagli tecnici, le convenzioni di sviluppo e i comandi operativi:

- [CLAUDE.md](CLAUDE.md) — panoramica completa di architettura, ambienti e convenzioni
- [docs/backend.md](docs/backend.md) — moduli custom e struttura Drupal
- [docs/frontend.md](docs/frontend.md) — struttura e convenzioni Astro

## Licenza

Distribuito con licenza [GNU GPLv3](LICENSE.md).
