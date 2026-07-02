const SHARPS = ['Do', 'Do#', 'Re', 'Re#', 'Mi', 'Fa', 'Fa#', 'Sol', 'Sol#', 'La', 'La#', 'Si'];
const FLATS  = ['Do', 'Reb', 'Re', 'Mib', 'Mi', 'Fa', 'Solb', 'Sol', 'Lab', 'La', 'Sib', 'Si'];

const NOTE_RE = /\b(Sol|Do|Re|Mi|Fa|La|Si)(#|b)?/g;
const CHORD_FULL_RE = /\b(Sol|Do|Re|Mi|Fa|La|Si)(#|b)?(m|min|maj|dim|aug|sus[24]|add\d{1,2}|6|7|9|11|13)*/g;

function noteToIndex(note, mod) {
  const full = note + (mod || '');
  let idx = SHARPS.indexOf(full);
  if (idx === -1) idx = FLATS.indexOf(full);
  return idx;
}

function transposeMatch(note, mod, semitones) {
  const idx = noteToIndex(note, mod);
  if (idx === -1) return note + (mod || '');
  const newIdx = ((idx + semitones) % 12 + 12) % 12;
  return semitones > 0 ? SHARPS[newIdx] : FLATS[newIdx];
}

function transposeLine(line, semitones) {
  let result = '';
  let lastEnd = 0;

  for (const match of line.matchAll(NOTE_RE)) {
    const [full, note, mod] = match;
    const start = match.index;
    result += line.slice(lastEnd, start);

    const transposed = transposeMatch(note, mod, semitones);
    const oldLen = full.length;
    const newLen = transposed.length;
    const diff = newLen - oldLen;

    result += transposed;

    const afterChord = start + oldLen;
    let spacesAfter = 0;
    while (afterChord + spacesAfter < line.length && line[afterChord + spacesAfter] === ' ') {
      spacesAfter++;
    }

    if (diff > 0 && spacesAfter >= diff) {
      lastEnd = afterChord + diff;
    } else if (diff < 0) {
      result += ' '.repeat(-diff);
      lastEnd = afterChord;
    } else {
      lastEnd = afterChord;
    }
  }

  result += line.slice(lastEnd);
  return result;
}

function isChordLine(line) {
  const nonSpace = line.replace(/\s/g, '');
  if (nonSpace.length === 0) return false;
  const withoutChords = line.replace(CHORD_FULL_RE, '').replace(/[\s/\-|()]/g, '');
  return withoutChords.length / nonSpace.length < 0.3;
}

function escapeHtml(str) {
  return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function renderAccordi(text, semitones) {
  const transposed = semitones === 0 ? text : text.split('\n').map((l) => transposeLine(l, semitones)).join('\n');
  return transposed.split('\n').map((line) => {
    const escaped = escapeHtml(line);
    return isChordLine(line) ? `<span class="chord-line">${escaped}</span>` : escaped;
  }).join('\n');
}

class SongLyrics extends HTMLElement {
  connectedCallback() {
    this._semitones = 0;
    this._originalAccordi = null;

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
