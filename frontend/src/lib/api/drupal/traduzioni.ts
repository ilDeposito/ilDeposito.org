import { fetchAllTraduzioniRaw } from './store.js';
import { fetchNodeByUuid } from './client.js';
import { buildIncludedMap, extractSlug } from './resolvers.js';
import { mapTraduzioneDetail } from './mappers.js';
import type { TraduzionePath, TraduzioneDetail } from '../types.js';

// Stessi fields/include della collezione (store.ts), applicati a una singola
// risorsa per uuid con auth, senza filter[status] (nodo non pubblicato incluso).
const TRADUZIONE_PREVIEW_PARAMS = new URLSearchParams({
  'fields[node--traduzione]': [
    'drupal_internal__nid', 'title', 'path', 'created', 'changed',
    'field_canto_testo', 'field_informazioni', 'field_lingua',
    'field_canti_correlati', 'field_visualizzazioni_totali',
  ].join(','),
  'fields[node--canto]': 'drupal_internal__nid,title,path,field_lingua',
  'fields[taxonomy_term--lingue]': 'name,path',
  'include': 'field_lingua,field_canti_correlati,field_canti_correlati.field_lingua',
});

export async function getTraduzionePreview(uuid: string): Promise<TraduzioneDetail | null> {
  const res = await fetchNodeByUuid('traduzione', uuid, TRADUZIONE_PREVIEW_PARAMS);
  if (!res?.data) return null;
  const map = buildIncludedMap(res.included);
  return mapTraduzioneDetail(res.data, map);
}

export async function getTraduzioni(): Promise<TraduzionePath[]> {
  const { data } = await fetchAllTraduzioniRaw();
  return data.map((item: any) => ({
    id: item.attributes.drupal_internal__nid,
    slug: extractSlug(item.attributes.path?.alias),
  }));
}

let traduzioniSlugMapPromise: Promise<Map<string, any>> | null = null;

function getTraduzioniSlugMap(): Promise<Map<string, any>> {
  if (!traduzioniSlugMapPromise) {
    traduzioniSlugMapPromise = fetchAllTraduzioniRaw().then(({ data }) => {
      const map = new Map<string, any>();
      for (const item of data) {
        map.set(extractSlug(item.attributes.path?.alias), item);
      }
      return map;
    });
  }
  return traduzioniSlugMapPromise;
}

export async function getTraduzione(slug: string): Promise<TraduzioneDetail | null> {
  const [slugMap, { included }] = await Promise.all([
    getTraduzioniSlugMap(),
    fetchAllTraduzioniRaw(),
  ]);
  const item = slugMap.get(slug);
  if (!item) return null;
  const map = buildIncludedMap(included);
  return mapTraduzioneDetail(item, map);
}

export async function getAllTraduzioniDetail(): Promise<TraduzioneDetail[]> {
  const { data, included } = await fetchAllTraduzioniRaw();
  const map = buildIncludedMap(included);
  return data.map((item: any) => mapTraduzioneDetail(item, map));
}
