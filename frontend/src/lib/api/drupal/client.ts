const DRUPAL_API_URL = import.meta.env.DRUPAL_API_URL;

if (!DRUPAL_API_URL) throw new Error('DRUPAL_API_URL non definito');

export { DRUPAL_API_URL };

export interface JsonApiResponse {
  data: any[];
  included?: any[];
  links?: { next?: { href: string } };
}

// Limite globale, non per-collezione: store.ts lancia tutti i content type
// in parallelo al primo warmAll() e ognuno pagina con più richieste
// concorrenti, quindi senza un tetto condiviso il fan-out reale verso
// Drupal può arrivare a decine di richieste simultanee e saturare il pool
// PHP-FPM (che ne va in OOM/crash sotto carico) → nginx risponde 502.
const GLOBAL_CONCURRENCY = 4;
let activeRequests = 0;
const waitQueue: (() => void)[] = [];

async function acquireSlot(): Promise<void> {
  if (activeRequests < GLOBAL_CONCURRENCY) {
    activeRequests++;
    return;
  }
  await new Promise<void>((resolve) => waitQueue.push(resolve));
  activeRequests++;
}

function releaseSlot(): void {
  activeRequests--;
  const next = waitQueue.shift();
  if (next) next();
}

export async function fetchJsonApi(path: string, params?: URLSearchParams): Promise<JsonApiResponse> {
  const url = new URL(path, DRUPAL_API_URL);
  if (params) {
    for (const [key, value] of params) {
      url.searchParams.set(key, value);
    }
  }

  await acquireSlot();
  try {
    const res = await fetch(url.toString(), {
      headers: { Accept: 'application/vnd.api+json' },
    });

    if (!res.ok) {
      throw new Error(`Drupal JSON:API fetch fallito per "${path}": ${res.status} ${res.statusText}`);
    }

    return res.json();
  } finally {
    releaseSlot();
  }
}

// Le waves di fetchAllJsonApi lanciano fino a PAGE_CONCURRENCY richieste,
// ma passano comunque per il gate globale sopra: questo valore stabilisce
// solo la granularità di paginazione, non il concorrenza reale verso Drupal.
const PAGE_CONCURRENCY = 4;

export async function fetchAllJsonApi(path: string, params?: URLSearchParams): Promise<JsonApiResponse> {
  const allData: any[] = [];
  const allIncluded: any[] = [];
  const seenIncluded = new Set<string>();

  const append = (response: JsonApiResponse) => {
    allData.push(...(Array.isArray(response.data) ? response.data : [response.data]));
    for (const item of response.included ?? []) {
      const key = `${item.type}:${item.id}`;
      if (!seenIncluded.has(key)) {
        seenIncluded.add(key);
        allIncluded.push(item);
      }
    }
  };

  const first = await fetchJsonApi(path, params);
  append(first);

  const nextHref = first.links?.next?.href;
  if (!nextHref) return { data: allData, included: allIncluded };

  // Drupal cappa page[limit] a 50 qualunque valore si chieda: il passo reale
  // di paginazione va letto dall'offset del link next, non dal limit richiesto.
  const step = Number(new URL(nextHref).searchParams.get('page[offset]'));

  if (!Number.isFinite(step) || step <= 0) {
    // Link next non basato su offset: fallback sequenziale.
    let href: string | undefined = nextHref;
    while (href) {
      await acquireSlot();
      let page: JsonApiResponse;
      try {
        const res = await fetch(href, { headers: { Accept: 'application/vnd.api+json' } });
        if (!res.ok) break;
        page = await res.json();
      } finally {
        releaseSlot();
      }
      append(page);
      href = page.links?.next?.href;
    }
    return { data: allData, included: allIncluded };
  }

  let offset = step;
  let done = false;
  while (!done) {
    const wave: Promise<JsonApiResponse>[] = [];
    for (let i = 0; i < PAGE_CONCURRENCY; i++) {
      const waveParams = new URLSearchParams(params);
      waveParams.set('page[offset]', String(offset + i * step));
      wave.push(fetchJsonApi(path, waveParams));
    }
    offset += PAGE_CONCURRENCY * step;

    // Append in ordine di offset per preservare l'ordinamento della collezione.
    for (const page of await Promise.all(wave)) {
      append(page);
      if (!page.links?.next?.href) done = true;
    }
  }

  return { data: allData, included: allIncluded };
}
