// Accesso centralizzato ai metadati di pagina definiti in src/config/pages.yaml.
//
// Ogni pagina dichiara una CHIAVE esplicita (es. 'tags.term') e passa le
// variabili disponibili a build time. I template nello YAML possono usare:
//
//   {var}                interpolazione semplice (stringa vuota se assente)
//   {?var}...{/var}      blocco mostrato solo se `var` è "pieno"
//   {^var}...{/var}      blocco mostrato solo se `var` è "vuoto" (l'else)
//   {count|canto|canti}  plurale: "canto" se count === 1, altrimenti "canti"
//
// "vuoto" = undefined, null, false, '' oppure 0. Tutto il resto è "pieno".
// I blocchi possono essere annidati e contenere altri {var}/plurali.

import pagesData from '../config/pages.yaml';

export interface RawPageMeta {
  /** <title> del tag <head>. Se assente eredita da pageTitle. */
  metaTitle?: string;
  /** <meta name="description">. Se assente eredita da pageDescription. */
  metaDescription?: string;
  /** Titolo visibile in pagina (<h1>). */
  pageTitle?: string;
  /** Sottotitolo visibile sotto il titolo. */
  pageDescription?: string;
  /** Aggiunge <meta name="robots" content="noindex,nofollow">. */
  noindex?: boolean;
}

export interface PageMeta {
  metaTitle: string;
  metaDescription: string;
  pageTitle: string;
  pageDescription: string;
  noindex: boolean;
}

export type PageVars = Record<string, string | number | boolean | null | undefined>;

const pages = pagesData as Record<string, RawPageMeta>;

function isFull(value: unknown): boolean {
  return value !== undefined && value !== null && value !== false && value !== '' && value !== 0;
}

/** Applica il mini-linguaggio di template alle variabili fornite. */
export function render(template: string, vars: PageVars): string {
  let out = template;

  // 1. Blocchi condizionali {?var}...{/var} e {^var}...{/var}.
  //    Il lookahead negativo isola il blocco più interno; il ciclo ripete
  //    finché restano blocchi (gestisce annidamento e blocchi in sequenza).
  const blockRe = /\{([?^])(\w+)\}((?:(?!\{[?^]\w+\}).)*?)\{\/\2\}/s;
  let prev: string;
  do {
    prev = out;
    out = out.replace(blockRe, (_m, kind: string, name: string, inner: string) => {
      const full = isFull(vars[name]);
      return (kind === '?' ? full : !full) ? inner : '';
    });
  } while (out !== prev);

  // 2. Plurali {count|singolare|plurale}.
  out = out.replace(/\{(\w+)\|([^|{}]*)\|([^|{}]*)\}/g, (_m, name: string, sing: string, plur: string) =>
    Number(vars[name]) === 1 ? sing : plur,
  );

  // 3. Interpolazione semplice {var}.
  out = out.replace(/\{(\w+)\}/g, (_m, name: string) => {
    const v = vars[name];
    return v === undefined || v === null ? '' : String(v);
  });

  return out.trim();
}

/**
 * Restituisce i metadati risolti per una chiave di pagina.
 * @param key   chiave definita in pages.yaml (es. 'contatti', 'tags.term')
 * @param vars  variabili per l'interpolazione dei template
 */
export function getPageMeta(key: string, vars: PageVars = {}): PageMeta {
  const raw = pages[key];
  if (!raw) {
    throw new Error(`[pages] Chiave "${key}" non trovata in src/config/pages.yaml`);
  }

  const pageTitle = render(raw.pageTitle ?? '', vars);
  const pageDescription = render(raw.pageDescription ?? '', vars);

  return {
    pageTitle,
    pageDescription,
    metaTitle: raw.metaTitle !== undefined ? render(raw.metaTitle, vars) : pageTitle,
    metaDescription: raw.metaDescription !== undefined ? render(raw.metaDescription, vars) : pageDescription,
    noindex: raw.noindex ?? false,
  };
}
