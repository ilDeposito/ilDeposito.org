import { mkdirSync, existsSync, copyFileSync, linkSync, rmSync, readFileSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { Worker } from 'node:worker_threads';
import { availableParallelism } from 'node:os';
import QRCode from 'qrcode';
import { withUtm } from '../lib/utm.js';

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

// Stessa strategia di src/lib/api/drupal/client.ts (che non possiamo importare
// qui: questo modulo gira fuori dalla pipeline Vite): Drupal cappa
// page[limit] a 50, quindi il passo si legge dal link next e le pagine
// successive si scaricano a ondate parallele su page[offset].
const PAGE_CONCURRENCY = 4;

async function fetchAllPaginated(path, params = {}) {
  const baseUrl = process.env.DRUPAL_API_URL;
  if (!baseUrl) throw new Error('DRUPAL_API_URL deve essere definito');

  const allData = [];
  const allIncluded = [];
  const seenIncluded = new Set();

  const append = (json) => {
    allData.push(...(Array.isArray(json.data) ? json.data : [json.data]));
    for (const item of (json.included ?? [])) {
      const key = `${item.type}:${item.id}`;
      if (!seenIncluded.has(key)) {
        seenIncluded.add(key);
        allIncluded.push(item);
      }
    }
  };

  const fetchPage = async (offset) => {
    const url = new URL(path, baseUrl);
    for (const [key, value] of Object.entries(params)) {
      url.searchParams.set(key, value);
    }
    if (offset > 0) url.searchParams.set('page[offset]', String(offset));

    const res = await fetch(url.toString(), {
      headers: { Accept: 'application/vnd.api+json' },
    });
    if (!res.ok) throw new Error(`JSON:API fetch fallito: ${res.status}`);
    return res.json();
  };

  const first = await fetchPage(0);
  append(first);

  const nextHref = first.links?.next?.href;
  if (!nextHref) return { data: allData, included: allIncluded };

  const step = Number(new URL(nextHref).searchParams.get('page[offset]'));
  if (!Number.isFinite(step) || step <= 0) {
    // Link next non basato su offset: fallback sequenziale.
    let href = nextHref;
    while (href) {
      const res = await fetch(href, { headers: { Accept: 'application/vnd.api+json' } });
      if (!res.ok) throw new Error(`JSON:API fetch fallito: ${res.status}`);
      const json = await res.json();
      append(json);
      href = json.links?.next?.href || null;
    }
    return { data: allData, included: allIncluded };
  }

  let offset = step;
  let done = false;
  while (!done) {
    const wave = [];
    for (let i = 0; i < PAGE_CONCURRENCY; i++) {
      wave.push(fetchPage(offset + i * step));
    }
    offset += PAGE_CONCURRENCY * step;

    for (const json of await Promise.all(wave)) {
      append(json);
      if (!json.links?.next?.href) done = true;
    }
  }

  return { data: allData, included: allIncluded };
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
    // Tiebreaker su nid: "title" da solo non è univoco (10+ coppie di canti
    // omonimi in archivio) e la paginazione concorrente può far "cadere" uno
    // dei due tra un offset e l'altro, saltando la generazione del suo PDF.
    'sort': 'title,drupal_internal__nid',
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
