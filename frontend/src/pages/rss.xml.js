import rss from '@astrojs/rss';
import { getAutoriImmaginiMap, getCantiRecentiDettaglio } from '../lib/api/index.js';
import { getAutoreImageUrl } from '../lib/api/drupal/assets.js';
import { buildCantoRssDescription } from '../lib/seo.js';

function escapeHtmlAttribute(value) {
  return String(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
}

function taxonomyMetadata(label, terms = [], route, site) {
  if (terms.length === 0) return '';
  const links = terms.map((term) => {
    const titolo = escapeHtmlAttribute(term.titolo);
    if (!route) return titolo;
    const href = new URL(`${route}/${encodeURIComponent(term.slug)}`, site).href;
    return `<a href="${escapeHtmlAttribute(href)}">${titolo}</a>`;
  });
  return `<br><strong>${label}:</strong> ${links.join(', ')}`;
}

export async function GET(context) {
  const [canti, autoriImmagini] = await Promise.all([
    getCantiRecentiDettaglio(),
    getAutoriImmaginiMap(),
  ]);
  const feedUrl = new URL('/rss.xml', context.site).href;

  return rss({
    title: 'ilDeposito.org — Nuovi canti',
    description: 'Gli ultimi canti aggiunti all\'archivio online di canti di protesta politica e sociale.',
    site: context.site,
    xmlns: {
      atom: 'http://www.w3.org/2005/Atom',
      media: 'http://search.yahoo.com/mrss/',
    },
    customData: [
      '<language>it-IT</language>',
      `<atom:link href="${feedUrl}" rel="self" type="application/rss+xml"/>`,
      `<lastBuildDate>${new Date().toUTCString()}</lastBuildDate>`,
    ].join(''),
    items: await Promise.all(canti.map(async (canto) => {
      const primoAutore = canto.autoriTesto?.[0];
      const immagineRelativa = primoAutore
        ? await getAutoreImageUrl(autoriImmagini.get(primoAutore.slug) ?? null)
        : null;
      const immagineAutore = immagineRelativa
        ? new URL(immagineRelativa, context.site).href
        : null;
      const immagineHtml = immagineAutore
        ? `<img src="${escapeHtmlAttribute(immagineAutore)}" alt="Foto di ${escapeHtmlAttribute(primoAutore.titolo)}" />`
        : '';
      const categorie = [
        ...canto.periodi.map((term) => `Periodo storico: ${term.titolo}`),
        ...canto.tematiche.map((term) => `Tematica: ${term.titolo}`),
        ...canto.tags.map((term) => `Tag: ${term.titolo}`),
      ];
      const metadatiTassonomici = [
        taxonomyMetadata('Periodo storico', canto.periodi, '/periodi', context.site),
        taxonomyMetadata('Tematiche', canto.tematiche),
        taxonomyMetadata('Tag', canto.tags, '/tags', context.site),
      ].join('');

      return {
        title: canto.titolo,
        link: `/canti/${canto.slug}`,
        description: `${immagineHtml}${buildCantoRssDescription(canto)}${metadatiTassonomici}`,
        ...(canto.dataCreazione ? { pubDate: canto.dataCreazione } : {}),
        ...(categorie.length > 0 ? { categories: categorie } : {}),
        ...(immagineAutore ? { customData: `<media:thumbnail url="${escapeHtmlAttribute(immagineAutore)}"/>` } : {}),
      };
    })),
  });
}
