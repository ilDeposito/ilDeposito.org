import { mkdirSync } from 'node:fs';
import { writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

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

const QR_BATCH = 500;

async function generateAllQrBuffers(canti, generateQrBuffer, logger) {
  const qrMap = new Map();
  for (let i = 0; i < canti.length; i += QR_BATCH) {
    const batch = canti.slice(i, i + QR_BATCH);
    const buffers = await Promise.all(batch.map((c) => generateQrBuffer(c.slug)));
    for (let j = 0; j < batch.length; j++) {
      qrMap.set(batch[j].slug, buffers[j]);
    }
    if (i + QR_BATCH < canti.length) {
      logger.info(`  QR codes: ${Math.min(i + QR_BATCH, canti.length)}/${canti.length}`);
    }
  }
  return qrMap;
}

export default function pdfGeneratorIntegration() {
  return {
    name: 'pdf-generator',
    hooks: {
      'astro:build:done': async ({ dir, logger }) => {
        const { generateCantoPdf, generateQrBuffer } = await import('../lib/generate-pdf.js');

        logger.info('Recupero canti da Drupal...');
        const canti = await getAllCantiForPdf();
        logger.info(`${canti.length} canti trovati.`);

        logger.info('Generazione QR codes...');
        const qrMap = await generateAllQrBuffers(canti, generateQrBuffer, logger);
        logger.info(`${qrMap.size} QR codes generati.`);

        const outDir = join(fileURLToPath(dir), 'pdf', 'canti');
        mkdirSync(outDir, { recursive: true });

        logger.info('Generazione PDF...');
        let count = 0;
        let errors = 0;
        const total = canti.length;
        const BATCH_SIZE = 200;

        for (let i = 0; i < total; i += BATCH_SIZE) {
          const batch = canti.slice(i, i + BATCH_SIZE);
          const results = await Promise.allSettled(
            batch.map(async (canto) => {
              const pdfBuffer = await generateCantoPdf(canto, {
                autoriTesto: canto.autoriTesto,
                periodo: canto.periodo,
                lingue: canto.lingue,
                tags: canto.tags,
                qrBuffer: qrMap.get(canto.slug),
              });
              await writeFile(join(outDir, `${canto.slug}.pdf`), pdfBuffer);
              return canto.slug;
            })
          );
          for (const r of results) {
            if (r.status === 'fulfilled') count++;
            else { errors++; logger.warn(`ERRORE PDF: ${r.reason.message}`); }
          }
          const pct = Math.round(((i + batch.length) / total) * 100);
          logger.info(`[${i + batch.length}/${total}] (${pct}%) — ${count} ok, ${errors} errori`);
        }

        logger.info(`${count}/${total} PDF generati in ${outDir}`);
      },
    },
  };
}
