class ImageCarousel extends HTMLElement {
  connectedCallback() {
    this._viewport = this.querySelector('[data-viewport]');
    this._track    = this.querySelector('[data-track]');
    this._items    = Array.from(this.querySelectorAll('[data-item]'));
    this._dots     = Array.from(this.querySelectorAll('[data-dot]'));
    this._live     = this.querySelector('[data-carousel-live]');
    this._current  = parseInt(this.dataset.initial ?? '0', 10) || 0;
    this._reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (this._items.length === 0) return;

    this._track.style.transition = this._reduceMotion ? 'none' : 'transform 300ms ease-in-out';
    this._update(false);

    this.querySelector('[data-prev]')?.addEventListener('click', () => this._go(this._current - 1));
    this.querySelector('[data-next]')?.addEventListener('click', () => this._go(this._current + 1));
    this._dots.forEach((dot, i) => dot.addEventListener('click', () => this._go(i)));

    // Touch swipe
    let startX = 0;
    this._viewport.addEventListener('touchstart', (e) => {
      startX = e.touches[0].clientX;
    }, { passive: true });
    this._viewport.addEventListener('touchend', (e) => {
      const dx = e.changedTouches[0].clientX - startX;
      if (Math.abs(dx) > 40) this._go(this._current + (dx < 0 ? 1 : -1));
    }, { passive: true });
  }

  _go(index) {
    this._current = (index + this._items.length) % this._items.length;
    this._update(true);
  }

  _update(animate) {
    const useTransition = animate && !this._reduceMotion;
    if (!useTransition) this._track.style.transition = 'none';
    this._track.style.transform = `translateX(-${this._current * 100}%)`;
    if (!useTransition) {
      // Force reflow then re-enable transition (se il movimento non è ridotto)
      void this._track.offsetWidth;
      if (!this._reduceMotion) this._track.style.transition = 'transform 300ms ease-in-out';
    }
    this._items.forEach((item, i) => item.toggleAttribute('inert', i !== this._current));
    this._dots.forEach((dot, i) => {
      const active = i === this._current;
      const indicator = dot.firstElementChild ?? dot;
      indicator.classList.toggle('opacity-100', active);
      indicator.classList.toggle('opacity-30', !active);
      dot.setAttribute('aria-current', active ? 'true' : 'false');
    });
    if (this._live && animate) {
      this._live.textContent = `Diapositiva ${this._current + 1} di ${this._items.length}`;
    }
  }
}

customElements.define('image-carousel', ImageCarousel);
