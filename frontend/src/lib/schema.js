import { linguaToIso, stripHtml, truncate } from './seo.js';

export function buildWebSiteSchema(siteUrl) {
  return {
    '@context': 'https://schema.org',
    '@type': 'WebSite',
    name: 'ilDeposito.org',
    url: siteUrl,
    description: 'Archivio online di canti di protesta politica e sociale',
    inLanguage: 'it',
    potentialAction: {
      '@type': 'SearchAction',
      target: {
        '@type': 'EntryPoint',
        urlTemplate: `${siteUrl}/cerca?q={search_term_string}`,
      },
      'query-input': 'required name=search_term_string',
    },
  };
}

export function buildOrganizationSchema(siteUrl) {
  return {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    name: 'ilDeposito.org',
    url: siteUrl,
    logo: `${siteUrl}/favicon.svg`,
    sameAs: [
      'https://www.facebook.com/ildeposito.org',
      'https://www.youtube.com/user/ildeposito',
    ],
  };
}

export function buildBreadcrumbSchema(items) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, i) => ({
      '@type': 'ListItem',
      position: i + 1,
      name: item.name,
      ...(item.url ? { item: item.url } : {}),
    })),
  };
}

export function buildCreativeWorkSchema(canto, siteUrl, ogImagePath, eventi = []) {
  const url = `${siteUrl}/canti/${canto.slug}`;

  const schema = {
    '@context': 'https://schema.org',
    '@type': 'MusicComposition',
    // @id stabile per riferimenti interni al @graph e riconciliazione entità.
    '@id': `${url}#composition`,
    name: canto.titolo,
    url,
    inLanguage: linguaToIso(canto.lingue?.[0]?.titolo),
    isPartOf: {
      '@type': 'CreativeWork',
      name: 'ilDeposito.org — Archivio canti di protesta',
      url: siteUrl,
    },
  };

  // La description deve descrivere l'opera, non citarla: si preferiscono le
  // note redazionali al capoverso (che è solo il primo verso del testo).
  const descrizione = stripHtml(canto.informazioni) || canto.capoverso;
  if (descrizione) {
    schema.description = truncate(descrizione, 300);
  }

  if (ogImagePath) {
    schema.image = `${siteUrl}${ogImagePath}`;
  }

  if (canto.altriTitoli) {
    schema.alternateName = canto.altriTitoli;
  }

  if (canto.testo) {
    let lyricsText = canto.testo;
    if (lyricsText.length > 500) {
      const cutAt = lyricsText.lastIndexOf('\n', 500);
      lyricsText = (cutAt > 100 ? lyricsText.substring(0, cutAt) : lyricsText.substring(0, 500)).trimEnd() + '…';
    }
    schema.lyrics = {
      '@type': 'CreativeWork',
      text: lyricsText,
      inLanguage: linguaToIso(canto.lingue?.[0]?.titolo),
    };
  }

  const autori = [...(canto.autoriTesto ?? []), ...(canto.autoriMusica ?? [])].filter(
    (a, i, arr) => arr.findIndex((x) => x.slug === a.slug) === i
  );

  // Lo stesso @id emesso da buildPersonSchema sulla pagina autore: Google
  // riconcilia così "l'autore di questo canto" con la sua pagina profilo.
  const toEntityRef = (a) => ({
    '@type': a.isPersona ? 'Person' : 'MusicGroup',
    '@id': `${siteUrl}/autori/${a.slug}#autore`,
    name: a.titolo,
    url: `${siteUrl}/autori/${a.slug}`,
  });

  if (autori.length > 0) {
    schema.author = autori.map(toEntityRef);

    if (canto.autoriTesto?.length > 0) {
      schema.lyricist = canto.autoriTesto.map(toEntityRef);
    }
    if (canto.autoriMusica?.length > 0) {
      schema.composer = canto.autoriMusica.map(toEntityRef);
    }
  }

  if (canto.anno) {
    schema.dateCreated = String(canto.anno);
  }

  if (canto.dataCreazione) schema.datePublished = canto.dataCreazione;
  if (canto.dataModifica) schema.dateModified = canto.dataModifica;

  if (canto.tematiche?.length > 0) {
    schema.genre = canto.tematiche.map((t) => t.titolo);
  }

  // I tag vanno in keywords (stringa comma-separated, come da spec schema.org);
  // le tematiche restano in genre per non duplicare lo stesso segnale.
  if (canto.tags?.length > 0) {
    schema.keywords = canto.tags.map((t) => t.titolo).join(', ');
  }

  // temporalCoverage accetta testo libero oltre agli intervalli ISO: i periodi
  // storici dell'archivio sono il dato temporale più ricco disponibile.
  if (canto.periodi?.length > 0) {
    schema.temporalCoverage = canto.periodi.map((p) => p.titolo);
  }

  // Il canto è un'opera scritta SULL'evento storico → about. L'inverso
  // (Event.subjectOf) è emesso dalla pagina evento: le due pagine si
  // riconciliano tramite gli @id condivisi.
  if (eventi.length > 0) {
    schema.about = eventi.map((e) => ({
      '@type': 'Event',
      '@id': `${siteUrl}/eventi/${e.slug}#evento`,
      name: e.titolo,
      url: `${siteUrl}/eventi/${e.slug}`,
    }));
  }

  if (canto.videoUrl) {
    // Google richiede thumbnailUrl e uploadDate (e raccomanda embedUrl e
    // description) per VideoObject: thumbnail ed embed si derivano dall'ID
    // YouTube; come uploadDate si usa la data di pubblicazione del canto sul
    // sito, perché la vera data di upload richiederebbe la YouTube Data API.
    // Se l'URL non è riconoscibile come YouTube meglio omettere il VideoObject
    // che emetterne uno invalido.
    const videoId = canto.videoUrl.match(
      /(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|v\/|shorts\/))([a-zA-Z0-9_-]{11})/
    )?.[1];

    if (videoId) {
      const video = {
        '@type': 'VideoObject',
        name: canto.titolo,
        description:
          canto.capoverso ||
          `Registrazione del canto "${canto.titolo}" dall'archivio ilDeposito.org`,
        thumbnailUrl: `https://i.ytimg.com/vi/${videoId}/hqdefault.jpg`,
        embedUrl: `https://www.youtube.com/embed/${videoId}`,
        url: canto.videoUrl,
      };
      if (canto.dataCreazione) video.uploadDate = canto.dataCreazione;

      // recordedAs (MusicRecording) è la proprietà corretta per collegare
      // l'opera a una sua registrazione, invece del generico subjectOf.
      schema.recordedAs = {
        '@type': 'MusicRecording',
        '@id': `${url}#recording`,
        name: canto.titolo,
        recordingOf: { '@id': `${url}#composition` },
        video,
      };
    }
  }

  // Il PDF testo/accordi è generato per ogni canto in fase di build
  // (integrations/pdf-generator.js): associatedMedia lo rende un asset
  // indicizzabile a sé, collegato all'opera.
  schema.associatedMedia = {
    '@type': 'DigitalDocument',
    name: `Testo${canto.accordi ? ' e accordi' : ''} di "${canto.titolo}" (PDF)`,
    url: `${siteUrl}/pdf/canti/ildeposito-${canto.slug}.pdf`,
    encodingFormat: 'application/pdf',
  };

  return schema;
}

