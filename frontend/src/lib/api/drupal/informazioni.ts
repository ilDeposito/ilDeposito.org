import { fetchAllPagineRaw } from './store.js';
import { sanitizeHtml } from './mappers.js';
import type { InformazionePath, InformazioneDetail } from '../types.js';

function mapPagina(item: any): { percorso: string; raw: any } {
  const alias = item.attributes.path?.alias ?? '';
  return {
    percorso: alias.startsWith('/') ? alias.slice(1) : alias,
    raw: item,
  };
}

export async function getInformazioni(): Promise<InformazionePath[]> {
  const { data } = await fetchAllPagineRaw();
  return data.map((item: any) => {
    const { percorso } = mapPagina(item);
    return {
      id: item.attributes.drupal_internal__nid,
      titolo: item.attributes.title,
      percorso,
    };
  });
}

export async function getInformazione(percorso: string): Promise<InformazioneDetail | null> {
  const { data } = await fetchAllPagineRaw();
  const match = data.find((item: any) => {
    const { percorso: p } = mapPagina(item);
    return p === percorso;
  });
  if (!match) return null;

  const alias = match.attributes.path?.alias ?? '';
  const field = match.attributes.field_descrizione_header;
  const html = field?.processed ?? field?.value ?? field ?? '';

  return {
    id: match.attributes.drupal_internal__nid,
    titolo: match.attributes.title,
    percorso: alias.startsWith('/') ? alias.slice(1) : alias,
    testo: sanitizeHtml(html),
  };
}

export async function getAllInformazioniDetail(): Promise<InformazioneDetail[]> {
  const { data } = await fetchAllPagineRaw();
  return data.map((item: any) => {
    const alias = item.attributes.path?.alias ?? '';
    const field = item.attributes.field_descrizione_header;
    const html = field?.processed ?? field?.value ?? field ?? '';
    return {
      id: item.attributes.drupal_internal__nid,
      titolo: item.attributes.title,
      percorso: alias.startsWith('/') ? alias.slice(1) : alias,
      testo: sanitizeHtml(html),
    };
  });
}
