#!/usr/bin/env node
import { readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { createHash } from 'node:crypto';

function walkHtml(dir) {
  const files = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) files.push(...walkHtml(full));
    else if (entry.endsWith('.html')) files.push(full);
  }
  return files;
}

function extractInlineScripts(html) {
  const scripts = [];
  const re = /<script(?:\s[^>]*)?>([^]*?)<\/script>/gi;
  let match;
  while ((match = re.exec(html)) !== null) {
    const tag = match[0];
    if (tag.includes(' src=')) continue;
    if (tag.includes('type="application/ld+json"')) continue;
    const content = match[1];
    if (content.trim()) scripts.push(content);
  }
  return scripts;
}

function sha256(content) {
  return createHash('sha256').update(content, 'utf8').digest('base64');
}

const buildDir = process.argv[2];
const outputPath = process.argv[3];

if (!buildDir || !outputPath) {
  console.error('Uso: generate-csp-hashes.js <build-dir> <output-path>');
  process.exit(1);
}

const htmlFiles = walkHtml(buildDir);
const hashSet = new Set();

for (const file of htmlFiles) {
  const html = readFileSync(file, 'utf8');
  for (const script of extractInlineScripts(html)) {
    hashSet.add(`'sha256-${sha256(script)}'`);
  }
}

console.log(`→ CSP: ${hashSet.size} hash da ${htmlFiles.length} pagine HTML`);

if (hashSet.size === 0) {
  console.log('→ CSP: nessuno script inline, file non generato');
  process.exit(0);
}

const hashes = [...hashSet].join(' ');
const csp =
  `default-src 'self'; ` +
  `script-src 'self' ${hashes}; ` +
  `style-src 'self' 'unsafe-inline'; ` +
  `img-src 'self' data: https://tile.openstreetmap.org; ` +
  `font-src 'self'; ` +
  `connect-src 'self' https://tile.openstreetmap.org; ` +
  `frame-src 'none'; ` +
  `object-src 'none'; ` +
  `base-uri 'self'; ` +
  `form-action 'self'`;

writeFileSync(outputPath, `add_header Content-Security-Policy "${csp}" always;\n`);
console.log(`→ CSP: ${outputPath} generato`);
