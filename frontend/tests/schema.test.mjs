import assert from 'node:assert/strict';
import test from 'node:test';

import { buildCreativeWorkSchema, buildEventSchema, buildPersonSchema, buildWebSiteSchema } from '../src/lib/schema.js';

test('buildCreativeWorkSchema includes startDate and location for linked events', () => {
  const canto = {
    slug: 'canto-test',
    titolo: 'Canto test',
    informazioni: null,
    capoverso: null,
    altriTitoli: null,
    testo: null,
    accordi: null,
    videoUrl: null,
    anno: null,
    dataCreazione: null,
    dataModifica: null,
    autoriTesto: [],
    autoriMusica: [],
    lingue: [{ titolo: 'Italiano', slug: 'italiano' }],
    tags: [],
    tematiche: [],
    periodi: [],
  };

  const schema = buildCreativeWorkSchema(canto, 'https://example.com', null, [
    {
      slug: 'evento-test',
      titolo: 'Evento test',
      dataEvento: '2024-05-10T00:00:00Z',
      localizzazioni: [{ titolo: 'Roma', slug: 'roma' }],
      latitude: 41.9,
      longitude: 12.5,
    },
  ]);

  assert.equal(schema.about[0].startDate, '2024-05-10');
  assert.deepEqual(schema.about[0].location, {
    '@type': 'Place',
    name: 'Roma',
    address: 'Roma',
    geo: {
      '@type': 'GeoCoordinates',
      latitude: 41.9,
      longitude: 12.5,
    },
  });
});

test('buildWebSiteSchema includes publisher organization metadata', () => {
  const schema = buildWebSiteSchema('https://example.com');

  assert.deepEqual(schema.publisher, {
    '@type': 'Organization',
    name: 'ilDeposito.org',
    url: 'https://example.com',
    logo: 'https://example.com/favicon.svg',
  });
});

test('buildCreativeWorkSchema includes publisher organization metadata', () => {
  const canto = {
    slug: 'canto-test',
    titolo: 'Canto test',
    informazioni: null,
    capoverso: 'Capoverso test',
    altriTitoli: null,
    testo: null,
    accordi: null,
    videoUrl: null,
    anno: null,
    dataCreazione: null,
    dataModifica: null,
    autoriTesto: [],
    autoriMusica: [],
    lingue: [{ titolo: 'Italiano', slug: 'italiano' }],
    tags: [],
    tematiche: [],
    periodi: [],
  };

  const schema = buildCreativeWorkSchema(canto, 'https://example.com', null, []);

  assert.deepEqual(schema.publisher, {
    '@type': 'Organization',
    name: 'ilDeposito.org',
    url: 'https://example.com',
    logo: 'https://example.com/favicon.svg',
  });
});

test('buildCreativeWorkSchema links taxonomy terms as DefinedTerm entities', () => {
  const canto = {
    slug: 'canto-tassonomie',
    titolo: 'Canto tassonomie',
    informazioni: null,
    capoverso: null,
    altriTitoli: null,
    testo: null,
    accordi: null,
    videoUrl: null,
    anno: null,
    dataCreazione: null,
    dataModifica: null,
    autoriTesto: [],
    autoriMusica: [],
    lingue: [{ titolo: 'Italiano', slug: 'italiano' }],
    tags: [{ titolo: 'Antifascismo', slug: 'antifascismo' }],
    tematiche: [{ titolo: 'Lavoro', slug: 'lavoro' }],
    periodi: [{ titolo: 'Novecento', slug: 'novecento' }],
  };

  const schema = buildCreativeWorkSchema(canto, 'https://example.com', null, []);
  const taxonomyTerms = schema.about.filter((item) => item['@type'] === 'DefinedTerm');

  assert.equal(taxonomyTerms.length, 4);
  assert.ok(taxonomyTerms.some((term) => term.name === 'Lavoro' && term.inDefinedTermSet?.name === 'Tematiche'));
  assert.ok(taxonomyTerms.some((term) => term.name === 'Antifascismo' && term.inDefinedTermSet?.name === 'Tag'));
  assert.ok(taxonomyTerms.some((term) => term.name === 'Novecento' && term.inDefinedTermSet?.name === 'Periodi'));
  assert.ok(taxonomyTerms.some((term) => term.name === 'Italiano' && term.inDefinedTermSet?.name === 'Lingue'));
});

