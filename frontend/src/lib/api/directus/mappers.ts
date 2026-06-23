import type {
  Ref,
  CantoRecente, CantoCard, CantoDetail, CantoInAutore, CantoCollegato,
  AutoreCard, AutoreDetail,
  EventoForCanto, EventoDelGiorno, EventoMese, EventoCard, EventoCalendario, EventoGeo, EventoDetail,
  TraduzioneDetail,
} from '../types.js';

// ── Helpers ────────────────────────────────────────────

function unwrapM2M(junctions: any[] | undefined, key: string): Ref[] {
  return (junctions ?? []).map((j: any) => j[key]).filter(Boolean);
}

function parseYear(raw: string | null | undefined): number | null {
  if (!raw) return null;
  return new Date(raw).getUTCFullYear();
}

// ── Canti ──────────────────────────────────────────────

export function mapCantoRecente(raw: any): CantoRecente {
  return {
    id: raw.id,
    titolo: raw.titolo,
    slug: raw.slug,
    capoverso: raw.capoverso ?? null,
  };
}

export function mapCantoCard(raw: any): CantoCard {
  return {
    id: raw.id,
    titolo: raw.titolo,
    slug: raw.slug,
    anno: parseYear(raw.anno),
    capoverso: raw.capoverso ?? null,
    videoUrl: raw.video_url ?? null,
    accordi: raw.accordi ?? null,
    visualizzazioni: raw.visualizzazioni ?? 0,
    autoriTesto: unwrapM2M(raw.autori_testo, 'autori_id'),
    autoriMusica: unwrapM2M(raw.autori_musica, 'autori_id'),
  };
}

export function mapCantoDetail(raw: any): CantoDetail {
  return {
    ...mapCantoCard(raw),
    testo: raw.testo ?? '',
    audio: raw.audio ?? null,
    fonte: raw.fonte ?? null,
    informazioni: raw.informazioni ?? null,
    lingue: unwrapM2M(raw.lingue, 'lingue_id'),
    periodi: unwrapM2M(raw.periodi, 'periodi_id'),
    tags: unwrapM2M(raw.tags, 'tags_id'),
    tematiche: unwrapM2M(raw.tematiche, 'tematiche_id'),
  };
}

export function mapCantoInAutore(raw: any): CantoInAutore {
  return {
    id: raw.id,
    titolo: raw.titolo,
    slug: raw.slug,
    anno: parseYear(raw.anno),
    capoverso: raw.capoverso ?? null,
    videoUrl: raw.video_url ?? null,
    accordi: raw.accordi ?? null,
    visualizzazioni: raw.visualizzazioni ?? 0,
  };
}

export function mapCantoCollegato(raw: any): CantoCollegato {
  return {
    titolo: raw.titolo,
    slug: raw.slug,
    anno: parseYear(raw.anno),
    capoverso: raw.capoverso ?? null,
    videoUrl: raw.video_url ?? null,
    accordi: raw.accordi ?? null,
  };
}

// ── Autori ─────────────────────────────────────────────

export function mapAutoreCard(raw: any): AutoreCard {
  return {
    id: raw.id,
    titolo: raw.titolo,
    slug: raw.slug,
    immagine: raw.immagine ?? null,
    visualizzazioni: raw.visualizzazioni ?? 0,
    localizzazioni: unwrapM2M(raw.localizzazioni, 'localizzazioni_id'),
  };
}

export function mapAutoreDetail(raw: any): AutoreDetail {
  return {
    id: raw.id,
    titolo: raw.titolo,
    slug: raw.slug,
    informazioni: raw.informazioni ?? null,
    immagine: raw.immagine ?? null,
    localizzazioni: unwrapM2M(raw.localizzazioni, 'localizzazioni_id'),
    periodi: unwrapM2M(raw.periodi, 'periodi_id'),
    annoNascita: raw.anno_nascita ?? null,
    annoMorte: raw.anno_morte ?? null,
  };
}

// ── Eventi ─────────────────────────────────────────────

export function mapEventoForCanto(raw: any): EventoForCanto {
  return {
    titolo: raw.titolo,
    slug: raw.slug,
    dataEvento: raw.data_evento,
  };
}

export function mapEventoDelGiorno(raw: any): EventoDelGiorno {
  return {
    id: raw.id,
    titolo: raw.titolo,
    slug: raw.slug,
    dataEvento: raw.data_evento,
  };
}

export function mapEventoMese(raw: any): EventoMese {
  return {
    id: raw.id,
    titolo: raw.titolo,
    slug: raw.slug,
    dataEvento: raw.data_evento,
    immagine: raw.immagine ?? null,
    localizzazioni: unwrapM2M(raw.localizzazioni, 'localizzazioni_id'),
  };
}

export function mapEventoCard(raw: any): EventoCard {
  return {
    id: raw.id,
    titolo: raw.titolo,
    slug: raw.slug,
    dataEvento: raw.data_evento ?? null,
    visualizzazioni: raw.visualizzazioni ?? 0,
    localizzazioni: unwrapM2M(raw.localizzazioni, 'localizzazioni_id'),
    periodi: unwrapM2M(raw.periodi, 'periodi_id'),
  };
}

export function mapEventoCalendario(raw: any): EventoCalendario {
  return {
    id: raw.id,
    titolo: raw.titolo,
    slug: raw.slug,
    dataEvento: raw.data_evento,
    localizzazioni: unwrapM2M(raw.localizzazioni, 'localizzazioni_id'),
    periodi: unwrapM2M(raw.periodi, 'periodi_id'),
  };
}

export function mapEventoGeo(raw: any): EventoGeo {
  return {
    id: raw.id,
    titolo: raw.titolo,
    slug: raw.slug,
    dataEvento: raw.data_evento,
    latitude: raw.latitude,
    longitude: raw.longitude,
  };
}

export function mapEventoDetail(raw: any): EventoDetail {
  const cantiRaw = (raw.canti_collegati ?? [])
    .map((j: any) => j.canti_id)
    .filter((c: any) => c?.status === 'published')
    .sort((a: any, b: any) => a.titolo.localeCompare(b.titolo, 'it'));

  return {
    id: raw.id,
    titolo: raw.titolo,
    slug: raw.slug,
    dataEvento: raw.data_evento ?? null,
    informazioni: raw.informazioni ?? null,
    latitude: raw.latitude ?? null,
    longitude: raw.longitude ?? null,
    localizzazioni: unwrapM2M(raw.localizzazioni, 'localizzazioni_id'),
    periodi: unwrapM2M(raw.periodi, 'periodi_id'),
    tags: unwrapM2M(raw.tags, 'tags_id'),
    tematiche: unwrapM2M(raw.tematiche, 'tematiche_id'),
    cantiCollegati: cantiRaw.map(mapCantoCollegato),
  };
}

// ── Traduzioni ─────────────────────────────────────────

export function mapTraduzioneDetail(raw: any): TraduzioneDetail {
  const cantoOrig = raw.canto_originale;
  return {
    id: raw.id,
    titolo: raw.titolo,
    slug: raw.slug,
    testo: raw.testo ?? '',
    informazioni: raw.informazioni ?? null,
    lingue: unwrapM2M(raw.lingue, 'lingue_id'),
    cantoOriginale: cantoOrig
      ? {
          titolo: cantoOrig.titolo,
          slug: cantoOrig.slug,
          lingue: unwrapM2M(cantoOrig.lingue, 'lingue_id'),
        }
      : null,
  };
}
