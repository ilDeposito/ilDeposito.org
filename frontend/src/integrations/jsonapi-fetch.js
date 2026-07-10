// Stessa strategia di src/lib/api/drupal/client.ts (che non possiamo importare
// qui: questi moduli girano fuori dalla pipeline Vite): Drupal cappa
// page[limit] a 50, quindi il passo si legge dal link next e le pagine
// successive si scaricano a ondate parallele su page[offset].
const PAGE_CONCURRENCY = 4;

export async function fetchAllPaginated(path, params = {}) {
  const baseUrl = process.env.DRUPAL_API_URL;
  if (!baseUrl) throw new Error('DRUPAL_API_URL deve essere definito');

  const allData = [];
  const allIncluded = [];
  const seenIncluded = new Set();

  const append = (json) => {
    allData.push(...(Array.isArray(json.data) ? json.data : [json.data]));
    for (const item of (json.included ?? [])) {
      const key = `${item.type}:${item.id}`;
      if (!seenIncluded.has(key)) {
        seenIncluded.add(key);
        allIncluded.push(item);
      }
    }
  };

  const fetchPage = async (offset) => {
    const url = new URL(path, baseUrl);
    for (const [key, value] of Object.entries(params)) {
      url.searchParams.set(key, value);
    }
    if (offset > 0) url.searchParams.set('page[offset]', String(offset));

    const res = await fetch(url.toString(), {
      headers: { Accept: 'application/vnd.api+json' },
    });
    if (!res.ok) throw new Error(`JSON:API fetch fallito: ${res.status}`);
    return res.json();
  };

  const first = await fetchPage(0);
  append(first);

  const nextHref = first.links?.next?.href;
  if (!nextHref) return { data: allData, included: allIncluded };

  const step = Number(new URL(nextHref).searchParams.get('page[offset]'));
  if (!Number.isFinite(step) || step <= 0) {
    // Link next non basato su offset: fallback sequenziale.
    let href = nextHref;
    while (href) {
      const res = await fetch(href, { headers: { Accept: 'application/vnd.api+json' } });
      if (!res.ok) throw new Error(`JSON:API fetch fallito: ${res.status}`);
      const json = await res.json();
      append(json);
      href = json.links?.next?.href || null;
    }
    return { data: allData, included: allIncluded };
  }

  let offset = step;
  let done = false;
  while (!done) {
    const wave = [];
    for (let i = 0; i < PAGE_CONCURRENCY; i++) {
      wave.push(fetchPage(offset + i * step));
    }
    offset += PAGE_CONCURRENCY * step;

    for (const json of await Promise.all(wave)) {
      append(json);
      if (!json.links?.next?.href) done = true;
    }
  }

  return { data: allData, included: allIncluded };
}

export function includedMapOf(included) {
  const map = new Map();
  for (const item of included) {
    map.set(`${item.type}:${item.id}`, item);
  }
  return map;
}

export function resolveNames(rel, includedMap) {
  const refs = Array.isArray(rel?.data) ? rel.data : rel?.data ? [rel.data] : [];
  return refs
    .map((ref) => includedMap.get(`${ref.type}:${ref.id}`))
    .filter(Boolean)
    .map((item) => item.attributes.title ?? item.attributes.name);
}

export function extractSlug(alias) {
  if (!alias) return '';
  return alias.split('/').pop() ?? '';
}
