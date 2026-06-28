import { fetchAllJsonApi, fetchJsonApi } from './client.js';
import { buildIncludedMap, extractSlug } from './resolvers.js';
import { mapTraduzioneDetail } from './mappers.js';
import type { TraduzionePath, TraduzioneDetail } from '../types.js';

let slugToUuidCache: Map<string, string> | null = null;

async function resolveTraduzioneUuid(slug: string): Promise<string | null> {
  if (!slugToUuidCache) {
    const { data } = await fetchAllJsonApi('/jsonapi/node/traduzione', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--traduzione]': 'path',
      'page[limit]': '50',
    }));
    slugToUuidCache = new Map();
    for (const item of data) {
      slugToUuidCache.set(extractSlug(item.attributes.path?.alias), item.id);
    }
  }
  return slugToUuidCache.get(slug) ?? null;
}

export async function getTraduzioni(): Promise<TraduzionePath[]> {
  const { data } = await fetchAllJsonApi('/jsonapi/node/traduzione', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--traduzione]': 'path',
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

export async function getTraduzione(slug: string): Promise<TraduzioneDetail | null> {
  const uuid = await resolveTraduzioneUuid(slug);
  if (!uuid) return null;

  const response = await fetchJsonApi(`/jsonapi/node/traduzione/${uuid}`, new URLSearchParams({
    'fields[node--traduzione]': 'drupal_internal__nid,title,path,field_canto_testo,field_informazioni,field_lingua,field_canti_correlati,field_visualizzazioni',
    'fields[node--canto]': 'drupal_internal__nid,title,path,field_lingua',
    'fields[taxonomy_term--lingue]': 'name,path',
    'include': 'field_lingua,field_canti_correlati,field_canti_correlati.field_lingua',
  }));

  const item = Array.isArray(response.data) ? response.data[0] : response.data;
  if (!item) return null;

  const map = buildIncludedMap(response.included);
  return mapTraduzioneDetail(item, map);
}
