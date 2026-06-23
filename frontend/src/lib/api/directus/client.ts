const DIRECTUS_URL = import.meta.env.DIRECTUS_URL ?? import.meta.env.PUBLIC_DIRECTUS_URL;
const DIRECTUS_TOKEN = import.meta.env.DIRECTUS_TOKEN;

if (!DIRECTUS_URL) throw new Error('DIRECTUS_URL o PUBLIC_DIRECTUS_URL non definito');
if (!DIRECTUS_TOKEN) throw new Error('DIRECTUS_TOKEN non definito');

export { DIRECTUS_URL, DIRECTUS_TOKEN };

export async function fetchCollection(collection: string, params: Record<string, string> = {}): Promise<any[]> {
  const url = new URL(`/items/${collection}`, DIRECTUS_URL);
  for (const [key, value] of Object.entries(params)) {
    url.searchParams.set(key, value);
  }

  const res = await fetch(url.toString(), {
    headers: {
      Authorization: `Bearer ${DIRECTUS_TOKEN}`,
      'Content-Type': 'application/json',
    },
  });

  if (!res.ok) {
    throw new Error(`Directus fetch fallito per "${collection}": ${res.status} ${res.statusText}`);
  }

  const { data } = await res.json();
  return data;
}

export async function fetchItem(collection: string, id: string | number, params: Record<string, string> = {}): Promise<any> {
  const url = new URL(`/items/${collection}/${id}`, DIRECTUS_URL);
  for (const [key, value] of Object.entries(params)) {
    url.searchParams.set(key, value);
  }

  const res = await fetch(url.toString(), {
    headers: {
      Authorization: `Bearer ${DIRECTUS_TOKEN}`,
    },
  });

  if (!res.ok) {
    throw new Error(`Directus fetch fallito per "${collection}/${id}": ${res.status} ${res.statusText}`);
  }

  const { data } = await res.json();
  return data;
}
