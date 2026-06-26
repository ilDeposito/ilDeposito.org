import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname, join } from 'node:path';
import { DRUPAL_API_URL } from './client.js';

const PUBLIC_DIR = join(process.cwd(), 'public');
const DIST_DIR = join(process.cwd(), 'dist');

function stableFilename(url: string): string {
  const ext = url.match(/\.\w+$/)?.[0] || '.jpg';
  const hash = createHash('md5').update(url).digest('hex').slice(0, 12);
  return hash + ext;
}

async function downloadAsset(relativeUrl: string, category: string): Promise<string | null> {
  if (!relativeUrl) return null;

  const filename = stableFilename(relativeUrl);
  const relPath = `uploads/${category}/${filename}`;
  const publicPath = join(PUBLIC_DIR, relPath);

  if (!existsSync(publicPath)) {
    const absoluteUrl = new URL(relativeUrl, DRUPAL_API_URL).toString();
    try {
      const res = await fetch(absoluteUrl);
      if (!res.ok) return null;
      const buffer = Buffer.from(await res.arrayBuffer());
      mkdirSync(dirname(publicPath), { recursive: true });
      writeFileSync(publicPath, buffer);

      if (existsSync(DIST_DIR)) {
        const distPath = join(DIST_DIR, relPath);
        mkdirSync(dirname(distPath), { recursive: true });
        writeFileSync(distPath, buffer);
      }
    } catch {
      return null;
    }
  }

  return `/${relPath}`;
}

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

export function getImageUrl(relativeUrl: string | null | undefined): string | null {
  if (!relativeUrl) return null;
  return new URL(relativeUrl, DRUPAL_API_URL).toString();
}
