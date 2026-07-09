// Interfacce frontend pulite — backend-agnostic.

export interface Ref {
  titolo: string;
  slug: string;
}

// Riferimento leggero a un autore — isPersona distingue individuo (Person)
// da collettivo/gruppo (Organization) per lo schema.org generato sui canti.
export interface AutoreRef extends Ref {
  isPersona: boolean;
}

export interface LinkRef {
  uri: string;
  title: string | null;
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
  autoriTesto: AutoreRef[];
  autoriMusica: AutoreRef[];
  periodi: Ref[];
}

export interface CantoDetail extends CantoCard {
  testo: string;
  audio: string | null;
  fonte: string | null;
  informazioni: string | null;
  altriTitoli: string | null;
  lingue: Ref[];
  periodi: Ref[];
  tags: Ref[];
  tematiche: Ref[];
  dataCreazione: string | null;
  dataModifica: string | null;
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
  nome: string | null;
  cognome: string | null;
  immagine: string | null;
  visualizzazioni: number;
  localizzazioni: Ref[];
  annoNascita: number | null;
  annoMorte: number | null;
}

export interface AutoreDetail {
  id: number | string;
  titolo: string;
  slug: string;
  nome: string | null;
  cognome: string | null;
  informazioni: string | null;
  immagine: string | null;
  visualizzazioni: number;
  localizzazioni: Ref[];
  periodi: Ref[];
  annoNascita: number | null;
  annoMorte: number | null;
  links: LinkRef[];
  dataCreazione: string | null;
  dataModifica: string | null;
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
  immagine: string | null;
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
  immagine: string | null;
  visualizzazioni: number;
  latitude: number | null;
  longitude: number | null;
  localizzazioni: Ref[];
  periodi: Ref[];
  tags: Ref[];
  tematiche: Ref[];
  cantiCollegati: CantoCollegato[];
  links: LinkRef[];
  dataCreazione: string | null;
  dataModifica: string | null;
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
  visualizzazioni: number;
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

export interface Tag extends Tassonomia {
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

// ── Paragraphs ─────────────────────────────────────────

export interface ParagraphTesto {
  type: 'testo';
  testo: string;
}

export interface ParagraphCitazione {
  type: 'citazione';
  testo: string;
  fonte: string | null;
}

export interface ParagraphImmagine {
  type: 'immagine';
  imageUrl: string | null;
  descrizione: string | null;
}

export interface ParagraphCard {
  type: 'card';
  titolo: string | null;
  testo: string | null;
  linkUrl: string | null;
  linkTesto: string | null;
}

export interface ParagraphGriglia {
  type: 'griglia';
  colonne: 'due_50_50' | 'due_33_66' | 'due_66_33' | 'tre_33_33_33';
  items: ParagraphItem[];
}

export type ParagraphItem =
  | ParagraphTesto
  | ParagraphCitazione
  | ParagraphImmagine
  | ParagraphCard
  | ParagraphGriglia;

// ── Pagina ─────────────────────────────────────────────

export interface PaginaDetail {
  id: number | string;
  titolo: string;
  percorso: string;
  descrizioneHeader: string | null;
  paragraphs: ParagraphItem[];
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
