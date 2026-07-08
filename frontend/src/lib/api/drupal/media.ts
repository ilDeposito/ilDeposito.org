import { fetchAllMediaHeaderRaw } from './store.js';
import { buildIncludedMap, resolveRef } from './resolvers.js';
import { getImageUrl } from './assets.js';
import { getPeriodoWatermarkCasuale } from './tassonomie.js';

async function getImmaginiHeaderCasuali(): Promise<string[]> {
  const { data, included } = await fetchAllMediaHeaderRaw();
  const map = buildIncludedMap(included);

  return data
    .map((item: any) => {
      const file = resolveRef(item.relationships?.field_media_image, map);
      return getImageUrl(file?.attributes?.uri?.url ?? null);
    })
    .filter((url): url is string => !!url);
}

// Immagine casuale tra i media taggati "field_header" in Drupal; se non ce ne
// sono, fallback sull'immagine del periodo (vedi getPeriodoWatermarkCasuale).
// Su un'installazione senza contenuti (es. prod appena avviata da config
// vuota) entrambe le fonti restituiscono array vuoti: nessun errore, il
// componente semplicemente non renderizza la filigrana.
export async function getWatermarkImageUrl(): Promise<string | null> {
  try {
    const immaginiHeader = await getImmaginiHeaderCasuali();
    if (immaginiHeader.length > 0) {
      return immaginiHeader[Math.floor(Math.random() * immaginiHeader.length)];
    }

    return await getPeriodoWatermarkCasuale();
  } catch {
    return null;
  }
}
