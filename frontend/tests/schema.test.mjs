import assert from 'node:assert/strict';
import test from 'node:test';

import { buildCreativeWorkSchema } from '../src/lib/schema.js';

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
