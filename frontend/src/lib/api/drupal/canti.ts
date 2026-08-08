import { fetchAllCantiRaw } from './store.js';
import { fetchNodeByUuid } from './client.js';
import { buildIncludedMap, extractSlug } from './resolvers.js';
import { mapCantoRecente, mapCantoCard, mapCantoDetail } from './mappers.js';
import type { CantoPath, CantoRecente, CantoCard, CantoDetail } from '../types.js';

// Stessi fields/include della collezione (store.ts), usati per la build
// statica: qui si applicano a una singola risorsa per uuid, con auth, senza
// filter[status] così da poter risolvere anche canti non pubblicati.
const CANTO_PREVIEW_PARAMS = new URLSearchParams({
  'fields[node--canto]': [
    'drupal_internal__nid', 'title', 'path', 'created', 'changed',
    'field_anno', 'field_capoverso', 'field_canto_testo', 'field_canto_accordi',
    'field_audio', 'field_fonte', 'field_informazioni', 'field_altri_titoli',
    'field_autori_testo', 'field_autori_musica',
    'field_lingua', 'field_periodo', 'field_tags', 'field_tematiche',
    'field_visualizzazioni_totali',
  ].join(','),
  'fields[node--autore]': 'drupal_internal__nid,title,path,field_nome',
  'fields[taxonomy_term--lingue]': 'name,path',
  'fields[taxonomy_term--periodi]': 'name,path',
  'fields[taxonomy_term--tags]': 'name,path',
  'fields[taxonomy_term--tematiche]': 'name,path',
  'include': 'field_autori_testo,field_autori_musica,field_lingua,field_periodo,field_tags,field_tematiche',
});

// Per l'anteprima (nodo non pubblicato) non si può passare dallo store SSG
// (che filtra sempre filter[status]=1): fetch diretto per uuid con auth.
export async function getCantoPreview(uuid: string): Promise<CantoDetail | null> {
  const res = await fetchNodeByUuid('canto', uuid, CANTO_PREVIEW_PARAMS);
  if (!res?.data) return null;
  const map = buildIncludedMap(res.included);
  return mapCantoDetail(res.data, map);
}

let slugMapPromise: Promise<Map<string, any>> | null = null;

function getCantiSlugMap(): Promise<Map<string, any>> {
  if (!slugMapPromise) {
    slugMapPromise = fetchAllCantiRaw().then(({ data }) => {
      const map = new Map<string, any>();
      for (const item of data) {
        map.set(extractSlug(item.attributes.path?.alias), item);
      }
      return map;
    });
  }
  return slugMapPromise;
}

export async function getCanti(): Promise<CantoPath[]> {
  const { data } = await fetchAllCantiRaw();
  return data.map((item: any) => ({
    id: item.attributes.drupal_internal__nid,
    slug: extractSlug(item.attributes.path?.alias),
  }));
}

export async function getCantiRecenti(limit = 50): Promise<CantoRecente[]> {
  const { data } = await fetchAllCantiRaw();
  return [...data]
    .sort((a: any, b: any) => {
      const da = a.attributes.created ?? '';
      const db = b.attributes.created ?? '';
      return db.localeCompare(da);
    })
    .slice(0, limit)
    .map(mapCantoRecente);
}

export async function getCantiPiuVisti(limit = 10): Promise<CantoCard[]> {
  const { data, included } = await fetchAllCantiRaw();
  const map = buildIncludedMap(included);
  return [...data]
    .sort((a: any, b: any) => (b.attributes.field_visualizzazioni_totali ?? 0) - (a.attributes.field_visualizzazioni_totali ?? 0))
    .slice(0, limit)
    .map((item: any) => mapCantoCard(item, map));
}

export async function getCantiByPeriodo(periodoId: number | string, limit = 5): Promise<CantoCard[]> {
  const { data, included } = await fetchAllCantiRaw();
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
    .map((item: any) => mapCantoCard(item, map));
}

export async function getCanto(slug: string): Promise<CantoDetail | null> {
  const [slugMap, { included }] = await Promise.all([
    getCantiSlugMap(),
    fetchAllCantiRaw(),
  ]);
  const item = slugMap.get(slug);
  if (!item) return null;
  const map = buildIncludedMap(included);
  return mapCantoDetail(item, map);
}

export async function getAllCantiDetail(): Promise<CantoDetail[]> {
  const { data, included } = await fetchAllCantiRaw();
  const map = buildIncludedMap(included);
  return data.map((item: any) => mapCantoDetail(item, map));
}
