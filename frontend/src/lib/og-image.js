import { getPeriodi } from './api/index.js';
import { getAutoreImageUrl, getEventoImageUrl, getPeriodoImageUrl, getPaginaOgImageUrl } from './api/index.js';

let periodoImageMapCache = null;
let randomPeriodoImageCache = null;

async function getPeriodoImageMap() {
  if (periodoImageMapCache) return periodoImageMapCache;
  const periodi = await getPeriodi();
  const entries = await Promise.all(
    periodi.map(async (p) => {
      const url = p.immagine ? await getPeriodoImageUrl(p.immagine) : null;
      return [p.slug, url];
    })
  );
  periodoImageMapCache = new Map(entries.filter(([, url]) => url));
  return periodoImageMapCache;
}

async function getRandomPeriodoImage() {
  if (randomPeriodoImageCache !== null) return randomPeriodoImageCache;
  const map = await getPeriodoImageMap();
  const values = [...map.values()];
  // "Casuale" ma deterministico (ruota col giorno, non con la build):
  // Math.random() cambiava l'og:image di fallback a ogni build, rendendo
  // gli HTML mai byte-identici e vanificando la cache di precompressione.
  const day = Number(new Date().toISOString().slice(0, 10).replaceAll('-', ''));
  randomPeriodoImageCache = values.length > 0
    ? values[day % values.length]
    : null;
  return randomPeriodoImageCache;
}

async function resolvePeriodoImageBySlug(slug) {
  const map = await getPeriodoImageMap();
  return map.get(slug) || null;
}

export async function getOgImageForAutore(autore) {
  if (autore.immagine) {
    const url = await getAutoreImageUrl(autore.immagine);
    if (url) return url;
  }
  for (const p of autore.periodi ?? []) {
    const url = await resolvePeriodoImageBySlug(p.slug);
    if (url) return url;
  }
  return getRandomPeriodoImage();
}

export async function getOgImageForCanto(canto, firstAutoreImmagine) {
  if (firstAutoreImmagine) {
    const url = await getAutoreImageUrl(firstAutoreImmagine);
    if (url) return url;
  }
  for (const p of canto.periodi ?? []) {
    const url = await resolvePeriodoImageBySlug(p.slug);
    if (url) return url;
  }
  return getRandomPeriodoImage();
}

export async function getOgImageForEvento(evento) {
  if (evento.immagine) {
    const url = await getEventoImageUrl(evento.immagine);
    if (url) return url;
  }
  for (const p of evento.periodi ?? []) {
    const url = await resolvePeriodoImageBySlug(p.slug);
    if (url) return url;
  }
  return getRandomPeriodoImage();
}

export async function getOgImageForPeriodo(periodo) {
  if (periodo.immagine) {
    const url = await getPeriodoImageUrl(periodo.immagine);
    if (url) return url;
  }
  return getRandomPeriodoImage();
}

export async function getOgImageFallback() {
  return getRandomPeriodoImage();
}

// Le pagine ("pagina") non hanno periodi collegati: se field_immagine è
// valorizzato viene usata (solo ritagliata, nessuna trasformazione di
// colore/trasparenza — vedi cropToOgImage in assets.ts); altrimenti nessun
// ogImage viene restituito e BaseLayout applica il fallback statico
// /og-default.jpg.
export async function getOgImageForPagina(pagina) {
  if (pagina.immagine) {
    const url = await getPaginaOgImageUrl(pagina.immagine);
    if (url) return url;
  }
  return null;
}
