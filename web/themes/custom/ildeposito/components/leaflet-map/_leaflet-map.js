((Drupal, once) => {
  Drupal.behaviors.ilDepositoLeafletMap = {
    attach(context) {
      once('ildeposito-leaflet-map', '.leaflet-map', context).forEach((el) => {
        const lat = parseFloat(el.dataset.lat);
        const lon = parseFloat(el.dataset.lon);
        const zoom = parseInt(el.dataset.zoom, 10) || 13;

        // Valori obbligatori: se mancano o non sono numeri validi, non inizializzare
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;

        // scrollWheelZoom disabilitato di default: lo scroll della pagina non zooma la mappa.
        // Si attiva solo quando l'utente clicca/tocca intenzionalmente la mappa.
        const map = L.map(el, { scrollWheelZoom: false }).setView([lat, lon], zoom);

        el.addEventListener('click', () => map.scrollWheelZoom.enable());
        el.addEventListener('mouseleave', () => map.scrollWheelZoom.disable());

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution:
            '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
          maxZoom: 19,
        }).addTo(map);

        L.marker([lat, lon]).addTo(map);
      });
    },
  };
})(Drupal, once);
