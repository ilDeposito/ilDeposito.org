#!/usr/bin/env node
/**
 * Verifica i link interni dell'output statico Astro contro i file realmente
 * generati nel build. Segnala anche i placeholder href="#"/vuoti e riepiloga
 * gli host esterni.
 *
 * Con --check-external verifica anche via HTTP i link esterni univoci trovati
 * (HEAD con fallback GET, timeout e retry singolo su errori di rete). I link
 * YouTube vengono verificati tramite l'endpoint oEmbed pubblico, perché la
 * pagina /watch è una SPA che risponde 200 anche quando il video è stato
 * rimosso o reso privato.
 *
 * Uso:  node scripts/linkcheck.mjs [dist/client] [--check-external] [--timeout=8000] [--concurrency=8]
 * Exit: 1 se trova link interni rotti (o esterni rotti con --check-external), 0 altrimenti.
 */
import { readFileSync, existsSync, statSync, readdirSync } from 'node:fs';
import { join, relative } from 'node:path';

const rawArgs = process.argv.slice(2);
const flags = new Set();
const options = {};
let ROOT = 'dist/client';
for (const arg of rawArgs) {
  if (arg.startsWith('--')) {
    const [key, val] = arg.slice(2).split('=');
    if (val !== undefined) options[key] = val;
    else flags.add(key);
  } else {
    ROOT = arg;
  }
}
const CHECK_EXTERNAL = flags.has('check-external');
const TIMEOUT_MS = Number(options.timeout) || 8000;
const CONCURRENCY = Math.max(1, Number(options.concurrency) || 8);
const USER_AGENT = 'Mozilla/5.0 (compatible; ilDepositoLinkCheck/1.0; +https://www.ildeposito.org)';

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

// Route SSR (prerender = false, vedi src/pages/canzonieri/index.astro): non
// generano mai un file in dist/client, quindi risulterebbero sempre "rotte"
// per una scansione puramente statica. Servite a runtime da nginx (vedi
// location dedicata in nginx.conf), non verificabili qui.
const SSR_ONLY_ROUTES = new Set(['/canzonieri']);

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
const externalUrls = new Map();
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

    let pathname = null, isExternal = false, host = null, externalUrl = null;
    if (/^https?:\/\//i.test(raw) || raw.startsWith('//')) {
      try {
        const u = new URL(raw.startsWith('//') ? 'https:' + raw : raw);
        host = u.host;
        if (/(^|\.)ildeposito\.org$/i.test(u.hostname)) pathname = u.pathname;
        else { isExternal = true; externalUrl = u.toString(); }
      } catch { isExternal = true; }
    } else if (raw.startsWith('/')) {
      pathname = raw.split('#')[0].split('?')[0];
    } else {
      continue; // relativo: raro in questo build, non risolvibile senza base
    }

    if (isExternal) {
      externalCount++;
      externalHosts.set(host, (externalHosts.get(host) || 0) + 1);
      if (externalUrl) add(externalUrls, externalUrl, srcRel);
      continue;
    }
    if (pathname !== null) {
      const clean = pathname.split('#')[0].split('?')[0];
      if (SSR_ONLY_ROUTES.has(clean) || resolveInternal(clean)) internalOk++;
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
console.log('Esterni                :', externalCount, `(host distinti: ${externalHosts.size}, URL univoci: ${externalUrls.size})`);
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
  const label = CHECK_EXTERNAL
    ? '===== TOP HOST ESTERNI ====='
    : '===== TOP HOST ESTERNI (non verificati via HTTP, usa --check-external) =====';
  console.log(`\n${label}`);
  for (const [h, c] of [...externalHosts.entries()].sort((a, b) => b[1] - a[1]).slice(0, 20)) {
    console.log(`  ${String(c).padStart(6)}  ${h}`);
  }
}

// ===== Verifica HTTP dei link esterni (--check-external) =====

const YOUTUBE_RE = /(^|\.)youtube\.com$|(^|\.)youtu\.be$|(^|\.)youtube-nocookie\.com$/i;

function isYoutubeUrl(url) {
  try { return YOUTUBE_RE.test(new URL(url).hostname); } catch { return false; }
}

