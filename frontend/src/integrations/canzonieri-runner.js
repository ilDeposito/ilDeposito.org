// Fetch dati per i canzonieri collettivi (PDF multi-canto): estende
// getAllCantiForPdf (pdf-runner.js) con accordi, autori musica, e i dati di
// periodi/autori (peso, immagine) necessari per copertine e pagine
// divisorie. Gira fuori dalla pipeline Vite (stesso vincolo di pdf-runner.js),
// quindi niente import da src/lib/api/drupal/*.
import { fetchAllPaginated, includedMapOf, extractSlug } from './jsonapi-fetch.js';

function resolveRefIds(rel) {
  const refs = Array.isArray(rel?.data) ? rel.data : rel?.data ? [rel.data] : [];
  return refs.map((ref) => ref.id);
}

// Diversi campi (field_periodo, field_immagine, ...) hanno cardinalità
// multipla lato Drupal: JSON:API ritorna sempre "data" come array anche con
// un solo valore. Per i campi usati qui come "singoli" si prende il primo,
// stesso criterio già usato altrove (pdf-runner.js: resolveNames(...)[0]).
function firstRef(rel) {
  const data = rel?.data;
  return Array.isArray(data) ? data[0] ?? null : data ?? null;
}

function resolveSingleRefId(rel) {
  return firstRef(rel)?.id ?? null;
}

function resolveNames(rel, includedMap) {
  const refs = Array.isArray(rel?.data) ? rel.data : rel?.data ? [rel.data] : [];
  return refs
    .map((ref) => includedMap.get(`${ref.type}:${ref.id}`))
    .filter(Boolean)
    .map((item) => item.attributes.title ?? item.attributes.name);
}

function resolveImageUrl(rel, includedMap) {
  const mediaRef = firstRef(rel);
  if (!mediaRef) return null;
  const media = includedMap.get(`${mediaRef.type}:${mediaRef.id}`);
  if (!media) return null;
  const fileRef = firstRef(media.relationships?.field_media_image);
  if (!fileRef) return null;
  const file = includedMap.get(`${fileRef.type}:${fileRef.id}`);
  const relativeUrl = file?.attributes?.uri?.url ?? null;
  if (!relativeUrl) return null;
  return new URL(relativeUrl, process.env.DRUPAL_API_URL).toString();
}

async function fetchPeriodi() {
  const { data, included } = await fetchAllPaginated('/jsonapi/taxonomy_term/periodi', {
    'fields[taxonomy_term--periodi]': 'name,path,weight,field_immagine',
    'fields[media--image]': 'field_media_image',
    'fields[file--file]': 'uri',
    'include': 'field_immagine,field_immagine.field_media_image',
    'sort': 'weight,drupal_internal__tid',
    'page[limit]': '200',
  });
  const includedMap = includedMapOf(included);

  const byId = new Map();
  const list = data.map((item) => {
    const periodo = {
      id: item.id,
      titolo: item.attributes.name,
      slug: extractSlug(item.attributes.path?.alias),
      sort: item.attributes.weight ?? 0,
      immagine: resolveImageUrl(item.relationships?.field_immagine, includedMap),
    };
    byId.set(item.id, periodo);
    return periodo;
  });

  return { list, byId };
}

async function fetchAutoriMeta() {
  const { data, included } = await fetchAllPaginated('/jsonapi/node/autore', {
    'filter[status]': '1',
    'fields[node--autore]': 'title,path,field_immagine',
    'fields[media--image]': 'field_media_image',
    'fields[file--file]': 'uri',
    'include': 'field_immagine,field_immagine.field_media_image',
    'page[limit]': '200',
  });
  const includedMap = includedMapOf(included);

  const byId = new Map();
  for (const item of data) {
    byId.set(item.id, {
      id: item.id,
      titolo: item.attributes.title,
      slug: extractSlug(item.attributes.path?.alias),
      immagine: resolveImageUrl(item.relationships?.field_immagine, includedMap),
    });
  }
  return byId;
}

