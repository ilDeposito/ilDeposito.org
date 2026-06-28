class NavHamburger extends HTMLElement {
  connectedCallback() {
    const toggle = this.querySelector('[data-menu-toggle]');
    const panel = this.querySelector('[data-menu-panel]');
    const closeButtons = this.querySelectorAll('[data-menu-close]');

    if (!toggle || !panel || !closeButtons.length) return;

    const TRANSITION_MS = 300;

    const open = () => {
      panel.classList.remove('invisible', 'pointer-events-none');
      panel.setAttribute('aria-hidden', 'false');
      toggle.setAttribute('aria-expanded', 'true');
      document.body.classList.add('overflow-hidden');

      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          panel.classList.remove('opacity-0', 'scale-95');
        });
      });

      closeButtons[closeButtons.length - 1].focus();
    };

    const closeMenu = () => {
      panel.classList.add('opacity-0', 'scale-95', 'pointer-events-none');
      panel.setAttribute('aria-hidden', 'true');
      toggle.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('overflow-hidden');

      setTimeout(() => panel.classList.add('invisible'), TRANSITION_MS);

      toggle.focus();
    };

    toggle.addEventListener('click', open);
    closeButtons.forEach((btn) => btn.addEventListener('click', closeMenu));

    panel.querySelectorAll('a[href]').forEach((link) => {
      link.addEventListener('click', closeMenu);
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && panel.getAttribute('aria-hidden') === 'false') {
        closeMenu();
      }
    });
  }
}

customElements.define('nav-hamburger', NavHamburger);
