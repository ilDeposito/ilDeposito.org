import { fetchJsonApi, fetchAllJsonApi } from './client.js';
import { buildIncludedMap, extractSlug } from './resolvers.js';
import {
  mapEventoForCanto, mapEventoDelGiorno, mapEventoMese,
  mapEventoCard, mapEventoCalendario, mapEventoGeo, mapEventoDetail,
} from './mappers.js';
import type {
  EventoPath, EventoForCanto, EventoDelGiorno, EventoMese,
  EventoCard, EventoCalendario, EventoGeo, EventoDetail,
} from '../types.js';

let slugToUuidCache: Map<string, string> | null = null;

async function resolveEventoUuid(slug: string): Promise<string | null> {
  if (!slugToUuidCache) {
    const { data } = await fetchAllJsonApi('/jsonapi/node/evento', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--evento]': 'path',
      'page[limit]': '50',
    }));
    slugToUuidCache = new Map();
    for (const item of data) {
      slugToUuidCache.set(extractSlug(item.attributes.path?.alias), item.id);
    }
  }
  return slugToUuidCache.get(slug) ?? null;
}

export async function getEventi(): Promise<EventoPath[]> {
  const { data } = await fetchAllJsonApi('/jsonapi/node/evento', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--evento]': 'path',
    'page[limit]': '50',
  }));

  if (!slugToUuidCache) {
    slugToUuidCache = new Map();
    for (const item of data) {
      slugToUuidCache.set(extractSlug(item.attributes.path?.alias), item.id);
    }
  }

  return data.map((item: any) => ({
    id: item.attributes.drupal_internal__nid,
    slug: extractSlug(item.attributes.path?.alias),
  }));
}

export async function getEventiForCanto(cantoId: number | string): Promise<EventoForCanto[]> {
  const { data } = await fetchAllJsonApi('/jsonapi/node/evento', new URLSearchParams({
    'filter[status]': '1',
    'filter[field_canti_correlati.drupal_internal__nid]': String(cantoId),
    'fields[node--evento]': 'drupal_internal__nid,title,path,field_data_evento',
    'page[limit]': '50',
  }));

  return data.map(mapEventoForCanto);
}

export async function getEventiDelMese(month: number): Promise<EventoMese[]> {
  const { data, included } = await fetchAllJsonApi('/jsonapi/node/evento', new URLSearchParams({
    'filter[status]': '1',
    'filter[field_data_evento][condition][operator]': 'IS NOT NULL',
    'filter[field_data_evento][condition][path]': 'field_data_evento',
    'fields[node--evento]': 'drupal_internal__nid,title,path,field_data_evento,field_immagine,field_localizzazione',
    'fields[taxonomy_term--localizzazioni]': 'name,path',
    'fields[media--image]': 'field_media_image',
    'fields[file--file]': 'uri',
    'include': 'field_localizzazione,field_immagine,field_immagine.field_media_image',
    'page[limit]': '50',
  }));

  const map = buildIncludedMap(included);

  return data
    .filter((e: any) => new Date(e.attributes.field_data_evento).getUTCMonth() + 1 === month)
    .sort((a: any, b: any) => {
      const da = new Date(a.attributes.field_data_evento);
      const db = new Date(b.attributes.field_data_evento);
      const dayDiff = da.getUTCDate() - db.getUTCDate();
      return dayDiff !== 0 ? dayDiff : da.getUTCFullYear() - db.getUTCFullYear();
    })
    .map((item: any) => mapEventoMese(item, map));
}