export function buildPersonSchema(autore, siteUrl, ogImagePath) {
  const isPersona = Boolean(autore.nome);
  const url = `${siteUrl}/autori/${autore.slug}`;

  const schema = {
    '@context': 'https://schema.org',
    '@type': isPersona ? 'Person' : 'MusicGroup',
    // Stesso @id usato nei riferimenti author/lyricist/composer dei canti:
    // àncora del knowledge graph interno per questa entità.
    '@id': `${url}#autore`,
    name: autore.titolo,
    url,
  };

  if (isPersona) {
    schema.givenName = autore.nome;
    schema.familyName = autore.cognome;
  }

  if (ogImagePath) {
    schema.image = `${siteUrl}${ogImagePath}`;
  }

  if (autore.informazioni) {
    schema.description = stripHtml(autore.informazioni).substring(0, 200);
  }

  // birthDate/deathDate/nationality sono proprietà di Person: per i collettivi
  // (MusicGroup) non hanno un equivalente valido in schema.org, si omettono.
  if (isPersona) {
    if (autore.annoNascita) schema.birthDate = String(autore.annoNascita);
    if (autore.annoMorte) schema.deathDate = String(autore.annoMorte);

    // 'localizzazioni' sugli autori indica la nazionalità (confermato in
    // redazione), non un luogo di nascita puntuale: nationality è la
    // proprietà corretta, non birthPlace.
    if (autore.localizzazioni?.length > 0) {
      schema.nationality = {
        '@type': 'Country',
        name: autore.localizzazioni[0].titolo,
      };
    }

    // colleague è una proprietà di Person, senza equivalente su Organization/
    // MusicGroup: gli autori correlati si aggiungono solo qui, non per i collettivi.
    if (autore.autoriCorrelati?.length > 0) {
      schema.colleague = autore.autoriCorrelati.map((c) => ({
        '@type': c.isPersona ? 'Person' : 'MusicGroup',
        '@id': `${siteUrl}/autori/${c.slug}#autore`,
        name: c.titolo,
        url: `${siteUrl}/autori/${c.slug}`,
      }));
    }
  }

  const sameAs = (autore.links ?? []).map((l) => l.uri).filter(Boolean);
  if (sameAs.length > 0) schema.sameAs = sameAs;

  return schema;
}