test('buildPersonSchema links localizations and periods as DefinedTerm entities', () => {
  const autore = {
    slug: 'autore-test',
    titolo: 'Autore test',
    nome: 'Nome',
    cognome: 'Cognome',
    informazioni: null,
    localizzazioni: [{ titolo: 'Italia', slug: 'italia' }],
    periodi: [{ titolo: 'Novecento', slug: 'novecento' }],
    autoriCorrelati: [],
    links: [],
  };

  const schema = buildPersonSchema(autore, 'https://example.com', null);
  const taxonomyTerms = schema.about.filter((item) => item['@type'] === 'DefinedTerm');

  assert.equal(taxonomyTerms.length, 2);
  assert.ok(taxonomyTerms.some((term) => term.name === 'Italia' && term.inDefinedTermSet?.name === 'Localizzazioni'));
  assert.ok(taxonomyTerms.some((term) => term.name === 'Novecento' && term.inDefinedTermSet?.name === 'Periodi'));
});

test('buildEventSchema links taxonomy terms as DefinedTerm entities', () => {
  const evento = {
    slug: 'evento-test',
    titolo: 'Evento test',
    informazioni: null,
    tematiche: [{ titolo: 'Lavoro', slug: 'lavoro' }],
    tags: [{ titolo: 'Antifascismo', slug: 'antifascismo' }],
    periodi: [{ titolo: 'Novecento', slug: 'novecento' }],
    localizzazioni: [{ titolo: 'Roma', slug: 'roma' }],
    latitude: 41.9,
    longitude: 12.5,
    dataEvento: '2024-05-10T00:00:00Z',
    cantiCollegati: [],
    links: [],
  };

  const schema = buildEventSchema(evento, 'https://example.com', null);
  const taxonomyTerms = schema.about.filter((item) => item['@type'] === 'DefinedTerm');

  assert.equal(taxonomyTerms.length, 3);
  assert.ok(taxonomyTerms.some((term) => term.name === 'Lavoro' && term.inDefinedTermSet?.name === 'Tematiche'));
  assert.ok(taxonomyTerms.some((term) => term.name === 'Antifascismo' && term.inDefinedTermSet?.name === 'Tag'));
  assert.ok(taxonomyTerms.some((term) => term.name === 'Novecento' && term.inDefinedTermSet?.name === 'Periodi'));
});

test('buildCreativeWorkSchema extracts YouTube video metadata from www URLs', () => {
  const canto = {
    slug: 'canto-video',
    titolo: 'Canto video',
    informazioni: null,
    capoverso: 'Capoverso test',
    altriTitoli: null,
    testo: null,
    accordi: null,
    videoUrl: 'https://www.youtube.com/watch?v=abc12345678&t=42s',
    anno: null,
    dataCreazione: '2024-05-10',
    dataModifica: null,
    autoriTesto: [],
    autoriMusica: [],
    lingue: [{ titolo: 'Italiano', slug: 'italiano' }],
    tags: [],
    tematiche: [],
    periodi: [],
  };

  const schema = buildCreativeWorkSchema(canto, 'https://example.com', null, []);

  assert.equal(schema.recordedAs.video.thumbnailUrl, 'https://i.ytimg.com/vi/abc12345678/hqdefault.jpg');
  assert.equal(schema.recordedAs.video.embedUrl, 'https://www.youtube.com/embed/abc12345678');
  assert.equal(schema.recordedAs.video.contentUrl, 'https://www.youtube.com/watch?v=abc12345678');
  assert.equal(schema.recordedAs.video.description, 'Video del canto Canto video su ilDeposito.org');
  assert.equal(schema.recordedAs.video.uploadDate, '2024-05-10');
});
