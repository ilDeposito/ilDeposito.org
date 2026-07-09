import 'leaflet/dist/leaflet.css';
import markerClusterCss from 'leaflet.markercluster/dist/MarkerCluster.css?inline';
import markerClusterDefaultCss from 'leaflet.markercluster/dist/MarkerCluster.Default.css?inline';

const cssInjected = false;
function injectMarkerClusterCss() {
  if (document.querySelector('[data-marker-cluster-css]')) return;
  const style = document.createElement('style');
  style.setAttribute('data-marker-cluster-css', '');
  style.textContent = markerClusterCss + markerClusterDefaultCss;
  document.head.appendChild(style);
}

function _esc(s) { if (!s) return ''; const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

class EventsMap extends HTMLElement {
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
    const raw = this.dataset.eventi;
    if (!raw) return;

    let eventi;
    try { eventi = JSON.parse(raw); } catch { return; }
    if (!eventi.length) return;

    injectMarkerClusterCss();

    const container = document.createElement('div');
    container.style.width = '100%';
    container.style.height = '100%';
    this.appendChild(container);

    const [{ default: L }, { default: markerIcon }, { default: markerIcon2x }, { default: markerShadow }] = await Promise.all([
      import('leaflet'),
      import('leaflet/dist/images/marker-icon.png?url'),
      import('leaflet/dist/images/marker-icon-2x.png?url'),
      import('leaflet/dist/images/marker-shadow.png?url'),
    ]);
    await import('leaflet.markercluster');

    delete L.Icon.Default.prototype._getIconUrl;
    L.Icon.Default.mergeOptions({
      iconUrl: markerIcon,
      iconRetinaUrl: markerIcon2x,
      shadowUrl: markerShadow,
    });

    const map = L.map(container, { scrollWheelZoom: false });
    container.style.backgroundColor = '#a8c8d8';

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      maxZoom: 19,
    }).addTo(map);

    if (L.Browser.touch) {
      map.dragging.disable();
      this.addEventListener('touchstart', (e) => {
        if (e.touches.length >= 2) map.dragging.enable();
      }, { passive: true });
      this.addEventListener('touchend', () => map.dragging.disable(), { passive: true });
    }

    const cluster = L.markerClusterGroup({ chunkedLoading: true });
    const primaryColor = getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim();

    for (const ev of eventi) {
      const d = new Date(ev.data_evento);
      const dateStr = `${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}.${d.getFullYear()}`;
      L.marker([ev.latitude, ev.longitude], { alt: ev.titolo, title: ev.titolo })
        .bindPopup(
          `<a href="/eventi/${_esc(ev.slug)}" style="font-weight:bold;color:${primaryColor};text-decoration:none">${_esc(ev.titolo)}</a>` +
          `<br><span style="font-size:0.85em">${_esc(dateStr)}</span>`
        )
        .addTo(cluster);
    }

    map.addLayer(cluster);
    map.fitBounds(cluster.getBounds(), { padding: [20, 20] });
    requestAnimationFrame(() => map.invalidateSize());
  }
}

customElements.define('events-map', EventsMap);
