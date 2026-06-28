import { fetchAllJsonApi, type JsonApiResponse } from './client.js';

export interface RawStore {
  data: any[];
  included: any[];
}

let cantiCache: RawStore | null = null;
let autoriCache: RawStore | null = null;
let eventiCache: RawStore | null = null;
let traduzioniCache: RawStore | null = null;
let pagineCache: RawStore | null = null;
let lingueCache: RawStore | null = null;
let localizzazioniCache: RawStore | null = null;
let periodiCache: RawStore | null = null;
let tagsCache: RawStore | null = null;

function toStore(res: JsonApiResponse): RawStore {
  return { data: res.data, included: res.included ?? [] };
}

export async function fetchAllCantiRaw(): Promise<RawStore> {
  if (cantiCache) return cantiCache;
  cantiCache = toStore(await fetchAllJsonApi('/jsonapi/node/canto', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--canto]': [
      'drupal_internal__nid', 'title', 'path', 'created',
      'field_anno', 'field_capoverso', 'field_canto_testo', 'field_canto_accordi',
      'field_audio', 'field_fonte', 'field_informazioni',
      'field_autori_testo', 'field_autori_musica',
      'field_lingua', 'field_periodo', 'field_tags', 'field_tematiche',
      'field_visualizzazioni',
    ].join(','),
    'fields[node--autore]': 'drupal_internal__nid,title,path',
    'fields[taxonomy_term--lingue]': 'name,path',
    'fields[taxonomy_term--periodi]': 'name,path',
    'fields[taxonomy_term--tags]': 'name,path',
    'fields[taxonomy_term--tematiche]': 'name,path',
    'include': 'field_autori_testo,field_autori_musica,field_lingua,field_periodo,field_tags,field_tematiche',
    'page[limit]': '200',
  })));
  return cantiCache;
}

export async function fetchAllAutoriRaw(): Promise<RawStore> {
  if (autoriCache) return autoriCache;
  autoriCache = toStore(await fetchAllJsonApi('/jsonapi/node/autore', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--autore]': [
      'drupal_internal__nid', 'title', 'path',
      'field_nome', 'field_cognome', 'field_informazioni', 'field_immagine',
      'field_localizzazione', 'field_periodo',
      'field_anno_di_nascita', 'field_anno_di_morte', 'field_visualizzazioni',
    ].join(','),
    'fields[taxonomy_term--localizzazioni]': 'name,path',
    'fields[taxonomy_term--periodi]': 'name,path',
    'fields[media--image]': 'field_media_image',
    'fields[file--file]': 'uri',
    'include': 'field_localizzazione,field_periodo,field_immagine,field_immagine.field_media_image',
    'page[limit]': '200',
  })));
  return autoriCache;
}

export async function fetchAllEventiRaw(): Promise<RawStore> {
  if (eventiCache) return eventiCache;
  eventiCache = toStore(await fetchAllJsonApi('/jsonapi/node/evento', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--evento]': [
      'drupal_internal__nid', 'title', 'path',
      'field_data_evento', 'field_informazioni', 'field_immagine',
      'field_geofield', 'field_localizzazione', 'field_periodo',
      'field_tags', 'field_tematiche', 'field_canti_correlati',
      'field_visualizzazioni',
    ].join(','),
    'fields[node--canto]': 'drupal_internal__nid,title,path,field_anno,field_capoverso,field_audio,field_canto_accordi,status',
    'fields[taxonomy_term--localizzazioni]': 'name,path',
    'fields[taxonomy_term--periodi]': 'name,path',
    'fields[taxonomy_term--tags]': 'name,path',
    'fields[taxonomy_term--tematiche]': 'name,path',
    'fields[media--image]': 'field_media_image',
    'fields[file--file]': 'uri',
    'include': 'field_immagine,field_immagine.field_media_image,field_localizzazione,field_periodo,field_tags,field_tematiche,field_canti_correlati',
    'page[limit]': '200',
  })));
  return eventiCache;
}

export async function fetchAllTraduzioniRaw(): Promise<RawStore> {
  if (traduzioniCache) return traduzioniCache;
  traduzioniCache = toStore(await fetchAllJsonApi('/jsonapi/node/traduzione', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--traduzione]': [
      'drupal_internal__nid', 'title', 'path',
      'field_canto_testo', 'field_informazioni', 'field_lingua',
      'field_canti_correlati', 'field_visualizzazioni',
    ].join(','),
    'fields[node--canto]': 'drupal_internal__nid,title,path,field_lingua',
    'fields[taxonomy_term--lingue]': 'name,path',
    'include': 'field_lingua,field_canti_correlati,field_canti_correlati.field_lingua',
    'page[limit]': '200',
  })));
  return traduzioniCache;
}

export async function fetchAllPagineRaw(): Promise<RawStore> {
  if (pagineCache) return pagineCache;
  pagineCache = toStore(await fetchAllJsonApi('/jsonapi/node/pagina', new URLSearchParams({
    'filter[status]': '1',
    'fields[node--pagina]': 'drupal_internal__nid,title,path,field_descrizione_header',
    'page[limit]': '200',
  })));
  return pagineCache;
}

export async function fetchAllLingueRaw(): Promise<RawStore> {
  if (lingueCache) return lingueCache;
  lingueCache = toStore(await fetchAllJsonApi('/jsonapi/taxonomy_term/lingue', new URLSearchParams({
    'fields[taxonomy_term--lingue]': 'drupal_internal__tid,name,path',
    'page[limit]': '200',
  })));
  return lingueCache;
}

export async function fetchAllLocalizzazioniRaw(): Promise<RawStore> {
  if (localizzazioniCache) return localizzazioniCache;
  localizzazioniCache = toStore(await fetchAllJsonApi('/jsonapi/taxonomy_term/localizzazioni', new URLSearchParams({
    'fields[taxonomy_term--localizzazioni]': 'drupal_internal__tid,name,path',
    'page[limit]': '200',
  })));
  return localizzazioniCache;
}

export async function fetchAllPeriodiRaw(): Promise<RawStore> {
  if (periodiCache) return periodiCache;
  periodiCache = toStore(await fetchAllJsonApi('/jsonapi/taxonomy_term/periodi', new URLSearchParams({
    'fields[taxonomy_term--periodi]': 'drupal_internal__tid,name,path,weight,field_immagine',
    'fields[media--image]': 'field_media_image',
    'fields[file--file]': 'uri',
    'include': 'field_immagine,field_immagine.field_media_image',
    'sort': 'weight',
    'page[limit]': '200',
  })));
  return periodiCache;
}

export async function fetchAllTagsRaw(): Promise<RawStore> {
  if (tagsCache) return tagsCache;
  tagsCache = toStore(await fetchAllJsonApi('/jsonapi/taxonomy_term/tags', new URLSearchParams({
    'fields[taxonomy_term--tags]': 'drupal_internal__tid,name,path,field_immagine',
    'fields[media--image]': 'field_media_image',
    'fields[file--file]': 'uri',
    'include': 'field_immagine,field_immagine.field_media_image',
    'page[limit]': '200',
  })));
  return tagsCache;
}
