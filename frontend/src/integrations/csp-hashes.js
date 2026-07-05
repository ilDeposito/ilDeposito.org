import { readdirSync, readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';

// Content-Security-Policy servita da nginx. L'header NON sta più in
// nginx-security-headers.conf: l'unica fonte è il file generato da questa
// integrazione (current/csp/csp-header.conf, incluso via glob — così nginx
// parte anche se il file non esiste ancora).
//
// script-src viene completato a build time con gli hash sha256 degli script
// inline realmente presenti nell'HTML prodotto (is:inline e eventuali script
// che Astro decide di inlinare): non serve mantenere una lista manuale di
// hash e una modifica a uno script inline non può rompere silenziosamente
// la policy. 'unsafe-inline' resta SOLO come fallback per browser senza
// supporto CSP2: quando sono presenti hash, i browser lo ignorano.
const SCRIPT_SRC_BASE = "'self' 'unsafe-inline' 'wasm-unsafe-eval' https://www.youtube.com https://umami.ildeposito.org";

// style-src mantiene 'unsafe-inline': gli attributi style="..." nei template
// (honeypot nascosto, content-visibility, ecc.) e i fogli di stile che Astro
// inlinea (build.inlineStylesheets 'auto') lo richiedono. Il rischio XSS via
// style è trascurabile rispetto a script-src.
const DIRECTIVES = [
  "default-src 'self'",
  null, // segnaposto script-src (composto con gli hash)
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data: https://tile.openstreetmap.org https://i.ytimg.com https://img.youtube.com",
  "font-src 'self'",
  "connect-src 'self' https://tile.openstreetmap.org https://umami.ildeposito.org",
  "frame-src https://www.youtube.com https://www.youtube-nocookie.com",
  "worker-src 'self' blob:",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'self'",
];

const SCRIPT_RE = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
// I data block (application/ld+json ecc.) non vengono eseguiti: CSP non li
// blocca e non vanno hashati.
const EXECUTABLE_TYPES = new Set(['module', 'text/javascript', 'application/javascript']);

function collectInlineScriptHashes(clientDir) {
  const hashes = new Set();
  let pages = 0;

  for (const rel of readdirSync(clientDir, { recursive: true })) {
    const relPath = String(rel);
    if (!relPath.endsWith('.html')) continue;
    const html = readFileSync(join(clientDir, relPath), 'utf-8');
    pages++;

    for (const match of html.matchAll(SCRIPT_RE)) {
      const attrs = match[1] ?? '';
      const body = match[2];
      if (/(^|\s)src\s*=/i.test(attrs)) continue;
      const type = attrs.match(/(?:^|\s)type\s*=\s*["']?([^"'\s>]+)/i)?.[1]?.toLowerCase();
      if (type && !EXECUTABLE_TYPES.has(type)) continue;
      if (!body) continue;
      hashes.add(`'sha256-${createHash('sha256').update(body, 'utf8').digest('base64')}'`);
    }
  }

  return { hashes: [...hashes].sort(), pages };
}

export default function cspHashesIntegration() {
  return {
    name: 'csp-hashes',
    hooks: {
      // dir punta alla directory client/ (HTML statico), come in pdf-generator.
      'astro:build:done': async ({ dir, logger }) => {
        const clientDir = fileURLToPath(dir);
        const { hashes, pages } = collectInlineScriptHashes(clientDir);

        const scriptSrc = `script-src ${[SCRIPT_SRC_BASE, ...hashes].join(' ')}`;
        const policy = DIRECTIVES.map((d) => d ?? scriptSrc).join('; ');

        // Fuori da client/ (non servito da nginx), dentro la release (versionato
        // con essa): nginx-security-headers.conf lo include via glob e la
        // reload post-swap di build-frontend lo fa rileggere.
        const cspDir = join(clientDir, '..', 'csp');
        mkdirSync(cspDir, { recursive: true });
        writeFileSync(
          join(cspDir, 'csp-header.conf'),
          `# Generato a build time da src/integrations/csp-hashes.js — non modificare a mano.\n` +
          `add_header Content-Security-Policy "${policy}" always;\n`,
        );

        logger.info(`CSP: ${hashes.length} hash di script inline da ${pages} pagine → csp/csp-header.conf`);
      },
    },
  };
}
