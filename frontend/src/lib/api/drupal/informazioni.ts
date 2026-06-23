import { fetchAllJsonApi, fetchJsonApi } from './client.js';
import { extractSlug } from './resolvers.js';
import type { InformazionePath, InformazioneDetail } from '../types.js';

let pathToUuidCache: Map<string, string> | null = null;

async function resolveInformazioneUuid(percorso: string): Promise<string | null> {
  if (!pathToUuidCache) {
    const { data } = await fetchAllJsonApi('/jsonapi/node/pagina', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--pagina]': 'path',
      'page[limit]': '50',
    }));
    pathToUuidCache = new Map();
    for (const item of data) {
      const alias = item.attributes.path?.alias ?? '';
      const key = alias.startsWith('/') ? alias.slice(1) : alias;
      pathToUuidCache.set(key, item.id);
    }
  }
  return pathToUuidCache.get(percorso) ?? null;
}

export async function getInformazioni(): Promise<InformazionePath[]> {
  const { data } = await fetchAllJsonApi('/jsonapi/node/pagina', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--pagina]': 'title,path',
    'page[limit]': '50',
  }));

  if (!pathToUuidCache) {
    pathToUuidCache = new Map();
    for (const item of data) {
      const alias = item.attributes.path?.alias ?? '';
      const key = alias.startsWith('/') ? alias.slice(1) : alias;
      pathToUuidCache.set(key, item.id);
    }
  }

  return data.map((item: any) => {
    const alias = item.attributes.path?.alias ?? '';
    const percorso = alias.startsWith('/') ? alias.slice(1) : alias;
    return {
      id: item.attributes.drupal_internal__nid,
      titolo: item.attributes.title,
      percorso,
    };
  });
}

export async function getInformazione(percorso: string): Promise<InformazioneDetail | null> {
  const uuid = await resolveInformazioneUuid(percorso);
  if (!uuid) return null;

  const response = await fetchJsonApi(`/jsonapi/node/pagina/${uuid}`, new URLSearchParams({
    'fields[node--pagina]': 'title,path,field_descrizione_header',
  }));

  const item = Array.isArray(response.data) ? response.data[0] : response.data;
  if (!item) return null;

  const alias = item.attributes.path?.alias ?? '';

  return {
    id: item.attributes.drupal_internal__nid,
    titolo: item.attributes.title,
    percorso: alias.startsWith('/') ? alias.slice(1) : alias,
    testo: item.attributes.field_descrizione_header ?? '',
  };
}
