import { fetchCollection } from './client.js';
import type {
  Tassonomia, Periodo,
  ContenutiLingua, ContenutiLocalizzazione, ContenutiPeriodo, ContenutiTag,
} from '../types.js';

// ── Lingue ─────────────────────────────────────────────

export async function getLingue(): Promise<Tassonomia[]> {
  const lingue = await fetchCollection('lingue', {
    fields: 'id,titolo,slug',
    limit: '-1',
  });
  return lingue.sort((a: any, b: any) => a.titolo.localeCompare(b.titolo, 'it'));
}

export async function getContenutiByLinguaMap(): Promise<Map<number | string, ContenutiLingua>> {
  const [cantiRows, traduzioniRows] = await Promise.all([
    fetchCollection('canti_lingue', { fields: 'lingue_id,canti_id.status', limit: '-1' }),
    fetchCollection('traduzioni_lingue', { fields: 'lingue_id,traduzioni_id.status', limit: '-1' }),
  ]);

  const map = new Map<number | string, ContenutiLingua>();
  const getEntry = (id: number | string) => {
    let entry = map.get(id);
    if (!entry) { entry = { canti: 0, traduzioni: 0 }; map.set(id, entry); }
    return entry;
  };

  for (const row of cantiRows) {
    if (row.canti_id?.status === 'published') getEntry(row.lingue_id).canti += 1;
  }
  for (const row of traduzioniRows) {
    if (row.traduzioni_id?.status === 'published') getEntry(row.lingue_id).traduzioni += 1;
  }
  return map;
}

// ── Localizzazioni ─────────────────────────────────────

export async function getLocalizzazioni(): Promise<Tassonomia[]> {
  const localizzazioni = await fetchCollection('localizzazioni', {
    fields: 'id,titolo,slug',
    limit: '-1',
  });
  return localizzazioni.sort((a: any, b: any) => a.titolo.localeCompare(b.titolo, 'it'));
}

export async function getContenutiByLocalizzazioneMap(): Promise<Map<number | string, ContenutiLocalizzazione>> {
  const [autoriRows, eventiRows] = await Promise.all([
    fetchCollection('autori_localizzazioni', { fields: 'localizzazioni_id,autori_id.status', limit: '-1' }),
    fetchCollection('eventi_localizzazioni', { fields: 'localizzazioni_id,eventi_id.status', limit: '-1' }),
  ]);

  const map = new Map<number | string, ContenutiLocalizzazione>();
  const getEntry = (id: number | string) => {
    let entry = map.get(id);
    if (!entry) { entry = { autori: 0, eventi: 0 }; map.set(id, entry); }
    return entry;
  };

  for (const row of autoriRows) {
    if (row.autori_id?.status === 'published') getEntry(row.localizzazioni_id).autori += 1;
  }
  for (const row of eventiRows) {
    if (row.eventi_id?.status === 'published') getEntry(row.localizzazioni_id).eventi += 1;
  }
  return map;
}

// ── Periodi ────────────────────────────────────────────

export async function getPeriodi(): Promise<Periodo[]> {
  return fetchCollection('periodi', {
    fields: 'id,titolo,slug,sort,immagine',
    sort: 'sort',
    limit: '-1',
  });
}

export async function getContenutiByPeriodoMap(): Promise<Map<number | string, ContenutiPeriodo>> {
  const [cantiRows, autoriRows, eventiRows] = await Promise.all([
    fetchCollection('canti_periodi', { fields: 'periodi_id,canti_id.status', limit: '-1' }),
    fetchCollection('autori_periodi', { fields: 'periodi_id,autori_id.status', limit: '-1' }),
    fetchCollection('eventi_periodi', { fields: 'periodi_id,eventi_id.status', limit: '-1' }),
  ]);

  const map = new Map<number | string, ContenutiPeriodo>();
  const getEntry = (id: number | string) => {
    let entry = map.get(id);
    if (!entry) { entry = { canti: 0, autori: 0, eventi: 0 }; map.set(id, entry); }
    return entry;
  };

  for (const row of cantiRows) {
    if (row.canti_id?.status === 'published') getEntry(row.periodi_id).canti += 1;
  }
  for (const row of autoriRows) {
    if (row.autori_id?.status === 'published') getEntry(row.periodi_id).autori += 1;
  }
  for (const row of eventiRows) {
    if (row.eventi_id?.status === 'published') getEntry(row.periodi_id).eventi += 1;
  }
  return map;
}

// ── Tags ───────────────────────────────────────────────

export async function getTags(): Promise<Tassonomia[]> {
  const tags = await fetchCollection('tags', {
    fields: 'id,titolo,slug',
    limit: '-1',
  });
  return tags.sort((a: any, b: any) => a.titolo.localeCompare(b.titolo, 'it'));
}

export async function getContenutiByTagMap(): Promise<Map<number | string, ContenutiTag>> {
  const [cantiRows, eventiRows] = await Promise.all([
    fetchCollection('canti_tags', { fields: 'tags_id,canti_id.status', limit: '-1' }),
    fetchCollection('eventi_tags', { fields: 'tags_id,eventi_id.status', limit: '-1' }),
  ]);

  const map = new Map<number | string, ContenutiTag>();
  const getEntry = (id: number | string) => {
    let entry = map.get(id);
    if (!entry) { entry = { canti: 0, eventi: 0 }; map.set(id, entry); }
    return entry;
  };

  for (const row of cantiRows) {
    if (row.canti_id?.status === 'published') getEntry(row.tags_id).canti += 1;
  }
  for (const row of eventiRows) {
    if (row.eventi_id?.status === 'published') getEntry(row.tags_id).eventi += 1;
  }
  return map;
}
