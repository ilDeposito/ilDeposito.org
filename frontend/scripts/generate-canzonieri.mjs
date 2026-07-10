#!/usr/bin/env node
// Genera i canzonieri PDF collettivi (tutti i canti, tutti con accordi, per
// periodo, per autore con >10 canti). Fuori dal ciclo di build Astro,
// pensato per girare da cron settimanale via ./ildeposito.sh build-canzonieri
// (vedi docker-entrypoint.sh, modalità "canzonieri").
//
// Env richieste: DRUPAL_API_URL, CANZONIERI_OUT_DIR (directory di output).
import { mkdirSync, writeFileSync, rmSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { getDatiCanzonieri, fetchImageBuffer } from '../src/integrations/canzonieri-runner.js';
import {
  generaCanzonierePerTutti,
  generaCanzonierePerSingoloPeriodo,
  generaCanzonierePerAutore,
} from '../src/lib/generate-canzoniere.js';

const outDir = process.env.CANZONIERI_OUT_DIR;
if (!outDir) {
  console.error('CANZONIERI_OUT_DIR deve essere definito (directory di output dei canzonieri).');
  process.exit(1);
}

function slugify(text) {
  return text
    .toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

async function scriviCanzoniere(manifest, slug, titolo, buildFn) {
  console.log(`→ Genero ${slug}.pdf...`);
  const t0 = Date.now();
  const buffer = await buildFn();
  writeFileSync(join(outDir, `${slug}.pdf`), buffer);
  manifest.canzonieri.push({
    slug,
    titolo,
    file: `${slug}.pdf`,
    dimensione: buffer.length,
  });
  console.log(`  ✓ ${slug}.pdf (${(buffer.length / 1024 / 1024).toFixed(1)} MB, ${Math.round((Date.now() - t0) / 1000)}s)`);
}

async function main() {
  const t0 = Date.now();
  mkdirSync(outDir, { recursive: true });

  console.log('→ Recupero dati canzonieri...');
  const { canti, periodi, autoriIdonei } = await getDatiCanzonieri();
  console.log(`  ${canti.length} canti, ${periodi.length} periodi, ${autoriIdonei.length} autori idonei (>10 canti).`);

  console.log('→ Scarico immagini periodi/autori...');
  for (const periodo of periodi) {
    periodo.immagineBuffer = await fetchImageBuffer(periodo.immagine);
  }
  for (const { autore } of autoriIdonei) {
    autore.immagineBuffer = await fetchImageBuffer(autore.immagine);
  }

  const manifest = { generatoIl: new Date().toISOString(), canzonieri: [] };

  await scriviCanzoniere(manifest, 'tutti', 'Tutti i canti', () =>
    generaCanzonierePerTutti(canti, periodi, { accordi: false }));

  await scriviCanzoniere(manifest, 'tutti-accordi', 'Tutti i canti con accordi', () =>
    generaCanzonierePerTutti(canti, periodi, { accordi: true }));

  for (const periodo of periodi) {
    const cantiPeriodo = canti.filter((c) => c.periodo?.id === periodo.id);
    if (cantiPeriodo.length === 0) continue;
    const slug = `periodo-${periodo.slug || slugify(periodo.titolo)}`;
    await scriviCanzoniere(manifest, slug, periodo.titolo, () =>
      generaCanzonierePerSingoloPeriodo(cantiPeriodo, periodo));

    const cantiPeriodoAccordi = cantiPeriodo.filter((c) => c.accordi);
    if (cantiPeriodoAccordi.length > 0) {
      await scriviCanzoniere(manifest, `${slug}-accordi`, `${periodo.titolo} (con accordi)`, () =>
        generaCanzonierePerSingoloPeriodo(cantiPeriodo, periodo, { accordi: true }));
    }
  }

  for (const { autore, canti: cantiAutore } of autoriIdonei) {
    const slug = `autore-${autore.slug || slugify(autore.titolo)}`;
    await scriviCanzoniere(manifest, slug, autore.titolo, () =>
      generaCanzonierePerAutore(cantiAutore, autore));
  }

  // Rimuove PDF orfani (es. periodo/autore non più idoneo da quando è
  // sceso sotto la soglia) confrontando con il manifest appena scritto.
  const attesi = new Set(manifest.canzonieri.map((c) => c.file));
  attesi.add('manifest.json');
  for (const file of readdirSync(outDir)) {
    const filePath = join(outDir, file);
    if (!attesi.has(file) && statSync(filePath).isFile()) {
      rmSync(filePath);
      console.log(`  – rimosso orfano ${file}`);
    }
  }

  writeFileSync(join(outDir, 'manifest.json'), JSON.stringify(manifest, null, 2));

  console.log(`✓ ${manifest.canzonieri.length} canzonieri generati in ${Math.round((Date.now() - t0) / 1000)}s.`);
}

main().catch((err) => {
  console.error('✗ Generazione canzonieri fallita:', err);
  process.exit(1);
});
