import { fetchAllJsonApi } from './client.js';

export interface RawStore {
  data: any[];
  included: any[];
}

function toStore(res: { data: any[]; included?: any[] }): RawStore {
  return { data: res.data, included: res.included ?? [] };
}

let cantiPromise: Promise<RawStore> | null = null;
let autoriPromise: Promise<RawStore> | null = null;
let eventiPromise: Promise<RawStore> | null = null;
let traduzioniPromise: Promise<RawStore> | null = null;
let paginePromise: Promise<RawStore> | null = null;
let linguePromise: Promise<RawStore> | null = null;
let localizzazioniPromise: Promise<RawStore> | null = null;
let periodiPromise: Promise<RawStore> | null = null;
let tagsPromise: Promise<RawStore> | null = null;
let mediaHeaderPromise: Promise<RawStore> | null = null;

let warmTriggered = false;
function triggerWarmAll(): void {
  if (warmTriggered) return;
  warmTriggered = true;
  fetchAllCantiRaw();
  fetchAllAutoriRaw();
  fetchAllEventiRaw();
  fetchAllTraduzioniRaw();
  fetchAllPagineRaw();
  fetchAllLingueRaw();
  fetchAllLocalizzazioniRaw();
  fetchAllPeriodiRaw();
  fetchAllTagsRaw();
  fetchAllMediaHeaderRaw();
}

export function fetchAllCantiRaw(): Promise<RawStore> {
  if (!cantiPromise) {
    cantiPromise = fetchAllJsonApi('/jsonapi/node/canto', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--canto]': [
        'drupal_internal__nid', 'title', 'path', 'created', 'changed',
        'field_anno', 'field_capoverso', 'field_canto_testo', 'field_canto_accordi',
        'field_audio', 'field_fonte', 'field_informazioni', 'field_altri_titoli',
        'field_autori_testo', 'field_autori_musica',
        'field_lingua', 'field_periodo', 'field_tags', 'field_tematiche',
        'field_visualizzazioni_totali',
      ].join(','),
      'fields[node--autore]': 'drupal_internal__nid,title,path,field_nome',
      'fields[taxonomy_term--lingue]': 'name,path',
      'fields[taxonomy_term--periodi]': 'name,path',
      'fields[taxonomy_term--tags]': 'name,path',
      'fields[taxonomy_term--tematiche]': 'name,path',
      'include': 'field_autori_testo,field_autori_musica,field_lingua,field_periodo,field_tags,field_tematiche',
      'page[limit]': '200',
    })).then(toStore);
    triggerWarmAll();
  }
  return cantiPromise;
}

export function fetchAllAutoriRaw(): Promise<RawStore> {
  if (!autoriPromise) {
    autoriPromise = fetchAllJsonApi('/jsonapi/node/autore', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--autore]': [
        'drupal_internal__nid', 'title', 'path', 'created', 'changed',
        'field_nome', 'field_cognome', 'field_informazioni', 'field_immagine',
        'field_localizzazione', 'field_periodo', 'field_links',
        'field_anno_di_nascita', 'field_anno_di_morte', 'field_visualizzazioni_totali',
        'field_autori_correlati',
      ].join(','),
      'fields[taxonomy_term--localizzazioni]': 'name,path',
      'fields[taxonomy_term--periodi]': 'name,path',
      'fields[media--image]': 'field_media_image',
      'fields[file--file]': 'uri',
      'include': 'field_localizzazione,field_periodo,field_immagine,field_immagine.field_media_image,field_autori_correlati',
      'page[limit]': '200',
    })).then(toStore);
    triggerWarmAll();
  }
  return autoriPromise;
}

export function fetchAllEventiRaw(): Promise<RawStore> {
  if (!eventiPromise) {
    eventiPromise = fetchAllJsonApi('/jsonapi/node/evento', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--evento]': [
        'drupal_internal__nid', 'title', 'path', 'created', 'changed',
        'field_data_evento', 'field_informazioni', 'field_immagine',
        'field_geofield', 'field_localizzazione', 'field_periodo',
        'field_tags', 'field_tematiche', 'field_canti_correlati', 'field_links',
        'field_visualizzazioni_totali',
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
    })).then(toStore);
    triggerWarmAll();
  }
  return eventiPromise;
}

export function fetchAllTraduzioniRaw(): Promise<RawStore> {
  if (!traduzioniPromise) {
    traduzioniPromise = fetchAllJsonApi('/jsonapi/node/traduzione', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--traduzione]': [
        'drupal_internal__nid', 'title', 'path', 'created', 'changed',
        'field_canto_testo', 'field_informazioni', 'field_lingua',
        'field_canti_correlati', 'field_visualizzazioni_totali',
      ].join(','),
      'fields[node--canto]': 'drupal_internal__nid,title,path,field_lingua',
      'fields[taxonomy_term--lingue]': 'name,path',
      'include': 'field_lingua,field_canti_correlati,field_canti_correlati.field_lingua',
      'page[limit]': '200',
    })).then(toStore);
    triggerWarmAll();
  }
  return traduzioniPromise;
}

