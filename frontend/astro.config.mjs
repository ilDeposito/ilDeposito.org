import { defineConfig } from 'astro/config';
import { loadEnv } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import yaml from '@rollup/plugin-yaml';
import sitemap from '@astrojs/sitemap';
import node from '@astrojs/node';

const { DRUPAL_API_URL } = loadEnv(process.env.NODE_ENV ?? 'production', process.cwd(), '');
const drupalHost = new URL(DRUPAL_API_URL || 'http://localhost').hostname;
// jsonapi-fetch.js legge process.env (non import.meta.env): gira fuori dalla
// pipeline Vite, come pdf-runner.js. Stage/prod lo impostano già via Docker;
// qui copriamo il caso locale dove vive solo nel .env letto sopra da loadEnv.
if (!process.env.DRUPAL_API_URL && DRUPAL_API_URL) {
  process.env.DRUPAL_API_URL = DRUPAL_API_URL;
}
import pdfGenerator from './src/integrations/pdf-generator.js';
import cspHashes from './src/integrations/csp-hashes.js';
import { fetchAllPaginated, extractSlug } from './src/integrations/jsonapi-fetch.js';
import { createReadStream, existsSync } from 'node:fs';
import { join, normalize } from 'node:path';

// Serve dist/pagefind/ in dev mode (generato da `npm run build`, assente in dev)
function pagefindDevServer() {
  return {
    name: 'pagefind-dev-server',
    configureServer(server) {
      const pagefindDir = join(process.cwd(), 'dist', 'pagefind');

      server.middlewares.use((req, res, next) => {
        if (!req.url?.startsWith('/pagefind/')) return next();

        const filePath = normalize(join(process.cwd(), 'dist', req.url.split('?')[0]));
        if (!filePath.startsWith(pagefindDir) || !existsSync(filePath)) return next();

        if (filePath.endsWith('.js')) res.setHeader('Content-Type', 'text/javascript');
        createReadStream(filePath).pipe(res);
      });
    },
  };
}

// URL → data di ultima modifica, per il <lastmod> della sitemap. Fetch
// minimale (solo path+changed) indipendente dallo store di lib/api/drupal,
// che dipende da import.meta.env non disponibile qui (vedi sopra).
async function buildLastmodMap() {
  const tipi = [
    { endpoint: '/jsonapi/node/canto', resourceType: 'node--canto', prefix: 'canti' },
    { endpoint: '/jsonapi/node/autore', resourceType: 'node--autore', prefix: 'autori' },
    { endpoint: '/jsonapi/node/evento', resourceType: 'node--evento', prefix: 'eventi' },
  ];
  const map = new Map();
  try {
    await Promise.all(tipi.map(async ({ endpoint, resourceType, prefix }) => {
      const { data } = await fetchAllPaginated(endpoint, {
        'filter[status]': '1',
        [`fields[${resourceType}]`]: 'path,changed',
        'page[limit]': '200',
      });
      for (const item of data) {
        const slug = extractSlug(item.attributes.path?.alias);
        if (slug && item.attributes.changed) {
          map.set(`/${prefix}/${slug}`, item.attributes.changed);
        }
      }
    }));
  } catch (err) {
    // Niente lastmod è meglio di una build rotta: la sitemap resta valida,
    // solo senza quel campo per questa build.
    console.warn(`[sitemap] lastmod non disponibile (Drupal non raggiungibile?): ${err.message}`);
  }
  return map;
}

const lastmodByPath = await buildLastmodMap();

export default defineConfig({
  site: 'https://www.ildeposito.org',
  output: 'static',
  adapter: node({ mode: 'standalone' }),
  trailingSlash: 'never',

  // Default: node_modules/.astro, effimero nel builder Docker (compose run
  // --rm). Qui invece la cache delle trasformazioni astro:assets finisce in
  // /app/.astro, dove i compose stage/prod montano il volume astro_cache:
  // le immagini remote non vengono riscaricate/ritrasformate a ogni build.
  cacheDir: './.astro',

  // Default 1 (pagine generate in sequenza): il fetch layer verso Drupal ha
  // già un gate a 4 richieste concorrenti (client.ts), quindi 4 pagine in
  // parallelo sovrappongono le attese I/O senza saturare il backend.
  build: { concurrency: 4 },
  // checkOrigin confronta Origin con url.origin, ma il Node adapter costruisce
  // sempre url come http:// dietro un proxy nginx (non legge X-Forwarded-Proto).
  // Anti-spam delegato al rate limit nginx (2r/min per IP) sull'endpoint /api/.
  security: { checkOrigin: false },

  image: {
    domains: [drupalHost],
  },

  integrations: [
    sitemap({
      filter: (page) => !page.includes('/cerca') && !page.includes('/404'),
      serialize(item) {
        const lastmod = lastmodByPath.get(new URL(item.url).pathname);
        return lastmod ? { ...item, lastmod } : item;
      },
    }),
    cspHashes(),
    pdfGenerator(),
  ],

  vite: {
    plugins: [
      yaml(),
      tailwindcss(),
      pagefindDevServer(),
    ],
  },
});
