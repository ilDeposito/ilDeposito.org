import { mkdirSync, existsSync, copyFileSync, linkSync, rmSync, readFileSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { Worker } from 'node:worker_threads';
import { availableParallelism } from 'node:os';
import QRCode from 'qrcode';
import { withUtm } from '../lib/utm.js';
import { fetchAllPaginated, includedMapOf, resolveNames, extractSlug } from './jsonapi-fetch.js';

async function getAllCantiForPdf() {
  const { data, included } = await fetchAllPaginated('/jsonapi/node/canto', {
    'filter[status]': '1',
    'fields[node--canto]': 'title,path,field_anno,field_canto_testo,field_informazioni,field_autori_testo,field_lingua,field_periodo,field_tags',
    'fields[node--autore]': 'title',
    'fields[taxonomy_term--lingue]': 'name',
    'fields[taxonomy_term--periodi]': 'name',
    'fields[taxonomy_term--tags]': 'name',
    'include': 'field_autori_testo,field_lingua,field_periodo,field_tags',
    // Tiebreaker su nid: "title" da solo non è univoco (10+ coppie di canti
    // omonimi in archivio) e la paginazione concorrente può far "cadere" uno
    // dei due tra un offset e l'altro, saltando la generazione del suo PDF.
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
      informazioni: a.field_informazioni?.processed ?? a.field_informazioni?.value ?? null,
      autoriTesto: resolveNames(r.field_autori_testo, includedMap),
      periodo: resolveNames(r.field_periodo, includedMap)[0] || '',
      lingue: resolveNames(r.field_lingua, includedMap),
      tags: resolveNames(r.field_tags, includedMap),
    };
  });
}

function computeHash(canto) {
  return createHash('md5').update(JSON.stringify({
    titolo: canto.titolo,
    testo: canto.testo,
    anno: canto.anno,
    informazioni: canto.informazioni,
    autoriTesto: canto.autoriTesto,
    periodo: canto.periodo,
    lingue: canto.lingue,
    tags: canto.tags,
  })).digest('hex');
}

async function generateQrBuffers(canti) {
  const BATCH_SIZE = 50;
  const results = new Map();

  for (let i = 0; i < canti.length; i += BATCH_SIZE) {
    const batch = canti.slice(i, i + BATCH_SIZE);
    const buffers = await Promise.all(
      batch.map((c) => {
        const qrUrl = withUtm(`https://www.ildeposito.org/canti/${c.slug}`, {
          source: 'pdf_canto',
          medium: 'qr_code',
          campaign: 'pdf',
        });
        return QRCode.toBuffer(qrUrl, {
          width: 100,
          errorCorrectionLevel: 'M',
          margin: 1,
          color: { dark: '#000000', light: '#ffffff' },
        });
      })
    );
    for (let j = 0; j < batch.length; j++) {
      results.set(batch[j].slug, buffers[j]);
    }
  }

  return results;
}

// Cache e outDir stanno sullo stesso volume (frontend_output): hardlink
// invece di copia, così le release condividono gli inode dei PDF invariati.
// La rimozione preventiva mantiene intatto l'inode precedente per le release
// che ancora lo referenziano. Fallback a copia su filesystem diversi (EXDEV).
function linkOrCopy(src, dest) {
  rmSync(dest, { force: true });
  try {
    linkSync(src, dest);
  } catch {
    copyFileSync(src, dest);
  }
}

function readFontBuffers() {
  const __dirname = dirname(fileURLToPath(import.meta.url));
  const fontsDir = join(__dirname, '../assets/fonts');
  return {
    'SourceSans':        readFileSync(join(fontsDir, 'SourceSans3-Medium.ttf')),
    'SourceSans-Italic': readFileSync(join(fontsDir, 'SourceSans3-Italic.ttf')),
    'Bitter':            readFileSync(join(fontsDir, 'Bitter.ttf')),
    'IBMPlexMono':       readFileSync(join(fontsDir, 'IBMPlexMono-Regular.ttf')),
  };
}

