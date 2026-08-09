import { renderAccordi } from './chords.js';

class SongLyrics extends HTMLElement {
  connectedCallback() {
    this._semitones = 0;
    this._originalAccordi = null;

    const tabs = Array.from(this.querySelectorAll('[data-tab]'));
    const panels = this.querySelectorAll('[data-panel]');

    const activateTab = (tab, focus) => {
      tabs.forEach((t) => {
        const active = t === tab;
        t.setAttribute('aria-selected', String(active));
        t.setAttribute('tabindex', active ? '0' : '-1');
        t.classList.toggle('border-secondary', active);
        t.classList.toggle('text-base-content', active);
        t.classList.toggle('border-transparent', !active);
        t.classList.toggle('text-base-content/70', !active);
      });

      panels.forEach((panel) => {
        panel.classList.toggle('hidden', panel.dataset.panel !== tab.dataset.tab);
      });

      if (focus) tab.focus();
    };

    tabs.forEach((tab, i) => {
      tab.addEventListener('click', () => activateTab(tab, false));
      tab.addEventListener('keydown', (e) => {
        let next = null;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = tabs[(i + 1) % tabs.length];
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = tabs[(i - 1 + tabs.length) % tabs.length];
        else if (e.key === 'Home') next = tabs[0];
        else if (e.key === 'End') next = tabs[tabs.length - 1];
        if (next) {
          e.preventDefault();
          activateTab(next, true);
        }
      });
    });

    const accordiPanel = this.querySelector('[data-panel="accordi"]');
    if (!accordiPanel) return;

    const pre = accordiPanel.querySelector('pre');
    if (!pre) return;

    this._originalAccordi = pre.textContent;
    pre.innerHTML = renderAccordi(this._originalAccordi, 0);

    const toolbar = this.querySelector('[data-transpose-toolbar]');
    if (!toolbar) return;

    toolbar.querySelector('[data-transpose="-1"]')?.addEventListener('click', () => this._transpose(-1, pre));
    toolbar.querySelector('[data-transpose="+1"]')?.addEventListener('click', () => this._transpose(1, pre));
    toolbar.querySelector('[data-transpose-reset]')?.addEventListener('click', () => this._reset(pre));
  }

  _transpose(direction, pre) {
    this._semitones += direction;
    if (this._semitones === 0) {
      this._reset(pre);
      return;
    }
    pre.innerHTML = renderAccordi(this._originalAccordi, this._semitones);
    const resetBtn = this.querySelector('[data-transpose-reset]');
    resetBtn?.classList.remove('invisible');
    resetBtn?.removeAttribute('disabled');
  }

  _reset(pre) {
    this._semitones = 0;
    pre.innerHTML = renderAccordi(this._originalAccordi, 0);
    const resetBtn = this.querySelector('[data-transpose-reset]');
    resetBtn?.classList.add('invisible');
    resetBtn?.setAttribute('disabled', '');
  }
}

customElements.define('song-lyrics', SongLyrics);
