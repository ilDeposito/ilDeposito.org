import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

async function fetchDirectus(collection, params = {}) {
  const baseUrl = process.env.DIRECTUS_URL || process.env.PUBLIC_DIRECTUS_URL;
  const token = process.env.DIRECTUS_TOKEN;
  if (!baseUrl || !token) throw new Error('DIRECTUS_URL e DIRECTUS_TOKEN devono essere definiti');

  const url = new URL(`/items/${collection}`, baseUrl);
  for (const [key, value] of Object.entries(params)) {
    url.searchParams.set(key, value);
  }

  const res = await fetch(url.toString(), {
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
  });
  if (!res.ok) throw new Error(`Directus fetch fallito per "${collection}": ${res.status}`);
  const { data } = await res.json();
  return data;
}

async function getAllCantiFull() {
  return fetchDirectus('canti', {
    fields: [
      'id', 'titolo', 'slug', 'anno', 'testo', 'informazioni',
      'autori_testo.autori_id.titolo',
      'lingue.lingue_id.titolo',
      'periodi.periodi_id.titolo',
      'tags.tags_id.titolo',
    ].join(','),
    'filter[status][_eq]': 'published',
    limit: '-1',
    sort: 'titolo',
  });
}

export default function pdfGeneratorIntegration() {
  return {
    name: 'pdf-generator',
    hooks: {
      'astro:build:done': async ({ dir, logger }) => {
        const { generateCantoPdf } = await import('../lib/generate-pdf.js');

        logger.info('Recupero canti da Directus...');
        const canti = await getAllCantiFull();
        logger.info(`${canti.length} canti trovati. Generazione PDF...`);

        const outDir = join(fileURLToPath(dir), 'pdf', 'canti');
        mkdirSync(outDir, { recursive: true });

        let count = 0;
        for (const canto of canti) {
          const autoriTesto = (canto.autori_testo ?? [])
            .map((j) => j.autori_id?.titolo)
            .filter(Boolean);
          const periodo = (canto.periodi ?? [])
            .map((j) => j.periodi_id?.titolo)
            .filter(Boolean)[0] || '';
          const lingue = (canto.lingue ?? [])
            .map((j) => j.lingue_id?.titolo)
            .filter(Boolean);
          const tags = (canto.tags ?? [])
            .map((j) => j.tags_id?.titolo)
            .filter(Boolean);

          try {
            const pdfBuffer = await generateCantoPdf(canto, { autoriTesto, periodo, lingue, tags });
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
