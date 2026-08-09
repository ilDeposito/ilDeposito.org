import assert from 'node:assert/strict';
import test from 'node:test';
import { renderAccordi, transposeLine } from '../src/scripts/chords.js';

test('traspone le note italiane mantenendo l’allineamento', () => {
  assert.equal(transposeLine('Do   Re', 1), 'Do#  Re#');
  assert.equal(transposeLine('Sib  Sol', -1), 'La   Solb');
});

test('ripristina la tonalità originale con zero semitoni', () => {
  const accordi = 'Do   Re\nUn verso';
  assert.equal(renderAccordi(accordi, 0), '<span class="chord-line">Do   Re</span>\nUn verso');
});

test('non modifica il testo originale degli accordi', () => {
  const accordi = 'Do   Re\nUn verso';
  renderAccordi(accordi, 3);
  assert.equal(accordi, 'Do   Re\nUn verso');
});