export function fetchAllPagineRaw(): Promise<RawStore> {
  if (!paginePromise) {
    paginePromise = fetchAllJsonApi('/jsonapi/node/pagina', new URLSearchParams({
      'filter[status]': '1',
      'fields[node--pagina]': 'drupal_internal__nid,title,path,field_descrizione_header,field_immagine,field_paragraphs',
      'fields[paragraph--testo]': 'field_testo',
      'fields[paragraph--citazione]': 'field_testo,field_fonte',
      'fields[paragraph--immagine]': 'field_immagine,field_descrizione_immagine',
      'fields[paragraph--card]': 'field_titolo,field_testo,field_link',
      'fields[paragraph--griglia]': 'field_colonne,field_grid_item',
      'fields[media--image]': 'field_media_image',
      'fields[file--file]': 'uri',
      // Drupal JSON:API non supporta include > 2 livelli su entity_reference_revisions:
      // field_paragraphs.field_grid_item.field_immagine causerebbe 400.
      // Le immagini nei grid item vengono risolte via fetchAllImmagineParaGraphsRaw().
      'include': [
        'field_immagine',
        'field_immagine.field_media_image',
        'field_paragraphs',
        'field_paragraphs.field_immagine',
        'field_paragraphs.field_immagine.field_media_image',
        'field_paragraphs.field_grid_item',
      ].join(','),
      'page[limit]': '200',
    })).then(toStore);
    triggerWarmAll();
  }
  return paginePromise;
}

export function fetchAllLingueRaw(): Promise<RawStore> {
  if (!linguePromise) {
    linguePromise = fetchAllJsonApi('/jsonapi/taxonomy_term/lingue', new URLSearchParams({
      'fields[taxonomy_term--lingue]': 'drupal_internal__tid,name,path',
      'page[limit]': '200',
    })).then(toStore);
    triggerWarmAll();
  }
  return linguePromise;
}

export function fetchAllLocalizzazioniRaw(): Promise<RawStore> {
  if (!localizzazioniPromise) {
    localizzazioniPromise = fetchAllJsonApi('/jsonapi/taxonomy_term/localizzazioni', new URLSearchParams({
      'fields[taxonomy_term--localizzazioni]': 'drupal_internal__tid,name,path',
      'page[limit]': '200',
    })).then(toStore);
    triggerWarmAll();
  }
  return localizzazioniPromise;
}

export function fetchAllPeriodiRaw(): Promise<RawStore> {
  if (!periodiPromise) {
    periodiPromise = fetchAllJsonApi('/jsonapi/taxonomy_term/periodi', new URLSearchParams({
      'fields[taxonomy_term--periodi]': 'drupal_internal__tid,name,path,weight,field_immagine,description',
      'fields[media--image]': 'field_media_image',
      'fields[file--file]': 'uri',
      'include': 'field_immagine,field_immagine.field_media_image',
      // Tiebreaker su tid: a parità di weight MariaDB restituisce ordine
      // arbitrario → l'HTML cambierebbe a ogni build (cache .br/.gz sempre
      // fredda). Stesso problema del tiebreaker nid in pdf-runner.js.
      'sort': 'weight,drupal_internal__tid',
      'page[limit]': '200',
    })).then(toStore);
    triggerWarmAll();
  }
  return periodiPromise;
}

export function fetchAllMediaHeaderRaw(): Promise<RawStore> {
  if (!mediaHeaderPromise) {
    mediaHeaderPromise = fetchAllJsonApi('/jsonapi/media/image', new URLSearchParams({
      'filter[status]': '1',
      'filter[field_header]': '1',
      'fields[media--image]': 'field_media_image',
      'fields[file--file]': 'uri',
      'include': 'field_media_image',
      'page[limit]': '200',
    })).then(toStore);
    triggerWarmAll();
  }
  return mediaHeaderPromise;
}

export function fetchAllTagsRaw(): Promise<RawStore> {
  if (!tagsPromise) {
    tagsPromise = fetchAllJsonApi('/jsonapi/taxonomy_term/tags', new URLSearchParams({
      'fields[taxonomy_term--tags]': 'drupal_internal__tid,name,path,field_immagine',
      'fields[media--image]': 'field_media_image',
      'fields[file--file]': 'uri',
      'include': 'field_immagine,field_immagine.field_media_image',
      'page[limit]': '200',
    })).then(toStore);
    triggerWarmAll();
  }
  return tagsPromise;
}

let immagineParaGraphsPromise: Promise<RawStore> | null = null;

export function fetchAllImmagineParaGraphsRaw(): Promise<RawStore> {
  if (!immagineParaGraphsPromise) {
    immagineParaGraphsPromise = fetchAllJsonApi('/jsonapi/paragraph/immagine', new URLSearchParams({
      'fields[paragraph--immagine]': 'field_immagine,field_descrizione_immagine',
      'fields[media--image]': 'field_media_image',
      'fields[file--file]': 'uri',
      'include': 'field_immagine,field_immagine.field_media_image',
      'page[limit]': '200',
    })).then(toStore);
  }
  return immagineParaGraphsPromise;
}

export function warmAll(): Promise<RawStore[]> {
  return Promise.all([
    fetchAllCantiRaw(),
    fetchAllAutoriRaw(),
    fetchAllEventiRaw(),
    fetchAllTraduzioniRaw(),
    fetchAllPagineRaw(),
    fetchAllLingueRaw(),
    fetchAllLocalizzazioniRaw(),
    fetchAllPeriodiRaw(),
    fetchAllTagsRaw(),
  ]);
}
