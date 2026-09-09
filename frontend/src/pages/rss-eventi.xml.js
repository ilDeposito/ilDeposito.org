import rss from '@astrojs/rss';
import { getEventiAnniversarioRecenti } from '../lib/api/index.js';
import { getEventoImageUrl } from '../lib/api/drupal/assets.js';

const eventDateFormatter = new Intl.DateTimeFormat('it-IT', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
});

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('"', '&quot;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');
}

function taxonomyMetadata(label, terms = [], route, site) {
  if (terms.length === 0) return '';
  const links = terms.map((term) => {
    const titolo = escapeHtml(term.titolo);
    if (!route) return titolo;
    const href = new URL(`${route}/${encodeURIComponent(term.slug)}`, site).href;
    return `<a href="${escapeHtml(href)}">${titolo}</a>`;
  });
  return `<strong>${label}:</strong> ${links.join(', ')}`;
}

export async function GET(context) {
  const eventi = await getEventiAnniversarioRecenti();
  const feedUrl = new URL('/rss-eventi.xml', context.site).href;

  return rss({
    title: 'ilDeposito.org — Anniversari storici',
    description: 'Gli anniversari degli eventi storici legati ai canti di protesta, aggiornati ogni giorno.',
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
    items: await Promise.all(eventi.map(async (evento) => {
      const immagineRelativa = await getEventoImageUrl(evento.immagine);
      const immagine = immagineRelativa ? new URL(immagineRelativa, context.site).href : null;
      const informazioni = evento.informazioni || 'Evento storico nell\'archivio di ilDeposito.org.';
      const metadati = [
        taxonomyMetadata('Periodo storico', evento.periodi, '/periodi', context.site),
        taxonomyMetadata('Tag', evento.tags, '/tags', context.site),
        taxonomyMetadata('Tematiche', evento.tematiche),
      ].filter(Boolean).join('<br>');
      const immagineHtml = immagine
        ? `<img src="${escapeHtml(immagine)}" alt="${escapeHtml(evento.titolo)}" />`
        : '';

      return {
        title: `${eventDateFormatter.format(new Date(evento.dataEvento))} - ${evento.titolo}`,
        link: `/eventi/${evento.slug}`,
        description: `${immagineHtml}${informazioni}${metadati ? `<br><br>${metadati}` : ''}`,
        pubDate: evento.dataEvento,
        ...(immagine ? { customData: `<media:thumbnail url="${escapeHtml(immagine)}"/>` } : {}),
        categories: [
          ...evento.periodi.map((term) => `Periodo storico: ${term.titolo}`),
          ...evento.tags.map((term) => `Tag: ${term.titolo}`),
          ...evento.tematiche.map((term) => `Tematica: ${term.titolo}`),
        ],
      };
    })),
  });
}
