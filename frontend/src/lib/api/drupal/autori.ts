import { fetchAllAutoriRaw, fetchAllCantiRaw } from './store.js';
import { fetchNodeByUuid } from './client.js';
import { buildIncludedMap, extractSlug, resolveImageUrl } from './resolvers.js';
import { mapAutoreCard, mapAutoreDetail, mapCantoInAutore } from './mappers.js';
import type { AutorePath, AutoreCard, AutoreDetail, CantoInAutore } from '../types.js';

// Stessi fields/include della collezione (store.ts), applicati a una singola
// risorsa per uuid con auth, senza filter[status] (nodo non pubblicato incluso).
const AUTORE_PREVIEW_PARAMS = new URLSearchParams({
  'fields[node--autore]': [
    'drupal_internal__nid', 'title', 'path', 'created', 'changed',
    'field_nome', 'field_cognome', 'field_informazioni', 'field_immagine',
    'field_localizzazione', 'field_periodo', 'field_links',
    'field_anno_di_nascita', 'field_anno_di_morte', 'field_visualizzazioni_totali',
    'field_autori_correlati',
  ].join(','),
  'fields[taxonomy_term--localizzazioni]': 'name,path',
  'fields[taxonomy_term--periodi]': 'name,path',
  'fields[media--image]': 'field_media_image',
  'fields[file--file]': 'uri',
  'include': 'field_localizzazione,field_periodo,field_immagine,field_immagine.field_media_image,field_autori_correlati',
});

export async function getAutorePreview(uuid: string): Promise<AutoreDetail | null> {
  const res = await fetchNodeByUuid('autore', uuid, AUTORE_PREVIEW_PARAMS);
  if (!res?.data) return null;
  const map = buildIncludedMap(res.included);
  return mapAutoreDetail(res.data, map);
}

export async function getAutori(): Promise<AutorePath[]> {
  const { data } = await fetchAllAutoriRaw();
  return data.map((item: any) => ({
    id: item.attributes.drupal_internal__nid,
    slug: extractSlug(item.attributes.path?.alias),
  }));
}

export async function getAutoriPiuVisti(limit = 20): Promise<AutoreCard[]> {
  const { data, included } = await fetchAllAutoriRaw();
  const map = buildIncludedMap(included);
  return [...data]
    .sort((a: any, b: any) => (b.attributes.field_visualizzazioni_totali ?? 0) - (a.attributes.field_visualizzazioni_totali ?? 0))
    .slice(0, limit)
    .map((item: any) => mapAutoreCard(item, map));
}

let autoriSlugMapPromise: Promise<Map<string, any>> | null = null;

function getAutoriSlugMap(): Promise<Map<string, any>> {
  if (!autoriSlugMapPromise) {
    autoriSlugMapPromise = fetchAllAutoriRaw().then(({ data }) => {
      const map = new Map<string, any>();
      for (const item of data) {
        map.set(extractSlug(item.attributes.path?.alias), item);
      }
      return map;
    });
  }
  return autoriSlugMapPromise;
}

export async function getAutore(slug: string): Promise<AutoreDetail | null> {
  const [slugMap, { included }] = await Promise.all([
    getAutoriSlugMap(),
    fetchAllAutoriRaw(),
  ]);
  const item = slugMap.get(slug);
  if (!item) return null;
  const map = buildIncludedMap(included);
  return mapAutoreDetail(item, map);
}

export async function getAutoriByPeriodo(periodoId: number | string, limit = 5): Promise<AutoreCard[]> {
  const { data, included } = await fetchAllAutoriRaw();
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
    .map((item: any) => mapAutoreCard(item, map));
}

export async function getAutoriImmaginiMap(): Promise<Map<string, string | null>> {
  const { data, included } = await fetchAllAutoriRaw();
  const map = buildIncludedMap(included);
  const result = new Map<string, string | null>();
  for (const item of data) {
    const slug = extractSlug(item.attributes.path?.alias);
    result.set(slug, resolveImageUrl(item.relationships.field_immagine, map));
  }
  return result;
}

export async function getAllAutoriDetail(): Promise<AutoreDetail[]> {
  const { data, included } = await fetchAllAutoriRaw();
  const map = buildIncludedMap(included);
  return data.map((item: any) => mapAutoreDetail(item, map));
}

let cantiByAutoreCache: Map<number | string, CantoInAutore[]> | null = null;

export async function getCantiByAutoreMap(): Promise<Map<number | string, CantoInAutore[]>> {
  if (cantiByAutoreCache) return cantiByAutoreCache;

  const { data, included } = await fetchAllCantiRaw();
  const includedMap = buildIncludedMap(included);
  const result = new Map<number | string, CantoInAutore[]>();

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
      const canti = result.get(autoreId) ?? [];
      if (!canti.some((c) => c.id === cantoMapped.id)) canti.push(cantoMapped);
      result.set(autoreId, canti);
    }
  }

  cantiByAutoreCache = result;
  return result;
}
