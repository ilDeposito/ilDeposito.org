const DRUPAL_API_URL = import.meta.env.DRUPAL_API_URL;

if (!DRUPAL_API_URL) throw new Error('DRUPAL_API_URL non definito');

export { DRUPAL_API_URL };

export interface JsonApiResponse {
  data: any[];
  included?: any[];
  links?: { next?: { href: string } };
}

export async function fetchJsonApi(path: string, params?: URLSearchParams): Promise<JsonApiResponse> {
  const url = new URL(path, DRUPAL_API_URL);
  if (params) {
    for (const [key, value] of params) {
      url.searchParams.set(key, value);
    }
  }

  const res = await fetch(url.toString(), {
    headers: { Accept: 'application/vnd.api+json' },
  });

  if (!res.ok) {
    throw new Error(`Drupal JSON:API fetch fallito per "${path}": ${res.status} ${res.statusText}`);
  }

  return res.json();
}

export async function fetchAllJsonApi(path: string, params?: URLSearchParams): Promise<JsonApiResponse> {
  const allData: any[] = [];
  const allIncluded: any[] = [];
  const seenIncluded = new Set<string>();

  let response = await fetchJsonApi(path, params);
  allData.push(...(Array.isArray(response.data) ? response.data : [response.data]));

  if (response.included) {
    for (const item of response.included) {
      const key = `${item.type}:${item.id}`;
      if (!seenIncluded.has(key)) {
        seenIncluded.add(key);
        allIncluded.push(item);
      }
    }
  }

  while (response.links?.next?.href) {
    const nextRes = await fetch(response.links.next.href, {
      headers: { Accept: 'application/vnd.api+json' },
    });
    if (!nextRes.ok) break;

    response = await nextRes.json();
    allData.push(...(Array.isArray(response.data) ? response.data : [response.data]));

    if (response.included) {
      for (const item of response.included) {
        const key = `${item.type}:${item.id}`;
        if (!seenIncluded.has(key)) {
          seenIncluded.add(key);
          allIncluded.push(item);
        }
      }
    }
  }

  return { data: allData, included: allIncluded };
}