function spawnWorker(workerFile, canti, outDir, fontBuffers) {
  return new Promise((resolve, reject) => {
    const worker = new Worker(workerFile, {
      workerData: { canti, outDir, fontBuffers },
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

// Rigenera i PDF dei canti in outDir, riusando la cache in cacheDir (manifest
// hash per canto) per rigenerare solo quelli cambiati. Condiviso tra
// l'integration Astro (hook astro:build:done) e lo script standalone
// scripts/generate-pdfs.mjs (rigenerazione PDF senza rebuild dei contenuti).
export async function runPdfGeneration({ outDir, cacheDir: cacheDirInput, logger }) {
  mkdirSync(outDir, { recursive: true });

  let cacheDir = null;
  let manifestPath = null;
  let manifest = {};

  try {
    // Nel builder Docker process.cwd() è filesystem effimero (compose run
    // --rm): PDF_CACHE_DIR punta al volume frontend_output così il
    // manifest sopravvive tra le build e si rigenerano solo i PDF cambiati.
    cacheDir = cacheDirInput || join(process.cwd(), '.cache', 'pdf');
    mkdirSync(cacheDir, { recursive: true });
    manifestPath = join(cacheDir, 'manifest.json');
    if (existsSync(manifestPath)) {
      manifest = JSON.parse(readFileSync(manifestPath, 'utf-8'));
    }
  } catch {
    cacheDir = null;
    manifestPath = null;
    manifest = {};
  }

  logger.info('Recupero canti per PDF...');
  const canti = await getAllCantiForPdf();
  logger.info(`${canti.length} canti trovati.`);

  const newManifest = {};
  const changedCanti = [];
  let cachedCount = 0;

  for (const canto of canti) {
    const hash = computeHash(canto);
    newManifest[canto.slug] = hash;

    if (cacheDir && manifest[canto.slug] === hash) {
      const cachedPdf = join(cacheDir, `${canto.slug}.pdf`);
      if (existsSync(cachedPdf)) {
        linkOrCopy(cachedPdf, join(outDir, `${canto.slug}.pdf`));
        cachedCount++;
        continue;
      }
    }
    // In-place (mode pdf): il file esistente può essere un hardlink condiviso
    // con cache e release precedenti — writeFile (O_TRUNC) riscriverebbe
    // l'inode condiviso, quindi va rimosso per far creare un inode nuovo.
    rmSync(join(outDir, `${canto.slug}.pdf`), { force: true });
    changedCanti.push(canto);
  }

  if (cachedCount > 0) {
    logger.info(`${cachedCount} PDF invariati copiati dalla cache.`);
  }

  if (changedCanti.length === 0) {
    logger.info('Nessun PDF da rigenerare.');
    if (manifestPath) {
      try { writeFileSync(manifestPath, JSON.stringify(newManifest, null, 2)); } catch {}
    }
    return { total: canti.length, generated: 0, cached: cachedCount, errors: 0 };
  }

  logger.info(`${changedCanti.length} PDF da generare...`);

  const qrMap = await generateQrBuffers(changedCanti);

  const cantiWithQr = changedCanti.map((c) => ({
    ...c,
    qrBuffer: qrMap.get(c.slug),
  }));

  const fontBuffers = readFontBuffers();

  const numWorkers = Math.min(availableParallelism(), 8, changedCanti.length);
  const chunkSize = Math.ceil(cantiWithQr.length / numWorkers);
  const workerFile = join(dirname(fileURLToPath(import.meta.url)), 'pdf-worker.js');

  logger.info(`Generazione con ${numWorkers} worker thread...`);

  const promises = [];
  for (let i = 0; i < numWorkers; i++) {
    const chunk = cantiWithQr.slice(i * chunkSize, (i + 1) * chunkSize);
    if (chunk.length === 0) continue;
    promises.push(spawnWorker(workerFile, chunk, outDir, fontBuffers));
  }

  const results = await Promise.all(promises);
  const totalCount = results.reduce((s, r) => s + r.count, 0);
  const totalErrors = results.reduce((s, r) => s + r.errors, 0);

  for (const r of results) {
    for (const msg of r.errorMessages) {
      logger.warn(`ERRORE PDF: ${msg}`);
    }
  }

  if (cacheDir) {
    try {
      for (const canto of changedCanti) {
        const pdfPath = join(outDir, `${canto.slug}.pdf`);
        if (existsSync(pdfPath)) {
          linkOrCopy(pdfPath, join(cacheDir, `${canto.slug}.pdf`));
        }
      }
      if (manifestPath) {
        writeFileSync(manifestPath, JSON.stringify(newManifest, null, 2));
      }
    } catch {}
  }

  logger.info(`${totalCount + cachedCount}/${canti.length} PDF totali (${totalCount} generati, ${cachedCount} da cache, ${totalErrors} errori)`);

  return { total: canti.length, generated: totalCount, cached: cachedCount, errors: totalErrors };
}
