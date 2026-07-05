#!/usr/bin/env node
// Rigenera solo i PDF dei canti, senza rebuild dei contenuti Astro. Pensato
// per aggiornare in-place la release già live (client/pdf/canti/) tramite
// ./ildeposito.sh build-frontend-pdf — vedi docker-entrypoint.sh (modalità "pdf").
//
// Env richieste: DRUPAL_API_URL, PDF_OUT_DIR (directory di output dei PDF).
// Env opzionale: PDF_CACHE_DIR (cache incrementale, altrimenti .cache/pdf locale).
import { runPdfGeneration } from '../src/integrations/pdf-runner.js';

const outDir = process.env.PDF_OUT_DIR;
if (!outDir) {
  console.error('PDF_OUT_DIR deve essere definito (directory di output dei PDF).');
  process.exit(1);
}

const logger = {
  info: (msg) => console.log(`→ ${msg}`),
  warn: (msg) => console.warn(`⚠ ${msg}`),
};

const result = await runPdfGeneration({
  outDir,
  cacheDir: process.env.PDF_CACHE_DIR,
  logger,
});

if (result.errors > 0) {
  console.error(`✗ ${result.errors} PDF falliti.`);
  process.exit(1);
}

console.log(`✓ PDF aggiornati in ${outDir}.`);
