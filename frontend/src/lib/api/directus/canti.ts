import { fetchCollection } from './client.js';
import { mapCantoRecente, mapCantoCard, mapCantoDetail } from './mappers.js';
import type { CantoPath, CantoRecente, CantoCard, CantoDetail } from '../types.js';

export async function getCanti(): Promise<CantoPath[]> {
  return fetchCollection('canti', {
    fields: 'id,slug',
    'filter[status][_eq]': 'published',
    limit: '-1',
  });
}

export async function getCantiRecenti(limit = 50): Promise<CantoRecente[]> {
  const items = await fetchCollection('canti', {
    fields: 'id,titolo,slug,capoverso',
    'filter[status][_eq]': 'published',
    sort: '-date_created',
    limit: String(limit),
  });
  return items.map(mapCantoRecente);
}

export async function getCantiPiuVisti(limit = 10): Promise<CantoCard[]> {
  const items = await fetchCollection('canti', {
    fields: [
      'id', 'titolo', 'slug', 'anno', 'capoverso', 'video_url', 'accordi',
      'visualizzazioni',
      'autori_testo.autori_id.titolo', 'autori_testo.autori_id.slug',
      'autori_musica.autori_id.titolo', 'autori_musica.autori_id.slug',
    ].join(','),
    'filter[status][_eq]': 'published',
    sort: '-visualizzazioni',
    limit: String(limit),
  });
  return items.map(mapCantoCard);
}

export async function getCanto(slug: string): Promise<CantoDetail | null> {
  const items = await fetchCollection('canti', {
    fields: [
      'id', 'titolo', 'slug', 'anno', 'testo', 'accordi',
      'audio', 'video_url', 'fonte', 'informazioni', 'capoverso',
      'autori_testo.autori_id.titolo', 'autori_testo.autori_id.slug',
      'autori_musica.autori_id.titolo', 'autori_musica.autori_id.slug',
      'lingue.lingue_id.titolo', 'lingue.lingue_id.slug',
      'periodi.periodi_id.titolo', 'periodi.periodi_id.slug',
      'tags.tags_id.titolo', 'tags.tags_id.slug',
      'tematiche.tematiche_id.titolo', 'tematiche.tematiche_id.slug',
    ].join(','),
    'filter[slug][_eq]': slug,
    'filter[status][_eq]': 'published',
    limit: '1',
  });
  return items[0] ? mapCantoDetail(items[0]) : null;
}
