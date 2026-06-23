class ImageCarousel extends HTMLElement {
  connectedCallback() {
    this._viewport = this.querySelector('[data-viewport]');
    this._track    = this.querySelector('[data-track]');
    this._items    = Array.from(this.querySelectorAll('[data-item]'));
    this._dots     = Array.from(this.querySelectorAll('[data-dot]'));
    this._current  = parseInt(this.dataset.initial ?? '0', 10) || 0;

    if (this._items.length === 0) return;

    this._track.style.transition = 'transform 300ms ease-in-out';
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
    if (!animate) this._track.style.transition = 'none';
    this._track.style.transform = `translateX(-${this._current * 100}%)`;
    if (!animate) {
      // Force reflow then re-enable transition
      void this._track.offsetWidth;
      this._track.style.transition = 'transform 300ms ease-in-out';
    }
    this._dots.forEach((dot, i) => {
      dot.classList.toggle('opacity-100', i === this._current);
      dot.classList.toggle('opacity-30', i !== this._current);
    });
  }
}

customElements.define('image-carousel', ImageCarousel);
