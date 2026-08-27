// Dati dedicati al canzoniere per NotebookLM. Questo modulo e' volutamente
// separato da canzonieri-runner.js: il canzoniere pubblico non cambia neppure
// quando vengono aggiunti nuovi campi a questa esportazione locale.
import { fetchAllPaginated, includedMapOf, extractSlug } from './jsonapi-fetch.js';

function refs(rel) {
  const data = rel?.data;
  return Array.isArray(data) ? data : data ? [data] : [];
}

function names(rel, included) {
  return refs(rel)
    .map((ref) => included.get(`${ref.type}:${ref.id}`))
    .filter(Boolean)
    .map((item) => item.attributes.title ?? item.attributes.name)
    .filter(Boolean);
}

function textValue(value) {
  return value?.processed ?? value?.value ?? value ?? null;
}

async function fetchCanti() {
  const { data, included } = await fetchAllPaginated('/jsonapi/node/canto', {
    'filter[status]': '1',
    'fields[node--canto]': 'drupal_internal__nid,title,path,field_anno,field_canto_testo,field_informazioni,field_fonte,field_autori_testo,field_autori_musica,field_lingua,field_periodo,field_tags,field_tematiche',
    'fields[node--autore]': 'title,path',
    'fields[taxonomy_term--lingue]': 'name',
    'fields[taxonomy_term--periodi]': 'name,weight',
    'fields[taxonomy_term--tags]': 'name',
    'fields[taxonomy_term--tematiche]': 'name',
    'include': 'field_autori_testo,field_autori_musica,field_lingua,field_periodo,field_tags,field_tematiche',
    'sort': 'title,drupal_internal__nid',
    'page[limit]': '200',
  });
  const map = includedMapOf(included);

  return data.map((item) => {
    const a = item.attributes;
    const r = item.relationships;
    const period = refs(r.field_periodo)[0];
    const periodItem = period ? map.get(`${period.type}:${period.id}`) : null;
    return {
      id: a.drupal_internal__nid,
      titolo: a.title?.trim() ?? '',
      slug: extractSlug(a.path?.alias),
      anno: a.field_anno,
      testo: a.field_canto_testo ?? '',
      informazioni: textValue(a.field_informazioni),
      fonte: textValue(a.field_fonte),
      autoriTesto: names(r.field_autori_testo, map),
      autoriMusica: names(r.field_autori_musica, map),
      lingue: names(r.field_lingua, map),
      tags: names(r.field_tags, map),
      tematiche: names(r.field_tematiche, map),
      periodo: periodItem ? { id: period.id, titolo: periodItem.attributes.name, sort: periodItem.attributes.weight ?? 0 } : null,
    };
  });
}

async function fetchEventiByCanto() {
  const { data, included } = await fetchAllPaginated('/jsonapi/node/evento', {
    'filter[status]': '1',
    'fields[node--evento]': 'title,path,field_data_evento,field_informazioni,field_localizzazione,field_canti_correlati',
    'fields[taxonomy_term--localizzazioni]': 'name',
    'include': 'field_localizzazione,field_canti_correlati',
    'sort': 'field_data_evento,title',
    'page[limit]': '200',
  });
  const map = includedMapOf(included);
  const byCanto = new Map();

  for (const item of data) {
    const a = item.attributes;
    const evento = {
      titolo: a.title?.trim() ?? '',
      slug: extractSlug(a.path?.alias),
      data: a.field_data_evento ?? null,
      localizzazioni: names(item.relationships.field_localizzazione, map),
      descrizione: textValue(a.field_informazioni),
    };
    for (const ref of refs(item.relationships.field_canti_correlati)) {
      const id = ref.meta?.drupal_internal__target_id;
      if (id == null) continue;
      const list = byCanto.get(Number(id)) ?? [];
      list.push(evento);
      byCanto.set(Number(id), list);
    }
  }
  return byCanto;
}

function assertSignificantTitles(canti) {
  const invalid = canti.filter((canto) =>
    canto.titolo.length < 2 || /^(senza titolo|untitled|n\/?a|test)$/i.test(canto.titolo)
  );
  if (invalid.length > 0) {
    throw new Error(`Titoli non significativi: ${invalid.map((c) => c.id).join(', ')}`);
  }
}

export async function getDatiCanzoniereNotebook() {
  const [canti, eventiByCanto] = await Promise.all([fetchCanti(), fetchEventiByCanto()]);
  assertSignificantTitles(canti);
  return canti.map((canto) => ({ ...canto, eventi: eventiByCanto.get(Number(canto.id)) ?? [] }));
}
