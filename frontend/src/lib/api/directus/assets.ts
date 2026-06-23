import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { DIRECTUS_URL, DIRECTUS_TOKEN } from './client.js';

const PUBLIC_DIR = join(process.cwd(), 'public');
const DIST_DIR = join(process.cwd(), 'dist');

async function downloadAsset(fileId: string, category: string, width: number, height: number): Promise<string | null> {
  if (!fileId) return null;

  const relPath = `uploads/${category}/${fileId}.jpg`;
  const publicPath = join(PUBLIC_DIR, relPath);

  if (!existsSync(publicPath)) {
    const url = new URL(`/assets/${fileId}`, DIRECTUS_URL);
    url.searchParams.set('width', String(width));
    url.searchParams.set('height', String(height));
    url.searchParams.set('fit', 'cover');
    url.searchParams.set('format', 'jpg');

    const res = await fetch(url.toString(), {
      headers: { Authorization: `Bearer ${DIRECTUS_TOKEN}` },
    });
    if (!res.ok) {
      throw new Error(`Download immagine ${category} fallito (${fileId}): ${res.status} ${res.statusText}`);
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

export async function getAutoreImageUrl(fileId: string | null | undefined): Promise<string | null> {
  if (!fileId) return null;
  return downloadAsset(fileId, 'autori', 160, 160);
}

export async function getEventoImageUrl(fileId: string | null | undefined): Promise<string | null> {
  if (!fileId) return null;
  return downloadAsset(fileId, 'eventi', 800, 450);
}

export async function getPeriodoImageUrl(fileId: string | null | undefined): Promise<string | null> {
  if (!fileId) return null;
  return downloadAsset(fileId, 'periodi', 800, 450);
}