async function fetchWithTimeout(url, opts = {}) {
  const controller = new AbortController();
  const id = setTimeout(() => controller.abort(), TIMEOUT_MS);
  try {
    return await fetch(url, {
      redirect: 'follow',
      headers: { 'User-Agent': USER_AGENT, ...opts.headers },
      ...opts,
      signal: controller.signal,
    });
  } finally {
    clearTimeout(id);
  }
}

// oEmbed pubblico: risponde 404 se il video non esiste più, 401 se privato o
// non incorporabile. Non richiede API key né conta sulle quote di YouTube Data API.
async function checkYoutube(url) {
  const oembed = `https://www.youtube.com/oembed?url=${encodeURIComponent(url)}&format=json`;
  const res = await fetchWithTimeout(oembed, { method: 'GET' });
  return { ok: res.ok, status: res.status };
}

async function checkGeneric(url) {
  let res = await fetchWithTimeout(url, { method: 'HEAD' });
  if (!res.ok) res = await fetchWithTimeout(url, { method: 'GET' }); // alcuni server non gestiscono HEAD
  return { ok: res.ok, status: res.status };
}

async function checkUrl(url) {
  const attempt = () => (isYoutubeUrl(url) ? checkYoutube(url) : checkGeneric(url));
  try {
    return await attempt();
  } catch (e) {
    try {
      return await attempt(); // retry singolo: assorbe blip di rete/timeout transitori
    } catch (e2) {
      return { ok: false, status: null, error: e2.name === 'AbortError' ? 'timeout' : (e2.message || 'errore di rete') };
    }
  }
}

function renderProgress(done, total) {
  const width = 30;
  const filled = Math.round((done / total) * width);
  const bar = '█'.repeat(filled) + '░'.repeat(width - filled);
  const pct = String(Math.round((done / total) * 100)).padStart(3);
  process.stdout.write(`\r  [${bar}] ${pct}%  ${done}/${total}`);
  if (done === total) process.stdout.write('\n');
}

async function runPool(items, worker, concurrency) {
  let idx = 0, done = 0;
  const results = new Array(items.length);
  async function next() {
    while (idx < items.length) {
      const i = idx++;
      results[i] = await worker(items[i]);
      done++;
      renderProgress(done, items.length);
    }
  }
  await Promise.all(Array.from({ length: Math.min(concurrency, items.length) }, next));
  return results;
}

const brokenExternal = new Map();

if (CHECK_EXTERNAL) {
  const urls = [...externalUrls.keys()];
  console.log(`\n===== VERIFICA LINK ESTERNI (${urls.length} univoci, timeout ${TIMEOUT_MS}ms, concorrenza ${CONCURRENCY}) =====`);
  if (urls.length === 0) {
    console.log('Nessun link esterno da verificare.');
  } else {
    const results = await runPool(urls, async (url) => ({ url, ...(await checkUrl(url)) }), CONCURRENCY);
    for (const r of results) {
      if (!r.ok) {
        const info = externalUrls.get(r.url);
        brokenExternal.set(r.url, { status: r.status, error: r.error, samples: info.samples });
      }
    }
  }

  console.log('\n===== LINK ESTERNI ROTTI =====');
  if (brokenExternal.size === 0) console.log('Nessuno ✅');
  for (const [url, v] of brokenExternal) {
    const label = v.error ? v.error : `HTTP ${v.status}`;
    console.log(`\n✗ ${url}  (${label})`);
    for (const s of v.samples) console.log(`    in: ${s}`);
  }

  const youtubeTotal = urls.filter(isYoutubeUrl).length;
  const youtubeBroken = [...brokenExternal.keys()].filter(isYoutubeUrl).length;
  console.log('\n===== RESOCONTO YOUTUBE =====');
  console.log('Link YouTube verificati:', youtubeTotal);
  console.log('Link YouTube non validi:', youtubeBroken);
}

if (brokenInternal.size > 0 || brokenExternal.size > 0) {
  if (brokenInternal.size > 0) console.log(`\n✗ Trovati ${brokenInternal.size} link interni rotti.`);
  if (brokenExternal.size > 0) console.log(`✗ Trovati ${brokenExternal.size} link esterni rotti.`);
  process.exit(1);
}
console.log('\n✓ Nessun link rotto.');
