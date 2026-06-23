const SITE_NAME = 'ilDeposito.org';
const MAX_TITLE_LEN = 47;
const MAX_DESC_LEN = 155;

export function truncate(text, maxLen) {
  if (!text || text.length <= maxLen) return text || '';
  const truncated = text.slice(0, maxLen);
  const lastSpace = truncated.lastIndexOf(' ');
  return (lastSpace > 0 ? truncated.slice(0, lastSpace) : truncated) + '…';
}

export function stripHtml(html) {
  if (!html) return '';
  return html.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
}

export function buildTitle(pageTitle, suffix = SITE_NAME) {
  const title = truncate(pageTitle, MAX_TITLE_LEN) || suffix;
  return `${title} | ${suffix}`;
}

export function buildDescription(text, fallback = '') {
  const raw = text || fallback;
  return truncate(stripHtml(raw), MAX_DESC_LEN);
}

export function buildCanonical(Astro) {
  const url = new URL(Astro.url.pathname, Astro.site);
  url.pathname = url.pathname.replace(/\/+$/, '') || '/';
  return url.href;
}

export function resolveOgImage(imagePath, site) {
  if (!imagePath) return null;
  if (imagePath.startsWith('http')) return imagePath;
  return new URL(imagePath, site).href;
}
