class NavHamburger extends HTMLElement {
  connectedCallback() {
    const toggle = this.querySelector('[data-menu-toggle]');
    const panel = this.querySelector('[data-menu-panel]');
    const closeButtons = this.querySelectorAll('[data-menu-close]');

    if (!toggle || !panel || !closeButtons.length) return;

    const open = () => {
      panel.showModal();
      toggle.setAttribute('aria-expanded', 'true');
      document.body.classList.add('overflow-hidden');
      closeButtons[closeButtons.length - 1].focus();
    };

    const closeMenu = () => {
      if (panel.open) panel.close();
    };

    toggle.addEventListener('click', open);
    closeButtons.forEach((btn) => btn.addEventListener('click', closeMenu));

    panel.querySelectorAll('a[href]').forEach((link) => {
      link.addEventListener('click', closeMenu);
    });

    // Click sul backdrop nativo del dialog (fuori dal contenuto) chiude il menu
    panel.addEventListener('click', (event) => {
      if (event.target === panel) closeMenu();
    });

    // Copre sia Esc (evento 'cancel' nativo) sia close() esplicita
    panel.addEventListener('close', () => {
      toggle.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('overflow-hidden');
      toggle.focus();
    });
  }
}

customElements.define('nav-hamburger', NavHamburger);
