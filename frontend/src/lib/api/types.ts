// Interfacce frontend pulite — backend-agnostic.
// Quando si cambia backend (Directus → Drupal), questi tipi NON cambiano.
// Cambia solo l'adapter in directus/ (o drupal/) e i relativi mapper.

export interface Ref {
  titolo: string;
  slug: string;
}

// ── Canti ──────────────────────────────────────────────

export interface CantoPath {
  id: number | string;
  slug: string;
}

export interface CantoRecente {
  id: number | string;
  titolo: string;
  slug: string;
  capoverso: string | null;
}

export interface CantoCard {
  id: number | string;
  titolo: string;
  slug: string;
  anno: number | null;
  capoverso: string | null;
  videoUrl: string | null;
  accordi: string | null;
  visualizzazioni: number;
  autoriTesto: Ref[];
  autoriMusica: Ref[];
}

export interface CantoDetail extends CantoCard {
  testo: string;
  audio: string | null;
  fonte: string | null;
  informazioni: string | null;
  lingue: Ref[];
  periodi: Ref[];
  tags: Ref[];
  tematiche: Ref[];
}

export interface CantoInAutore {
  id: number | string;
  titolo: string;
  slug: string;
  anno: number | null;
  capoverso: string | null;
  videoUrl: string | null;
  accordi: string | null;
  visualizzazioni: number;
}

export interface CantoCollegato {
  titolo: string;
  slug: string;
  anno: number | null;
  capoverso: string | null;
  videoUrl: string | null;
  accordi: string | null;
}

// ── Autori ─────────────────────────────────────────────

export interface AutorePath {
  id: number | string;
  slug: string;
}

export interface AutoreCard {
  id: number | string;
  titolo: string;
  slug: string;
  immagine: string | null;
  visualizzazioni: number;
  localizzazioni: Ref[];
}

export interface AutoreDetail {
  id: number | string;
  titolo: string;
  slug: string;
  informazioni: string | null;
  immagine: string | null;
  localizzazioni: Ref[];
  periodi: Ref[];
  annoNascita: number | null;
  annoMorte: number | null;
}

// ── Eventi ─────────────────────────────────────────────

export interface EventoPath {
  id: number | string;
  slug: string;
}

export interface EventoForCanto {
  titolo: string;
  slug: string;
  dataEvento: string;
}

export interface EventoDelGiorno {
  id: number | string;
  titolo: string;
  slug: string;
  dataEvento: string;
}

export interface EventoMese {
  id: number | string;
  titolo: string;
  slug: string;
  dataEvento: string;
  immagine: string | null;
  localizzazioni: Ref[];
}

export interface EventoCard {
  id: number | string;
  titolo: string;
  slug: string;
  dataEvento: string | null;
  visualizzazioni: number;
  localizzazioni: Ref[];
  periodi: Ref[];
}

export interface EventoCalendario {
  id: number | string;
  titolo: string;
  slug: string;
  dataEvento: string;
  localizzazioni: Ref[];
  periodi: Ref[];
}

export interface EventoGeo {
  id: number | string;
  titolo: string;
  slug: string;
  dataEvento: string;
  latitude: number;
  longitude: number;
}

export interface EventoDetail {
  id: number | string;
  titolo: string;
  slug: string;
  dataEvento: string | null;
  informazioni: string | null;
  latitude: number | null;
  longitude: number | null;
  localizzazioni: Ref[];
  periodi: Ref[];
  tags: Ref[];
  tematiche: Ref[];
  cantiCollegati: CantoCollegato[];
}

// ── Traduzioni ─────────────────────────────────────────

export interface TraduzionePath {
  id: number | string;
  slug: string;
}

export interface TraduzioneDetail {
  id: number | string;
  titolo: string;
  slug: string;
  testo: string;
  informazioni: string | null;
  lingue: Ref[];
  cantoOriginale: {
    titolo: string;
    slug: string;
    lingue: Ref[];
  } | null;
}

// ── Tassonomie ─────────────────────────────────────────

export interface Tassonomia {
  id: number | string;
  titolo: string;
  slug: string;
}

export interface Periodo extends Tassonomia {
  sort: number;
  immagine: string | null;
}

// ── Informazioni ───────────────────────────────────────

export interface InformazionePath {
  id: number | string;
  titolo: string;
  percorso: string;
}

export interface InformazioneDetail extends InformazionePath {
  testo: string;
}

// ── Contatori per tassonomie ───────────────────────────

export interface ContenutiLingua {
  canti: number;
  traduzioni: number;
}

export interface ContenutiLocalizzazione {
  autori: number;
  eventi: number;
}

export interface ContenutiPeriodo {
  canti: number;
  autori: number;
  eventi: number;
}

export interface ContenutiTag {
  canti: number;
  eventi: number;
}