export async function getEventiDelGiorno(): Promise<EventoDelGiorno[]> {
  const oggi = new Date();
  const day = oggi.getUTCDate();
  const month = oggi.getUTCMonth() + 1;

  const { data } = await fetchAllJsonApi('/jsonapi/node/evento', new URLSearchParams({
    'filter[status]': '1',
    'filter[field_data_evento][condition][operator]': 'IS NOT NULL',
    'filter[field_data_evento][condition][path]': 'field_data_evento',
    'fields[node--evento]': 'drupal_internal__nid,title,path,field_data_evento',
    'page[limit]': '50',
  }));

  return data
    .filter((e: any) => {
      const d = new Date(e.attributes.field_data_evento);
      return d.getUTCDate() === day && d.getUTCMonth() + 1 === month;
    })
    .sort((a: any, b: any) =>
      new Date(a.attributes.field_data_evento).getUTCFullYear() - new Date(b.attributes.field_data_evento).getUTCFullYear()
    )
    .map(mapEventoDelGiorno);
}

export async function getEventiPiuVisti(limit = 10): Promise<EventoCard[]> {
  const { data, included } = await fetchJsonApi('/jsonapi/node/evento', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--evento]': 'drupal_internal__nid,title,path,field_data_evento,field_localizzazione,field_periodo,field_visualizzazioni',
    'fields[taxonomy_term--localizzazioni]': 'name,path',
    'fields[taxonomy_term--periodi]': 'name,path',
    'include': 'field_localizzazione,field_periodo',
    'sort': '-field_visualizzazioni',
    'page[limit]': String(Math.min(limit, 50)),
  }));

  const map = buildIncludedMap(included);
  return data.map((item: any) => mapEventoCard(item, map));
}

export async function getEventiCalendario(): Promise<EventoCalendario[]> {
  const { data, included } = await fetchAllJsonApi('/jsonapi/node/evento', new URLSearchParams({
    'filter[status]': '1',
    'filter[field_data_evento][condition][operator]': 'IS NOT NULL',
    'filter[field_data_evento][condition][path]': 'field_data_evento',
    'fields[node--evento]': 'drupal_internal__nid,title,path,field_data_evento,field_localizzazione,field_periodo',
    'fields[taxonomy_term--localizzazioni]': 'name,path',
    'fields[taxonomy_term--periodi]': 'name,path',
    'include': 'field_localizzazione,field_periodo',
    'page[limit]': '50',
  }));

  const map = buildIncludedMap(included);
  return data.map((item: any) => mapEventoCalendario(item, map));
}

export async function getEventiGeo(): Promise<EventoGeo[]> {
  const { data } = await fetchAllJsonApi('/jsonapi/node/evento', new URLSearchParams({
    'filter[status]': '1',
    'filter[geo][condition][path]': 'field_geofield.lat',
    'filter[geo][condition][operator]': 'IS NOT NULL',
    'fields[node--evento]': 'drupal_internal__nid,title,path,field_data_evento,field_geofield',
    'page[limit]': '50',
  }));

  return data.map(mapEventoGeo);
}

export async function getEvento(slug: string): Promise<EventoDetail | null> {
  const uuid = await resolveEventoUuid(slug);
  if (!uuid) return null;

  const response = await fetchJsonApi(`/jsonapi/node/evento/${uuid}`, new URLSearchParams({
    'fields[node--evento]': 'drupal_internal__nid,title,path,field_data_evento,field_informazioni,field_geofield,field_localizzazione,field_periodo,field_tags,field_tematiche,field_canti_correlati',
    'fields[node--canto]': 'drupal_internal__nid,title,path,field_anno,field_capoverso,field_audio,field_canto_accordi,status',
    'fields[taxonomy_term--localizzazioni]': 'name,path',
    'fields[taxonomy_term--periodi]': 'name,path',
    'fields[taxonomy_term--tags]': 'name,path',
    'fields[taxonomy_term--tematiche]': 'name,path',
    'include': 'field_localizzazione,field_periodo,field_tags,field_tematiche,field_canti_correlati',
  }));

  const item = Array.isArray(response.data) ? response.data[0] : response.data;
  if (!item) return null;

  const map = buildIncludedMap(response.included);
  return mapEventoDetail(item, map);
}
