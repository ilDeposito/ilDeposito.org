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
      'https://www.youtube.com/ildeposito',
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
  };

  if (canto.capoverso) {
    schema.description = canto.capoverso;
  }

  const autori = [...(canto.autoriTesto ?? []), ...(canto.autoriMusica ?? [])].filter(
    (a, i, arr) => arr.findIndex((x) => x.slug === a.slug) === i
  );

  if (autori.length > 0) {
    schema.author = autori.map((a) => ({
      '@type': 'Person',
      name: a.titolo,
      url: `${siteUrl}/autori/${a.slug}`,
    }));
  }

  if (canto.lingue?.length > 0) {
    schema.inLanguage = canto.lingue[0].titolo;
  }

  if (canto.anno) {
    schema.dateCreated = String(canto.anno);
  }

  return schema;
}

export function buildPersonSchema(autore, siteUrl) {
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'Person',
    name: autore.titolo,
    url: `${siteUrl}/autori/${autore.slug}`,
  };

  if (autore.immagine) {
    schema.image = `${siteUrl}/uploads/autori/${autore.slug}.jpg`;
  }

  return schema;
}

export function buildEventSchema(evento, siteUrl) {
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'Event',
    name: evento.titolo,
    url: `${siteUrl}/eventi/${evento.slug}`,
  };

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
    }
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

export function buildCollectionPageSchema(title, description, url) {
  return {
    '@context': 'https://schema.org',
    '@type': 'CollectionPage',
    name: title,
    description,
    url,
  };
}
