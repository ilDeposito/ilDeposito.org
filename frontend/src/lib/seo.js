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

// ── Title helpers per content type ─────────────────────

export function buildCantoTitle(canto) {
  const parti = [canto.titolo, '—', 'Testo'];
  if (canto.accordi) parti.push('e accordi');
  return parti.join(' ');
}

export function buildAutoreTitle(autore) {
  return `${autore.titolo} — Canti e biografia`;
}

export function buildEventoTitle(evento) {
  if (evento.dataEvento) {
    const anno = new Date(evento.dataEvento).getUTCFullYear();
    return `${evento.titolo} (${anno})`;
  }
  return evento.titolo;
}

export function buildTraduzioneTitle(traduzione) {
  const lingua = traduzione.lingue?.[0]?.titolo;
  return lingua
    ? `${traduzione.titolo} — Traduzione in ${lingua}`
    : traduzione.titolo;
}

// ── Description helpers per content type ───────────────

export function buildCantoDescription(canto) {
  const risorse = ['Testo'];
  if (canto.accordi) risorse.push('accordi');

  let desc = `${risorse.join(', ')} di ${canto.titolo}`;

  const autori = [...(canto.autoriTesto ?? []), ...(canto.autoriMusica ?? [])].filter(
    (a, i, arr) => arr.findIndex((x) => x.slug === a.slug) === i
  );
  if (autori.length > 0) {
    desc += ` di ${autori.map((a) => a.titolo).join(' e ')}`;
  }

  if (canto.anno) desc += ` (${canto.anno})`;
  desc += '.';

  const extra = canto.capoverso || stripHtml(canto.informazioni || '');
  if (extra) desc += ` ${extra}`;

  return desc;
}

export function buildAutoreDescription(autore, numCanti) {
  if (autore.informazioni) {
    let desc = stripHtml(autore.informazioni);
    if (numCanti > 0) desc += ` ${numCanti} canti nell'archivio.`;
    return desc;
  }

  let desc = `Biografia e canti di ${autore.titolo}`;
  if (autore.localizzazioni?.length > 0) {
    desc += `, da ${autore.localizzazioni[0].titolo}`;
  }
  desc += '.';
  if (numCanti > 0) {
    desc += ` ${numCanti} canti con testo e accordi nell'archivio di ilDeposito.org.`;
  } else {
    desc += ' Nell\'archivio di ilDeposito.org.';
  }
  return desc;
}

export function buildEventoDescription(evento) {
  if (evento.informazioni) return stripHtml(evento.informazioni);

  let desc = evento.titolo;
  if (evento.dataEvento) {
    const d = new Date(evento.dataEvento);
    desc += `, ${d.toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' })}`;
  }
  if (evento.localizzazioni?.length > 0) {
    desc += ` — ${evento.localizzazioni[0].titolo}`;
  }
  desc += '. Evento storico nell\'archivio di ilDeposito.org.';
  return desc;
}

export function buildTraduzioneDescription(traduzione) {
  if (traduzione.informazioni) return stripHtml(traduzione.informazioni);

  const parti = ['Traduzione'];
  if (traduzione.lingue?.length > 0) {
    parti.push(`in ${traduzione.lingue[0].titolo}`);
  }
  const nome = traduzione.cantoOriginale?.titolo || traduzione.titolo;
  parti.push(`di ${nome}.`);
  parti.push('Testo completo nell\'archivio di ilDeposito.org.');
  return parti.join(' ');
}

