import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { basename, dirname, extname, join } from 'node:path';
import { DRUPAL_API_URL } from './client.js';

const PUBLIC_DIR = join(process.cwd(), 'public');
const DIST_DIR = join(process.cwd(), 'dist');

function slugify(name: string): string {
  return name.replace(/[/\\]/g, '-').replace(/[^a-zA-Z0-9._-]/g, '');
}

async function downloadAsset(relativeUrl: string, category: string): Promise<string | null> {
  const fileName = slugify(basename(relativeUrl));
  const ext = extname(fileName) || '.jpg';
  const nameWithoutExt = fileName.replace(ext, '');
  const relPath = `uploads/${category}/${nameWithoutExt}${ext}`;
  const publicPath = join(PUBLIC_DIR, relPath);

  if (!existsSync(publicPath)) {
    const fullUrl = new URL(relativeUrl, DRUPAL_API_URL).toString();
    const res = await fetch(fullUrl);
    if (!res.ok) {
      console.warn(`Download immagine ${category} fallito (${relativeUrl}): ${res.status}`);
      return null;
    }

    const buffer = Buffer.from(await res.arrayBuffer());
    mkdirSync(dirname(publicPath), { recursive: true });
    writeFileSync(publicPath, buffer);

    if (existsSync(DIST_DIR)) {
      const distPath = join(DIST_DIR, relPath);
      mkdirSync(dirname(distPath), { recursive: true });
      writeFileSync(distPath, buffer);
    }
  }

  return `/${relPath}`;
}

// relativeUrl è il path dal JSON:API, es. "/sites/default/files/immagini/2018/09/foto.png"
export async function getAutoreImageUrl(relativeUrl: string | null | undefined): Promise<string | null> {
  if (!relativeUrl) return null;
  return downloadAsset(relativeUrl, 'autori');
}

export async function getEventoImageUrl(relativeUrl: string | null | undefined): Promise<string | null> {
  if (!relativeUrl) return null;
  return downloadAsset(relativeUrl, 'eventi');
}

export async function getPeriodoImageUrl(relativeUrl: string | null | undefined): Promise<string | null> {
  if (!relativeUrl) return null;
  return downloadAsset(relativeUrl, 'periodi');
}
