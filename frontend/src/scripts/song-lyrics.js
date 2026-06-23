class SongLyrics extends HTMLElement {
  connectedCallback() {
    const tabs = this.querySelectorAll('[data-tab]');
    const panels = this.querySelectorAll('[data-panel]');

    tabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        tabs.forEach((t) => {
          const active = t === tab;
          t.setAttribute('aria-selected', String(active));
          t.classList.toggle('border-secondary', active);
          t.classList.toggle('text-base-content', active);
          t.classList.toggle('border-transparent', !active);
          t.classList.toggle('text-base-content/40', !active);
        });

        panels.forEach((panel) => {
          panel.classList.toggle('hidden', panel.dataset.panel !== tab.dataset.tab);
        });
      });
    });
  }
}

customElements.define('song-lyrics', SongLyrics);
