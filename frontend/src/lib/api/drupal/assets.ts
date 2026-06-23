import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { DRUPAL_API_URL } from './client.js';

const PUBLIC_DIR = join(process.cwd(), 'public');
const DIST_DIR = join(process.cwd(), 'dist');

async function downloadAsset(fileUrl: string, category: string, fileId: string): Promise<string | null> {
  if (!fileUrl) return null;

  const relPath = `uploads/${category}/${fileId}.jpg`;
  const publicPath = join(PUBLIC_DIR, relPath);

  if (!existsSync(publicPath)) {
    const res = await fetch(fileUrl);
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
  const url = new URL(`/sites/default/files/styles/thumbnail/public/${fileId}`, DRUPAL_API_URL);
  return downloadAsset(url.toString(), 'autori', fileId.replace(/[/\\]/g, '-'));
}

export async function getEventoImageUrl(fileId: string | null | undefined): Promise<string | null> {
  if (!fileId) return null;
  const url = new URL(`/sites/default/files/styles/medium/public/${fileId}`, DRUPAL_API_URL);
  return downloadAsset(url.toString(), 'eventi', fileId.replace(/[/\\]/g, '-'));
}

export async function getPeriodoImageUrl(fileId: string | null | undefined): Promise<string | null> {
  if (!fileId) return null;
  const url = new URL(`/sites/default/files/styles/medium/public/${fileId}`, DRUPAL_API_URL);
  return downloadAsset(url.toString(), 'periodi', fileId.replace(/[/\\]/g, '-'));
}
