import type { Ref } from '../types.js';

export interface IncludedMap {
  get(type: string, id: string): any | undefined;
}

export function buildIncludedMap(included: any[] = []): IncludedMap {
  const map = new Map<string, any>();
  for (const item of included) {
    map.set(`${item.type}:${item.id}`, item);
  }
  return { get: (type, id) => map.get(`${type}:${id}`) };
}

export function resolveRefs(rel: any, included: IncludedMap): Ref[] {
  const items = Array.isArray(rel?.data) ? rel.data : rel?.data ? [rel.data] : [];
  return items
    .map((ref: any) => included.get(ref.type, ref.id))
    .filter(Boolean)
    .map((item: any) => ({
      titolo: item.attributes.title ?? item.attributes.name,
      slug: extractSlug(item.attributes.path?.alias),
    }));
}

export function resolveRef(rel: any, included: IncludedMap): any | null {
  if (!rel?.data) return null;
  const ref = Array.isArray(rel.data) ? rel.data[0] : rel.data;
  if (!ref) return null;
  return included.get(ref.type, ref.id) ?? null;
}

export function resolveMany(rel: any, included: IncludedMap): any[] {
  const items = Array.isArray(rel?.data) ? rel.data : rel?.data ? [rel.data] : [];
  return items
    .map((ref: any) => included.get(ref.type, ref.id))
    .filter(Boolean);
}

// Quando field_slug sarà disponibile in Drupal, usare quello al posto di path.alias
export function extractSlug(alias: string | null | undefined, fieldSlug?: string | null): string {
  if (fieldSlug) return fieldSlug;
  if (!alias) return '';
  return alias.split('/').pop() ?? '';
}

// Risolve node → media--image → file--file e restituisce l'URL relativo del file
export function resolveImageUrl(rel: any, included: IncludedMap): string | null {
  const media = resolveRef(rel, included);
  if (!media) return null;
  const file = resolveRef(media.relationships?.field_media_image, included);
  if (!file) return null;
  return file.attributes?.uri?.url ?? null;
}
