/**
 * @param {string} url
 * @param {{ source: string, medium: string, campaign: string }} params
 * @returns {string}
 */
export function withUtm(url, { source, medium, campaign }) {
  const tagged = new URL(url);
  tagged.searchParams.set('utm_source', source);
  tagged.searchParams.set('utm_medium', medium);
  tagged.searchParams.set('utm_campaign', campaign);
  return tagged.href;
}
