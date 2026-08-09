const SHARPS = ['Do', 'Do#', 'Re', 'Re#', 'Mi', 'Fa', 'Fa#', 'Sol', 'Sol#', 'La', 'La#', 'Si'];
const FLATS = ['Do', 'Reb', 'Re', 'Mib', 'Mi', 'Fa', 'Solb', 'Sol', 'Lab', 'La', 'Sib', 'Si'];

const NOTE_RE = /\b(Sol|Do|Re|Mi|Fa|La|Si)(#|b)?/g;
const CHORD_FULL_RE = /\b(Sol|Do|Re|Mi|Fa|La|Si)(#|b)?(m|min|maj|dim|aug|sus[24]|add\d{1,2}|6|7|9|11|13)*/g;

function noteToIndex(note, mod) {
  const full = note + (mod || '');
  return SHARPS.indexOf(full) === -1 ? FLATS.indexOf(full) : SHARPS.indexOf(full);
}

function transposeMatch(note, mod, semitones) {
  const index = noteToIndex(note, mod);
  if (index === -1) return note + (mod || '');
  const newIndex = ((index + semitones) % 12 + 12) % 12;
  return semitones > 0 ? SHARPS[newIndex] : FLATS[newIndex];
}

export function transposeLine(line, semitones) {
  let result = '';
  let lastEnd = 0;

  for (const match of line.matchAll(NOTE_RE)) {
    const [full, note, mod] = match;
    const start = match.index;
    result += line.slice(lastEnd, start);

    const transposed = transposeMatch(note, mod, semitones);
    const diff = transposed.length - full.length;
    result += transposed;

    const afterChord = start + full.length;
    let spacesAfter = 0;
    while (afterChord + spacesAfter < line.length && line[afterChord + spacesAfter] === ' ') spacesAfter++;

    if (diff > 0 && spacesAfter >= diff) lastEnd = afterChord + diff;
    else if (diff < 0) {
      result += ' '.repeat(-diff);
      lastEnd = afterChord;
    } else lastEnd = afterChord;
  }

  return result + line.slice(lastEnd);
}

function isChordLine(line) {
  const nonSpace = line.replace(/\s/g, '');
  if (nonSpace.length === 0) return false;
  const withoutChords = line.replace(CHORD_FULL_RE, '').replace(/[\s/|()-]/g, '');
  return withoutChords.length / nonSpace.length < 0.3;
}

function escapeHtml(value) {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

export function renderAccordi(text, semitones = 0) {
  const transposed = semitones === 0 ? text : text.split('\n').map((line) => transposeLine(line, semitones)).join('\n');
  return transposed.split('\n').map((line) => (
    isChordLine(line) ? `<span class="chord-line">${escapeHtml(line)}</span>` : escapeHtml(line)
  )).join('\n');
}
