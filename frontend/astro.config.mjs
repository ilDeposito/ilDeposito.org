import { defineConfig } from 'astro/config';
import { loadEnv } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';
import node from '@astrojs/node';

const { DRUPAL_API_URL } = loadEnv(process.env.NODE_ENV ?? 'production', process.cwd(), '');
const drupalHost = new URL(DRUPAL_API_URL || 'http://localhost').hostname;
import pdfGenerator from './src/integrations/pdf-generator.js';
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

export default defineConfig({
  site: 'https://www.ildeposito.org',
  output: 'static',
  adapter: node({ mode: 'standalone' }),
  trailingSlash: 'never',
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
    }),
    pdfGenerator(),
  ],

  vite: {
    plugins: [
      tailwindcss(),
      pagefindDevServer(),
    ],
  },
});