export function buildProfilePageSchema(autore, siteUrl, ogImagePath, canti = []) {
  const person = buildPersonSchema(autore, siteUrl, ogImagePath);
  delete person['@context'];

  const schema = {
    '@context': 'https://schema.org',
    '@type': 'ProfilePage',
    url: `${siteUrl}/autori/${autore.slug}`,
    mainEntity: person,
  };

  if (autore.dataCreazione) schema.datePublished = autore.dataCreazione;
  if (autore.dataModifica) schema.dateModified = autore.dataModifica;

  // Le opere dell'autore come ItemList: i riferimenti @id #composition
  // agganciano le pagine canto al profilo nel knowledge graph. Cap a 50 per
  // non gonfiare l'HTML degli autori più prolifici (es. anonimo).
  if (canti.length > 0) {
    const opere = canti.slice(0, 50);
    schema.hasPart = {
      '@type': 'ItemList',
      numberOfItems: opere.length,
      itemListElement: opere.map((c, i) => ({
        '@type': 'ListItem',
        position: i + 1,
        item: {
          '@type': 'MusicComposition',
          '@id': `${siteUrl}/canti/${c.slug}#composition`,
          name: c.titolo,
          url: `${siteUrl}/canti/${c.slug}`,
        },
      })),
    };
  }

  return schema;
}

