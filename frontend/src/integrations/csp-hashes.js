import { readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

function walkHtml(dir) {
  const files = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      files.push(...walkHtml(full));
    } else if (entry.endsWith('.html')) {
      files.push(full);
    }
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

const CSP_TEMPLATE = (scriptHashes) =>
  `default-src 'self'; ` +
  `script-src 'self' ${scriptHashes}; ` +
  `style-src 'self' 'unsafe-inline'; ` +
  `img-src 'self' data: https://tile.openstreetmap.org; ` +
  `font-src 'self'; ` +
  `connect-src 'self' https://tile.openstreetmap.org; ` +
  `frame-src 'none'; ` +
  `object-src 'none'; ` +
  `base-uri 'self'; ` +
  `form-action 'self'`;

export default function cspHashesIntegration() {
  return {
    name: 'csp-hashes',
    hooks: {
      'astro:build:done': async ({ dir, logger }) => {
        const outDir = fileURLToPath(dir);
        const htmlFiles = walkHtml(outDir);

        const scriptHashSet = new Set();
        for (const file of htmlFiles) {
          const html = readFileSync(file, 'utf8');
          for (const script of extractInlineScripts(html)) {
            scriptHashSet.add(`'sha256-${sha256(script)}'`);
          }
        }

        logger.info(`${scriptHashSet.size} hash di script inline trovati in ${htmlFiles.length} pagine HTML.`);

        if (scriptHashSet.size === 0) {
          logger.info('Nessuno script inline trovato, CSP hash file non generato.');
          return;
        }

        const scriptHashes = [...scriptHashSet].join(' ');
        const csp = CSP_TEMPLATE(scriptHashes);

        const outputDir = process.env.OUTPUT_DIR || '/app/output';
        const outputPath = join(outputDir, 'csp-hashes.conf');
        writeFileSync(outputPath, `add_header Content-Security-Policy "${csp}" always;\n`);

        logger.info(`${scriptHashSet.size} hash generati → ${outputPath}`);
      },
    },
  };
}
