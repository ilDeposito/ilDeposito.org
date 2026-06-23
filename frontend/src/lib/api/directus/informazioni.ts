import { fetchCollection } from './client.js';
import type { InformazionePath, InformazioneDetail } from '../types.js';

export async function getInformazioni(): Promise<InformazionePath[]> {
  return fetchCollection('informazioni', {
    fields: 'id,titolo,percorso',
    'filter[status][_eq]': 'published',
    limit: '-1',
  });
}

export async function getInformazione(percorso: string): Promise<InformazioneDetail | null> {
  const items = await fetchCollection('informazioni', {
    fields: 'id,titolo,percorso,testo',
    'filter[percorso][_eq]': percorso,
    'filter[status][_eq]': 'published',
    limit: '1',
  });
  return (items[0] as InformazioneDetail) ?? null;
}
