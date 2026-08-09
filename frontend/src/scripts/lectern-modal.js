import { renderAccordi } from './chords.js';

const MIN_FONT_SIZE = 0;
const MAX_FONT_SIZE = 12;
const MIN_SPEED = 2;
const MAX_SPEED = 80;
const SPEED_STEP = 1;

class LecternModal extends HTMLElement {
  connectedCallback() {
    this.dialog = this.querySelector('[data-lectern-dialog]');
    this.openButton = this.querySelector('[data-lectern-open]');
    this.closeButton = this.querySelector('[data-lectern-close]');
    this.shell = this.querySelector('.lectern-shell');
    this.reader = this.querySelector('[data-lectern-reader]');
    this.content = this.querySelector('[data-lectern-content]');
    this.status = this.querySelector('[data-lectern-status]');
    this.hasAccordi = this.dataset.hasAccordi === 'true';
    this.testo = this.querySelector('[data-lectern-source="testo"]')?.textContent || '';
    this.accordi = this.querySelector('[data-lectern-source="accordi"]')?.textContent || '';

    this.openButton.addEventListener('click', () => this.open());
    this.closeButton.addEventListener('click', () => this.dialog.close());
    this.dialog.addEventListener('close', () => this.reset());
    this.dialog.addEventListener('cancel', () => this.stopScroll());
    this.querySelectorAll('[data-lectern-toggle-accordi]').forEach((button) => button.addEventListener('click', () => this.toggleAccordi()));
    this.querySelector('[data-lectern-theme]')?.addEventListener('click', () => this.toggleTheme());
    this.querySelector('[data-lectern-wake-lock]')?.addEventListener('click', () => this.toggleWakeLock());
    this.querySelector('[data-lectern-scroll]')?.addEventListener('click', () => this.toggleScroll());
    const optionsButton = this.querySelector('[data-lectern-options-toggle]');
    optionsButton?.addEventListener('pointerup', (event) => {
      if (event.pointerType === 'mouse') return;
      this.ignoreNextOptionsClick = true;
      this.toggleOptions();
      setTimeout(() => { this.ignoreNextOptionsClick = false; }, 0);
    });
    optionsButton?.addEventListener('click', () => {
      if (this.ignoreNextOptionsClick) {
        this.ignoreNextOptionsClick = false;
        return;
      }
      this.toggleOptions();
    });
    this.querySelectorAll('[data-lectern-transpose]').forEach((button) => button.addEventListener('click', () => this.transpose(Number(button.dataset.lecternTranspose))));
    this.querySelectorAll('[data-lectern-speed]').forEach((button) => button.addEventListener('click', () => this.changeSpeed(Number(button.dataset.lecternSpeed))));
    this.querySelectorAll('[data-lectern-font]').forEach((button) => button.addEventListener('click', () => this.changeFont(Number(button.dataset.lecternFont))));
    document.addEventListener('visibilitychange', () => this.handleVisibilityChange());
  }

  open() {
    this.semitones = 0;
    this.showingAccordi = this.hasAccordi;
    this.fontSize = 2;
    this.speed = 6;
    this.isScrolling = false;
    this.wakeRequested = false;
    this.wakeLock = null;
    const isDesktop = window.matchMedia('(min-width: 1024px)').matches;
    this.optionsOpen = isDesktop;
    const options = this.querySelector('[data-lectern-options]');
    const optionsButton = this.querySelector('[data-lectern-options-toggle]');
    if (options) options.hidden = !isDesktop;
    optionsButton?.setAttribute('aria-expanded', String(isDesktop));
    this.shell.dataset.theme = 'light';
    this.shell.dataset.fontSize = String(this.fontSize);
    this.render();
    this.syncControls();
    this.dialog.showModal();
    this.reader.scrollTop = 0;
    this.closeButton.focus();
  }

  reset() {
    this.stopScroll();
    this.releaseWakeLock();
    this.openButton.focus();
  }

  render() {
    if (this.showingAccordi) this.content.innerHTML = renderAccordi(this.accordi, this.semitones);
    else this.content.textContent = this.testo;
  }

  toggleAccordi() {
    this.showingAccordi = !this.showingAccordi;
    this.render();
    this.syncControls();
  }

  transpose(direction) {
    this.semitones += direction;
    this.render();
    this.announce(this.semitones === 0 ? 'Tonalità originale' : `Trasposizione ${this.semitones > 0 ? '+' : ''}${this.semitones} semitoni`);
  }

  toggleTheme() {
    const dark = this.shell.dataset.theme !== 'dark';
    this.shell.dataset.theme = dark ? 'dark' : 'light';
    this.syncControls();
  }

  toggleOptions() {
    this.optionsOpen = !this.optionsOpen;
    const options = this.querySelector('[data-lectern-options]');
    const button = this.querySelector('[data-lectern-options-toggle]');
    if (options) options.hidden = !this.optionsOpen;
    button?.setAttribute('aria-expanded', String(this.optionsOpen));
    button?.setAttribute('aria-label', this.optionsOpen ? 'Nascondi opzioni del leggio' : 'Mostra opzioni del leggio');
    if (button) button.title = button.getAttribute('aria-label');
  }

  changeFont(direction) {
    this.fontSize = Math.min(MAX_FONT_SIZE, Math.max(MIN_FONT_SIZE, this.fontSize + direction));
    this.shell.dataset.fontSize = String(this.fontSize);
    this.announce(`Dimensione testo ${this.fontSize + 1} di 13`);
  }

