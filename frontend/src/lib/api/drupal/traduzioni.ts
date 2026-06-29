import { fetchAllTraduzioniRaw } from './store.js';
import { buildIncludedMap, extractSlug } from './resolvers.js';
import { mapTraduzioneDetail } from './mappers.js';
import type { TraduzionePath, TraduzioneDetail } from '../types.js';

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
