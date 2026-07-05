import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runPdfGeneration } from './pdf-runner.js';

export default function pdfGeneratorIntegration() {
  return {
    name: 'pdf-generator',
    hooks: {
      'astro:build:done': async ({ dir, logger }) => {
        // SKIP_PDF=1 per la build "solo contenuti" (script build-frontend-content):
        // vedi scripts/generate-pdfs.mjs per la rigenerazione PDF standalone.
        if (process.env.SKIP_PDF === '1') {
          logger.info('SKIP_PDF=1: generazione PDF saltata.');
          return;
        }

        const outDir = join(fileURLToPath(dir), 'pdf', 'canti');
        const cacheDir = process.env.PDF_CACHE_DIR || join(process.cwd(), '.cache', 'pdf');
        await runPdfGeneration({ outDir, cacheDir, logger });
      },
    },
  };
}
