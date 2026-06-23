import { fetchCollection } from './client.js';
import {
  mapEventoForCanto, mapEventoDelGiorno, mapEventoMese,
  mapEventoCard, mapEventoCalendario, mapEventoGeo, mapEventoDetail,
} from './mappers.js';
import type {
  EventoPath, EventoForCanto, EventoDelGiorno, EventoMese,
  EventoCard, EventoCalendario, EventoGeo, EventoDetail,
} from '../types.js';

export async function getEventi(): Promise<EventoPath[]> {
  return fetchCollection('eventi', {
    fields: 'id,slug',
    'filter[status][_eq]': 'published',
    limit: '-1',
  });
}

export async function getEventiForCanto(cantoId: number | string): Promise<EventoForCanto[]> {
  const items = await fetchCollection('eventi_canti', {
    fields: 'eventi_id.titolo,eventi_id.slug,eventi_id.data_evento,eventi_id.status',
    'filter[canti_id][_eq]': String(cantoId),
    limit: '-1',
  });
  return items
    .map((j: any) => j.eventi_id)
    .filter((e: any) => e?.status === 'published')
    .map(mapEventoForCanto);
}

export async function getEventiDelMese(month: number): Promise<EventoMese[]> {
  const tutti = await fetchCollection('eventi', {
    fields: [
      'id', 'titolo', 'slug', 'data_evento', 'immagine',
      'localizzazioni.localizzazioni_id.titolo',
      'localizzazioni.localizzazioni_id.slug',
    ].join(','),
    'filter[status][_eq]': 'published',
    'filter[data_evento][_nnull]': 'true',
    limit: '-1',
  });

  return tutti
    .filter((e: any) => new Date(e.data_evento).getUTCMonth() + 1 === month)
    .sort((a: any, b: any) => {
      const da = new Date(a.data_evento);
      const db = new Date(b.data_evento);
      const dayDiff = da.getUTCDate() - db.getUTCDate();
      return dayDiff !== 0 ? dayDiff : da.getUTCFullYear() - db.getUTCFullYear();
    })
    .map(mapEventoMese);
}

export async function getEventiDelGiorno(): Promise<EventoDelGiorno[]> {
  const oggi = new Date();
  const day = oggi.getUTCDate();
  const month = oggi.getUTCMonth() + 1;

  const tutti = await fetchCollection('eventi', {
    fields: 'id,titolo,slug,data_evento',
    'filter[status][_eq]': 'published',
    'filter[data_evento][_nnull]': 'true',
    limit: '-1',
  });

  return tutti
    .filter((e: any) => {
      const d = new Date(e.data_evento);
      return d.getUTCDate() === day && d.getUTCMonth() + 1 === month;
    })
    .sort((a: any, b: any) =>
      new Date(a.data_evento).getUTCFullYear() - new Date(b.data_evento).getUTCFullYear()
    )
    .map(mapEventoDelGiorno);
}

export async function getEventiPiuVisti(limit = 10): Promise<EventoCard[]> {
  const items = await fetchCollection('eventi', {
    fields: [
      'id', 'titolo', 'slug', 'data_evento', 'visualizzazioni',
      'localizzazioni.localizzazioni_id.titolo', 'localizzazioni.localizzazioni_id.slug',
      'periodi.periodi_id.titolo', 'periodi.periodi_id.slug',
    ].join(','),
    'filter[status][_eq]': 'published',
    sort: '-visualizzazioni',
    limit: String(limit),
  });
  return items.map(mapEventoCard);
}

export async function getEventiCalendario(): Promise<EventoCalendario[]> {
  const items = await fetchCollection('eventi', {
    fields: [
      'id', 'titolo', 'slug', 'data_evento',
      'localizzazioni.localizzazioni_id.titolo', 'localizzazioni.localizzazioni_id.slug',
      'periodi.periodi_id.titolo', 'periodi.periodi_id.slug',
    ].join(','),
    'filter[status][_eq]': 'published',
    'filter[data_evento][_nnull]': 'true',
    limit: '-1',
  });
  return items.map(mapEventoCalendario);
}

export async function getEventiGeo(): Promise<EventoGeo[]> {
  const items = await fetchCollection('eventi', {
    fields: 'id,titolo,slug,data_evento,latitude,longitude',
    'filter[status][_eq]': 'published',
    'filter[latitude][_nnull]': 'true',
    'filter[longitude][_nnull]': 'true',
    limit: '-1',
  });
  return items.map(mapEventoGeo);
}

export async function getEvento(slug: string): Promise<EventoDetail | null> {
  const items = await fetchCollection('eventi', {
    fields: [
      'id', 'titolo', 'slug', 'data_evento', 'informazioni',
      'latitude', 'longitude',
      'localizzazioni.localizzazioni_id.titolo', 'localizzazioni.localizzazioni_id.slug',
      'periodi.periodi_id.titolo', 'periodi.periodi_id.slug',
      'tags.tags_id.titolo', 'tags.tags_id.slug',
      'tematiche.tematiche_id.titolo', 'tematiche.tematiche_id.slug',
      'canti_collegati.canti_id.titolo', 'canti_collegati.canti_id.slug',
      'canti_collegati.canti_id.anno', 'canti_collegati.canti_id.capoverso',
      'canti_collegati.canti_id.video_url', 'canti_collegati.canti_id.accordi',
      'canti_collegati.canti_id.status',
    ].join(','),
    'filter[slug][_eq]': slug,
    'filter[status][_eq]': 'published',
    limit: '1',
  });
  return items[0] ? mapEventoDetail(items[0]) : null;
}
