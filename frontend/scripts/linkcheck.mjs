#!/usr/bin/env node
/**
 * Verifica i link interni dell'output statico Astro contro i file realmente
 * generati nel build. Segnala anche i placeholder href="#"/vuoti e riepiloga
 * gli host esterni (che NON vengono verificati via HTTP: serve rete).
 *
 * Uso:  node scripts/linkcheck.mjs [dist/client]
 * Exit: 1 se trova link interni rotti, 0 altrimenti.
 */
import { readFileSync, existsSync, statSync, readdirSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = process.argv[2] || 'dist/client';

if (!existsSync(ROOT) || !statSync(ROOT).isDirectory()) {
  console.error(`✗ Directory di build non trovata: ${ROOT}`);
  console.error('  Esegui prima una build del frontend.');
  process.exit(2);
}

function walk(dir, out = []) {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name.endsWith('.html')) out.push(p);
  }
  return out;
}

const htmlFiles = walk(ROOT);

const existsCache = new Map();
function fileExists(rel) {
  if (existsCache.has(rel)) return existsCache.get(rel);
  let ok = false;
  try { ok = statSync(join(ROOT, rel)).isFile(); } catch { ok = false; }
  existsCache.set(rel, ok);
  return ok;
}

// Risolve un path interno a un file reale nel build (directory-style o asset).
function resolveInternal(pathname) {
  let p = decodeURIComponent(pathname);
  if (p.startsWith('/')) p = p.slice(1);
  if (p === '') p = 'index.html';
  const candidates = [];
  if (p.endsWith('/')) candidates.push(p + 'index.html');
  else if (/\.[a-z0-9]{1,8}$/i.test(p)) candidates.push(p);
  else candidates.push(p + '/index.html', p + '.html', p);
  return candidates.some(fileExists);
}

// Rimuove blocchi <script>/<style>: contengono JS/CSS (es. i template client di
// PagefindList con href="/canti/${...}"), non link navigabili → falsi positivi.
const STRIP_RE = /<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi;
const ATTR_RE = /(?:href|src)\s*=\s*("([^"]*)"|'([^']*)')/gi;

const brokenInternal = new Map();
const placeholders = new Map();
const externalHosts = new Map();
let totalLinks = 0, internalOk = 0, externalCount = 0, otherSchemes = 0, anchorSame = 0;

function add(map, key, srcRel) {
  const v = map.get(key) || { count: 0, samples: new Set() };
  v.count++;
  if (v.samples.size < 5) v.samples.add(srcRel);
  map.set(key, v);
}

for (const file of htmlFiles) {
  const srcRel = relative(ROOT, file);
  const html = readFileSync(file, 'utf8').replace(STRIP_RE, '');
  let m;
  ATTR_RE.lastIndex = 0;
  while ((m = ATTR_RE.exec(html)) !== null) {
    const raw = (m[2] ?? m[3] ?? '').trim();
    totalLinks++;
    if (raw.includes('${')) continue;                       // template non renderizzato
    if (raw === '' || raw === '#') { add(placeholders, raw === '' ? '(vuoto)' : '#', srcRel); continue; }
    if (/^(mailto:|tel:|javascript:|data:|sms:)/i.test(raw)) { otherSchemes++; continue; }
    if (raw.startsWith('#')) { anchorSame++; continue; }

    let pathname = null, isExternal = false, host = null;
    if (/^https?:\/\//i.test(raw) || raw.startsWith('//')) {
      try {
        const u = new URL(raw.startsWith('//') ? 'https:' + raw : raw);
        host = u.host;
        if (/(^|\.)ildeposito\.org$/i.test(u.hostname)) pathname = u.pathname;
        else isExternal = true;
      } catch { isExternal = true; }
    } else if (raw.startsWith('/')) {
      pathname = raw.split('#')[0].split('?')[0];
    } else {
      continue; // relativo: raro in questo build, non risolvibile senza base
    }

    if (isExternal) { externalCount++; externalHosts.set(host, (externalHosts.get(host) || 0) + 1); continue; }
    if (pathname !== null) {
      const clean = pathname.split('#')[0].split('?')[0];
      if (resolveInternal(clean)) internalOk++;
      else add(brokenInternal, clean, srcRel);
    }
  }
}

const byCount = (map) => [...map.entries()].sort((a, b) => b[1].count - a[1].count);
const placeholderTotal = [...placeholders.values()].reduce((a, v) => a + v.count, 0);

console.log('===== RIEPILOGO LINK CHECK =====');
console.log('Directory              :', ROOT);
console.log('Pagine HTML analizzate :', htmlFiles.length);
console.log('Link (href/src) totali :', totalLinks);
console.log('Interni OK             :', internalOk);
console.log('Interni ROTTI (unici)  :', brokenInternal.size);
console.log('Placeholder #/vuoti    :', placeholderTotal);
console.log('Esterni                :', externalCount, `(host distinti: ${externalHosts.size})`);
console.log('mailto/tel/js/data     :', otherSchemes);
console.log('Anchor stessa pagina   :', anchorSame);

console.log('\n===== LINK INTERNI ROTTI =====');
if (brokenInternal.size === 0) console.log('Nessuno ✅');
for (const [target, v] of byCount(brokenInternal)) {
  console.log(`\n✗ ${target}  (${v.count} occorrenze)`);
  for (const s of v.samples) console.log(`    in: ${s}`);
}

if (placeholderTotal > 0) {
  console.log('\n===== PLACEHOLDER (#/vuoti) =====');
  for (const [k, v] of byCount(placeholders)) {
    console.log(`⚠ href="${k}"  (${v.count} occorrenze) — es. ${[...v.samples][0]}`);
  }
}

if (externalHosts.size > 0) {
  console.log('\n===== TOP HOST ESTERNI (non verificati via HTTP) =====');
  for (const [h, c] of [...externalHosts.entries()].sort((a, b) => b[1] - a[1]).slice(0, 20)) {
    console.log(`  ${String(c).padStart(6)}  ${h}`);
  }
}

if (brokenInternal.size > 0) {
  console.log(`\n✗ Trovati ${brokenInternal.size} link interni rotti.`);
  process.exit(1);
}
console.log('\n✓ Nessun link interno rotto.');
