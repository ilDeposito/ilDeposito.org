class EventCarousel extends HTMLElement {
  connectedCallback() {
    const slides = this.querySelectorAll('[data-event-slide]');
    if (slides.length <= 1) return;

    const counter = this.querySelector('[data-event-counter]');
    const toggle = this.querySelector('[data-event-toggle]');
    const iconPause = this.querySelector('[data-event-icon-pause]');
    const iconPlay = this.querySelector('[data-event-icon-play]');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    let current = 0;
    let timer = null;

    const advance = () => {
      slides[current].classList.add('opacity-0', 'pointer-events-none');
      slides[current].classList.remove('opacity-100');
      slides[current].setAttribute('inert', '');
      current = (current + 1) % slides.length;
      slides[current].classList.remove('opacity-0', 'pointer-events-none');
      slides[current].classList.add('opacity-100');
      slides[current].removeAttribute('inert');
      if (counter) counter.textContent = String(current + 1);
    };

    const play = () => {
      if (timer) return;
      timer = setInterval(advance, 5000);
      toggle?.setAttribute('aria-label', 'Metti in pausa la rotazione degli eventi');
      iconPause?.classList.remove('hidden');
      iconPlay?.classList.add('hidden');
    };

    const pause = () => {
      clearInterval(timer);
      timer = null;
      toggle?.setAttribute('aria-label', 'Riprendi la rotazione degli eventi');
      iconPause?.classList.add('hidden');
      iconPlay?.classList.remove('hidden');
    };

    toggle?.addEventListener('click', () => (timer ? pause() : play()));

    if (reduceMotion) {
      pause();
      return;
    }

    this.addEventListener('mouseenter', pause);
    this.addEventListener('mouseleave', () => { if (!timer) play(); });
    this.addEventListener('focusin', pause);
    this.addEventListener('focusout', (e) => { if (!this.contains(e.relatedTarget)) play(); });

    play();
  }
}

customElements.define('event-carousel', EventCarousel);