  changeSpeed(direction) {
    this.speed = Math.min(MAX_SPEED, Math.max(MIN_SPEED, this.speed + direction * SPEED_STEP));
    this.announce(`Velocità di scorrimento ${this.speed}`);
  }

  toggleScroll() {
    if (this.isScrolling) this.stopScroll();
    else this.startScroll();
  }

  startScroll() {
    this.isScrolling = true;
    this.lastFrame = null;
    this.scrollPosition = this.reader.scrollTop;
    this.syncControls();
    this.frame = requestAnimationFrame((time) => this.scrollFrame(time));
  }

  scrollFrame(time) {
    if (!this.isScrolling) return;
    if (this.lastFrame !== null) {
      const elapsed = (time - this.lastFrame) / 1000;
      const maximum = this.reader.scrollHeight - this.reader.clientHeight;
      this.scrollPosition = Math.min(maximum, this.scrollPosition + this.speed * elapsed);
      this.reader.scrollTop = this.scrollPosition;
      if (this.scrollPosition >= maximum) {
        this.stopScroll();
        this.announce('Fine del testo');
        return;
      }
    }
    this.lastFrame = time;
    this.frame = requestAnimationFrame((nextTime) => this.scrollFrame(nextTime));
  }

  stopScroll() {
    this.isScrolling = false;
    if (this.frame) cancelAnimationFrame(this.frame);
    this.frame = null;
    this.syncControls();
  }

  async toggleWakeLock() {
    this.wakeRequested = !this.wakeRequested;
    if (this.wakeRequested) await this.requestWakeLock();
    else await this.releaseWakeLock();
    this.syncControls();
  }

  async requestWakeLock() {
    if (!('wakeLock' in navigator)) {
      this.wakeRequested = false;
      this.announce('Schermo sempre attivo non supportato dal browser');
      return;
    }
    try {
      this.wakeLock = await navigator.wakeLock.request('screen');
      this.wakeLock.addEventListener('release', () => {
        this.wakeLock = null;
        this.syncControls();
      });
      this.announce('Schermo sempre attivo');
    } catch {
      this.wakeRequested = false;
      this.announce('Impossibile mantenere attivo lo schermo');
    }
  }

  async releaseWakeLock() {
    this.wakeRequested = false;
    if (this.wakeLock) await this.wakeLock.release();
    this.wakeLock = null;
    this.syncControls();
  }

  async handleVisibilityChange() {
    if (document.visibilityState === 'visible' && this.dialog.open && this.wakeRequested && !this.wakeLock) await this.requestWakeLock();
  }

  syncControls() {
    const accordiButtons = this.querySelectorAll('[data-lectern-toggle-accordi]');
    if (accordiButtons.length > 0) {
      accordiButtons.forEach((button) => {
        button.setAttribute('aria-pressed', String(this.showingAccordi));
        button.setAttribute('aria-label', this.showingAccordi ? 'Nascondi accordi' : 'Visualizza accordi');
        button.title = button.getAttribute('aria-label');
        button.querySelector('[data-icon-show-accordi]')?.classList.toggle('hidden', !this.showingAccordi);
        button.querySelector('[data-icon-hide-accordi]')?.classList.toggle('hidden', this.showingAccordi);
      });
      this.querySelector('[data-lectern-transpose-controls]')?.classList.toggle('hidden', !this.showingAccordi);
    }

    const dark = this.shell.dataset.theme === 'dark';
    const themeButton = this.querySelector('[data-lectern-theme]');
    themeButton?.setAttribute('aria-pressed', String(dark));
    themeButton?.setAttribute('aria-label', dark ? 'Passa al tema chiaro' : 'Passa al tema scuro');
    if (themeButton) themeButton.title = themeButton.getAttribute('aria-label');
    const themeLabel = this.querySelector('[data-lectern-theme-label]');
    if (themeLabel) themeLabel.textContent = dark ? 'Chiaro' : 'Scuro';
    this.querySelector('[data-icon-theme-light]')?.classList.toggle('hidden', dark);
    this.querySelector('[data-icon-theme-dark]')?.classList.toggle('hidden', !dark);

    const wakeButton = this.querySelector('[data-lectern-wake-lock]');
    wakeButton?.setAttribute('aria-pressed', String(this.wakeRequested && Boolean(this.wakeLock)));
    this.querySelector('[data-icon-wake-off]')?.classList.toggle('hidden', Boolean(this.wakeLock));
    this.querySelector('[data-icon-wake-on]')?.classList.toggle('hidden', !this.wakeLock);

    const scrollButton = this.querySelector('[data-lectern-scroll]');
    scrollButton?.setAttribute('aria-pressed', String(this.isScrolling));
    scrollButton?.setAttribute('aria-label', this.isScrolling ? 'Metti in pausa lo scorrimento automatico' : 'Avvia scorrimento automatico');
    if (scrollButton) scrollButton.title = scrollButton.getAttribute('aria-label');
    this.querySelector('[data-icon-scroll-play]')?.classList.toggle('hidden', this.isScrolling);
    this.querySelector('[data-icon-scroll-pause]')?.classList.toggle('hidden', !this.isScrolling);
  }

  announce(message) { this.status.textContent = message; }
}

if (!customElements.get('lectern-modal')) customElements.define('lectern-modal', LecternModal);
