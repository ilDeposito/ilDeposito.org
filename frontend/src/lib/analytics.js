// Wrapper unico per gli eventi custom Umami: lo script è caricato solo in
// produzione (vedi BaseLayout.astro, isProduction), quindi window.umami è
// undefined in dev/staging e su ogni richiesta prima che lo script defer giri.
export function track(event, data) {
  window.umami?.track(event, data);
}
