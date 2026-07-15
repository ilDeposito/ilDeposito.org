#!/usr/bin/env node
// Genera il blocco nginx dei redirect legacy a partire da /api/redirects.json
// (Drupal, modulo ildeposito_redirects). Chiamato da docker-entrypoint.sh dopo
// `astro build`, scrive sempre il file — anche vuoto/con solo un commento —
// per non rompere l'`include` in frontend/nginx.conf se Drupal non risponde
// o non ha redirect configurati.
//
// Seconda barriera anti-open-redirect, indipendente dalla validazione PHP in
// RedirectsForm: questo endpoint è pubblico, non ci fidiamo di un'unica
// validazione lato Drupal per generare direttiva `return 301` in nginx.
import { writeFile } from 'node:fs/promises';

const ALLOWED_HOST_SUFFIX = '.ildeposito.org';
const ALLOWED_HOST = 'ildeposito.org';
const PATH_RE = /^\/[A-Za-z0-9\-_./]*$/;
// Come PATH_RE ma ammette un "*" finale (mai in mezzo) solo per `from`: vedi
// renderBlock(). Il target (`to`) resta validato con PATH_RE, mai wildcard.
const PATH_RE_FROM = /^\/[A-Za-z0-9\-_./]*\*?$/;

const outPath = process.argv[2];
if (!outPath) {
  console.error('Uso: generate-redirects.mjs <output-path>');
  process.exit(1);
}

function isValidFrom(from) {
  return typeof from === 'string' && PATH_RE_FROM.test(from);
}

function isValidTo(to) {
  if (typeof to !== 'string' || to === '') return false;
  if (to.startsWith('/') && !to.startsWith('//')) return PATH_RE.test(to);
  if (!to.startsWith('https://') && !to.startsWith('http://')) return false;
  try {
    const host = new URL(to).hostname;
    return host === ALLOWED_HOST || host.endsWith(ALLOWED_HOST_SUFFIX);
  } catch {
    return false;
  }
}

function renderBlock(from, to) {
  if (from.endsWith('*')) {
    const prefix = from.slice(0, -1);
    // ^~ obbligatorio: senza, un prefix match "vince" solo se più lungo di
    // *ogni* regex location dichiarata dopo in nginx.conf (es. il redirect
    // trailing-slash ^(.+)/$, che altrimenti intercetta prima le richieste
    // sotto ${prefix} che terminano con "/") — stesso motivo del ^~ su /api/.
    return `location ^~ ${prefix} {\n    return 301 ${to};\n}\n`;
  }
  return `location = ${from} {\n    return 301 ${to};\n}\n`;
}

async function main() {
  const drupalApiUrl = process.env.DRUPAL_API_URL;
  const header = '# Generato automaticamente da generate-redirects.mjs — non modificare a mano.\n# Fonte: /api/redirects.json (Drupal, modulo ildeposito_redirects).\n';

  if (!drupalApiUrl) {
    console.warn('⚠ DRUPAL_API_URL non definito: scrivo _redirects.conf vuoto.');
    await writeFile(outPath, header);
    return;
  }

  let redirects = [];
  try {
    const res = await fetch(new URL('/api/redirects.json', drupalApiUrl));
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const body = await res.json();
    redirects = Array.isArray(body.redirects) ? body.redirects : [];
  } catch (err) {
    console.warn(`⚠ Impossibile recuperare /api/redirects.json (${err.message}): scrivo _redirects.conf vuoto.`);
    await writeFile(outPath, header);
    return;
  }

  const blocks = [];
  let skipped = 0;
  for (const entry of redirects) {
    const from = entry?.from;
    const to = entry?.to;
    if (!isValidFrom(from) || !isValidTo(to)) {
      skipped++;
      console.warn(`⚠ Redirect scartato (formato non valido): ${JSON.stringify(entry)}`);
      continue;
    }
    blocks.push(renderBlock(from, to));
  }

  await writeFile(outPath, header + blocks.join('\n'));
  console.log(`→ Generati ${blocks.length} redirect nginx${skipped ? ` (${skipped} scartati)` : ''} in ${outPath}`);
}

await main();
