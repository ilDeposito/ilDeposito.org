import {
  fetchAllLingueRaw, fetchAllLocalizzazioniRaw, fetchAllPeriodiRaw, fetchAllTagsRaw,
  fetchAllCantiRaw, fetchAllAutoriRaw, fetchAllEventiRaw, fetchAllTraduzioniRaw,
} from './store.js';
import { extractSlug, buildIncludedMap, resolveImageUrl } from './resolvers.js';
import { getImageUrl } from './assets.js';
import type {
  Tassonomia, Periodo, Tag,
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
  const { data } = await fetchAllLingueRaw();
  return data
    .map(mapTassonomia)
    .sort((a, b) => a.titolo.localeCompare(b.titolo, 'it'));
}

export async function getContenutiByLinguaMap(): Promise<Map<number | string, ContenutiLingua>> {
  const [cantiRes, traduzioniRes] = await Promise.all([
    fetchAllCantiRaw(),
    fetchAllTraduzioniRaw(),
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
  const { data } = await fetchAllLocalizzazioniRaw();
  return data
    .map(mapTassonomia)
    .sort((a, b) => a.titolo.localeCompare(b.titolo, 'it'));
}

export async function getContenutiByLocalizzazioneMap(): Promise<Map<number | string, ContenutiLocalizzazione>> {
  const [autoriRes, eventiRes] = await Promise.all([
    fetchAllAutoriRaw(),
    fetchAllEventiRaw(),
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
  const { data, included } = await fetchAllPeriodiRaw();
  const map = buildIncludedMap(included);

  return data.map((item: any) => ({
    id: item.attributes.drupal_internal__tid,
    titolo: item.attributes.name,
    slug: extractSlug(item.attributes.path?.alias),
    sort: item.attributes.weight ?? 0,
    immagine: resolveImageUrl(item.relationships?.field_immagine, map),
  }));
}

export async function getPeriodoWatermarkCasuale(): Promise<string | null> {
  const periodi = await getPeriodi();
  const conImmagine = periodi.filter((p) => p.immagine);
  if (conImmagine.length === 0) return null;

  const scelto = conImmagine[Math.floor(Math.random() * conImmagine.length)];
  return getImageUrl(scelto.immagine);
}

export async function getContenutiByPeriodoMap(): Promise<Map<number | string, ContenutiPeriodo>> {
  const [cantiRes, autoriRes, eventiRes] = await Promise.all([
    fetchAllCantiRaw(),
    fetchAllAutoriRaw(),
    fetchAllEventiRaw(),
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

export async function getTags(): Promise<Tag[]> {
  const { data, included } = await fetchAllTagsRaw();
  const map = buildIncludedMap(included);

  return data
    .map((item: any): Tag => ({
      ...mapTassonomia(item),
      immagine: resolveImageUrl(item.relationships?.field_immagine, map),
    }))
    .sort((a, b) => a.titolo.localeCompare(b.titolo, 'it'));
}

export async function getContenutiByTagMap(): Promise<Map<number | string, ContenutiTag>> {
  const [cantiRes, eventiRes] = await Promise.all([
    fetchAllCantiRaw(),
    fetchAllEventiRaw(),
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
