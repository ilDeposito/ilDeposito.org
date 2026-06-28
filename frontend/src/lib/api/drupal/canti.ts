import { fetchAllCantiRaw } from './store.js';
import { buildIncludedMap, extractSlug } from './resolvers.js';
import { mapCantoRecente, mapCantoCard, mapCantoDetail } from './mappers.js';
import type { CantoPath, CantoRecente, CantoCard, CantoDetail } from '../types.js';

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
    .sort((a: any, b: any) => (b.attributes.field_visualizzazioni ?? 0) - (a.attributes.field_visualizzazioni ?? 0))
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
    .sort((a: any, b: any) => (b.attributes.field_visualizzazioni ?? 0) - (a.attributes.field_visualizzazioni ?? 0))
    .slice(0, limit)
    .map((item: any) => mapCantoCard(item, map));
}

export async function getCanto(slug: string): Promise<CantoDetail | null> {
  const { data, included } = await fetchAllCantiRaw();
  const item = data.find((c: any) => extractSlug(c.attributes.path?.alias) === slug);
  if (!item) return null;
  const map = buildIncludedMap(included);
  return mapCantoDetail(item, map);
}

export async function getAllCantiDetail(): Promise<CantoDetail[]> {
  const { data, included } = await fetchAllCantiRaw();
  const map = buildIncludedMap(included);
  return data.map((item: any) => mapCantoDetail(item, map));
}
