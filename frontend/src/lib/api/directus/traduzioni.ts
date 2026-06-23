import { fetchCollection } from './client.js';
import { mapTraduzioneDetail } from './mappers.js';
import type { TraduzionePath, TraduzioneDetail } from '../types.js';

export async function getTraduzioni(): Promise<TraduzionePath[]> {
  return fetchCollection('traduzioni', {
    fields: 'id,slug',
    'filter[status][_eq]': 'published',
    limit: '-1',
  });
}

export async function getTraduzione(slug: string): Promise<TraduzioneDetail | null> {
  const items = await fetchCollection('traduzioni', {
    fields: [
      'id', 'titolo', 'slug', 'testo', 'informazioni',
      'canto_originale.titolo', 'canto_originale.slug',
      'canto_originale.lingue.lingue_id.titolo', 'canto_originale.lingue.lingue_id.slug',
      'lingue.lingue_id.titolo', 'lingue.lingue_id.slug',
    ].join(','),
    'filter[slug][_eq]': slug,
    'filter[status][_eq]': 'published',
    limit: '1',
  });
  return items[0] ? mapTraduzioneDetail(items[0]) : null;
}
