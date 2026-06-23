import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

async function fetchAllDrupalJsonApi(path, params = {}) {
  const baseUrl = process.env.DRUPAL_API_URL;
  if (!baseUrl) throw new Error('DRUPAL_API_URL deve essere definito');

  const allData = [];
  const allIncluded = [];
  const seenIncluded = new Set();

  const url = new URL(path, baseUrl);
  for (const [key, value] of Object.entries(params)) {
    url.searchParams.set(key, value);
  }

  let nextUrl = url.toString();

  while (nextUrl) {
    const res = await fetch(nextUrl, {
      headers: { Accept: 'application/vnd.api+json' },
    });
    if (!res.ok) throw new Error(`Drupal JSON:API fetch fallito: ${res.status}`);

    const json = await res.json();
    const items = Array.isArray(json.data) ? json.data : [json.data];
    allData.push(...items);

    if (json.included) {
      for (const item of json.included) {
        const key = `${item.type}:${item.id}`;
        if (!seenIncluded.has(key)) {
          seenIncluded.add(key);
          allIncluded.push(item);
        }
      }
    }

    nextUrl = json.links?.next?.href || null;
  }

  return { data: allData, included: allIncluded };
}

function buildIncludedMap(included = []) {
  const map = new Map();
  for (const item of included) {
    map.set(`${item.type}:${item.id}`, item);
  }
  return map;
}

function resolveNames(rel, includedMap) {
  const refs = Array.isArray(rel?.data) ? rel.data : rel?.data ? [rel.data] : [];
  return refs
    .map((ref) => includedMap.get(`${ref.type}:${ref.id}`))
    .filter(Boolean)
    .map((item) => item.attributes.title ?? item.attributes.name);
}

function extractSlug(alias) {
  if (!alias) return '';
  return alias.split('/').pop() ?? '';
}

async function getAllCantiFull() {
  const { data, included } = await fetchAllDrupalJsonApi('/jsonapi/node/canto', {
    'filter[status]': '1',
    'fields[node--canto]': 'title,path,field_anno,field_canto_testo,field_informazioni,field_autori_testo,field_lingua,field_periodo,field_tags',
    'fields[node--autore]': 'title',
    'fields[taxonomy_term--lingue]': 'name',
    'fields[taxonomy_term--periodi]': 'name',
    'fields[taxonomy_term--tags]': 'name',
    'include': 'field_autori_testo,field_lingua,field_periodo,field_tags',
    'sort': 'title',
    'page[limit]': '50',
  });

  const includedMap = buildIncludedMap(included);

  return data.map((item) => {
    const a = item.attributes;
    const r = item.relationships;
    return {
      titolo: a.title,
      slug: extractSlug(a.path?.alias),
      anno: a.field_anno,
      testo: a.field_canto_testo ?? '',
      informazioni: a.field_informazioni?.processed ?? a.field_informazioni?.value ?? null,
      autoriTesto: resolveNames(r.field_autori_testo, includedMap),
      periodo: resolveNames(r.field_periodo, includedMap)[0] || '',
      lingue: resolveNames(r.field_lingua, includedMap),
      tags: resolveNames(r.field_tags, includedMap),
    };
  });
}

export default function pdfGeneratorIntegration() {
  return {
    name: 'pdf-generator',
    hooks: {
      'astro:build:done': async ({ dir, logger }) => {
        const { generateCantoPdf } = await import('../lib/generate-pdf.js');

        logger.info('Recupero canti da Drupal...');
        const canti = await getAllCantiFull();
        logger.info(`${canti.length} canti trovati. Generazione PDF...`);

        const outDir = join(fileURLToPath(dir), 'pdf', 'canti');
        mkdirSync(outDir, { recursive: true });

        let count = 0;
        for (const canto of canti) {
          try {
            const pdfBuffer = await generateCantoPdf(canto, {
              autoriTesto: canto.autoriTesto,
              periodo: canto.periodo,
              lingue: canto.lingue,
              tags: canto.tags,
            });
            writeFileSync(join(outDir, `${canto.slug}.pdf`), pdfBuffer);
            count++;
          } catch (err) {
            logger.warn(`Errore PDF per "${canto.titolo}" (${canto.slug}): ${err.message}`);
          }
        }

        logger.info(`${count}/${canti.length} PDF generati in ${outDir}`);
      },
    },
  };
}
