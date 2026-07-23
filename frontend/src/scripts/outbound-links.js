import { track } from '../lib/analytics.js';

// Listener delegato invece del decoratore di attributi suggerito dai doc Umami:
// copre anche i link inseriti dinamicamente dopo il load (es. risultati Pagefind).
// 'outbound-link-click' con { url } è la convenzione ufficiale Umami.
document.addEventListener('click', (event) => {
  if (!(event.target instanceof Element)) return;
  const link = event.target.closest('a[href]');
  if (!link) return;
  // Solo http/https: esclude mailto:, tel:, ecc. (host vuoto ma ≠ location.host)
  if (!/^https?:$/.test(link.protocol) || link.host === location.host) return;
  track('outbound-link-click', { url: link.href });
});
