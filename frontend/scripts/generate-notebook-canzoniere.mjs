#!/usr/bin/env node
// Generatore esclusivamente locale: e' invocato solo da ./local.sh
// e rifiuta esplicitamente qualunque esecuzione priva del marcatore locale.
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import { getDatiCanzoniereNotebook } from '../src/integrations/notebook-canzoniere-runner.js';
import { generaCanzoniereNotebook } from '../src/lib/generate-notebook-canzoniere.js';

if (process.env.LOCAL_CANZONIERE_NOTEBOOK !== '1') {
  console.error('Questo generatore e\' riservato a ./local.sh canzoniere-notebook.');
  process.exit(1);
}
const output = process.env.NOTEBOOK_CANZONIERE_OUT_FILE;
if (!output) {
  console.error('NOTEBOOK_CANZONIERE_OUT_FILE deve essere definito.');
  process.exit(1);
}

try {
  console.log('→ Recupero canti, metadati ed eventi collegati...');
  const canti = await getDatiCanzoniereNotebook();
  console.log(`  ${canti.length} canti; titoli verificati.`);
  console.log('→ Genero il canzoniere per NotebookLM...');
  const buffer = await generaCanzoniereNotebook(canti);
  mkdirSync(dirname(output), { recursive: true });
  writeFileSync(output, buffer);
  console.log(`✓ PDF generato: ${output} (${(buffer.length / 1024 / 1024).toFixed(1)} MB)`);
} catch (error) {
  console.error('✗ Generazione canzoniere NotebookLM fallita:', error);
  process.exit(1);
}
