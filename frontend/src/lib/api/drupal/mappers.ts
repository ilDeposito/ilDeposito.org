import type {
  Ref,
  CantoRecente, CantoCard, CantoDetail, CantoInAutore, CantoCollegato,
  AutoreCard, AutoreDetail,
  EventoForCanto, EventoDelGiorno, EventoMese, EventoCard, EventoCalendario, EventoGeo, EventoDetail,
  TraduzioneDetail,
} from '../types.js';
import { type IncludedMap, resolveRefs, resolveMany, resolveImageUrl, extractSlug } from './resolvers.js';

// ── Helpers ────────────────────────────────────────────

function parseYear(raw: string | null | undefined): number | null {
  if (!raw) return null;
  return new Date(raw).getUTCFullYear();
}

function extractVideoUrl(fieldAudio: any[] | null | undefined): string | null {
  if (!fieldAudio?.length) return null;
  return fieldAudio[0].uri?.trim() || null;
}

function textValue(field: any): string | null {
  if (!field) return null;
  if (typeof field === 'string') return field;
  return field.processed ?? field.value ?? null;
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
    accordi: a.field_canto_accordi ?? null,
    visualizzazioni: a.field_visualizzazioni ?? 0,
    autoriTesto: resolveRefs(r.field_autori_testo, included),
    autoriMusica: resolveRefs(r.field_autori_musica, included),
  };
}

export function mapCantoDetail(raw: any, included: IncludedMap): CantoDetail {
  const a = raw.attributes;
  const r = raw.relationships;
  return {
    ...mapCantoCard(raw, included),
    testo: a.field_canto_testo ?? '',
    audio: null,
    fonte: textValue(a.field_fonte),
    informazioni: textValue(a.field_informazioni),
    lingue: resolveRefs(r.field_lingua, included),
    periodi: resolveRefs(r.field_periodo, included),
    tags: resolveRefs(r.field_tags, included),
    tematiche: resolveRefs(r.field_tematiche, included),
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
    accordi: a.field_canto_accordi ?? null,
    visualizzazioni: a.field_visualizzazioni ?? 0,
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
    accordi: a.field_canto_accordi ?? null,
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
    visualizzazioni: a.field_visualizzazioni ?? 0,
    localizzazioni: resolveRefs(r.field_localizzazione, included),
  };
}

export function mapAutoreDetail(raw: any, included: IncludedMap): AutoreDetail {
  const a = raw.attributes;
  const r = raw.relationships;
  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    slug: extractSlug(a.path?.alias),
    informazioni: textValue(a.field_informazioni),
    immagine: resolveImageUrl(r.field_immagine, included),
    localizzazioni: resolveRefs(r.field_localizzazione, included),
    periodi: resolveRefs(r.field_periodo, included),
    annoNascita: a.field_anno_di_nascita ?? null,
    annoMorte: a.field_anno_di_morte ?? null,
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
    visualizzazioni: a.field_visualizzazioni ?? 0,
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
    latitude: a.field_geofield?.lat ?? null,
    longitude: a.field_geofield?.lon ?? null,
    localizzazioni: resolveRefs(r.field_localizzazione, included),
    periodi: resolveRefs(r.field_periodo, included),
    tags: resolveRefs(r.field_tags, included),
    tematiche: resolveRefs(r.field_tematiche, included),
    cantiCollegati: cantiRaw.map(mapCantoCollegato),
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
    testo: a.field_canto_testo ?? '',
    informazioni: textValue(a.field_informazioni),
    lingue: resolveRefs(r.field_lingua, included),
    cantoOriginale,
  };
}

function buildIncludedMapForCanto(_cantoRaw: any, parentIncluded: IncludedMap): IncludedMap {
  return parentIncluded;
}
