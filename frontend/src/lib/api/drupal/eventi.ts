import { fetchAllEventiRaw } from './store.js';
import { fetchNodeByUuid } from './client.js';
import { buildIncludedMap, extractSlug } from './resolvers.js';
import { oggiRoma } from '../../date-utils.js';
import {
  mapEventoForCanto, mapEventoDelGiorno, mapEventoMese,
  mapEventoCard, mapEventoCalendario, mapEventoGeo, mapEventoDetail,
} from './mappers.js';
import type {
  EventoPath, EventoForCanto, EventoDelGiorno, EventoMese,
  EventoCard, EventoCalendario, EventoGeo, EventoDetail,
} from '../types.js';

// Stessi fields/include della collezione (store.ts), applicati a una singola
// risorsa per uuid con auth, senza filter[status] (nodo non pubblicato incluso).
const EVENTO_PREVIEW_PARAMS = new URLSearchParams({
  'fields[node--evento]': [
    'drupal_internal__nid', 'title', 'path', 'created', 'changed',
    'field_data_evento', 'field_informazioni', 'field_immagine',
    'field_geofield', 'field_localizzazione', 'field_periodo',
    'field_tags', 'field_tematiche', 'field_canti_correlati', 'field_links',
    'field_visualizzazioni_totali',
  ].join(','),
  'fields[node--canto]': 'drupal_internal__nid,title,path,field_anno,field_capoverso,field_audio,field_canto_accordi,status',
  'fields[taxonomy_term--localizzazioni]': 'name,path',
  'fields[taxonomy_term--periodi]': 'name,path',
  'fields[taxonomy_term--tags]': 'name,path',
  'fields[taxonomy_term--tematiche]': 'name,path',
  'fields[media--image]': 'field_media_image',
  'fields[file--file]': 'uri',
  'include': 'field_immagine,field_immagine.field_media_image,field_localizzazione,field_periodo,field_tags,field_tematiche,field_canti_correlati',
});

export async function getEventoPreview(uuid: string): Promise<EventoDetail | null> {
  const res = await fetchNodeByUuid('evento', uuid, EVENTO_PREVIEW_PARAMS);
  if (!res?.data) return null;
  const map = buildIncludedMap(res.included);
  return mapEventoDetail(res.data, map);
}

export async function getEventi(): Promise<EventoPath[]> {
  const { data } = await fetchAllEventiRaw();
  return data.map((item: any) => ({
    id: item.attributes.drupal_internal__nid,
    slug: extractSlug(item.attributes.path?.alias),
  }));
}

export async function getEventiForCanto(cantoId: number | string): Promise<EventoForCanto[]> {
  const { data, included } = await fetchAllEventiRaw();
  const includedMap = buildIncludedMap(included);
  const nid = Number(cantoId);
  return data
    .filter((e: any) =>
      (e.relationships.field_canti_correlati?.data ?? []).some(
        (ref: any) => ref.meta?.drupal_internal__target_id === nid
      )
    )
    .map((evento: any) => mapEventoForCanto(evento, includedMap));
}

export async function getEventiForCantoMap(): Promise<Map<number | string, EventoForCanto[]>> {
  const { data, included } = await fetchAllEventiRaw();
  const includedMap = buildIncludedMap(included);
  const result = new Map<number | string, EventoForCanto[]>();

  for (const evento of data) {
    const mapped = mapEventoForCanto(evento, includedMap);
    for (const ref of (evento.relationships.field_canti_correlati?.data ?? [])) {
      const cantoId = ref.meta?.drupal_internal__target_id;
      if (cantoId == null) continue;
      const list = result.get(cantoId) ?? [];
      list.push(mapped);
      result.set(cantoId, list);
    }
  }

  return result;
}

