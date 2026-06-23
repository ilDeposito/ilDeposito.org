import { fetchAllJsonApi } from './client.js';
import { extractSlug, buildIncludedMap, resolveImageUrl } from './resolvers.js';
import type {
  Tassonomia, Periodo,
  ContenutiLingua, ContenutiLocalizzazione, ContenutiPeriodo, ContenutiTag,
} from '../types.js';

// ── Helpers ───────────────────────────────────────────

function mapTassonomia(item: any): Tassonomia {
  return {
    id: item.attributes.drupal_internal__tid,
    titolo: item.attributes.name,
    slug: extractSlug(item.attributes.path?.alias),
  };
}

// ── Lingue ─────────────────────────────────────────────

export async function getLingue(): Promise<Tassonomia[]> {
  const { data } = await fetchAllJsonApi('/jsonapi/taxonomy_term/lingue', new URLSearchParams({
    'fields[taxonomy_term--lingue]': 'name,path',
    'page[limit]': '50',
  }));

  return data
    .map(mapTassonomia)
    .sort((a, b) => a.titolo.localeCompare(b.titolo, 'it'));
}

export async function getContenutiByLinguaMap(): Promise<Map<number | string, ContenutiLingua>> {
  const [cantiRes, traduzioniRes] = await Promise.all([
    fetchAllJsonApi('/jsonapi/node/canto', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--canto]': 'field_lingua',
      'page[limit]': '50',
    })),
    fetchAllJsonApi('/jsonapi/node/traduzione', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--traduzione]': 'field_lingua',
      'page[limit]': '50',
    })),
  ]);

  const map = new Map<number | string, ContenutiLingua>();
  const getEntry = (id: number | string) => {
    let entry = map.get(id);
    if (!entry) { entry = { canti: 0, traduzioni: 0 }; map.set(id, entry); }
    return entry;
  };

  for (const canto of cantiRes.data) {
    for (const ref of (canto.relationships.field_lingua?.data ?? [])) {
      getEntry(ref.meta.drupal_internal__target_id).canti += 1;
    }
  }
  for (const traduzione of traduzioniRes.data) {
    for (const ref of (traduzione.relationships.field_lingua?.data ?? [])) {
      getEntry(ref.meta.drupal_internal__target_id).traduzioni += 1;
    }
  }

  return map;
}

// ── Localizzazioni ─────────────────────────────────────

export async function getLocalizzazioni(): Promise<Tassonomia[]> {
  const { data } = await fetchAllJsonApi('/jsonapi/taxonomy_term/localizzazioni', new URLSearchParams({
    'fields[taxonomy_term--localizzazioni]': 'name,path',
    'page[limit]': '50',
  }));

  return data
    .map(mapTassonomia)
    .sort((a, b) => a.titolo.localeCompare(b.titolo, 'it'));
}

export async function getContenutiByLocalizzazioneMap(): Promise<Map<number | string, ContenutiLocalizzazione>> {
  const [autoriRes, eventiRes] = await Promise.all([
    fetchAllJsonApi('/jsonapi/node/autore', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--autore]': 'field_localizzazione',
      'page[limit]': '50',
    })),
    fetchAllJsonApi('/jsonapi/node/evento', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--evento]': 'field_localizzazione',
      'page[limit]': '50',
    })),
  ]);

  const map = new Map<number | string, ContenutiLocalizzazione>();
  const getEntry = (id: number | string) => {
    let entry = map.get(id);
    if (!entry) { entry = { autori: 0, eventi: 0 }; map.set(id, entry); }
    return entry;
  };

  for (const autore of autoriRes.data) {
    for (const ref of (autore.relationships.field_localizzazione?.data ?? [])) {
      getEntry(ref.meta.drupal_internal__target_id).autori += 1;
    }
  }
  for (const evento of eventiRes.data) {
    for (const ref of (evento.relationships.field_localizzazione?.data ?? [])) {
      getEntry(ref.meta.drupal_internal__target_id).eventi += 1;
    }
  }

  return map;
}

