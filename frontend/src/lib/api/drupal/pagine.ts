import { fetchAllPagineRaw, fetchAllImmagineParaGraphsRaw } from './store.js';
import { fetchNodeByUuid } from './client.js';
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

function mapPaginaItem(
  item: any,
  includedMap: ReturnType<typeof buildIncludedMap>,
  immagineUuidMap: Map<string, string>,
): PaginaDetail {
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

  const immagine = resolveImageUrl(r.field_immagine, includedMap);

  return {
    id: a.drupal_internal__nid,
    titolo: a.title,
    percorso,
    descrizioneHeader,
    immagine,
    paragraphs,
  };
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
        const pagina = mapPaginaItem(item, includedMap, immagineUuidMap);
        map.set(pagina.percorso, pagina);
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

// Stessi fields/include della collezione (store.ts), applicati a una singola
// risorsa per uuid con auth, senza filter[status] (nodo non pubblicato incluso).
// Il fallback via immagineUuidMap (griglie) non è disponibile in anteprima:
// per un nodo in bozza le immagini nelle griglie provengono solo dall'include
// diretto (field_immagine risolto in mapParagraph), non da paragraph/immagine
// pubblicati separatamente.
const PAGINA_PREVIEW_PARAMS = new URLSearchParams({
  'fields[node--pagina]': 'drupal_internal__nid,title,path,field_descrizione_header,field_immagine,field_paragraphs',
  'fields[paragraph--testo]': 'field_testo',
  'fields[paragraph--citazione]': 'field_testo,field_fonte',
  'fields[paragraph--immagine]': 'field_immagine,field_descrizione_immagine',
  'fields[paragraph--card]': 'field_titolo,field_testo,field_link',
  'fields[paragraph--griglia]': 'field_colonne,field_grid_item',
  'fields[media--image]': 'field_media_image',
  'fields[file--file]': 'uri',
  'include': [
    'field_immagine',
    'field_immagine.field_media_image',
    'field_paragraphs',
    'field_paragraphs.field_immagine',
    'field_paragraphs.field_immagine.field_media_image',
    'field_paragraphs.field_grid_item',
  ].join(','),
});

export async function getPaginaPreview(uuid: string): Promise<PaginaDetail | null> {
  const res = await fetchNodeByUuid('pagina', uuid, PAGINA_PREVIEW_PARAMS);
  if (!res?.data) return null;
  const includedMap = buildIncludedMap(res.included);
  return mapPaginaItem(res.data, includedMap, new Map());
}
