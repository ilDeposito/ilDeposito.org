import { fetchJsonApi, fetchAllJsonApi } from './client.js';
import { buildIncludedMap, extractSlug } from './resolvers.js';
import { mapAutoreCard, mapAutoreDetail, mapCantoInAutore } from './mappers.js';
import { resolveImageUrl } from './resolvers.js';
import type { AutorePath, AutoreCard, AutoreDetail, CantoInAutore } from '../types.js';

let slugToUuidCache: Map<string, string> | null = null;

async function resolveAutoreUuid(slug: string): Promise<string | null> {
  if (!slugToUuidCache) {
    const { data } = await fetchAllJsonApi('/jsonapi/node/autore', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--autore]': 'drupal_internal__nid,path',
      'page[limit]': '50',
    }));
    slugToUuidCache = new Map();
    for (const item of data) {
      slugToUuidCache.set(extractSlug(item.attributes.path?.alias), item.id);
    }
  }
  return slugToUuidCache.get(slug) ?? null;
}

export async function getAutori(): Promise<AutorePath[]> {
  const { data } = await fetchAllJsonApi('/jsonapi/node/autore', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--autore]': 'drupal_internal__nid,path',
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

export async function getAutoriPiuVisti(limit = 20): Promise<AutoreCard[]> {
  const { data, included } = await fetchJsonApi('/jsonapi/node/autore', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--autore]': 'drupal_internal__nid,title,path,field_immagine,field_localizzazione,field_visualizzazioni,field_anno_di_nascita,field_anno_di_morte',
    'fields[taxonomy_term--localizzazioni]': 'name,path',
    'fields[media--image]': 'field_media_image',
    'fields[file--file]': 'uri',
    'include': 'field_localizzazione,field_immagine,field_immagine.field_media_image',
    'sort': '-field_visualizzazioni',
    'page[limit]': String(Math.min(limit, 50)),
  }));

  const map = buildIncludedMap(included);
  return data.map((item: any) => mapAutoreCard(item, map));
}

export async function getAutore(slug: string): Promise<AutoreDetail | null> {
  const uuid = await resolveAutoreUuid(slug);
  if (!uuid) return null;

  const response = await fetchJsonApi(`/jsonapi/node/autore/${uuid}`, new URLSearchParams({
    'fields[node--autore]': 'drupal_internal__nid,title,path,field_nome,field_cognome,field_informazioni,field_immagine,field_localizzazione,field_periodo,field_anno_di_nascita,field_anno_di_morte,field_visualizzazioni',
    'fields[taxonomy_term--localizzazioni]': 'name,path',
    'fields[taxonomy_term--periodi]': 'name,path',
    'fields[media--image]': 'field_media_image',
    'fields[file--file]': 'uri',
    'include': 'field_localizzazione,field_periodo,field_immagine,field_immagine.field_media_image',
  }));

  const item = Array.isArray(response.data) ? response.data[0] : response.data;
  if (!item) return null;

  const map = buildIncludedMap(response.included);
  return mapAutoreDetail(item, map);
}

export async function getAutoriByPeriodo(periodoId: number | string, limit = 5): Promise<AutoreCard[]> {
  const { data, included } = await fetchJsonApi('/jsonapi/node/autore', new URLSearchParams({
    'filter[status]': '1',
    'filter[field_periodo.drupal_internal__tid]': String(periodoId),
    'fields[node--autore]': 'drupal_internal__nid,title,path,field_immagine,field_localizzazione,field_visualizzazioni,field_anno_di_nascita,field_anno_di_morte',
    'fields[taxonomy_term--localizzazioni]': 'name,path',
    'fields[media--image]': 'field_media_image',
    'fields[file--file]': 'uri',
    'include': 'field_localizzazione,field_immagine,field_immagine.field_media_image',
    'sort': '-field_visualizzazioni',
    'page[limit]': String(Math.min(limit, 50)),
  }));

  const map = buildIncludedMap(included);
  return data.map((item: any) => mapAutoreCard(item, map));
}

export async function getAutoriImmaginiMap(): Promise<Map<string, string | null>> {
  const { data, included } = await fetchAllJsonApi('/jsonapi/node/autore', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--autore]': 'path,field_immagine',
    'fields[media--image]': 'field_media_image',
    'fields[file--file]': 'uri',
    'include': 'field_immagine,field_immagine.field_media_image',
    'page[limit]': '50',
  }));

  const map = buildIncludedMap(included);
  const result = new Map<string, string | null>();
  for (const item of data) {
    const slug = extractSlug(item.attributes.path?.alias);
    result.set(slug, resolveImageUrl(item.relationships.field_immagine, map));
  }
  return result;
}

let cantiByAutoreCache: Map<number | string, CantoInAutore[]> | null = null;

export async function getCantiByAutoreMap(): Promise<Map<number | string, CantoInAutore[]>> {
  if (!cantiByAutoreCache) cantiByAutoreCache = await buildCantiByAutoreMap();
  return cantiByAutoreCache;
}

async function buildCantiByAutoreMap(): Promise<Map<number | string, CantoInAutore[]>> {
  const { data, included } = await fetchAllJsonApi('/jsonapi/node/canto', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--canto]': 'drupal_internal__nid,title,path,field_anno,field_capoverso,field_audio,field_canto_accordi,field_autori_testo,field_autori_musica,field_visualizzazioni',
    'fields[node--autore]': 'drupal_internal__nid,title,path',
    'include': 'field_autori_testo,field_autori_musica',
    'page[limit]': '50',
  }));

  const map = new Map<number | string, CantoInAutore[]>();
  const includedMap = buildIncludedMap(included);

  for (const canto of data) {
    const cantoMapped = mapCantoInAutore(canto);
    const rels = canto.relationships;

    const autoriIds = new Set<number>();

    for (const ref of (rels.field_autori_testo?.data ?? [])) {
      const autore = includedMap.get(ref.type, ref.id);
      if (autore) autoriIds.add(autore.attributes.drupal_internal__nid);
    }
    for (const ref of (rels.field_autori_musica?.data ?? [])) {
      const autore = includedMap.get(ref.type, ref.id);
      if (autore) autoriIds.add(autore.attributes.drupal_internal__nid);
    }

    for (const autoreId of autoriIds) {
      const canti = map.get(autoreId) ?? [];
      if (!canti.some((c) => c.id === cantoMapped.id)) canti.push(cantoMapped);
      map.set(autoreId, canti);
    }
  }

  return map;
}