// ── Periodi ────────────────────────────────────────────

export async function getPeriodi(): Promise<Periodo[]> {
  const { data, included } = await fetchAllJsonApi('/jsonapi/taxonomy_term/periodi', new URLSearchParams({
    'fields[taxonomy_term--periodi]': 'name,path,weight,field_immagine',
    'fields[media--image]': 'field_media_image',
    'fields[file--file]': 'uri',
    'include': 'field_immagine,field_immagine.field_media_image',
    'sort': 'weight',
    'page[limit]': '50',
  }));

  const map = buildIncludedMap(included);

  return data.map((item: any) => ({
    id: item.attributes.drupal_internal__tid,
    titolo: item.attributes.name,
    slug: extractSlug(item.attributes.path?.alias),
    sort: item.attributes.weight ?? 0,
    immagine: resolveImageUrl(item.relationships?.field_immagine, map),
  }));
}

export async function getContenutiByPeriodoMap(): Promise<Map<number | string, ContenutiPeriodo>> {
  const [cantiRes, autoriRes, eventiRes] = await Promise.all([
    fetchAllJsonApi('/jsonapi/node/canto', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--canto]': 'field_periodo',
      'page[limit]': '50',
    })),
    fetchAllJsonApi('/jsonapi/node/autore', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--autore]': 'field_periodo',
      'page[limit]': '50',
    })),
    fetchAllJsonApi('/jsonapi/node/evento', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--evento]': 'field_periodo',
      'page[limit]': '50',
    })),
  ]);

  const map = new Map<number | string, ContenutiPeriodo>();
  const getEntry = (id: number | string) => {
    let entry = map.get(id);
    if (!entry) { entry = { canti: 0, autori: 0, eventi: 0 }; map.set(id, entry); }
    return entry;
  };

  for (const canto of cantiRes.data) {
    for (const ref of (canto.relationships.field_periodo?.data ?? [])) {
      getEntry(ref.meta.drupal_internal__target_id).canti += 1;
    }
  }
  for (const autore of autoriRes.data) {
    for (const ref of (autore.relationships.field_periodo?.data ?? [])) {
      getEntry(ref.meta.drupal_internal__target_id).autori += 1;
    }
  }
  for (const evento of eventiRes.data) {
    for (const ref of (evento.relationships.field_periodo?.data ?? [])) {
      getEntry(ref.meta.drupal_internal__target_id).eventi += 1;
    }
  }

  return map;
}

// ── Tags ───────────────────────────────────────────────

export async function getTags(): Promise<Tassonomia[]> {
  const { data } = await fetchAllJsonApi('/jsonapi/taxonomy_term/tags', new URLSearchParams({
    'fields[taxonomy_term--tags]': 'name,path',
    'page[limit]': '50',
  }));

  return data
    .map(mapTassonomia)
    .sort((a, b) => a.titolo.localeCompare(b.titolo, 'it'));
}

export async function getContenutiByTagMap(): Promise<Map<number | string, ContenutiTag>> {
  const [cantiRes, eventiRes] = await Promise.all([
    fetchAllJsonApi('/jsonapi/node/canto', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--canto]': 'field_tags',
      'page[limit]': '50',
    })),
    fetchAllJsonApi('/jsonapi/node/evento', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--evento]': 'field_tags',
      'page[limit]': '50',
    })),
  ]);

  const map = new Map<number | string, ContenutiTag>();
  const getEntry = (id: number | string) => {
    let entry = map.get(id);
    if (!entry) { entry = { canti: 0, eventi: 0 }; map.set(id, entry); }
    return entry;
  };

  for (const canto of cantiRes.data) {
    for (const ref of (canto.relationships.field_tags?.data ?? [])) {
      getEntry(ref.meta.drupal_internal__target_id).canti += 1;
    }
  }
  for (const evento of eventiRes.data) {
    for (const ref of (evento.relationships.field_tags?.data ?? [])) {
      getEntry(ref.meta.drupal_internal__target_id).eventi += 1;
    }
  }

  return map;
}
