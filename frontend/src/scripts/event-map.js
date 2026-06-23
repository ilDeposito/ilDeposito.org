import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import markerIcon from 'leaflet/dist/images/marker-icon.png?url';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png?url';
import markerShadow from 'leaflet/dist/images/marker-shadow.png?url';

// Vite/Rollup non risolvono il path automatico delle icone di Leaflet:
// si forniscono esplicitamente gli asset bundlati.
delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions({
  iconUrl: markerIcon,
  iconRetinaUrl: markerIcon2x,
  shadowUrl: markerShadow,
});

class EventMap extends HTMLElement {
  connectedCallback() {
    const lat = parseFloat(this.dataset.lat);
    const lng = parseFloat(this.dataset.lng);
    if (Number.isNaN(lat) || Number.isNaN(lng)) return;

    const map = L.map(this, { scrollWheelZoom: false }).setView([lat, lng], 11);

    if (L.Browser.touch) {
      map.dragging.disable();
      this.addEventListener('touchstart', (e) => {
        if (e.touches.length >= 2) map.dragging.enable();
      }, { passive: true });
      this.addEventListener('touchend', () => map.dragging.disable(), { passive: true });
    }

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      maxZoom: 19,
    }).addTo(map);

    L.marker([lat, lng]).addTo(map);
  }
}

customElements.define('event-map', EventMap);