export async function getEventiDelMese(month: number): Promise<EventoMese[]> {
  const { data, included } = await fetchAllEventiRaw();
  const map = buildIncludedMap(included);

  return data
    .filter((e: any) => {
      const d = e.attributes.field_data_evento;
      return d && new Date(d).getUTCMonth() + 1 === month;
    })
    .sort((a: any, b: any) => {
      const da = new Date(a.attributes.field_data_evento);
      const db = new Date(b.attributes.field_data_evento);
      const dayDiff = da.getUTCDate() - db.getUTCDate();
      return dayDiff !== 0 ? dayDiff : da.getUTCFullYear() - db.getUTCFullYear();
    })
    .map((item: any) => mapEventoMese(item, map));
}

export async function getEventiDelGiorno(): Promise<EventoDelGiorno[]> {
  const { data } = await fetchAllEventiRaw();
  const { day, month } = oggiRoma();

  return data
    .filter((e: any) => {
      const d = e.attributes.field_data_evento;
      if (!d) return false;
      const date = new Date(d);
      return date.getUTCDate() === day && date.getUTCMonth() + 1 === month;
    })
    .sort((a: any, b: any) =>
      new Date(a.attributes.field_data_evento).getUTCFullYear() - new Date(b.attributes.field_data_evento).getUTCFullYear()
    )
    .map(mapEventoDelGiorno);
}

export async function getEventiPiuVisti(limit = 10): Promise<EventoCard[]> {
  const { data, included } = await fetchAllEventiRaw();
  const map = buildIncludedMap(included);
  return [...data]
    .sort((a: any, b: any) => (b.attributes.field_visualizzazioni_totali ?? 0) - (a.attributes.field_visualizzazioni_totali ?? 0))
    .slice(0, limit)
    .map((item: any) => mapEventoCard(item, map));
}

export async function getEventiByPeriodo(periodoId: number | string, limit = 5): Promise<EventoCard[]> {
  const { data, included } = await fetchAllEventiRaw();
  const map = buildIncludedMap(included);
  const tid = Number(periodoId);
  return [...data]
    .filter((item: any) =>
      (item.relationships.field_periodo?.data ?? []).some(
        (ref: any) => ref.meta?.drupal_internal__target_id === tid
      )
    )
    .sort((a: any, b: any) => (b.attributes.field_visualizzazioni_totali ?? 0) - (a.attributes.field_visualizzazioni_totali ?? 0))
    .slice(0, limit)
    .map((item: any) => mapEventoCard(item, map));
}

export async function getEventiCalendario(): Promise<EventoCalendario[]> {
  const { data, included } = await fetchAllEventiRaw();
  const map = buildIncludedMap(included);
  return data
    .filter((e: any) => e.attributes.field_data_evento != null)
    .map((item: any) => mapEventoCalendario(item, map));
}

export async function getEventiGeo(): Promise<EventoGeo[]> {
  const { data } = await fetchAllEventiRaw();
  return data
    .filter((e: any) => e.attributes.field_geofield?.lat != null)
    .map(mapEventoGeo);
}

let eventiSlugMapPromise: Promise<Map<string, any>> | null = null;

function getEventiSlugMap(): Promise<Map<string, any>> {
  if (!eventiSlugMapPromise) {
    eventiSlugMapPromise = fetchAllEventiRaw().then(({ data }) => {
      const map = new Map<string, any>();
      for (const item of data) {
        map.set(extractSlug(item.attributes.path?.alias), item);
      }
      return map;
    });
  }
  return eventiSlugMapPromise;
}

export async function getEvento(slug: string): Promise<EventoDetail | null> {
  const [slugMap, { included }] = await Promise.all([
    getEventiSlugMap(),
    fetchAllEventiRaw(),
  ]);
  const item = slugMap.get(slug);
  if (!item) return null;
  const map = buildIncludedMap(included);
  return mapEventoDetail(item, map);
}

export async function getAllEventiDetail(): Promise<EventoDetail[]> {
  const { data, included } = await fetchAllEventiRaw();
  const map = buildIncludedMap(included);
  return data.map((item: any) => mapEventoDetail(item, map));
}
