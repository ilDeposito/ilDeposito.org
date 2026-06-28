import { parentPort, workerData } from 'node:worker_threads';
import { writeFile } from 'node:fs/promises';
import { join } from 'node:path';

async function run() {
  const { generateCantoPdf, generateQrBuffer } = await import('../lib/generate-pdf.js');
  const { canti, outDir } = workerData;

  let count = 0;
  let errors = 0;

  for (const canto of canti) {
    try {
      const qrBuffer = await generateQrBuffer(canto.slug);
      const pdfBuffer = await generateCantoPdf(canto, {
        autoriTesto: canto.autoriTesto,
        periodo: canto.periodo,
        lingue: canto.lingue,
        tags: canto.tags,
        qrBuffer,
      });
      await writeFile(join(outDir, `${canto.slug}.pdf`), pdfBuffer);
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
