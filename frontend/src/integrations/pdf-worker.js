import { parentPort, workerData } from 'node:worker_threads';
import { writeFile } from 'node:fs/promises';
import { join } from 'node:path';

async function run() {
  const { generateCantoPdf, initFonts } = await import('../lib/generate-pdf.js');
  const { canti, outDir, fontBuffers } = workerData;

  if (fontBuffers) initFonts(fontBuffers);

  let count = 0;
  let errors = 0;

  for (const canto of canti) {
    try {
      const pdfBuffer = await generateCantoPdf(canto, {
        autoriTesto: canto.autoriTesto,
        periodo: canto.periodo,
        lingue: canto.lingue,
        tags: canto.tags,
        qrBuffer: Buffer.from(canto.qrBuffer),
      });
      await writeFile(join(outDir, `ildeposito-${canto.slug}.pdf`), pdfBuffer);
      count++;
    } catch (err) {
      parentPort.postMessage({ type: 'error', slug: canto.slug, message: err.message });
      errors++;
    }

    if (count % 100 === 0) {
      parentPort.postMessage({ type: 'progress', count, errors, total: canti.length });
    }
  }

  parentPort.postMessage({ type: 'done', count, errors });
}

run();
