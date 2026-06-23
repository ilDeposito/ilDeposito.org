import rss from '@astrojs/rss';
import { getCantiRecenti } from '../lib/api/index.js';

export async function GET(context) {
  const canti = await getCantiRecenti(50);

  return rss({
    title: 'ilDeposito.org — Canti di protesta politica e sociale',
    description: 'I canti più recenti aggiunti all\'archivio di ilDeposito.org',
    site: context.site,
    language: 'it',
    items: canti.map((canto) => ({
      title: canto.titolo,
      link: `/canti/${canto.slug}`,
      description: canto.capoverso || '',
    })),
  });
}
