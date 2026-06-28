import 'leaflet/dist/leaflet.css';

class EventMap extends HTMLElement {
  connectedCallback() {
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry.isIntersecting) return;
        observer.disconnect();
        this._initMap();
      },
      { rootMargin: '200px' }
    );
    observer.observe(this);
  }

  async _initMap() {
    const lat = parseFloat(this.dataset.lat);
    const lng = parseFloat(this.dataset.lng);
    if (Number.isNaN(lat) || Number.isNaN(lng)) return;

    const [{ default: L }, { default: markerIcon }, { default: markerIcon2x }, { default: markerShadow }] = await Promise.all([
      import('leaflet'),
      import('leaflet/dist/images/marker-icon.png?url'),
      import('leaflet/dist/images/marker-icon-2x.png?url'),
      import('leaflet/dist/images/marker-shadow.png?url'),
    ]);
    delete L.Icon.Default.prototype._getIconUrl;
    L.Icon.Default.mergeOptions({
      iconUrl: markerIcon,
      iconRetinaUrl: markerIcon2x,
      shadowUrl: markerShadow,
    });

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