async function fetchCanti() {
  const { data, included } = await fetchAllPaginated('/jsonapi/node/canto', {
    'filter[status]': '1',
    'fields[node--canto]': 'title,path,field_anno,field_canto_testo,field_canto_accordi,field_informazioni,field_autori_testo,field_autori_musica,field_lingua,field_periodo,field_tags',
    'fields[taxonomy_term--lingue]': 'name',
    'fields[taxonomy_term--tags]': 'name',
    'include': 'field_lingua,field_tags',
    // Tiebreaker su nid: vedi pdf-runner.js, stesso motivo (titoli non univoci).
    'sort': 'title,drupal_internal__nid',
    'page[limit]': '200',
  });
  const includedMap = includedMapOf(included);

  return data.map((item) => {
    const a = item.attributes;
    const r = item.relationships;
    return {
      titolo: a.title,
      slug: extractSlug(a.path?.alias),
      anno: a.field_anno,
      testo: a.field_canto_testo ?? '',
      accordi: a.field_canto_accordi ?? null,
      informazioni: a.field_informazioni?.processed ?? a.field_informazioni?.value ?? null,
      autoriRefs: [...new Set([...resolveRefIds(r.field_autori_testo), ...resolveRefIds(r.field_autori_musica)])],
      periodoRef: resolveSingleRefId(r.field_periodo),
      lingue: resolveNames(r.field_lingua, includedMap),
      tags: resolveNames(r.field_tags, includedMap),
    };
  });
}

// Autori idonei a un canzoniere proprio: più di questo numero di canti
// (testo o musica), come deciso con l'utente.
const AUTORE_MIN_CANTI = 10;

// Ritorna i dati completi per i 4 canzonieri: canti risolti (con periodo e
// autori come oggetti, non più solo riferimenti), periodi ordinati per peso,
// e autori idonei con i relativi canti.
export async function getDatiCanzonieri() {
  const [canti, { list: periodi, byId: periodoById }, autoreById] = await Promise.all([
    fetchCanti(),
    fetchPeriodi(),
    fetchAutoriMeta(),
  ]);

  const cantiByAutore = new Map();

  const cantiRisolti = canti.map((canto) => {
    const periodo = canto.periodoRef ? periodoById.get(canto.periodoRef) ?? null : null;
    const autori = canto.autoriRefs.map((id) => autoreById.get(id)).filter(Boolean);

    const risolto = {
      titolo: canto.titolo,
      slug: canto.slug,
      anno: canto.anno,
      testo: canto.testo,
      accordi: canto.accordi,
      informazioni: canto.informazioni,
      autori,
      periodo,
      lingue: canto.lingue,
      tags: canto.tags,
    };

    for (const autore of autori) {
      if (!cantiByAutore.has(autore.id)) cantiByAutore.set(autore.id, []);
      cantiByAutore.get(autore.id).push(risolto);
    }

    return risolto;
  });

  const autoriIdonei = [...cantiByAutore.entries()]
    .filter(([, cantiAutore]) => cantiAutore.length > AUTORE_MIN_CANTI)
    .map(([id, cantiAutore]) => ({ autore: autoreById.get(id), canti: cantiAutore }))
    .sort((a, b) => a.autore.titolo.localeCompare(b.autore.titolo, 'it'));

  return { canti: cantiRisolti, periodi, autoriIdonei };
}

const imageBufferCache = new Map();

export async function fetchImageBuffer(url) {
  if (!url) return null;
  if (imageBufferCache.has(url)) return imageBufferCache.get(url);
  try {
    const res = await fetch(url);
    if (!res.ok) {
      imageBufferCache.set(url, null);
      return null;
    }
    const buffer = Buffer.from(await res.arrayBuffer());
    imageBufferCache.set(url, buffer);
    return buffer;
  } catch {
    imageBufferCache.set(url, null);
    return null;
  }
}
