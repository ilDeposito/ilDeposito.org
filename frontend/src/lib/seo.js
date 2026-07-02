import { getPageMeta } from './pages.js';

const SITE_NAME = 'ilDeposito.org';
const MAX_TITLE_LEN = 53;
const MAX_DESC_LEN = 155;

// ── Mapping lingua → codice ISO BCP 47 ────────────────

const LINGUA_TO_ISO = {
  'italiano': 'it',
  'inglese': 'en',
  'francese': 'fr',
  'spagnolo': 'es',
  'tedesco': 'de',
  'portoghese': 'pt',
  'catalano': 'ca',
  'basco': 'eu',
  'greco': 'el',
  'russo': 'ru',
  'arabo': 'ar',
  'turco': 'tr',
  'yiddish': 'yi',
  'napoletano': 'nap',
  'sardo': 'sc',
  'siciliano': 'scn',
  'piemontese': 'pms',
  'friulano': 'fur',
  'ladino': 'lld',
  'occitano': 'oc',
  'romeno': 'ro',
  'polacco': 'pl',
  'ebraico': 'he',
  'giapponese': 'ja',
  'cinese': 'zh',
  'coreano': 'ko',
  'olandese': 'nl',
  'svedese': 'sv',
  'norvegese': 'no',
  'danese': 'da',
  'finlandese': 'fi',
  'ungherese': 'hu',
  'ceco': 'cs',
  'slovacco': 'sk',
  'croato': 'hr',
  'serbo': 'sr',
  'bulgaro': 'bg',
  'ucraino': 'uk',
  'albanese': 'sq',
  'irlandese': 'ga',
  'gallese': 'cy',
  'esperanto': 'eo',
  'latino': 'la',
  'persiano': 'fa',
  'curdo': 'ku',
  'hindi': 'hi',
  'bengalese': 'bn',
  'swahili': 'sw',
};

export function linguaToIso(nome) {
  return LINGUA_TO_ISO[nome?.toLowerCase()] || nome || 'it';
}

// ── Utility base ───────────────────────────────────────

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
  if (title.includes(suffix)) return title;
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

// ── Metadati per content type (entità) ────────────────
// I template vivono in src/config/pages.yaml (chiavi *.detail): qui si prepara
// solo il set di variabili da interpolare. Vedi il commento in cima allo YAML
// per i token disponibili e la sintassi dei condizionali.

const formatDataIT = (data) =>
  new Date(data).toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' });

function cantoVars(canto) {
  const autori = [...(canto.autoriTesto ?? []), ...(canto.autoriMusica ?? [])].filter(
    (a, i, arr) => arr.findIndex((x) => x.slug === a.slug) === i
  );
  return {
    titolo: canto.titolo,
    accordi: canto.accordi ? '1' : '',
    autori: autori.map((a) => a.titolo).join(' e '),
    anno: canto.anno || '',
    extra: canto.capoverso || stripHtml(canto.informazioni || ''),
  };
}

function autoreVars(autore, numCanti = 0) {
  return {
    titolo: autore.titolo,
    bio: autore.informazioni ? stripHtml(autore.informazioni) : '',
    count: numCanti,
    loc: autore.localizzazioni?.[0]?.titolo || '',
  };
}

function eventoVars(evento) {
  return {
    titolo: evento.titolo,
    anno: evento.dataEvento ? new Date(evento.dataEvento).getUTCFullYear() : '',
    info: evento.informazioni ? stripHtml(evento.informazioni) : '',
    data: evento.dataEvento ? formatDataIT(evento.dataEvento) : '',
    loc: evento.localizzazioni?.[0]?.titolo || '',
  };
}

function traduzioneVars(traduzione) {
  return {
    titolo: traduzione.titolo,
    lingua: traduzione.lingue?.[0]?.titolo || '',
    info: traduzione.informazioni ? stripHtml(traduzione.informazioni) : '',
    nome: traduzione.cantoOriginale?.titolo || traduzione.titolo,
  };
}

export function buildCantoTitle(canto) {
  return getPageMeta('canti.detail', cantoVars(canto)).metaTitle;
}
export function buildCantoDescription(canto) {
  return getPageMeta('canti.detail', cantoVars(canto)).metaDescription;
}

export function buildAutoreTitle(autore) {
  return getPageMeta('autori.detail', autoreVars(autore)).metaTitle;
}
export function buildAutoreDescription(autore, numCanti) {
  return getPageMeta('autori.detail', autoreVars(autore, numCanti)).metaDescription;
}

export function buildEventoTitle(evento) {
  return getPageMeta('eventi.detail', eventoVars(evento)).metaTitle;
}
export function buildEventoDescription(evento) {
  return getPageMeta('eventi.detail', eventoVars(evento)).metaDescription;
}

export function buildTraduzioneTitle(traduzione) {
  return getPageMeta('traduzioni.detail', traduzioneVars(traduzione)).metaTitle;
}
export function buildTraduzioneDescription(traduzione) {
  return getPageMeta('traduzioni.detail', traduzioneVars(traduzione)).metaDescription;
}

