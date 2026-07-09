import type {
  Ref, LinkRef,
  CantoRecente, CantoCard, CantoDetail, CantoInAutore, CantoCollegato,
  AutoreCard, AutoreDetail,
  EventoForCanto, EventoDelGiorno, EventoMese, EventoCard, EventoCalendario, EventoGeo, EventoDetail,
  TraduzioneDetail,
  ParagraphItem,
} from '../types.js';
import { type IncludedMap, resolveRefs, resolveAutoreRefs, resolveMany, resolveImageUrl, extractSlug } from './resolvers.js';

// ── Helpers ────────────────────────────────────────────

function parseYear(raw: string | null | undefined): number | null {
  if (!raw) return null;
  return new Date(raw).getUTCFullYear();
}

function extractVideoUrl(fieldAudio: any[] | null | undefined): string | null {
  if (!fieldAudio?.length) return null;
  return fieldAudio[0].uri?.trim() || null;
}

function mapLinks(raw: any[] | undefined): LinkRef[] {
  if (!Array.isArray(raw)) return [];
  return raw
    .filter((l) => typeof l?.uri === 'string' && l.uri.startsWith('http'))
    .map((l) => ({ uri: l.uri, title: l.title || null }));
}

const ALLOWED_TAGS = new Set([
  'p', 'br', 'a', 'em', 'i', 'strong', 'b', 'u',
  'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'h5', 'h6',
  'blockquote', 'sup', 'sub', 'span', 'div',
]);

const ALLOWED_ATTRS: Record<string, Set<string>> = {
  a: new Set(['href', 'title', 'target', 'rel']),
  span: new Set(['class']),
  div: new Set(['class']),
};

export function sanitizeHtml(html: string): string {
  return html.replace(/<\/?([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)?\/?>/g, (match, tag, attrs) => {
    const lower = tag.toLowerCase();
    if (!ALLOWED_TAGS.has(lower)) return '';

    if (match.startsWith('</')) return `</${lower}>`;

    const allowedAttrs = ALLOWED_ATTRS[lower];
    if (!allowedAttrs || !attrs?.trim()) return `<${lower}>`;

    const safeAttrs = [...(attrs as string).matchAll(/([a-zA-Z-]+)\s*=\s*"([^"]*)"/g)]
      .filter(([, name]) => allowedAttrs.has(name.toLowerCase()))
      .filter(([, name, value]) => {
        if (name.toLowerCase() === 'href') {
          return /^(?:https?:\/\/|\/|#|mailto:)/.test(value.trim());
        }
        return true;
      })
      .map(([, name, value]) => `${name.toLowerCase()}="${value}"`)
      .join(' ');

    return safeAttrs ? `<${lower} ${safeAttrs}>` : `<${lower}>`;
  });
}

function textValue(field: any): string | null {
  if (!field) return null;
  if (typeof field === 'string') return sanitizeHtml(field);
  const raw = field.processed ?? field.value ?? null;
  return raw ? sanitizeHtml(raw) : null;
}

function plainText(field: string | null | undefined): string {
  if (!field) return '';
  return field.replace(/<br\s*\/?>[\r\n]*/gi, '\n').replace(/\r\n/g, '\n');
}

// ── Canti ──────────────────────────────────────────────

export function mapCantoRecente(raw: any): CantoRecente {
  const a = raw.attributes;
  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    capoverso: a.field_capoverso ?? null,
  };
}

export function mapCantoCard(raw: any, included: IncludedMap): CantoCard {
  const a = raw.attributes;
  const r = raw.relationships;
  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    anno: parseYear(a.field_anno),
    capoverso: a.field_capoverso ?? null,
    videoUrl: extractVideoUrl(a.field_audio),
    accordi: plainText(a.field_canto_accordi) || null,
    visualizzazioni: a.field_visualizzazioni_totali ?? 0,
    autoriTesto: resolveAutoreRefs(r.field_autori_testo, included),
    autoriMusica: resolveAutoreRefs(r.field_autori_musica, included),
    periodi: resolveRefs(r.field_periodo, included),
  };
}

