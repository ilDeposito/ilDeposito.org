import { linguaToIso, stripHtml } from './seo.js';

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

export function buildCreativeWorkSchema(canto, siteUrl) {
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'MusicComposition',
    name: canto.titolo,
    url: `${siteUrl}/canti/${canto.slug}`,
    inLanguage: linguaToIso(canto.lingue?.[0]?.titolo),
    isPartOf: {
      '@type': 'CreativeWork',
      name: 'ilDeposito.org — Archivio canti di protesta',
      url: siteUrl,
    },
  };

  if (canto.capoverso) {
    schema.description = canto.capoverso;
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

  const toEntityRef = (a) => ({
    '@type': a.isPersona ? 'Person' : 'Organization',
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

  if (canto.videoUrl) {
    schema.subjectOf = {
      '@type': 'VideoObject',
      url: canto.videoUrl,
      name: canto.titolo,
    };
  }

  return schema;
}

export function buildPersonSchema(autore, siteUrl, ogImagePath) {
  const isPersona = Boolean(autore.nome);

  const schema = {
    '@context': 'https://schema.org',
    '@type': isPersona ? 'Person' : 'Organization',
    name: autore.titolo,
    url: `${siteUrl}/autori/${autore.slug}`,
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

  // birthDate/deathDate/birthPlace sono proprietà di Person: per i collettivi
  // (Organization) non hanno un equivalente valido in schema.org, si omettono.
  if (isPersona) {
    if (autore.annoNascita) schema.birthDate = String(autore.annoNascita);
    if (autore.annoMorte) schema.deathDate = String(autore.annoMorte);

    if (autore.localizzazioni?.length > 0) {
      schema.birthPlace = {
        '@type': 'Place',
        name: autore.localizzazioni[0].titolo,
      };
    }
  }

  const sameAs = (autore.links ?? []).map((l) => l.uri).filter(Boolean);
  if (sameAs.length > 0) schema.sameAs = sameAs;

  return schema;
}

export function buildProfilePageSchema(autore, siteUrl, ogImagePath) {
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

  return schema;
}

export function buildEventSchema(evento, siteUrl) {
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'Event',
    name: evento.titolo,
    url: `${siteUrl}/eventi/${evento.slug}`,
  };

  if (evento.informazioni) {
    schema.description = stripHtml(evento.informazioni).substring(0, 200);
  }

  if (evento.dataEvento) {
    schema.startDate = new Date(evento.dataEvento).toISOString().split('T')[0];
  }

  if (evento.localizzazioni?.length > 0) {
    const loc = evento.localizzazioni[0];
    schema.location = {
      '@type': 'Place',
      name: loc.titolo,
    };
    if (evento.latitude != null && evento.longitude != null) {
      schema.location.geo = {
        '@type': 'GeoCoordinates',
        latitude: evento.latitude,
        longitude: evento.longitude,
      };
      schema.location.address = loc.titolo;
    }
  }

  if (evento.cantiCollegati?.length > 0) {
    schema.about = evento.cantiCollegati.map((c) => ({
      '@type': 'MusicComposition',
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
      name: traduzione.cantoOriginale.titolo,
      url: `${siteUrl}/canti/${traduzione.cantoOriginale.slug}`,
      inLanguage: linguaToIso(traduzione.cantoOriginale.lingue?.[0]?.titolo),
    };
  }

  if (traduzione.informazioni) {
    schema.description = stripHtml(traduzione.informazioni).substring(0, 200);
  }

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
