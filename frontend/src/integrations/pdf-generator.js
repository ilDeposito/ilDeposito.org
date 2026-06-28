import { mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Worker } from 'node:worker_threads';
import { availableParallelism } from 'node:os';

async function fetchAllPaginated(path, params = {}) {
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
    if (!res.ok) throw new Error(`JSON:API fetch fallito: ${res.status}`);

    const json = await res.json();
    allData.push(...(Array.isArray(json.data) ? json.data : [json.data]));

    for (const item of (json.included ?? [])) {
      const key = `${item.type}:${item.id}`;
      if (!seenIncluded.has(key)) {
        seenIncluded.add(key);
        allIncluded.push(item);
      }
    }
    nextUrl = json.links?.next?.href || null;
  }

  return { data: allData, included: allIncluded };
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

async function getAllCantiForPdf() {
  const { data, included } = await fetchAllPaginated('/jsonapi/node/canto', {
    'filter[status]': '1',
    'fields[node--canto]': 'title,path,field_anno,field_canto_testo,field_informazioni,field_autori_testo,field_lingua,field_periodo,field_tags',
    'fields[node--autore]': 'title',
    'fields[taxonomy_term--lingue]': 'name',
    'fields[taxonomy_term--periodi]': 'name',
    'fields[taxonomy_term--tags]': 'name',
    'include': 'field_autori_testo,field_lingua,field_periodo,field_tags',
    'sort': 'title',
    'page[limit]': '200',
  });

  const includedMap = new Map();
  for (const item of included) {
    includedMap.set(`${item.type}:${item.id}`, item);
  }

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

function spawnWorker(workerFile, canti, outDir) {
  return new Promise((resolve, reject) => {
    const worker = new Worker(workerFile, {
      workerData: { canti, outDir },
    });

    let result = { count: 0, errors: 0, errorMessages: [] };

    worker.on('message', (msg) => {
      if (msg.type === 'done') {
        result.count = msg.count;
        result.errors = msg.errors;
      } else if (msg.type === 'error') {
        result.errorMessages.push(msg.message);
      }
    });

    worker.on('error', reject);
    worker.on('exit', (code) => {
      if (code !== 0) reject(new Error(`Worker exit code ${code}`));
      else resolve(result);
    });
  });
}

export default function pdfGeneratorIntegration() {
  return {
    name: 'pdf-generator',
    hooks: {
      'astro:build:done': async ({ dir, logger }) => {
        logger.info('Recupero canti da Drupal...');
        const canti = await getAllCantiForPdf();
        logger.info(`${canti.length} canti trovati.`);

        const outDir = join(fileURLToPath(dir), 'pdf', 'canti');
        mkdirSync(outDir, { recursive: true });

        const numWorkers = Math.min(availableParallelism(), 8);
        const chunkSize = Math.ceil(canti.length / numWorkers);
        const workerFile = join(dirname(fileURLToPath(import.meta.url)), 'pdf-worker.js');

        logger.info(`Generazione PDF con ${numWorkers} worker thread...`);

        const promises = [];
        for (let i = 0; i < numWorkers; i++) {
          const chunk = canti.slice(i * chunkSize, (i + 1) * chunkSize);
          if (chunk.length === 0) continue;
          promises.push(spawnWorker(workerFile, chunk, outDir));
        }

        const results = await Promise.all(promises);
        const totalCount = results.reduce((s, r) => s + r.count, 0);
        const totalErrors = results.reduce((s, r) => s + r.errors, 0);

        for (const r of results) {
          for (const msg of r.errorMessages) {
            logger.warn(`ERRORE PDF: ${msg}`);
          }
        }

        logger.info(`${totalCount}/${canti.length} PDF generati (${totalErrors} errori) in ${outDir}`);
      },
    },
  };
}