export function mapCantoDetail(raw: any, included: IncludedMap): CantoDetail {
  const a = raw.attributes;
  const r = raw.relationships;
  return {
    ...mapCantoCard(raw, included),
    testo: plainText(a.field_canto_testo),
    audio: null,
    fonte: textValue(a.field_fonte),
    informazioni: textValue(a.field_informazioni),
    altriTitoli: a.field_altri_titoli || null,
    lingue: resolveRefs(r.field_lingua, included),
    periodi: resolveRefs(r.field_periodo, included),
    tags: resolveRefs(r.field_tags, included),
    tematiche: resolveRefs(r.field_tematiche, included),
    dataCreazione: a.created ?? null,
    dataModifica: a.changed ?? null,
  };
}

export function mapCantoInAutore(raw: any): CantoInAutore {
  const a = raw.attributes;
  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    anno: parseYear(a.field_anno),
    capoverso: a.field_capoverso ?? null,
    videoUrl: extractVideoUrl(a.field_audio),
    accordi: plainText(a.field_canto_accordi) || null,
    visualizzazioni: a.field_visualizzazioni_totali ?? 0,
  };
}

export function mapCantoCollegato(raw: any): CantoCollegato {
  const a = raw.attributes;
  return {
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    anno: parseYear(a.field_anno),
    capoverso: a.field_capoverso ?? null,
    videoUrl: extractVideoUrl(a.field_audio),
    accordi: plainText(a.field_canto_accordi) || null,
  };
}

// ── Autori ─────────────────────────────────────────────

export function mapAutoreCard(raw: any, included: IncludedMap): AutoreCard {
  const a = raw.attributes;
  const r = raw.relationships;
  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    immagine: resolveImageUrl(r.field_immagine, included),
    visualizzazioni: a.field_visualizzazioni_totali ?? 0,
    localizzazioni: resolveRefs(r.field_localizzazione, included),
    annoNascita: a.field_anno_di_nascita ?? null,
    annoMorte: a.field_anno_di_morte ?? null,
  };
}

export function mapAutoreDetail(raw: any, included: IncludedMap): AutoreDetail {
  const a = raw.attributes;
  const r = raw.relationships;
  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    nome: a.field_nome || null,
    cognome: a.field_cognome || null,
    informazioni: textValue(a.field_informazioni),
    immagine: resolveImageUrl(r.field_immagine, included),
    visualizzazioni: a.field_visualizzazioni_totali ?? 0,
    localizzazioni: resolveRefs(r.field_localizzazione, included),
    periodi: resolveRefs(r.field_periodo, included),
    annoNascita: a.field_anno_di_nascita ?? null,
    annoMorte: a.field_anno_di_morte ?? null,
    links: mapLinks(a.field_links),
    dataCreazione: a.created ?? null,
    dataModifica: a.changed ?? null,
  };
}

// ── Eventi ─────────────────────────────────────────────

export function mapEventoForCanto(raw: any): EventoForCanto {
  const a = raw.attributes;
  return {
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    dataEvento: a.field_data_evento,
  };
}

export function mapEventoDelGiorno(raw: any): EventoDelGiorno {
  const a = raw.attributes;
  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    dataEvento: a.field_data_evento,
  };
}

export function mapEventoMese(raw: any, included: IncludedMap): EventoMese {
  const a = raw.attributes;
  const r = raw.relationships;
  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    dataEvento: a.field_data_evento,
    immagine: resolveImageUrl(r.field_immagine, included),
    localizzazioni: resolveRefs(r.field_localizzazione, included),
  };
}

export function mapEventoCard(raw: any, included: IncludedMap): EventoCard {
  const a = raw.attributes;
  const r = raw.relationships;
  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    dataEvento: a.field_data_evento ?? null,
    immagine: resolveImageUrl(r.field_immagine, included),
    visualizzazioni: a.field_visualizzazioni_totali ?? 0,
    localizzazioni: resolveRefs(r.field_localizzazione, included),
    periodi: resolveRefs(r.field_periodo, included),
  };
}

export function mapEventoCalendario(raw: any, included: IncludedMap): EventoCalendario {
  const a = raw.attributes;
  const r = raw.relationships;
  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    dataEvento: a.field_data_evento,
    localizzazioni: resolveRefs(r.field_localizzazione, included),
    periodi: resolveRefs(r.field_periodo, included),
  };
}

export function mapEventoGeo(raw: any): EventoGeo {
  const a = raw.attributes;
  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    dataEvento: a.field_data_evento,
    latitude: a.field_geofield?.lat,
    longitude: a.field_geofield?.lon,
  };
}

