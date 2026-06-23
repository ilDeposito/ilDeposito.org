import { fetchCollection } from './client.js';
import { mapAutoreCard, mapAutoreDetail, mapCantoInAutore } from './mappers.js';
import type { AutorePath, AutoreCard, AutoreDetail, CantoInAutore } from '../types.js';

export async function getAutori(): Promise<AutorePath[]> {
  return fetchCollection('autori', {
    fields: 'id,slug',
    'filter[status][_eq]': 'published',
    limit: '-1',
  });
}

export async function getAutoriPiuVisti(limit = 20): Promise<AutoreCard[]> {
  const items = await fetchCollection('autori', {
    fields: [
      'id', 'slug', 'titolo', 'immagine', 'visualizzazioni',
      'localizzazioni.localizzazioni_id.titolo',
      'localizzazioni.localizzazioni_id.slug',
    ].join(','),
    'filter[status][_eq]': 'published',
    sort: '-visualizzazioni',
    limit: String(limit),
  });
  return items.map(mapAutoreCard);
}

export async function getAutore(slug: string): Promise<AutoreDetail | null> {
  const items = await fetchCollection('autori', {
    fields: [
      'id', 'slug', 'titolo', 'informazioni', 'immagine',
      'localizzazioni.localizzazioni_id.titolo',
      'localizzazioni.localizzazioni_id.slug',
      'periodi.periodi_id.titolo',
      'periodi.periodi_id.slug',
    ].join(','),
    'filter[slug][_eq]': slug,
    'filter[status][_eq]': 'published',
    limit: '1',
  });
  return items[0] ? mapAutoreDetail(items[0]) : null;
}

let cantiByAutoreCache: Map<number | string, CantoInAutore[]> | null = null;

export async function getCantiByAutoreMap(): Promise<Map<number | string, CantoInAutore[]>> {
  if (!cantiByAutoreCache) cantiByAutoreCache = await buildCantiByAutoreMap();
  return cantiByAutoreCache;
}

async function buildCantiByAutoreMap(): Promise<Map<number | string, CantoInAutore[]>> {
  const fields = [
    'autori_id',
    'canti_id.id', 'canti_id.titolo', 'canti_id.slug', 'canti_id.anno',
    'canti_id.capoverso', 'canti_id.video_url', 'canti_id.accordi',
    'canti_id.visualizzazioni', 'canti_id.status',
  ].join(',');

  const [testo, musica] = await Promise.all([
    fetchCollection('canti_autori_testo', { fields, limit: '-1' }),
    fetchCollection('canti_autori_musica', { fields, limit: '-1' }),
  ]);

  const map = new Map<number | string, CantoInAutore[]>();
  for (const row of [...testo, ...musica]) {
    const raw = row.canti_id;
    if (!raw || raw.status !== 'published') continue;

    const canti = map.get(row.autori_id) ?? [];
    if (!canti.some((c) => c.id === raw.id)) canti.push(mapCantoInAutore(raw));
    map.set(row.autori_id, canti);
  }
  return map;
}
