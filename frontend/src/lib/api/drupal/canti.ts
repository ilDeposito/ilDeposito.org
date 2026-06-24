import { fetchJsonApi, fetchAllJsonApi } from './client.js';
import { buildIncludedMap, extractSlug } from './resolvers.js';
import { mapCantoRecente, mapCantoCard, mapCantoDetail } from './mappers.js';
import type { CantoPath, CantoRecente, CantoCard, CantoDetail } from '../types.js';

const CANTO_CARD_FIELDS = [
  'drupal_internal__nid', 'title', 'path', 'field_anno', 'field_capoverso',
  'field_audio', 'field_canto_accordi', 'field_autori_testo', 'field_autori_musica',
  'field_visualizzazioni',
].join(',');

const CANTO_DETAIL_FIELDS = [
  'drupal_internal__nid', 'title', 'path', 'field_anno', 'field_capoverso',
  'field_canto_testo', 'field_canto_accordi', 'field_audio', 'field_fonte',
  'field_informazioni', 'field_autori_testo', 'field_autori_musica',
  'field_lingua', 'field_periodo', 'field_tags', 'field_tematiche',
].join(',');

const CANTO_CARD_INCLUDE = 'field_autori_testo,field_autori_musica';
const CANTO_DETAIL_INCLUDE = 'field_autori_testo,field_autori_musica,field_lingua,field_periodo,field_tags,field_tematiche';

let slugToUuidCache: Map<string, string> | null = null;

async function resolveCantoUuid(slug: string): Promise<string | null> {
  if (!slugToUuidCache) {
    const { data } = await fetchAllJsonApi('/jsonapi/node/canto', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--canto]': 'path',
      'page[limit]': '50',
    }));
    slugToUuidCache = new Map();
    for (const item of data) {
      slugToUuidCache.set(extractSlug(item.attributes.path?.alias), item.id);
    }
  }
  return slugToUuidCache.get(slug) ?? null;
}

export async function getCanti(): Promise<CantoPath[]> {
  const { data } = await fetchAllJsonApi('/jsonapi/node/canto', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--canto]': 'path',
    'page[limit]': '50',
  }));

  // Popola anche la cache UUID per evitare doppia fetch
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

export async function getCantiRecenti(limit = 50): Promise<CantoRecente[]> {
  const { data } = await fetchJsonApi('/jsonapi/node/canto', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--canto]': 'drupal_internal__nid,title,path,field_capoverso',
    'sort': '-created',
    'page[limit]': String(Math.min(limit, 50)),
  }));

  return data.map(mapCantoRecente);
}

export async function getCantiPiuVisti(limit = 10): Promise<CantoCard[]> {
  const { data, included } = await fetchJsonApi('/jsonapi/node/canto', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--canto]': CANTO_CARD_FIELDS,
    'fields[node--autore]': 'drupal_internal__nid,title,path',
    'include': CANTO_CARD_INCLUDE,
    'sort': '-field_visualizzazioni',
    'page[limit]': String(Math.min(limit, 50)),
  }));

  const map = buildIncludedMap(included);
  return data.map((item: any) => mapCantoCard(item, map));
}

export async function getCanto(slug: string): Promise<CantoDetail | null> {
  const uuid = await resolveCantoUuid(slug);
  if (!uuid) return null;

  const response = await fetchJsonApi(`/jsonapi/node/canto/${uuid}`, new URLSearchParams({
    'fields[node--canto]': CANTO_DETAIL_FIELDS,
    'fields[node--autore]': 'drupal_internal__nid,title,path',
    'fields[taxonomy_term--lingue]': 'name,path',
    'fields[taxonomy_term--periodi]': 'name,path',
    'fields[taxonomy_term--tags]': 'name,path',
    'fields[taxonomy_term--tematiche]': 'name,path',
    'include': CANTO_DETAIL_INCLUDE,
  }));

  const item = Array.isArray(response.data) ? response.data[0] : response.data;
  if (!item) return null;

  const map = buildIncludedMap(response.included);
  return mapCantoDetail(item, map);
}