export function mapEventoDetail(raw: any, included: IncludedMap): EventoDetail {
  const a = raw.attributes;
  const r = raw.relationships;

  const cantiRaw = resolveMany(r.field_canti_correlati, included)
    .filter((c: any) => c.attributes.status === true)
    .sort((a: any, b: any) => a.attributes.title.localeCompare(b.attributes.title, 'it'));

  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    dataEvento: a.field_data_evento ?? null,
    informazioni: textValue(a.field_informazioni),
    immagine: resolveImageUrl(r.field_immagine, included),
    visualizzazioni: a.field_visualizzazioni_totali ?? 0,
    latitude: a.field_geofield?.lat ?? null,
    longitude: a.field_geofield?.lon ?? null,
    localizzazioni: resolveRefs(r.field_localizzazione, included),
    periodi: resolveRefs(r.field_periodo, included),
    tags: resolveRefs(r.field_tags, included),
    tematiche: resolveRefs(r.field_tematiche, included),
    cantiCollegati: cantiRaw.map(mapCantoCollegato),
    links: mapLinks(a.field_links),
    dataCreazione: a.created ?? null,
    dataModifica: a.changed ?? null,
  };
}

// ── Traduzioni ─────────────────────────────────────────

export function mapTraduzioneDetail(raw: any, included: IncludedMap): TraduzioneDetail {
  const a = raw.attributes;
  const r = raw.relationships;

  const cantoRaw = resolveMany(r.field_canti_correlati, included)[0] ?? null;
  let cantoOriginale: TraduzioneDetail['cantoOriginale'] = null;

  if (cantoRaw) {
    const cantoIncluded = buildIncludedMapForCanto(cantoRaw, included);
    cantoOriginale = {
      titolo: cantoRaw.attributes.title,
      slug: extractSlug(cantoRaw.attributes.path?.alias),
      lingue: resolveRefs(cantoRaw.relationships?.field_lingua, cantoIncluded),
    };
  }

  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    testo: plainText(a.field_canto_testo),
    informazioni: textValue(a.field_informazioni),
    visualizzazioni: a.field_visualizzazioni_totali ?? 0,
    lingue: resolveRefs(r.field_lingua, included),
    cantoOriginale,
  };
}

function buildIncludedMapForCanto(_cantoRaw: any, parentIncluded: IncludedMap): IncludedMap {
  return parentIncluded;
}

// ── Paragraphs ─────────────────────────────────────────

function normalizeLinkUrl(uri: string | null | undefined): string | null {
  if (!uri) return null;
  return uri.startsWith('internal:') ? uri.slice('internal:'.length) : uri;
}

export function mapParagraph(
  raw: any,
  included: IncludedMap,
  // UUID→imageUrl per paragraph--immagine dentro griglie (fetched separatamente)
  immagineUuidMap?: Map<string, string>,
): ParagraphItem | null {
  const a = raw.attributes ?? {};
  const r = raw.relationships ?? {};

  switch (raw.type) {
    case 'paragraph--testo':
      return { type: 'testo', testo: textValue(a.field_testo) ?? '' };

    case 'paragraph--citazione':
      return {
        type: 'citazione',
        testo: textValue(a.field_testo) ?? '',
        fonte: textValue(a.field_fonte),
      };

    case 'paragraph--immagine': {
      const imageUrl =
        resolveImageUrl(r.field_immagine, included)
        ?? immagineUuidMap?.get(raw.id)
        ?? null;
      return {
        type: 'immagine',
        imageUrl,
        descrizione: a.field_descrizione_immagine ?? null,
      };
    }

    case 'paragraph--card':
      return {
        type: 'card',
        titolo: a.field_titolo ?? null,
        testo: textValue(a.field_testo),
        linkUrl: normalizeLinkUrl(a.field_link?.uri),
        linkTesto: a.field_link?.title ?? null,
      };

    case 'paragraph--griglia': {
      const gridRefs = Array.isArray(r.field_grid_item?.data) ? r.field_grid_item.data : [];
      const items = gridRefs
        .map((ref: any) => included.get(ref.type, ref.id))
        .filter(Boolean)
        .map((item: any) => mapParagraph(item, included, immagineUuidMap))
        .filter(Boolean) as ParagraphItem[];
      return {
        type: 'griglia',
        colonne: a.field_colonne ?? 'due_50_50',
        items,
      };
    }

    default:
      return null;
  }
}
