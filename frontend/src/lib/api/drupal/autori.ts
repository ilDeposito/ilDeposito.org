import { fetchAllAutoriRaw, fetchAllCantiRaw } from './store.js';
import { buildIncludedMap, extractSlug, resolveImageUrl } from './resolvers.js';
import { mapAutoreCard, mapAutoreDetail, mapCantoInAutore } from './mappers.js';
import type { AutorePath, AutoreCard, AutoreDetail, CantoInAutore, Ref } from '../types.js';

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

// Soglia minima e numero di tematiche mostrate: una tematica "caratterizza"
// un autore solo se ricorre in almeno 2 dei suoi canti (altrimenti un singolo
// canto basterebbe a "etichettare" l'autore su un campione non significativo).
const TEMATICA_MIN_CANTI = 2;
const TEMATICA_TOP_N = 2;

let tematichePerAutoreCache: Map<number | string, Ref[]> | null = null;

// Nessun campo diretto autore↔tematica in Drupal: si derivano le tematiche
// dominanti di un autore contando, sui suoi canti (testo+musica), quali
// tematiche ricorrono più spesso. Stesso dato usato per le badge in testata
// e per il filtro tematica sull'elenco autori (vedi getContenutiByTematicaMap
// in tassonomie.ts per la derivazione simmetrica letta dal verso opposto).
export async function getTematichePerAutoreMap(): Promise<Map<number | string, Ref[]>> {
  if (tematichePerAutoreCache) return tematichePerAutoreCache;

  const { data, included } = await fetchAllCantiRaw();
  const includedMap = buildIncludedMap(included);

  const perAutore = new Map<number, Map<number, { count: number; ref: Ref }>>();

  for (const canto of data) {
    const rels = canto.relationships;
    const autoreIds = new Set<number>();
    for (const ref of [...(rels.field_autori_testo?.data ?? []), ...(rels.field_autori_musica?.data ?? [])]) {
      const autore = includedMap.get(ref.type, ref.id);
      if (autore) autoreIds.add(autore.attributes.drupal_internal__nid);
    }
    if (autoreIds.size === 0) continue;

    const tematiche: Array<{ id: number; ref: Ref }> = [];
    for (const ref of (rels.field_tematiche?.data ?? [])) {
      const term = includedMap.get(ref.type, ref.id);
      if (!term) continue;
      tematiche.push({
        id: ref.meta.drupal_internal__target_id,
        ref: { titolo: term.attributes.name, slug: extractSlug(term.attributes.path?.alias) },
      });
    }
    if (tematiche.length === 0) continue;

    for (const autoreId of autoreIds) {
      let counts = perAutore.get(autoreId);
      if (!counts) { counts = new Map(); perAutore.set(autoreId, counts); }
      for (const { id: tid, ref } of tematiche) {
        const entry = counts.get(tid);
        if (entry) entry.count += 1;
        else counts.set(tid, { count: 1, ref });
      }
    }
  }

  const result = new Map<number | string, Ref[]>();
  for (const [autoreId, counts] of perAutore) {
    const top = [...counts.values()]
      .filter((e) => e.count >= TEMATICA_MIN_CANTI)
      // Tiebreak alfabetico: a parità di conteggio l'ordine deve restare
      // stabile da una build all'altra (stesso problema del tiebreaker tid
      // sui periodi in store.ts).
      .sort((a, b) => b.count - a.count || a.ref.titolo.localeCompare(b.ref.titolo, 'it'))
      .slice(0, TEMATICA_TOP_N)
      .map((e) => e.ref);
    if (top.length > 0) result.set(autoreId, top);
  }

  tematichePerAutoreCache = result;
  return result;
}

export async function getAutoriByTematica(tematicaId: number | string, limit = 5): Promise<AutoreCard[]> {
  const [cantiRes, autoriRes] = await Promise.all([fetchAllCantiRaw(), fetchAllAutoriRaw()]);
  const cantiIncludedMap = buildIncludedMap(cantiRes.included);
  const autoriIncludedMap = buildIncludedMap(autoriRes.included);
  const tid = Number(tematicaId);

  const autoreIds = new Set<number>();
  for (const canto of cantiRes.data) {
    const haTematica = (canto.relationships.field_tematiche?.data ?? []).some(
      (ref: any) => ref.meta?.drupal_internal__target_id === tid
    );
    if (!haTematica) continue;
    for (const ref of [
      ...(canto.relationships.field_autori_testo?.data ?? []),
      ...(canto.relationships.field_autori_musica?.data ?? []),
    ]) {
      const autore = cantiIncludedMap.get(ref.type, ref.id);
      if (autore) autoreIds.add(autore.attributes.drupal_internal__nid);
    }
  }

  return autoriRes.data
    .filter((item: any) => autoreIds.has(item.attributes.drupal_internal__nid))
    .sort((a: any, b: any) => (b.attributes.field_visualizzazioni_totali ?? 0) - (a.attributes.field_visualizzazioni_totali ?? 0))
    .slice(0, limit)
    .map((item: any) => mapAutoreCard(item, autoriIncludedMap));
}
