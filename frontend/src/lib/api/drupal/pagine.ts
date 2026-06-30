import { fetchAllPagineRaw, fetchAllImmagineParaGraphsRaw } from './store.js';
import { buildIncludedMap, resolveImageUrl } from './resolvers.js';
import { mapParagraph, sanitizeHtml } from './mappers.js';
import type { PaginaDetail, ParagraphItem } from '../types.js';

function aliasToPercorso(alias: string): string {
  return alias.startsWith('/') ? alias.slice(1) : alias;
}

// Mappa UUID paragraph--immagine → imageUrl (relativo Drupal)
// Usata come fallback per immagini dentro griglie, non risolvibili via include profondo.
async function buildImmagineUuidMap(): Promise<Map<string, string>> {
  const { data, included } = await fetchAllImmagineParaGraphsRaw();
  const includedMap = buildIncludedMap(included);
  const map = new Map<string, string>();
  for (const item of data) {
    const imageUrl = resolveImageUrl(item.relationships?.field_immagine, includedMap);
    if (imageUrl) map.set(item.id, imageUrl);
  }
  return map;
}

let pagineMapPromise: Promise<Map<string, PaginaDetail>> | null = null;

function getPagineMap(): Promise<Map<string, PaginaDetail>> {
  if (!pagineMapPromise) {
    pagineMapPromise = Promise.all([
      fetchAllPagineRaw(),
      buildImmagineUuidMap(),
    ]).then(([{ data, included }, immagineUuidMap]) => {
      const includedMap = buildIncludedMap(included);
      const map = new Map<string, PaginaDetail>();

      for (const item of data) {
        const a = item.attributes;
        const r = item.relationships ?? {};

        const alias = a.path?.alias ?? '';
        const percorso = aliasToPercorso(alias);

        const paragraphRefs = Array.isArray(r.field_paragraphs?.data)
          ? r.field_paragraphs.data
          : r.field_paragraphs?.data
            ? [r.field_paragraphs.data]
            : [];

        const paragraphs = paragraphRefs
          .map((ref: any) => includedMap.get(ref.type, ref.id))
          .filter(Boolean)
          .map((raw: any) => mapParagraph(raw, includedMap, immagineUuidMap))
          .filter(Boolean) as ParagraphItem[];

        const descField = a.field_descrizione_header;
        const descrizioneHeader = descField
          ? sanitizeHtml(descField.processed ?? descField.value ?? (typeof descField === 'string' ? descField : ''))
          : null;

        map.set(percorso, {
          id: a.drupal_internal__nid,
          titolo: a.title,
          percorso,
          descrizioneHeader,
          paragraphs,
        });
      }

      return map;
    });
  }
  return pagineMapPromise;
}

export async function getAllPagineDetail(): Promise<PaginaDetail[]> {
  const map = await getPagineMap();
  return [...map.values()];
}

export async function getPagina(percorso: string): Promise<PaginaDetail | null> {
  const map = await getPagineMap();
  return map.get(percorso) ?? null;
}
