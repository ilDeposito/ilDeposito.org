import { DRUPAL_API_URL } from './client.js';

export function getImageUrl(relativeUrl: string | null | undefined): string | null {
  if (!relativeUrl) return null;
  return new URL(relativeUrl, DRUPAL_API_URL).toString();
}

export const getAutoreImageUrl = getImageUrl;
export const getEventoImageUrl = getImageUrl;
export const getPeriodoImageUrl = getImageUrl;
