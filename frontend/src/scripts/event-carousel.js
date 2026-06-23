class EventCarousel extends HTMLElement {
  connectedCallback() {
    const slides = this.querySelectorAll('[data-event-slide]');
    if (slides.length <= 1) return;

    const counter = this.querySelector('[data-event-counter]');
    let current = 0;

    setInterval(() => {
      slides[current].classList.add('opacity-0', 'pointer-events-none');
      slides[current].classList.remove('opacity-100');
      current = (current + 1) % slides.length;
      slides[current].classList.remove('opacity-0', 'pointer-events-none');
      slides[current].classList.add('opacity-100');
      if (counter) counter.textContent = String(current + 1);
    }, 5000);
  }
}

customElements.define('event-carousel', EventCarousel);