export function buildEventSchema(evento, siteUrl, ogImagePath) {
  const url = `${siteUrl}/eventi/${evento.slug}`;

  const schema = {
    '@context': 'https://schema.org',
    '@type': 'Event',
    // Stesso @id usato in about dalle pagine canto (riconciliazione entità).
    '@id': `${url}#evento`,
    name: evento.titolo,
    url,
    // Search Console segnala eventStatus come mancante: per anniversari
    // storici il default (svoltosi regolarmente) è l'unico valore onesto.
    // offers/performer/organizer restano invece omessi di proposito: per un
    // evento storico non esistono, e inventarli sarebbe markup ingannevole.
    eventStatus: 'https://schema.org/EventScheduled',
  };

  if (evento.informazioni) {
    schema.description = stripHtml(evento.informazioni).substring(0, 200);
  }

  // Stesso trattamento di buildCreativeWorkSchema sul canto: genre per le
  // tematiche, keywords per i tag. Prima mancavano qui pur essendo presenti
  // (e visibili in pagina) anche sull'evento.
  if (evento.tematiche?.length > 0) {
    schema.genre = evento.tematiche.map((t) => t.titolo);
  }

  if (evento.tags?.length > 0) {
    schema.keywords = evento.tags.map((t) => t.titolo).join(', ');
  }

  if (evento.dataEvento) {
    const giorno = new Date(evento.dataEvento).toISOString().split('T')[0];
    schema.startDate = giorno;
    // Anniversario puntuale: l'evento storico è registrato su un solo giorno.
    schema.endDate = giorno;
  }

  if (ogImagePath) {
    schema.image = `${siteUrl}${ogImagePath}`;
  }

  if (evento.localizzazioni?.length > 0) {
    const loc = evento.localizzazioni[0];
    schema.location = {
      '@type': 'Place',
      name: loc.titolo,
      // Google vuole address anche senza coordinate: il nome della località
      // è il livello di dettaglio più preciso che l'archivio possiede.
      address: loc.titolo,
    };
    if (evento.latitude != null && evento.longitude != null) {
      schema.location.geo = {
        '@type': 'GeoCoordinates',
        latitude: evento.latitude,
        longitude: evento.longitude,
      };
    }
  }

  // I canti sono opere scritte SULL'evento: la proprietà corretta è subjectOf
  // (inversa di about, che sta sul canto). Gli @id #composition riconciliano
  // questi riferimenti con le pagine canto.
  if (evento.cantiCollegati?.length > 0) {
    schema.subjectOf = evento.cantiCollegati.map((c) => ({
      '@type': 'MusicComposition',
      '@id': `${siteUrl}/canti/${c.slug}#composition`,
      name: c.titolo,
      url: `${siteUrl}/canti/${c.slug}`,
    }));
  }

  if (evento.links?.[0]?.uri) schema.sameAs = [evento.links[0].uri];

  if (evento.dataCreazione) schema.datePublished = evento.dataCreazione;
  if (evento.dataModifica) schema.dateModified = evento.dataModifica;

  return schema;
}

export function buildTranslationSchema(traduzione, siteUrl) {
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'CreativeWork',
    name: traduzione.titolo,
    url: `${siteUrl}/traduzioni/${traduzione.slug}`,
    inLanguage: linguaToIso(traduzione.lingue?.[0]?.titolo),
  };

  if (traduzione.cantoOriginale) {
    schema.translationOfWork = {
      '@type': 'MusicComposition',
      '@id': `${siteUrl}/canti/${traduzione.cantoOriginale.slug}#composition`,
      name: traduzione.cantoOriginale.titolo,
      url: `${siteUrl}/canti/${traduzione.cantoOriginale.slug}`,
      inLanguage: linguaToIso(traduzione.cantoOriginale.lingue?.[0]?.titolo),
    };
  }

  if (traduzione.informazioni) {
    schema.description = stripHtml(traduzione.informazioni).substring(0, 200);
  }

  if (traduzione.dataCreazione) schema.datePublished = traduzione.dataCreazione;
  if (traduzione.dataModifica) schema.dateModified = traduzione.dataModifica;

  return schema;
}

export function buildWebPageSchema(title, description, url) {
  return {
    '@context': 'https://schema.org',
    '@type': 'WebPage',
    name: title,
    description,
    url,
  };
}

export function buildItemListSchema(name, items) {
  return {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    name,
    numberOfItems: items.length,
    itemListElement: items.map((item, i) => ({
      '@type': 'ListItem',
      position: i + 1,
      name: item.name,
      url: item.url,
    })),
  };
}

export function buildCollectionPageSchema(title, description, url) {
  return {
    '@context': 'https://schema.org',
    '@type': 'CollectionPage',
    name: title,
    description,
    url,
  };
}

// Per le pagine tassonomia (tags, lingue, localizzazioni, periodi): DefinedTermSet
// per l'indice, DefinedTerm per il singolo termine — più corretto di
// WebPage/CollectionPage per un vocabolario controllato.
export function buildDefinedTermSetSchema(name, description, url) {
  return {
    '@context': 'https://schema.org',
    '@type': 'DefinedTermSet',
    name,
    description,
    url,
  };
}

export function buildDefinedTermSchema(name, description, url, termSet) {
  return {
    '@context': 'https://schema.org',
    '@type': 'DefinedTerm',
    name,
    description,
    url,
    inDefinedTermSet: {
      '@type': 'DefinedTermSet',
      name: termSet.name,
      url: termSet.url,
    },
  };
}
